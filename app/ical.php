<?php
// Calendrier iCal d'une chambre, lu par Booking.com / Airbnb (lien privé avec jeton).
// Fonctionne même quand le site est fermé au public : les riads peuvent préparer leur synchronisation.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/booking.php';

$room = q('SELECT * FROM rooms WHERE id = ? AND active = 1', [(int)($_GET['room'] ?? 0)])->fetch();
$t = (string)($_GET['t'] ?? '');
if (!$room || $room['ical_token'] === '' || !hash_equals($room['ical_token'], $t)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Not found'); }

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="stayinriad-' . (int)$room['id'] . '.ics"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo ical_export($room);
