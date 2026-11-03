<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

if (current_user()) redirect('account.php');
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $err = attempt_login((string)($_POST['email'] ?? ''), (string)($_POST['pass'] ?? ''));
    if ($err === 'CODE') redirect('verify.php');
    if (!$err) redirect('account.php');
}
render_page('Connexion', '<div class="card narrow"><h1>Connexion</h1>
<form method="post">' . csrf_field() . '
<label>Email</label><input type="email" name="email" required autocomplete="email" value="' . e($_POST['email'] ?? '') . '">
<label>Mot de passe</label><input type="password" name="pass" required autocomplete="current-password">
' . ($err ? '<div class="err">' . e($err) . '</div>' : '') . '
<button class="btn full">Se connecter</button></form>
<p class="note" style="margin-top:14px"><a href="forgot.php">Mot de passe oublié ?</a><br>Pas encore de compte ? <a href="register.php">Créer un compte</a></p></div>');
