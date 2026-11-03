<?php
// StayInRiad — démarrage commun à toutes les pages
declare(strict_types=1);

if (!defined('SIR_APP')) define('SIR_APP', true);

// ---------- Configuration (fichier HORS de public_html) ----------
function sir_load_config(): array {
    $candidates = [
        getenv('SIR_CONFIG') ?: '',
        dirname(__DIR__, 3) . '/stayinriad-config.php', // /home/compte/stayinriad-config.php
        dirname(__DIR__, 2) . '/stayinriad-config.php',
    ];
    foreach ($candidates as $f) {
        if ($f && is_file($f)) { $c = require $f; if (is_array($c)) return $c; }
    }
    http_response_code(500);
    exit('Configuration introuvable : créez le fichier stayinriad-config.php (voir le guide).');
}
$CONFIG = sir_load_config();
date_default_timezone_set($CONFIG['timezone'] ?? 'Africa/Casablanca');

// ---------- Erreurs : jamais affichées au public ----------
ini_set('display_errors', !empty($CONFIG['debug']) ? '1' : '0');
error_reporting(E_ALL);

// ---------- En-têtes de sécurité ----------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
header('X-Robots-Tag: noindex, nofollow'); // espace privé : pas dans Google

// ---------- Session sécurisée ----------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('sir_sess');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => $https || empty($CONFIG['debug']),
    'httponly' => true, 'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();
// expiration après 2 h d'inactivité
if (isset($_SESSION['last']) && time() - $_SESSION['last'] > 7200) { $_SESSION = []; session_regenerate_id(true); }
$_SESSION['last'] = time();

// ---------- Base de données ----------
function db(): PDO {
    static $pdo = null; global $CONFIG;
    if ($pdo) return $pdo;
    $d = $CONFIG['db'];
    if (($d['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $d['path']);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'] ?? 'localhost', $d['name']);
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"]);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function now(): string { return gmdate('Y-m-d H:i:s'); }

// ---------- Petits outils ----------
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $to): void { header('Location: ' . $to); exit; }
function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

// ---------- Protection CSRF ----------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = $_POST['csrf'] ?? '';
        if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
            http_response_code(400); exit('Formulaire expiré. Revenez en arrière et réessayez.');
        }
    }
}
csrf_check();

require __DIR__ . '/migrate.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/layout.php';
