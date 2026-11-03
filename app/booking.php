<?php
// Page d'une réservation (lien privé : référence + jeton) : paiement, confirmation, fiche de police, annulation.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$b = q('SELECT * FROM bookings WHERE ref = ?', [(string)($_GET['ref'] ?? '')])->fetch();
if (!$b || !hash_equals($b['token'], (string)($_GET['t'] ?? ''))) { http_response_code(404); render_page('Réservation', '<div class="card"><h1>Réservation introuvable</h1></div>'); exit; }
$self = 'booking.php?ref=' . urlencode($b['ref']) . '&t=' . urlencode($b['token']);
if ($b['status'] === 'bloquée' && $b['hold_until'] < now()) { q("UPDATE bookings SET status = 'expirée' WHERE id = ?", [$b['id']]); $b['status'] = 'expirée'; }
$u = current_user(); $isAdmin = $u && $u['role'] === 'admin';
$r = q('SELECT * FROM riads WHERE id = ?', [$b['riad_id']])->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'test_pay') {
        if (!test_payment_allowed()) { http_response_code(403); exit('Paiement de test réservé à l\'administrateur.'); }
        flash(confirm_booking($b, 'TEST-' . strtoupper(bin2hex(random_bytes(4)))) ? 'Paiement de test accepté : réservation confirmée. Les emails de confirmation sont envoyés.' : 'Impossible de confirmer : la chambre n\'est plus disponible.', 'ok');
    } elseif ($act === 'cancel') {
        if ($isAdmin && !empty($_POST['full_refund'])) $GLOBALS['FULL_REFUND'] = true;
        $err = cancel_booking($b, $isAdmin ? 'admin' : 'client');
        flash($err ?: 'Réservation annulée. Un email de confirmation vous a été envoyé.', $err ? 'bad' : 'ok');
    } elseif ($act === 'refunded' && $isAdmin) {
        q("UPDATE bookings SET refund_status = 'remboursé' WHERE id = ?", [$b['id']]); flash('Remboursement marqué comme effectué.');
    }
    redirect($self);
}

$test = str_starts_with((string)$b['payment_ref'], 'TEST-');
$summary = '<div class="card summary"><b style="font-size:18px">' . e($r['name']) . '</b><div class="note">📍 ' . e($r['address'] ?: $r['city']) . '</div>
<p><b>' . e($b['room_name']) . '</b></p><p>📅 ' . e(fr_date($b['check_in'])) . ' → ' . e(fr_date($b['check_out'])) . '<br>' . (int)$b['nights'] . ' nuit(s) · ' . (int)$b['guests'] . ' personne(s)</p>
<div class="line"><span>' . price((float)$b['price_night']) . ' × ' . (int)$b['nights'] . '</span><span>' . price_stay((float)$b['price_night'], (int)$b['nights']) . '</span></div>
<div class="line tot"><span>Total</span><span>' . price_stay((float)$b['price_night'], (int)$b['nights']) . '</span></div>' . pay_note((float)$b['total']) . '<p class="note">Taxe de séjour à régler sur place.<br>' . e(cancel_policy_text($r)) . '</p></div>';

