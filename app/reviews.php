<?php
// Avis clients : riad (les siens) et admin (un riad, ou tous les avis récents)
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$u = require_login();
if (!in_array($u['role'], ['riad', 'admin'], true)) redirect('account.php');
$admin = $u['role'] === 'admin';
$fmt = fn($x) => number_format((float)$x, 1, ',', '');
$card = function (array $x, bool $admin) use ($fmt) {
    $c = REVIEW_CRITERIA;
    $detail = implode(' · ', array_filter(array_map(fn($k) => $x[$k] !== null ? e($c[$k]) . ' ' . (int)$x[$k] . '/5' : null, array_keys($c))));
    return '<div class="review"><div class="rhead"><span class="score">' . $fmt($x['score']) . '</span><div><b>' . e(score_word((float)$x['score'])) . '</b> · ' . e($x['first_name']) . ($x['country'] ? ' (' . e($x['country']) . ')' : '')
      . ($admin && !empty($x['riad_name']) ? ' · <a href="reviews.php?id=' . (int)$x['riad_id'] . '">' . e($x['riad_name']) . '</a>' : '')
      . '<br><span class="note">' . e($x['room_name']) . ' · séjour du ' . e(fr_date($x['check_in'])) . ' · ' . ($x['recommend'] ? 'recommande ce riad' : '<b style="color:var(--bad)">ne recommande pas</b>') . '</span></div></div>'
      . ($x['liked'] !== '' ? '<p>👍 ' . nl2br(e($x['liked'])) . '</p>' : '') . ($x['disliked'] !== '' ? '<p>👎 ' . nl2br(e($x['disliked'])) . '</p>' : '')
      . ($admin && $x['private_note'] !== '' ? '<p class="private">🔒 Message privé : ' . nl2br(e($x['private_note'])) . '</p>' : '')
      . ($admin ? '<p class="note">' . e($x['ref']) . ' · ' . e($x['email']) . ' · ' . e($x['phone']) . '</p>' : '')
      . '<p class="note">' . $detail . '</p></div>';
};
$cols = 'v.*, b.ref, b.first_name, b.country, b.room_name, b.check_in, b.email, b.phone';

if ($admin && empty($_GET['id'])) {
    $list = q("SELECT $cols, r.name AS riad_name FROM reviews v JOIN bookings b ON b.id = v.booking_id JOIN riads r ON r.id = v.riad_id ORDER BY v.created_at DESC LIMIT 200")->fetchAll();
    $sent = (int)q('SELECT COUNT(*) FROM bookings WHERE review_sent IS NOT NULL')->fetchColumn();
    $got = (int)q('SELECT COUNT(*) FROM reviews')->fetchColumn();
    render_page('Avis clients', '<p><a href="account.php">← Administration</a></p><h1>Avis clients</h1>
    <div class="kpis"><div class="kpi"><small>Fiches envoyées</small><b>' . $sent . '</b></div><div class="kpi"><small>Avis reçus</small><b>' . $got . '</b></div><div class="kpi"><small>Taux de réponse</small><b>' . ($sent ? round($got / $sent * 100) . ' %' : '–') . '</b></div><div class="kpi"><small>Note moyenne</small><b>' . ($got ? $fmt(q('SELECT AVG(score) FROM reviews')->fetchColumn()) : '–') . '</b></div></div>
    <div class="card">' . ($list ? implode('', array_map(fn($x) => $card($x, true), $list)) : '<p class="note">Aucun avis pour l\'instant. La fiche de satisfaction part automatiquement le jour du départ, à partir de midi.</p>') . '</div>');
    exit;
}

$r = my_riad($u, isset($_GET['id']) ? (int)$_GET['id'] : null);
if (!$r) redirect('account.php');
$q = $admin ? '?id=' . (int)$r['id'] : '';
$st = review_stats((int)$r['id']);
$list = q("SELECT $cols FROM reviews v JOIN bookings b ON b.id = v.booking_id WHERE v.riad_id = ? ORDER BY v.created_at DESC LIMIT 300", [$r['id']])->fetchAll();
$bars = implode('', array_map(fn($k) => '<div class="bar"><span>' . e(REVIEW_CRITERIA[$k]) . '</span><div class="track"><i style="width:' . (int)round($st['crit'][$k] / 5 * 100) . '%"></i></div><b>' . $fmt($st['crit'][$k]) . '</b></div>', array_keys($st['crit'])));

render_page('Avis clients', '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>' . riad_tabs('avis', $q)
 . ($st['n'] ? '<div class="card"><div class="rhead"><span class="score big">' . $fmt($st['score']) . '</span><div><b>' . e(score_word((float)$st['score'])) . '</b><br><span class="note">' . $st['n'] . ' avis · ' . $st['reco'] . ' % recommandent votre riad</span></div></div>' . $bars . '</div>'
   . '<div class="card"><h2 style="margin-top:0">Derniers avis</h2>' . implode('', array_map(fn($x) => $card($x, $admin), $list)) . '</div>'
   : '<div class="card"><p>Pas encore d\'avis.</p><p class="note">Après chaque séjour, le voyageur reçoit automatiquement une fiche de satisfaction par email (le jour du départ, à partir de midi). Les avis apparaîtront ici, avec vos notes par point : accueil, propreté, confort, emplacement, rapport qualité/prix et petit-déjeuner.</p></div>')
 . '<p class="note">Note sur 10 : moyenne des étoiles × 2. Les messages privés des voyageurs sont lus uniquement par l\'équipe StayInRiad.</p>');
