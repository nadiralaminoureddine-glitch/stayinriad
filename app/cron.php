<?php
// StayInRiad — tâches automatiques. À lancer par une « tâche cron » cPanel, toutes les heures :
//   php /home/stayinri/public_html/app/cron.php
// Ne s'exécute pas depuis le navigateur.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$log = [];
// 1) Paiements abandonnés : la chambre est libérée
expire_holds();

// 1 bis) Calendriers Booking.com / Airbnb : relus toutes les 15 minutes
$log = array_merge($log, ical_sync_due());

// 2) Rappel fiche de police : la veille de l'arrivée (ou le jour même), si elle n'est pas remplie
$tomorrow = date('Y-m-d', strtotime('+1 day'));
foreach (q("SELECT * FROM bookings WHERE status = 'confirmée' AND police_filled_at IS NULL AND police_reminded = 0 AND check_in <= ? AND check_in >= ?", [$tomorrow, today()])->fetchAll() as $b) {
    $r = q('SELECT name, address, city FROM riads WHERE id = ?', [$b['riad_id']])->fetch();
    send_mail($b['email'], 'Votre arrivée au ' . $r['name'] . ' : fiche de police à remplir', mail_layout('À très bientôt au ' . $r['name'] . ' !', '<p>Bonjour ' . e($b['first_name']) . ',</p><p>Votre séjour commence le <b>' . e(fr_date($b['check_in'])) . '</b>. Pour gagner du temps à l\'accueil, remplissez dès maintenant la fiche de police obligatoire (2 minutes par voyageur).</p>' . mail_button(booking_url($b, 'police.php'), 'Remplir la fiche de police') . '<p style="font-size:14px">Adresse du riad : ' . e($r['address'] ?: $r['city']) . '<br>Réservation : ' . e($b['ref']) . '</p>'));
    q('UPDATE bookings SET police_reminded = 1 WHERE id = ?', [$b['id']]);
    $log[] = 'rappel police ' . $b['ref'];
}

// 2 bis) Fiche de satisfaction : le jour du départ, à partir de midi
$log = array_merge($log, send_review_requests());

// 2 ter) Relevé mensuel : le 1er du mois, à partir de 8 h
$log = array_merge($log, send_monthly_statements());

// 3) Suppression des fiches de police après le délai de conservation (par défaut 365 jours après le départ)
$days = (int)($CONFIG['police_retention_days'] ?? 365);
$limit = date('Y-m-d', strtotime("-$days days"));
$n = q('DELETE FROM police_forms WHERE booking_id IN (SELECT id FROM bookings WHERE check_out < ?)', [$limit])->rowCount();
if ($n) $log[] = "$n fiche(s) de police supprimée(s)";

// 4) Comptes jamais activés (code non saisi) depuis 7 jours : supprimés avec leur fiche riad vide
$old = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
foreach (q("SELECT id FROM users WHERE status = 'unverified' AND created_at < ?", [$old])->fetchAll() as $x) {
    foreach (q('SELECT id FROM riads WHERE owner_id = ?', [$x['id']])->fetchAll() as $rr) { q('DELETE FROM rooms WHERE riad_id = ?', [$rr['id']]); q('DELETE FROM photos WHERE riad_id = ?', [$rr['id']]); q('DELETE FROM riads WHERE id = ?', [$rr['id']]); }
    q('DELETE FROM auth_codes WHERE user_id = ?', [$x['id']]); q('DELETE FROM users WHERE id = ?', [$x['id']]);
    $log[] = 'compte non activé supprimé #' . $x['id'];
}
q('DELETE FROM auth_codes WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);

// 5) Nettoyage du journal des connexions (90 jours)
q('DELETE FROM login_attempts WHERE at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);

echo date('Y-m-d H:i') . ' OK ' . implode(' · ', $log) . "\n";
