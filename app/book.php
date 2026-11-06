<?php
// Réservation : coordonnées du voyageur, puis blocage de la chambre pendant le paiement
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';
require_public_open();

const COUNTRIES = ['France','Maroc','Espagne','Royaume-Uni','États-Unis','Belgique','Allemagne','Italie','Suisse','Canada','Pays-Bas','Autre'];

$room = q('SELECT * FROM rooms WHERE id = ? AND active = 1 AND price_eur > 0', [(int)($_GET['room'] ?? $_POST['room'] ?? 0)])->fetch();
$r = $room ? q("SELECT * FROM riads WHERE id = ? AND status = 'en ligne'", [$room['riad_id']])->fetch() : null;
if (!$r) { flash('Cette chambre n\'est plus disponible.', 'warn'); redirect('riads.php'); }
[$in, $out, $n, $err] = parse_stay($_GET['in'] ?? $_POST['in'] ?? null, $_GET['out'] ?? $_POST['out'] ?? null);
$g = max(1, min((int)$room['capacity'], (int)($_GET['g'] ?? $_POST['g'] ?? 2)));
if ($err) { flash($err, 'bad'); redirect('riad_view.php?id=' . (int)$r['id']); }
if (room_free($room, $in, $out) < 1) { flash('Cette chambre n\'est plus disponible à ces dates.', 'warn'); redirect('riad_view.php?' . http_build_query(['id' => $r['id'], 'in' => $in, 'out' => $out, 'g' => $g])); }

$u = current_user();
$instant = riad_instant($r);
$total = (float)$room['price_eur'] * $n;
$fe = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = fn($k, $m = 120) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $m);
    $guest = ['first' => $p('first', 80), 'last' => $p('last', 80), 'email' => strtolower($p('email', 190)), 'phone' => $p('phone', 40),
              'country' => in_array($p('country'), COUNTRIES, true) ? $p('country') : '', 'request' => $p('request', 500)];
    if ($guest['first'] === '' || $guest['last'] === '') $fe = 'Indiquez votre prénom et votre nom.';
    elseif (!valid_email($guest['email'])) $fe = 'Adresse email invalide.';
    elseif ($guest['phone'] === '') $fe = 'Indiquez un numéro de téléphone (le riad peut avoir besoin de vous joindre).';
    elseif (empty($_POST['cgv'])) $fe = 'Merci d\'accepter les conditions de réservation.';
    else {
        [$b, $fe] = create_hold($r, $room, $in, $out, $n, $g, $guest, $u ? (int)$u['id'] : null, !$instant);
        if ($b && !$instant) { try { notify_request($b); } catch (Throwable $e) { error_log('[StayInRiad] demande : ' . $e->getMessage()); } }
        if ($b) redirect('booking.php?ref=' . urlencode($b['ref']) . '&t=' . urlencode($b['token']));
    }
}
$v = fn($k, $d = '') => e($_POST[$k] ?? $d);
$ph = riad_photos((int)$r['id'], (int)$room['id']) ?: riad_photos((int)$r['id']);

render_page('Réserver', '<p><a href="riad_view.php?' . e(http_build_query(['id' => $r['id'], 'in' => $in, 'out' => $out, 'g' => $g])) . '">← Retour au riad</a></p>
<h1>Votre réservation</h1>
<div class="bookgrid"><form method="post" class="card">' . csrf_field() . '
<input type="hidden" name="room" value="' . (int)$room['id'] . '"><input type="hidden" name="in" value="' . e($in) . '"><input type="hidden" name="out" value="' . e($out) . '"><input type="hidden" name="g" value="' . $g . '">
<h2 style="margin-top:0">Vos coordonnées</h2>
<div class="row"><div><label>Prénom</label><input name="first" required maxlength="80" autocomplete="given-name" value="' . $v('first', $u['first_name'] ?? '') . '"></div><div><label>Nom</label><input name="last" required maxlength="80" autocomplete="family-name" value="' . $v('last', $u['last_name'] ?? '') . '"></div></div>
<label>Email (pour la confirmation)</label><input type="email" name="email" required autocomplete="email" value="' . $v('email', $u['email'] ?? '') . '">
<div class="row"><div><label>Téléphone</label><input name="phone" required maxlength="40" autocomplete="tel" placeholder="+33 6 …" value="' . $v('phone', $u['phone'] ?? '') . '"></div>
<div><label>Pays</label><select name="country">' . implode('', array_map(fn($c) => '<option' . (($_POST['country'] ?? '') === $c ? ' selected' : '') . '>' . e($c) . '</option>', COUNTRIES)) . '</select></div></div>
<label>Demande particulière (facultatif)</label><input name="request" maxlength="500" placeholder="Heure d\'arrivée, lit bébé…" value="' . $v('request') . '">
<label class="chk"><input type="checkbox" name="cgv" value="1"' . (!empty($_POST['cgv']) ? ' checked' : '') . '> J\'accepte les conditions de réservation et d\'annulation du riad et les <a href="/conditions/" target="_blank" rel="noopener">conditions générales d\'utilisation</a>.</label>
' . ($fe ? '<div class="err">' . e($fe) . '</div>' : '') . '
' . ($instant ? '<button class="btn full">Continuer vers le paiement</button>
<p class="note">⚡ Réservation immédiate. La chambre est bloquée pour vous pendant ' . HOLD_MINUTES . ' minutes le temps du paiement.</p>'
 : '<button class="btn full">Envoyer ma demande</button>
<p class="note">Le riad confirme la disponibilité <b>sous ' . REQUEST_HOURS . ' h maximum</b>. <b>Aucun débit</b> avant son accord : vous payez ensuite en ligne pour finaliser.</p>') . '</form>
<div class="card summary">' . ($ph ? '<img src="uploads/' . e($ph[0]) . '" alt="">' : '') . '
<b style="font-size:18px">' . e($r['name']) . '</b><div class="note">📍 ' . e($r['city']) . '</div>
<p><b>' . e($room['name']) . '</b><br><span class="note">' . e($room['description']) . '</span></p>
<p>📅 ' . e(fr_date($in)) . ' → ' . e(fr_date($out)) . '<br>' . $n . ' nuit' . ($n > 1 ? 's' : '') . ' · ' . $g . ' personne' . ($g > 1 ? 's' : '') . '</p>
<div class="line"><span>' . price((float)$room['price_eur']) . ' × ' . $n . ' nuit' . ($n > 1 ? 's' : '') . '</span><span>' . price_stay((float)$room['price_eur'], $n) . '</span></div>
' . ($r['breakfast'] ? '<div class="line"><span>Petit-déjeuner</span><span style="color:var(--ok)">inclus</span></div>' : '') . '
<div class="line tot"><span>Total</span><span>' . price_stay((float)$room['price_eur'], $n) . '</span></div>' . pay_note($total) . '
<p class="note">Taxe de séjour à régler sur place, directement au riad.<br>' . ($r['free_cancel'] ? 'Annulation gratuite jusqu\'à 48 h avant l\'arrivée.' : 'Tarif non remboursable.') . '</p></div></div>');
