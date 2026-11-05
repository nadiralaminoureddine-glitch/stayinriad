<?php
// StayInRiad — outils pour les fiches riads : RIB, dossier, fichiers, photos
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const CITIES = ['Marrakech','Fès','Essaouira','Chefchaouen','Meknès','Rabat','Tanger','Tétouan','Asilah','Casablanca','El Jadida','Oualidia','Safi','Agadir','Taghazout','Taroudant','Tiznit','Mirleft','Imlil','Ouarzazate','Aït Ben Haddou','Skoura','Tinghir','Merzouga','Rissani','Zagora','Ifrane','Moulay Idriss','Larache','Al Hoceïma','Dakhla','Autre'];
const AMENITIES = ['Wi-Fi','Piscine','Hammam','Spa','Terrasse','Climatisation','Restaurant','Parking','Transfert aéroport','Petit-déjeuner'];
const BANKS = ['Attijariwafa bank','Banque Populaire','Bank of Africa (BMCE)','CIH Bank','BMCI','Crédit du Maroc','Crédit Agricole du Maroc','Al Barid Bank','Société Générale Maroc / Saham Bank','CDG Capital','Autre'];
const FORMS = ['SARL','SARL AU','SA','SNC','Auto-entrepreneur','Personne physique','Autre'];
const RIAD_STATUS = ['brouillon' => ['Brouillon', 'warn'], 'soumis' => ['En vérification', 'info'], 'en ligne' => ['En ligne', 'ok'], 'refusé' => ['À corriger', 'bad']];

function digits(string $s): string { return preg_replace('/\D+/', '', $s) ?? ''; }

/** Reste de la division par 97 d'un grand nombre écrit en chiffres. */
function mod97(string $num): int { $r = 0; foreach (str_split($num) as $c) { $r = ($r * 10 + (int)$c) % 97; } return $r; }
/** RIB marocain : 24 chiffres, clé = 97 − (22 premiers chiffres × 100 mod 97). */
function rib_ok(string $rib): bool {
    $r = digits($rib);
    if (strlen($r) !== 24) return false;
    return 97 - mod97(substr($r, 0, 22) . '00') === (int)substr($r, 22, 2);
}
function iban_from_rib(string $rib): string {
    $r = digits($rib); if (strlen($r) !== 24) return '';
    $check = 98 - mod97($r . '221000'); // M=22 A=10 + 00
    return 'MA' . str_pad((string)$check, 2, '0', STR_PAD_LEFT) . $r;
}
function mask_rib(string $rib): string { $r = digits($rib); return strlen($r) === 24 ? substr($r, 0, 3) . ' ••• •••• ' . substr($r, -6, 4) . ' ' . substr($r, -2) : ''; }
function norm_name(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
    $s = preg_replace('/\b(ste|societe|s\.?a\.?r\.?l\.?(\s*au)?|sa|snc|sas)\b/u', '', $s) ?? $s;
    return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
}
function holder_ok(array $r): bool { return $r['co_legal'] !== '' && $r['bank_holder'] !== '' && norm_name($r['co_legal']) === norm_name($r['bank_holder']); }

/** Liste de ce qui manque avant de pouvoir envoyer la fiche. */
function dossier_missing(array $r): array {
    $m = [];
    if (mb_strlen(trim((string)$r['description'])) < 80) $m[] = 'une description d\'au moins 80 caractères';
    if (trim((string)$r['address']) === '') $m[] = 'l\'adresse du riad';
    if ($r['co_legal'] === '') $m[] = 'la raison sociale';
    if (strlen(digits((string)$r['co_ice'])) !== 15) $m[] = 'l\'ICE (15 chiffres)';
    if ($r['co_rc'] === '') $m[] = 'le registre de commerce';
    if ($r['co_license'] === '') $m[] = 'l\'autorisation d\'exploitation touristique';
    if ($r['co_rep'] === '') $m[] = 'le nom du représentant légal';
    if (!rib_ok((string)$r['bank_rib'])) $m[] = 'un RIB valide (24 chiffres)';
    elseif (!holder_ok($r)) $m[] = 'un RIB au nom de la société (' . $r['co_legal'] . ')';
    if ($r['bank_doc'] === '') $m[] = 'l\'attestation de RIB';
    $rooms = (int)q('SELECT COUNT(*) FROM rooms WHERE riad_id = ? AND active = 1 AND price_eur > 0', [$r['id']])->fetchColumn();
    if ($rooms < 1) $m[] = 'au moins une chambre avec un prix';
    $ph = (int)q("SELECT COUNT(*) FROM photos WHERE riad_id = ? AND status <> 'refusée'", [$r['id']])->fetchColumn();
    if ($ph < 3) $m[] = 'au moins 3 photos';
    return $m;
}
function status_pill(string $s): string { [$l, $c] = RIAD_STATUS[$s] ?? [$s, 'info']; return '<span class="pill ' . $c . '">' . e($l) . '</span>'; }
function bank_pill(array $r): string {
    return match ($r['bank_status']) { 'vérifié' => '<span class="pill ok">RIB vérifié</span>', 'refusé' => '<span class="pill bad">RIB refusé</span>', 'à vérifier' => '<span class="pill warn">RIB à vérifier</span>', default => '<span class="pill warn">RIB manquant</span>' };
}

