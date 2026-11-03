<?php
// Saisie du code reçu par SMS ou email : activation du compte (inscription) ou confirmation de connexion.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$uid = (int)($_SESSION['pending_uid'] ?? 0); $purpose = $_SESSION['pending_purpose'] ?? '';
$u = $uid ? q('SELECT * FROM users WHERE id = ?', [$uid])->fetch() : null;
if (!$u || !in_array($purpose, ['verify', 'login'], true)) { flash('Session expirée. Connectez-vous à nouveau.', 'warn'); redirect('login.php'); }
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'resend') {
        $e = send_code($u, $purpose); flash($e ?: 'Nouveau code envoyé.', $e ? 'warn' : 'ok'); redirect('verify.php');
    }
    $err = check_code((int)$u['id'], $purpose, (string)($_POST['code'] ?? ''));
    if (!$err) {
        if ($purpose === 'verify') {
            q("UPDATE users SET status = 'active', email_verified_at = ? WHERE id = ?", [now(), $u['id']]);
            $u = q('SELECT * FROM users WHERE id = ?', [$u['id']])->fetch();
            if ($u['role'] === 'riad') {
                $r = q('SELECT name, city FROM riads WHERE owner_id = ? ORDER BY id LIMIT 1', [$u['id']])->fetch();
                send_mail(admin_email(), '[StayInRiad] Nouveau riad inscrit : ' . ($r['name'] ?? ''), mail_layout('Nouveau riad inscrit', mail_rows(['Riad' => e($r['name'] ?? ''), 'Ville' => e($r['city'] ?? ''), 'Propriétaire' => e($u['first_name'] . ' ' . $u['last_name']), 'Téléphone' => e($u['phone']), 'Email' => e($u['email'])]) . mail_button(base_url() . 'admin.php', 'Voir dans l\'administration')));
            }
            set_trusted($u);
            open_session($u);
            flash($u['role'] === 'riad' ? 'Compte activé ! Votre riad est enregistré : complétez sa fiche pour la mise en ligne.' : 'Compte activé. Bienvenue sur StayInRiad !');
        } else {
            if (!empty($_POST['trust'])) set_trusted($u);
            open_session($u);
        }
        redirect('account.php');
    }
}
$sms = ($_SESSION['code_channel'] ?? 'email') === 'sms';
$where = $sms ? 'par SMS au <b>' . e(substr((string)intl_phone((string)$u['phone']), 0, 6)) . ' ••• ' . e(substr((string)intl_phone((string)$u['phone']), -2)) . '</b>' : 'par email à <b>' . e(preg_replace('/(?<=.).(?=[^@]*@)/u', '•', $u['email'])) . '</b>';
render_page('Code de confirmation', '<div class="card narrow"><h1>' . ($purpose === 'verify' ? 'Activez votre compte' : 'Code de connexion') . '</h1>
<p>Nous venons d\'envoyer un code à 6 chiffres ' . $where . '.' . ($sms ? '' : ' Pensez à regarder dans les courriers indésirables.') . '</p>
<form method="post">' . csrf_field() . '
<label>Code</label><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus style="font-size:26px;letter-spacing:8px;text-align:center">
' . ($purpose === 'login' ? '<label class="chk"><input type="checkbox" name="trust" value="1" checked> Se souvenir de cet appareil pendant 30 jours</label>' : '') . '
' . ($err ? '<div class="err">' . e($err) . '</div>' : '') . '
<button class="btn full">Valider</button></form>
<form method="post" style="margin-top:12px">' . csrf_field() . '<button class="link" name="action" value="resend">Je n\'ai rien reçu : renvoyer un code</button></form></div>');
