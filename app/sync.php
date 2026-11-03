<?php
// Espace riad — synchronisation des calendriers avec Booking.com, Airbnb…
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $room = q('SELECT * FROM rooms WHERE id = ? AND riad_id = ? AND active = 1', [(int)($_POST['room_id'] ?? 0), $rid])->fetch();
    $feed = isset($_POST['feed_id']) ? q('SELECT f.* FROM ical_feeds f JOIN rooms r ON r.id = f.room_id WHERE f.id = ? AND r.riad_id = ?', [(int)$_POST['feed_id'], $rid])->fetch() : null;
    if ($act === 'add' && $room) {
        $src = in_array($_POST['source'] ?? '', ICAL_SOURCES, true) ? $_POST['source'] : 'Autre';
        $url = trim((string)($_POST['url'] ?? ''));
        if ((int)$room['qty'] !== 1) flash('La synchronisation se fait chambre par chambre : mettez « Nombre de chambres identiques » à 1 (une ligne par chambre) dans « Chambres & prix ».', 'bad');
        elseif (strlen($url) > 600 || $url === '') flash('Collez le lien iCal complet.', 'bad');
        elseif (str_contains($url, 'stayinriad.com')) flash('Ce lien est celui de StayInRiad : il faut le coller dans Booking/Airbnb, pas ici. Ici, collez le lien que Booking/Airbnb vous donne.', 'bad');
        elseif ((int)q('SELECT COUNT(*) FROM ical_feeds WHERE room_id = ?', [$room['id']])->fetchColumn() >= 5) flash('5 calendriers maximum par chambre.', 'bad');
        else {
            [$text, $err] = ical_fetch($url);
            if ($err) flash('Lien non enregistré : ' . $err, 'bad');
            else {
                q('INSERT INTO ical_feeds (room_id, source, url, created_at) VALUES (?,?,?,?)', [$room['id'], $src, $url, now()]);
                $f = q('SELECT * FROM ical_feeds WHERE room_id = ? ORDER BY id DESC LIMIT 1', [$room['id']])->fetch();
                ical_sync_feed($f);
                $n = (int)q('SELECT events FROM ical_feeds WHERE id = ?', [$f['id']])->fetchColumn();
                log_action('ical_ajout', $r['name'] . ' / ' . $room['name'] . ' / ' . $src);
                flash("Calendrier $src relié : $n réservation(s) ou blocage(s) à venir importé(s).");
            }
        }
    } elseif ($act === 'delete' && $feed) {
        q('DELETE FROM ext_blocks WHERE feed_id = ?', [$feed['id']]); q('DELETE FROM ical_feeds WHERE id = ?', [$feed['id']]);
        log_action('ical_retrait', $r['name'] . ' / feed #' . $feed['id']);
        flash('Calendrier retiré : ses dates ne sont plus bloquées sur StayInRiad.');
    } elseif ($act === 'sync' && $room) {
        $ok = 0; $all = room_feeds((int)$room['id']);
        foreach ($all as $f) $ok += ical_sync_feed($f) ? 1 : 0;
        flash($ok === count($all) ? 'Calendriers relus à l\'instant.' : 'Certains calendriers n\'ont pas pu être lus : voir le détail ci-dessous.', $ok === count($all) ? 'ok' : 'warn');
    } elseif ($act === 'newlink' && $room) {
        q("UPDATE rooms SET ical_token = '' WHERE id = ?", [$room['id']]);
        log_action('ical_nouveau_lien', $r['name'] . ' / ' . $room['name']);
        flash('Nouveau lien créé. L\'ancien ne fonctionne plus : collez le nouveau dans Booking.com et Airbnb.', 'warn');
    }
    redirect('sync.php' . $q);
}

$rooms = q('SELECT * FROM rooms WHERE riad_id = ? AND active = 1 ORDER BY id', [$rid])->fetchAll();
$ago = function (?string $t): string {
    if (!$t) return 'jamais';
    $m = (int)round((time() - strtotime($t . ' UTC')) / 60);
    return $m < 1 ? 'à l\'instant' : ($m < 60 ? "il y a $m min" : ($m < 1440 ? 'il y a ' . intdiv($m, 60) . ' h' : 'il y a ' . intdiv($m, 1440) . ' j'));
};
$opts = implode('', array_map(fn($s) => '<option>' . e($s) . '</option>', ICAL_SOURCES));

