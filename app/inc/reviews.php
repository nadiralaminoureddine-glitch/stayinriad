<?php
// StayInRiad — fiche de satisfaction envoyée après chaque séjour
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const REVIEW_CRITERIA = ['staff' => 'Accueil et personnel', 'clean' => 'Propreté', 'comfort' => 'Confort de la chambre', 'location' => 'Emplacement', 'value' => 'Rapport qualité / prix', 'breakfast' => 'Petit-déjeuner'];
const REVIEW_DAYS = 30; // la fiche reste ouverte 30 jours après le départ

/** Envoie la fiche de satisfaction le jour du départ, à partir de midi (heure du Maroc). */
function send_review_requests(): array {
    $log = [];
    if ((int)date('G') < 12) return $log;
    $from = date('Y-m-d', strtotime('-3 days'));
    foreach (q("SELECT * FROM bookings WHERE status = 'confirmée' AND review_sent IS NULL AND check_out <= ? AND check_out >= ?", [today(), $from])->fetchAll() as $b) {
        $r = q('SELECT name FROM riads WHERE id = ?', [$b['riad_id']])->fetch();
        send_mail($b['email'], 'Comment s\'est passé votre séjour au ' . $r['name'] . ' ?', mail_layout('Merci d\'avoir séjourné au ' . $r['name'],
            '<p>Bonjour ' . e($b['first_name']) . ',</p><p>Nous espérons que votre séjour s\'est bien passé. Votre avis aide le riad à s\'améliorer et les prochains voyageurs à choisir.</p><p>Cela prend <b>1 minute</b> :</p>'
            . mail_button(booking_url($b, 'review.php'), 'Donner mon avis')
            . '<p style="font-size:14px">Un souci pendant le séjour ? Répondez simplement à cet email, notre équipe vous répond.</p>'));
        q('UPDATE bookings SET review_sent = ? WHERE id = ?', [now(), $b['id']]);
        $log[] = 'fiche satisfaction ' . $b['ref'];
    }
    return $log;
}

/** Moyennes d'un riad : ['n' => nombre d'avis, 'score' => note /10, 'crit' => [critère => moyenne /5], 'reco' => % qui recommandent]. */
function review_stats(int $riadId): array {
    $row = q('SELECT COUNT(*) n, AVG(score) score, AVG(staff) staff, AVG(clean) clean, AVG(comfort) comfort, AVG(location) location, AVG(value) value, AVG(breakfast) breakfast, AVG(recommend) reco FROM reviews WHERE riad_id = ?', [$riadId])->fetch();
    $crit = [];
    foreach (array_keys(REVIEW_CRITERIA) as $k) if ($row[$k] !== null) $crit[$k] = round((float)$row[$k], 1);
    return ['n' => (int)$row['n'], 'score' => $row['n'] ? round((float)$row['score'], 1) : null, 'crit' => $crit, 'reco' => $row['n'] ? (int)round((float)$row['reco'] * 100) : null];
}
function score_word(float $s): string { return $s >= 9 ? 'Exceptionnel' : ($s >= 8 ? 'Très bien' : ($s >= 7 ? 'Bien' : ($s >= 6 ? 'Correct' : 'Décevant'))); }
