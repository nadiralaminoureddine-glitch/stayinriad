<?php
// Fiche de police remplie en ligne par le client (lien privé de la réservation). Données chiffrées.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$b = q('SELECT * FROM bookings WHERE ref = ?', [(string)($_GET['ref'] ?? '')])->fetch();
if (!$b || !hash_equals($b['token'], (string)($_GET['t'] ?? '')) || $b['status'] !== 'confirmée') { http_response_code(404); render_page('Fiche de police', '<div class="card"><h1>Lien invalide</h1><p>Cette réservation est introuvable ou n\'est plus active.</p></div>'); exit; }
$back = 'booking.php?ref=' . urlencode($b['ref']) . '&t=' . urlencode($b['token']);
$r = q('SELECT name FROM riads WHERE id = ?', [$b['riad_id']])->fetch();
$n = max(1, (int)$b['guests']);
const DOCS = ['Passeport', 'Carte d\'identité', 'CIN (Maroc)', 'Titre de séjour'];
$existing = [];
foreach (q('SELECT guest_no, data FROM police_forms WHERE booking_id = ? ORDER BY guest_no', [$b['id']])->fetchAll() as $row) $existing[(int)$row['guest_no']] = police_decrypt($row['data']);
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $guests = [];
    for ($i = 1; $i <= $n; $i++) {
        $g = [];
        foreach (array_keys(POLICE_FIELDS) as $k) $g[$k] = mb_substr(trim((string)($_POST["g$i"][$k] ?? '')), 0, 160);
        if (!in_array($g['doc_type'], DOCS, true)) $g['doc_type'] = DOCS[0];
        foreach (['last', 'first', 'birth_date', 'nationality', 'doc_no'] as $req) if ($g[$req] === '') { $err = 'Voyageur ' . $i . ' : merci de remplir au moins le nom, le prénom, la date de naissance, la nationalité et le numéro de pièce d\'identité.'; break 2; }
        $guests[$i] = $g;
    }
    if (!$err && empty($_POST['consent'])) $err = 'Merci de confirmer que les informations sont exactes.';
    if (!$err) {
        db()->beginTransaction();
        q('DELETE FROM police_forms WHERE booking_id = ?', [$b['id']]);
        foreach ($guests as $i => $g) q('INSERT INTO police_forms (booking_id, guest_no, data, created_at) VALUES (?,?,?,?)', [$b['id'], $i, police_encrypt($g), now()]);
        q('UPDATE bookings SET police_filled_at = ? WHERE id = ?', [now(), $b['id']]);
        db()->commit();
        flash('Merci ! Votre fiche de police est enregistrée et transmise de façon sécurisée au riad.');
        redirect($back);
    }
}

$types = ['birth_date' => 'date', 'doc_date' => 'date', 'entry_date' => 'date'];
$form = '';
for ($i = 1; $i <= $n; $i++) {
    $g = $_POST["g$i"] ?? ($existing[$i] ?? []);
    $f = function ($k) use ($i, $g, $types) {
        [$fr, $ar] = POLICE_FIELDS[$k];
        $val = e((string)($g[$k] ?? ($k === 'to' ? '' : '')));
        if ($k === 'doc_type') return '<div><label>' . e($fr) . ' <span class="note" dir="rtl">' . e($ar) . '</span></label><select name="g' . $i . '[doc_type]">' . implode('', array_map(fn($d) => '<option' . (($g['doc_type'] ?? '') === $d ? ' selected' : '') . '>' . e($d) . '</option>', DOCS)) . '</select></div>';
        return '<div><label>' . e($fr) . ' <span class="note" dir="rtl">' . e($ar) . '</span></label><input type="' . ($types[$k] ?? 'text') . '" name="g' . $i . '[' . $k . ']" maxlength="160" value="' . $val . '"></div>';
    };
    $form .= '<div class="card"><h2 style="margin-top:0">Voyageur ' . $i . ($i === 1 ? ' (titulaire de la réservation)' : '') . '</h2>
      <div class="row">' . $f('last') . $f('first') . '</div><div class="row">' . $f('birth_date') . $f('birth_place') . '</div>
      <div class="row">' . $f('nationality') . $f('profession') . '</div>' . $f('address') . '
      <div class="row">' . $f('doc_type') . $f('doc_no') . '</div><div class="row">' . $f('doc_date') . $f('entry_date') . '</div>
      <div class="row">' . $f('from') . $f('to') . '</div></div>';
}
render_page('Fiche de police', '<p><a href="' . e($back) . '">← Ma réservation</a></p><h1>Fiche de police</h1>
<p>Réservation <b>' . e($b['ref']) . '</b> · ' . e($r['name']) . ' · ' . e(fr_date($b['check_in'])) . ' → ' . e(fr_date($b['check_out'])) . '</p>
<p class="note">Au Maroc, les établissements d\'hébergement doivent enregistrer chaque voyageur. Vos informations sont <b>chiffrées</b>, transmises uniquement au riad pour la déclaration obligatoire, puis supprimées après le délai légal de conservation.</p>
' . ($err ? '<div class="flash bad">' . e($err) . '</div>' : '') . '
<form method="post">' . csrf_field() . $form . '
<label class="chk"><input type="checkbox" name="consent" value="1"> Je certifie que ces informations sont exactes.</label>
<button class="btn">Enregistrer la fiche</button></form>');
