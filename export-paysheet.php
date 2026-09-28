<?php
/**
 * Excel export of the paysheet (by department) — built cell by cell with
 * PhpSpreadsheet so it matches the Sheet1 layout of PAYSHEET SAMPLE.xls:
 * yellow bold wrapped header, SSS header merged over two columns, Arial 10,
 * #,##0.00, "DEPT n | name" rows, TOTAL rows and a Grand Total.
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

$cols    = PAYSHEET_COLUMNS;
$keys    = array_keys($cols);
$lastCol = Coordinate::stringFromColumnIndex(count($keys));
$colOf   = fn($k) => Coordinate::stringFromColumnIndex(array_search($k, $keys, true) + 1);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Sheet1');
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

// ── Header row ──────────────────────────────────────────────────────────────
foreach ($keys as $i => $k) {
    $label = $k === 'rate' || $k === 'mpf' ? '' : $cols[$k][0];
    $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1) . '1', $label, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
}
$sheet->mergeCells($colOf('sss') . '1:' . $colOf('mpf') . '1');
$sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
    'font'      => ['bold' => true],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF00']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);
$sheet->getRowDimension(1)->setRowHeight(40);

// ── Body ────────────────────────────────────────────────────────────────────
$r = 2;
$moneyKeys = array_keys(array_filter($cols, fn($c) => $c[1]));
$putMoney = function (array $vals, int $row, array $only) use ($sheet, $colOf) {
    foreach ($only as $k) $sheet->setCellValue($colOf($k) . $row, round((float) $vals[$k], 2));
};
foreach ($ps['groups'] as $g) {
    $sheet->setCellValue("A$r", paysheet_dept_label($g));
    $sheet->setCellValue("B$r", $g['name']);
    $sheet->getStyle("A$r:B$r")->getFont()->setBold(true);
    $r++;
    foreach ($g['rows'] as $line) {
        // Employee numbers keep their leading zeros ("0907256").
        $sheet->setCellValueExplicit("A$r", $line['emp_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue("B$r", $line['name']);
        $putMoney($line, $r, $moneyKeys);
        $r++;
    }
    $sheet->setCellValue("A$r", 'TOTAL');
    $sheet->setCellValue("B$r", $g['name']);
    $putMoney($g['total'], $r, paysheet_sum_keys());
    $sheet->getStyle("A$r:{$lastCol}$r")->applyFromArray([
        'font'    => ['bold' => true],
        'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_MEDIUM]],
    ]);
    $r++;
}
if ($ps['groups']) {
    $sheet->setCellValue($colOf('rate') . $r, 'Grand Total');
    $putMoney($ps['grand'], $r, paysheet_sum_keys());
    $sheet->getStyle("A$r:{$lastCol}$r")->applyFromArray([
        'font'    => ['bold' => true],
        'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF7C2']],
        'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
    ]);
}
$lastRow = max(1, $r);

// Money format + alignment on every numeric column.
foreach ($moneyKeys as $k) {
    $c = $colOf($k);
    $sheet->getStyle("{$c}2:{$c}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("{$c}2:{$c}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
}

// Column widths from the sample sheet.
$widths = [9, 37, 12, 11, 10, 13, 14, 12, 11, 11, 10, 13, 12, 10, 10, 12, 12];
foreach ($widths as $i => $w) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);

$sheet->freezePane('C2');

// Print setup — legal landscape, one page wide, header row repeated.
$pageSetup = $sheet->getPageSetup();
$pageSetup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$pageSetup->setPaperSize(PageSetup::PAPERSIZE_LEGAL);
$pageSetup->setFitToWidth(1);
$pageSetup->setFitToHeight(0);
$pageSetup->setRowsToRepeatAtTopByStartAndEnd(1, 1);
$sheet->getHeaderFooter()->setOddHeader('&L&BPAYSHEET  ' . $ps['period'] . '&R&P / &N');

$spreadsheet->getProperties()
    ->setTitle('Paysheet ' . $ps['period'])
    ->setSubject('Paysheet by department')
    ->setCreator($_SESSION['login_name'] ?? 'Payroll');

$fileName = 'paysheet_' . $id . '_' . preg_replace('/[^A-Za-z0-9]+/', '-', $ps['period']) . '.xlsx';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
