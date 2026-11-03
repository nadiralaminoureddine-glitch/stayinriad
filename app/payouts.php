<?php
// Versements aux riads : chaque lundi, pour les séjours terminés la semaine précédente.
// Admin : virements à faire + ordre de virement + « marquer comme versé ». Riad : à venir et historique.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/riad.php';
require __DIR__ . '/inc/booking.php';

$u = require_login();
if (!in_array($u['role'], ['riad', 'admin'], true)) redirect('account.php');
$adminAll = $u['role'] === 'admin' && empty($_GET['id']);

if ($adminAll) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'paid') {
        $rid = (int)$_POST['riad_id']; $r = q('SELECT * FROM riads WHERE id = ?', [$rid])->fetch();
        $due = payable_bookings($rid);
        if (!$r || $r['bank_status'] !== 'vérifié') flash('Virement impossible : RIB non vérifié.', 'bad');
        elseif (!$due) flash('Rien à verser pour ce riad.', 'warn');
        else {
            $eurT = array_sum(array_map(fn($b) => (float)$b['net'], $due)); $madT = array_sum(array_map(fn($b) => (float)$b['net_mad'], $due));
            db()->beginTransaction();
            q('INSERT INTO payouts (riad_id, amount_eur, amount_mad, refs, bank_rib, bank_ref, paid_at) VALUES (?,?,?,?,?,?,?)', [$rid, $eurT, $madT, implode(' ', array_column($due, 'ref')), $r['bank_rib'], mb_substr(trim((string)($_POST['bank_ref'] ?? '')), 0, 80), now()]);
            $pid = (int)db()->lastInsertId();
            foreach ($due as $b) q('UPDATE bookings SET payout_id = ? WHERE id = ?', [$pid, $b['id']]);
            db()->commit();
            $owner = q('SELECT email, first_name FROM users WHERE id = ?', [$r['owner_id']])->fetch();
            if ($owner) send_mail($owner['email'], 'Versement StayInRiad : ' . mad($madT), mail_layout('Votre versement est parti', '<p>Bonjour ' . e($owner['first_name']) . ',</p><p>Nous venons d\'effectuer un virement de <b>' . e(mad($madT)) . '</b> sur votre compte ' . e(mask_rib($r['bank_rib'])) . '.</p>' . mail_rows(['Réservations' => e(implode(', ', array_column($due, 'ref'))), 'Montant' => e(mad($madT)) . ' (' . e(eur($eurT)) . ')']) . '<p style="font-size:14px">Selon les banques, le virement apparaît sous 1 à 2 jours ouvrés. Le détail est dans votre espace, onglet « Versements ».</p>'));
            log_action('versement', $r['name'] . ' · ' . mad($madT) . ' · ' . implode(' ', array_column($due, 'ref')));
            flash('Versement enregistré pour ' . $r['name'] . ' : ' . mad($madT) . '. Le riad est prévenu par email.');
        }
        redirect('payouts.php');
    }
    $due = payable_bookings();
    $by = []; foreach ($due as $b) $by[$b['riad_id']][] = $b;
    $blocks = '';
    foreach ($by as $rid => $l) {
        $r = q('SELECT * FROM riads WHERE id = ?', [$rid])->fetch();
        $eurT = array_sum(array_map(fn($b) => (float)$b['net'], $l)); $madT = array_sum(array_map(fn($b) => (float)$b['net_mad'], $l));
        $ok = $r['bank_status'] === 'vérifié' && rib_ok($r['bank_rib']);
        $order = "Bénéficiaire : {$r['bank_holder']}\nBanque : {$r['bank_name']}\nRIB : " . trim(chunk_split($r['bank_rib'], 4, ' ')) . "\nIBAN : " . iban_from_rib($r['bank_rib']) . ($r['bank_swift'] ? "\nSWIFT : {$r['bank_swift']}" : '') . "\nMontant : " . number_format($madT, 2, ',', ' ') . " MAD\nMotif : StayInRiad versement " . implode(' ', array_column($l, 'ref'));
        $blocks .= '<div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><h2 style="margin:0">' . e($r['name']) . '</h2><span class="note">' . e($r['bank_holder']) . ' · ' . e($r['bank_name']) . '</span><br>' . bank_pill($r) . '</div><div style="text-align:right"><b style="font-size:22px;color:var(--green)">' . e(mad($madT)) . '</b><br><span class="note">' . e(eur($eurT)) . ' · ' . count($l) . ' réservation(s)</span></div></div>
          <div class="scroll"><table><thead><tr><th>N°</th><th>Séjour</th><th class="num">Payé client</th><th class="num">Commission</th><th class="num">Net</th></tr></thead><tbody>' . implode('', array_map(fn($b) => '<tr><td>' . e($b['ref']) . ($b['status'] === 'annulée' ? ' <span class="pill info">frais d\'annulation</span>' : '') . '</td><td>' . e(fr_date($b['check_in'])) . ' → ' . e(fr_date($b['check_out'])) . '</td><td class="num">' . eur($b['status'] === 'annulée' ? (float)$b['penalty'] : (float)$b['total']) . '</td><td class="num">' . eur((float)$b['commission']) . '</td><td class="num"><b>' . mad((float)$b['net_mad']) . '</b></td></tr>', $l)) . '</tbody></table></div>'
          . ($ok ? '<label>Ordre de virement (à copier dans votre banque en ligne)</label><textarea readonly rows="7" style="width:100%;font-family:monospace;font-size:13px;border:1px solid #cdbfa9;border-radius:8px;padding:10px">' . e($order) . '</textarea>
             <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px">' . csrf_field() . '<input type="hidden" name="riad_id" value="' . (int)$rid . '"><div><label>Référence du virement (facultatif)</label><input name="bank_ref" maxlength="80" style="max-width:240px"></div><button class="btn sm" name="action" value="paid">Marquer comme versé</button></form>'
             : '<div class="flash bad" style="margin-top:10px">Virement bloqué : ' . ($r['bank_rib'] ? 'RIB non vérifié' : 'RIB manquant') . '. <a href="admin_riad.php?id=' . (int)$rid . '">Vérifier le RIB</a></div>') . '</div>';
    }
    $hist = q('SELECT p.*, r.name FROM payouts p JOIN riads r ON r.id = p.riad_id ORDER BY p.paid_at DESC LIMIT 50')->fetchAll();
    $upcoming = payable_bookings(null, false);
    render_page('Versements', '<p><a href="admin.php">← Administration</a></p><h1>Versements aux riads</h1>
    <div class="kpis"><div class="kpi"><small>À verser maintenant</small><b>' . e(mad(array_sum(array_map(fn($b) => (float)$b['net_mad'], $due)))) . '</b></div><div class="kpi"><small>Riads concernés</small><b>' . count($by) . '</b></div><div class="kpi"><small>Prochain lundi</small><b style="font-size:18px">' . e(fr_date(next_pay_day())) . '</b></div><div class="kpi"><small>À venir (séjours non terminés)</small><b style="font-size:18px">' . e(mad(array_sum(array_map(fn($b) => (float)$b['net_mad'], $upcoming)) - array_sum(array_map(fn($b) => (float)$b['net_mad'], $due)))) . '</b></div></div>
    <div class="flash warn">📅 Chaque <b>lundi</b> : un virement par riad pour tous les clients partis du lundi au dimanche précédents. Montant en dirhams au taux fixé le jour de chaque réservation. Virement uniquement vers un RIB vérifié.</div>'
    . ($blocks ?: '<div class="card"><p class="note" style="margin:0">✅ Rien à verser pour l\'instant.</p></div>')
    . '<div class="card"><h2 style="margin-top:0">Historique</h2>' . ($hist ? '<div class="scroll"><table><thead><tr><th>Date</th><th>Riad</th><th class="num">Montant</th><th>Réservations</th><th>Réf.</th></tr></thead><tbody>' . implode('', array_map(fn($p) => '<tr><td>' . e(date('d/m/Y', strtotime($p['paid_at'] . ' UTC'))) . '</td><td>' . e($p['name']) . '</td><td class="num"><b>' . e(mad((float)$p['amount_mad'])) . '</b></td><td class="note">' . e($p['refs']) . '</td><td>' . e($p['bank_ref']) . '</td></tr>', $hist)) . '</tbody></table></div>' : '<p class="note">Aucun versement.</p>') . '</div>');
    exit;
}

