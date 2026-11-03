<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$u = require_login();
$hello = '<h1>Bonjour ' . e($u['first_name']) . '</h1>';

if ($u['role'] === 'admin') {
    $n = fn($sql, $p = []) => (int)q($sql, $p)->fetchColumn();
    $body = $hello . '<div class="kpis">
      <div class="kpi"><small>Riads inscrits</small><b>' . $n('SELECT COUNT(*) FROM riads') . '</b></div>
      <div class="kpi"><small>Riads en ligne</small><b>' . $n("SELECT COUNT(*) FROM riads WHERE status = 'en ligne'") . '</b></div>
      <div class="kpi"><small>À vérifier</small><b>' . $n("SELECT COUNT(*) FROM riads WHERE status = 'soumis'") . '</b></div>
      <div class="kpi"><small>Voyageurs</small><b>' . $n("SELECT COUNT(*) FROM users WHERE role = 'traveler'") . '</b></div>
    </div><div class="card"><h2 style="margin-top:0">Administration</h2><p>Gérez les comptes, les riads inscrits et les réservations.</p><a class="btn" href="admin.php">Voir les comptes</a> <a class="btn ghost" href="bookings.php">Réservations</a> <a class="btn ghost" href="riads.php">Voir le site</a></div>';
} elseif ($u['role'] === 'riad') {
    require __DIR__ . '/inc/riad.php';
    $r = my_riad($u);
    if (!$r) { $body = $hello . '<div class="card"><p>Aucun riad lié à ce compte. Écrivez-nous à contact@stayinriad.com.</p></div>'; }
    else {
        $miss = dossier_missing($r);
        $body = $hello . '<div class="card"><h2 style="margin-top:0">' . e($r['name']) . ' ' . status_pill($r['status']) . '</h2>'
          . ($r['status'] === 'refusé' && $r['admin_note'] ? '<div class="flash bad">✏️ À corriger : ' . e($r['admin_note']) . '</div>' : '')
          . ($r['status'] === 'en ligne' ? '<p>✅ Votre riad est en ligne.</p>' : ($r['status'] === 'soumis' ? '<p>⏳ Votre fiche est en cours de vérification par notre équipe.</p>' :
             ($miss ? '<p>Pour mettre votre riad en ligne, complétez :</p><ul>' . implode('', array_map(fn($m) => '<li>' . e($m) . '</li>', $miss)) . '</ul>' : '<p>Votre fiche est complète : envoyez-la pour validation depuis la page « Fiche du riad ».</p>')))
          . '<a class="btn" href="bookings.php">Réservations</a> <a class="btn ghost" href="calendar.php">Calendrier</a> <a class="btn ghost" href="riad.php">Fiche du riad</a> <a class="btn ghost" href="rooms.php">Chambres & prix</a> <a class="btn ghost" href="photos.php">Photos</a>'
          . '<p class="note" style="margin-top:14px">Besoin d\'aide ? Notre équipe peut remplir la fiche avec vous : <a href="mailto:contact@stayinriad.com">contact@stayinriad.com</a></p></div>';
    }
} else {
    $body = $hello . '<div class="card"><h2 style="margin-top:0">Mes réservations</h2><p>Retrouvez vos séjours, à venir et passés.</p><a class="btn" href="bookings.php">Voir mes réservations</a> <a class="btn ghost" href="riads.php">Trouver un riad</a></div>';
}
$body .= '<div class="card"><h2 style="margin-top:0">Mon compte</h2><p>' . e($u['first_name'] . ' ' . $u['last_name']) . '<br>' . e($u['email']) . ($u['phone'] ? '<br>' . e($u['phone']) : '') . '</p></div>';
render_page('Mon espace', $body);
