<?php
/**
 * Excel export of the Department Summary ("PAYROLL SUMMARY" sheet) — the same
 * figures as the web view / PDF (paysheet_summary_html, print-payroll-dept.php):
 * one row per department total, NONATM last, TOTAL, then the ATM / NON ATM /
 * TOTAL net split, the O. DEDUCTIONS breakdown and the signature block.
 * Layout / figures: includes/paysheet.php.
 *
 * GET: id (payroll id).
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login'])) {
    http_response_code(403);
    exit('Not authorised.');
}
if (!isset($conn)) include 'db_connect.php';
require_page_access('payroll', 'text');
require 'vendor/autoload.php';
require_once __DIR__ . '/includes/paysheet.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$id = (int) ($_GET['id'] ?? 0);
$ps = $id > 0 ? paysheet_build($conn, $id) : ['payroll' => null];
if (!$ps['payroll']) {
    http_response_code(400);
    exit('Nothing to export.');
}
$payroll = $ps['payroll'];

$cols    = paysheet_summary_columns();
$keys    = array_keys($cols);
$lastCol = Coordinate::stringFromColumnIndex(count($keys) + 1);   // + DEPARTMENT column
$colOf   = fn($k) => Coordinate::stringFromColumnIndex(array_search($k, $keys, true) + 2);
$money   = '#,##0.00';
$thin    = ['borderStyle' => Border::BORDER_THIN];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('PAYROLL SUMMARY');
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

// ── Title ───────────────────────────────────────────────────────────────────
$titles = ['CDO Medical Center', 'PAYROLL REPORT', strtoupper($ps['period'])];
foreach ($titles as $i => $t) {
    $row = $i + 1;
    $sheet->setCellValue("A$row", $t);
    $sheet->mergeCells("A$row:{$lastCol}$row");
    $sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A$row")->getFont()->setBold($i < 2)->setSize($i === 0 ? 12 : 10);
}

// ── Header ──────────────────────────────────────────────────────────────────
$h = 5;
$sheet->setCellValue("A$h", 'DEPARTMENT');
foreach ($keys as $k) $sheet->setCellValue($colOf($k) . $h, $cols[$k]);
$sheet->getStyle("A$h:{$lastCol}$h")->applyFromArray([
    'font'      => ['bold' => true],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF00']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders'   => ['allBorders' => $thin],
]);
$sheet->getStyle("A$h")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getRowDimension($h)->setRowHeight(30);

// ── One row per department, then TOTAL ──────────────────────────────────────
$r = $h + 1;
foreach ($ps['groups'] as $g) {
    $sheet->setCellValue("A$r", $g['name']);
    $sheet->getStyle("A$r")->getFont()->setBold(true);
    foreach ($keys as $k) $sheet->setCellValue($colOf($k) . $r, round((float) $g['total'][$k], 2));
    $r++;
}
$sheet->setCellValue("A$r", 'TOTAL');
foreach ($keys as $k) $sheet->setCellValue($colOf($k) . $r, round((float) $ps['grand'][$k], 2));
$sheet->getStyle("A$r:{$lastCol}$r")->applyFromArray([
    'font'    => ['bold' => true],
    'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM]],
]);
$sheet->getStyle('A' . ($h + 1) . ":{$lastCol}$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('B' . ($h + 1) . ":{$lastCol}$r")->getNumberFormat()->setFormatCode($money);
$sheet->getStyle('B' . ($h + 1) . ":{$lastCol}$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$tableEnd = $r;

// ── Footer: payout split (left) and O. DEDUCTIONS breakdown (right) ─────────
$f = $tableEnd + 2;
$split = [['ATM', $ps['atm_net']], ['NON ATM', $ps['nonatm_net']], ['TOTAL', $ps['grand']['net']]];
foreach ($split as $i => [$label, $amt]) {
    $row = $f + $i;
    $sheet->setCellValue("A$row", $label);
    $sheet->setCellValue("B$row", round((float) $amt, 2));
    $sheet->getStyle("A$row:B$row")->getFont()->setBold(true);
    $sheet->getStyle("B$row")->getNumberFormat()->setFormatCode($money);
}
$sheet->getStyle('A' . ($f + 2) . ':B' . ($f + 2))->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('B' . ($f + 2))->getFont()->setUnderline(true);

$labelCol = $colOf('tax');      // breakdown sits under the right-hand columns
$amtCol   = $colOf('net');
$row = $f;
foreach ($ps['breakdown'] as $key => $amt) {
    if (abs($amt) < 0.005) continue;
    $sheet->setCellValue("$labelCol$row", PAYSHEET_DED_BUCKETS[$key]);
    $sheet->setCellValue("$amtCol$row", round((float) $amt, 2));
    $sheet->getStyle("$labelCol$row:$amtCol$row")->getFont()->setBold(true);
    $sheet->getStyle("$amtCol$row")->getNumberFormat()->setFormatCode($money);
    $row++;
}
$sheet->setCellValue("$labelCol$row", 'TOTAL O. DEDUCTIONS');
$sheet->setCellValue("$amtCol$row", round((float) $ps['grand']['other'], 2));
$sheet->getStyle("$labelCol$row:$amtCol$row")->getFont()->setBold(true);
$sheet->getStyle("$labelCol$row:$amtCol$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("$amtCol$row")->getNumberFormat()->setFormatCode($money);
$sheet->getStyle("$amtCol$row")->getFont()->setUnderline(true);

// ── Signatures (same blocks as the PDF) ─────────────────────────────────────
$s = max($f + 2, $row) + 3;
$sigs = [
    ['Prepared By:', $payroll['prepared_by'] ?? '', $payroll['prepared_by_role'] ?? ''],
    ['Verified By:', $payroll['verified_by'] ?? '', $payroll['verified_by_role'] ?? ''],
    ['Noted By:', '', ''],
    ['Checked By:', '', ''],
    ['Approved By:', $payroll['approved_by'] ?? '', $payroll['approved_by_role'] ?? ''],
];
$sigCols = ['A', 'D', 'G', 'J', 'M'];
foreach ($sigs as $i => [$label, $name, $role]) {
    $c = $sigCols[$i];
    $sheet->setCellValue("$c$s", $label);
    $sheet->setCellValue($c . ($s + 2), (string) $name);
    $sheet->setCellValue($c . ($s + 3), (string) $role);
    $sheet->getStyle($c . ($s + 2))->getFont()->setBold(true);
}

// ── Layout ──────────────────────────────────────────────────────────────────
$sheet->getColumnDimension('A')->setWidth(30);
foreach ($keys as $k) $sheet->getColumnDimension($colOf($k))->setWidth(14);
$sheet->freezePane('B' . ($h + 1));

$pageSetup = $sheet->getPageSetup();
$pageSetup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$pageSetup->setPaperSize(PageSetup::PAPERSIZE_LEGAL);
$pageSetup->setFitToWidth(1);
$pageSetup->setFitToHeight(0);

$spreadsheet->getProperties()
    ->setTitle('Payroll Summary ' . $ps['period'])
    ->setSubject('Department summary')
    ->setCreator($_SESSION['login_name'] ?? 'Payroll');

$fileName = 'dept-summary_' . $id . '_' . preg_replace('/[^A-Za-z0-9]+/', '-', $ps['period']) . '.xlsx';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
