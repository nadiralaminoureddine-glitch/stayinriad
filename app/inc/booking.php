<?php
// StayInRiad — disponibilités, prix et réservations
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const HOLD_MINUTES = 15;   // la chambre est bloquée pendant le paiement
const MAX_NIGHTS = 30;
const BOOKING_STATUS = ['bloquée' => ['Paiement en cours', 'warn'], 'confirmée' => ['Confirmée', 'ok'], 'annulée' => ['Annulée', 'bad'], 'expirée' => ['Expirée', 'info']];

function eur(float $v): string { return number_format($v, $v == floor($v) ? 0 : 2, ',', ' ') . ' €'; }
function mad(float $v): string { return number_format($v, 0, ',', ' ') . ' MAD'; }
function eur_mad(): float { global $CONFIG; return (float)($CONFIG['eur_mad'] ?? 10.75); }
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
    for ($d = $in; $d < $out; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (isset($closed[$d])) return 0;
        $used = 0; foreach ($books as $b) if ($b['check_in'] <= $d && $d < $b['check_out']) $used++;
        $free = min($free, (int)$room['qty'] - $used);
    }
    return max(0, $free);
}
/** Chambres libres jour par jour (pour le calendrier du riad). */
function room_days(array $room, string $from, int $days): array {
    $to = date('Y-m-d', strtotime("$from +$days days"));
    $books = q('SELECT check_in, check_out FROM bookings WHERE room_id = ? AND check_in < ? AND check_out > ? AND ' . occupying_sql(), [$room['id'], $to, $from, now()])->fetchAll();
    $closed = array_flip(array_column(q('SELECT day FROM closed_days WHERE room_id = ? AND day >= ? AND day < ?', [$room['id'], $from, $to])->fetchAll(), 'day'));
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime("$from +$i days")); $used = 0;
        foreach ($books as $b) if ($b['check_in'] <= $d && $d < $b['check_out']) $used++;
        $out[$d] = ['free' => max(0, (int)$room['qty'] - $used), 'used' => $used, 'closed' => isset($closed[$d])];
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
    $pdo = db(); $my = ($CONFIG['db']['driver'] ?? 'mysql') !== 'sqlite';
    $pdo->beginTransaction();
    try {
        if ($my) q('SELECT id FROM rooms WHERE id = ? FOR UPDATE', [$room['id']]); // une seule réservation à la fois sur cette chambre
        else $pdo->exec('UPDATE rooms SET active = active WHERE id = ' . (int)$room['id']);
        if (room_free($room, $in, $out) < 1) { $pdo->rollBack(); return [null, 'Désolé, cette chambre vient d\'être réservée pour ces dates. Choisissez une autre chambre ou d\'autres dates.']; }
        $price = (float)$room['price_eur']; $sub = round($price * $nights, 2);
        $rate = (float)$riad['commission']; $com = round($sub * $rate / 100, 2); $net = round($sub - $com, 2);
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
