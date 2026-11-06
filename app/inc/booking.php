<?php
// StayInRiad — disponibilités, prix et réservations
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const HOLD_MINUTES = 15;   // la chambre est bloquée pendant le paiement
const MAX_NIGHTS = 30;
const REQUEST_HOURS = 6;      // délai de réponse du riad à une demande de réservation
const ACCEPTED_PAY_HOURS = 24; // délai de paiement du voyageur après acceptation
const BOOKING_STATUS = ['demande' => ['En attente du riad', 'warn'], 'bloquée' => ['Paiement en cours', 'warn'], 'confirmée' => ['Confirmée', 'ok'], 'refusée' => ['Refusée', 'bad'], 'annulée' => ['Annulée', 'bad'], 'expirée' => ['Expirée', 'info']];

function eur(float $v): string { return number_format($v, $v == floor($v) ? 0 : 2, ',', ' ') . ' €'; }
function mad(float $v): string { return number_format($v, 0, ',', ' ') . ' MAD'; }
/** Taux de change (modifiables par l'admin). 1 € = X MAD / X $. */
/** Met à jour les taux EUR→MAD/USD automatiquement (une fois par jour, source : open.er-api.com). */
function rates_refresh(): void {
    static $done = false; if ($done) return; $done = true;
    try { $at = q('SELECT v FROM settings WHERE k = ?', ['rate_at'])->fetchColumn(); } catch (Throwable $e) { return; }
    if ($at && strtotime($at . ' UTC') > time() - 86400) return;
    q('DELETE FROM settings WHERE k = ?', ['rate_at']); q('INSERT INTO settings (k, v) VALUES (?, ?)', ['rate_at', now()]);
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true], 'ssl' => ['verify_peer' => true]]);
    $j = json_decode((string)@file_get_contents('https://open.er-api.com/v6/latest/EUR', false, $ctx), true);
    $m = (float)($j['rates']['MAD'] ?? 0); $d = (float)($j['rates']['USD'] ?? 0);
    if ($m < 5 || $m > 20 || $d < 0.5 || $d > 2) return;
    foreach (['rate_mad' => round($m, 4), 'rate_usd' => round($d, 4)] as $kk => $v) { q('DELETE FROM settings WHERE k = ?', [$kk]); q('INSERT INTO settings (k, v) VALUES (?, ?)', [$kk, (string)$v]); }
}
function rate(string $k): float {
    global $CONFIG; static $c = [];
    if (!$c) { try { rates_refresh(); } catch (Throwable $e) {} }
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
function occupying_sql(): string { return "(status = 'confirmée' OR (status IN ('bloquée', 'demande') AND hold_until > ?))"; }

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
function create_hold(array $riad, array $room, string $in, string $out, int $nights, int $guests, array $guest, ?int $userId, bool $request = false): array {
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
            $request ? 'demande' : 'bloquée', gmdate('Y-m-d H:i:s', time() + ($request ? REQUEST_HOURS * 3600 : HOLD_MINUTES * 60)), now()]);
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
        mail_button(booking_url($b, 'police.php'), 'Remplir la fiche de police') . '<p style="font-size:14px">Retrouvez ou annulez votre réservation : <a href="' . e(booking_url($b)) . '" style="color:#2647a8">voir ma réservation</a>.</p>'));
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

// =====================================================================
//  Demandes de réservation : le riad confirme d'un clic (pas de synchro obligatoire)
// =====================================================================
/** Réservation immédiate : le calendrier du riad est fiable (synchronisé, ou riad absent de Booking/Airbnb). */
function riad_instant(array $r): bool { [$s] = riad_sync_summary($r); return in_array($s, ['complet', 'aucun'], true); }
function instant_pill(array $r): string {
    return riad_instant($r) ? '<span class="pill ok">⚡ Réservation immédiate</span>' : '<span class="pill info">Confirmation du riad sous ' . REQUEST_HOURS . ' h</span>';
}
/** Jeton du lien Accepter / Refuser envoyé au riad. */
function riad_key(array $b): string { return substr(hash_hmac('sha256', 'riad|' . $b['ref'] . '|' . $b['token'], app_key()), 0, 32); }
function respond_url(array $b, string $a = ''): string { return base_url() . 'respond.php?ref=' . urlencode($b['ref']) . '&k=' . riad_key($b) . ($a ? '&a=' . $a : ''); }
function hours_left(?string $until): string {
    $m = max(0, (int)ceil((strtotime((string)$until . ' UTC') - time()) / 60));
    return $m >= 90 ? (int)round($m / 60) . ' h' : $m . ' min';
}
function stay_text(array $b): string { return fr_date($b['check_in']) . ' → ' . fr_date($b['check_out']) . ' (' . (int)$b['nights'] . ' nuit' . ($b['nights'] > 1 ? 's' : '') . ')'; }
function riad_owner(int $riadId): array {
    return q('SELECT r.*, u.email AS owner_email, u.first_name AS owner_first, u.phone AS owner_phone FROM riads r JOIN users u ON u.id = r.owner_id WHERE r.id = ?', [$riadId])->fetch() ?: [];
}
/** Lien WhatsApp (wa.me) avec message pré-rempli. */
function wa_link(string $phone, string $text): string { return 'https://wa.me/' . ltrim((string)intl_phone($phone), '+') . '?text=' . rawurlencode($text); }
function request_wa_text(array $b, array $r): string {
    return "Bonjour, nouvelle demande de réservation StayInRiad pour {$r['name']} :\n{$b['room_name']}, " . stay_text($b) . ', ' . (int)$b['guests'] . " pers.\nVotre revenu net : " . mad((float)$b['net_mad'])
        . "\n\nAccepter : " . respond_url($b, 'accept') . "\nRefuser : " . respond_url($b, 'refuse') . "\n\nSans réponse sous " . REQUEST_HOURS . " h, la demande est annulée.\nL'équipe StayInRiad";
}

