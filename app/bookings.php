<?php
// Liste des réservations : riad (les siennes), admin (toutes), voyageur (les siennes)
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$u = require_login();
expire_holds();
$filter = in_array($_GET['f'] ?? '', ['up', 'past', 'all'], true) ? $_GET['f'] : 'up';
$cond = ['up' => "b.status = 'confirmée' AND b.check_out >= ?", 'past' => "b.status = 'confirmée' AND b.check_out < ?", 'all' => "? = ?"][$filter];
$cp = $filter === 'all' ? [1, 1] : [today()];
$q = '';

if ($u['role'] === 'admin' && empty($_GET['id'])) {
    $list = q("SELECT b.*, r.name AS riad_name FROM bookings b JOIN riads r ON r.id = b.riad_id WHERE $cond ORDER BY b.created_at DESC LIMIT 500", $cp)->fetchAll();
    $title = 'Toutes les réservations'; $top = '<p><a href="admin.php">← Administration</a></p>';
} elseif (in_array($u['role'], ['riad', 'admin'], true)) {
    $r = my_riad($u, isset($_GET['id']) ? (int)$_GET['id'] : null); if (!$r) redirect('account.php');
    $q = $u['role'] === 'admin' ? '?id=' . (int)$r['id'] : '';
    $list = q("SELECT b.*, '' AS riad_name FROM bookings b WHERE b.riad_id = ? AND $cond ORDER BY b.check_in", array_merge([$r['id']], $cp))->fetchAll();
    $title = 'Réservations'; $top = '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>' . riad_tabs('res', $q);
} else {
    $list = q("SELECT b.*, r.name AS riad_name FROM bookings b JOIN riads r ON r.id = b.riad_id WHERE (b.user_id = ? OR b.email = ?) AND $cond ORDER BY b.check_in DESC", array_merge([$u['id'], $u['email']], $cp))->fetchAll();
    $title = 'Mes réservations'; $top = '';
}
$isRiad = $u['role'] === 'riad' || ($u['role'] === 'admin' && !empty($_GET['id']));
// Demandes à confirmer (riad) / en attente (admin)
$pendSql = "SELECT b.*, r.name AS riad_name FROM bookings b JOIN riads r ON r.id = b.riad_id WHERE b.status = 'demande' AND b.hold_until > ?" . ($isRiad ? ' AND b.riad_id = ' . (int)$r['id'] : '') . ' ORDER BY b.hold_until';
$pend = ($isRiad || ($u['role'] === 'admin' && empty($_GET['id']))) ? q($pendSql, [now()])->fetchAll() : [];
$pendCard = $pend ? '<div class="card" style="border:2px solid #f2b632"><h2 style="margin-top:0">⏳ ' . count($pend) . ' demande' . (count($pend) > 1 ? 's' : '') . ' à confirmer</h2>' . implode('', array_map(fn($b) =>
    '<div class="line" style="align-items:center;gap:10px;flex-wrap:wrap"><span><b>' . e($b['room_name']) . '</b>' . ($isRiad ? '' : ' · ' . e($b['riad_name'])) . '<br><span class="note">' . e(stay_text($b)) . ' · ' . (int)$b['guests'] . ' pers. · ' . e($b['first_name']) . ' · reste ' . e(hours_left($b['hold_until'])) . '</span></span>'
    . '<span><a class="btn sm" href="' . e(respond_url($b, 'accept')) . '">Répondre</a></span></div>', $pend)) . '</div>' : '';
$admin = $u['role'] === 'admin' && empty($_GET['id']);
$sum = fn($k) => array_sum(array_map(fn($b) => $b['status'] === 'confirmée' ? (float)$b[$k] : 0, $list));
$base = 'bookings.php?' . ($q ? substr($q, 1) . '&' : '');
$tabs = '<div class="tabs small">' . implode('', array_map(fn($k, $l) => '<a href="' . e($base . 'f=' . $k) . '"' . ($k === $filter ? ' class="on"' : '') . '>' . $l . '</a>', ['up', 'past', 'all'], ['À venir', 'Passées', 'Toutes'])) . '</div>';

