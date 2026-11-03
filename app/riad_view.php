<?php
// Page publique d'un riad : photos, description, chambres disponibles
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';
require_public_open();

$r = q("SELECT * FROM riads WHERE id = ? AND status = 'en ligne'", [(int)($_GET['id'] ?? 0)])->fetch();
$u = current_user();
if (!$r && $u && $u['role'] === 'admin') $r = q('SELECT * FROM riads WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch(); // aperçu admin
if (!$r) { http_response_code(404); render_page('Riad introuvable', '<div class="card"><h1>Riad introuvable</h1><p>Ce riad n\'est pas (ou plus) en ligne.</p><a class="btn" href="riads.php">Voir les riads</a></div>'); exit; }

$g = max(1, min(12, (int)($_GET['g'] ?? 2)));
$in = $_GET['in'] ?? ''; $out = $_GET['out'] ?? ''; $err = null; $n = 0;
if ($in !== '' || $out !== '') { [$in, $out, $n, $err] = parse_stay($in, $out); if ($err) { $in = $_GET['in'] ?? ''; $out = $_GET['out'] ?? ''; } }
$dated = $n > 0;

$photos = riad_photos((int)$r['id']);
$amen = json_decode((string)($r['amenities'] ?? '[]'), true) ?: [];
$rooms = q('SELECT * FROM rooms WHERE riad_id = ? AND active = 1 AND price_eur > 0 ORDER BY price_eur', [$r['id']])->fetchAll();
$roomRows = implode('', array_map(function ($x) use ($r, $dated, $in, $out, $n, $g) {
    $ph = riad_photos((int)$r['id'], (int)$x['id']);
    $fits = (int)$x['capacity'] >= $g;
    $free = $dated ? room_free($x, $in, $out) : null;
    $state = !$fits ? '<span class="note" style="color:var(--bad)">Jusqu\'à ' . (int)$x['capacity'] . ' personnes</span>'
           : ($dated ? ($free > 0 ? '<span class="note" style="color:var(--ok)">' . ($free <= 2 ? 'Plus que ' . $free . ' disponible' . ($free > 1 ? 's' : '') : 'Disponible') . '</span>' : '<span class="note" style="color:var(--bad)">Complet à ces dates</span>') : '<span class="note">Choisissez vos dates</span>');
    $btn = ($dated && $fits && $free > 0) ? '<a class="btn sm" href="book.php?' . e(http_build_query(['room' => $x['id'], 'in' => $in, 'out' => $out, 'g' => $g])) . '">Réserver</a>' : '';
    return '<div class="room"><div class="rthumb">' . ($ph ? '<img src="uploads/' . e($ph[0]) . '" alt="">' : '<div class="noimg">🛏️</div>') . '</div>
      <div style="flex:1;min-width:0"><b>' . e($x['name']) . '</b><div class="note">' . ($x['description'] !== '' ? e($x['description']) . ' · ' : '') . 'jusqu\'à ' . (int)$x['capacity'] . ' pers.</div>' . $state . '</div>
      <div style="text-align:right"><b>' . eur((float)$x['price_eur']) . '</b><div class="note">/ nuit</div>' . ($dated ? '<div class="note">' . eur((float)$x['price_eur'] * $n) . ' au total</div>' : '') . $btn . '</div></div>';
}, $rooms));

render_page($r['name'], '<p><a href="riads.php?city=' . e(urlencode($r['city'])) . '">← Riads à ' . e($r['city']) . '</a></p>
' . ($r['status'] !== 'en ligne' ? '<div class="flash warn">Aperçu admin : ce riad n\'est pas en ligne.</div>' : '') . '
<div class="pgal">' . ($photos ? implode('', array_map(fn($p, $i) => '<img src="uploads/' . e($p) . '" alt="" class="' . ($i === 0 ? 'big' : '') . '"' . ($i ? ' loading="lazy"' : '') . '>', array_slice($photos, 0, 5), array_keys(array_slice($photos, 0, 5)))) : '<div class="noimg big">🕌</div>') . '</div>
<h1 style="margin-top:16px">' . e($r['name']) . ($r['stars'] ? ' <span style="color:var(--gold);font-size:18px">' . str_repeat('★', (int)$r['stars']) . '</span>' : '') . '</h1>
<p class="note">📍 ' . e(($r['area'] ? $r['area'] . ', ' : '') . $r['city']) . '</p>
<div class="card"><p style="margin-top:0;white-space:pre-line">' . e($r['description']) . '</p>
' . ($amen ? '<p>' . implode(' · ', array_map('e', $amen)) . '</p>' : '') . '
<p class="note">' . ($r['breakfast'] ? '☕ Petit-déjeuner inclus · ' : '') . ($r['free_cancel'] ? 'Annulation gratuite jusqu\'à 48 h avant l\'arrivée' : 'Tarif non remboursable') . ' · Taxe de séjour à régler sur place</p></div>
<form class="card search" method="get"><input type="hidden" name="id" value="' . (int)$r['id'] . '">
 <div><label>Arrivée</label><input type="date" name="in" min="' . today() . '" value="' . e($in) . '" required></div>
 <div><label>Départ</label><input type="date" name="out" min="' . today() . '" value="' . e($out) . '" required></div>
 <div><label>Personnes</label><select name="g">' . implode('', array_map(fn($i) => '<option value="' . $i . '"' . ($i === $g ? ' selected' : '') . '>' . $i . '</option>', range(1, 8))) . '</select></div>
 <div><button class="btn full">Voir les disponibilités</button></div></form>
' . ($err ? '<div class="flash bad">' . e($err) . '</div>' : '') . '
<h2>Chambres</h2><div class="card">' . ($roomRows ?: '<p class="note">Aucune chambre pour l\'instant.</p>') . '</div>');
