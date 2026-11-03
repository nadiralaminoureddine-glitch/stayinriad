<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

if (current_user()) redirect('account.php');
const CITIES = ['Marrakech','Fès','Essaouira','Chefchaouen','Meknès','Rabat','Tanger','Ouarzazate','Merzouga','Agadir','Taroudant','Autre'];

$type = ($_GET['type'] ?? $_POST['type'] ?? 'traveler') === 'riad' ? 'riad' : 'traveler';
if (!site_open()) $type = 'riad'; // tant que le site est fermé : inscriptions de riads uniquement
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = fn($k) => trim((string)($_POST[$k] ?? ''));
    $started = (int)($_SESSION['reg_started'] ?? 0);
    // anti-robots : champ invisible rempli, ou formulaire envoyé en moins de 4 secondes
    if ($p('website') !== '' || !$started || time() - $started < 4) { sleep(2); $err = 'Merci de réessayer dans quelques secondes.'; }
    elseif ($p('pass') !== $p('pass2')) $err = 'Les deux mots de passe ne sont pas identiques.';
    elseif (empty($_POST['cgu'])) $err = 'Merci d\'accepter les conditions pour créer votre compte.';
    elseif ($type === 'riad' && ($p('riad_name') === '' || !in_array($p('city'), CITIES, true))) $err = 'Indiquez le nom et la ville de votre riad.';
    elseif (!intl_phone($p('phone'))) $err = 'Indiquez un numéro de téléphone valide, avec l\'indicatif du pays (ex. +212 6…, +33 6…).';
    else {
        db()->beginTransaction();
        [$id, $err] = create_user($type, $p('email'), (string)($_POST['pass'] ?? ''), $p('first'), $p('last'), (string)intl_phone($p('phone')), 'unverified');
        if ($id && $type === 'riad') {
            q('INSERT INTO riads (owner_id, name, city, rooms_count, status, created_at) VALUES (?,?,?,?,?,?)',
              [$id, mb_substr($p('riad_name'), 0, 120), $p('city'), max(0, min(200, (int)$p('rooms'))), 'brouillon', now()]);
        }
        if ($id) {
            db()->commit();
            unset($_SESSION['reg_started']);
            $e = pend_code(q('SELECT * FROM users WHERE id = ?', [$id])->fetch(), 'verify');
            if ($e) flash($e, 'warn');
            redirect('verify.php');
        }
        db()->rollBack();
    }
}

$_SESSION['reg_started'] = $_SESSION['reg_started'] ?? time();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $_SESSION['reg_started'] = time();
$v = fn($k, $d = '') => e($_POST[$k] ?? $d);
$riadFields = $type === 'riad' ? '<h2>Votre riad</h2>
<label>Nom du riad</label><input name="riad_name" required maxlength="120" value="' . $v('riad_name') . '">
<div class="row"><div><label>Ville</label><select name="city" required>' . implode('', array_map(fn($c) => '<option' . (($_POST['city'] ?? '') === $c ? ' selected' : '') . '>' . e($c) . '</option>', CITIES)) . '</select></div>
<div><label>Nombre de chambres</label><input type="number" name="rooms" min="1" max="200" value="' . $v('rooms') . '"></div></div><h2>Vos coordonnées</h2>' : '';

render_page('Créer un compte', '<div class="card narrow"><h1>' . ($type === 'riad' ? 'Inscrire mon riad' : 'Créer un compte') . '</h1>
' . (site_open() ? '<div class="tabs"><a href="register.php?type=traveler"' . ($type === 'traveler' ? ' class="on"' : '') . '>Voyageur</a><a href="register.php?type=riad"' . ($type === 'riad' ? ' class="on"' : '') . '>Propriétaire de riad</a></div>' : '<p class="note">Inscription des <b>propriétaires de riads</b>. Les comptes voyageurs ouvriront avec les réservations.</p>') . '
<form method="post">' . csrf_field() . '<input type="hidden" name="type" value="' . $type . '">' . $riadFields . '
<div class="row"><div><label>Prénom</label><input name="first" required maxlength="80" autocomplete="given-name" value="' . $v('first') . '"></div>
<div><label>Nom</label><input name="last" maxlength="80" autocomplete="family-name" value="' . $v('last') . '"></div></div>
<label>Email</label><input type="email" name="email" required autocomplete="email" value="' . $v('email') . '">
<label>Téléphone mobile' . ($type === 'riad' ? ' / WhatsApp' : '') . '</label><input name="phone" required maxlength="40" autocomplete="tel" inputmode="tel" placeholder="+212 6 … ou +33 6 …" value="' . $v('phone') . '"><p class="note" style="margin:4px 0 0">Un code de confirmation vous sera envoyé pour activer votre compte.</p>
<div class="hp" aria-hidden="true"><label>Site web</label><input name="website" tabindex="-1" autocomplete="off"></div>
<label>Mot de passe (10 caractères minimum, lettres et chiffres)</label><input type="password" name="pass" required minlength="10" autocomplete="new-password">
<label>Confirmer le mot de passe</label><input type="password" name="pass2" required minlength="10" autocomplete="new-password">
<label style="font-weight:400;display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="cgu" value="1" style="width:auto;margin-top:4px"' . (!empty($_POST['cgu']) ? ' checked' : '') . '> J\'accepte que StayInRiad utilise mes informations pour gérer mon compte' . ($type === 'riad' ? ' et me contacter pour l\'inscription de mon riad' : '') . '.</label>
' . ($err ? '<div class="err">' . e($err) . '</div>' : '') . '
<button class="btn full">Créer mon compte</button></form>
<p class="note" style="margin-top:14px">Déjà inscrit ? <a href="login.php">Se connecter</a></p></div>');
