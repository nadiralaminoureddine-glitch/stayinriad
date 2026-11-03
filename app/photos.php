<?php
// Espace riad — photos (validées par StayInRiad puis publiées avec le logo)
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';

$u = require_login();
if (!in_array($u['role'], ['riad', 'admin'], true)) redirect('account.php');
$r = my_riad($u, isset($_GET['id']) ? (int)$_GET['id'] : null);
if (!$r) redirect('account.php');
$rid = (int)$r['id'];
$q = $u['role'] === 'admin' ? '?id=' . $rid : '';
const MAX_PHOTOS = 30;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'upload') {
        $count = (int)q("SELECT COUNT(*) FROM photos WHERE riad_id = ? AND status <> 'refusée'", [$rid])->fetchColumn();
        $roomId = (int)($_POST['room_id'] ?? 0);
        if ($roomId && !q('SELECT 1 FROM rooms WHERE id = ? AND riad_id = ?', [$roomId, $rid])->fetch()) $roomId = 0;
        $files = $_FILES['photos'] ?? null; $added = 0; $errs = [];
        if ($files && is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']) && $count + $added < MAX_PHOTOS; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                [$name, $hash, $e2] = save_photo(['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]]);
                if ($e2) { $errs[] = $files['name'][$i] . ' : ' . $e2; continue; }
                q('INSERT INTO photos (riad_id, room_id, file, hash, status, sort, created_at) VALUES (?,?,?,?,?,?,?)', [$rid, $roomId ?: null, $name, $hash, 'en attente', $count + $added, now()]);
                $added++;
            }
        }
        $dup = $added && photo_duplicates($rid);
        flash($added ? $added . ' photo(s) envoyée(s). Elles seront publiées avec le logo stayinriad.com après vérification par notre équipe.' . ($dup ? ' Une photo ressemble à celle d\'un autre établissement : elle sera vérifiée en priorité.' : '') . ($errs ? ' Problème : ' . implode(' ; ', $errs) : '') : ($errs ? implode(' ; ', $errs) : 'Aucune photo reçue.'), $added ? ($dup ? 'warn' : 'ok') : 'bad');
        redirect('photos.php' . $q);
    }
    if ($act === 'delete') {
        $ph = q('SELECT * FROM photos WHERE id = ? AND riad_id = ?', [(int)$_POST['photo_id'], $rid])->fetch();
        if ($ph) {
            @unlink(private_dir() . '/' . $ph['file']);
            if ($ph['public_file']) @unlink(public_photo_dir() . '/' . $ph['public_file']);
            q('DELETE FROM photos WHERE id = ?', [$ph['id']]);
            flash('Photo supprimée.');
        }
        redirect('photos.php' . $q);
    }
}

$rooms = q('SELECT id, name FROM rooms WHERE riad_id = ? AND active = 1 ORDER BY id', [$rid])->fetchAll();
$roomName = array_column($rooms, 'name', 'id');
$photos = q('SELECT * FROM photos WHERE riad_id = ? ORDER BY sort, id', [$rid])->fetchAll();
$pill = fn($s) => match ($s) { 'publiée' => '<span class="pill ok">✓ Publiée</span>', 'refusée' => '<span class="pill bad">Refusée</span>', default => '<span class="pill warn">En attente</span>' };
$grid = $photos ? '<div class="gallery">' . implode('', array_map(fn($p) => '<figure><img src="file.php?photo=' . (int)$p['id'] . '" alt="" loading="lazy"><figcaption>' . $pill($p['status']) . '<br><span class="note">' . e($p['room_id'] ? ($roomName[$p['room_id']] ?? 'Chambre') : 'Riad (général)') . '</span>
 <form method="post">' . csrf_field() . '<input type="hidden" name="photo_id" value="' . (int)$p['id'] . '"><button class="link" name="action" value="delete">Supprimer</button></form></figcaption></figure>', $photos)) . '</div>' : '<p class="note">Aucune photo pour l\'instant.</p>';

render_page('Photos', '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>' . riad_tabs('photos', $q) . '
<div class="card"><h2 style="margin-top:0">Ajouter des photos</h2>
<p class="note">Au moins <b>3 photos</b> : patio, chambres, terrasse… Photos horizontales et lumineuses de préférence. Chaque photo est vérifiée par notre équipe, puis publiée avec le petit logo <b>stayinriad.com</b>, qui protège vos photos contre les copies.</p>
<form method="post" enctype="multipart/form-data">' . csrf_field() . '
<label>Photos (JPG, PNG ou WebP · plusieurs à la fois)</label><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
<label>Pour</label><select name="room_id"><option value="0">Le riad en général (patio, terrasse, salon…)</option>' . implode('', array_map(fn($x) => '<option value="' . (int)$x['id'] . '">' . e($x['name']) . '</option>', $rooms)) . '</select>
<button class="btn" name="action" value="upload">Envoyer les photos</button></form></div>
<div class="card"><h2 style="margin-top:0">Vos photos (' . count($photos) . ')</h2>' . $grid . '</div>');