if ($b['status'] === 'confirmée') {
    $nPolice = (int)q('SELECT COUNT(*) FROM police_forms WHERE booking_id = ?', [$b['id']])->fetchColumn();
    [$pen, $ref, $why] = cancel_terms($b, $r);
    $canCancel = $b['check_in'] > today() || $isAdmin;
    $body = '<div class="card" style="text-align:center"><div style="font-size:44px">✅</div><h1>Réservation confirmée</h1>
    <p>Merci ' . e($b['first_name']) . ', <b>' . e($r['name']) . '</b> vous attend du <b>' . e(fr_date($b['check_in'])) . '</b> au <b>' . e(fr_date($b['check_out'])) . '</b>.</p>
    <p style="font-size:20px">N° <b>' . e($b['ref']) . '</b></p><p class="note">Un email de confirmation a été envoyé à ' . e($b['email']) . '. Gardez ce lien pour retrouver votre réservation.' . ($test ? '<br><b>Réservation de TEST (aucun paiement réel).</b>' : '') . '</p></div>
    <div class="card"><h2 style="margin-top:0">📄 Fiche de police</h2>' . ($b['police_filled_at']
        ? '<p>✅ Fiche remplie pour ' . $nPolice . ' voyageur(s). Merci !</p><a class="btn ghost" href="' . e(str_replace('booking.php', 'police.php', $self)) . '">Modifier</a>'
        : '<p>La loi marocaine demande une fiche pour chaque voyageur. Remplissez-la maintenant en 2 minutes : vous gagnerez du temps à l\'arrivée.</p><a class="btn" href="' . e(str_replace('booking.php', 'police.php', $self)) . '">Remplir la fiche de police</a>') . '</div>'
    . ($canCancel ? '<div class="card"><h2 style="margin-top:0">Annuler la réservation</h2><p>' . e($why) . '</p>' . mail_rows_html($pen, $ref) . '
      <form method="post">' . csrf_field() . '<label class="chk"><input type="checkbox" required> Je confirme vouloir annuler cette réservation</label>' . ($isAdmin ? '<label class="chk"><input type="checkbox" name="full_refund" value="1"> Admin : rembourser intégralement (geste commercial, problème du riad…)</label>' : '') . '<button class="btn ghost" name="action" value="cancel">Annuler ma réservation</button></form></div>' : '')
    . $summary;
} elseif ($b['status'] === 'bloquée') {
    $mins = max(0, (int)ceil((strtotime($b['hold_until'] . ' UTC') - time()) / 60));
    $pay = payment_available() ? '<p>Paiement sécurisé par carte bancaire.</p>' : '<div class="flash warn">Le paiement en ligne sera bientôt disponible. Votre chambre est bloquée encore <b>' . $mins . ' min</b>.</div>';
    if (test_payment_allowed()) $pay .= '<form method="post">' . csrf_field() . '<button class="btn" name="action" value="test_pay">🧪 Paiement de TEST (admin uniquement)</button></form><p class="note">Visible uniquement pour l\'administrateur, pour tester le parcours. Aucun argent n\'est débité.</p>';
    $body = '<h1>Paiement</h1><div class="bookgrid"><div class="card">' . pay_logos() . '<h2 style="margin-top:0">' . eur((float)$b['total']) . ' à payer</h2>' . (cur() !== 'EUR' ? '<p class="note">soit environ ' . price((float)$b['total']) . '</p>' : '') . '
    <p>Réservation <b>' . e($b['ref']) . '</b> au nom de ' . e($b['first_name'] . ' ' . $b['last_name']) . '.</p>' . $pay . '</div>' . $summary . '</div>';
} elseif ($b['status'] === 'annulée') {
    $body = '<div class="card"><h1>Réservation annulée</h1><p>Annulée le ' . e(fr_date(substr((string)$b['cancelled_at'], 0, 10))) . '.</p>' . mail_rows_html((float)$b['penalty'], (float)$b['refund'])
      . ((float)$b['refund'] > 0 ? '<p class="note">Remboursement sur la carte utilisée : ' . ($b['refund_status'] === 'remboursé' ? '<b>effectué</b>' : 'en cours (5 à 10 jours ouvrés selon la banque)') . '.</p>' : '')
      . ($isAdmin && $b['refund_status'] === 'à rembourser' ? '<form method="post">' . csrf_field() . '<button class="btn sm" name="action" value="refunded">Admin : marquer comme remboursé</button></form>' : '') . '</div>' . $summary;
} else {
    $body = '<div class="card"><h1>Délai de paiement dépassé</h1><p>Le paiement n\'a pas été effectué dans les ' . HOLD_MINUTES . ' minutes : la chambre a été libérée. Aucun montant n\'a été débité.</p>
    <a class="btn" href="riad_view.php?' . e(http_build_query(['id' => $r['id'], 'in' => $b['check_in'], 'out' => $b['check_out'], 'g' => $b['guests']])) . '">Réserver à nouveau</a></div>' . $summary;
}
render_page('Réservation ' . $b['ref'], $body);

function mail_rows_html(float $pen, float $ref): string {
    return '<div class="line"><span>Frais d\'annulation</span><span>' . eur($pen) . '</span></div><div class="line tot"><span>Remboursé</span><span>' . eur($ref) . '</span></div>';
}
