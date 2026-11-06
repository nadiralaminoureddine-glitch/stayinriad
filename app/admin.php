<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

require_login('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['site_open'])) {
    set_site_open($_POST['site_open'] === '1'); log_action($_POST['site_open'] === '1' ? 'site_ouvert' : 'site_fermé');
    flash($_POST['site_open'] === '1' ? 'Le site est OUVERT : les voyageurs peuvent chercher et réserver.' : 'Le site est FERMÉ : seuls vous et les riads utilisez l\'app.');
    redirect('admin.php');
}
$openBox = '<div class="card" style="border-color:' . (site_open() ? 'var(--ok)' : 'var(--gold)') . '"><h2 style="margin-top:0">' . (site_open() ? '🟢 Site ouvert aux voyageurs' : '🔒 Site fermé (pré-lancement)') . '</h2><p class="note">' . (site_open() ? 'Tout le monde peut chercher des riads, créer un compte voyageur et réserver.' : 'Les visiteurs ne voient pas les riads et ne peuvent pas réserver. Seuls les riads peuvent s\'inscrire. Vous, en admin, voyez tout pour tester.') . '</p><form method="post">' . csrf_field() . '<button class="btn ' . (site_open() ? 'ghost' : '') . '" name="site_open" value="' . (site_open() ? '0' : '1') . '">' . (site_open() ? 'Fermer le site' : 'Ouvrir le site aux voyageurs') . '</button></form></div>';

$riads = q("SELECT r.*, u.first_name, u.last_name, u.email, u.phone FROM riads r JOIN users u ON u.id = r.owner_id ORDER BY CASE r.status WHEN 'soumis' THEN 0 WHEN 'refusé' THEN 1 WHEN 'brouillon' THEN 2 ELSE 3 END, r.id DESC")->fetchAll();
$users = q('SELECT id, role, email, first_name, last_name, phone, created_at, last_login FROM users ORDER BY id DESC LIMIT 200')->fetchAll();
$d = fn($s) => $s ? e(date('d/m/Y H:i', strtotime($s . ' UTC'))) : '—';

$rRows = implode('', array_map(fn($r) => '<tr><td><a href="admin_riad.php?id=' . (int)$r['id'] . '"><b>' . e($r['name']) . '</b></a><br><span class="note">' . e($r['city']) . ' · ' . (int)$r['rooms_count'] . ' ch.</span></td><td>' . e($r['first_name'] . ' ' . $r['last_name']) . '<br><span class="note">' . e($r['email']) . '<br>' . e($r['phone']) . '</span></td><td>' . e(rtrim(rtrim(number_format((float)$r['commission'], 2, '.', ''), '0'), '.')) . ' %</td><td>' . status_pill($r['status']) . '<br>' . bank_pill($r) . (!empty($r['import_requested']) && empty($r['import_done']) ? '<br><a class="pill warn" href="admin_import.php?id=' . (int)$r['id'] . '">Import Booking à faire</a>' : '') . '</td><td>' . $d($r['created_at']) . '</td><td style="white-space:nowrap">' . ($r['status'] === 'en ligne' ? '<span class="pill ok">En ligne</span>' : '<form method="post" action="admin_riad.php?id=' . (int)$r['id'] . '" class="inline">' . csrf_field() . '<button class="btn sm" name="action" value="approve">✓ Approuver</button></form>') . ' <form method="post" action="admin_riad.php?id=' . (int)$r['id'] . '" class="inline">' . csrf_field() . '<button class="btn sm ghost" name="action" value="delete">🗑 Supprimer</button></form></td></tr>', $riads));
$uRows = implode('', array_map(fn($u) => '<tr><td>' . e($u['first_name'] . ' ' . $u['last_name']) . '</td><td>' . e($u['email']) . '</td><td><span class="pill info">' . e(ROLES[$u['role']] ?? $u['role']) . '</span></td><td>' . $d($u['created_at']) . '</td><td>' . $d($u['last_login']) . '</td></tr>', $users));

$logs = q('SELECT l.*, u.email FROM admin_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 30')->fetchAll();
$logBox = '<div class="card"><h2 style="margin-top:0">🛡️ Journal des actions sensibles</h2>' . ($logs ? '<div class="scroll"><table><thead><tr><th>Date</th><th>Qui</th><th>Action</th><th>Détail</th><th>IP</th></tr></thead><tbody>' . implode('', array_map(fn($l) => '<tr><td style="white-space:nowrap">' . e(date('d/m H:i', strtotime($l['created_at'] . ' UTC'))) . '</td><td>' . e($l['email'] ?? '—') . '</td><td>' . e($l['action']) . '</td><td>' . e($l['details']) . '</td><td class="note">' . e($l['ip']) . '</td></tr>', $logs)) . '</tbody></table></div>' : '<p class="note">Aucune action pour l\'instant.</p>') . '</div>';
$mails = q('SELECT * FROM email_log ORDER BY id DESC LIMIT 15')->fetchAll();
$mailBox = '<div class="card"><h2 style="margin-top:0">✉️ Derniers emails envoyés</h2>' . ($mails ? '<div class="scroll"><table><thead><tr><th>Date</th><th>Destinataire</th><th>Objet</th><th>État</th></tr></thead><tbody>' . implode('', array_map(fn($m) => '<tr><td style="white-space:nowrap">' . e(date('d/m H:i', strtotime($m['created_at'] . ' UTC'))) . '</td><td>' . e($m['to_addr']) . '</td><td>' . e($m['subject']) . '</td><td><span class="pill ' . ($m['status'] === 'échec' ? 'bad' : 'ok') . '">' . e($m['status']) . '</span></td></tr>', $mails)) . '</tbody></table></div>' : '<p class="note">Aucun email pour l\'instant.</p>') . '</div>';
render_page('Comptes', '<h1>Administration</h1>' . $openBox . '
<div class="card"><h2 style="margin-top:0">Riads inscrits (' . count($riads) . ')</h2>' . ($riads ? '<div class="scroll"><table><thead><tr><th>Riad</th><th>Propriétaire</th><th>Commission</th><th>Statut</th><th>Inscrit le</th><th>Actions</th></tr></thead><tbody>' . $rRows . '</tbody></table></div>' : '<p class="note">Aucun riad inscrit pour l\'instant.</p>') . '</div>
' . $mailBox . $logBox . '<div class="card"><h2 style="margin-top:0">Tous les comptes (' . count($users) . ')</h2><div class="scroll"><table><thead><tr><th>Nom</th><th>Email</th><th>Type</th><th>Créé le</th><th>Dernière connexion</th></tr></thead><tbody>' . $uRows . '</tbody></table></div></div>');