function notify_request(array $b): void {
    $r = riad_owner((int)$b['riad_id']); if (!$r) return;
    $rows = mail_rows(['N°' => e($b['ref']), 'Client' => e($b['first_name'] . ' ' . $b['last_name']) . ' (' . e($b['country']) . ')', 'Chambre' => e($b['room_name']), 'Séjour' => e(stay_text($b)),
        'Voyageurs' => (int)$b['guests'], 'Demande' => e($b['request'] ?: '—'), 'Votre revenu net' => e(mad((float)$b['net_mad'])) . ' · ' . e(eur((float)$b['net']))]);
    // Riad : email avec les deux boutons
    send_mail($r['owner_email'], 'Demande de réservation à confirmer · ' . $b['room_name'] . ' · ' . fr_date($b['check_in']), mail_layout('Nouvelle demande de réservation',
        '<p>Bonjour ' . e($r['owner_first']) . ',</p><p>Un voyageur souhaite réserver chez <b>' . e($r['name']) . '</b>. La chambre est-elle libre ? Répondez d\'un clic, <b>avant ' . REQUEST_HOURS . ' h</b>.</p>' . $rows
        . mail_button(respond_url($b, 'accept'), '✓ Accepter la réservation')
        . '<p style="text-align:center;font-size:14px"><a href="' . e(respond_url($b, 'refuse')) . '" style="color:#a33">Refuser (chambre déjà prise)</a></p>'
        . '<p style="font-size:14px">Si vous acceptez, pensez à <b>fermer ces dates sur Booking</b> (ou dans votre channel manager), comme pour un client qui vous appelle. Sans réponse, la demande est annulée automatiquement et le voyageur n\'est pas débité.</p>'));
    // Riad : SMS si disponible
    send_sms((string)$r['owner_phone'], 'StayInRiad : demande de réservation ' . $b['room_name'] . ', ' . fr_date($b['check_in']) . ', ' . (int)$b['nights'] . ' nuit(s). Accepter ou refuser : ' . respond_url($b));
    // Voyageur
    send_mail($b['email'], 'Demande envoyée à ' . $r['name'] . ' · ' . $b['ref'], mail_layout('Votre demande est envoyée',
        '<p>Bonjour ' . e($b['first_name']) . ',</p><p>Votre demande est transmise à <b>' . e($r['name']) . '</b>. Le riad confirme la disponibilité <b>sous ' . REQUEST_HOURS . ' h maximum</b>.</p>'
        . '<p><b>Aucun montant n\'est débité</b> tant que le riad n\'a pas accepté. Dès son accord, vous recevez un email pour finaliser le paiement.</p>'
        . mail_rows(['N° de demande' => e($b['ref']), 'Riad' => e($r['name']), 'Chambre' => e($b['room_name']), 'Séjour' => e(stay_text($b)), 'Total' => e(eur((float)$b['total']))])
        . mail_button(booking_url($b), 'Suivre ma demande')));
    // Équipe : pour relancer le riad sur WhatsApp en un clic
    send_mail(admin_email(), '[Demande à confirmer] ' . $r['name'] . ' · ' . $b['ref'], mail_layout('Demande à faire confirmer par le riad', $rows
        . '<p>Le riad a reçu l\'email' . (sms_enabled() ? ' et le SMS' : '') . '. Pour qu\'il réponde vite, envoyez-lui aussi le message WhatsApp pré-rempli :</p>'
        . ($r['owner_phone'] ? mail_button(wa_link((string)$r['owner_phone'], request_wa_text($b, $r)), 'Envoyer sur WhatsApp') : '<p>Pas de téléphone pour ce riad.</p>')));
}