$rows = implode('', array_map(function ($b) use ($u, $isRiad, $admin) {
    $guest = e($b['first_name'] . ' ' . $b['last_name']) . '<br><span class="note">' . e($b['country']) . ($isRiad || $admin ? ' · ' . e($b['phone']) : '') . '</span>' . ($b['request'] ? '<br><span class="note">« ' . e($b['request']) . ' »</span>' : '');
    $money = $u['role'] === 'traveler' ? '<td class="num">' . eur((float)$b['total']) . '</td>'
        : '<td class="num">' . eur((float)$b['total']) . '</td><td class="num">' . eur((float)$b['commission']) . '<br><span class="note">' . e(rtrim(rtrim(number_format((float)$b['commission_rate'], 2, '.', ''), '0'), '.')) . ' %</span></td><td class="num"><b>' . eur((float)$b['net']) . '</b><br><span class="note">' . mad((float)$b['net_mad']) . '</span></td>';
    return '<tr><td><b>' . e($b['ref']) . '</b>' . (str_starts_with($b['payment_ref'], 'TEST-') ? '<br><span class="pill info">TEST</span>' : '') . '</td>' . ($admin || $u['role'] === 'traveler' ? '<td>' . e($b['riad_name']) . '</td>' : '') . '<td>' . $guest . '</td>
      <td style="white-space:nowrap">' . e(fr_date($b['check_in'])) . '<br>→ ' . e(fr_date($b['check_out'])) . '<br><span class="note">' . (int)$b['nights'] . ' nuit(s) · ' . (int)$b['guests'] . ' pers.</span></td><td>' . e($b['room_name']) . '</td>' . $money . '<td>' . booking_pill($b['status']) . '</td>'
      . ($u['role'] !== 'riad' ? '<td><a href="booking.php?ref=' . e(urlencode($b['ref'])) . '&t=' . e(urlencode($b['token'])) . '">Voir</a>' . ($b['refund_status'] === 'à rembourser' ? '<br><span class="pill bad">à rembourser</span>' : '') . '</td>' : '') . '</tr>';
}, $list));
$heads = '<th>N°</th>' . ($admin || $u['role'] === 'traveler' ? '<th>Riad</th>' : '') . '<th>Client</th><th>Séjour</th><th>Chambre</th>' . ($u['role'] === 'traveler' ? '<th>Total</th>' : '<th>Payé client</th><th>Commission</th><th>Net riad</th>') . '<th>Statut</th>' . ($u['role'] !== 'riad' ? '<th></th>' : '');
$kpis = $u['role'] === 'traveler' ? '' : '<div class="kpis"><div class="kpi"><small>Réservations confirmées</small><b>' . count(array_filter($list, fn($b) => $b['status'] === 'confirmée')) . '</b></div><div class="kpi"><small>Payé par les clients</small><b>' . eur($sum('total')) . '</b></div>' . ($admin ? '<div class="kpi"><small>Commissions StayInRiad</small><b>' . eur($sum('commission')) . '</b></div>' : '') . '<div class="kpi"><small>Net ' . ($admin ? 'aux riads' : 'pour vous') . '</small><b>' . eur($sum('net')) . '</b></div></div>';

render_page($title, $top . ($top === '' || $admin ? '<h1>' . e($title) . '</h1>' : '') . $pendCard . $tabs . $kpis . '<div class="card">' . ($list ? '<div class="scroll"><table><thead><tr>' . $heads . '</tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="note">Aucune réservation.</p>') . '</div>'
 . ($isRiad ? '<p class="note">Les montants nets sont versés en dirhams, au taux fixé le jour de la réservation. La taxe de séjour est payée sur place par le client, directement à vous.</p>' : ''));
