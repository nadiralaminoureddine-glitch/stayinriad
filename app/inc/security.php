<?php
// StayInRiad — codes par email, appareils de confiance, mot de passe oublié, journal des actions
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const CODE_MINUTES = 15;      // validité d'un code
const CODE_MAX_TRIES = 5;     // essais par code
const TRUST_DAYS = 30;        // « se souvenir de cet appareil »

function app_key(): string {
    $f = private_dir_sec() . '/app.key';
    if (!is_file($f)) { file_put_contents($f, random_bytes(32)); @chmod($f, 0600); }
    return (string)file_get_contents($f);
}
function private_dir_sec(): string { global $CONFIG; $d = $CONFIG['private_dir'] ?? (dirname(__DIR__, 3) . '/stayinriad-private'); if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function code_hash(string $code, int $uid, string $purpose): string { return hash_hmac('sha256', "$uid|$purpose|$code", app_key()); }

/** Crée et envoie un code à 6 chiffres. Refuse si trop de demandes. Retourne null ou une erreur. */
function send_code(array $u, string $purpose): ?string {
    $recent = (int)q('SELECT COUNT(*) FROM auth_codes WHERE user_id = ? AND created_at > ?', [$u['id'], gmdate('Y-m-d H:i:s', time() - 3600)])->fetchColumn();
    $last = q('SELECT created_at FROM auth_codes WHERE user_id = ? AND purpose = ? ORDER BY id DESC LIMIT 1', [$u['id'], $purpose])->fetchColumn(); // délai entre deux codes du même type
    if ($recent >= 6) return 'Trop de codes demandés. Réessayez dans une heure.';
    if ($last && strtotime($last . ' UTC') > time() - 45) return 'Un code vient d\'être envoyé. Patientez une minute avant d\'en demander un autre.';
    q('UPDATE auth_codes SET used = 1 WHERE user_id = ? AND purpose = ? AND used = 0', [$u['id'], $purpose]);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('INSERT INTO auth_codes (user_id, purpose, code_hash, expires_at, created_at) VALUES (?,?,?,?,?)', [$u['id'], $purpose, code_hash($code, (int)$u['id'], $purpose), gmdate('Y-m-d H:i:s', time() + CODE_MINUTES * 60), now()]);
    $_SESSION['code_channel'] = 'email';
    if (sms_enabled() && intl_phone((string)$u['phone']) && send_sms((string)$u['phone'], (fn($t) => function_exists('tr') ? tr($t, $u['lang'] ?? 'fr') : $t)('stayinriad.com : votre code ' . ($purpose === 'verify' ? 'de confirmation' : 'de connexion') . ' est ' . $code . '. Valable ' . CODE_MINUTES . ' min. Ne le communiquez à personne.'))) {
        $_SESSION['code_channel'] = 'sms'; return null;
    }
    [$subject, $title, $intro] = $purpose === 'verify'
        ? ['Votre code de confirmation StayInRiad : ' . $code, 'Confirmez votre adresse email', 'Bienvenue sur StayInRiad ! Pour activer votre compte, saisissez ce code :']
        : ['Votre code de connexion StayInRiad : ' . $code, 'Code de connexion', 'Une connexion à votre compte StayInRiad est en cours. Saisissez ce code pour la confirmer :'];
    send_mail($u['email'], $subject, mail_layout($title, '<p>Bonjour ' . e($u['first_name']) . ',</p><p>' . $intro . '</p><p style="font-size:34px;letter-spacing:8px;font-weight:bold;color:#2647a8;background:#f7efe3;border-radius:10px;padding:14px;text-align:center">' . $code . '</p><p style="font-size:13px;color:#6b625a">Ce code est valable ' . CODE_MINUTES . ' minutes. Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email' . ($purpose === 'login' ? ' et changez votre mot de passe : quelqu\'un connaît peut-être votre mot de passe actuel.' : '.') . '</p>'));
    return null;
}
/** Vérifie un code. Retourne null si correct, sinon une erreur. */
function check_code(int $uid, string $purpose, string $code): ?string {
    $row = q('SELECT * FROM auth_codes WHERE user_id = ? AND purpose = ? AND used = 0 ORDER BY id DESC LIMIT 1', [$uid, $purpose])->fetch();
    if (!$row || $row['expires_at'] < now()) return 'Code expiré. Demandez un nouveau code.';
    if ((int)$row['attempts'] >= CODE_MAX_TRIES) return 'Trop d\'essais. Demandez un nouveau code.';
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (!hash_equals($row['code_hash'], code_hash($code, $uid, $purpose))) {
        q('UPDATE auth_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        $left = CODE_MAX_TRIES - (int)$row['attempts'] - 1;
        return 'Code incorrect.' . ($left > 0 ? " Encore $left essai(s)." : ' Demandez un nouveau code.');
    }
    q('UPDATE auth_codes SET used = 1 WHERE id = ?', [$row['id']]);
    return null;
}

// ---------- SMS (si un fournisseur est configuré ; sinon le code part par email) ----------
/** Numéro au format international (+212…, +33…). Les numéros marocains 06/07… sont convertis en +212. */
function intl_phone(string $p): ?string {
    $p = preg_replace('/[^\d+]/', '', $p) ?? '';
    if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
    if (preg_match('/^0[5-7]\d{8}$/', $p)) $p = '+212' . substr($p, 1);
    return preg_match('/^\+[1-9]\d{7,14}$/', $p) ? $p : null;
}
function sms_enabled(): bool { global $CONFIG; return !empty($CONFIG['sms']['provider']); }
/** Envoie un SMS. Fournisseur pris en charge : Twilio (compte, clé et numéro dans stayinriad-config.php). */
function send_sms(string $to, string $text): bool {
    global $CONFIG; $c = $CONFIG['sms'] ?? []; $to = intl_phone($to) ?? '';
    if (!$to || !sms_enabled()) return false;
    if (($c['provider'] ?? '') === 'log') { @file_put_contents(private_dir_sec() . '/sms.log', date('c') . " $to $text\n", FILE_APPEND); $ok = true; }
    elseif (($c['provider'] ?? '') === 'twilio' && function_exists('curl_init')) {
        $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($c['sid']) . '/Messages.json');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERPWD => $c['sid'] . ':' . $c['token'],
            CURLOPT_POSTFIELDS => http_build_query(['To' => $to, 'From' => $c['from'], 'Body' => $text])]);
        curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $ok = $code >= 200 && $code < 300;
    } else $ok = false;
    try { q('INSERT INTO email_log (to_addr, subject, status, created_at) VALUES (?,?,?,?)', ['SMS ' . $to, mb_substr($text, 0, 255), $ok ? 'envoyé' : 'échec', now()]); } catch (Throwable $e) {}
    return $ok;
}

