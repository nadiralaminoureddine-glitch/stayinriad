<?php
// StayInRiad — disponibilités, prix et réservations
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const HOLD_MINUTES = 15;   // la chambre est bloquée pendant le paiement
const MAX_NIGHTS = 30;
const BOOKING_STATUS = ['bloquée' => ['Paiement en cours', 'warn'], 'confirmée' => ['Confirmée', 'ok'], 'annulée' => ['Annulée', 'bad'], 'expirée' => ['Expirée', 'info']];

function eur(float $v): string { return number_format($v, $v == floor($v) ? 0 : 2, ',', ' ') . ' €'; }
function mad(float $v): string { return number_format($v, 0, ',', ' ') . ' MAD'; }
/** Taux de change (modifiables par l'admin). 1 € = X MAD / X $. */
function rate(string $k): float {
    global $CONFIG; static $c = [];
    if (!isset($c[$k])) { try { $v = q('SELECT v FROM settings WHERE k = ?', ['rate_' . $k])->fetchColumn(); } catch (Throwable $e) { $v = false; }
        $c[$k] = $v !== false ? (float)$v : (float)($CONFIG['rate_' . $k] ?? ($k === 'mad' ? 10.75 : 1.08)); }
    return $c[$k];
}
function eur_mad(): float { return rate('mad'); }
const CURRENCIES = ['EUR' => '€', 'MAD' => 'DH', 'USD' => '$'];
function cur(): string { return $_SESSION['cur'] ?? 'EUR'; }
/** Affiche un prix (stocké en euros) dans la devise choisie par le visiteur. */
function price(float $eur): string {
    return match (cur()) {
        'MAD' => number_format(round($eur * rate('mad')), 0, ',', ' ') . ' DH',
        'USD' => '$' . number_format(round($eur * rate('usd')), 0, '.', ','),
        default => eur($eur),
    };
}
/** Prix d'un séjour (prix par nuit × nuits), cohérent avec le prix par nuit affiché. */
function price_stay(float $eurNight, int $nights): string {
    return match (cur()) {
        'MAD' => number_format(round($eurNight * rate('mad')) * $nights, 0, ',', ' ') . ' DH',
        'USD' => '$' . number_format(round($eurNight * rate('usd')) * $nights, 0, '.', ','),
        default => eur($eurNight * $nights),
    };
}
/** Rappel quand la devise affichée n'est pas celle du paiement. */
function pay_note(float $eur): string { return cur() === 'EUR' ? '' : '<p class="note">Paiement en euros : vous serez débité de <b>' . eur($eur) . '</b>. Montant en ' . (cur() === 'MAD' ? 'dirhams' : 'dollars') . ' indicatif, selon le taux du jour.</p>'; }
function today(): string { return date('Y-m-d'); }
function fr_date(string $d): string {
    static $m = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
    $t = strtotime($d); return (int)date('j', $t) . ' ' . $m[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}
function booking_pill(string $s): string { [$l, $c] = BOOKING_STATUS[$s] ?? [$s, 'info']; return '<span class="pill ' . $c . '">' . e($l) . '</span>'; }

/** Vérifie des dates de séjour. Retourne [arrivée, départ, nuits, erreur]. */
function parse_stay(?string $in, ?string $out): array {
    $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    if (!$ok($in) || !$ok($out)) return [null, null, 0, 'Choisissez vos dates d\'arrivée et de départ.'];
    if ($in < today()) return [null, null, 0, 'La date d\'arrivée est déjà passée.'];
    if ($in > date('Y-m-d', strtotime('+18 months'))) return [null, null, 0, 'Les réservations sont ouvertes sur 18 mois.'];
    $n = (int)round((strtotime($out) - strtotime($in)) / 86400);
    if ($n < 1) return [null, null, 0, 'La date de départ doit être après la date d\'arrivée.'];
    if ($n > MAX_NIGHTS) return [null, null, 0, 'Séjour de ' . MAX_NIGHTS . ' nuits maximum.'];
    return [$in, $out, $n, null];
}

/** Les réservations qui occupent une chambre : confirmées, ou bloquées pour un paiement en cours. */
function occupying_sql(): string { return "(status = 'confirmée' OR (status = 'bloquée' AND hold_until > ?))"; }

/** Nombre de chambres encore libres pour toute la période [arrivée, départ[. */
function room_free(array $room, string $in, string $out): int {
    $free = (int)$room['qty'];
    $books = q('SELECT check_in, check_out FROM bookings WHERE room_id = ? AND check_in < ? AND check_out > ? AND ' . occupying_sql(),
               [$room['id'], $out, $in, now()])->fetchAll();
    $closed = array_flip(array_column(q('SELECT day FROM closed_days WHERE room_id = ? AND day >= ? AND day < ?', [$room['id'], $in, $out])->fetchAll(), 'day'));
    $ext = ext_days((int)$room['id'], $in, $out); // réservations Booking/Airbnb
    for ($d = $in; $d < $out; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (isset($closed[$d])) return 0;
        $used = $ext[$d] ?? 0; foreach ($books as $b) if ($b['check_in'] <= $d && $d < $b['check_out']) $used++;
        $free = min($free, (int)$room['qty'] - $used);
    }
    return max(0, $free);
}
/** Chambres libres jour par jour (pour le calendrier du riad). */
function room_days(array $room, string $from, int $days): array {
    $to = date('Y-m-d', strtotime("$from +$days days"));
    $books = q('SELECT check_in, check_out FROM bookings WHERE room_id = ? AND check_in < ? AND check_out > ? AND ' . occupying_sql(), [$room['id'], $to, $from, now()])->fetchAll();
    $closed = array_flip(array_column(q('SELECT day FROM closed_days WHERE room_id = ? AND day >= ? AND day < ?', [$room['id'], $from, $to])->fetchAll(), 'day'));
    $ext = ext_days((int)$room['id'], $from, $to);
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime("$from +$i days")); $x = $ext[$d] ?? 0; $used = $x;
        foreach ($books as $b) if ($b['check_in'] <= $d && $d < $b['check_out']) $used++;
        $out[$d] = ['free' => max(0, (int)$room['qty'] - $used), 'used' => $used, 'ext' => $x, 'closed' => isset($closed[$d])];
    }
    return $out;
}

