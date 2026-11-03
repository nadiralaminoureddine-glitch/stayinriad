<?php
// Fiche de satisfaction du voyageur (lien privé reçu par email après le séjour)
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$b = q('SELECT * FROM bookings WHERE ref = ?', [(string)($_GET['ref'] ?? '')])->fetch();
$bad = fn($msg) => render_page('Votre avis', '<div class="card"><h1>Votre avis</h1><p>' . $msg . '</p></div>');
if (!$b || !hash_equals($b['token'], (string)($_GET['t'] ?? '')) || $b['status'] !== 'confirmée') { http_response_code(404); $bad('Ce lien est introuvable ou n\'est plus actif.'); exit; }
$r = q('SELECT * FROM riads WHERE id = ?', [$b['riad_id']])->fetch();
if ($b['check_out'] > today()) { $bad('Vous pourrez donner votre avis à la fin de votre séjour, le ' . e(fr_date($b['check_out'])) . '. Bon séjour au ' . e($r['name']) . ' !'); exit; }
$done = q('SELECT * FROM reviews WHERE booking_id = ?', [$b['id']])->fetch();
if ($done) { $bad('Merci ' . e($b['first_name']) . ', votre avis sur <b>' . e($r['name']) . '</b> a bien été enregistré.'); exit; }
if ($b['check_out'] < date('Y-m-d', strtotime('-' . REVIEW_DAYS . ' days'))) { $bad('Le délai pour donner votre avis sur ce séjour est dépassé (' . REVIEW_DAYS . ' jours après le départ).'); exit; }

$crit = REVIEW_CRITERIA; if (!$r['breakfast']) unset($crit['breakfast']);
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes = [];
    foreach (array_keys($crit) as $k) { $v = (int)($_POST[$k] ?? 0); if ($v < 1 || $v > 5) { $err = 'Merci de donner une note pour chaque point (de 1 à 5 étoiles).'; break; } $notes[$k] = $v; }
    $txt = fn($k) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, 1000);
    if (!$err && !in_array($_POST['recommend'] ?? '', ['1', '0'], true)) $err = 'Recommanderiez-vous ce riad ? Répondez oui ou non.';
    if (!$err) {
        $score = round(array_sum($notes) / count($notes) * 2, 1);
        q('INSERT INTO reviews (booking_id, riad_id, staff, clean, comfort, location, value, breakfast, score, recommend, liked, disliked, private_note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $b['id'], $r['id'], $notes['staff'], $notes['clean'], $notes['comfort'], $notes['location'], $notes['value'], $notes['breakfast'] ?? null,
            $score, (int)$_POST['recommend'], $txt('liked'), $txt('disliked'), $txt('private_note'), now()]);
        // Le riad est prévenu ; l'équipe aussi si la note est basse ou s'il y a un message privé
        $owner = q('SELECT email FROM users WHERE id = ?', [$r['owner_id']])->fetch();
        $sum = '<p>Note : <b>' . number_format($score, 1, ',', '') . ' / 10</b> (' . e(score_word($score)) . ') · ' . e($b['first_name']) . ', séjour du ' . e(fr_date($b['check_in'])) . '.</p>'
             . ($txt('liked') !== '' ? '<p>👍 ' . nl2br(e($txt('liked'))) . '</p>' : '') . ($txt('disliked') !== '' ? '<p>👎 ' . nl2br(e($txt('disliked'))) . '</p>' : '');
        if ($owner) send_mail($owner['email'], 'Nouvel avis client · ' . $r['name'], mail_layout('Un voyageur a donné son avis', $sum . mail_button(base_url() . 'reviews.php', 'Voir tous vos avis')));
        if ($score < 6 || $txt('private_note') !== '' || $_POST['recommend'] === '0')
            send_mail(admin_email(), '[Avis] ' . ($score < 6 ? 'Note basse ' : '') . number_format($score, 1, ',', '') . '/10 · ' . $r['name'] . ' · ' . $b['ref'],
                mail_layout('Avis à regarder', $sum . ($txt('private_note') !== '' ? '<p><b>Message privé pour StayInRiad :</b><br>' . nl2br(e($txt('private_note'))) . '</p>' : '') . '<p>Voyageur : ' . e($b['first_name'] . ' ' . $b['last_name']) . ' · ' . e($b['email']) . ' · ' . e($b['phone']) . '</p>'));
        render_page('Merci', '<div class="card"><h1>Merci ' . e($b['first_name']) . ' !</h1><p>Votre avis sur <b>' . e($r['name']) . '</b> a bien été enregistré. Il aide le riad à s\'améliorer et les prochains voyageurs à choisir.</p><p>À bientôt au Maroc,<br>L\'équipe stayinriad.com</p><p><a class="btn" href="riads.php">Voir d\'autres riads</a></p></div>');
        exit;
    }
}

$stars = function (string $k, string $label) {
    $cur = (int)($_POST[$k] ?? 0);
    $opts = '';
    for ($i = 5; $i >= 1; $i--) $opts .= '<input type="radio" id="' . $k . $i . '" name="' . $k . '" value="' . $i . '"' . ($cur === $i ? ' checked' : '') . ' required><label for="' . $k . $i . '" title="' . $i . ' / 5">★</label>';
    return '<div class="rate"><span>' . e($label) . '</span><div class="stars">' . $opts . '</div></div>';
};
$v = fn($k) => e($_POST[$k] ?? '');
render_page('Votre avis', '<div class="card"><h1>Votre séjour au ' . e($r['name']) . '</h1>
<p class="note">' . e($b['room_name']) . ' · ' . e(fr_date($b['check_in'])) . ' → ' . e(fr_date($b['check_out'])) . '</p>
' . ($err ? '<div class="flash bad">' . e($err) . '</div>' : '') . '
<form method="post">' . csrf_field() . '
<p><b>Notez chaque point</b> (5 étoiles = parfait) :</p>
' . implode('', array_map(fn($k) => $stars($k, $crit[$k]), array_keys($crit))) . '
<label>Recommanderiez-vous ce riad à un ami ?</label>
<div class="yn"><label class="check"><input type="radio" name="recommend" value="1"' . (($_POST['recommend'] ?? '') === '1' ? ' checked' : '') . ' required> Oui</label><label class="check"><input type="radio" name="recommend" value="0"' . (($_POST['recommend'] ?? '') === '0' ? ' checked' : '') . '> Non</label></div>
<label>Ce que vous avez aimé</label><textarea name="liked" maxlength="1000" rows="3" placeholder="ex. le patio, le petit-déjeuner sur la terrasse, l\'accueil de Fatima…">' . $v('liked') . '</textarea>
<label>Ce qui pourrait être amélioré</label><textarea name="disliked" maxlength="1000" rows="3">' . $v('disliked') . '</textarea>
<label>Un message pour l\'équipe StayInRiad <span class="note">(privé, le riad ne le voit pas)</span></label><textarea name="private_note" maxlength="1000" rows="2">' . $v('private_note') . '</textarea>
<button class="btn full" style="margin-top:14px">Envoyer mon avis</button>
<p class="note">Votre prénom et vos notes sont transmis au riad. Votre message privé est lu uniquement par StayInRiad.</p>
</form></div>');
