<?php
// CSV export for the Government Remittance Report
session_start();
if (!isset($_SESSION['is_login']) || !$_SESSION['is_login']) { header('HTTP/1.0 403 Forbidden'); exit('Forbidden'); }
include 'db_connect.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { exit('Missing payroll id'); }

function rr_bucket($name) {
    $n = strtoupper(trim($name));
    if (strpos($n, 'SSS') !== false)        return 'SSS';
    if (strpos($n, 'PHIC') !== false || strpos($n, 'PHILHEALTH') !== false || strpos($n, 'PHIL') !== false) return 'PhilHealth';
    if (strpos($n, 'HDMF') !== false || strpos($n, 'PAG') !== false) return 'Pag-IBIG';
    return $name;
}

$st = $conn->prepare("SELECT ref_no, date_from, date_to FROM payroll WHERE id = ?");
$st->bind_param('i', $id); $st->execute();
$pay = $st->get_result()->fetch_assoc();
if (!$pay) { exit('Payroll not found'); }

$contrib_names = [];
$cn = $conn->query("SELECT id, contribution FROM contributions");
while ($c = $cn->fetch_assoc()) $contrib_names[(int)$c['id']] = $c['contribution'];

// Fallback amounts from employee_contributions
$ec_map = [];
$ecq = $conn->query("SELECT employee_id, contribution_id, MAX(amount) AS amount
                     FROM employee_contributions GROUP BY employee_id, contribution_id");
if ($ecq) while ($e = $ecq->fetch_assoc()) {
    $ec_map[(int)$e['employee_id']][(int)$e['contribution_id']] = (float)$e['amount'];
}

$it = $conn->prepare("
    SELECT pi.employee_id, e.employee_no, CONCAT(e.lastname, ', ', e.firstname) AS name,
           e.sss_no, e.ph_no, e.hdmf_no, e.tin_no,
           pi.contributions, pi.sss_fund, pi.tax
    FROM payroll_items pi INNER JOIN employee e ON e.id = pi.employee_id
    WHERE pi.payroll_id = ? ORDER BY e.lastname, e.firstname
");
$it->bind_param('i', $id); $it->execute();
$res = $it->get_result();

// Stream CSV
$fname = 'remittance_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $pay['ref_no'] ?: $id) . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

fputcsv($out, ['Government Remittance Report']);
fputcsv($out, ['Payroll', $pay['ref_no'], 'Period', date('M d, Y', strtotime($pay['date_from'])).' - '.date('M d, Y', strtotime($pay['date_to']))]);
fputcsv($out, []);
fputcsv($out, ['Employee No','Name','SSS No','PhilHealth No','Pag-IBIG No','TIN','SSS','PhilHealth','Pag-IBIG','SSS Fund','Tax','Total']);

$T = ['SSS'=>0,'PhilHealth'=>0,'Pag-IBIG'=>0,'fund'=>0,'tax'=>0,'grand'=>0];
while ($row = $res->fetch_assoc()) {
    $b = ['SSS'=>0,'PhilHealth'=>0,'Pag-IBIG'=>0];
    $json = json_decode($row['contributions'], true);
    if (is_array($json)) foreach ($json as $c) {
        $bk = rr_bucket($contrib_names[(int)($c['contribution_id'] ?? 0)] ?? 'Other');
        if (isset($b[$bk])) $b[$bk] += (float)($c['amount'] ?? 0);
    }
    // Fallback to employee_contributions if the payslip had no itemised contributions
    if ($b['SSS'] == 0 && $b['PhilHealth'] == 0 && $b['Pag-IBIG'] == 0 && isset($ec_map[(int)$row['employee_id']])) {
        foreach ($ec_map[(int)$row['employee_id']] as $cid => $amt) {
            $bk = rr_bucket($contrib_names[$cid] ?? 'Other');
            if (isset($b[$bk])) $b[$bk] += (float)$amt;
        }
    }
    $fund = (float)$row['sss_fund']; $tax = (float)$row['tax'];
    $total = $b['SSS'] + $b['PhilHealth'] + $b['Pag-IBIG'] + $fund + $tax;
    fputcsv($out, [
        $row['employee_no'], $row['name'], $row['sss_no'], $row['ph_no'], $row['hdmf_no'], $row['tin_no'],
        number_format($b['SSS'],2,'.',''), number_format($b['PhilHealth'],2,'.',''), number_format($b['Pag-IBIG'],2,'.',''),
        number_format($fund,2,'.',''), number_format($tax,2,'.',''), number_format($total,2,'.','')
    ]);
    $T['SSS']+=$b['SSS']; $T['PhilHealth']+=$b['PhilHealth']; $T['Pag-IBIG']+=$b['Pag-IBIG'];
    $T['fund']+=$fund; $T['tax']+=$tax; $T['grand']+=$total;
}
fputcsv($out, []);
fputcsv($out, ['','TOTAL','','','','',
    number_format($T['SSS'],2,'.',''), number_format($T['PhilHealth'],2,'.',''), number_format($T['Pag-IBIG'],2,'.',''),
    number_format($T['fund'],2,'.',''), number_format($T['tax'],2,'.',''), number_format($T['grand'],2,'.','')]);
fclose($out);
exit;
