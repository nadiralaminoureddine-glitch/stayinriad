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
      <div class="kpi"><small>Voyageurs</small><b>' . $n("SELECT COUNT(*) FROM users WHERE role = 'traveler'") . '</b></div>
    </div><div class="card"><h2 style="margin-top:0">Administration</h2><p>Gérez les comptes et les riads inscrits.</p><a class="btn" href="admin.php">Voir les comptes</a></div>';
} elseif ($u['role'] === 'riad') {
    $riads = q('SELECT * FROM riads WHERE owner_id = ? ORDER BY id', [$u['id']])->fetchAll();
    $list = implode('', array_map(fn($r) => '<tr><td><b>' . e($r['name']) . '</b></td><td>' . e($r['city']) . '</td><td>' . (int)$r['rooms_count'] . '</td><td><span class="pill ' . ($r['status'] === 'en ligne' ? 'ok' : 'warn') . '">' . e($r['status'] === 'en ligne' ? 'En ligne' : 'En préparation') . '</span></td></tr>', $riads));
    $body = $hello . '<div class="card"><h2 style="margin-top:0">Mon riad</h2><div class="scroll"><table><thead><tr><th>Riad</th><th>Ville</th><th>Chambres</th><th>Statut</th></tr></thead><tbody>' . $list . '</tbody></table></div>
    <p class="note" style="margin-top:12px">Prochaine étape : notre équipe vous contacte pour compléter votre fiche (photos, chambres, prix, informations de la société et RIB). Ces écrans arrivent dans la prochaine version de votre espace.</p></div>';
} else {
    $body = $hello . '<div class="card"><h2 style="margin-top:0">Mes réservations</h2><p class="note">Vous n\'avez pas encore de réservation. Les réservations ouvriront bientôt : nous vous préviendrons par email.</p></div>';
}
$body .= '<div class="card"><h2 style="margin-top:0">Mon compte</h2><p>' . e($u['first_name'] . ' ' . $u['last_name']) . '<br>' . e($u['email']) . ($u['phone'] ? '<br>' . e($u['phone']) : '') . '</p></div>';
render_page('Mon espace', $body);
