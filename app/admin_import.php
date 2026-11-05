<?php
// Admin — pré-remplir la fiche d'un riad depuis sa page Booking
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/import.php';

require_login('admin');
$rid = (int)($_GET['id'] ?? 0);
$r = q('SELECT r.*, u.first_name, u.email FROM riads r JOIN users u ON u.id = r.owner_id WHERE r.id = ?', [$rid])->fetch();
if (!$r) { flash('Riad introuvable.', 'bad'); redirect('admin.php'); }
$self = 'admin_import.php?id=' . $rid;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    [$d, $err] = import_decode((string)($_POST['data'] ?? ''));
    if (!$err) {
        $res = import_apply($r, $d, true);
        $nb = (int)q('SELECT COUNT(*) FROM rooms WHERE riad_id = ? AND active = 1 AND price_eur <= 0', [$rid])->fetchColumn();
        log_action('import_booking', $r['name'] . ' · ' . $res['rooms'] . ' chambres · ' . $res['photos'] . ' photos');
        $sum = ($res['fields'] ? 'Fiche : ' . implode(', ', $res['fields']) . '. ' : 'Fiche : rien à compléter (déjà remplie). ')
             . $res['rooms'] . ' chambre(s) ajoutée(s), ' . $res['photos'] . ' photo(s) importée(s)'
             . ($res['photo_errors'] ? ' (' . $res['photo_errors'] . ' photo(s) non téléchargée(s))' : '') . '.';
        if (!empty($_POST['notify']) && ($res['rooms'] || $res['photos'] || $res['fields'])) {
            send_mail($r['email'], 'Votre fiche StayInRiad est pré-remplie', mail_layout('Votre fiche est presque prête',
                '<p>Bonjour ' . e($r['first_name']) . ',</p><p>Nous avons pré-rempli la fiche de <b>' . e($r['name']) . '</b> à partir de votre page Booking : '
                . e(implode(', ', array_merge($res['fields'], $res['rooms'] ? [$res['rooms'] . ' chambre' . ($res['rooms'] > 1 ? 's' : '')] : [], $res['photos'] ? [$res['photos'] . ' photos'] : []))) . '.</p>'
                . '<p>Il vous reste à :</p><ul>' . ($nb ? '<li><b>indiquer le prix par nuit</b> de chaque chambre ;</li>' : '') . '<li>relire la description ;</li><li>compléter les informations de la société et le RIB, puis envoyer la fiche.</li></ul>'
                . '<p>Une question ? Écrivez-nous sur WhatsApp au <b>+212 707-596333</b>.</p>' . mail_button(base_url() . ($nb ? 'rooms.php' : 'riad.php'), $nb ? 'Indiquer mes prix' : 'Voir ma fiche')));
            $sum .= ' Le riad a été prévenu par email.';
        }
        flash($sum, $res['photo_errors'] && !$res['photos'] ? 'warn' : 'ok');
        redirect($self);
    }
}

$nRooms = (int)q('SELECT COUNT(*) FROM rooms WHERE riad_id = ? AND active = 1', [$rid])->fetchColumn();
$nPhotos = (int)q("SELECT COUNT(*) FROM photos WHERE riad_id = ? AND status <> 'refusée'", [$rid])->fetchColumn();
$link = (string)$r['booking_url'];

render_page('Import Booking · ' . $r['name'], '<p><a href="admin_riad.php?id=' . $rid . '">← ' . e($r['name']) . '</a></p>
<h1>Pré-remplir depuis Booking</h1>
' . ($err ? '<div class="flash bad">' . e($err) . '</div>' : '') . '
<div class="card"><p style="margin-top:0"><b>' . e($r['name']) . '</b> · ' . e($r['city']) . ' · ' . $nRooms . ' chambre(s) · ' . $nPhotos . ' photo(s)'
 . ($r['import_done'] ? ' · <span class="pill ok">importé le ' . e(date('d/m/Y', strtotime($r['import_done'] . ' UTC'))) . '</span>' : '') . '</p>
' . ($link ? '<p>Page Booking donnée par le riad : <a href="' . e($link) . '" target="_blank" rel="noopener">' . e($link) . '</a></p>' : '<p class="note">Le riad n\'a pas encore donné de lien Booking : cherchez sa page sur booking.com.</p>') . '
<p class="note">On ne remplace jamais ce que le riad a déjà saisi : seuls les champs vides sont remplis, les chambres sont ajoutées si leur nom n\'existe pas, et les photos (30 au maximum) attendent votre validation comme d\'habitude. Les prix restent à indiquer par le riad.</p></div>

<div class="card"><h2 style="margin-top:0">1. Une seule fois : installez le bouton d\'import</h2>
<p>Depuis un ordinateur, <b>glissez ce bouton dans votre barre de favoris</b> :</p>
<p><a class="btn" id="bmLink" draggable="true" href="' . e(import_bookmarklet()) . '" title="Glissez-moi dans la barre de favoris">📥 Importer dans StayInRiad</a></p>
<div id="bmHelp" class="flash warn" hidden><b>Ce bouton ne se clique pas ici.</b> Maintenez le clic dessus et <b>faites-le glisser</b> jusqu\'à la barre de favoris (la barre juste sous l\'adresse du site), puis relâchez.</div>
<p class="note">Barre de favoris invisible ? Ctrl+Maj+B (Windows) ou Cmd+Maj+B (Mac).</p>
<details><summary class="note">Le glisser-déposer ne marche pas ?</summary><ol class="note"><li><button type="button" class="btn sm ghost" id="bmCopy">Copier le code du favori</button></li><li>Faites un clic droit sur la barre de favoris, puis <b>Ajouter une page…</b></li><li>Nom : <b>Importer dans StayInRiad</b>. Dans <b>URL</b>, collez le code (Ctrl+V), puis Enregistrer.</li></ol></details></div>

<div class="card"><h2 style="margin-top:0">2. Sur la page Booking du riad</h2>
<p>' . ($link ? '<a class="btn ghost" href="' . e($link) . '" target="_blank" rel="noopener">Ouvrir la page Booking</a>' : 'Ouvrez la page du riad sur booking.com') . ', attendez qu\'elle soit chargée, puis cliquez sur le favori <b>📥 Importer dans StayInRiad</b>. Un message confirme le nombre de chambres et de photos copiées.</p></div>

<div class="card"><h2 style="margin-top:0">3. Collez ici et importez</h2>
<form method="post">' . csrf_field() . '<textarea name="data" rows="5" required placeholder="Collez ici (Ctrl+V)" style="width:100%;border:1px solid #cdbfa9;border-radius:8px;padding:11px;font:13px/1.4 monospace"></textarea>
<label class="chk"><input type="checkbox" name="notify" value="1" checked> Prévenir le riad par email (« votre fiche est pré-remplie, indiquez vos prix »)</label>
<button class="btn" name="action" value="import">Importer dans la fiche</button></form>
<p class="note">Le téléchargement des photos peut prendre une minute.</p></div><script src="assets/admin-import.js" defer></script>');
