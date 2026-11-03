<?php
// Espace riad — calendrier : chambres libres par jour, fermeture de dates
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
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
if ($month < date('Y-m')) $month = date('Y-m');
$first = $month . '-01'; $days = (int)date('t', strtotime($first));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roomId = (int)($_POST['room_id'] ?? 0); $day = (string)($_POST['day'] ?? '');
    $ok = q('SELECT 1 FROM rooms WHERE id = ? AND riad_id = ?', [$roomId, $rid])->fetch() && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && $day >= today();
    if ($ok) {
        if (q('SELECT 1 FROM closed_days WHERE room_id = ? AND day = ?', [$roomId, $day])->fetch()) q('DELETE FROM closed_days WHERE room_id = ? AND day = ?', [$roomId, $day]);
        else q('INSERT INTO closed_days (room_id, day) VALUES (?, ?)', [$roomId, $day]);
    }
    redirect('calendar.php?' . http_build_query(array_filter(['id' => $u['role'] === 'admin' ? $rid : null, 'm' => $month])));
}

$rooms = q('SELECT * FROM rooms WHERE riad_id = ? AND active = 1 ORDER BY id', [$rid])->fetchAll();
$mo = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
$label = $mo[(int)substr($month, 5, 2) - 1] . ' ' . substr($month, 0, 4);
$nav = fn($delta) => 'calendar.php?' . http_build_query(array_filter(['id' => $u['role'] === 'admin' ? $rid : null, 'm' => date('Y-m', strtotime("$first $delta month"))]));
$head = ''; for ($i = 0; $i < $days; $i++) { $d = date('Y-m-d', strtotime("$first +$i days")); $head .= '<th class="' . (in_array(date('N', strtotime($d)), ['6', '7']) ? 'we' : '') . '">' . (int)date('j', strtotime($d)) . '</th>'; }
$rows = implode('', array_map(function ($x) use ($first, $days, $q) {
    $cells = '';
    foreach (room_days($x, $first, $days) as $d => $s) {
        $past = $d < today();
        $isExt = !$s['closed'] && $s['free'] === 0 && $s['ext'] > 0;
        $cls = $s['closed'] ? 'closed' : ($isExt ? 'ext' : ($s['free'] === 0 ? 'full' : ($s['used'] ? 'part' : 'free')));
        $txt = $s['closed'] ? '✕' : ($isExt ? '⇄' : (string)$s['free']);
        $cells .= '<td class="' . $cls . ($past ? ' past' : '') . '">' . ($past ? $txt : '<form method="post">' . csrf_field() . '<input type="hidden" name="room_id" value="' . (int)$x['id'] . '"><input type="hidden" name="day" value="' . $d . '"><button title="' . ($s['closed'] ? 'Rouvrir' : 'Fermer') . ' le ' . e(fr_date($d)) . '">' . $txt . '</button></form>') . '</td>';
    }
    return '<tr><th class="rn">' . e($x['name']) . '<br><span class="note">' . (int)$x['qty'] . ' ch.</span></th>' . $cells . '</tr>';
}, $rooms));

render_page('Calendrier', '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>' . riad_tabs('cal', $q) . '
<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><a class="btn sm ghost" href="' . e($nav(-1)) . '">←</a><h2 style="margin:0">' . e(ucfirst($label)) . '</h2><a class="btn sm ghost" href="' . e($nav(1)) . '">→</a></div>
<p class="note">Chaque case indique le <b>nombre de chambres encore libres</b>. Touchez une case pour <b>fermer</b> la vente ce jour-là (✕), ou pour la rouvrir. Les réservations StayInRiad, et celles de Booking/Airbnb si vous avez activé la <a href="sync.php' . $q . '">synchronisation</a>, sont déduites automatiquement.</p>
' . ($rooms ? '<div class="scroll"><table class="cal"><thead><tr><th></th>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="note">Ajoutez d\'abord vos chambres.</p>') . '
<p class="note"><span class="lg free"></span> libre · <span class="lg part"></span> en partie réservé · <span class="lg full"></span> complet · <span class="lg ext"></span> ⇄ réservé sur Booking/Airbnb · <span class="lg closed"></span> fermé</p></div>');
