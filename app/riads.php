<?php
// Page publique : recherche des riads en ligne
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';
require_public_open();

$city = in_array($_GET['city'] ?? '', CITIES, true) ? $_GET['city'] : '';
$g = max(1, min(12, (int)($_GET['g'] ?? 2)));
$in = $_GET['in'] ?? ''; $out = $_GET['out'] ?? '';
$err = null; $n = 0;
if ($in !== '' || $out !== '') { [$in, $out, $n, $err] = parse_stay($in, $out); if ($err) { $in = $_GET['in'] ?? ''; $out = $_GET['out'] ?? ''; } }
$dated = $n > 0;

$sql = "SELECT * FROM riads WHERE status = 'en ligne'" . ($city ? ' AND city = ?' : '') . ' ORDER BY name';
$riads = q($sql, $city ? [$city] : [])->fetchAll();
$cards = [];
foreach ($riads as $r) {
    $rooms = q('SELECT * FROM rooms WHERE riad_id = ? AND active = 1 AND price_eur > 0 AND capacity >= ? ORDER BY price_eur', [$r['id'], $g])->fetchAll();
    if ($dated) $rooms = array_values(array_filter($rooms, fn($x) => room_free($x, $in, $out) > 0));
    if (!$rooms) continue;
    $from = (float)$rooms[0]['price_eur'];
    $ph = riad_photos((int)$r['id']);
    $qs = http_build_query(array_filter(['id' => $r['id'], 'in' => $dated ? $in : null, 'out' => $dated ? $out : null, 'g' => $g]));
    $cards[] = '<a class="rcard" href="riad_view.php?' . e($qs) . '"><div class="rimg">' . ($ph ? '<img src="uploads/' . e($ph[0]) . '" alt="" loading="lazy">' : '<div class="noimg">🕌</div>') . '</div>
      <div class="rbody"><b>' . e($r['name']) . '</b><div class="note">📍 ' . e(($r['area'] ? $r['area'] . ', ' : '') . $r['city']) . ($r['stars'] ? ' · ' . str_repeat('★', (int)$r['stars']) : '') . '</div>
      <div class="rprice">' . ($dated ? '<b>' . eur($from * $n) . '</b> <span class="note">pour ' . $n . ' nuit' . ($n > 1 ? 's' : '') . '</span>' : '<span class="note">à partir de</span> <b>' . eur($from) . '</b> <span class="note">/ nuit</span>') . '</div>
      ' . ($r['free_cancel'] ? '<div class="note" style="color:var(--ok)">Annulation gratuite</div>' : '') . '</div></a>';
}

$opts = '<option value="">Toutes les villes</option>' . implode('', array_map(fn($c) => '<option' . ($c === $city ? ' selected' : '') . '>' . e($c) . '</option>', array_diff(CITIES, ['Autre'])));
render_page('Trouver un riad', '<h1>Trouvez votre riad</h1>
<form class="card search" method="get">
 <div><label>Destination</label><select name="city">' . $opts . '</select></div>
 <div><label>Arrivée</label><input type="date" name="in" min="' . today() . '" value="' . e($in) . '"></div>
 <div><label>Départ</label><input type="date" name="out" min="' . today() . '" value="' . e($out) . '"></div>
 <div><label>Personnes</label><select name="g">' . implode('', array_map(fn($i) => '<option value="' . $i . '"' . ($i === $g ? ' selected' : '') . '>' . $i . '</option>', range(1, 8))) . '</select></div>
 <div><button class="btn full">Rechercher</button></div></form>
' . ($err ? '<div class="flash bad">' . e($err) . '</div>' : '') . '
<p class="note">' . count($cards) . ' riad' . (count($cards) > 1 ? 's' : '') . ($dated ? ' disponible' . (count($cards) > 1 ? 's' : '') . ' du ' . e(fr_date($in)) . ' au ' . e(fr_date($out)) : '') . '. Prix en euros, taxe de séjour à régler sur place.</p>
<div class="rgrid">' . ($cards ? implode('', $cards) : '<div class="card">Aucun riad disponible pour cette recherche. Essayez d\'autres dates ou une autre ville.</div>') . '</div>');