/** Riad du compte connecté (ou d'un id pour l'admin). */
function my_riad(array $u, ?int $id = null): ?array {
    if ($u['role'] === 'admin' && $id) return q('SELECT * FROM riads WHERE id = ?', [$id])->fetch() ?: null;
    $sql = 'SELECT * FROM riads WHERE owner_id = ?' . ($id ? ' AND id = ?' : '') . ' ORDER BY id LIMIT 1';
    return q($sql, $id ? [$u['id'], $id] : [$u['id']])->fetch() ?: null;
}
/** Quand un riad en ligne modifie des infos importantes, il repasse en vérification. */
function touch_riad(int $id): void { q('UPDATE riads SET updated_at = ? WHERE id = ?', [now(), $id]); }

// ---------- Fichiers ----------
function private_dir(): string {
    global $CONFIG;
    $d = $CONFIG['private_dir'] ?? (dirname(__DIR__, 3) . '/stayinriad-private');
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}
function public_photo_dir(): string { $d = dirname(__DIR__) . '/uploads'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function new_name(string $ext): string { return bin2hex(random_bytes(12)) . '.' . $ext; }

/** Enregistre une attestation (PDF ou image) hors du site public. Retourne [nom, erreur]. */
function save_private_doc(array $f): array {
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) return [null, 'Le fichier n\'a pas pu être envoyé.'];
    if ($f['size'] > 5 * 1024 * 1024) return [null, 'Fichier trop lourd (5 Mo maximum).'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
    if (!$ext) return [null, 'Formats acceptés : PDF, JPG ou PNG.'];
    $name = new_name($ext);
    if (!move_uploaded_file($f['tmp_name'], private_dir() . '/' . $name)) return [null, 'Impossible d\'enregistrer le fichier.'];
    return [$name, null];
}

/** Ouvre une image téléversée (JPG/PNG/WebP) en ressource GD. */
function gd_open(string $path) {
    $info = @getimagesize($path); if (!$info) return null;
    return match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($path), IMAGETYPE_PNG => @imagecreatefrompng($path), IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null, default => null };
}
function gd_resize($im, int $max) {
    $w = imagesx($im); $h = imagesy($im); $k = min(1, $max / max($w, $h));
    if ($k >= 1) return $im;
    $nw = (int)round($w * $k); $nh = (int)round($h * $k);
    $out = imagecreatetruecolor($nw, $nh); imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); return $out;
}
/** Empreinte visuelle (dHash 64 bits) : identique si la photo est réduite, recompressée ou un peu recadrée. */
function img_hash($im): string {
    $s = imagecreatetruecolor(9, 8); imagecopyresampled($s, $im, 0, 0, 0, 0, 9, 8, imagesx($im), imagesy($im));
    $g = function ($x, $y) use ($s) { $c = imagecolorat($s, $x, $y); return (($c >> 16) & 255) * .3 + (($c >> 8) & 255) * .59 + ($c & 255) * .11; };
    $bits = '';
    for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++) $bits .= $g($x, $y) > $g($x + 1, $y) ? '1' : '0';
    $hex = ''; foreach (str_split($bits, 4) as $n) $hex .= dechex(bindec($n));
    return $hex;
}
function hamming(string $a, string $b): int { $d = 0; for ($i = 0; $i < 16; $i++) { $x = hexdec($a[$i]) ^ hexdec($b[$i]); $d += substr_count(decbin($x), '1'); } return $d; }

/** Enregistre une photo : original privé (redimensionné) + empreinte. Retourne [nom, hash, erreur]. */
function save_photo(array $f): array {
    if (!function_exists('imagecreatetruecolor')) return [null, '', 'Le traitement d\'images (GD) n\'est pas activé sur l\'hébergement.'];
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) return [null, '', 'La photo n\'a pas pu être envoyée.'];
    if ($f['size'] > 12 * 1024 * 1024) return [null, '', 'Photo trop lourde (12 Mo maximum).'];
    $im = gd_open($f['tmp_name']);
    if (!$im) return [null, '', 'Formats acceptés : JPG, PNG ou WebP.'];
    $im = gd_resize($im, 1600);
    $name = new_name('jpg');
    imagejpeg($im, private_dir() . '/' . $name, 86);
    return [$name, img_hash($im), null];
}

