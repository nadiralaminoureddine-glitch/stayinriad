<?php
// StayInRiad — synchronisation des calendriers avec Booking.com, Airbnb, etc. (format iCal)
//
// Principe :
//  - EXPORT : chaque chambre a un lien privé (ical.php?room=…&t=…) que le riad colle dans Booking/Airbnb.
//    Il contient UNIQUEMENT les réservations StayInRiad et les jours fermés à la main — jamais ce qui vient
//    de Booking/Airbnb, pour éviter les boucles (une réservation qui se renverrait d'un site à l'autre sans fin).
//  - IMPORT : le riad colle ici les liens iCal de Booking/Airbnb. Ils sont relus toutes les 15 minutes (cron),
//    et relus EN DIRECT juste avant chaque paiement.
//  - Réservé à une chambre unique (nombre = 1) : c'est ainsi que Booking et Airbnb gèrent l'iCal.
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const ICAL_SOURCES = ['Booking.com', 'Airbnb', 'Expedia', 'Autre'];
const ICAL_MAX_BYTES = 2_000_000;
const ICAL_TIMEOUT = 8;          // secondes, synchronisation de fond
const ICAL_LIVE_TIMEOUT = 5;     // secondes, juste avant le paiement
const ICAL_EXPORT_DAYS = 550;    // ~18 mois

function room_ical_token(array $room): string {
    if (!empty($room['ical_token'])) return $room['ical_token'];
    $t = bin2hex(random_bytes(16));
    q('UPDATE rooms SET ical_token = ? WHERE id = ?', [$t, $room['id']]);
    return $t;
}
function ical_export_url(array $room): string {
    return base_url() . 'ical.php?room=' . (int)$room['id'] . '&t=' . room_ical_token($room);
}
function room_feeds(int $roomId): array { return q('SELECT * FROM ical_feeds WHERE room_id = ? ORDER BY id', [$roomId])->fetchAll(); }

/** Jours occupés par des réservations venant de Booking/Airbnb : [jour => nombre]. */
function ext_days(int $roomId, string $from, string $to): array {
    $out = [];
    foreach (q('SELECT start_day, end_day FROM ext_blocks WHERE room_id = ? AND start_day < ? AND end_day > ?', [$roomId, $to, $from])->fetchAll() as $b) {
        $d = max($b['start_day'], $from); $end = min($b['end_day'], $to);
        for (; $d < $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $out[$d] = ($out[$d] ?? 0) + 1;
    }
    return $out;
}

// ---------------------------------------------------------------- sécurité des liens
/** Vérifie un lien iCal : https, adresse publique (pas le serveur lui-même ni un réseau interne). Retourne [hôte, ip, erreur]. */
function ical_check_url(string $url): array {
    $p = parse_url($url);
    if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return [null, null, 'Le lien doit commencer par https://'];
    if (isset($p['user']) || isset($p['pass'])) return [null, null, 'Lien non accepté.'];
    if (isset($p['port']) && (int)$p['port'] !== 443) return [null, null, 'Lien non accepté.'];
    $host = strtolower($p['host']);
    if (filter_var($host, FILTER_VALIDATE_IP)) return [null, null, 'Lien non accepté.'];
    $ips = @gethostbynamel($host) ?: [];
    if (!$ips) return [null, null, 'Adresse introuvable : vérifiez le lien.'];
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return [null, null, 'Lien non accepté.'];
    }
    return [$host, $ips[0], null];
}

