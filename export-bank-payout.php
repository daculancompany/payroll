<?php
// Bank payout register (.xlsx) — one row per employee with bank, account no and net pay,
// for upload to the bank's disbursement facility.
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login']) && empty($_SESSION['login_id'])) {
    http_response_code(403);
    exit('Not authorized.');
}
$conn = include 'db_connect.php';
require_page_access('bank-payout', 'text');

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

// .xlsx, not CSV: Excel reads a CSV account number as a number, shows it as
// 1.23457E+15 and zeroes every digit past the 15th — the bank then gets the
// wrong account. Here the account number is written as TEXT, digit for digit.
require 'vendor/autoload.php';

$fname = 'bank-payout-' . preg_replace('/[^A-Za-z0-9\-]/', '', $pay['ref_no']) . '-' . date('Ymd', strtotime($pay['date_from'])) . '.xlsx';

$ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Bank Payout');
$sheet->fromArray(['Employee No', 'Employee Name', 'Bank', 'Account Number', 'Net Pay', 'Period'], null, 'A1');
$sheet->getStyle('A1:F1')->getFont()->setBold(true);

$STR = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
$period = date('M j', strtotime($pay['date_from'])) . '-' . date('M j, Y', strtotime($pay['date_to']));
$total = 0;
$row = 2;
while ($r = $res->fetch_assoc()) {
    $total += (float) $r['net'];
    $sheet->setCellValueExplicit('A' . $row, (string) $r['employee_no'], $STR);
    $sheet->setCellValue('B' . $row, $r['name']);
    $sheet->setCellValue('C' . $row, $r['bank_name'] ?: 'NO BANK');
    $sheet->setCellValueExplicit('D' . $row, (string) ($r['bank_account_no'] ?: ''), $STR);
    $sheet->setCellValue('E' . $row, round((float) $r['net'], 2));
    $sheet->setCellValue('F' . $row, $period);
    $row++;
}
$sheet->setCellValue('D' . $row, 'TOTAL');
$sheet->setCellValue('E' . $row, round($total, 2));
$sheet->getStyle('D' . $row . ':E' . $row)->getFont()->setBold(true);

// Text format on the whole column too, so a number typed in later stays text.
$sheet->getStyle('D2:D' . max(2, $row - 1))->getNumberFormat()->setFormatCode('@');
$sheet->getStyle('E2:E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
foreach (range('A', 'F') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: max-age=0');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
exit;
