<?php
// StayInRiad — gabarit des pages
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

function logo_html(): string {
    return '<a class="logo" href="account.php"><svg width="30" height="30" viewBox="0 0 40 40" aria-hidden="true"><rect x="8" y="8" width="24" height="24" fill="#d9a441"/><rect x="8" y="8" width="24" height="24" fill="#d9a441" transform="rotate(45 20 20)"/><rect x="13" y="13" width="14" height="14" fill="#0f5a4a"/><rect x="13" y="13" width="14" height="14" fill="#0f5a4a" transform="rotate(45 20 20)"/><circle cx="20" cy="20" r="3" fill="#d9a441"/></svg><span>stay<b>in</b>riad<b>.com</b></span></a>';
}

/** Logos de paiement officiels, s'ils ont été déposés dans assets/pay/ (cmi, visa, mastercard). */
function pay_logos(): string {
    $dir = dirname(__DIR__) . '/assets/pay'; $out = '';
    foreach (['cmi' => 'CMI', 'visa' => 'Visa', 'mastercard' => 'Mastercard'] as $f => $alt) {
        foreach (['svg', 'png'] as $ext) if (is_file("$dir/$f.$ext")) { $out .= '<img src="assets/pay/' . $f . '.' . $ext . '" alt="' . $alt . '" height="28">'; break; }
    }
    return $out ? '<div class="paylogos"><span>🔒 Paiement sécurisé</span>' . $out . '</div>' : '';
}

function render_page(string $title, string $body): void {
    $u = current_user();
    $f = flash();
    $nav = '';
    if ($u) {
        $nav = '<span class="who">' . e($u['first_name']) . ' · ' . e(ROLES[$u['role']] ?? '') . '</span>'
             . (site_open() || $u['role'] === 'admin' ? '<a href="riads.php">Les riads</a>' : '')
             . ($u['role'] === 'admin' ? '<a href="admin.php">Comptes</a><a href="bookings.php">Réservations</a><a href="payouts.php">Versements</a>' : '')
             . '<a href="account.php">Mon espace</a>'
             . '<form method="post" action="logout.php" class="inline">' . csrf_field() . '<button class="link">Déconnexion</button></form>';
    } else {
        $nav = (site_open() ? '<a href="riads.php">Les riads</a>' : '') . '<a href="login.php">Connexion</a><a href="register.php' . (site_open() ? '' : '?type=riad') . '">' . (site_open() ? 'Créer un compte' : 'Inscrire mon riad') . '</a>';
    }
    $cur = $_SESSION['cur'] ?? 'EUR';
    $qs = $_GET; unset($qs['cur']);
    $curLinks = '<span class="cur">' . implode('', array_map(fn($c, $l) => '<a href="?' . e(http_build_query($qs + ['cur' => $c])) . '"' . ($c === $cur ? ' class="on"' : '') . '>' . $l . '</a>', ['EUR', 'MAD', 'USD'], ['€', 'DH', '$'])) . '</span>';
    $nav = $curLinks . $nav;
    $flash = $f ? '<div class="flash ' . e($f[1]) . '">' . e($f[0]) . '</div>' : '';
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow"><title>' . e($title) . ' · StayInRiad</title>'
       . '<link rel="stylesheet" href="assets/app.css">' . (isset($_SESSION['cur']) ? '' : '<script src="assets/cur.js" defer></script>') . '</head><body>'
       . '<header><div class="wrap">' . logo_html() . '<nav>' . $nav . '</nav></div></header>'
       . '<main class="wrap">' . $flash . $body . '</main>'
       . '<footer class="wrap">' . pay_logos() . '© ' . date('Y') . ' stayinriad.com · <a href="mailto:contact@stayinriad.com">contact@stayinriad.com</a></footer>'
       . '</body></html>';
}
