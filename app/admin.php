<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

require_login('admin');

$riads = q('SELECT r.*, u.first_name, u.last_name, u.email, u.phone FROM riads r JOIN users u ON u.id = r.owner_id ORDER BY r.id DESC')->fetchAll();
$users = q('SELECT id, role, email, first_name, last_name, phone, created_at, last_login FROM users ORDER BY id DESC LIMIT 200')->fetchAll();
$d = fn($s) => $s ? e(date('d/m/Y H:i', strtotime($s . ' UTC'))) : '—';

$rRows = implode('', array_map(fn($r) => '<tr><td><b>' . e($r['name']) . '</b><br><span class="note">' . e($r['city']) . ' · ' . (int)$r['rooms_count'] . ' ch.</span></td><td>' . e($r['first_name'] . ' ' . $r['last_name']) . '<br><span class="note">' . e($r['email']) . '<br>' . e($r['phone']) . '</span></td><td>' . e(rtrim(rtrim(number_format((float)$r['commission'], 2, '.', ''), '0'), '.')) . ' %</td><td><span class="pill ' . ($r['status'] === 'en ligne' ? 'ok' : 'warn') . '">' . e($r['status']) . '</span></td><td>' . $d($r['created_at']) . '</td></tr>', $riads));
$uRows = implode('', array_map(fn($u) => '<tr><td>' . e($u['first_name'] . ' ' . $u['last_name']) . '</td><td>' . e($u['email']) . '</td><td><span class="pill info">' . e(ROLES[$u['role']] ?? $u['role']) . '</span></td><td>' . $d($u['created_at']) . '</td><td>' . $d($u['last_login']) . '</td></tr>', $users));

render_page('Comptes', '<h1>Administration</h1>
<div class="card"><h2 style="margin-top:0">Riads inscrits (' . count($riads) . ')</h2>' . ($riads ? '<div class="scroll"><table><thead><tr><th>Riad</th><th>Propriétaire</th><th>Commission</th><th>Statut</th><th>Inscrit le</th></tr></thead><tbody>' . $rRows . '</tbody></table></div>' : '<p class="note">Aucun riad inscrit pour l\'instant.</p>') . '</div>
<div class="card"><h2 style="margin-top:0">Tous les comptes (' . count($users) . ')</h2><div class="scroll"><table><thead><tr><th>Nom</th><th>Email</th><th>Type</th><th>Créé le</th><th>Dernière connexion</th></tr></thead><tbody>' . $uRows . '</tbody></table></div></div>');
