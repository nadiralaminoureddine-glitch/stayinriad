<?php
// StayInRiad — envoi des emails (fonction mail() de l'hébergement, expéditeur contact@stayinriad.com)
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

function base_url(): string { global $CONFIG; return rtrim($CONFIG['base_url'] ?? 'https://stayinriad.com/app', '/') . '/'; }
function admin_email(): string { global $CONFIG; return $CONFIG['admin_email'] ?? 'contact@stayinriad.com'; }

/** Mise en page commune des emails. */
function mail_layout(string $title, string $body): string {
    return '<!DOCTYPE html><html lang="fr"><body style="margin:0;background:#f7efe3;font-family:Arial,Helvetica,sans-serif;color:#1f1a17">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden">
<tr><td style="background:#0f5a4a;padding:18px 24px;font-family:Georgia,serif;font-size:22px;font-weight:bold;color:#ffffff">stay<span style="color:#d9a441">in</span>riad<span style="color:#d9a441">.com</span></td></tr>
<tr><td style="padding:24px"><h1 style="font-family:Georgia,serif;color:#0f5a4a;font-size:22px;margin:0 0 14px">' . e($title) . '</h1>' . $body . '</td></tr>
<tr><td style="padding:16px 24px;background:#faf6ef;color:#6b625a;font-size:12px">StayInRiad · la plateforme 100 % marocaine des riads · <a href="mailto:contact@stayinriad.com" style="color:#0f5a4a">contact@stayinriad.com</a> · WhatsApp +212 707-596333<br>StayInRiad ne vous demandera jamais vos coordonnées bancaires par email, message ou WhatsApp.</td></tr>
</table></td></tr></table></body></html>';
}
function mail_button(string $url, string $label): string {
    return '<p style="margin:22px 0"><a href="' . e($url) . '" style="background:#0f5a4a;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:bold;display:inline-block">' . e($label) . '</a></p>';
}
function mail_rows(array $rows): string {
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border-collapse:collapse">' . implode('', array_map(fn($k, $v) => '<tr><td style="padding:7px 0;border-bottom:1px solid #f0e8dc;color:#6b625a;width:42%">' . e($k) . '</td><td style="padding:7px 0;border-bottom:1px solid #f0e8dc"><b>' . $v . '</b></td></tr>', array_keys($rows), $rows)) . '</table>';
}

/**
 * Envoie un email HTML. En mode « log » (tests), l'email est seulement noté dans le journal.
 * Retourne true si l'envoi a été accepté par le serveur.
 */
function send_mail(string $to, string $subject, string $html): bool {
    global $CONFIG;
    if (!valid_email($to)) return false;
    $from = $CONFIG['mail_from'] ?? 'contact@stayinriad.com';
    $mode = $CONFIG['mail_mode'] ?? 'mail';
    $ok = true;
    if ($mode !== 'log') {
        $headers = [
            'From' => 'stayinriad.com <' . $from . '>',
            'Reply-To' => $from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer' => 'StayInRiad',
        ];
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers, '-f' . $from);
    } else {
        $dir = private_dir_mail(); @file_put_contents($dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.html', "<!-- To: $to | $subject -->\n" . $html);
    }
    try { q('INSERT INTO email_log (to_addr, subject, status, created_at) VALUES (?,?,?,?)', [$to, mb_substr($subject, 0, 255), $ok ? ($mode === 'log' ? 'journal' : 'envoyé') : 'échec', now()]); } catch (Throwable $e) {}
    if (!$ok) error_log('[StayInRiad mail] échec vers ' . $to . ' : ' . $subject);
    return $ok;
}
function private_dir_mail(): string {
    global $CONFIG; $d = ($CONFIG['private_dir'] ?? (dirname(__DIR__, 3) . '/stayinriad-private')) . '/emails';
    if (!is_dir($d)) @mkdir($d, 0750, true); return $d;
}
