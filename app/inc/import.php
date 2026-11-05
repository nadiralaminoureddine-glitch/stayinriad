<?php
// StayInRiad — import d'une fiche depuis la page Booking du riad.
// Booking refuse les lectures automatiques venant d'un serveur : les données sont lues dans le navigateur
// (favori « Importer dans StayInRiad » sur la page Booking), collées dans admin_import.php, puis appliquées ici.
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const IMPORT_MAX_PHOTOS = 30;

/** Lien Booking d'un riad, nettoyé (sans paramètres de suivi). Null si ce n'est pas un lien Booking valable. */
function booking_link(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return null;
    $host = strtolower($p['host']);
    if ($host !== 'booking.com' && !str_ends_with($host, '.booking.com')) return null;
    $path = $p['path'] ?? '';
    if (preg_match('#^/hotel/[a-z]{2}/[A-Za-z0-9._-]+\.html$#', $path)) return 'https://www.booking.com' . $path;
    if (preg_match('#^/Share-[A-Za-z0-9]+/?$#', $path)) return 'https://www.booking.com' . $path; // lien « Partager » de l'appli
    return null;
}

/** Clé stable d'une page Booking (sans la langue) : hotel/ma/riad-janate. */
function booking_key(string $url): string {
    $p = (string)parse_url($url, PHP_URL_PATH);
    return strtolower(preg_replace('#\.[a-z]{2}(-[a-z]{2})?\.html$|\.html$#i', '', trim($p, '/')));
}

/** Équipements StayInRiad reconnus dans un texte Booking. */
function import_amenities(string $t): array {
    $map = [
        'Wi-Fi' => '/wi-?fi|internet/i', 'Piscine' => '/piscine|swimming pool|\bpool\b/i', 'Hammam' => '/hammam|bain turc|turkish bath/i',
        'Spa' => '/\bspa\b|massage|bien-être/i', 'Terrasse' => '/terrasse|terrace|rooftop|toit-terrasse/i', 'Climatisation' => '/climatis|air condition/i',
        'Restaurant' => '/restaurant/i', 'Parking' => '/parking/i', 'Transfert aéroport' => '/navette.{0,20}aéroport|transfert.{0,20}aéroport|airport shuttle/i',
        'Petit-déjeuner' => '/petit[- ]déjeuner|breakfast/i',
    ];
    return array_keys(array_filter($map, fn($re) => preg_match($re, $t) === 1));
}

/** Coupe un texte proprement à $max caractères. */
function import_cut(string $s, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if (mb_strlen($s) <= $max) return $s;
    $s = mb_substr($s, 0, $max - 1);
    $sp = mb_strrpos($s, ' ');
    return rtrim($sp > $max * 0.6 ? mb_substr($s, 0, $sp) : $s, " ,;.") . '…';
}

/** Photo Booking [taille, id, clé] → adresse sur le serveur d'images de Booking. */
function import_photo_url($p): ?string {
    if (!is_array($p) || count($p) !== 3) return null;
    [$size, $id, $k] = array_map('strval', $p);
    if (!preg_match('/^max\d{3,4}x\d{3,4}$/', $size) || !preg_match('/^\d{4,12}$/', $id) || !preg_match('/^[a-f0-9]{20,80}$/', $k)) return null;
    return 'https://cf.bstatic.com/xdata/images/hotel/' . $size . '/' . $id . '.jpg?k=' . $k . '&o=';
}

