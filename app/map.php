<?php
// Carte de la page d'accueil : riads en ligne avec une position GPS (JSON). Vide tant que les réservations ne sont pas ouvertes.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('Access-Control-Allow-Origin: https://stayinriad.com');
$out = [];
if (site_open()) {
    foreach (q("SELECT id, name, city, area, lat, lng FROM riads WHERE status = 'en ligne' AND lat IS NOT NULL AND lng IS NOT NULL ORDER BY commission DESC, name LIMIT 500")->fetchAll() as $r) {
        $from = q('SELECT MIN(price_eur) FROM rooms WHERE riad_id = ? AND active = 1 AND price_eur > 0', [$r['id']])->fetchColumn();
        if (!$from) continue;
        $ph = riad_photos((int)$r['id']);
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'city' => $r['city'], 'area' => $r['area'], 'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'],
                  'from' => (float)$from, 'photo' => $ph ? '/app/uploads/' . $ph[0] : null, 'url' => '/app/riad_view.php?id=' . (int)$r['id']];
    }
}
echo json_encode(['open' => site_open(), 'riads' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