function new_ref(): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do { $r = 'SR-'; for ($i = 0; $i < 6; $i++) $r .= $abc[random_int(0, strlen($abc) - 1)]; }
    while (q('SELECT 1 FROM bookings WHERE ref = ?', [$r])->fetch());
    return $r;
}

/** Bloque une chambre pendant le paiement. Retourne [réservation, erreur]. */
function create_hold(array $riad, array $room, string $in, string $out, int $nights, int $guests, array $guest, ?int $userId): array {
    global $CONFIG;
    ical_sync_room((int)$room['id']); // relit Booking/Airbnb en direct juste avant le paiement
    $pdo = db(); $my = ($CONFIG['db']['driver'] ?? 'mysql') !== 'sqlite';
    $pdo->beginTransaction();
    try {
        if ($my) q('SELECT id FROM rooms WHERE id = ? FOR UPDATE', [$room['id']]); // une seule réservation à la fois sur cette chambre
        else $pdo->exec('UPDATE rooms SET active = active WHERE id = ' . (int)$room['id']);
        if (room_free($room, $in, $out) < 1) { $pdo->rollBack(); return [null, 'Désolé, cette chambre vient d\'être réservée pour ces dates. Choisissez une autre chambre ou d\'autres dates.']; }
        $price = (float)$room['price_eur']; $sub = round($price * $nights, 2);
        $rate = commission_now($riad); $com = round($sub * $rate / 100, 2); $net = round($sub - $com, 2);
        $fx = eur_mad();
        $ref = new_ref(); $token = bin2hex(random_bytes(16));
        q('INSERT INTO bookings (ref, token, riad_id, room_id, user_id, first_name, last_name, email, phone, country, request, check_in, check_out, nights, guests,
            room_name, price_night, subtotal, commission_rate, commission, net, total, eur_mad, net_mad, status, hold_until, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $ref, $token, $riad['id'], $room['id'], $userId, $guest['first'], $guest['last'], $guest['email'], $guest['phone'], $guest['country'], $guest['request'],
            $in, $out, $nights, $guests, $room['name'], $price, $sub, $rate, $com, $net, $sub, $fx, round($net * $fx, 2),
            'bloquée', gmdate('Y-m-d H:i:s', time() + HOLD_MINUTES * 60), now()]);
        $pdo->commit();
        return [q('SELECT * FROM bookings WHERE ref = ?', [$ref])->fetch(), null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[StayInRiad booking] ' . $e->getMessage());
        return [null, 'Une erreur est survenue. Merci de réessayer.'];
    }
}

