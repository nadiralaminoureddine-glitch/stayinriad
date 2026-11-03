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

// 2) Rappel fiche de police : la veille de l'arrivée (ou le jour même), si elle n'est pas remplie
$tomorrow = date('Y-m-d', strtotime('+1 day'));
foreach (q("SELECT * FROM bookings WHERE status = 'confirmée' AND police_filled_at IS NULL AND police_reminded = 0 AND check_in <= ? AND check_in >= ?", [$tomorrow, today()])->fetchAll() as $b) {
    $r = q('SELECT name, address, city FROM riads WHERE id = ?', [$b['riad_id']])->fetch();
    send_mail($b['email'], 'Votre arrivée au ' . $r['name'] . ' : fiche de police à remplir', mail_layout('À très bientôt au ' . $r['name'] . ' !', '<p>Bonjour ' . e($b['first_name']) . ',</p><p>Votre séjour commence le <b>' . e(fr_date($b['check_in'])) . '</b>. Pour gagner du temps à l\'accueil, remplissez dès maintenant la fiche de police obligatoire (2 minutes par voyageur).</p>' . mail_button(booking_url($b, 'police.php'), 'Remplir la fiche de police') . '<p style="font-size:14px">Adresse du riad : ' . e($r['address'] ?: $r['city']) . '<br>Réservation : ' . e($b['ref']) . '</p>'));
    q('UPDATE bookings SET police_reminded = 1 WHERE id = ?', [$b['id']]);
    $log[] = 'rappel police ' . $b['ref'];
}

// 3) Suppression des fiches de police après le délai de conservation (par défaut 365 jours après le départ)
$days = (int)($CONFIG['police_retention_days'] ?? 365);
$limit = date('Y-m-d', strtotime("-$days days"));
$n = q('DELETE FROM police_forms WHERE booking_id IN (SELECT id FROM bookings WHERE check_out < ?)', [$limit])->rowCount();
if ($n) $log[] = "$n fiche(s) de police supprimée(s)";

// 4) Nettoyage du journal des connexions (90 jours)
q('DELETE FROM login_attempts WHERE at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);

echo date('Y-m-d H:i') . ' OK ' . implode(' · ', $log) . "\n";
