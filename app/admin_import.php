<?php
// Admin — pré-remplir la fiche d'un riad depuis sa page Booking
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/import.php';

require_login('admin');
$rid = (int)($_POST['rid'] ?? $_GET['id'] ?? 0);
$auto = !empty($_GET['auto']);
$r = $rid ? q('SELECT r.*, u.first_name, u.email FROM riads r JOIN users u ON u.id = r.owner_id WHERE r.id = ?', [$rid])->fetch() : null;
if (!$r && !$auto) { flash('Riad introuvable.', 'bad'); redirect('admin.php'); }
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    [$d, $err] = import_decode((string)($_POST['data'] ?? ''));
    if (!$err && !$r) $err = 'Choisissez le riad à remplir.';
    if (!$err) {
        $res = import_apply($r, $d, true);
        $nb = (int)q('SELECT COUNT(*) FROM rooms WHERE riad_id = ? AND active = 1 AND price_eur <= 0', [$rid])->fetchColumn();
        log_action('import_booking', $r['name'] . ' · ' . $res['rooms'] . ' chambres · ' . $res['photos'] . ' photos');
        $sum = ($res['fields'] ? 'Fiche : ' . implode(', ', $res['fields']) . '. ' : 'Fiche : rien à compléter (déjà remplie). ')
             . $res['rooms'] . ' chambre(s) ajoutée(s), ' . $res['photos'] . ' photo(s) importée(s)'
             . ($res['photo_errors'] ? ' (' . $res['photo_errors'] . ' photo(s) non téléchargée(s))' : '') . '.';
        if (!empty($_POST['notify']) && ($res['rooms'] || $res['fields'] || ($res['photos'] && empty($r['import_done'])))) {
            send_mail($r['email'], 'Votre fiche StayInRiad est pré-remplie', mail_layout('Votre fiche est presque prête',
                '<p>Bonjour ' . e($r['first_name']) . ',</p><p>Nous avons pré-rempli la fiche de <b>' . e($r['name']) . '</b> à partir de votre page Booking : '
                . e(implode(', ', array_merge($res['fields'], $res['rooms'] ? [$res['rooms'] . ' chambre' . ($res['rooms'] > 1 ? 's' : '')] : [], $res['photos'] ? [$res['photos'] . ' photos'] : []))) . '.</p>'
                . '<p>Il vous reste à :</p><ul>' . ($nb ? '<li><b>indiquer le prix par nuit</b> de chaque chambre ;</li>' : '') . '<li>relire la description ;</li><li>compléter les informations de la société et le RIB, puis envoyer la fiche.</li></ul>'
                . '<p>Une question ? Écrivez-nous sur WhatsApp au <b>+212 707-596333</b>.</p>' . mail_button(base_url() . ($nb ? 'rooms.php' : 'riad.php'), $nb ? 'Indiquer mes prix' : 'Voir ma fiche')));
            $sum .= ' Le riad a été prévenu par email.';
        }
        flash('✅ ' . $r['name'] . ' : ' . $sum, $res['photo_errors'] && !$res['photos'] ? 'warn' : 'ok');
        redirect('admin_import.php?id=' . $rid);
    }
}

// Formulaire d'import (rempli automatiquement par le favori, ou collé à la main)
$riads = q('SELECT id, name, city, booking_url FROM riads ORDER BY name')->fetchAll();
$opts = '<option value="">— Choisir le riad —</option>' . implode('', array_map(fn($x) => '<option value="' . (int)$x['id'] . '" data-key="' . e($x['booking_url'] ? booking_key($x['booking_url']) : '') . '"' . ($r && (int)$r['id'] === (int)$x['id'] ? ' selected' : '') . '>' . e($x['name'] . ' (' . $x['city'] . ')') . '</option>', $riads));
$form = '<form method="post" id="impForm">' . csrf_field() . '<input type="hidden" name="action" value="import">
<label>Riad à remplir</label><select name="rid" id="impRiad" required>' . $opts . '</select>
<textarea name="data" id="impData" rows="4" required placeholder="Les données arrivent toutes seules avec le favori. Sinon, collez-les ici (Ctrl+V)." style="width:100%;border:1px solid #cdbfa9;border-radius:8px;padding:11px;font:13px/1.4 monospace;margin-top:10px"></textarea>
<label class="chk"><input type="checkbox" name="notify" value="1" checked> Prévenir le riad par email (« votre fiche est pré-remplie, indiquez vos prix »)</label>
<button class="btn" id="impGo">Importer dans la fiche</button></form>';

