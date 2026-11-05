<?php
// Admin — vérification et validation d'un riad
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

require_login('admin');
$rid = (int)($_GET['id'] ?? 0);
$r = q('SELECT r.*, u.first_name, u.last_name, u.email, u.phone FROM riads r JOIN users u ON u.id = r.owner_id WHERE r.id = ?', [$rid])->fetch();
if (!$r) { flash('Riad introuvable.', 'bad'); redirect('admin.php'); }
$back = 'admin_riad.php?id=' . $rid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $pid = (int)($_POST['photo_id'] ?? 0);
    $ph = $pid ? q('SELECT * FROM photos WHERE id = ? AND riad_id = ?', [$pid, $rid])->fetch() : null;
    log_action('riad_' . $act, $r['name'] . ($pid ? ' · photo ' . $pid : '') . (isset($_POST['commission']) ? ' · ' . $_POST['commission'] . ' %' : '') . (!empty($_POST['note']) ? ' · ' . $_POST['note'] : ''));
    if ($act === 'sync_done') { q('UPDATE riads SET sync_booking_invite = NULL, sync_airbnb_call = NULL WHERE id = ?', [$rid]); flash('Demande de synchronisation marquée comme traitée.'); redirect($back); }
    switch ($act) {
        case 'photo_ok':
            if ($ph) { $pub = publish_photo($ph['file']); q("UPDATE photos SET status = 'publiée', public_file = ? WHERE id = ?", [$pub, $pid]); flash('Photo validée et publiée avec le logo.'); }
            break;
        case 'photo_no':
            if ($ph) { q("UPDATE photos SET status = 'refusée' WHERE id = ?", [$pid]); flash('Photo refusée.'); }
            break;
        case 'dup_ok':
            $pair = preg_replace('/[^0-9-]/', '', (string)($_POST['pair'] ?? ''));
            if ($pair) { q('INSERT INTO photo_ok (pair, at) VALUES (?, ?)', [$pair, now()]); flash('Marqué comme vérifié : pas de fraude.'); }
            break;
        case 'rib_ok':
            if (!holder_ok($r)) flash('Impossible : le titulaire du compte n\'est pas la société du contrat.', 'bad');
            elseif (!$r['bank_doc']) flash('Impossible : attestation de RIB manquante.', 'bad');
            else { q("UPDATE riads SET bank_status = 'vérifié' WHERE id = ?", [$rid]); flash('RIB vérifié.'); }
            break;
        case 'rib_no':
            q("UPDATE riads SET bank_status = 'refusé' WHERE id = ?", [$rid]); flash('RIB refusé : le riad doit le corriger.');
            break;
        case 'commission':
            $c = max(0, min(40, round((float)str_replace(',', '.', (string)($_POST['commission'] ?? '15')), 2)));
            q('UPDATE riads SET commission = ? WHERE id = ?', [$c, $rid]); flash('Commission enregistrée : ' . $c . ' %.');
            break;
        case 'approve':
            $miss = dossier_missing($r);
            $pub = (int)q("SELECT COUNT(*) FROM photos WHERE riad_id = ? AND status = 'publiée'", [$rid])->fetchColumn();
            if ($miss) flash('Dossier incomplet : ' . implode(', ', $miss) . '.', 'bad');
            elseif ($r['bank_status'] !== 'vérifié') flash('Vérifiez d\'abord le RIB.', 'bad');
            elseif ($pub < 3) flash('Validez au moins 3 photos avant la mise en ligne.', 'bad');
            elseif (photo_duplicates($rid)) flash('Une photo en double doit d\'abord être tranchée.', 'bad');
            else { q("UPDATE riads SET status = 'en ligne', approved_at = ?, admin_note = NULL WHERE id = ?", [now(), $rid]); flash($r['name'] . ' est en ligne. Le riad est prévenu par email.');
                send_mail($r['email'], 'Votre riad est validé : ' . $r['name'], mail_layout('Bienvenue sur StayInRiad !', '<p>Bonjour ' . e($r['first_name']) . ',</p><p>Bonne nouvelle : la fiche de <b>' . e($r['name']) . '</b> est validée' . (site_open() ? ' et en ligne. Les voyageurs peuvent désormais réserver.' : '. Elle sera visible par les voyageurs dès l\'ouverture des réservations.') . '</p><p>Pensez à tenir votre calendrier à jour (dates fermées) pour éviter les doubles réservations.</p>' . mail_button(base_url() . 'account.php', 'Mon espace riad'))); }
            break;
        case 'refuse':
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
            q("UPDATE riads SET status = 'refusé', admin_note = ? WHERE id = ?", [$note ?: 'Merci de compléter ou corriger votre fiche.', $rid]); flash('Fiche renvoyée au riad pour correction. Le riad est prévenu par email.');
            send_mail($r['email'], 'Votre fiche StayInRiad est à compléter', mail_layout('Encore un petit effort', '<p>Bonjour ' . e($r['first_name']) . ',</p><p>Notre équipe a relu la fiche de <b>' . e($r['name']) . '</b>. Avant la mise en ligne, merci de corriger :</p><p style="background:#fdf0dc;padding:12px;border-radius:8px">' . e($note ?: 'Merci de compléter ou corriger votre fiche.') . '</p>' . mail_button(base_url() . 'riad.php', 'Modifier ma fiche') . '<p style="font-size:14px">Besoin d\'aide ? Répondez simplement à cet email.</p>'));
            break;
        case 'offline':
            q("UPDATE riads SET status = 'brouillon' WHERE id = ?", [$rid]); flash('Riad retiré du site.');
            break;
    }
    redirect($back);
}

