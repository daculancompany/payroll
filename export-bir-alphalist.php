<?php
// BIR Alphalist CSV — same figures as the on-screen report, one row per
// employee for the calendar year. Import-friendly for the BIR Alphalist
// Data Entry / e-submission transcription.
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login']) && empty($_SESSION['login_id'])) {
    http_response_code(403);
    exit('Not authorized.');
}
$conn = include 'db_connect.php';

$al_year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
const BIR_13TH_CAP = 90000.0;

// Gov't contribution ids (SSS/PHIC/HDMF aliases).
$gov_ids = [];
$cq = $conn->query("SELECT id, contribution FROM contributions");
if ($cq) while ($c = $cq->fetch_assoc()) {
    $n = strtoupper($c['contribution']);
    if (strpos($n, 'SSS') !== false || strpos($n, 'PHIC') !== false || strpos($n, 'PHIL') !== false
        || strpos($n, 'HDMF') !== false || strpos($n, 'PAG') !== false) {
        $gov_ids[(int) $c['id']] = true;
    }
}

$m13 = [];
$tq = $conn->query("SELECT employee_id, amount, override_amount FROM thirteenth_month WHERE year = $al_year");
if ($tq) while ($t = $tq->fetch_assoc()) {
    $m13[(int) $t['employee_id']] = $t['override_amount'] !== null ? (float) $t['override_amount'] : (float) $t['amount'];
}

$rows = [];
$iq = $conn->query("
    SELECT pi.*, p.date_from AS pf, p.date_to AS pt,
           e.tin_no, e.employee_no, e.lastname, e.firstname, e.middlename
    FROM payroll_items pi
    INNER JOIN payroll p ON p.id = pi.payroll_id
    INNER JOIN employee e ON e.id = pi.employee_id
    WHERE YEAR(p.date_from) = $al_year
    ORDER BY e.lastname ASC, e.firstname ASC");
if ($iq) while ($r = $iq->fetch_assoc()) {
    $eid = (int) $r['employee_id'];
    if (!isset($rows[$eid])) {
        $rows[$eid] = [
            'lastname' => $r['lastname'], 'firstname' => $r['firstname'], 'middlename' => $r['middlename'],
            'employee_no' => $r['employee_no'], 'tin' => trim((string) $r['tin_no']),
            'from' => $r['pf'], 'to' => $r['pt'],
            'gross' => 0.0, 'gov' => 0.0, 'tax' => 0.0, 'adj' => 0.0,
        ];
    }
    $a = &$rows[$eid];
    if ($r['pf'] < $a['from']) $a['from'] = $r['pf'];
    if ($r['pt'] > $a['to'])   $a['to'] = $r['pt'];
    $perMin = payroll_per_minute($r);
    $allow = $r['allowance_amount'] * $r['allowance_days'];
    $ot = $r['ot'] * $r['ot_rate'];
    $late = $r['late'] * $perMin;
    $rt = $r['rate_type'] ?? 'daily';
    if ($rt === 'monthly' || $rt === 'fixed') {
        $gross = (($r['basic_pay'] + $allow - $r['absent'] * $r['per_day']) / 2) + $ot
               + $r['legal_holiday'] * $r['per_day'] + $r['sunday_duty'] * $r['per_day']
               + ($r['per_day'] / 8 * 2.4) * $r['special_holiday'] - $late;
    } else {
        $gross = ($r['present'] + (float) ($r['paid_leave'] ?? 0)) * $r['per_day'] + $ot + $allow - $late;
    }
    $a['gross'] += $gross;
    $a['adj']   += (float) ($r['adjustment'] ?? 0);
    $a['tax']   += (float) $r['tax'];
    $cons = json_decode($r['contributions'], true) ?: [];
    foreach ($cons as $c) {
        if (isset($gov_ids[(int) ($c['contribution_id'] ?? 0)])) $a['gov'] += (float) ($c['amount'] ?? 0);
    }
    unset($a);
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="bir-alphalist-' . $al_year . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Seq', 'TIN', 'Last Name', 'First Name', 'Middle Name', 'Employee No', 'Period From', 'Period To',
    'Gross Compensation', '13th Month & Benefits', 'Non-Taxable 13th (<=90k)', 'Taxable 13th (excess)',
    'Govt Contributions (SSS/PHIC/HDMF)', 'Total Non-Taxable', 'Taxable Compensation', 'Tax Withheld']);

$i = 0;
$T = array_fill_keys(['gross', 'g13', 'nt13', 'tx13', 'gov', 'nontax', 'taxable', 'tax'], 0.0);
foreach ($rows as $eid => $a) {
    $i++;
    $g13 = $m13[$eid] ?? 0.0;
    $nt13 = min($g13, BIR_13TH_CAP);
    $tx13 = max(0, $g13 - BIR_13TH_CAP);
    $total_gross = $a['gross'] + $a['adj'] + $g13;
    $nontax = $a['gov'] + $nt13;
    $taxable = max(0, $total_gross - $nontax);
    fputcsv($out, [
        $i, $a['tin'] !== '' ? $a['tin'] : 'NO TIN', $a['lastname'], $a['firstname'], $a['middlename'], $a['employee_no'],
        date('m/d/Y', strtotime($a['from'])), date('m/d/Y', strtotime($a['to'])),
        number_format($total_gross, 2, '.', ''), number_format($g13, 2, '.', ''),
        number_format($nt13, 2, '.', ''), number_format($tx13, 2, '.', ''),
        number_format($a['gov'], 2, '.', ''), number_format($nontax, 2, '.', ''),
        number_format($taxable, 2, '.', ''), number_format($a['tax'], 2, '.', ''),
    ]);
    $T['gross'] += $total_gross; $T['g13'] += $g13; $T['nt13'] += $nt13; $T['tx13'] += $tx13;
    $T['gov'] += $a['gov']; $T['nontax'] += $nontax; $T['taxable'] += $taxable; $T['tax'] += $a['tax'];
}
fputcsv($out, ['', '', '', '', '', '', '', 'TOTAL',
    number_format($T['gross'], 2, '.', ''), number_format($T['g13'], 2, '.', ''),
    number_format($T['nt13'], 2, '.', ''), number_format($T['tx13'], 2, '.', ''),
    number_format($T['gov'], 2, '.', ''), number_format($T['nontax'], 2, '.', ''),
    number_format($T['taxable'], 2, '.', ''), number_format($T['tax'], 2, '.', '')]);
fclose($out);
exit;