$bm = '<div class="card"><h2 style="margin-top:0">Installer le bouton d\'import (une seule fois)</h2>
<p>Depuis un ordinateur, <b>maintenez le clic sur ce bouton et faites-le glisser</b> dans la barre de favoris (sous l\'adresse du site) :</p>
<p><a class="btn" id="bmLink" draggable="true" href="' . e(import_bookmarklet()) . '" title="Glissez-moi dans la barre de favoris">📥 Importer dans StayInRiad</a></p>
<div id="bmHelp" class="flash warn" hidden><b>Ce bouton ne se clique pas ici :</b> faites-le glisser jusqu\'à la barre de favoris, puis relâchez.</div>
<p class="note">Barre de favoris invisible ? Ctrl+Maj+B (Windows) ou Cmd+Maj+B (Mac).</p>
<details><summary class="note">Le glisser-déposer ne marche pas ?</summary><ol class="note"><li><button type="button" class="btn sm ghost" id="bmCopy">Copier le code du favori</button></li><li>Clic droit sur la barre de favoris, puis <b>Ajouter une page…</b></li><li>Nom : <b>Importer dans StayInRiad</b>. Dans le champ <b>URL</b> de ce favori, collez le code (Ctrl+V), puis Enregistrer.</li></ol><p class="note" style="color:var(--bad)">⚠️ Ne collez jamais ce code dans la barre d\'adresse : le navigateur le transforme en recherche Google.</p></details></div>';

if (!$r) {
    // Arrivée depuis le favori : les données sont dans l'adresse, l'import part tout seul si le riad est reconnu
    render_page('Import Booking', '<p><a href="admin.php">← Tous les riads</a></p><h1>Import depuis Booking</h1>'
        . ($err ? '<div class="flash bad">' . e($err) . '</div>' : '')
        . '<div class="flash ok" id="impStatus" hidden></div><div class="card">' . $form . '</div><script src="assets/admin-import.js" defer></script>');
    exit;
}

$nRooms = (int)q('SELECT COUNT(*) FROM rooms WHERE riad_id = ? AND active = 1', [$rid])->fetchColumn();
$nPhotos = (int)q("SELECT COUNT(*) FROM photos WHERE riad_id = ? AND status <> 'refusée'", [$rid])->fetchColumn();
$link = (string)$r['booking_url'];

render_page('Import Booking · ' . $r['name'], '<p><a href="admin_riad.php?id=' . $rid . '">← ' . e($r['name']) . '</a></p>
<h1>Pré-remplir depuis Booking</h1>
' . ($err ? '<div class="flash bad">' . e($err) . '</div>' : '') . '
<div class="card"><p style="margin-top:0"><b>' . e($r['name']) . '</b> · ' . e($r['city']) . ' · ' . $nRooms . ' chambre(s) · ' . $nPhotos . ' photo(s)'
 . ($r['import_done'] ? ' · <span class="pill ok">importé le ' . e(date('d/m/Y', strtotime($r['import_done'] . ' UTC'))) . '</span>' : '') . '</p>
<ol style="font-size:17px;line-height:1.7;margin:10px 0 6px;padding-left:22px">
<li>' . ($link ? '<a class="btn" href="' . e($link) . '" target="_blank" rel="noopener">Ouvrir la page Booking du riad</a>' : 'Ouvrez la page du riad sur booking.com (le riad n\'a pas donné de lien)') . '</li>
<li>Sur la page Booking, cliquez sur le favori <b>📥 Importer dans StayInRiad</b>. <span class="note">C\'est fini : l\'import se fait tout seul et le résultat s\'affiche.</span></li></ol>
<p class="note">On ne remplace jamais ce que le riad a déjà saisi. Les photos (30 au maximum) attendent votre validation, et les prix restent à indiquer par le riad.</p></div>
' . $bm . '
<details class="card"><summary><b>Import manuel</b> (si le favori ne peut pas ouvrir StayInRiad)</summary>' . $form . '</details>
<script src="assets/admin-import.js" defer></script>');
