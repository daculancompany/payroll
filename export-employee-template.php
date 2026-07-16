<?php
/**
 * Downloadable import template for Employees.
 *
 * The column ORDER here is not cosmetic: import_employee() reads the sheet by
 * numeric index (row[3] = name, row[10] = basic pay, ...), so columns A–M must
 * keep their exact positions or data lands in the wrong field. New fields are
 * appended from N onwards, which keeps spreadsheets built against the old
 * layout importing correctly.
 *
 * Columns G and I are unused by the importer. They are kept as reserved spacers
 * purely to preserve the index positions of the columns after them.
 */
session_start();
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

include 'db_connect.php';

// Classification + shift names are pulled live so the template always offers the
// values the importer will actually match against.
$classifications = [];
$q = $conn->query("SELECT clasification FROM clasification ORDER BY id");
if ($q) while ($r = $q->fetch_assoc()) $classifications[] = $r['clasification'];
if (!$classifications) $classifications = ['Regular'];

$shifts = [];
$q = $conn->query("SELECT description FROM work_schedules WHERE status = 1 ORDER BY id");
if ($q) while ($r = $q->fetch_assoc()) $shifts[] = $r['description'];

$deductionNames = [];
$q = $conn->query("SELECT deduction FROM deductions ORDER BY id");
if ($q) while ($r = $q->fetch_assoc()) $deductionNames[] = $r['deduction'];

// [header, width, note]  — index position is the contract with import_employee()
$columns = [
    ['No.',                 6,  'Optional. Ignored by the importer — for your own numbering.'],
    ['First Name',          16, 'REQUIRED. Given name only, e.g. JUAN'],
    ['Middle Name',         14, 'Optional. Full middle name or just an initial, e.g. SANTOS or S'],
    ['Last Name',           22, 'REQUIRED. Surname only, e.g. DELA CRUZ — do NOT put a comma in this cell.'],
    ['Position',            22, 'REQUIRED. Created automatically if it does not exist.'],
    ['PhilHealth No.',      18, 'ID number (text), not an amount.'],
    ['(reserved)',          10, 'Leave blank. Kept to preserve column positions.'],
    ['SSS No.',             18, 'ID number (text), not an amount.'],
    ['(reserved)',          10, 'Leave blank. Kept to preserve column positions.'],
    ['Pag-IBIG No.',        18, 'ID number (text), not an amount.'],
    ['Basic Pay',           12, 'Number. Currency symbols and commas are stripped.'],
    ['Allowance Rate',      14, 'Number.'],
    ['OT Rate',             10, 'Number.'],
    // ── appended fields ──
    ['Classification',      16, 'One of: ' . implode(', ', $classifications) . '. Blank = Regular.'],
    ['SSS Amount',          12, 'Contribution AMOUNT deducted per payroll. Blank = 0.'],
    ['PhilHealth Amount',   16, 'Contribution AMOUNT deducted per payroll. Blank = 0.'],
    ['Pag-IBIG Amount',     15, 'Contribution AMOUNT deducted per payroll. Blank = 0.'],
    ['Shift / Schedule',    20, ($shifts ? 'One of: ' . implode(', ', $shifts) : 'No work schedules defined yet.') . ' Blank = unassigned.'],
    ['Deduction Name',      20, ($deductionNames ? 'One of: ' . implode(', ', $deductionNames) : 'No deductions defined yet.') . ' Blank = none.'],
    ['Deduction Amount',    16, 'Number. Required when Deduction Name is filled.'],
];

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setTitle('Employee Import Template');

// ── Sheet 1: the importable sheet ────────────────────────────────────────────
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Employees');

foreach ($columns as $i => [$header, $width, $note]) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $sheet->setCellValue($col . '1', $header);
    $sheet->getColumnDimension($col)->setWidth($width);
    // Note travels with the header so the rules are readable in-place.
    $sheet->getComment($col . '1')->getText()->createTextRun($note);
}

$headerStyle = $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns)) . '1');
$headerStyle->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
$headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF009688');
$headerStyle->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
$sheet->getRowDimension(1)->setRowHeight(30);
$sheet->freezePane('A2');