/** Confirme après paiement réussi (appelé par la passerelle de paiement, ou par le paiement de test). */
function confirm_booking(array $b, string $paymentRef): bool {
    if ($b['status'] === 'confirmée') return true;
    if ($b['status'] !== 'bloquée') return false;
    // si le blocage a expiré, on vérifie que la chambre est toujours libre avant de confirmer
    if ($b['hold_until'] < now()) {
        $room = q('SELECT * FROM rooms WHERE id = ?', [$b['room_id']])->fetch();
        q("UPDATE bookings SET status = 'expirée' WHERE id = ?", [$b['id']]);
        if (!$room || room_free($room, $b['check_in'], $b['check_out']) < 1) return false;
    }
    q("UPDATE bookings SET status = 'confirmée', paid_at = ?, payment_ref = ?, hold_until = NULL WHERE id = ?", [now(), $paymentRef, $b['id']]);
    try { notify_confirmed(q('SELECT * FROM bookings WHERE id = ?', [$b['id']])->fetch()); } catch (Throwable $e) { error_log('[StayInRiad] email confirmation : ' . $e->getMessage()); }
    return true;
}
/** Marque comme expirés les paiements abandonnés. */
function expire_holds(): void { q("UPDATE bookings SET status = 'expirée' WHERE status = 'bloquée' AND hold_until < ?", [now()]); }

/** Paiement en ligne : tant qu'aucune passerelle n'est branchée, seul l'admin peut faire un paiement de TEST. */
function payment_available(): bool { global $CONFIG; return ($CONFIG['payment']['provider'] ?? '') !== '' && ($CONFIG['payment']['provider'] ?? '') !== 'test'; }
function test_payment_allowed(): bool { $u = current_user(); return $u && $u['role'] === 'admin'; }

/** Photos publiées d'un riad (avec logo). */
function riad_photos(int $riadId, ?int $roomId = null): array {
    $sql = "SELECT public_file FROM photos WHERE riad_id = ? AND status = 'publiée'" . ($roomId !== null ? ' AND room_id = ?' : '') . ' ORDER BY room_id IS NOT NULL, sort, id';
    return array_column(q($sql, $roomId !== null ? [$riadId, $roomId] : [$riadId])->fetchAll(), 'public_file');
}

// =====================================================================
//  Étape 4 : emails, annulation, versements, fiche de police
// =====================================================================
function booking_url(array $b, string $page = 'booking.php'): string { return base_url() . $page . '?ref=' . urlencode($b['ref']) . '&t=' . urlencode($b['token']); }
function cancel_policy_text(array $r): string { return $r['free_cancel'] ? 'Annulation gratuite jusqu\'à 48 h avant l\'arrivée. Ensuite, la première nuit est due.' : 'Tarif non remboursable.'; }

/** Conditions d'annulation à l'instant présent : [pénalité, remboursement, explication]. */
function cancel_terms(array $b, array $r): array {
    $total = (float)$b['total']; $night = min($total, (float)$b['price_night']);
    $hours = (strtotime($b['check_in'] . ' 14:00:00') - time()) / 3600; // arrivée vers 14 h, heure du Maroc
    if (!$r['free_cancel']) return [$total, 0.0, 'Tarif non remboursable : le montant total reste dû.'];
    if ($hours >= 48) return [0.0, $total, 'Annulation gratuite : vous êtes remboursé intégralement.'];
    return [$night, round($total - $night, 2), 'Moins de 48 h avant l\'arrivée : la première nuit reste due, le reste vous est remboursé.'];
}

/** Annule une réservation confirmée. $by = 'client' ou 'admin'. */
function cancel_booking(array $b, string $by): ?string {
    if ($b['status'] !== 'confirmée') return 'Cette réservation ne peut plus être annulée.';
    if ($b['check_in'] <= today() && $by !== 'admin') return 'Le séjour a commencé : contactez-nous à contact@stayinriad.com.';
    $r = q('SELECT * FROM riads WHERE id = ?', [$b['riad_id']])->fetch();
    [$pen, $ref] = cancel_terms($b, $r);
    if ($by === 'admin' && !empty($GLOBALS['FULL_REFUND'])) { $pen = 0.0; $ref = (float)$b['total']; }
    $com = round($pen * (float)$b['commission_rate'] / 100, 2); $net = round($pen - $com, 2);
    q("UPDATE bookings SET status = 'annulée', cancelled_at = ?, penalty = ?, refund = ?, commission = ?, net = ?, net_mad = ?, refund_status = ? WHERE id = ?",
      [now(), $pen, $ref, $com, $net, round($net * (float)$b['eur_mad'], 2), $ref > 0 ? 'à rembourser' : '', $b['id']]);
    $b = q('SELECT * FROM bookings WHERE id = ?', [$b['id']])->fetch();
    notify_cancelled($b, $r, $by);
    return null;
}

