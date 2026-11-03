<?php
// Page d'une réservation : paiement (si en attente) puis confirmation. Accès par lien privé (référence + jeton).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$b = q('SELECT * FROM bookings WHERE ref = ?', [(string)($_GET['ref'] ?? '')])->fetch();
if (!$b || !hash_equals($b['token'], (string)($_GET['t'] ?? ''))) { http_response_code(404); render_page('Réservation', '<div class="card"><h1>Réservation introuvable</h1></div>'); exit; }
$self = 'booking.php?ref=' . urlencode($b['ref']) . '&t=' . urlencode($b['token']);
if ($b['status'] === 'bloquée' && $b['hold_until'] < now()) { q("UPDATE bookings SET status = 'expirée' WHERE id = ?", [$b['id']]); $b['status'] = 'expirée'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_pay') {
    if (!test_payment_allowed()) { http_response_code(403); exit('Paiement de test réservé à l\'administrateur.'); }
    if (confirm_booking($b, 'TEST-' . strtoupper(bin2hex(random_bytes(4))))) flash('Paiement de test accepté : réservation confirmée.');
    else flash('Impossible de confirmer : la chambre n\'est plus disponible.', 'bad');
    redirect($self);
}

$r = q('SELECT * FROM riads WHERE id = ?', [$b['riad_id']])->fetch();
$mins = $b['status'] === 'bloquée' ? max(0, (int)ceil((strtotime($b['hold_until'] . ' UTC') - time()) / 60)) : 0;
$summary = '<div class="card summary"><b style="font-size:18px">' . e($r['name']) . '</b><div class="note">📍 ' . e(($r['address'] ?: $r['city'])) . '</div>
<p><b>' . e($b['room_name']) . '</b></p><p>📅 ' . e(fr_date($b['check_in'])) . ' → ' . e(fr_date($b['check_out'])) . '<br>' . (int)$b['nights'] . ' nuit(s) · ' . (int)$b['guests'] . ' personne(s)</p>
<div class="line"><span>' . price((float)$b['price_night']) . ' × ' . (int)$b['nights'] . '</span><span>' . price_stay((float)$b['price_night'], (int)$b['nights']) . '</span></div>
<div class="line tot"><span>Total</span><span>' . price_stay((float)$b['price_night'], (int)$b['nights']) . '</span></div>' . pay_note((float)$b['total']) . '<p class="note">Taxe de séjour à régler sur place.</p></div>';

if ($b['status'] === 'confirmée') {
    $body = '<div class="card" style="text-align:center"><div style="font-size:44px">✅</div><h1>Réservation confirmée</h1>
    <p>Merci ' . e($b['first_name']) . ', <b>' . e($r['name']) . '</b> vous attend du <b>' . e(fr_date($b['check_in'])) . '</b> au <b>' . e(fr_date($b['check_out'])) . '</b>.</p>
    <p style="font-size:20px">N° <b>' . e($b['ref']) . '</b></p>
    <p class="note">Gardez ce lien : il vous permet de retrouver votre réservation. ' . (str_starts_with($b['payment_ref'], 'TEST-') ? '<br><b>Réservation de TEST (aucun paiement réel).</b>' : '') . '</p></div>' . $summary;
} elseif ($b['status'] === 'bloquée') {
    $pay = payment_available()
        ? '<p>Paiement sécurisé par carte bancaire.</p>' // le bouton de la passerelle sera branché ici
        : '<div class="flash warn">Le paiement en ligne sera bientôt disponible. Votre chambre est bloquée encore <b>' . $mins . ' min</b>.</div>';
    if (test_payment_allowed()) $pay .= '<form method="post">' . csrf_field() . '<button class="btn" name="action" value="test_pay">🧪 Paiement de TEST (admin uniquement)</button></form><p class="note">Visible uniquement pour l\'administrateur, pour tester le parcours. Aucun argent n\'est débité.</p>';
    $body = '<h1>Paiement</h1><div class="bookgrid"><div class="card">' . pay_logos() . '<h2 style="margin-top:0">' . eur((float)$b['total']) . ' à payer</h2>' . (cur() !== 'EUR' ? '<p class="note">soit environ ' . price_stay((float)$b['price_night'], (int)$b['nights']) . '</p>' : '') . '
    <p>Réservation <b>' . e($b['ref']) . '</b> au nom de ' . e($b['first_name'] . ' ' . $b['last_name']) . '.</p>' . $pay . '</div>' . $summary . '</div>';
} else {
    $body = '<div class="card"><h1>' . ($b['status'] === 'expirée' ? 'Délai de paiement dépassé' : 'Réservation annulée') . '</h1>
    <p>' . ($b['status'] === 'expirée' ? 'Le paiement n\'a pas été effectué dans les ' . HOLD_MINUTES . ' minutes : la chambre a été libérée. Aucun montant n\'a été débité.' : 'Cette réservation a été annulée.') . '</p>
    <a class="btn" href="riad_view.php?' . e(http_build_query(['id' => $r['id'], 'in' => $b['check_in'], 'out' => $b['check_out'], 'g' => $b['guests']])) . '">Réserver à nouveau</a></div>' . $summary;
}
render_page('Réservation ' . $b['ref'], $body);
