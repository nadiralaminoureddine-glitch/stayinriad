<?php
// StayInRiad — version anglaise de l'espace riad.
// Les pages sont écrites en français ; quand la langue est « en », le HTML final est traduit texte par texte
// avec le dictionnaire inc/lang_en.php (phrases exactes + modèles avec variables). Ce que le riad saisit
// (valeurs des champs, zones de texte) n'est jamais traduit.
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const LANGS = ['fr' => 'FR', 'en' => 'EN'];

/** Langue de l'interface : ?lang=…, sinon le choix fait sur cet appareil (cookie), sinon celle du compte, sinon le français. */
function lang(): string {
    static $l = null;
    if ($l !== null) return $l;
    $want = $_GET['lang'] ?? null;
    if (is_string($want) && isset(LANGS[$want])) {
        $l = $want;
        if (PHP_SAPI !== 'cli' && !headers_sent()) setcookie('lang', $l, ['expires' => time() + 31536000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['lang'] = $l;
        if (function_exists('current_user') && ($u = current_user()) && ($u['lang'] ?? 'fr') !== $l) { try { q('UPDATE users SET lang = ? WHERE id = ?', [$l, $u['id']]); } catch (Throwable $e) {} }
        return $l;
    }
    $u = function_exists('current_user') ? current_user() : null;
    if (isset(LANGS[$_COOKIE['lang'] ?? ''])) {
        $l = $_COOKIE['lang'];
        if ($u && ($u['lang'] ?? 'fr') !== $l) { try { q('UPDATE users SET lang = ? WHERE id = ?', [$l, $u['id']]); } catch (Throwable $e) {} } // emails dans la même langue
        return $l;
    }
    if ($u && isset(LANGS[$u['lang'] ?? ''])) return $l = $u['lang'];
    if (!$u && !empty($_SESSION['pending_uid'])) { // code de connexion en cours : langue du compte
        try { $pl = q('SELECT lang FROM users WHERE id = ?', [(int)$_SESSION['pending_uid']])->fetchColumn(); if (isset(LANGS[(string)$pl])) return $l = (string)$pl; } catch (Throwable $e) {}
    }
    return $l = 'fr'; // français par défaut (riads marocains) ; l'anglais se choisit avec FR | EN ou un lien ?lang=en
}

/** Langue d'un destinataire d'email (compte existant), sinon français. */
function lang_of_email(string $email): string {
    try { $v = q('SELECT lang FROM users WHERE email = ?', [mb_strtolower(trim($email))])->fetchColumn(); } catch (Throwable $e) { $v = null; }
    return isset(LANGS[(string)$v]) ? (string)$v : 'fr';
}

function i18n_dict(): array {
    static $d = null;
    if ($d === null) $d = require __DIR__ . '/lang_en.php';
    return $d;
}

/** Traduit un texte brut (non échappé). Renvoie le texte tel quel s'il n'est pas dans le dictionnaire. */
function tr(string $s, ?string $lang = null): string {
    if (($lang ?? lang()) !== 'en') return $s;
    $core = trim(preg_replace('/\s+/u', ' ', $s));
    if ($core === '') return $s;
    $d = i18n_dict();
    if (isset($d['exact'][$core])) $out = $d['exact'][$core];
    else {
        $out = null;
        foreach ($d['re'] as $re => $rep) {
            if (preg_match($re, $core)) { $out = is_callable($rep) ? preg_replace_callback($re, $rep, $core) : preg_replace($re, $rep, $core); break; }
        }
        if ($out === null) return $s;
    }
    // garde les espaces autour (« Bonjour » + « <b>Sana</b> »)
    preg_match('/^\s*/u', $s, $a); preg_match('/\s*$/u', $s, $b);
    return $a[0] . $out . $b[0];
}

/** Traduit un document ou un fragment HTML. */
function tr_html(string $html, ?string $lang = null): string {
    if (($lang ?? lang()) !== 'en') return $html;
    $parts = preg_split('/(<!--.*?-->|<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $skip = null; $out = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        if ($p[0] === '<') {
            if ($skip) { if (preg_match('#^</' . $skip . '\b#i', $p)) $skip = null; $out .= $p; continue; }
            if (preg_match('#^<(textarea|script|style)\b#i', $p, $m)) $skip = strtolower($m[1]);
            if (str_contains($p, ' data-notr')) { $out .= $p; continue; }
            $p = preg_replace('/^<html lang="fr"/', '<html lang="en"', $p);
            $p = preg_replace_callback('/\s(placeholder|title|aria-label|alt)="([^"]*)"/', fn($m) => ' ' . $m[1] . '="' . htmlspecialchars(tr(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'en'), ENT_QUOTES, 'UTF-8', false) . '"', $p);
            $out .= $p;
            continue;
        }
        if ($skip || trim($p) === '' || !preg_match('/\p{L}/u', $p)) { $out .= $p; continue; }
        $raw = html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = tr($raw, 'en');
        $out .= $t === $raw ? $p : htmlspecialchars($t, ENT_NOQUOTES, 'UTF-8', false);
    }
    return $out;
}

/** Sélecteur FR | EN pour l'en-tête. */
function lang_switch(): string {
    $qs = $_GET; unset($qs['lang']); $cur = lang();
    return '<span class="cur lang">' . implode('', array_map(fn($k, $l) => '<a href="?' . e(http_build_query($qs + ['lang' => $k])) . '"' . ($k === $cur ? ' class="on"' : '') . ' data-notr>' . $l . '</a>', array_keys(LANGS), LANGS)) . '</span>';
}
