<?php
// Espace riad — fiche du riad (présentation, société, RIB) et envoi pour validation
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/import.php';

$u = require_login();
if (!in_array($u['role'], ['riad', 'admin'], true)) redirect('account.php');
$r = my_riad($u, isset($_GET['id']) ? (int)$_GET['id'] : null);
if (!$r) { flash('Aucun riad trouvé.', 'warn'); redirect('account.php'); }
$rid = (int)$r['id'];
$err = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = fn($k, $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $action = $_POST['action'] ?? 'save';

    if ($action === 'import_request') {
        $link = booking_link((string)($_POST['booking_url'] ?? ''));
        if (!$link) $err[] = 'Ce lien ne ressemble pas à une page Booking. Ouvrez votre riad sur Booking.com, copiez l\'adresse de la page (elle contient « booking.com/hotel/ma/ ») et collez-la ici.';
        else {
            import_request($rid, $link);
            flash('Merci ! Notre équipe pré-remplit votre fiche (description, chambres, photos) à partir de votre page Booking, en général sous 24 h. Vous recevrez un email : il vous restera à indiquer vos prix.');
            redirect('riad.php' . ($u['role'] === 'admin' ? '?id=' . $rid : ''));
        }
    } else if ($action === 'commission') {
        $c = (int)($_POST['commission'] ?? 0);
        if (!isset(COMMISSION_TIERS[$c])) $err[] = 'Choisissez une commission de 15, 18 ou 20 %.';
        elseif ((float)$r['commission'] !== (float)$c) {
            $old = rtrim(rtrim(number_format((float)$r['commission'], 2, '.', ''), '0'), '.');
            q('UPDATE riads SET commission = ?, updated_at = ? WHERE id = ?', [$c, now(), $rid]);
            log_action('commission_riad', $r['name'] . ' · ' . $old . ' % → ' . $c . ' %');
            send_mail(admin_email(), '[Commission] ' . $r['name'] . ' : ' . $old . ' % → ' . $c . ' %', mail_layout('Commission modifiée par le riad', '<p><b>' . e($r['name']) . '</b> a choisi une commission de <b>' . $c . ' %</b> (avant : ' . e($old) . ' %). Elle s\'applique aux nouvelles réservations.</p>'));
            flash('Commission enregistrée : ' . $c . ' %. Elle s\'applique à vos prochaines réservations.');
            redirect('riad.php' . ($u['role'] === 'admin' ? '?id=' . $rid : ''));
        } else redirect('riad.php' . ($u['role'] === 'admin' ? '?id=' . $rid : ''));
    } elseif ($action === 'submit') {
        $miss = dossier_missing($r);
        if ($miss) $err[] = 'Il manque encore : ' . implode(', ', $miss) . '.';
        elseif (photo_duplicates($rid)) $err[] = 'Une de vos photos ressemble à celle d\'un autre établissement. Notre équipe va la vérifier avant la mise en ligne.';
        else {
            q("UPDATE riads SET status = 'soumis', submitted_at = ?, updated_at = ? WHERE id = ?", [now(), now(), $rid]);
            $owner = q('SELECT first_name, last_name, email, phone FROM users WHERE id = ?', [(int)$r['owner_id']])->fetch();
            $nRooms = (int)q('SELECT COUNT(*) FROM rooms WHERE riad_id = ? AND active = 1', [$rid])->fetchColumn();
            send_mail(admin_email(), '[À vérifier] Fiche envoyée : ' . $r['name'] . ' (' . $r['city'] . ')', mail_layout('Une fiche riad est à vérifier', mail_rows(['Riad' => e($r['name']), 'Ville' => e($r['city']), 'Propriétaire' => e(trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''))), 'Téléphone' => e($owner['phone'] ?? ''), 'Email' => e($owner['email'] ?? ''), 'Chambres' => (string)$nRooms, 'Commission' => e(rtrim(rtrim(number_format((float)$r['commission'], 2, '.', ''), '0'), '.')) . ' %']) . '<p>À contrôler : société, RIB et attestation, photos. Puis « Mettre en ligne ».</p>' . mail_button(base_url() . 'admin_riad.php?id=' . $rid, 'Vérifier la fiche')));
            if ($owner && $u['role'] !== 'admin') send_mail($owner['email'], 'Fiche reçue : ' . $r['name'], mail_layout('Merci, votre fiche est bien reçue', '<p>Bonjour ' . e($owner['first_name']) . ',</p><p>Nous avons bien reçu la fiche de <b>' . e($r['name']) . '</b>. Notre équipe vérifie la société, le RIB et les photos, en général sous 24 h, et vous prévient par email dès que votre riad est en ligne.</p><p>Une question ? Écrivez-nous sur WhatsApp au <b>+212 707-596333</b> ou à contact@stayinriad.com.</p>' . mail_button(base_url() . 'account.php', 'Mon espace riad')));
            flash('Merci ! Votre fiche est envoyée. Notre équipe la vérifie et vous prévient dès qu\'elle est en ligne.');
            redirect('riad.php' . ($u['role'] === 'admin' ? '?id=' . $rid : ''));
        }
    } else {
        $city = in_array($p('city'), CITIES, true) ? $p('city') : $r['city'];
        $amen = array_values(array_intersect((array)($_POST['amen'] ?? []), AMENITIES));
        $ice = digits($p('co_ice')); $rib = digits($p('bank_rib'));
        if ($ice !== '' && strlen($ice) !== 15) $err[] = 'L\'ICE doit contenir 15 chiffres.';
        if ($rib !== '' && !rib_ok($rib)) $err[] = 'RIB non enregistré : la clé RIB est incorrecte. Vérifiez les 24 chiffres sur votre attestation.';
        $lat = $p('lat') === '' ? null : (float)str_replace(',', '.', $p('lat'));
        $lng = $p('lng') === '' ? null : (float)str_replace(',', '.', $p('lng'));
        if ($lat !== null && ($lat < 20.5 || $lat > 36.5 || $lng === null || $lng < -17.5 || $lng > -0.9)) { $err[] = 'Coordonnées GPS hors du Maroc : laissées vides.'; $lat = $lng = null; }

        // RIB : tout changement repasse en vérification (protection contre la fraude)
        $holder = $p('bank_holder', 160); $bankName = in_array($p('bank_name'), BANKS, true) ? $p('bank_name') : '';
        $newRib = ($rib !== '' && rib_ok($rib)) ? $rib : $r['bank_rib'];
        $bankChanged = $newRib !== $r['bank_rib'] || $holder !== $r['bank_holder'];
        $doc = $r['bank_doc'];
        if (!empty($_FILES['bank_doc']['name'])) {
            [$name, $e2] = save_private_doc($_FILES['bank_doc']);
            if ($e2) $err[] = $e2; else { $doc = $name; $bankChanged = true; }
        }
        $bankStatus = $newRib === '' ? 'non renseigné' : ($bankChanged ? 'à vérifier' : $r['bank_status']);

        q('UPDATE riads SET name=?, city=?, area=?, address=?, description=?, stars=?, amenities=?, breakfast=?, free_cancel=?, lat=?, lng=?,
            co_legal=?, co_form=?, co_ice=?, co_rc=?, co_if=?, co_license=?, co_address=?, co_rep=?, co_rep_role=?,
            bank_holder=?, bank_name=?, bank_rib=?, bank_swift=?, bank_doc=?, bank_status=?, bank_updated=?, updated_at=? WHERE id=?', [
            $p('name', 120) ?: $r['name'], $city, $p('area', 120), $p('address'), mb_substr(trim((string)($_POST['description'] ?? '')), 0, 3000),
            max(0, min(5, (int)$p('stars'))), json_encode($amen, JSON_UNESCAPED_UNICODE), empty($_POST['breakfast']) ? 0 : 1, empty($_POST['free_cancel']) ? 0 : 1, $lat, $lng,
            $p('co_legal', 160), in_array($p('co_form'), FORMS, true) ? $p('co_form') : '', $ice === '' || strlen($ice) === 15 ? $ice : $r['co_ice'],
            $p('co_rc', 60), digits($p('co_if')), $p('co_license', 80), $p('co_address'), $p('co_rep', 120), $p('co_rep_role', 60),
            $holder, $bankName, $newRib, strtoupper($p('bank_swift', 11)), $doc, $bankStatus, $bankChanged ? now() : $r['bank_updated'], now(), $rid]);
        if ($bankChanged && $r['bank_rib'] !== '') {
            flash('Nouveau RIB enregistré : il sera vérifié par StayInRiad avant le prochain virement.', 'warn');
            $owner = q('SELECT * FROM users WHERE id = ?', [$r['owner_id']])->fetch();
            $msg = '<p>Bonjour ' . e($owner['first_name']) . ',</p><p>Le <b>compte bancaire</b> de <b>' . e($r['name']) . '</b> vient d\'être modifié sur StayInRiad (nouveau compte : ' . e(mask_rib($newRib)) . '). Par sécurité, les virements sont suspendus jusqu\'à sa vérification par notre équipe.</p><p style="background:#fbebeb;padding:12px;border-radius:8px"><b>Ce n\'est pas vous ?</b> Contactez-nous immédiatement à contact@stayinriad.com et changez votre mot de passe.</p>';
            if ($owner) { send_mail($owner['email'], '⚠️ Votre RIB StayInRiad a été modifié', mail_layout('Changement de compte bancaire', $msg)); if (intl_phone((string)$owner['phone'])) send_sms((string)$owner['phone'], 'stayinriad.com : le RIB de ' . $r['name'] . ' vient d\'etre modifie. Si ce n\'est pas vous, contactez contact@stayinriad.com immediatement.'); }
            send_mail(admin_email(), '[StayInRiad] RIB modifié : ' . $r['name'] . ' (à vérifier)', mail_layout('RIB modifié', '<p>' . e($r['name']) . ' : nouveau RIB ' . e(mask_rib($newRib)) . ', modifié par ' . e($u['email']) . '. Vérifiez l\'attestation avant le prochain virement.</p>' . mail_button(base_url() . 'admin_riad.php?id=' . $rid, 'Vérifier')));
            log_action('rib_modifié', $r['name'] . ' · ' . mask_rib($newRib));
        }
        elseif (!$err) flash('Fiche enregistrée.');
        if (!$err) redirect('riad.php' . ($u['role'] === 'admin' ? '?id=' . $rid : ''));
        $r = my_riad($u, $rid) ?? $r;
    }
}