$rooms = q('SELECT * FROM rooms WHERE riad_id = ? AND active = 1 ORDER BY id', [$rid])->fetchAll();
$photos = q('SELECT * FROM photos WHERE riad_id = ? ORDER BY sort, id', [$rid])->fetchAll();
$dups = photo_duplicates($rid);
$dupIds = []; foreach ($dups as $d) { $dupIds[$d['a']['id']] = 1; $dupIds[$d['b']['id']] = 1; }
$miss = dossier_missing($r);
$amen = json_decode((string)($r['amenities'] ?? '[]'), true) ?: [];
$btn = fn($act, $label, $cls = '', $extra = '') => '<form method="post" class="inline">' . csrf_field() . $extra . '<button class="btn sm ' . $cls . '" name="action" value="' . $act . '">' . $label . '</button></form>';
$line = fn($k, $v) => '<tr><th style="width:38%">' . e($k) . '</th><td>' . ($v === '' || $v === null ? '<span class="note">—</span>' : $v) . '</td></tr>';

$photoGrid = $photos ? '<div class="gallery">' . implode('', array_map(function ($p) use ($btn, $dupIds) {
    $hid = '<input type="hidden" name="photo_id" value="' . (int)$p['id'] . '">';
    $act = $p['status'] === 'en attente' ? (isset($dupIds[$p['id']]) ? '<b style="color:var(--bad)">⚠️ Doublon : tranchez ci-dessous</b><br>' . $btn('photo_no', 'Refuser', 'ghost', $hid) : $btn('photo_ok', 'Valider', '', $hid) . ' ' . $btn('photo_no', 'Refuser', 'ghost', $hid))
         : ($p['status'] === 'publiée' ? '<span class="pill ok">✓ Publiée</span> ' . $btn('photo_no', 'Retirer', 'ghost', $hid) : '<span class="pill bad">Refusée</span>');
    $img = $p['public_file'] ? 'uploads/' . e($p['public_file']) : 'file.php?photo=' . (int)$p['id'];
    return '<figure' . (isset($dupIds[$p['id']]) ? ' class="dup"' : '') . '><img src="' . $img . '" alt="" loading="lazy"><figcaption>' . $act . '</figcaption></figure>';
}, $photos)) . '</div>' : '<p class="note">Aucune photo.</p>';

