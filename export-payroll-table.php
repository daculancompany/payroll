<?php
/**
 * Excel export of the payroll workbench's Table View — the classic Excel-style
 * sheet in #modal-table-editor, colours and all.
 *
 * The browser posts the table it already rendered (with the palette inlined by
 * pcwExportTableExcel() in payroll_calculations.php) and PhpSpreadsheet's HTML
 * reader turns it into a real .xlsx. Going through the rendered DOM instead of
 * recomputing every figure server-side means the workbook can never disagree
 * with what the user is looking at — one source of truth for ~40 columns of
 * dynamic contribution / deduction / loan / refund arithmetic.
 *
 * POST: id (payroll id, for the filename) + html (the <table> markup).
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login'])) {
    http_response_code(403);
    exit('Not authorised.');
}
if (!isset($conn)) include 'db_connect.php';
require_page_access('payroll', 'text');   // admin-only, same as the payroll screen
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Reader\Html as HtmlReader;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

$id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$html = (string)($_POST['html'] ?? '');
if ($id <= 0 || $html === '') {
    http_response_code(400);
    exit('Nothing to export.');
}

// Period + status for the filename and the title row.
$p = $conn->query("SELECT date_from, date_to FROM payroll WHERE id = $id")->fetch_assoc();
$period = $p
    ? date('M j', strtotime($p['date_from'])) . ' - ' . date('M j, Y', strtotime($p['date_to']))
    : ('Payroll #' . $id);

// ── HTML → workbook ────────────────────────────────────────────────────────
// The reader only understands inline style attributes (not <style> blocks or
// classes), which is exactly what the client sends.
// Parsed straight from memory — no temp file, so the export cannot fail on a
// locked-down or unwritable system temp directory.
$doc = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';

try {
    $reader = new HtmlReader();
    $spreadsheet = $reader->loadFromString($doc);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Could not build the workbook: ' . $e->getMessage());
}

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Payroll ' . $id);

$maxCol = $sheet->getHighestColumn();
$maxRow = $sheet->getHighestRow();

// ── Presentation the HTML could not carry ──────────────────────────────────
// How many leading text columns the client sent (No. / Name / Employee No. /
// Position). Everything to their right is money.
$identity  = max(1, min(8, (int)($_POST['identity'] ?? 3)));
$firstMoney = $identity + 1;
$firstMoneyCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($firstMoney);

// Two header rows come from the table itself, so freeze below them and keep the
// identity columns on screen while scrolling right through the money columns.
$sheet->freezePane($firstMoneyCol . '3');

// Widths: autosize is O(cells) and this sheet can be 361 × 45, so size the
// identity columns properly and give the numeric ones a sane fixed width.
$idWidths = [6, 30, 16, 24, 20, 20, 20, 20];
for ($i = 1; $i <= $identity; $i++) {
    $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))
        ->setWidth($idWidths[$i - 1] ?? 18);
}
$colIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($maxCol);
for ($i = $firstMoney; $i <= $colIdx; $i++) {
    $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    $sheet->getColumnDimension($letter)->setWidth(13);
}

// Header rows: wrapped + centered so long labels stay readable at width 13.
$sheet->getStyle('A1:' . $maxCol . '2')->getAlignment()
    ->setWrapText(true)
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(30);
$sheet->getRowDimension(2)->setRowHeight(30);

// Money columns as numbers, not text. The client sends raw values (no thousands
// separators) so Excel parses them; the display format is applied here.
// (Guarded: a table no wider than its identity columns has no money range.)
if ($maxRow > 2 && $colIdx >= $firstMoney) {
    $money = $firstMoneyCol . '3:' . $maxCol . $maxRow;
    $sheet->getStyle($money)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle($money)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
}

// Print setup — landscape, repeat the header rows, fit to one page wide.
$ps = $sheet->getPageSetup();
$ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$ps->setPaperSize(PageSetup::PAPERSIZE_LEGAL);
$ps->setFitToWidth(1);
$ps->setFitToHeight(0);
$sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 2);
$sheet->getHeaderFooter()->setOddHeader('&L&B' . $period . '&R&P / &N');

$spreadsheet->getProperties()
    ->setTitle('Payroll ' . $period)
    ->setSubject('Payroll register')
    ->setCreator($_SESSION['login_name'] ?? 'Payroll');

$fileName = 'payroll_' . $id . '_' . preg_replace('/[^A-Za-z0-9]+/', '-', $period) . '.xlsx';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