/** Télécharge un calendrier. Retourne [texte, erreur]. */
function ical_fetch(string $url, int $timeout = ICAL_TIMEOUT): array {
    if (!function_exists('curl_init')) return [null, 'Extension curl absente sur le serveur.'];
    for ($hop = 0; $hop < 4; $hop++) {
        [$host, $ip, $err] = ical_check_url($url);
        if ($err) return [null, $err];
        $body = ''; $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["$host:443:$ip"],              // on se connecte à l'adresse vérifiée, pas à une autre
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'StayInRiad-Calendar/1.0 (+https://stayinriad.com)',
            CURLOPT_HTTPHEADER => ['Accept: text/calendar, text/plain, */*'],
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$tooBig) {
                $body .= $chunk; if (strlen($body) > ICAL_MAX_BYTES) { $tooBig = true; return 0; } return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($tooBig) return [null, 'Calendrier trop volumineux.'];
        if ($ok === false) return [null, 'Pas de réponse (' . mb_substr($cerr, 0, 80) . ').'];
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        if ($code !== 200) return [null, "Le site a répondu avec l'erreur $code : le lien est peut-être expiré."];
        if (stripos($body, 'BEGIN:VCALENDAR') === false) return [null, 'Ce lien ne renvoie pas un calendrier iCal.'];
        return [$body, null];
    }
    return [null, 'Trop de redirections.'];
}

/** Lit un fichier iCal : liste de [début, fin[ en jours. */
function ical_parse(string $text): array {
    $text = preg_replace("/\r\n[ \t]|\n[ \t]/", '', str_replace("\r\n", "\n", $text)); // lignes repliées
    $events = []; $cur = null;
    foreach (explode("\n", $text) as $line) {
        $line = rtrim($line, "\r");
        if ($line === 'BEGIN:VEVENT') { $cur = []; continue; }
        if ($line === 'END:VEVENT') {
            if ($cur !== null && !empty($cur['s']) && strtoupper($cur['status'] ?? '') !== 'CANCELLED') {
                $s = $cur['s']; $e = $cur['e'] ?? null;
                if (!$e || $e <= $s) $e = date('Y-m-d', strtotime($s . ' +1 day'));
                $events[] = [$s, $e];
            }
            $cur = null; continue;
        }
        if ($cur === null || !str_contains($line, ':')) continue;
        [$k, $v] = explode(':', $line, 2); $name = strtoupper(explode(';', $k)[0]);
        if ($name === 'DTSTART' || $name === 'DTEND') {
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($v), $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) continue;
            $cur[$name === 'DTSTART' ? 's' : 'e'] = "$m[1]-$m[2]-$m[3]";
        } elseif ($name === 'STATUS') $cur['status'] = trim($v);
    }
    return $events;
}

