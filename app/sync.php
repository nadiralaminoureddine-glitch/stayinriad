<?php
// Espace riad — synchronisation des calendriers avec Booking.com et Airbnb (le plus simple possible pour le riad)
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$u = require_login();
if (!in_array($u['role'], ['riad', 'admin'], true)) redirect('account.php');
$r = my_riad($u, isset($_GET['id']) ? (int)$_GET['id'] : null);
if (!$r) redirect('account.php');
$rid = (int)$r['id'];
$q = $u['role'] === 'admin' ? '?id=' . $rid : '';
$owner = q('SELECT first_name, last_name, email, phone FROM users WHERE id = ?', [$r['owner_id']])->fetch() ?: ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];
$owner['name'] = trim($owner['first_name'] . ' ' . $owner['last_name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $room = q('SELECT * FROM rooms WHERE id = ? AND riad_id = ? AND active = 1', [(int)($_POST['room_id'] ?? 0), $rid])->fetch();
    $feed = isset($_POST['feed_id']) ? q('SELECT f.* FROM ical_feeds f JOIN rooms r ON r.id = f.room_id WHERE f.id = ? AND r.riad_id = ?', [(int)$_POST['feed_id'], $rid])->fetch() : null;
    $team = fn(string $subject, string $html) => send_mail(admin_email(), '[Synchro] ' . $subject . ' — ' . $r['name'], mail_layout($subject, $html . '<p>Riad : <b>' . e($r['name']) . '</b> (' . e($r['city']) . ')<br>Contact : ' . e($owner['name']) . ' · ' . e($owner['phone']) . ' · ' . e($owner['email']) . '</p>' . mail_button(base_url() . 'sync.php?id=' . $rid, 'Ouvrir la synchronisation du riad')));

    if ($act === 'channels') {
        $ch = array_values(array_intersect((array)($_POST['ch'] ?? []), array_keys(SYNC_CHANNELS)));
        q('UPDATE riads SET channels = ? WHERE id = ?', [$ch ? implode(',', $ch) : 'none', $rid]);
        flash($ch ? 'C\'est noté. Suivez les étapes ci-dessous.' : 'C\'est noté : rien à synchroniser.');
    } elseif ($act === 'booking_invited') {
        q('UPDATE riads SET sync_booking_invite = ? WHERE id = ?', [now(), $rid]);
        $team('Invitation Booking.com envoyée', '<p>Le riad dit avoir invité <b>' . SYNC_TEAM_EMAIL . '</b> comme utilisateur de son extranet Booking.com. Acceptez l\'invitation (email de Booking), puis pour chaque chambre : importez le lien StayInRiad dans Booking, et collez le lien d\'export Booking dans la page de synchronisation du riad.</p>');
        log_action('sync_booking_invite', $r['name']);
        flash('Merci ! Notre équipe accepte l\'invitation et relie vos chambres, en général sous 24 h.');
    } elseif ($act === 'airbnb_call') {
        q('UPDATE riads SET sync_airbnb_call = ? WHERE id = ?', [now(), $rid]);
        $team('Appel guidé Airbnb demandé', '<p>Le riad demande un appel (5 minutes, partage d\'écran ou WhatsApp) pour relier son calendrier Airbnb.</p><p>Préférence : ' . e(mb_substr(trim((string)($_POST['when'] ?? '')), 0, 120)) . '</p>');
        log_action('sync_airbnb_appel', $r['name']);
        flash('Demande envoyée : nous vous appelons au ' . ($owner['phone'] ?: 'numéro de votre compte') . ' pour le faire ensemble (5 minutes).');
    } elseif ($act === 'add' && $room) {
        $src = in_array($_POST['source'] ?? '', ICAL_SOURCES, true) ? $_POST['source'] : 'Autre';
        $url = trim((string)($_POST['url'] ?? ''));
        if ((int)$room['qty'] !== 1) flash('Booking.com et Airbnb synchronisent chambre par chambre : mettez « Nombre de chambres identiques » à 1 dans « Chambres & prix » (une ligne par chambre).', 'bad');
        elseif ($url === '' || strlen($url) > 600) flash('Collez le lien complet.', 'bad');
        elseif (str_contains($url, 'stayinriad.com')) flash('Ce lien est celui de StayInRiad : il se colle chez Booking/Airbnb. Ici, collez le lien que Booking/Airbnb vous donne (« Exporter le calendrier »).', 'bad');
        elseif ((int)q('SELECT COUNT(*) FROM ical_feeds WHERE room_id = ?', [$room['id']])->fetchColumn() >= 5) flash('5 calendriers maximum par chambre.', 'bad');
        else {
            [$text, $err] = ical_fetch($url);
            if ($err) flash('Lien non enregistré : ' . $err, 'bad');
            else {
                foreach (q('SELECT * FROM ical_feeds WHERE room_id = ? AND source = ?', [$room['id'], $src])->fetchAll() as $old) { // un seul lien par site et par chambre : on remplace
                    q('DELETE FROM ext_blocks WHERE feed_id = ?', [$old['id']]); q('DELETE FROM ical_feeds WHERE id = ?', [$old['id']]);
                }
                q('INSERT INTO ical_feeds (room_id, source, url, created_at) VALUES (?,?,?,?)', [$room['id'], $src, $url, now()]);
                $f = q('SELECT * FROM ical_feeds WHERE room_id = ? ORDER BY id DESC LIMIT 1', [$room['id']])->fetch();
                ical_sync_feed($f);
                $n = (int)q('SELECT events FROM ical_feeds WHERE id = ?', [$f['id']])->fetchColumn();
                log_action('ical_ajout', $r['name'] . ' / ' . $room['name'] . ' / ' . $src);
                flash("$src relié pour « {$room['name']} » : $n période(s) déjà réservée(s) importée(s).");
            }
        }
    } elseif ($act === 'delete' && $feed) {
        q('DELETE FROM ext_blocks WHERE feed_id = ?', [$feed['id']]); q('DELETE FROM ical_feeds WHERE id = ?', [$feed['id']]);
        log_action('ical_retrait', $r['name'] . ' / feed #' . $feed['id']);
        flash('Lien retiré : ses dates ne sont plus bloquées sur StayInRiad.');
    } elseif ($act === 'sync' && $room) {
        $ok = 0; $all = room_feeds((int)$room['id']);
        foreach ($all as $f) $ok += ical_sync_feed($f) ? 1 : 0;
        flash($ok === count($all) ? 'Relu à l\'instant.' : 'Certains liens n\'ont pas pu être lus : voir le détail.', $ok === count($all) ? 'ok' : 'warn');
    } elseif ($act === 'newlink' && $room) {
        q("UPDATE rooms SET ical_token = '', seen_booking = NULL, seen_airbnb = NULL, seen_other = NULL WHERE id = ?", [$room['id']]);
        log_action('ical_nouveau_lien', $r['name'] . ' / ' . $room['name']);
        flash('Nouveau lien StayInRiad créé. L\'ancien ne marche plus : il faut installer le nouveau chez Booking/Airbnb.', 'warn');
    }
    redirect('sync.php' . $q);
}

$r = q('SELECT * FROM riads WHERE id = ?', [$rid])->fetch();
$rooms = q('SELECT * FROM rooms WHERE riad_id = ? AND active = 1 ORDER BY id', [$rid])->fetchAll();
$chs = riad_channels($r);
[$state, $todo] = riad_sync_summary($r);
$ago = function (?string $t): string {
    if (!$t) return 'jamais';
    $m = (int)round((time() - strtotime($t . ' UTC')) / 60);
    return $m < 1 ? 'à l\'instant' : ($m < 60 ? "il y a $m min" : ($m < 1440 ? 'il y a ' . intdiv($m, 60) . ' h' : 'il y a ' . intdiv($m, 1440) . ' j'));
};
$ok = fn($b, $yes, $no) => $b ? '<span style="color:var(--ok)">✓ ' . $yes . '</span>' : '<span style="color:var(--warn)">○ ' . $no . '</span>';
$copy = fn(string $val, string $id) => '<div class="copyrow"><input class="copy" id="' . $id . '" readonly value="' . e($val) . '"><button type="button" class="btn sm" data-copy="' . $id . '">Copier</button></div>';
$isOn = fn($c) => in_array($c, $chs, true);

$head = '<div class="card"><h2 style="margin-top:0">Synchroniser Booking.com et Airbnb</h2>
<p>Une chambre réservée sur StayInRiad se ferme chez Booking/Airbnb, et inversement. Ensuite, vous n\'avez plus rien à faire.</p>'
 . ($state === 'complet' ? '<div class="flash ok" style="margin:0">✅ Tout est synchronisé.</div>'
   : ($state === 'à faire' ? '<p><b>Reste à faire :</b></p><ul>' . implode('', array_map(fn($t) => '<li>' . e($t) . '</li>', array_slice($todo, 0, 8))) . '</ul>' : '')) . '</div>';

// 1) Sur quels sites est le riad ?
$ask = '<div class="card"><h2 style="margin-top:0">Votre riad est-il aussi sur…</h2><form method="post">' . csrf_field() . '
  <label class="check"><input type="checkbox" name="ch[]" value="booking"' . ($isOn('booking') ? ' checked' : '') . '> Booking.com</label>
  <label class="check"><input type="checkbox" name="ch[]" value="airbnb"' . ($isOn('airbnb') ? ' checked' : '') . '> Airbnb</label>
  <p class="note">Ni l\'un ni l\'autre ? Ne cochez rien et validez.</p>
  <button class="btn" name="action" value="channels">Valider</button></form></div>';

// 2) Booking.com : on s'en occupe pour lui (il invite notre équipe comme utilisateur de son extranet)
$booking = !$isOn('booking') ? '' : '<div class="card"><h2 style="margin-top:0">Booking.com</h2>'
 . ($r['sync_booking_invite'] ? '<div class="flash ok">Invitation signalée ' . e($ago($r['sync_booking_invite'])) . ' : notre équipe relie vos chambres, en général sous 24 h.</div>' : '') . '
<p><b>Le plus simple : laissez StayInRiad s\'en occuper.</b> Invitez notre équipe dans votre extranet Booking.com. Aucun mot de passe à nous donner, et vous pouvez nous retirer quand vous voulez.</p>
<a class="btn" href="https://admin.booking.com/" target="_blank" rel="noopener">Synchroniser avec Booking.com ↗</a>
<ol style="line-height:1.7">
<li>Dans l\'extranet, touchez l\'<b>icône du compte</b> (en haut à droite) → <b>Créer et gérer les utilisateurs</b> → <b>Ajouter un utilisateur</b>.</li>
<li>Nom : <b>StayInRiad</b> · Email :' . $copy(SYNC_TEAM_EMAIL, 'cp-team') . '</li>
<li>Droits : seulement <b>les tarifs et disponibilités</b> (pas les finances), puis <b>Envoyer l\'invitation</b>. Booking peut vous envoyer un code par SMS pour confirmer.</li>
</ol>
<form method="post">' . csrf_field() . '<button class="btn" name="action" value="booking_invited">C\'est fait, j\'ai invité StayInRiad</button></form>
<details style="margin-top:10px"><summary>Je préfère le faire moi-même</summary><p class="note">Pour chaque chambre ci-dessous : extranet → <b>Tarifs et disponibilités</b> → <b>Synchroniser les calendriers</b>. « Importer » : collez le lien StayInRiad de la chambre. « Exporter » : copiez le lien Booking et collez-le dans la case Booking de la chambre.</p></details></div>';

// 3) Airbnb : appel guidé (Airbnb ne permet de déléguer le calendrier qu'avec un « accès complet »)
$airbnb = !$isOn('airbnb') ? '' : '<div class="card"><h2 style="margin-top:0">Airbnb</h2>'
 . ($r['sync_airbnb_call'] ? '<div class="flash ok">Appel demandé ' . e($ago($r['sync_airbnb_call'])) . ' : nous vous contactons.</div>' : '') . '
<p><b>Faisons-le ensemble en 5 minutes</b>, par téléphone ou WhatsApp avec partage d\'écran :</p>
<form method="post" class="row" style="align-items:end">' . csrf_field() . '<div><label>Quand vous appeler ?</label><input name="when" maxlength="120" placeholder="ex. demain matin, ou après 17 h"></div><div style="flex:0 0 auto"><button class="btn" name="action" value="airbnb_call">Demander l\'appel</button></div></form>
<p style="margin-top:14px">Ou seul, chambre par chambre (2 minutes) :</p>
<a class="btn ghost" href="https://www.airbnb.fr/multicalendar" target="_blank" rel="noopener">Synchroniser avec Airbnb ↗</a>
<ol style="line-height:1.7">
<li>Ouvrez l\'annonce de la chambre → <b>Disponibilités</b> → <b>Synchroniser les calendriers</b>.</li>
<li><b>Importer un calendrier</b> : collez le lien StayInRiad de la chambre (ci-dessous), nom : StayInRiad.</li>
<li><b>Exporter le calendrier</b> : copiez le lien Airbnb et collez-le dans la case Airbnb de la chambre, ci-dessous.</li>
</ol></div>';

// 4) Chambres : une case par site, notre lien à copier, état vérifié automatiquement
$roomCards = !$chs ? '' : implode('', array_map(function ($x) use ($chs, $ago, $ok, $copy, $q) {
    if ((int)$x['qty'] !== 1) return '<div class="card"><h2 style="margin-top:0">' . e($x['name']) . '</h2><p class="note">Cette ligne regroupe <b>' . (int)$x['qty'] . ' chambres identiques</b>. Booking.com et Airbnb synchronisent chambre par chambre : créez une ligne par chambre (nombre = 1) dans <a href="rooms.php' . $q . '">Chambres &amp; prix</a>.</p></div>';
    $hid = csrf_field() . '<input type="hidden" name="room_id" value="' . (int)$x['id'] . '">';
    $feeds = room_feeds((int)$x['id']);
    $blocks = '';
    foreach ($chs as $ch) {
        $label = SYNC_CHANNELS[$ch];
        $f = current(array_filter($feeds, fn($z) => $z['source'] === $label)) ?: null;
        [, $read] = room_sync_state($x, $ch);
        $blocks .= '<div class="chan"><h3>' . $label . '</h3><p class="note">'
        . $ok($read, $label . ' lit notre calendrier (' . e($ago($x['seen_' . $ch])) . ')', 'lien StayInRiad pas encore installé chez ' . $label) . '<br>'
        . ($f ? ($f['last_error'] === '' && $f['last_ok'] ? $ok(true, 'nous lisons ' . $label . ' (' . e($ago($f['last_ok'])) . ') · ' . (int)$f['events'] . ' période(s) réservée(s)', '') : '<span style="color:var(--bad)">✕ ' . e($f['last_error'] ?: 'pas encore lu') . '</span>') : $ok(false, '', 'lien ' . $label . ' à coller ci-dessous')) . '</p>'
        . ($f ? '<div class="feed"><div class="note">' . e(mb_strimwidth($f['url'], 0, 64, '…')) . '</div><form method="post">' . csrf_field() . '<input type="hidden" name="feed_id" value="' . (int)$f['id'] . '"><button class="btn sm ghost" name="action" value="delete">Retirer</button></form></div>' : '')
        . '<form method="post" class="copyrow">' . $hid . '<input type="hidden" name="source" value="' . $label . '"><input name="url" type="url" required maxlength="600" placeholder="Lien ' . $label . ' (Exporter le calendrier)"><button class="btn sm" name="action" value="add">' . ($f ? 'Remplacer' : 'Relier') . '</button></form></div>';
    }
    return '<div class="card"><h2 style="margin-top:0">' . e($x['name']) . '</h2>
    <label>Lien StayInRiad de cette chambre (à importer chez Booking/Airbnb)</label>' . $copy(ical_export_url($x), 'cp-' . (int)$x['id']) . $blocks . '
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">' . ($feeds ? '<form method="post">' . $hid . '<button class="btn sm ghost" name="action" value="sync">Relire maintenant</button></form>' : '') . '
    <form method="post">' . $hid . '<button class="btn sm ghost" name="action" value="newlink">Nouveau lien StayInRiad</button></form></div></div>';
}, $rooms));

render_page('Booking / Airbnb', '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>' . riad_tabs('sync', $q)
 . $head . $ask . $booking . $airbnb
 . ($chs ? ($rooms ? '<h2>Vos chambres</h2>' . $roomCards : '<p class="note">Ajoutez d\'abord vos chambres dans « Chambres &amp; prix ».</p>') : '')
 . ($chs ? '<p class="note">StayInRiad relit vos calendriers Booking/Airbnb toutes les 15 minutes, et en direct juste avant chaque paiement. Booking et Airbnb relisent le nôtre à leur rythme (parfois plusieurs heures) : en cas de double réservation, écrivez-nous aussitôt à contact@stayinriad.com. Si un lien cesse de fonctionner, vous recevez un email.</p>' : '')
 . '<script src="assets/copy.js" defer></script>');
