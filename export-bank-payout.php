<?php
// Bank payout BANKLIST (.xlsx) — one row per employee with account no, split name and net pay,
// for upload to the bank's disbursement facility.
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login']) && empty($_SESSION['login_id'])) {
    http_response_code(403);
    exit('Not authorized.');
}
$conn = include 'db_connect.php';
require_page_access('bank-payout', 'text');
require_once __DIR__ . '/includes/paysheet.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$id) { http_response_code(400); exit('Missing payroll id'); }

$pay = $conn->query("SELECT ref_no, type, date_from, date_to FROM payroll WHERE id = $id")->fetch_assoc();
if (!$pay) { http_response_code(404); exit('Payroll not found'); }

// BANKLIST layout: # | Bank Account No | LName | FName | MName | Amount,
// alphabetical by last name.
$q = $conn->prepare("
    SELECT pi.employee_id, e.lastname, e.firstname, e.middlename, e.bank_account_no
    FROM payroll_items pi
    INNER JOIN employee e ON e.id = pi.employee_id
    WHERE pi.payroll_id = ?
    GROUP BY pi.employee_id
    ORDER BY e.lastname ASC, e.firstname ASC, e.middlename ASC
");
$q->bind_param('i', $id);
$q->execute();
$res = $q->get_result();
// Same net as the payroll sheet / payslip; Non-ATM (cash-paid) employees are not in it.
$nets = paysheet_atm_net_by_employee($conn, $id);

// .xlsx, not CSV: Excel reads a CSV account number as a number, shows it as
// 1.23457E+15 and zeroes every digit past the 15th — the bank then gets the
// wrong account. Here the account number is written as TEXT, digit for digit.
require 'vendor/autoload.php';

$fname = 'banklist-' . preg_replace('/[^A-Za-z0-9\-]/', '', $pay['ref_no']) . '-' . date('Ymd', strtotime($pay['date_from'])) . '.xlsx';

$ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Banklist');

// Title block (red, bold, centred across the table).
$sheet->setCellValue('A1', 'BANKLIST');
$sheet->setCellValue('A2', paysheet_banklist_title($pay));
foreach (['A1:F1', 'A2:F2'] as $rng) {
    $sheet->mergeCells($rng);
    $st = $sheet->getStyle($rng);
    $st->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('E8253A');
    $st->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
}

$sheet->fromArray(['', 'Bank Account No', 'LName', 'FName', 'MName', 'Amount'], null, 'A4');
$sheet->getStyle('A4:F4')->getFont()->setBold(true);
$sheet->getStyle('A4:F4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E7E6E6');

$STR = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
$total = 0;
$n = 0;
$row = 5;
while ($r = $res->fetch_assoc()) {
    if (!isset($nets[(int) $r['employee_id']])) continue;   // Non-ATM
    $net = $nets[(int) $r['employee_id']];
    $total += $net;
    $sheet->setCellValue('A' . $row, ++$n);
    $sheet->setCellValueExplicit('B' . $row, (string) ($r['bank_account_no'] ?? ''), $STR);
    $sheet->setCellValue('C' . $row, strtoupper(trim((string) $r['lastname'])));
    $sheet->setCellValue('D' . $row, strtoupper(trim((string) $r['firstname'])));
    $sheet->setCellValue('E' . $row, strtoupper(trim((string) ($r['middlename'] ?? ''))));
    $sheet->setCellValue('F' . $row, $net);
    $row++;
}
$sheet->setCellValue('E' . $row, 'TOTAL');
$sheet->setCellValue('F' . $row, round($total, 2));
$sheet->getStyle('E' . $row . ':F' . $row)->getFont()->setBold(true);

// Text format on the whole column too, so a number typed in later stays text.
$sheet->getStyle('B5:B' . max(5, $row - 1))->getNumberFormat()->setFormatCode('@');
$sheet->getStyle('F5:F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getColumnDimension('A')->setWidth(6);
foreach (range('B', 'F') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: max-age=0');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
exit;