/** Relit un calendrier Booking/Airbnb et remplace ses blocages. Si la lecture échoue, les anciens blocages restent (prudence). */
function ical_sync_feed(array $feed, int $timeout = ICAL_TIMEOUT): bool {
    [$text, $err] = ical_fetch($feed['url'], $timeout);
    if ($err === null) {
        $from = today(); $to = date('Y-m-d', strtotime('+2 years'));
        $ev = array_slice(array_values(array_filter(ical_parse($text), fn($x) => $x[1] > $from && $x[0] < $to)), 0, 3000);
        $pdo = db(); $pdo->beginTransaction();
        try {
            q('DELETE FROM ext_blocks WHERE feed_id = ?', [$feed['id']]);
            foreach ($ev as [$s, $e]) q('INSERT INTO ext_blocks (feed_id, room_id, start_day, end_day) VALUES (?,?,?,?)', [$feed['id'], $feed['room_id'], $s, $e]);
            q("UPDATE ical_feeds SET last_sync = ?, last_ok = ?, last_error = '', events = ?, fail_since = NULL, alerted = 0 WHERE id = ?", [now(), now(), count($ev), $feed['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[StayInRiad iCal] ' . $e->getMessage()); $err = 'Erreur interne.';
        }
        if ($err === null) return true;
    }
    q('UPDATE ical_feeds SET last_sync = ?, last_error = ?, fail_since = COALESCE(fail_since, ?) WHERE id = ?', [now(), mb_substr($err, 0, 250), now(), $feed['id']]);
    return false;
}

/** Relecture en direct avant un paiement (toutes les sources de la chambre). */
function ical_sync_room(int $roomId, int $timeout = ICAL_LIVE_TIMEOUT): void {
    foreach (room_feeds($roomId) as $f) ical_sync_feed($f, $timeout);
}

/** Tâche planifiée : relit les calendriers qui n'ont pas été lus depuis 14 minutes, prévient le riad si un lien ne marche plus depuis 6 h. */
function ical_sync_due(int $max = 150): array {
    $log = [];
    $old = gmdate('Y-m-d H:i:s', time() - 14 * 60);
    foreach (q('SELECT * FROM ical_feeds WHERE last_sync IS NULL OR last_sync < ? ORDER BY last_sync LIMIT ' . (int)$max, [$old])->fetchAll() as $f) {
        if (!ical_sync_feed($f)) $log[] = 'iCal en erreur #' . $f['id'];
    }
    $limit = gmdate('Y-m-d H:i:s', time() - 6 * 3600);
    foreach (q('SELECT f.*, r.name AS room_name, r.riad_id FROM ical_feeds f JOIN rooms r ON r.id = f.room_id WHERE f.fail_since IS NOT NULL AND f.fail_since < ? AND f.alerted = 0', [$limit])->fetchAll() as $f) {
        $riad = q('SELECT r.name, u.email FROM riads r JOIN users u ON u.id = r.owner_id WHERE r.id = ?', [$f['riad_id']])->fetch();
        $body = '<p>Bonjour,</p><p>Depuis plus de 6 heures, StayInRiad n\'arrive plus à lire votre calendrier <b>' . e($f['source']) . '</b> pour la chambre <b>' . e($f['room_name']) . '</b>.</p><p>Raison : ' . e($f['last_error']) . '</p><p>Tant que ce n\'est pas réglé, les nouvelles réservations ' . e($f['source']) . ' de cette chambre ne bloquent plus les dates sur StayInRiad. Vérifiez le lien dans votre espace (onglet <b>Booking/Airbnb</b>), ou fermez les dates à la main.</p>' . mail_button(base_url() . 'sync.php', 'Ouvrir la synchronisation');
        if ($riad) send_mail($riad['email'], 'Calendrier ' . $f['source'] . ' non synchronisé — ' . $f['room_name'], mail_layout('Synchronisation interrompue', $body));
        send_mail(admin_email(), '[Admin] iCal en erreur — ' . ($riad['name'] ?? '') . ' / ' . $f['room_name'], mail_layout('iCal en erreur', $body));
        q('UPDATE ical_feeds SET alerted = 1 WHERE id = ?', [$f['id']]);
        $log[] = 'alerte iCal #' . $f['id'];
    }
    return $log;
}

// ---------------------------------------------------------------- export
function ical_esc(string $s): string { return str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s); }

/** Calendrier exporté d'une chambre : réservations StayInRiad + jours fermés à la main (jamais les blocages importés). */
function ical_export(array $room): string {
    $from = today(); $to = date('Y-m-d', strtotime('+' . ICAL_EXPORT_DAYS . ' days'));
    $busy = [];
    foreach (q('SELECT check_in, check_out FROM bookings WHERE room_id = ? AND check_in < ? AND check_out > ? AND ' . occupying_sql(), [$room['id'], $to, $from, now()])->fetchAll() as $b) {
        for ($d = max($b['check_in'], $from); $d < min($b['check_out'], $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) $busy[$d] = 1;
    }
    foreach (q('SELECT day FROM closed_days WHERE room_id = ? AND day >= ? AND day < ?', [$room['id'], $from, $to])->fetchAll() as $c) $busy[$c['day']] = 1;
    ksort($busy);
    // regroupe les jours qui se suivent en une seule période
    $ranges = []; $start = $prev = null;
    foreach (array_keys($busy) as $d) {
        if ($prev !== null && $d === date('Y-m-d', strtotime($prev . ' +1 day'))) { $prev = $d; continue; }
        if ($start !== null) $ranges[] = [$start, date('Y-m-d', strtotime($prev . ' +1 day'))];
        $start = $prev = $d;
    }
    if ($start !== null) $ranges[] = [$start, date('Y-m-d', strtotime($prev . ' +1 day'))];

    $stamp = gmdate('Ymd\THis\Z');
    $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//StayInRiad//Calendrier//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
          'X-WR-CALNAME:' . ical_esc('StayInRiad - ' . $room['name'])];
    foreach ($ranges as [$s, $e]) {
        $L[] = 'BEGIN:VEVENT';
        $L[] = 'UID:sir-' . (int)$room['id'] . '-' . str_replace('-', '', $s) . '@stayinriad.com';
        $L[] = 'DTSTAMP:' . $stamp;
        $L[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $s);
        $L[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', $e);
        $L[] = 'SUMMARY:StayInRiad - Indisponible';
        $L[] = 'TRANSP:OPAQUE';
        $L[] = 'END:VEVENT';
    }
    $L[] = 'END:VCALENDAR';
    return implode("\r\n", $L) . "\r\n";
}

// ---------------------------------------------------------------- état de la synchronisation (riad + admin)
const SYNC_TEAM_EMAIL = 'contact@stayinriad.com';
const SYNC_CHANNELS = ['booking' => 'Booking.com', 'airbnb' => 'Airbnb'];

/** Note qui lit notre lien (Booking, Airbnb…) : c'est la preuve que le lien StayInRiad est bien installé chez eux. */
function ical_note_reader(array $room, string $ua): void {
    $col = stripos($ua, 'booking') !== false ? 'seen_booking' : (stripos($ua, 'airbnb') !== false ? 'seen_airbnb' : null);
    if ($col === null) {
        if (preg_match('/Mozilla|Chrome|Safari/i', $ua) && !preg_match('/bot|crawl|calendar|ical/i', $ua)) return; // un navigateur : ignoré
        $col = 'seen_other';
    }
    q("UPDATE rooms SET $col = ? WHERE id = ?", [now(), $room['id']]);
}
function riad_channels(array $r): array { return $r['channels'] === null || $r['channels'] === '' || $r['channels'] === 'none' ? [] : array_values(array_intersect(explode(',', $r['channels']), array_keys(SYNC_CHANNELS))); }
function seen_recent(?string $t): bool { return $t !== null && strtotime($t . ' UTC') > time() - 3 * 86400; }

/** Pour une chambre et un site : [import OK, notre lien lu par ce site]. */
function room_sync_state(array $room, string $ch): array {
    $label = SYNC_CHANNELS[$ch];
    $in = false;
    foreach (room_feeds((int)$room['id']) as $f) if ($f['source'] === $label && $f['last_ok'] && $f['last_error'] === '') $in = true;
    return [$in, seen_recent($room['seen_' . $ch] ?? null)];
}
/** Résumé pour un riad : 'aucun' (pas sur Booking/Airbnb), 'complet', 'à faire', ou 'non renseigné'. */
function riad_sync_summary(array $r): array {
    if ($r['channels'] === null || $r['channels'] === '') return ['non renseigné', []];
    $chs = riad_channels($r); if (!$chs) return ['aucun', []];
    $todo = [];
    foreach (q('SELECT * FROM rooms WHERE riad_id = ? AND active = 1 ORDER BY id', [$r['id']])->fetchAll() as $x) {
        foreach ($chs as $ch) {
            if ((int)$x['qty'] !== 1) { $todo[] = $x['name'] . ' : une ligne par chambre'; continue 2; }
            [$in, $out] = room_sync_state($x, $ch);
            if (!$in) $todo[] = $x['name'] . ' : lien ' . SYNC_CHANNELS[$ch] . ' à coller sur StayInRiad';
            if (!$out) $todo[] = $x['name'] . ' : lien StayInRiad à installer chez ' . SYNC_CHANNELS[$ch];
        }
    }
    return [$todo ? 'à faire' : 'complet', $todo];
}
function sync_pill(array $r): string {
    [$s] = riad_sync_summary($r);
    return match ($s) { 'complet' => '<span class="pill ok">Booking/Airbnb synchronisé</span>', 'aucun' => '<span class="pill info">Pas sur Booking/Airbnb</span>', 'à faire' => '<span class="pill info">Réservation sur demande · synchro facultative à terminer</span>', default => '<span class="pill info">Réservation sur demande</span>' };
}
