<?php
// StayInRiad — réception des visites envoyées par assets/t.js (voir inc/stats.php)
declare(strict_types=1);
define('SIR_BEACON', true);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/stats.php';
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input', false, null, 0, 4096);
$in = json_decode((string)$raw, true);
try { if (is_array($in)) stats_record($in); } catch (Throwable $e) { /* ne jamais gêner le visiteur */ }
http_response_code(204);
