<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/stats.php';

require_login('admin');
$days = (int)($_GET['j'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;
$today = date('Y-m-d');
$from = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));

// visiteurs = identifiants anonymes distincts par jour ayant réellement regardé une page
$uniq = fn(string $a, string $b, string $ev = 'view') => (int)q("SELECT COUNT(*) FROM (SELECT DISTINCT day, vid FROM visits WHERE ev = ? AND day BETWEEN ? AND ?) t", [$ev, $a, $b])->fetchColumn();
$vToday = $uniq($today, $today);
$vYest = $uniq(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')));
$vPeriod = $uniq($from, $today);
$pages = (int)q("SELECT COUNT(*) FROM visits WHERE ev = 'view' AND day BETWEEN ? AND ?", [$from, $today])->fetchColumn();
$cta = $uniq($from, $today, 'cta');
$videos = $uniq($from, $today, 'video');
$signups = (int)q("SELECT COUNT(*) FROM users WHERE role = 'riad' AND created_at >= ?", [$from . ' 00:00:00'])->fetchColumn();
$fmt = fn($n) => number_format((float)$n, 0, ',', ' ');

// courbe par jour
$perDay = [];
foreach (q("SELECT day, COUNT(DISTINCT vid) n FROM visits WHERE ev = 'view' AND day BETWEEN ? AND ? GROUP BY day", [$from, $today]) as $r) $perDay[$r['day']] = (int)$r['n'];
$max = max(1, ...array_values($perDay ?: [0]));
$bars = '';
for ($t = strtotime($from); $t <= strtotime($today); $t += 86400) {
    $k = date('Y-m-d', $t); $n = $perDay[$k] ?? 0;
    $bars .= '<div class="sb" title="' . e(date('d/m', $t) . ' : ' . $n . ' visiteur' . ($n > 1 ? 's' : '')) . '"><i style="height:' . max($n ? 3 : 0, round($n / $max * 100)) . '%"></i>'
           . ($days <= 30 ? '<span>' . (int)date('j', $t) . '</span>' : '') . '</div>';
}

// tableaux : source (première page vue du visiteur dans la journée), pays, pages, appareils
$firsts = q("SELECT v.src, v.country, v.dev FROM visits v JOIN (SELECT day, vid, MIN(id) id FROM visits WHERE ev = 'view' AND day BETWEEN ? AND ? GROUP BY day, vid) f ON f.id = v.id", [$from, $today])->fetchAll();
$tally = function (array $rows, string $col, callable $label): array {
    $c = [];
    foreach ($rows as $r) { $k = $label((string)$r[$col]); $c[$k] = ($c[$k] ?? 0) + 1; }
    arsort($c); return $c;
};
$srcT = $tally($firsts, 'src', fn($s) => $s === '' || $s === '=' ? 'Lien direct (tapé, favori, email, WhatsApp…)' : $s);
$ctyT = $tally($firsts, 'country', fn($c) => $c === '' ? 'Inconnu' : (STATS_COUNTRY_NAME[$c] ?? $c));
$devT = $tally($firsts, 'dev', fn($d) => ucfirst($d ?: 'inconnu'));
$pageT = [];
foreach (q("SELECT path, COUNT(*) n FROM (SELECT DISTINCT path, day, vid FROM visits WHERE ev = 'view' AND day BETWEEN ? AND ?) t GROUP BY path ORDER BY n DESC LIMIT 12", [$from, $today]) as $r) $pageT[stats_page_label($r['path'])] = (int)$r['n'];

$table = function (string $title, array $c, int $total) use ($fmt): string {
    if (!$c) return '<div class="card"><h2 style="margin-top:0">' . $title . '</h2><p class="note">Pas encore de données.</p></div>';
    $rows = '';
    foreach (array_slice($c, 0, 10, true) as $k => $n) {
        $pct = $total ? round($n / $total * 100) : 0;
        $rows .= '<div class="bar"><span>' . e($k) . '</span><span class="track"><i style="width:' . $pct . '%;background:var(--green)"></i></span><b>' . $fmt($n) . '</b></div>';
    }
    return '<div class="card"><h2 style="margin-top:0">' . $title . '</h2>' . $rows . '</div>';
};
$tabs = implode(' ', array_map(fn($j, $l) => '<a class="btn sm' . ($j === $days ? '' : ' ghost') . '" href="?j=' . $j . '">' . $l . '</a>', [7, 30, 90, 365], ['7 jours', '30 jours', '3 mois', '1 an']));
$kpi = fn($label, $val, $sub = '') => '<div class="kpi"><span>' . $label . '</span><b>' . $val . '</b>' . ($sub ? '<small>' . $sub . '</small>' : '') . '</div>';

render_page('Visites', '<style>
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:14px 0}
.kpi{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px 16px}.kpi span{display:block;font-size:13px;color:var(--muted)}
.kpi b{display:block;font-size:28px;color:var(--green);margin-top:4px}.kpi small{color:var(--muted);font-size:12px}
.spark{display:flex;align-items:flex-end;gap:3px;height:160px;padding-bottom:18px}.sb{flex:1;height:100%;display:flex;flex-direction:column;justify-content:flex-end;position:relative}
.sb i{display:block;background:var(--green);border-radius:3px 3px 0 0;min-height:0}.sb span{position:absolute;bottom:-18px;left:0;right:0;text-align:center;font-size:10px;color:var(--muted)}
.sb:not(:nth-child(5n+1)) span{display:none}@media(min-width:700px){.sb span{display:block!important}}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:0 16px}.grid2 .bar{grid-template-columns:1.4fr 1fr 40px}
</style>
<h1>Visites du site</h1>
<p>' . $tabs . '</p>
<div class="kpis">' . $kpi('Aujourd\'hui', $fmt($vToday), 'hier : ' . $fmt($vYest)) . $kpi('Visiteurs (' . ($days === 365 ? '1 an' : $days . ' j') . ')', $fmt($vPeriod), $fmt($pages) . ' pages vues')
 . $kpi('Clics « Inscrire mon riad »', $fmt($cta)) . $kpi('Vidéo tutoriel lancée', $fmt($videos)) . $kpi('Riads inscrits', $fmt($signups), 'comptes créés sur la période') . '</div>
<div class="card"><h2 style="margin-top:0">Visiteurs par jour</h2><div class="spark">' . $bars . '</div></div>
<div class="grid2">' . $table('D\'où ils viennent', $srcT, count($firsts)) . $table('Pays (d\'après le fuseau horaire)', $ctyT, count($firsts)) . $table('Pages les plus vues', $pageT, max(1, $vPeriod)) . $table('Appareils', $devT, count($firsts)) . '</div>
<p class="note">Seules les vraies personnes sont comptées : les robots, vous-même et les appareils sur lesquels vous vous êtes connecté en admin sont exclus. Un visiteur est compté une fois par jour. Aucun cookie n\'est déposé chez les visiteurs. Astuce : ajoutez <b>?utm_source=instagram</b> au lien de votre bio Instagram pour reconnaître ces visiteurs à coup sûr.</p>');
