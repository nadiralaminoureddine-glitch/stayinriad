<?php
// StayInRiad — statistiques de visite (classement des sources, pays, appareils)
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const STATS_BOT_UA = '/bot|crawl|spider|slurp|headless|lighthouse|preview|facebookexternalhit|python|curl|wget|httpclient|java\/|scrapy|go-http|axios|node-fetch|phantom|selenium|puppeteer|playwright/i';

/** Fuseau horaire du navigateur → pays (code ISO). Suffit pour savoir d'où viennent les visiteurs, sans base GeoIP. */
const STATS_TZ_COUNTRY = [
    'Africa/Casablanca' => 'MA', 'Africa/El_Aaiun' => 'MA', 'Europe/Paris' => 'FR', 'Europe/Madrid' => 'ES', 'Atlantic/Canary' => 'ES',
    'Africa/Ceuta' => 'ES', 'Europe/London' => 'GB', 'Europe/Dublin' => 'IE', 'Europe/Brussels' => 'BE', 'Europe/Amsterdam' => 'NL',
    'Europe/Luxembourg' => 'LU', 'Europe/Zurich' => 'CH', 'Europe/Berlin' => 'DE', 'Europe/Rome' => 'IT', 'Europe/Lisbon' => 'PT',
    'Europe/Vienna' => 'AT', 'Europe/Stockholm' => 'SE', 'Europe/Oslo' => 'NO', 'Europe/Copenhagen' => 'DK', 'Europe/Helsinki' => 'FI',
    'Europe/Warsaw' => 'PL', 'Europe/Prague' => 'CZ', 'Europe/Budapest' => 'HU', 'Europe/Bucharest' => 'RO', 'Europe/Athens' => 'GR',
    'Europe/Istanbul' => 'TR', 'Europe/Kiev' => 'UA', 'Europe/Kyiv' => 'UA', 'Europe/Moscow' => 'RU', 'Europe/Vilnius' => 'LT',
    'Africa/Algiers' => 'DZ', 'Africa/Tunis' => 'TN', 'Africa/Cairo' => 'EG', 'Africa/Dakar' => 'SN', 'Africa/Abidjan' => 'CI',
    'Africa/Johannesburg' => 'ZA', 'Africa/Lagos' => 'NG', 'Asia/Dubai' => 'AE', 'Asia/Riyadh' => 'SA', 'Asia/Qatar' => 'QA',
    'Asia/Tokyo' => 'JP', 'Asia/Shanghai' => 'CN', 'Asia/Hong_Kong' => 'HK', 'Asia/Singapore' => 'SG', 'Asia/Kolkata' => 'IN',
    'Asia/Calcutta' => 'IN', 'Asia/Jakarta' => 'ID', 'Asia/Seoul' => 'KR', 'Australia/Sydney' => 'AU', 'Australia/Melbourne' => 'AU',
    'America/Toronto' => 'CA', 'America/Montreal' => 'CA', 'America/Vancouver' => 'CA', 'America/Sao_Paulo' => 'BR', 'America/Mexico_City' => 'MX',
    'America/Argentina/Buenos_Aires' => 'AR', 'America/Bogota' => 'CO',
];
const STATS_COUNTRY_NAME = [
    'MA' => 'Maroc', 'FR' => 'France', 'ES' => 'Espagne', 'GB' => 'Royaume-Uni', 'US' => 'États-Unis', 'IE' => 'Irlande', 'BE' => 'Belgique',
    'NL' => 'Pays-Bas', 'LU' => 'Luxembourg', 'CH' => 'Suisse', 'DE' => 'Allemagne', 'IT' => 'Italie', 'PT' => 'Portugal', 'AT' => 'Autriche',
    'SE' => 'Suède', 'NO' => 'Norvège', 'DK' => 'Danemark', 'FI' => 'Finlande', 'PL' => 'Pologne', 'CZ' => 'Tchéquie', 'HU' => 'Hongrie',
    'RO' => 'Roumanie', 'GR' => 'Grèce', 'TR' => 'Turquie', 'UA' => 'Ukraine', 'RU' => 'Russie', 'LT' => 'Lituanie', 'DZ' => 'Algérie',
    'TN' => 'Tunisie', 'EG' => 'Égypte', 'SN' => 'Sénégal', 'CI' => 'Côte d\'Ivoire', 'ZA' => 'Afrique du Sud', 'NG' => 'Nigeria',
    'AE' => 'Émirats', 'SA' => 'Arabie saoudite', 'QA' => 'Qatar', 'JP' => 'Japon', 'CN' => 'Chine', 'HK' => 'Hong Kong', 'SG' => 'Singapour',
    'IN' => 'Inde', 'ID' => 'Indonésie', 'KR' => 'Corée du Sud', 'AU' => 'Australie', 'CA' => 'Canada', 'BR' => 'Brésil', 'MX' => 'Mexique',
    'AR' => 'Argentine', 'CO' => 'Colombie',
];

