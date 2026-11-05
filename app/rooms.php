<?php
// Espace riad — chambres et prix
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';

$u = require_login();
if (!in_array($u['role'], ['riad', 'admin'], true)) redirect('account.php');
$r = my_riad($u, isset($_GET['id']) ? (int)$_GET['id'] : null);
if (!$r) redirect('account.php');
$rid = (int)$r['id'];
$q = $u['role'] === 'admin' ? '?id=' . $rid : '';
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $roomId = (int)($_POST['room_id'] ?? 0);
    $own = $roomId ? q('SELECT id FROM rooms WHERE id = ? AND riad_id = ?', [$roomId, $rid])->fetch() : null;
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
    $cap = max(1, min(12, (int)($_POST['capacity'] ?? 2)));
    $qty = max(1, min(50, (int)($_POST['qty'] ?? 1)));
    $price = round((float)str_replace(',', '.', (string)($_POST['price'] ?? '0')), 2);
    if (in_array($act, ['add', 'save'], true) && ($name === '' || $price < 10 || $price > 5000)) {
        $err = 'Indiquez un nom de chambre et un prix par nuit entre 10 € et 5 000 €.';
    } elseif ($act === 'add') {
        q('INSERT INTO rooms (riad_id, name, description, capacity, qty, price_eur, active, created_at) VALUES (?,?,?,?,?,?,1,?)', [$rid, $name, $desc, $cap, $qty, $price, now()]);
        touch_riad($rid); flash('Chambre ajoutée.'); redirect('rooms.php' . $q);
    } elseif ($act === 'save' && $own) {
        q('UPDATE rooms SET name=?, description=?, capacity=?, qty=?, price_eur=? WHERE id=?', [$name, $desc, $cap, $qty, $price, $roomId]);
        touch_riad($rid); flash('Chambre enregistrée.'); redirect('rooms.php' . $q);
    } elseif ($act === 'delete' && $own) {
        q('UPDATE rooms SET active = 0 WHERE id = ?', [$roomId]);
        flash('Chambre retirée.'); redirect('rooms.php' . $q);
    }
}

$rooms = q('SELECT * FROM rooms WHERE riad_id = ? AND active = 1 ORDER BY id', [$rid])->fetchAll();
$form = function (?array $x) {
    $v = fn($k, $d = '') => e($x[$k] ?? $d);
    return '<form method="post" class="card">' . csrf_field() . ($x ? '<input type="hidden" name="room_id" value="' . (int)$x['id'] . '">' : '') . '
    <div class="row"><div><label>Nom de la chambre</label><input name="name" required maxlength="120" placeholder="ex. Suite Majorelle" value="' . $v('name') . '"></div>
    <div><label>Prix par nuit (€)</label><input name="price" required inputmode="decimal" placeholder="85" value="' . ($x && (float)$x['price_eur'] > 0 ? e(rtrim(rtrim(number_format((float)$x['price_eur'], 2, '.', ''), '0'), '.')) : '') . '"' . ($x && (float)$x['price_eur'] <= 0 ? ' style="border-color:var(--bad)"' : '') . '>' . ($x && (float)$x['price_eur'] <= 0 ? '<span class="note" style="color:var(--bad)">Prix à indiquer</span>' : '') . '</div></div>
    <label>Description courte</label><input name="description" maxlength="255" placeholder="ex. 1 grand lit · 20 m² · vue sur le patio" value="' . $v('description') . '">
    <div class="row"><div><label>Personnes maximum</label><input type="number" name="capacity" min="1" max="12" value="' . $v('capacity', '2') . '"></div>
    <div><label>Nombre de chambres identiques</label><input type="number" name="qty" min="1" max="50" value="' . $v('qty', '1') . '"></div></div>
    <button class="btn" name="action" value="' . ($x ? 'save' : 'add') . '">' . ($x ? 'Enregistrer' : 'Ajouter la chambre') . '</button>'
    . ($x ? ' <button class="btn ghost" name="action" value="delete" formnovalidate>Retirer</button>' : '') . '</form>';
};

render_page('Chambres & prix', '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>' . riad_tabs('rooms', $q)
 . '<p class="note">Les prix sont en <b>euros</b>, par chambre et par nuit, petit-déjeuner compris s\'il est inclus. La taxe de séjour se règle sur place.</p>'
 . ($err ? '<div class="flash bad">' . e($err) . '</div>' : '')
 . implode('', array_map($form, $rooms))
 . '<h2>Ajouter une chambre</h2>' . $form(null));