// ---------- Emails liés aux réservations ----------
function notify_confirmed(array $b): void {
    $r = q('SELECT r.*, u.email AS owner_email, u.first_name AS owner_first FROM riads r JOIN users u ON u.id = r.owner_id WHERE r.id = ?', [$b['riad_id']])->fetch();
    $stay = e(fr_date($b['check_in'])) . ' → ' . e(fr_date($b['check_out'])) . ' (' . (int)$b['nights'] . ' nuit' . ($b['nights'] > 1 ? 's' : '') . ')';
    $test = str_starts_with((string)$b['payment_ref'], 'TEST-') ? '<p style="background:#e7f1fb;padding:10px;border-radius:8px">Réservation de <b>TEST</b> : aucun paiement réel.</p>' : '';
    // Client
    send_mail($b['email'], 'Réservation confirmée ' . $b['ref'] . ' · ' . $r['name'], mail_layout('Votre réservation est confirmée', $test .
        '<p>Bonjour ' . e($b['first_name']) . ',</p><p>Merci pour votre réservation : <b>' . e($r['name']) . '</b> vous attend.</p>' .
        mail_rows(['N° de réservation' => e($b['ref']), 'Riad' => e($r['name']), 'Adresse' => e($r['address'] ?: $r['city']), 'Chambre' => e($b['room_name']), 'Séjour' => $stay,
                   'Voyageurs' => (int)$b['guests'] . ' personne(s)', 'Montant payé' => e(eur((float)$b['total'])), 'Taxe de séjour' => 'à régler sur place, au riad']) .
        '<p style="font-size:14px">' . e(cancel_policy_text($r)) . '</p>' .
        '<p><b>Fiche de police :</b> la loi marocaine demande une fiche pour chaque voyageur. Remplissez-la en ligne avant votre arrivée, cela vous fera gagner du temps à l\'accueil.</p>' .
        mail_button(booking_url($b, 'police.php'), 'Remplir la fiche de police') . '<p style="font-size:14px">Retrouvez ou annulez votre réservation : <a href="' . e(booking_url($b)) . '" style="color:#0f5a4a">voir ma réservation</a>.</p>'));
    // Riad
    send_mail($r['owner_email'], 'Nouvelle réservation ' . $b['ref'] . ' · ' . $b['first_name'] . ' ' . $b['last_name'], mail_layout('Nouvelle réservation', $test .
        '<p>Bonjour ' . e($r['owner_first']) . ',</p><p>Vous avez une nouvelle réservation sur StayInRiad. La chambre est déjà bloquée sur votre calendrier StayInRiad : pensez à la fermer sur vos autres sites (Booking, Airbnb…) si ce n\'est pas automatique.</p>' .
        mail_rows(['N°' => e($b['ref']), 'Client' => e($b['first_name'] . ' ' . $b['last_name']) . ' (' . e($b['country']) . ')', 'Téléphone' => e($b['phone']), 'Chambre' => e($b['room_name']), 'Séjour' => $stay,
                   'Voyageurs' => (int)$b['guests'], 'Demande' => e($b['request'] ?: '—'), 'Votre revenu net' => e(eur((float)$b['net'])) . ' · ' . e(mad((float)$b['net_mad']))]) .
        mail_button(base_url() . 'bookings.php', 'Voir dans mon espace')));
    // Admin
    send_mail(admin_email(), '[StayInRiad] Réservation ' . $b['ref'] . ' · ' . $r['name'] . ' · ' . eur((float)$b['total']), mail_layout('Nouvelle réservation', $test . mail_rows(['N°' => e($b['ref']), 'Riad' => e($r['name']), 'Séjour' => $stay, 'Payé' => e(eur((float)$b['total'])), 'Commission' => e(eur((float)$b['commission']))])));
}
function notify_cancelled(array $b, array $r, string $by): void {
    $owner = q('SELECT email, first_name FROM users WHERE id = ?', [$r['owner_id']])->fetch();
    $money = mail_rows(['N°' => e($b['ref']), 'Séjour' => e(fr_date($b['check_in'])) . ' → ' . e(fr_date($b['check_out'])), 'Montant payé' => e(eur((float)$b['total'])), 'Remboursé' => e(eur((float)$b['refund'])), 'Frais d\'annulation' => e(eur((float)$b['penalty']))]);
    send_mail($b['email'], 'Annulation de votre réservation ' . $b['ref'], mail_layout('Réservation annulée', '<p>Bonjour ' . e($b['first_name']) . ',</p><p>Votre réservation chez <b>' . e($r['name']) . '</b> est annulée' . ($by === 'admin' ? ' par StayInRiad' : '') . '.</p>' . $money . ((float)$b['refund'] > 0 ? '<p>Le remboursement de <b>' . e(eur((float)$b['refund'])) . '</b> est effectué sur la carte utilisée. Selon votre banque, il apparaît sous 5 à 10 jours ouvrés.</p>' : '')));
    if ($owner) send_mail($owner['email'], 'Réservation annulée ' . $b['ref'], mail_layout('Réservation annulée', '<p>Bonjour ' . e($owner['first_name']) . ',</p><p>La réservation <b>' . e($b['ref']) . '</b> de ' . e($b['first_name'] . ' ' . $b['last_name']) . ' (' . e($b['room_name']) . ', ' . e(fr_date($b['check_in'])) . ' → ' . e(fr_date($b['check_out'])) . ') est annulée. La chambre est de nouveau disponible sur StayInRiad.</p>' . ((float)$b['net'] > 0 ? '<p>Frais d\'annulation conservés : votre part nette de <b>' . e(eur((float)$b['net'])) . '</b> (' . e(mad((float)$b['net_mad'])) . ') vous sera versée.</p>' : '')));
    send_mail(admin_email(), '[StayInRiad] Annulation ' . $b['ref'] . ((float)$b['refund'] > 0 ? ' · à rembourser ' . eur((float)$b['refund']) : ''), mail_layout('Annulation', $money));
}