$dupHtml = $dups ? '<div class="card" style="border-color:var(--bad)"><h2 style="margin-top:0">⚠️ Photos en double avec un autre riad</h2>' . implode('', array_map(fn($d) => '<div class="row" style="margin-bottom:10px"><div><img src="file.php?photo=' . (int)$d['a']['id'] . '" style="width:100%;border-radius:8px"><div class="note">' . e($d['a']['riad_name']) . '</div></div><div><img src="file.php?photo=' . (int)$d['b']['id'] . '" style="width:100%;border-radius:8px"><div class="note">' . e($d['b']['riad_name']) . '</div></div></div><p>Ressemblance <b>' . $d['sim'] . ' %</b>. Vérifiez qui est le vrai propriétaire des photos (contrat, RIB, appel vidéo).</p>' . $btn('dup_ok', 'Pas de fraude (même propriétaire, photo autorisée)', 'ghost', '<input type="hidden" name="pair" value="' . e($d['pair']) . '">'), $dups)) . '</div>' : '';

render_page($r['name'], '<p><a href="admin.php">← Tous les riads</a></p><h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>
<div class="card"><h2 style="margin-top:0">Décision</h2>' .
($miss ? '<p>Dossier incomplet :</p><ul>' . implode('', array_map(fn($m) => '<li>' . e($m) . '</li>', $miss)) . '</ul>' : '<p>✅ Dossier complet.</p>') . '
<p>RIB : ' . bank_pill($r) . ' · Photos publiées : <b>' . count(array_filter($photos, fn($p) => $p['status'] === 'publiée')) . '</b> / ' . count($photos) . '</p>
<p>Import Booking : ' . ($r['import_done'] ? '<span class="pill ok">fait le ' . e(date('d/m/Y', strtotime($r['import_done'] . ' UTC'))) . '</span>' : ($r['import_requested'] ? '<span class="pill warn">demandé par le riad</span>' : '<span class="note">non demandé</span>')) . ' <a class="btn sm ghost" href="admin_import.php?id=' . $rid . '">Pré-remplir depuis Booking</a></p>
' . ($r['status'] === 'en ligne' ? $btn('offline', 'Retirer du site', 'ghost') : $btn('approve', 'Mettre en ligne')) . '
<form method="post" style="margin-top:12px">' . csrf_field() . '<label>Message au riad (si la fiche est à corriger)</label><input name="note" maxlength="500" placeholder="ex. Merci d\'ajouter des photos des chambres"><button class="btn sm ghost" name="action" value="refuse">Renvoyer pour correction</button></form>
<form method="post" style="margin-top:12px">' . csrf_field() . '<label>Commission (%)</label><div style="display:flex;gap:8px;align-items:center"><input name="commission" inputmode="decimal" style="max-width:110px" value="' . e(rtrim(rtrim(number_format((float)$r['commission'], 2, '.', ''), '0'), '.')) . '"><button class="btn sm" name="action" value="commission" style="margin-top:0">Enregistrer</button></div></form></div>
' . $dupHtml . '
<div class="card"><h2 style="margin-top:0">Photos</h2>' . $photoGrid . '</div>
<div class="card"><h2 style="margin-top:0">Compte bancaire</h2><div class="scroll"><table>' .
 $line('Titulaire', e($r['bank_holder']) . (holder_ok($r) ? ' <span class="pill ok">= société</span>' : ($r['bank_holder'] ? ' <span class="pill bad">≠ ' . e($r['co_legal'] ?: 'société') . '</span>' : ''))) .
 $line('Banque', e($r['bank_name'])) . $line('RIB', $r['bank_rib'] ? '<span style="font-family:monospace">' . e(trim(chunk_split($r['bank_rib'], 4, ' '))) . '</span> ' . (rib_ok($r['bank_rib']) ? '<span class="pill ok">clé OK</span>' : '<span class="pill bad">clé fausse</span>') : '') .
 $line('IBAN', e(iban_from_rib((string)$r['bank_rib']))) . $line('SWIFT', e($r['bank_swift'])) .
 $line('Attestation', $r['bank_doc'] ? '<a href="file.php?rib=' . $rid . '">📎 Télécharger</a>' : '<b style="color:var(--bad)">manquante</b>') .
 $line('Modifié le', e($r['bank_updated'] ? date('d/m/Y H:i', strtotime($r['bank_updated'] . ' UTC')) : '')) . '</table></div>
' . ($r['bank_rib'] && $r['bank_status'] !== 'vérifié' ? $btn('rib_ok', 'Valider le RIB', '', '') . ' ' . $btn('rib_no', 'Refuser le RIB', 'ghost') : '') . '</div>
<div class="card"><h2 style="margin-top:0">Société</h2><div class="scroll"><table>' .
 $line('Raison sociale', e($r['co_legal']) . ' ' . e($r['co_form'])) . $line('ICE', e($r['co_ice'])) . $line('RC', e($r['co_rc'])) . $line('IF', e($r['co_if'])) .
 $line('Autorisation touristique', e($r['co_license'])) . $line('Siège', e($r['co_address'])) . $line('Représentant', e(trim($r['co_rep'] . ', ' . $r['co_rep_role'], ', '))) . '</table></div></div>
<div class="card"><h2 style="margin-top:0">Riad</h2><div class="scroll"><table>' .
 $line('Propriétaire (compte)', e($r['first_name'] . ' ' . $r['last_name']) . '<br>' . e($r['email']) . '<br>' . e($r['phone'])) .
 $line('Ville / quartier', e($r['city'] . ($r['area'] ? ' · ' . $r['area'] : ''))) . $line('Adresse', e($r['address'])) .
 $line('Catégorie', $r['stars'] ? str_repeat('★', (int)$r['stars']) : '') . $line('GPS', $r['lat'] ? e($r['lat'] . ', ' . $r['lng']) : '') .
 $line('Équipements', e(implode(', ', $amen))) . $line('Petit-déjeuner', $r['breakfast'] ? 'Inclus' : 'Non inclus') . $line('Annulation', $r['free_cancel'] ? 'Gratuite jusqu\'à 48 h' : 'Non remboursable') .
 $line('Description', nl2br(e($r['description']))) . '</table></div></div>
<div class="card"><h2 style="margin-top:0">Chambres (' . count($rooms) . ')</h2>' . ($rooms ? '<div class="scroll"><table><thead><tr><th>Chambre</th><th>Pers.</th><th>Nombre</th><th>Prix / nuit</th></tr></thead><tbody>' . implode('', array_map(fn($x) => '<tr><td><b>' . e($x['name']) . '</b><br><span class="note">' . e($x['description']) . '</span></td><td>' . (int)$x['capacity'] . '</td><td>' . (int)$x['qty'] . '</td><td>' . e(number_format((float)$x['price_eur'], 0, ',', ' ')) . ' €</td></tr>', $rooms)) . '</tbody></table></div>' : '<p class="note">Aucune chambre.</p>') . '</div>
' . (function () use ($r, $rid) {
    [$st, $todo] = riad_sync_summary($r);
    $req = ($r['sync_booking_invite'] ? '<li>📩 Invitation Booking.com signalée le ' . e(fr_date(substr($r['sync_booking_invite'], 0, 10))) . ' : acceptez-la (email de Booking à ' . SYNC_TEAM_EMAIL . ') et installez les liens.</li>' : '')
         . ($r['sync_airbnb_call'] ? '<li>📞 Appel Airbnb demandé le ' . e(fr_date(substr($r['sync_airbnb_call'], 0, 10))) . ' : ' . e($r['phone']) . '</li>' : '');
    return '<div class="card"><h2 style="margin-top:0">Booking / Airbnb ' . sync_pill($r) . '</h2>'
      . ($req ? '<ul>' . $req . '</ul>' : '')
      . ($todo ? '<p class="note">Reste à faire :</p><ul class="note">' . implode('', array_map(fn($t) => '<li>' . e($t) . '</li>', $todo)) . '</ul>' : '')
      . '<a class="btn" href="sync.php?id=' . $rid . '">Ouvrir la synchronisation du riad</a>'
      . ($req ? ' <form method="post" style="display:inline">' . csrf_field() . '<button class="btn ghost" name="action" value="sync_done">Demande traitée</button></form>' : '') . '</div>';
})() . '
<p><a class="btn ghost" href="riad.php?id=' . $rid . '">Modifier la fiche à la place du riad</a> <a class="btn ghost" href="bookings.php?id=' . $rid . '">Réservations du riad</a> <a class="btn ghost" href="calendar.php?id=' . $rid . '">Calendrier</a> <a class="btn ghost" href="riad_view.php?id=' . $rid . '">Voir la page publique</a></p>');
