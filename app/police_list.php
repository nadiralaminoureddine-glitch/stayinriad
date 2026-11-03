<?php
// Espace riad — fiches de police des clients (à imprimer)
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$u = require_login();
if (!in_array($u['role'], ['riad', 'admin'], true)) redirect('account.php');
$r = my_riad($u, isset($_GET['id']) ? (int)$_GET['id'] : null);
if (!$r) redirect('account.php');
$rid = (int)$r['id'];
$q = $u['role'] === 'admin' ? '?id=' . $rid : '';

// Impression d'une ou plusieurs réservations
if (isset($_GET['print'])) {
    $refs = array_filter(explode(',', (string)$_GET['print']));
    $sheets = '';
    foreach ($refs as $ref) {
        $b = q("SELECT * FROM bookings WHERE ref = ? AND riad_id = ? AND status = 'confirmée'", [$ref, $rid])->fetch(); if (!$b) continue;
        foreach (q('SELECT guest_no, data FROM police_forms WHERE booking_id = ? ORDER BY guest_no', [$b['id']])->fetchAll() as $row) {
            $g = police_decrypt($row['data']);
            $sheets .= '<div class="sheet"><div class="sh-head"><div><b>' . e($r['name']) . '</b><br>' . e($r['address'] ?: $r['city']) . '<br>' . e($r['co_license'] ? 'Autorisation n° ' . $r['co_license'] : '') . '</div><div style="text-align:right"><b>FICHE DE POLICE</b><br><span dir="rtl">بطاقة الشرطة</span><br>N° ' . e($b['ref']) . '-' . (int)$row['guest_no'] . '</div></div>
            <table class="sh">' . implode('', array_map(fn($k) => '<tr><th>' . e(POLICE_FIELDS[$k][0]) . '</th><td>' . e(in_array($k, ['birth_date', 'doc_date', 'entry_date'], true) && !empty($g[$k]) ? date('d/m/Y', strtotime($g[$k])) : ($g[$k] ?? '')) . '</td><th dir="rtl" style="text-align:right">' . e(POLICE_FIELDS[$k][1]) . '</th></tr>', array_keys(POLICE_FIELDS))) . '
            <tr><th>Arrivée</th><td>' . e(date('d/m/Y', strtotime($b['check_in']))) . '</td><th dir="rtl" style="text-align:right">تاريخ الوصول</th></tr><tr><th>Départ prévu</th><td>' . e(date('d/m/Y', strtotime($b['check_out']))) . '</td><th dir="rtl" style="text-align:right">تاريخ المغادرة</th></tr>
            <tr><th>Chambre</th><td>' . e($b['room_name']) . '</td><th dir="rtl" style="text-align:right">الغرفة</th></tr></table>
            <div class="sh-sign"><div>Signature du voyageur<br><span dir="rtl">توقيع النزيل</span></div><div>Cachet de l\'établissement<br><span dir="rtl">خاتم المؤسسة</span></div></div></div>';
        }
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Fiches de police</title><link rel="stylesheet" href="assets/print.css"></head><body><p class="noprint">Utilisez <b>Ctrl + P</b> (ou Imprimer dans le menu du navigateur) : une fiche par page. <a href="police_list.php' . e($q) . '">← Retour</a></p>' . ($sheets ?: '<p>Aucune fiche remplie.</p>') . '</body></html>';
    exit;
}

$from = date('Y-m-d', strtotime('-2 days'));
$list = q("SELECT * FROM bookings WHERE riad_id = ? AND status = 'confirmée' AND check_out >= ? ORDER BY check_in", [$rid, $from])->fetchAll();
$rows = implode('', array_map(function ($b) use ($q) {
    $n = (int)q('SELECT COUNT(*) FROM police_forms WHERE booking_id = ?', [$b['id']])->fetchColumn();
    $state = $b['police_filled_at'] ? '<span class="pill ok">Remplie (' . $n . '/' . (int)$b['guests'] . ')</span>' : '<span class="pill warn">Pas encore remplie</span>';
    return '<tr><td><b>' . e($b['ref']) . '</b></td><td>' . e($b['first_name'] . ' ' . $b['last_name']) . '<br><span class="note">' . (int)$b['guests'] . ' pers. · ' . e($b['room_name']) . '</span></td><td style="white-space:nowrap">' . e(fr_date($b['check_in'])) . '<br>→ ' . e(fr_date($b['check_out'])) . '</td><td>' . $state . '</td><td>' . ($n ? '<a class="btn sm" href="police_list.php?' . e(http_build_query(array_filter(['id' => $q ? substr($q, 4) : null, 'print' => $b['ref']]))) . '" target="_blank" rel="noopener">🖨️ Imprimer</a>' : '') . '</td></tr>';
}, $list));
$filled = array_map(fn($b) => $b['ref'], array_filter($list, fn($b) => $b['police_filled_at'] && $b['check_in'] <= date('Y-m-d', strtotime('+1 day'))));

render_page('Fiches de police', '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>' . riad_tabs('police', $q) . '
<div class="card"><p style="margin-top:0">Le client remplit sa fiche <b>en ligne</b> après la réservation ; il reçoit un rappel par email la veille de son arrivée. Vous n\'avez plus qu\'à <b>imprimer</b> (une fiche par voyageur, en français et en arabe) et la faire signer à l\'arrivée.</p>'
 . ($filled ? '<a class="btn" target="_blank" rel="noopener" href="police_list.php?' . e(http_build_query(array_filter(['id' => $q ? substr($q, 4) : null, 'print' => implode(',', $filled)]))) . '">🖨️ Imprimer les arrivées d\'aujourd\'hui et de demain</a>' : '') . '</div>
<div class="card">' . ($list ? '<div class="scroll"><table><thead><tr><th>N°</th><th>Client</th><th>Séjour</th><th>Fiche</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="note">Aucune arrivée à venir.</p>') . '</div>
<p class="note">🔒 Les fiches sont chiffrées et visibles uniquement par vous et StayInRiad. Elles sont supprimées automatiquement après le délai de conservation.</p>');