function stats_country(string $tz): string {
    if (isset(STATS_TZ_COUNTRY[$tz])) return STATS_TZ_COUNTRY[$tz];
    if (str_starts_with($tz, 'America/') || str_starts_with($tz, 'US/') || $tz === 'Pacific/Honolulu') return 'US';
    return '';
}

/** Source d'une visite : paramètre utm_source, sinon le site d'où vient le visiteur. '' = lien direct. */
function stats_source(string $ref, string $utm, string $host): string {
    $utm = strtolower(trim($utm));
    $map = ['instagram' => 'Instagram', 'ig' => 'Instagram', 'facebook' => 'Facebook', 'fb' => 'Facebook', 'whatsapp' => 'WhatsApp',
            'wa' => 'WhatsApp', 'email' => 'Email', 'mail' => 'Email', 'google' => 'Google', 'tiktok' => 'TikTok', 'booking' => 'Booking.com'];
    if ($utm !== '') return $map[$utm] ?? mb_substr(ucfirst($utm), 0, 30);
    $h = strtolower((string)parse_url($ref, PHP_URL_HOST));
    if ($h === '') return '';
    $h = preg_replace('/^www\./', '', $h);
    if ($h === strtolower(preg_replace('/^www\./', '', $host))) return '=';  // page suivante sur le site
    $rules = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'fb.' => 'Facebook', 'google.' => 'Google', 'bing.' => 'Bing',
              'booking.com' => 'Booking.com', 'whatsapp' => 'WhatsApp', 'wa.me' => 'WhatsApp', 'wl.co' => 'WhatsApp', 'tiktok' => 'TikTok',
              'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 't.co' => 'X (Twitter)', 'twitter' => 'X (Twitter)', 'x.com' => 'X (Twitter)',
              'yahoo' => 'Yahoo', 'duckduckgo' => 'DuckDuckGo', 'ecosia' => 'Ecosia', 'chatgpt' => 'ChatGPT', 'mail.' => 'Email', 'outlook' => 'Email'];
    foreach ($rules as $k => $v) if (str_contains($h, $k)) return $v;
    return mb_substr($h, 0, 30);
}

function stats_salt(): string {
    $s = q("SELECT v FROM settings WHERE k = 'stats_salt'")->fetchColumn();
    if (!$s) { $s = bin2hex(random_bytes(16)); q("INSERT INTO settings (k, v) VALUES ('stats_salt', ?)", [$s]); }
    return (string)$s;
}

/** Enregistre un évènement envoyé par assets/t.js. Renvoie false s'il est ignoré. */
function stats_record(array $in): bool {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match(STATS_BOT_UA, $ua)) return false;
    $u = current_user();
    if ($u && $u['role'] === 'admin') return false;           // l'équipe ne compte pas
    $ev = (string)($in['e'] ?? '');
    if (!in_array($ev, ['view', 'cta', 'video'], true)) return false;
    $path = substr(preg_replace('/[^\w\/\-.%]/u', '', (string)($in['p'] ?? '/')) ?: '/', 0, 190);
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'stayinriad.com');
    $src = stats_source(substr((string)($in['r'] ?? ''), 0, 400), (string)($in['u'] ?? ''), $host);
    $day = date('Y-m-d');
    // identifiant anonyme du jour (IP + navigateur + date, haché avec un sel secret) : aucun cookie, rien de stocké en clair
    $vid = substr(hash('sha256', stats_salt() . '|' . $day . '|' . client_ip() . '|' . $ua), 0, 16);
    if ((int)q('SELECT COUNT(*) FROM visits WHERE day = ? AND vid = ?', [$day, $vid])->fetchColumn() >= 300) return false;
    $w = (int)($in['w'] ?? 0);
    $dev = preg_match('/iPad|Tablet/i', $ua) ? 'tablette' : (preg_match('/Mobi|Android|iPhone/i', $ua) || ($w > 0 && $w < 700) ? 'mobile' : 'ordinateur');
    q('INSERT INTO visits (at, day, vid, ev, path, src, country, dev, lang, extra) VALUES (?,?,?,?,?,?,?,?,?,?)', [
        now(), $day, $vid, $ev, $path, $src, stats_country((string)($in['tz'] ?? '')), $dev,
        substr(preg_replace('/[^A-Za-z\-]/', '', (string)($in['l'] ?? '')), 0, 5), substr((string)($in['x'] ?? ''), 0, 120),
    ]);
    return true;
}

function stats_page_label(string $p): string {
    $known = ['/' => 'Accueil (FR)', '/en/' => 'Accueil (EN)', '/es/' => 'Accueil (ES)', '/ar/' => 'Accueil (AR)',
              '/app/register.php' => 'Formulaire d\'inscription riad', '/inscrire-mon-riad/' => 'Guide « Inscrire mon riad »',
              '/en/list-your-riad/' => 'Guide « List your riad » (EN)', '/conditions/' => 'Conditions (CGU)', '/app/login.php' => 'Connexion'];
    if (isset($known[$p])) return $known[$p];
    if (preg_match('#^/(en/)?riads-([a-z]+)/$#', $p, $m)) return 'Guide riads ' . ucfirst($m[2]) . ($m[1] ? ' (EN)' : '');
    return $p;
}
