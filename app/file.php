<?php
// Affiche un fichier privé (photo originale ou attestation de RIB) au riad concerné ou à l'admin uniquement
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';

$u = require_login();
$file = null; $riadId = null; $download = false;
if (isset($_GET['photo'])) {
    $p = q('SELECT riad_id, file FROM photos WHERE id = ?', [(int)$_GET['photo']])->fetch();
    if ($p) { $file = $p['file']; $riadId = (int)$p['riad_id']; }
} elseif (isset($_GET['rib'])) {
    $r = q('SELECT id, bank_doc FROM riads WHERE id = ?', [(int)$_GET['rib']])->fetch();
    if ($r && $r['bank_doc']) { $file = $r['bank_doc']; $riadId = (int)$r['id']; $download = true; }
}
$allowed = $file && ($u['role'] === 'admin' || ($u['role'] === 'riad' && q('SELECT 1 FROM riads WHERE id = ? AND owner_id = ?', [$riadId, $u['id']])->fetch()));
$path = $file ? private_dir() . '/' . basename($file) : '';
if (!$allowed || !is_file($path)) { http_response_code(404); exit('Fichier introuvable.'); }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . (['pdf' => 'application/pdf', 'png' => 'image/png'][$ext] ?? 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=600');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . ($download ? 'attestation-rib' : 'photo') . '.' . $ext . '"');
readfile($path);