// ----- Vue riad -----
$r = my_riad($u, isset($_GET['id']) ? (int)$_GET['id'] : null); if (!$r) redirect('account.php');
$q = $u['role'] === 'admin' ? '?id=' . (int)$r['id'] : '';
$all = payable_bookings((int)$r['id'], false);
$due = array_filter($all, fn($b) => pay_day($b['check_out']) <= today());
$later = array_filter($all, fn($b) => pay_day($b['check_out']) > today());
$hist = q('SELECT * FROM payouts WHERE riad_id = ? ORDER BY paid_at DESC', [$r['id']])->fetchAll();
$sum = fn($l) => array_sum(array_map(fn($b) => (float)$b['net_mad'], $l));
render_page('Versements', '<h1>' . e($r['name']) . ' ' . status_pill($r['status']) . '</h1>' . riad_tabs('pay', $q) . '
<div class="kpis"><div class="kpi"><small>Prochain versement · ' . e(fr_date(next_pay_day())) . '</small><b>' . e(mad($sum($due))) . '</b></div><div class="kpi"><small>À venir</small><b>' . e(mad($sum($later))) . '</b></div><div class="kpi"><small>Déjà versé</small><b>' . e(mad(array_sum(array_map(fn($p) => (float)$p['amount_mad'], $hist)))) . '</b></div></div>
<div class="card"><p style="margin-top:0">📅 <b>Versement chaque lundi</b>, en dirhams, pour tous vos clients partis du lundi au dimanche précédents. Le montant en dirhams est fixé dès la réservation.</p><p class="note">Compte : ' . ($r['bank_rib'] ? e(mask_rib($r['bank_rib'])) . ' ' . bank_pill($r) : '<b>aucun RIB</b> : <a href="riad.php' . e($q) . '">ajoutez-le dans la fiche</a>') . '</p></div>
<div class="card"><h2 style="margin-top:0">À venir</h2>' . ($all ? '<div class="scroll"><table><thead><tr><th>N°</th><th>Séjour</th><th class="num">Net</th><th>Versé le</th></tr></thead><tbody>' . implode('', array_map(fn($b) => '<tr><td>' . e($b['ref']) . '</td><td>' . e(fr_date($b['check_in'])) . ' → ' . e(fr_date($b['check_out'])) . '</td><td class="num"><b>' . e(mad((float)$b['net_mad'])) . '</b><br><span class="note">' . e(eur((float)$b['net'])) . '</span></td><td>' . e(fr_date(max(pay_day($b['check_out']), next_pay_day()))) . '</td></tr>', $all)) . '</tbody></table></div>' : '<p class="note">Rien à venir pour l\'instant.</p>') . '</div>
<div class="card"><h2 style="margin-top:0">Historique des virements</h2>' . ($hist ? '<div class="scroll"><table><thead><tr><th>Date</th><th class="num">Montant</th><th>Réservations</th></tr></thead><tbody>' . implode('', array_map(fn($p) => '<tr><td>' . e(date('d/m/Y', strtotime($p['paid_at'] . ' UTC'))) . '</td><td class="num"><b>' . e(mad((float)$p['amount_mad'])) . '</b></td><td class="note">' . e($p['refs']) . '</td></tr>', $hist)) . '</tbody></table></div>' : '<p class="note">Aucun versement pour l\'instant.</p>') . '</div>');
