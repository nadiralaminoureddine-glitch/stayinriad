<?php
// Relevé mensuel : riad (le sien) ou admin (tous les riads, ou un riad avec ?id=)
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$u = require_login();
if (!in_array($u['role'], ['riad', 'admin'], true)) redirect('account.php');
$m = valid_month($_GET['m'] ?? null, date('Y-m'));
$adminAll = $u['role'] === 'admin' && empty($_GET['id']);
$r = $adminAll ? null : my_riad($u, isset($_GET['id']) ? (int)$_GET['id'] : null);
if (!$adminAll && !$r) redirect('account.php');
$rows = statement_rows($m, $r ? (int)$r['id'] : null);
if (isset($_GET['csv'])) statement_csv($rows, 'stayinriad-releve-' . ($r ? preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $r['name']) ?: 'riad')) . '-' : 'tous-') . $m . '.csv');

$t = statement_totals($rows);
$base = 'statement.php?' . ($r && $u['role'] === 'admin' ? 'id=' . (int)$r['id'] . '&' : '');
$nav = fn($d) => $base . 'm=' . date('Y-m', strtotime($m . '-01 ' . $d . ' month'));
$kpi = fn($l, $v) => '<div class="kpi"><small>' . $l . '</small><b>' . $v . '</b></div>';
$head = '<div class="card noprint"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><a class="btn sm ghost" href="' . e($nav('-1')) . '">←</a><h2 style="margin:0">' . e(ucfirst(month_label($m))) . '</h2><a class="btn sm ghost" href="' . e($nav('+1')) . '">→</a></div>
<p class="note">Séjours terminés dans le mois (date de départ) et frais d\'annulation. Montants en euros, net du riad aussi en dirhams au taux bloqué le jour de chaque réservation.</p>
<a class="btn sm" href="' . e($base . 'm=' . $m . '&csv=1') . '">Télécharger (Excel / CSV)</a> <a class="btn sm ghost" href="#" data-print>Imprimer / PDF</a></div>';
$kpis = '<div class="kpis">' . $kpi('Réservations', (string)$t['n']) . $kpi('Nuits', (string)$t['nights']) . $kpi('Payé par les voyageurs', e(eur($t['amount'])))
      . $kpi($adminAll ? 'Commissions StayInRiad' : 'Commission StayInRiad', e(eur($t['commission']))) . $kpi($adminAll ? 'Net dû aux riads' : 'Net pour vous', e(mad($t['net_mad'])))
      . $kpi('Déjà versé', e(mad($t['paid_mad']))) . $kpi('Reste à verser', e(mad($t['due_mad']))) . '</div>';

if ($adminAll) {
    $by = []; foreach ($rows as $b) $by[$b['riad_id']][] = $b;
    $lines = implode('', array_map(function ($rid) use ($by, $m) {
        $l = $by[$rid]; $x = statement_totals($l);
        return '<tr><td><a href="statement.php?id=' . (int)$rid . '&m=' . $m . '"><b>' . e($l[0]['riad_name']) . '</b></a></td><td>' . $x['n'] . '</td><td>' . $x['nights'] . '</td><td>' . e(eur($x['amount'])) . '</td><td><b>' . e(eur($x['commission'])) . '</b></td><td>' . e(mad($x['net_mad'])) . '</td><td>' . ($x['due_mad'] > 0 ? '<span class="pill warn">' . e(mad($x['due_mad'])) . '</span>' : '<span class="pill ok">Tout versé</span>') . '</td></tr>';
    }, array_keys($by)));
    $body = '<p class="noprint"><a href="account.php">← Administration</a></p><h1>Relevé mensuel · tous les riads</h1>' . $head . $kpis
      . '<div class="card"><h2 style="margin-top:0">Par riad</h2>' . ($by ? '<div class="scroll"><table><thead><tr><th>Riad</th><th>Rés.</th><th>Nuits</th><th>Payé par les voyageurs</th><th>Commission</th><th>Net riad</th><th>Reste à verser</th></tr></thead><tbody>' . $lines
        . '<tr><th>Total</th><th>' . $t['n'] . '</th><th>' . $t['nights'] . '</th><th>' . e(eur($t['amount'])) . '</th><th>' . e(eur($t['commission'])) . '</th><th>' . e(mad($t['net_mad'])) . '</th><th>' . e(mad($t['due_mad'])) . '</th></tr></tbody></table></div>' : '<p class="note">Aucun séjour terminé ce mois-ci.</p>') . '</div>';
} else {
    $q = $u['role'] === 'admin' ? '?id=' . (int)$r['id'] : '';
    $lines = implode('', array_map(fn($b) => '<tr><td><b>' . e($b['ref']) . '</b>' . ($b['test'] ? ' <span class="pill info">test</span>' : '') . '<br><span class="note">' . e($b['label']) . '</span></td><td>' . e($b['first_name'] . ' ' . $b['last_name']) . '<br><span class="note">' . e($b['room_name']) . '</span></td><td>' . e(fr_date($b['check_in'])) . ' → ' . e(fr_date($b['check_out'])) . '<br><span class="note">' . ($b['status'] === 'confirmée' ? (int)$b['nights'] . ' nuit(s)' : 'annulée') . '</span></td><td>' . e(eur($b['amount'])) . '</td><td>' . e(eur((float)$b['commission'])) . '<br><span class="note">' . e(rtrim(rtrim(number_format((float)$b['commission_rate'], 2, ',', ''), '0'), ',')) . ' %</span></td><td><b>' . e(mad((float)$b['net_mad'])) . '</b><br><span class="note">' . e(eur((float)$b['net'])) . '</span></td><td>' . ($b['paid'] ? '<span class="pill ok">Versé le ' . e(fr_date(substr($b['payout_at'], 0, 10))) . '</span>' : '<span class="pill warn">Lundi ' . e(fr_date(pay_day($b['check_out']))) . '</span>') . '</td></tr>', $rows));
    $body = '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1><div class="noprint">' . riad_tabs('releve', $q) . '</div>' . $head
      . '<div class="printonly"><h2>Relevé StayInRiad · ' . e($r['name']) . ' · ' . e(month_label($m)) . '</h2></div>' . $kpis
      . '<div class="card"><h2 style="margin-top:0">Détail</h2>' . ($rows ? '<div class="scroll"><table><thead><tr><th>Réservation</th><th>Client</th><th>Séjour</th><th>Payé</th><th>Commission</th><th>Net pour vous</th><th>Versement</th></tr></thead><tbody>' . $lines . '</tbody></table></div>' : '<p class="note">Aucun séjour terminé ce mois-ci.</p>') . '</div>';
}
render_page('Relevé mensuel', $body . '<script src="assets/print.js" defer></script>');
