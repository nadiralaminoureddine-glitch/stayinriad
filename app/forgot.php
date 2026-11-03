<?php
// Mot de passe oublié : envoi d'un lien de réinitialisation (valable 1 heure, utilisable une seule fois)
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    // limite : 3 demandes par heure et par adresse IP
    $n = (int)q("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email LIKE 'reset:%' AND at > ?", [client_ip(), gmdate('Y-m-d H:i:s', time() - 3600)])->fetchColumn();
    q('INSERT INTO login_attempts (email, ip, ok, at) VALUES (?, ?, 1, ?)', ['reset:' . mb_substr($email, 0, 180), client_ip(), now()]);
    if ($n < 3 && valid_email($email)) {
        $u = q("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email])->fetch();
        if ($u) send_mail($u['email'], 'Réinitialiser votre mot de passe StayInRiad', mail_layout('Nouveau mot de passe', '<p>Bonjour ' . e($u['first_name']) . ',</p><p>Vous avez demandé à changer votre mot de passe. Cliquez sur le bouton ci-dessous (lien valable 1 heure) :</p>' . mail_button(reset_link($u), 'Choisir un nouveau mot de passe') . '<p style="font-size:13px;color:#6b625a">Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email : votre mot de passe ne change pas.</p>'));
    }
    $sent = true; // même message dans tous les cas, pour ne pas révéler si un compte existe
}
render_page('Mot de passe oublié', '<div class="card narrow"><h1>Mot de passe oublié</h1>' . ($sent
 ? '<div class="flash ok">Si un compte existe avec cette adresse, vous allez recevoir un email avec un lien pour choisir un nouveau mot de passe (pensez aux courriers indésirables).</div><a class="btn" href="login.php">Retour à la connexion</a>'
 : '<p>Indiquez l\'email de votre compte : nous vous envoyons un lien pour choisir un nouveau mot de passe.</p><form method="post">' . csrf_field() . '<label>Email</label><input type="email" name="email" required autocomplete="email"><button class="btn full">Envoyer le lien</button></form>') . '</div>');