/** Ajoute le logo stayinriad.com (en bas à droite) et publie la photo. Retourne le nom du fichier public. */
function publish_photo(string $file): string {
    $im = imagecreatefromjpeg(private_dir() . '/' . $file);
    $w = imagesx($im); $h = imagesy($im);
    $wm = @imagecreatefrompng(dirname(__DIR__) . '/assets/watermark.png');
    if ($wm) {
        $ww = imagesx($wm); $wh = imagesy($wm);
        $tw = (int)round($w * 0.30); $th = (int)round($wh * $tw / $ww); $pad = (int)round($w * 0.025);
        imagealphablending($im, true);
        imagecopyresampled($im, $wm, $w - $tw - $pad, $h - $th - $pad, 0, 0, $tw, $th, $ww, $wh);
    }
    $pub = new_name('jpg');
    imagejpeg($im, public_photo_dir() . '/' . $pub, 85);
    return $pub;
}

/** Photos très ressemblantes dans deux riads différents (non encore tranchées). */
function photo_duplicates(?int $riadId = null): array {
    $rows = q("SELECT p.id, p.riad_id, p.file, p.hash, p.status, r.name AS riad_name FROM photos p JOIN riads r ON r.id = p.riad_id WHERE p.hash <> '' AND p.status <> 'refusée'")->fetchAll();
    $ok = array_flip(array_column(q('SELECT pair FROM photo_ok')->fetchAll(), 'pair'));
    $out = [];
    for ($i = 0; $i < count($rows); $i++) for ($j = $i + 1; $j < count($rows); $j++) {
        $a = $rows[$i]; $b = $rows[$j];
        if ($a['riad_id'] === $b['riad_id']) continue;
        if ($riadId && $a['riad_id'] != $riadId && $b['riad_id'] != $riadId) continue;
        $d = hamming($a['hash'], $b['hash']); if ($d > 10) continue;
        $pair = min($a['id'], $b['id']) . '-' . max($a['id'], $b['id']);
        if (isset($ok[$pair])) continue;
        $out[] = ['a' => $a, 'b' => $b, 'sim' => (int)round((1 - $d / 64) * 100), 'pair' => $pair];
    }
    return $out;
}

function riad_tabs(string $on, string $q = ''): string {
    $t = ['res' => ['bookings.php', 'Réservations'], 'cal' => ['calendar.php', 'Calendrier'], 'sync' => ['sync.php', 'Booking/Airbnb'], 'police' => ['police_list.php', 'Fiches de police'], 'avis' => ['reviews.php', 'Avis clients'], 'pay' => ['payouts.php', 'Versements'], 'releve' => ['statement.php', 'Relevé mensuel'], 'fiche' => ['riad.php', 'Fiche'], 'rooms' => ['rooms.php', 'Chambres & prix'], 'photos' => ['photos.php', 'Photos']];
    return '<div class="tabs">' . implode('', array_map(fn($k) => '<a href="' . $t[$k][0] . $q . '"' . ($k === $on ? ' class="on"' : '') . '>' . $t[$k][1] . '</a>', array_keys($t))) . '</div>';
}

// ---------------------------------------------------------------- commission choisie par le riad : 3 niveaux de visibilité
const COMMISSION_MIN = 15;
const COMMISSION_MAX = 20;
const COMMISSION_TIERS = [
    20 => ['Première page', 'En tête des résultats de recherche'],
    18 => ['2e page', 'Juste après les riads à 20 %'],
    15 => ['Visibilité normale', 'Après les riads à 18 et 20 %'],
];
function commission_card(array $r): string {
    $cur = (float)$r['commission'];
    $opts = '';
    foreach ([15, 18, 20] as $c) {
        [$lvl, $txt] = COMMISSION_TIERS[$c];
        $on = abs($cur - $c) < 0.01;
        $opts .= '<label class="ccard' . ($on ? ' on' : '') . '"><input type="radio" name="commission" value="' . $c . '"' . ($on ? ' checked' : '') . '><b>' . $c . ' %</b><em>' . $lvl . '</em><span>' . $txt . '</span></label>';
    }
    $outside = !isset(COMMISSION_TIERS[(int)round($cur)]) || abs($cur - round($cur)) > 0.001 ? '<p class="note">Commission actuelle : <b>' . e(rtrim(rtrim(number_format($cur, 2, ',', ''), '0'), ',')) . ' %</b>. Choisissez un des trois niveaux ci-dessous pour la changer.</p>' : '';
    return '<div class="card" style="margin-top:18px"><h2 style="margin-top:0">Votre commission et votre visibilité</h2>
' . (launch_offer_text() ? '<p class="flash ok" style="margin:0 0 12px">' . e(launch_offer_text()) . ' Le niveau choisi compte déjà pour votre visibilité.</p>' : '') . '<p>C\'est vous qui choisissez : <b>15, 18 ou 20 %</b>. Plus la commission est élevée, plus votre riad apparaît haut dans les résultats de recherche (à égalité, la note des voyageurs départage). Elle est calculée uniquement sur le prix des nuits (jamais sur la taxe de séjour ni les extras). Vous pouvez la changer quand vous voulez : le nouveau taux s\'applique aux <b>prochaines réservations</b>, celles déjà faites gardent leur taux.</p>' . $outside . '
<form method="post">' . csrf_field() . '<div class="ccards">' . $opts . '</div><button class="btn" name="action" value="commission">Enregistrer ma commission</button></form></div>';
}