$v = fn($k) => e($r[$k] ?? '');
$sel = fn(array $opts, string $cur, string $name) => '<select name="' . $name . '"><option value="">—</option>' . implode('', array_map(fn($o) => '<option' . ($o === $cur ? ' selected' : '') . '>' . e($o) . '</option>', $opts)) . '</select>';
$amen = json_decode((string)($r['amenities'] ?? '[]'), true) ?: [];
$miss = dossier_missing($r);
$ribLine = $r['bank_rib'] ? '<p class="note">Compte enregistré : <b>' . e(mask_rib($r['bank_rib'])) . '</b> · IBAN ' . e(substr(iban_from_rib($r['bank_rib']), 0, 4)) . '… ' . bank_pill($r) . '</p>' : '';
$q = $u['role'] === 'admin' ? '?id=' . $rid : '';

$statusBox = match ($r['status']) {
    'en ligne' => '<div class="flash ok">✅ Votre riad est <b>en ligne</b>. Toute modification importante est vérifiée par notre équipe.</div>',
    'soumis' => '<div class="flash warn">⏳ Votre fiche est <b>en cours de vérification</b> par l\'équipe StayInRiad.</div>',
    'refusé' => '<div class="flash bad">✏️ Votre fiche est à corriger' . ($r['admin_note'] ? ' : <b>' . e($r['admin_note']) . '</b>' : '.') . ' Modifiez-la puis renvoyez-la.</div>',
    default => '',
};

