<?php
// StayInRiad — installation : crée les tables et le premier compte administrateur.
// Ne fonctionne qu'une seule fois. Supprimez ce fichier après l'installation.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/schema.php';

function installed(): bool {
    try { return (bool)q("SELECT v FROM settings WHERE k = 'installed'")->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

if (installed()) {
    render_page('Installation', '<div class="card narrow"><h1>Déjà installé</h1><p>La base de données est déjà prête et le compte administrateur existe.</p><p><b>Supprimez maintenant le fichier <code>install.php</code></b> dans le gestionnaire de fichiers, par sécurité.</p><a class="btn" href="login.php">Se connecter</a></div>');
    exit;
}

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $driver = $CONFIG['db']['driver'] ?? 'mysql';
        foreach (schema_sql($driver) as $sql) {
            try { db()->exec($sql); }
            catch (PDOException $ex) { if (stripos($sql, 'CREATE INDEX') !== 0) throw $ex; } // index déjà présent
        }
        if ($_POST['pass'] !== ($_POST['pass2'] ?? '')) {
            $err = 'Les deux mots de passe ne sont pas identiques.';
        } else {
            [$id, $err] = create_user('admin', (string)$_POST['email'], (string)$_POST['pass'], (string)$_POST['first'], (string)$_POST['last']);
            if ($id) {
                q("INSERT INTO settings (k, v) VALUES ('installed', ?)", [now()]);
                flash('Installation terminée. Connectez-vous avec votre compte administrateur, puis supprimez install.php.');
                redirect('login.php');
            }
        }
    } catch (Throwable $ex) {
        error_log('[StayInRiad install] ' . $ex->getMessage());
        $err = 'Impossible de se connecter à la base de données. Vérifiez le nom de la base, l\'utilisateur et le mot de passe dans stayinriad-config.php.';
    }
}

render_page('Installation', '<div class="card narrow"><h1>Installation</h1>
<p class="note">Cette page crée la base de données et votre compte <b>administrateur</b>. Elle ne sert qu\'une seule fois.</p>
<form method="post" autocomplete="off">' . csrf_field() . '
<div class="row"><div><label>Prénom</label><input name="first" required maxlength="80" value="' . e($_POST['first'] ?? '') . '"></div>
<div><label>Nom</label><input name="last" maxlength="80" value="' . e($_POST['last'] ?? '') . '"></div></div>
<label>Email de l\'administrateur</label><input type="email" name="email" required value="' . e($_POST['email'] ?? 'contact@stayinriad.com') . '">
<label>Mot de passe (10 caractères minimum, lettres et chiffres)</label><input type="password" name="pass" required minlength="10" autocomplete="new-password">
<label>Confirmer le mot de passe</label><input type="password" name="pass2" required minlength="10" autocomplete="new-password">
' . ($err ? '<div class="err">' . e($err) . '</div>' : '') . '
<button class="btn full">Installer et créer le compte</button></form></div>');