/** Le riad accepte : le voyageur a ACCEPTED_PAY_HOURS h pour payer. */
function accept_request(array $b): ?string {
    if ($b['status'] === 'bloquée' || $b['status'] === 'confirmée') return null;
    if ($b['status'] !== 'demande' || $b['hold_until'] < now()) return 'Cette demande a expiré : le voyageur a été prévenu.';
    q("UPDATE bookings SET status = 'bloquée', hold_until = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', time() + ACCEPTED_PAY_HOURS * 3600), $b['id']]);
    $b = q('SELECT * FROM bookings WHERE id = ?', [$b['id']])->fetch(); $r = riad_owner((int)$b['riad_id']);
    send_mail($b['email'], 'Bonne nouvelle : ' . $r['name'] . ' accepte votre demande', mail_layout('Votre demande est acceptée 🎉',
        '<p>Bonjour ' . e($b['first_name']) . ',</p><p><b>' . e($r['name']) . '</b> confirme que la chambre est disponible. Finalisez votre réservation par le paiement : la chambre vous est réservée pendant <b>' . ACCEPTED_PAY_HOURS . ' h</b>.</p>'
        . mail_rows(['N°' => e($b['ref']), 'Chambre' => e($b['room_name']), 'Séjour' => e(stay_text($b)), 'Total' => e(eur((float)$b['total']))]) . mail_button(booking_url($b), 'Payer et confirmer')));
    return null;
}
/** Le riad refuse (chambre déjà prise ailleurs). */
function refuse_request(array $b): ?string {
    if ($b['status'] === 'refusée') return null;
    if ($b['status'] !== 'demande') return 'Cette demande n\'est plus en attente.';
    q("UPDATE bookings SET status = 'refusée', hold_until = NULL, cancelled_at = ? WHERE id = ?", [now(), $b['id']]);
    $r = riad_owner((int)$b['riad_id']);
    send_mail($b['email'], 'Votre demande chez ' . $r['name'], mail_layout('Chambre indisponible',
        '<p>Bonjour ' . e($b['first_name']) . ',</p><p>Désolé, <b>' . e($r['name']) . '</b> n\'a plus cette chambre de libre du ' . e(stay_text($b)) . '. <b>Aucun montant n\'a été débité.</b></p><p>D\'autres riads sont disponibles à vos dates :</p>'
        . mail_button(base_url() . 'riads.php?' . http_build_query(['city' => $r['city'], 'in' => $b['check_in'], 'out' => $b['check_out'], 'g' => $b['guests']]), 'Voir les riads disponibles')));
    return null;
}
/** Demandes sans réponse : annulées, le voyageur et le riad sont prévenus. */
function expire_requests(): array {
    $log = [];
    foreach (q("SELECT * FROM bookings WHERE status = 'demande' AND hold_until < ?", [now()])->fetchAll() as $b) {
        q("UPDATE bookings SET status = 'expirée' WHERE id = ? AND status = 'demande'", [$b['id']]);
        $r = riad_owner((int)$b['riad_id']);
        send_mail($b['email'], 'Votre demande chez ' . $r['name'], mail_layout('Pas de réponse du riad',
            '<p>Bonjour ' . e($b['first_name']) . ',</p><p><b>' . e($r['name']) . '</b> n\'a pas pu confirmer votre demande à temps. Elle est annulée et <b>aucun montant n\'a été débité</b>.</p>'
            . mail_button(base_url() . 'riads.php?' . http_build_query(['city' => $r['city'], 'in' => $b['check_in'], 'out' => $b['check_out'], 'g' => $b['guests']]), 'Voir les riads disponibles')));
        send_mail($r['owner_email'], 'Demande expirée ' . $b['ref'], mail_layout('Demande expirée', '<p>Bonjour ' . e($r['owner_first']) . ',</p><p>La demande de ' . e($b['first_name']) . ' (' . e(stay_text($b)) . ') n\'a pas reçu de réponse et a été annulée. Pour ne plus manquer de réservations, répondez dès que vous recevez une demande, ou synchronisez votre calendrier : vos voyageurs pourront alors réserver immédiatement.</p>' . mail_button(base_url() . 'sync.php', 'Synchroniser mon calendrier')));
        $log[] = 'demande expirée ' . $b['ref'];
    }
    return $log;
}

require_once __DIR__ . '/ical.php';
require_once __DIR__ . '/reviews.php';
require_once __DIR__ . '/statement.php';