// Grey out the reserved spacers so nobody tries to fill them.
foreach (['G', 'I'] as $reserved) {
    $sheet->getStyle($reserved . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF9E9E9E');
}

// One example row, clearly marked so it is obvious it must be replaced.
$example = [
    '1', 'JUAN', 'SANTOS', 'DELA CRUZ', 'Security Guard',
    '12-345678901-2', '', '34-1234567-8', '', '1234-5678-9012',
    '15000', '1500', '85',
    $classifications[0], '675', '375', '100',
    $shifts[0] ?? '', $deductionNames[0] ?? '', $deductionNames ? '250' : '',
];
foreach ($example as $i => $val) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    // Force ID numbers to text so Excel does not mangle them into dates/numbers.
    if (in_array($col, ['F', 'H', 'J'], true) && $val !== '') {
        $sheet->setCellValueExplicit($col . '2', $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    } else {
        $sheet->setCellValue($col . '2', $val);
    }
}
$sheet->getStyle('A2:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns)) . '2')
      ->getFont()->getColor()->setARGB('FF9E9E9E');
$sheet->getStyle('A2:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns)) . '2')
      ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF9E6');

// ── Sheet 2: Notes / instructions ────────────────────────────────────────────
$notes = $spreadsheet->createSheet();
$notes->setTitle('Notes');
$notes->getColumnDimension('A')->setWidth(4);
$notes->getColumnDimension('B')->setWidth(22);
$notes->getColumnDimension('C')->setWidth(95);

$notes->setCellValue('B1', 'Employee Import — Instructions');
$notes->getStyle('B1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF00695C');

$lines = [
    ['', ''],
    ['How it works', 'Fill in the "Employees" sheet, one employee per row. Row 1 is the header and row 2 is a greyed-out example — delete the example before uploading. Upload via Employees → Import.'],
    ['Matching', 'Existing employees are matched on first + middle + last name. A match UPDATES that employee; no match INSERTS a new one.'],
    ['Name columns', 'Name is split across three columns: First Name (B), Middle Name (C) and Last Name (D). Middle name may be a full name or just an initial, and may be left blank.'],
    ['Required', 'First Name, Last Name and Position. Rows missing a first or last name are skipped silently.'],
    ['Combined names', 'Legacy sheets that put "LASTNAME, FIRSTNAME MIDDLENAME" into column D still import: if column D contains a comma it is split automatically and columns B/C are ignored. New sheets should use the three separate columns instead.'],
    ['Position', 'Matched by name, case-insensitive. If the position does not exist it is created automatically — watch your spelling or you will create duplicates.'],
    ['Amounts vs ID numbers', 'PhilHealth/SSS/Pag-IBIG No. (cols F, H, J) are ID NUMBERS. The contribution AMOUNTS are separate columns (O, P, Q).'],
    ['Contributions', 'SSS / PhilHealth / Pag-IBIG amounts are taken from this file exactly as typed — they are NOT auto-calculated. Blank means 0. Re-importing an employee overwrites their existing amounts.'],
    ['Classification', 'Matched by name against: ' . implode(', ', $classifications) . '. Blank or unrecognised falls back to Regular.'],
    ['Shift / Schedule', $shifts ? 'Matched by name against: ' . implode(', ', $shifts) . '. Blank leaves the employee unassigned.' : 'No work schedules are defined yet — leave this blank until you create some.'],
    ['Deductions', $deductionNames ? 'Deduction Name must match an existing deduction (' . implode(', ', $deductionNames) . ') and needs an amount. Blank name = no deduction added.' : 'No deductions are defined yet — leave these blank until you create some.'],
    ['Reserved columns', 'Columns G and I are unused spacers. Leave them empty — they exist only to keep the other columns in the positions the importer expects.'],
    ['Number formatting', 'Currency symbols, spaces and commas in amount columns are stripped automatically, so "P 15,000.00" is read as 15000.'],
    ['Payroll type', 'Everyone is semi-monthly. There is no weekly payroll column.'],
];
$row = 2;
foreach ($lines as [$label, $text]) {
    if ($label === '') { $row++; continue; }
    $notes->setCellValue('B' . $row, $label);
    $notes->setCellValue('C' . $row, $text);
    $notes->getStyle('B' . $row)->getFont()->setBold(true);
    $notes->getStyle('C' . $row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    $notes->getStyle('B' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    $notes->getRowDimension($row)->setRowHeight(-1);
    $row++;
}

$spreadsheet->setActiveSheetIndex(0);

$fileName = 'employee-import-template.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
