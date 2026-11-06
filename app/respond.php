<?php
// Réponse du riad à une demande de réservation (lien reçu par email / SMS / WhatsApp) : Accepter ou Refuser, sans se connecter.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$b = q('SELECT * FROM bookings WHERE ref = ?', [(string)($_GET['ref'] ?? '')])->fetch();
if (!$b || !hash_equals(riad_key($b), (string)($_GET['k'] ?? ''))) { http_response_code(404); render_page('Demande', '<div class="card narrow"><h1>Lien invalide</h1><p>Ce lien de réponse n\'est pas valide. Écrivez-nous sur WhatsApp au +212 707-596333.</p></div>'); exit; }
$self = 'respond.php?ref=' . urlencode($b['ref']) . '&k=' . urlencode(riad_key($b));
if ($b['status'] === 'demande' && $b['hold_until'] < now()) { expire_requests(); $b = q('SELECT * FROM bookings WHERE id = ?', [$b['id']])->fetch(); }
$r = riad_owner((int)$b['riad_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $err = $act === 'accept' ? accept_request($b) : ($act === 'refuse' ? refuse_request($b) : 'Action inconnue.');
    if (!$err) log_action($act === 'accept' ? 'demande acceptée' : 'demande refusée', $b['ref'] . ' · ' . $r['name']);
    flash($err ?: ($act === 'accept' ? 'Merci ! Le voyageur est prévenu et va payer en ligne. Pensez à fermer ces dates sur Booking.' : 'C\'est noté : le voyageur est prévenu, aucun montant n\'est débité.'), $err ? 'bad' : 'ok');
    redirect($self);
}

$a = $_GET['a'] ?? '';
$rows = '<div class="line"><span>Client</span><span>' . e($b['first_name'] . ' ' . $b['last_name']) . ' · ' . e($b['country']) . '</span></div>
<div class="line"><span>Chambre</span><span>' . e($b['room_name']) . '</span></div>
<div class="line"><span>Séjour</span><span style="text-align:right">' . e(stay_text($b)) . '</span></div>
<div class="line"><span>Voyageurs</span><span>' . (int)$b['guests'] . '</span></div>' . ($b['request'] ? '<div class="line"><span>Demande</span><span>« ' . e($b['request']) . ' »</span></div>' : '') . '
<div class="line tot"><span>Votre revenu net</span><span>' . e(mad((float)$b['net_mad'])) . '</span></div>';

$body = match ($b['status']) {
    'demande' => '<div class="card narrow"><h1>Demande de réservation</h1><p><b>' . e($r['name']) . '</b> · à confirmer dans les <b>' . e(hours_left($b['hold_until'])) . '</b></p>' . $rows . '
      <p style="margin-top:18px">La chambre est-elle libre à ces dates ?</p>
      <form method="post">' . csrf_field() . '<button class="btn full" name="action" value="accept"' . ($a === 'refuse' ? '' : ' autofocus') . '>✓ Oui, j\'accepte</button></form>
      <form method="post" style="margin-top:10px">' . csrf_field() . '<button class="btn full ghost" name="action" value="refuse">Non, la chambre est déjà prise</button></form>
      <p class="note" style="margin-top:14px">Si vous acceptez, fermez ensuite ces dates sur Booking (ou dans votre channel manager), comme pour un client qui vous appelle.</p></div>',
    'bloquée' => '<div class="card narrow"><div style="font-size:40px">✅</div><h1>Demande acceptée</h1>' . $rows . '<p>Le voyageur a reçu le lien de paiement. Vous recevrez la confirmation dès qu\'il aura payé.</p><p class="note"><b>Pensez à fermer ces dates sur Booking</b> si ce n\'est pas déjà fait.</p></div>',
    'confirmée' => '<div class="card narrow"><div style="font-size:40px">✅</div><h1>Réservation confirmée et payée</h1>' . $rows . '<a class="btn" href="bookings.php">Voir mes réservations</a></div>',
    'refusée' => '<div class="card narrow"><h1>Demande refusée</h1>' . $rows . '<p>Le voyageur est prévenu. Aucun montant n\'a été débité.</p></div>',
    default => '<div class="card narrow"><h1>Demande expirée</h1>' . $rows . '<p>Cette demande n\'a pas reçu de réponse à temps et a été annulée. Pour ne plus manquer de réservations, répondez dès que vous recevez une demande.</p></div>',
};
render_page('Demande ' . $b['ref'], $body);
