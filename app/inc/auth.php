<?php
// StayInRiad — comptes, connexion, droits
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const ROLES = ['traveler' => 'Voyageur', 'riad' => 'Riad partenaire', 'admin' => 'Administrateur'];
const MAX_FAILS = 5;          // tentatives ratées autorisées…
const FAIL_WINDOW_MIN = 15;   // …sur cette durée (minutes)

function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $id = $_SESSION['uid'] ?? null;
    $u = $id ? (q('SELECT * FROM users WHERE id = ? AND status = ?', [$id, 'active'])->fetch() ?: null) : null;
    return $u;
}
function require_login(?string $role = null): array {
    $u = current_user();
    if (!$u) { flash('Connectez-vous pour continuer.', 'warn'); redirect('login.php'); }
    if ($role && $u['role'] !== $role) { http_response_code(403); render_page('Accès refusé', '<div class="card"><h1>Accès refusé</h1><p>Cette page est réservée à un autre type de compte.</p><p><a class="btn" href="account.php">Retour à mon espace</a></p></div>'); exit; }
    return $u;
}

function valid_email(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 190; }
function password_problem(string $p): ?string {
    if (strlen($p) < 10) return 'Le mot de passe doit contenir au moins 10 caractères.';
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) return 'Le mot de passe doit contenir des lettres et des chiffres.';
    return null;
}

function too_many_fails(string $email): bool {
    $since = gmdate('Y-m-d H:i:s', time() - FAIL_WINDOW_MIN * 60);
    $n = (int)q('SELECT COUNT(*) FROM login_attempts WHERE (email = ? OR ip = ?) AND ok = 0 AND at > ?', [$email, client_ip(), $since])->fetchColumn();
    return $n >= MAX_FAILS;
}
function log_attempt(string $email, bool $ok): void {
    q('INSERT INTO login_attempts (email, ip, ok, at) VALUES (?, ?, ?, ?)', [$email, client_ip(), $ok ? 1 : 0, now()]);
}

/** Retourne null si OK, sinon un message d'erreur (volontairement vague). */
function attempt_login(string $email, string $pass): ?string {
    $email = strtolower(trim($email));
    if (too_many_fails($email)) return 'Trop de tentatives. Réessayez dans ' . FAIL_WINDOW_MIN . ' minutes.';
    $u = q('SELECT * FROM users WHERE email = ?', [$email])->fetch();
    // on vérifie toujours un hash pour ne pas révéler si l'email existe
    $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    if (!password_verify($pass, $hash) || !$u || $u['status'] !== 'active') {
        log_attempt($email, false);
        return 'Email ou mot de passe incorrect.';
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }
    log_attempt($email, true);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    q('UPDATE users SET last_login = ? WHERE id = ?', [now(), $u['id']]);
    return null;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Crée un compte. Retourne [id, null] ou [null, erreur]. */
function create_user(string $role, string $email, string $pass, string $first, string $last, string $phone = ''): array {
    $email = strtolower(trim($email));
    if (!isset(ROLES[$role])) return [null, 'Type de compte invalide.'];
    if (!valid_email($email)) return [null, 'Adresse email invalide.'];
    if ($m = password_problem($pass)) return [null, $m];
    if (trim($first) === '' || mb_strlen($first) > 80 || mb_strlen($last) > 80) return [null, 'Indiquez votre prénom et votre nom.'];
    if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) return [null, 'Un compte existe déjà avec cet email. Connectez-vous.'];
    q('INSERT INTO users (role, email, password_hash, first_name, last_name, phone, status, created_at) VALUES (?,?,?,?,?,?,?,?)',
      [$role, $email, password_hash($pass, PASSWORD_DEFAULT), trim($first), trim($last), trim($phone), 'active', now()]);
    return [(int)db()->lastInsertId(), null];
}