$cards = implode('', array_map(function ($x) use ($ago, $opts, $q) {
    $hid = csrf_field() . '<input type="hidden" name="room_id" value="' . (int)$x['id'] . '">';
    if ((int)$x['qty'] !== 1) {
        return '<div class="card"><h2>' . e($x['name']) . '</h2><p class="note">Cette ligne regroupe <b>' . (int)$x['qty'] . ' chambres identiques</b>. Booking.com et Airbnb synchronisent un calendrier <b>par chambre</b> : pour relier ces chambres, créez une ligne par chambre (nombre = 1) dans <a href="rooms.php' . $q . '">Chambres &amp; prix</a>.</p></div>';
    }
    $feeds = room_feeds((int)$x['id']);
    $list = implode('', array_map(fn($f) => '<div class="feed"><div><b>' . e($f['source']) . '</b> · '
        . ($f['last_error'] === '' && $f['last_ok']
            ? '<span style="color:var(--ok)">✓ relu ' . e($ago($f['last_ok'])) . '</span> · ' . (int)$f['events'] . ' période(s) bloquée(s)'
            : '<span style="color:var(--bad)">✕ ' . e($f['last_error'] ?: 'pas encore lu') . '</span>' . ($f['last_ok'] ? ' · dernière lecture réussie ' . e($ago($f['last_ok'])) : ''))
        . '<br><span class="note">' . e(mb_strimwidth($f['url'], 0, 70, '…')) . '</span></div>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="feed_id" value="' . (int)$f['id'] . '"><button class="btn sm ghost" name="action" value="delete">Retirer</button></form></div>', $feeds));
    return '<div class="card"><h2>' . e($x['name']) . '</h2>
    <h3>1. Lien StayInRiad à coller dans Booking.com / Airbnb</h3>
    <input class="copy" readonly value="' . e(ical_export_url($x)) . '">
    <p class="note">Ce lien contient seulement les réservations StayInRiad et les jours que vous fermez. Il est privé : ne le partagez pas.</p>
    <form method="post" style="margin:0">' . $hid . '<button class="btn sm ghost" name="action" value="newlink">Créer un nouveau lien</button></form>
    <h3>2. Liens Booking.com / Airbnb à coller ici</h3>'
    . ($list ? '<div>' . $list . '</div><form method="post">' . $hid . '<button class="btn sm" name="action" value="sync">Relire maintenant</button></form>' : '<p class="note">Aucun calendrier relié pour l\'instant.</p>') . '
    <form method="post" class="row" style="align-items:end">' . $hid . '
      <div style="flex:0 0 150px"><label>Site</label><select name="source">' . $opts . '</select></div>
      <div><label>Lien iCal (.ics)</label><input name="url" type="url" required maxlength="600" placeholder="https://…"></div>
      <div style="flex:0 0 auto"><button class="btn" name="action" value="add">Relier</button></div>
    </form></div>';
}, $rooms));

render_page('Synchronisation', '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>' . riad_tabs('sync', $q) . '
<div class="card"><h2>Synchroniser avec Booking.com et Airbnb</h2>
<p>Pour qu\'une chambre ne soit jamais vendue deux fois, reliez les calendriers dans les deux sens, chambre par chambre :</p>
<ol>
<li><b>StayInRiad → Booking/Airbnb</b> : copiez le lien StayInRiad de la chambre et collez-le dans
 <b>Booking.com</b> (Extranet → Tarifs et disponibilités → Synchroniser les calendriers → Importer un calendrier) et dans
 <b>Airbnb</b> (Annonce → Disponibilités → Synchroniser les calendriers → Importer un calendrier).</li>
<li><b>Booking/Airbnb → StayInRiad</b> : dans les mêmes menus, choisissez « Exporter le calendrier », copiez le lien et collez-le ci-dessous.</li>
</ol>
<p class="note">StayInRiad relit vos calendriers <b>toutes les 15 minutes</b>, et <b>en direct juste avant chaque paiement</b> : une chambre déjà prise sur Booking/Airbnb ne peut pas être payée ici.
Dans l\'autre sens, Booking.com et Airbnb relisent notre lien à leur propre rythme (parfois plusieurs heures) : en cas de double réservation, prévenez-nous aussitôt à contact@stayinriad.com.
Si un lien cesse de fonctionner, vous recevez un email.</p></div>'
 . ($rooms ? $cards : '<p class="note">Ajoutez d\'abord vos chambres dans « Chambres &amp; prix ».</p>'));
