<?php
// Choix d'un nouveau mot de passe depuis le lien reçu par email
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$u = q("SELECT * FROM users WHERE id = ? AND status = 'active'", [(int)($_GET['u'] ?? 0)])->fetch();
$exp = (int)($_GET['e'] ?? 0); $t = (string)($_GET['t'] ?? '');
if (!$u || !reset_valid($u, $exp, $t)) { render_page('Lien expiré', '<div class="card narrow"><h1>Lien expiré</h1><p>Ce lien n\'est plus valable (il expire après 1 heure et ne sert qu\'une fois).</p><a class="btn" href="forgot.php">Demander un nouveau lien</a></div>'); exit; }
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = (string)($_POST['pass'] ?? '');
    if ($p !== (string)($_POST['pass2'] ?? '')) $err = 'Les deux mots de passe ne sont pas identiques.';
    elseif ($m = password_problem($p)) $err = $m;
    else {
        q('UPDATE users SET password_hash = ?, pw_changed_at = ? WHERE id = ?', [password_hash($p, PASSWORD_DEFAULT), now(), $u['id']]);
        q('UPDATE auth_codes SET used = 1 WHERE user_id = ?', [$u['id']]);
        send_mail($u['email'], 'Votre mot de passe StayInRiad a été modifié', mail_layout('Mot de passe modifié', '<p>Bonjour ' . e($u['first_name']) . ',</p><p>Le mot de passe de votre compte vient d\'être modifié. Toutes les autres sessions ont été déconnectées.</p><p style="font-size:13px;color:#6b625a">Si ce n\'est pas vous, écrivez-nous immédiatement à contact@stayinriad.com.</p>'));
        flash('Mot de passe modifié. Connectez-vous avec votre nouveau mot de passe.');
        redirect('login.php');
    }
}
render_page('Nouveau mot de passe', '<div class="card narrow"><h1>Nouveau mot de passe</h1><form method="post">' . csrf_field() . '
<label>Nouveau mot de passe (10 caractères minimum, lettres et chiffres)</label><input type="password" name="pass" required minlength="10" autocomplete="new-password">
<label>Confirmer</label><input type="password" name="pass2" required minlength="10" autocomplete="new-password">
' . ($err ? '<div class="err">' . e($err) . '</div>' : '') . '<button class="btn full">Enregistrer</button></form></div>');