/** Télécharge une photo Booking et l'enregistre comme une photo envoyée par le riad. Retourne [nom, hash, erreur]. */
function import_photo_fetch(string $url): array {
    if (!function_exists('curl_init') || !function_exists('imagecreatetruecolor')) return [null, '', 'curl ou GD absent'];
    $body = ''; $tooBig = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; StayInRiad-Import/1.0; +https://stayinriad.com)',
        CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/*'],
        CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$tooBig) { $body .= $chunk; if (strlen($body) > 8 * 1024 * 1024) { $tooBig = true; return 0; } return strlen($chunk); },
    ]);
    $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($tooBig) return [null, '', 'photo trop lourde'];
    if ($ok === false || $code !== 200 || $body === '') return [null, '', 'téléchargement refusé (' . $code . ')'];
    $tmp = tempnam(sys_get_temp_dir(), 'sir');
    file_put_contents($tmp, $body);
    $im = gd_open($tmp); @unlink($tmp);
    if (!$im) return [null, '', 'image illisible'];
    $im = gd_resize($im, 1600);
    $name = new_name('jpg');
    imagejpeg($im, private_dir() . '/' . $name, 86);
    return [$name, img_hash($im), null];
}

/** Lit le texte copié depuis Booking. Retourne [données, erreur]. */
function import_decode(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [null, 'Collez d\'abord le texte copié depuis la page Booking.'];
    if (preg_match('/^(javascript:|\(\(\)|%28%28)/i', $raw)) return [null, 'Vous avez collé le code du favori, pas les données. Le code du favori va dans votre barre de favoris ; ensuite, cliquez sur ce favori depuis la page Booking du riad.'];
    $d = json_decode($raw, true);
    if (!is_array($d) || ($d['src'] ?? '') !== 'booking' || (int)($d['v'] ?? 0) !== 1) return [null, 'Ce texte ne vient pas du favori « Importer dans StayInRiad ». Recommencez depuis la page Booking du riad.'];
    if (!is_array($d['rooms'] ?? null)) $d['rooms'] = [];
    if (!is_array($d['photos'] ?? null)) $d['photos'] = [];
    return [$d, null];
}

/**
 * Remplit la fiche d'un riad avec les données Booking. Ne remplace jamais ce que le riad a déjà saisi :
 * champs vides seulement, chambres ajoutées si le nom n'existe pas, photos ajoutées dans la limite.
 */
function import_apply(array $r, array $d, bool $withPhotos = true): array {
    @set_time_limit(240);
    $rid = (int)$r['id'];
    $done = ['fields' => [], 'rooms' => 0, 'photos' => 0, 'photo_errors' => 0];

    $set = []; $vals = [];
    $addr = import_cut((string)($d['address'] ?? ''), 255);
    if (trim((string)$r['address']) === '' && $addr !== '') { $set[] = 'address = ?'; $vals[] = $addr; $done['fields'][] = 'adresse'; }
    $desc = trim(preg_replace("/[ \t]+/u", ' ', (string)($d['desc'] ?? '')));
    $desc = mb_substr(preg_replace("/\n{3,}/", "\n\n", $desc), 0, 3000);
    if (mb_strlen(trim((string)$r['description'])) < 80 && mb_strlen($desc) >= 80) { $set[] = 'description = ?'; $vals[] = $desc; $done['fields'][] = 'description'; }
    if ($r['lat'] === null && preg_match('/^(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)$/', trim((string)($d['ll'] ?? '')), $m)) {
        $lat = round((float)$m[1], 6); $lng = round((float)$m[2], 6);
        if ($lat >= 27 && $lat <= 36.5 && $lng >= -13.5 && $lng <= -0.9) { $set[] = 'lat = ?'; $set[] = 'lng = ?'; array_push($vals, $lat, $lng); $done['fields'][] = 'position GPS'; }
    }
    $amenOld = json_decode((string)($r['amenities'] ?? '[]'), true) ?: [];
    $amenNew = array_values(array_unique(array_merge($amenOld, array_intersect(import_amenities((string)($d['fac'] ?? '') . ' ' . $desc), AMENITIES))));
    if (count($amenNew) > count($amenOld)) { $set[] = 'amenities = ?'; $vals[] = json_encode($amenNew, JSON_UNESCAPED_UNICODE); $done['fields'][] = 'équipements'; }
    $url = booking_link((string)($d['url'] ?? ''));
    if ($url && ($r['booking_url'] ?? '') === '') { $set[] = 'booking_url = ?'; $vals[] = $url; }
    $set[] = 'import_done = ?'; $vals[] = now();
    $set[] = 'updated_at = ?'; $vals[] = now();
    $vals[] = $rid;
    q('UPDATE riads SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);

    // Chambres : une par type Booking, prix à compléter par le riad
    $have = [];
    foreach (q('SELECT id, name FROM rooms WHERE riad_id = ? AND active = 1', [$rid])->fetchAll() as $x) $have[mb_strtolower(trim($x['name']))] = (int)$x['id'];
    $roomIds = [];
    foreach (array_slice($d['rooms'], 0, 30) as $room) {
        $name = import_cut((string)($room['name'] ?? ''), 120);
        if ($name === '') continue;
        $key = mb_strtolower($name);
        if (isset($have[$key])) { $roomIds[$key] = $have[$key]; continue; }
        $size = (int)($room['size'] ?? 0);
        $rdesc = import_cut(($size > 0 && $size < 500 ? $size . ' m² · ' : '') . (string)($room['desc'] ?? ''), 255);
        q('INSERT INTO rooms (riad_id, name, description, capacity, qty, price_eur, active, created_at) VALUES (?,?,?,?,1,0,1,?)',
          [$rid, $name, $rdesc, max(1, min(12, (int)($room['cap'] ?? 2))), now()]);
        $have[$key] = $roomIds[$key] = (int)db()->lastInsertId();
        $done['rooms']++;
    }
    if ($done['rooms']) q('UPDATE riads SET rooms_count = (SELECT COUNT(*) FROM rooms WHERE riad_id = ? AND active = 1) WHERE id = ?', [$rid, $rid]);

    if (!$withPhotos) return $done;
    // Photos : quelques vues générales, puis 3 par chambre, puis le reste des vues générales
    $count = (int)q("SELECT COUNT(*) FROM photos WHERE riad_id = ? AND status <> 'refusée'", [$rid])->fetchColumn();
    $budget = IMPORT_MAX_PHOTOS - $count;
    $queue = []; $seen = [];
    $push = function ($p, ?int $roomId) use (&$queue, &$seen) { $u = import_photo_url($p); if (!$u || isset($seen[$p[1]])) return; $seen[$p[1]] = 1; $queue[] = [$u, $roomId]; };
    $general = $d['photos'];
    foreach (array_slice($general, 0, 8) as $p) $push($p, null);
    foreach ($d['rooms'] as $room) {
        $rk = mb_strtolower(import_cut((string)($room['name'] ?? ''), 120));
        foreach (array_slice((array)($room['photos'] ?? []), 0, 3) as $p) $push($p, $roomIds[$rk] ?? null);
    }
    foreach (array_slice($general, 8) as $p) $push($p, null);
    $sort = $count;
    foreach (array_slice($queue, 0, max(0, $budget)) as [$u, $roomId]) {
        [$name, $hash, $err] = import_photo_fetch($u);
        if ($err) { $done['photo_errors']++; if ($done['photo_errors'] >= 4 && !$done['photos']) break; continue; }
        q('INSERT INTO photos (riad_id, room_id, file, hash, status, sort, created_at) VALUES (?,?,?,?,?,?,?)', [$rid, $roomId, $name, $hash, 'en attente', $sort++, now()]);
        $done['photos']++;
    }
    return $done;
}

/** Favori à glisser dans la barre du navigateur : lit la page Booking ouverte et copie les données. */
function import_bookmarklet(): string {
    $js = <<<'JS'
(()=>{try{if(!/booking\.com$/.test(location.hostname)){alert('StayInRiad : ouvrez d\'abord la page du riad sur Booking.com');return}
const H=document.documentElement.innerHTML;
const ld=[...document.querySelectorAll('script[type="application/ld+json"]')].map(s=>{try{return JSON.parse(s.textContent)}catch(e){return{}}}).find(j=>/Hotel|Lodging/.test(j['@type']||''))||{};
const pic=u=>{const m=String(u).replace(/&amp;/g,'&').match(/xdata\/images\/hotel\/(max\d+x\d+)\/(\d+)\.jpg\?k=([a-f0-9]+)/);return m?[m[1],m[2],m[3]]:null};
const all={};const walk=o=>{if(o&&typeof o=='object'){for(const k in o){if(/^RTRoom(Card|Photo):/.test(k))all[k]=o[k];else walk(o[k])}}};
for(const s of document.querySelectorAll('script[type="application/json"]')){if(s.textContent.indexOf('RTRoomCard:')>-1){try{walk(JSON.parse(s.textContent))}catch(e){}}}
const seen=new Set(),rooms=[];
for(const k in all){const c=all[k];if(c.__typename!=='RTRoomCard'||!c.name)continue;const key=c.name.trim().toLowerCase();if(seen.has(key))continue;seen.add(key);
rooms.push({name:c.name.trim(),desc:c.description||'',size:c.roomSize||0,cap:(c.occupancy||{}).maxPersons||2,photos:(c.photos||[]).map(r=>all[r.__ref]).filter(Boolean).map(p=>pic(p.photoUri)).filter(Boolean).slice(0,6)})}
const ph=[],ids=new Set();for(const m of H.matchAll(/large_url:\s*'([^']+)'/g)){const p=pic(m[1]);if(p&&!ids.has(p[1])){ids.add(p[1]);ph.push(p)}}
const g=s=>document.querySelector(s);
const out={v:1,src:'booking',url:location.origin+location.pathname,name:ld.name||'',address:(ld.address||{}).streetAddress||'',city:(ld.address||{}).addressLocality||'',
desc:((g('[data-testid="property-description"]')||{}).innerText||ld.description||'').trim(),ll:(g('[data-atlas-latlng]')||{getAttribute:()=>''}).getAttribute('data-atlas-latlng')||'',
fac:[...document.querySelectorAll('[data-testid*="facilit"]')].map(e=>e.innerText).join(' | ').slice(0,4000),rooms,photos:ph};
if(!rooms.length&&!ph.length){alert('StayInRiad : rien trouvé. Ouvrez la page du riad sur Booking (pas la liste de recherche), attendez qu\'elle soit chargée et recommencez.');return}
const t=JSON.stringify(out);const u='__BASE__admin_import.php?auto=1#sir='+btoa(unescape(encodeURIComponent(t)));
const w=window.open(u,'_blank');if(!w)location.href=u}catch(e){alert('StayInRiad : erreur, '+e.message)}})()
JS;
    $js = str_replace('__BASE__', base_url(), $js);
    return 'javascript:' . rawurlencode(preg_replace("/\n/", '', $js));
}

/** Enregistre la demande d'import Booking d'un riad et prévient l'admin par email. */
function import_request(int $rid, string $link): void {
    q('UPDATE riads SET booking_url = ?, import_requested = ?, updated_at = ? WHERE id = ?', [$link, now(), now(), $rid]);
    $r = q('SELECT r.name, r.city, u.first_name, u.last_name, u.phone FROM riads r JOIN users u ON u.id = r.owner_id WHERE r.id = ?', [$rid])->fetch();
    if (!$r) return;
    send_mail(admin_email(), '[Import Booking] ' . $r['name'] . ' (' . $r['city'] . ') : fiche à pré-remplir', mail_layout('Un riad demande l\'import de sa fiche Booking', mail_rows(['Riad' => e($r['name']), 'Ville' => e($r['city']), 'Propriétaire' => e(trim($r['first_name'] . ' ' . $r['last_name'])), 'Téléphone' => e((string)$r['phone']), 'Page Booking' => '<a href="' . e($link) . '">' . e($link) . '</a>']) . '<p>Ouvrez la page d\'import, puis la page Booking du riad, et cliquez sur le favori « Importer dans StayInRiad » : l\'import se fait tout seul.</p>' . mail_button(base_url() . 'admin_import.php?id=' . $rid, 'Importer la fiche')));
    log_action('import_demande', $r['name'] . ' · ' . $link);
}

/** Encadré « Votre riad est déjà sur Booking ? » de la fiche riad. */
function import_card(array $r, array $u): string {
    $d = fn($t) => date('d/m/Y', strtotime($t . ' UTC'));
    $admin = $u['role'] === 'admin' ? ' <a class="btn sm" href="admin_import.php?id=' . (int)$r['id'] . '">Importer maintenant</a>' : '';
    if (!empty($r['import_done'])) {
        $noPrice = (int)q('SELECT COUNT(*) FROM rooms WHERE riad_id = ? AND active = 1 AND price_eur <= 0', [(int)$r['id']])->fetchColumn();
        return '<div class="flash ok">✅ Fiche pré-remplie depuis Booking le ' . $d($r['import_done']) . '. Relisez la description ci-dessous'
            . ($noPrice ? ' et <b><a href="rooms.php' . ($u['role'] === 'admin' ? '?id=' . (int)$r['id'] : '') . '">indiquez le prix de vos ' . $noPrice . ' chambre' . ($noPrice > 1 ? 's' : '') . '</a></b>' : '') . '.' . $admin . '</div>';
    }
    if (!empty($r['import_requested'])) {
        return '<div class="flash warn">⏳ Import demandé le ' . $d($r['import_requested']) . ' : notre équipe pré-remplit votre fiche à partir de <a href="' . e($r['booking_url']) . '" target="_blank" rel="noopener">votre page Booking</a>. Vous recevrez un email dès que c\'est prêt.' . $admin . '</div>';
    }
    return '<div class="card import-card"><h2 style="margin-top:0">⚡ Votre riad est déjà sur Booking ?</h2>
<p>Collez le lien de votre page Booking : <b>nous remplissons votre fiche pour vous</b> (description, adresse, équipements, chambres et photos). Il ne vous restera qu\'à indiquer vos prix.</p>
<form method="post" class="import-form">' . csrf_field() . '<input name="booking_url" inputmode="url" maxlength="400" placeholder="https://www.booking.com/hotel/ma/votre-riad.html" required><button class="btn" name="action" value="import_request">Envoyer le lien</button></form>
<p class="note">Où trouver le lien ? Ouvrez votre riad sur booking.com et copiez l\'adresse de la page. Dans l\'appli Booking : bouton <b>Partager</b>, puis <b>Copier le lien</b>.' . $admin . '</p></div>';
}