// ---------- Appareil de confiance (évite le code de connexion pendant 30 jours) ----------
function trust_cookie_name(): string { return 'sir_trust'; }
function trust_value(array $u, int $exp): string { return $u['id'] . '.' . $exp . '.' . hash_hmac('sha256', $u['id'] . '|' . $exp . '|' . $u['password_hash'], app_key()); }
function set_trusted(array $u): void {
    global $https; $exp = time() + TRUST_DAYS * 86400;
    setcookie(trust_cookie_name(), trust_value($u, $exp), ['expires' => $exp, 'path' => '/', 'secure' => (bool)$https, 'httponly' => true, 'samesite' => 'Lax']);
}
function is_trusted(array $u): bool {
    $c = (string)($_COOKIE[trust_cookie_name()] ?? ''); $p = explode('.', $c);
    if (count($p) !== 3 || (int)$p[0] !== (int)$u['id'] || (int)$p[1] < time()) return false;
    return hash_equals(trust_value($u, (int)$p[1]), $c); // invalide automatiquement si le mot de passe change
}
/** Comptes qui demandent un code à chaque nouvel appareil : admin et riads (ils gèrent RIB et virements). */
function needs_2fa(array $u): bool { return in_array($u['role'], ['admin', 'riad'], true); }

// ---------- Mot de passe oublié ----------
function reset_token(array $u, int $exp): string { return hash_hmac('sha256', 'reset|' . $u['id'] . '|' . $exp . '|' . $u['password_hash'], app_key()); }
function reset_link(array $u): string { $exp = time() + 3600; return base_url() . 'reset.php?u=' . (int)$u['id'] . '&e=' . $exp . '&t=' . reset_token($u, $exp); }
function reset_valid(array $u, int $exp, string $t): bool { return $exp >= time() && hash_equals(reset_token($u, $exp), $t); } // le lien ne sert qu'une fois : il change avec le mot de passe

// ---------- Journal des actions sensibles ----------
function log_action(string $action, string $details = ''): void {
    $u = current_user();
    try { q('INSERT INTO admin_log (user_id, action, details, ip, created_at) VALUES (?,?,?,?,?)', [$u['id'] ?? null, $action, mb_substr($details, 0, 500), client_ip(), now()]); } catch (Throwable $e) {}
}