render_page('Fiche du riad', '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>' . riad_tabs('fiche', $q) . $statusBox . '
' . ($err ? '<div class="flash bad">' . implode('<br>', array_map('e', $err)) . '</div>' : '') . '
' . import_card($r, $u) . '<form method="post" enctype="multipart/form-data">' . csrf_field() . '
<div class="card"><h2 style="margin-top:0">Présentation</h2>
<div class="row"><div><label>Nom du riad</label><input name="name" required maxlength="120" value="' . $v('name') . '"></div><div><label>Ville</label>' . $sel(CITIES, (string)$r['city'], 'city') . '</div></div>
<div class="row"><div><label>Quartier</label><input name="area" maxlength="120" placeholder="ex. Médina, Kasbah…" value="' . $v('area') . '"></div><div><label>Catégorie (étoiles)</label><select name="stars">' . implode('', array_map(fn($n) => '<option value="' . $n . '"' . ((int)$r['stars'] === $n ? ' selected' : '') . '>' . ($n ? str_repeat('★', $n) : 'Non classé') . '</option>', range(0, 5))) . '</select></div></div>
<label>Adresse du riad</label><input name="address" maxlength="255" value="' . $v('address') . '">
<label>Description (au moins 80 caractères)</label><textarea name="description" rows="5" maxlength="3000" style="width:100%;border:1px solid #cdbfa9;border-radius:8px;padding:11px;font:inherit">' . $v('description') . '</textarea>
<label>Équipements</label><div class="checks">' . implode('', array_map(fn($a) => '<label class="chk"><input type="checkbox" name="amen[]" value="' . e($a) . '"' . (in_array($a, $amen, true) ? ' checked' : '') . '> ' . e($a) . '</label>', AMENITIES)) . '</div>
<label class="chk"><input type="checkbox" name="breakfast" value="1"' . ($r['breakfast'] ? ' checked' : '') . '> Petit-déjeuner inclus dans le prix</label>
<label class="chk"><input type="checkbox" name="free_cancel" value="1"' . ($r['free_cancel'] ? ' checked' : '') . '> Annulation gratuite jusqu\'à 48 h avant l\'arrivée</label>
<label>Emplacement du riad sur la carte</label>
<p class="note" style="margin:0 0 8px">Touchez la carte à l\'endroit exact de votre riad (zoomez sur votre derb), ou utilisez votre position si vous êtes au riad. Vous pouvez ensuite déplacer l\'épingle.</p>
<div id="mapPick" style="height:340px;border-radius:12px;border:1px solid #cdbfa9;overflow:hidden;z-index:0"></div>
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:8px 0 4px"><button type="button" class="btn sm ghost" id="mapMe">📍 Utiliser ma position</button><span class="note" id="mapStatus"></span></div>
<div class="row"><div><label>Latitude GPS (facultatif)</label><input name="lat" inputmode="decimal" placeholder="31.6295" value="' . $v('lat') . '"></div><div><label>Longitude GPS (facultatif)</label><input name="lng" inputmode="decimal" placeholder="-7.9811" value="' . $v('lng') . '"></div></div>
<p class="note">Rempli automatiquement par la carte. Astuce : dans Google Maps, un appui long sur votre riad affiche aussi ses coordonnées.</p></div>

<div class="card"><h2 style="margin-top:0">Société <span class="pill info">avant le 1er virement</span></h2>
<p class="note">Pas obligatoire pour être en ligne. Ces informations et votre RIB sont nécessaires seulement pour recevoir vos virements : vous pouvez les compléter plus tard.</p>
<div class="row"><div><label>Raison sociale</label><input name="co_legal" maxlength="160" placeholder="ex. SARL Dar Yasmina" value="' . $v('co_legal') . '"></div><div><label>Forme juridique</label>' . $sel(FORMS, (string)$r['co_form'], 'co_form') . '</div></div>
<div class="row"><div><label>ICE (15 chiffres)</label><input name="co_ice" inputmode="numeric" maxlength="20" value="' . $v('co_ice') . '"></div><div><label>Registre de commerce (n° et ville)</label><input name="co_rc" maxlength="60" value="' . $v('co_rc') . '"></div></div>
<div class="row"><div><label>Identifiant fiscal (IF)</label><input name="co_if" inputmode="numeric" maxlength="30" value="' . $v('co_if') . '"></div><div><label>Autorisation d\'exploitation touristique <span class="note">(facultatif)</span></label><input name="co_license" maxlength="80" value="' . $v('co_license') . '"></div></div>
<label>Adresse du siège</label><input name="co_address" maxlength="255" value="' . $v('co_address') . '">
<div class="row"><div><label>Représentant légal</label><input name="co_rep" maxlength="120" value="' . $v('co_rep') . '"></div><div><label>Fonction</label><input name="co_rep_role" maxlength="60" placeholder="ex. Gérant" value="' . $v('co_rep_role') . '"></div></div></div>

<div class="card"><h2 style="margin-top:0">Compte bancaire pour les virements</h2>
<p class="note">Le compte doit être <b>au nom de la société</b> ci-dessus. Les versements sont faits en dirhams. Tout changement de RIB est vérifié avant le virement suivant.</p>' . $ribLine . '
<div class="row"><div><label>Titulaire du compte</label><input name="bank_holder" maxlength="160" value="' . $v('bank_holder') . '"></div><div><label>Banque</label>' . $sel(BANKS, (string)$r['bank_name'], 'bank_name') . '</div></div>
<div class="row"><div><label>RIB (24 chiffres)</label><input name="bank_rib" inputmode="numeric" maxlength="40" placeholder="' . ($r['bank_rib'] ? 'Laisser vide pour garder le RIB enregistré' : '000 000 0000000000000000 00') . '" value=""></div><div><label>Code SWIFT (facultatif)</label><input name="bank_swift" maxlength="11" value="' . $v('bank_swift') . '"></div></div>
<label>Attestation de RIB (PDF, JPG ou PNG, 5 Mo max)</label><input type="file" name="bank_doc" accept="application/pdf,image/jpeg,image/png">' . ($r['bank_doc'] ? '<p class="note">📎 Attestation reçue. Envoyez un nouveau fichier seulement si votre RIB change.</p>' : '') . '</div>

<button class="btn" name="action" value="save">Enregistrer</button></form>

' . commission_card($r) . '
<div class="card" style="margin-top:18px"><h2 style="margin-top:0">Envoyer pour validation</h2>' .
($r['status'] === 'en ligne' ? '<p class="note">Votre riad est déjà en ligne.</p>' :
 ($r['status'] === 'soumis' ? '<p class="note">Fiche déjà envoyée : vérification en cours.</p>' :
  ($miss ? '<p>Avant d\'envoyer votre fiche, il manque :</p><ul>' . implode('', array_map(fn($m) => '<li>' . e($m) . '</li>', $miss)) . '</ul>' : '<p>Votre fiche est complète. Envoyez-la à l\'équipe StayInRiad pour la mise en ligne.</p>')
  . '<form method="post">' . csrf_field() . '<button class="btn" name="action" value="submit"' . ($miss ? ' disabled' : '') . '>Envoyer ma fiche</button></form>')) . '</div>' . '<link rel="stylesheet" href="assets/leaflet/leaflet.css"><script src="assets/leaflet/leaflet.js" defer></script><script src="assets/map-pick.js" defer></script>');
