<?php
// StayInRiad — relevé mensuel : réservations, commissions et versements d'un riad (ou de tous) pour un mois.
// Un séjour compte dans le mois de son DÉPART (c'est à ce moment qu'il est dû au riad).
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const MONTHS_FR = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
function month_label(string $m): string { return MONTHS_FR[(int)substr($m, 5, 2) - 1] . ' ' . substr($m, 0, 4); }
function valid_month(?string $m, string $default): string { return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$m) ? $m : $default; }

/** Lignes du relevé : séjours terminés dans le mois + annulations avec frais (comptées au mois de la date d'arrivée prévue). */
function statement_rows(string $m, ?int $riadId = null): array {
    $from = $m . '-01'; $to = date('Y-m-d', strtotime($from . ' +1 month'));
    $sql = "SELECT b.*, r.name AS riad_name, p.paid_at AS payout_at, p.bank_ref AS payout_ref FROM bookings b JOIN riads r ON r.id = b.riad_id LEFT JOIN payouts p ON p.id = b.payout_id
            WHERE ((b.status = 'confirmée' AND b.check_out >= ? AND b.check_out < ?) OR (b.status = 'annulée' AND b.penalty > 0 AND b.check_in >= ? AND b.check_in < ?))"
         . ($riadId ? ' AND b.riad_id = ?' : '') . ' ORDER BY r.name, b.check_out, b.id';
    $rows = q($sql, $riadId ? [$from, $to, $from, $to, $riadId] : [$from, $to, $from, $to])->fetchAll();
    foreach ($rows as &$b) {
        $b['amount'] = $b['status'] === 'confirmée' ? (float)$b['total'] : (float)$b['penalty'];
        $b['paid'] = $b['payout_id'] !== null;
        $b['label'] = $b['status'] === 'confirmée' ? 'Séjour' : 'Frais d\'annulation';
        $b['test'] = str_starts_with((string)$b['payment_ref'], 'TEST-');
    }
    return $rows;
}
/** Totaux d'une liste de lignes. */
function statement_totals(array $rows): array {
    $t = ['n' => 0, 'nights' => 0, 'amount' => 0.0, 'commission' => 0.0, 'net' => 0.0, 'net_mad' => 0.0, 'paid_mad' => 0.0, 'due_mad' => 0.0, 'cancelled' => 0];
    foreach ($rows as $b) {
        $t['n']++; if ($b['status'] === 'confirmée') $t['nights'] += (int)$b['nights']; else $t['cancelled']++;
        $t['amount'] += $b['amount']; $t['commission'] += (float)$b['commission']; $t['net'] += (float)$b['net']; $t['net_mad'] += (float)$b['net_mad'];
        $t[$b['paid'] ? 'paid_mad' : 'due_mad'] += (float)$b['net_mad'];
    }
    return $t;
}
function statement_csv(array $rows, string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); // pour qu'Excel lise les accents
    fputcsv($o, ['Riad', 'Réservation', 'Type', 'Client', 'Chambre', 'Arrivée', 'Départ', 'Nuits', 'Montant payé (EUR)', 'Commission (%)', 'Commission (EUR)', 'Net riad (EUR)', 'Taux EUR/MAD', 'Net riad (MAD)', 'Versé le', 'Réf. virement'], ';');
    foreach ($rows as $b) fputcsv($o, [$b['riad_name'], $b['ref'] . ($b['test'] ? ' (TEST)' : ''), $b['label'], $b['first_name'] . ' ' . $b['last_name'], $b['room_name'], $b['check_in'], $b['check_out'], $b['status'] === 'confirmée' ? $b['nights'] : 0,
        number_format($b['amount'], 2, ',', ''), number_format((float)$b['commission_rate'], 2, ',', ''), number_format((float)$b['commission'], 2, ',', ''), number_format((float)$b['net'], 2, ',', ''),
        number_format((float)$b['eur_mad'], 4, ',', ''), number_format((float)$b['net_mad'], 2, ',', ''), $b['payout_at'] ? substr($b['payout_at'], 0, 10) : 'à venir', $b['payout_ref'] ?? ''], ';');
    fclose($o); exit;
}

/** Le 1er du mois : chaque riad reçoit son relevé du mois précédent, l'admin le récapitulatif. Une seule fois par mois. */
function send_monthly_statements(): array {
    if ((int)date('j') !== 1 || (int)date('G') < 8) return [];
    $m = date('Y-m', strtotime('first day of last month'));
    if (q("SELECT 1 FROM settings WHERE k = ?", ['statement_sent_' . $m])->fetch()) return [];
    q('INSERT INTO settings (k, v) VALUES (?, ?)', ['statement_sent_' . $m, now()]);
    $all = statement_rows($m); $by = [];
    foreach ($all as $b) $by[$b['riad_id']][] = $b;
    $log = [];
    foreach ($by as $rid => $rows) {
        $t = statement_totals($rows);
        $o = q('SELECT u.email, u.first_name, r.name FROM riads r JOIN users u ON u.id = r.owner_id WHERE r.id = ?', [$rid])->fetch();
        if (!$o) continue;
        send_mail($o['email'], 'Votre relevé StayInRiad · ' . month_label($m), mail_layout('Relevé de ' . month_label($m), '<p>Bonjour ' . e($o['first_name']) . ',</p><p>Voici le résumé du mois pour <b>' . e($o['name']) . '</b> (séjours terminés en ' . e(month_label($m)) . ').</p>'
            . mail_rows(['Réservations' => (string)$t['n'], 'Nuits' => (string)$t['nights'], 'Payé par les voyageurs' => e(eur($t['amount'])), 'Commission StayInRiad' => e(eur($t['commission'])), 'Net pour vous' => '<b>' . e(mad($t['net_mad'])) . '</b> (' . e(eur($t['net'])) . ')', 'Déjà versé' => e(mad($t['paid_mad'])), 'Reste à verser' => e(mad($t['due_mad']))])
            . mail_button(base_url() . 'statement.php?m=' . $m, 'Voir le relevé détaillé')));
        $log[] = 'relevé ' . $m . ' · ' . $o['name'];
    }
    $t = statement_totals($all);
    send_mail(admin_email(), '[Relevé] ' . month_label($m) . ' : ' . eur($t['commission']) . ' de commissions', mail_layout('Récapitulatif de ' . month_label($m), mail_rows(['Riads avec des séjours' => (string)count($by), 'Réservations' => (string)$t['n'], 'Nuits' => (string)$t['nights'], 'Payé par les voyageurs' => e(eur($t['amount'])), 'Commissions StayInRiad' => '<b>' . e(eur($t['commission'])) . '</b>', 'Net dû aux riads' => e(mad($t['net_mad'])), 'Reste à verser' => e(mad($t['due_mad']))]) . mail_button(base_url() . 'statement.php?m=' . $m, 'Voir le relevé de tous les riads')));
    return $log;
}
