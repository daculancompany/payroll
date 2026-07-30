<?php
// Bank payout CSV — one row per employee with bank, account no and net pay,
// for upload to the bank's disbursement facility.
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login']) && empty($_SESSION['login_id'])) {
    http_response_code(403);
    exit('Not authorized.');
}
$conn = include 'db_connect.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$id) { http_response_code(400); exit('Missing payroll id'); }

$pay = $conn->query("SELECT ref_no, date_from, date_to FROM payroll WHERE id = $id")->fetch_assoc();
if (!$pay) { http_response_code(404); exit('Payroll not found'); }

$q = $conn->prepare("
    SELECT e.employee_no, CONCAT(e.lastname, ', ', e.firstname) AS name,
           b.bank_name, e.bank_account_no, SUM(pi.net) AS net
    FROM payroll_items pi
    INNER JOIN employee e ON e.id = pi.employee_id
    LEFT JOIN banks b ON b.id = e.bank_id
    WHERE pi.payroll_id = ?
    GROUP BY pi.employee_id
    ORDER BY b.bank_name IS NULL, b.bank_name ASC, e.lastname ASC
");
$q->bind_param('i', $id);
$q->execute();
$res = $q->get_result();

$fname = 'bank-payout-' . preg_replace('/[^A-Za-z0-9\-]/', '', $pay['ref_no']) . '-' . date('Ymd', strtotime($pay['date_from'])) . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel opens names with accents correctly.
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Employee No', 'Employee Name', 'Bank', 'Account Number', 'Net Pay', 'Period']);
$period = date('M j', strtotime($pay['date_from'])) . '-' . date('M j, Y', strtotime($pay['date_to']));
$total = 0;
while ($r = $res->fetch_assoc()) {
    $total += (float) $r['net'];
    fputcsv($out, [
        $r['employee_no'],
        $r['name'],
        $r['bank_name'] ?: 'NO BANK',
        $r['bank_account_no'] ?: '',
        number_format((float) $r['net'], 2, '.', ''),
        $period,
    ]);
}
fputcsv($out, ['', '', '', 'TOTAL', number_format($total, 2, '.', ''), '']);
fclose($out);
exit;