// ---------- Versements (chaque lundi, pour les départs de la semaine précédente) ----------
function pay_day(string $checkout): string { $dw = (int)date('N', strtotime($checkout)); return date('Y-m-d', strtotime($checkout . ' +' . (8 - $dw) . ' days')); }
function next_pay_day(): string { return pay_day(date('Y-m-d', strtotime('-1 day'))); }
/** Réservations dont le net est à verser aujourd'hui (séjour terminé la semaine précédente, pas encore versé). */
function payable_bookings(?int $riadId = null, bool $dueOnly = true): array {
    $rows = q("SELECT * FROM bookings WHERE payout_id IS NULL AND net > 0 AND (status = 'confirmée' OR (status = 'annulée' AND penalty > 0))" . ($riadId ? ' AND riad_id = ?' : '') . ' ORDER BY check_out', $riadId ? [$riadId] : [])->fetchAll();
    return array_values(array_filter($rows, fn($b) => !$dueOnly || pay_day($b['check_out']) <= today()));
}

// ---------- Fiche de police : chiffrée (AES-256-GCM), clé privée hors du site ----------
const POLICE_FIELDS = [
    'last' => ['Nom', 'الاسم العائلي'], 'first' => ['Prénom', 'الاسم الشخصي'], 'birth_date' => ['Date de naissance', 'تاريخ الازدياد'],
    'birth_place' => ['Lieu de naissance', 'مكان الازدياد'], 'nationality' => ['Nationalité', 'الجنسية'], 'profession' => ['Profession', 'المهنة'],
    'address' => ['Adresse habituelle', 'العنوان الاعتيادي'], 'doc_type' => ['Pièce d\'identité', 'نوع الوثيقة'], 'doc_no' => ['N° de la pièce', 'رقم الوثيقة'],
    'doc_date' => ['Délivrée le', 'تاريخ التسليم'], 'entry_date' => ['Date d\'entrée au Maroc', 'تاريخ الدخول إلى المغرب'],
    'from' => ['Venant de', 'قادم من'], 'to' => ['Se rendant à', 'متوجه إلى'],
];
function police_key(): string {
    $f = private_dir() . '/police.key';
    if (!is_file($f)) { file_put_contents($f, random_bytes(32)); @chmod($f, 0600); }
    return (string)file_get_contents($f);
}
function police_encrypt(array $data): string {
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', police_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $c);
}
function police_decrypt(string $blob): array {
    $raw = base64_decode($blob, true); if ($raw === false || strlen($raw) < 29) return [];
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', police_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? [] : (json_decode($p, true) ?: []);
}

require_once __DIR__ . '/ical.php';
require_once __DIR__ . '/reviews.php';
require_once __DIR__ . '/statement.php';
