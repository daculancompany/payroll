<?php
/**
 * Duty Roster → Excel.
 *
 * The sheet is the SAME shape as the on-screen grid (one row per employee, one
 * column per day of the cutoff) so a head nurse can plot in Excel and read it
 * back without translating anything.
 *
 * Three things make it plottable rather than just printable:
 *   1. Every day cell carries a dropdown of the valid shift codes + OFF, so a
 *      typo cannot get in. The list is a RANGE on the Shifts sheet, not an
 *      inline string — Excel caps inline validation lists at 255 characters and
 *      a hospital with twenty shifts would silently exceed it.
 *   2. The coverage rows underneath are live COUNTIF formulas, so the counts
 *      update in Excel as they type. Losing that feedback is the main cost of
 *      leaving the web grid, and this buys it back.
 *   3. Days whose DTR batch is already approved are greyed out. They are locked
 *      in the app and editing them in Excel would achieve nothing.
 *
 * The employee list, the cutoff maths and the locked/punched map are all read
 * from Action so the export can never show a different set of people, or a
 * different idea of what is locked, than the grid it was exported from.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login'])) {
    header('Location: index.php');
    exit;
}
require 'vendor/autoload.php';
// db_connect FIRST and at file scope: Action includes it inside its constructor,
// where $conn and require_page_access() would be out of reach here.
include 'db_connect.php';
include 'admin_class.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

require_page_access('duty-roster');

$crud   = new Action();
$period = (string) ($_GET['period'] ?? '');
$range  = $crud->dutyPeriodRange($period);
if (!$range) { http_response_code(400); echo 'Invalid cutoff period.'; exit; }
$deptId = (int) ($_GET['department_id'] ?? 0);
// A Department Head / Supervisor gets their own ward whatever the URL says.
// dutyRosterEmployees() enforces this again on the row set; doing it here as
// well keeps the title, the filename and the sheet stamp honest — a file
// labelled with another ward's name would be worse than a refusal.
$scopeId = $crud->dutyScopeId();
if ($scopeId > 0) $deptId = $scopeId;

// Defined up here because the stamp on the Shifts sheet is written before the
// roster grid that also shows it.
$label = date('M j', strtotime($range['from'])) . ' – ' . date('M j, Y', strtotime($range['to']));

$deptName = 'All departments';
if ($deptId > 0) {
    $dq = $conn->query("SELECT name FROM department WHERE id = $deptId LIMIT 1");
    $deptName = ($dq && ($d = $dq->fetch_assoc())) ? $d['name'] : ('Department #' . $deptId);
}

$employees = $crud->dutyRosterEmployees($deptId, $range['from'], $range['to']);
if (!$employees) { http_response_code(404); echo 'No employees in this view.'; exit; }
$empIds = array_column($employees, 'id');
$zones  = $crud->dutyZoneMap($range['from'], $range['to'], $empIds);
// Approved leave, so the ward can see it while they plot rather than finding
// out at publish. Same map the grid marks its cells from.
$leaves = $crud->dutyLeaveMap($range['from'], $range['to'], $empIds);

$days = [];
for ($t = strtotime($range['from']); $t <= strtotime($range['to']); $t = strtotime('+1 day', $t)) {
    $days[] = ['date' => date('Y-m-d', $t), 'dom' => (int) date('j', $t), 'dow' => strtoupper(date('D', $t)), 'w' => (int) date('w', $t)];
}

// Holidays tint their column, same as the grid.
$holidays = [];
$hq = $conn->query("SELECT start_date, end_date, title FROM calendar_events
    WHERE type IN (1,3) AND start_date <= '" . $conn->real_escape_string($range['to']) . "'
      AND COALESCE(end_date, start_date) >= '" . $conn->real_escape_string($range['from']) . "'");
while ($hq && ($h = $hq->fetch_assoc())) {
    for ($t = strtotime($h['start_date']); $t <= strtotime($h['end_date'] ?: $h['start_date']); $t = strtotime('+1 day', $t)) {
        $holidays[date('Y-m-d', $t)] = $h['title'];
    }
}

// The code map lives in Action, shared with the importer — if the two built it
// separately they would drift apart the first time somebody renames a shift.
$shifts   = $crud->dutyShiftCodes();

/**
 * The SAME colour ramp the on-screen grid uses — DAY_COLORS / NIGHT_COLORS in
 * assets2/js/duty-roster.js, assigned by position exactly as shiftColor() does.
 * A planner who has learned that 6-2 is the pale blue one should not have to
 * learn it twice, and a graveyard block has to read as dark in both places.
 *
 * Kept in step by hand: they are two languages. If the ramp changes there,
 * change it here.
 */
$DAY_COLORS   = ['FFD6E4FF', 'FFD9F7BE', 'FFFFF1B8', 'FFFFD8BF', 'FFE4D7FF', 'FFB5F5EC', 'FFFFD6E7', 'FFF4FFB8'];
$NIGHT_COLORS = ['FF4C4A6B', 'FF3F5C8A', 'FF5C4A7A', 'FF2F4858', 'FF584A3F', 'FF4A5C4A'];
foreach ($shifts as $i => $s) {
    $shifts[$i]['bg'] = $s['noc']
        ? $NIGHT_COLORS[$i % count($NIGHT_COLORS)]
        : $DAY_COLORS[$i % count($DAY_COLORS)];
    $shifts[$i]['fg'] = $s['noc'] ? 'FFFFFFFF' : 'FF28223B';
}

$codeById = [];
foreach ($shifts as $s) $codeById[$s['id']] = $s['code'];

// Existing plan for the cutoff.
$cells = [];
if ($empIds) {
    $ids = implode(',', array_map('intval', $empIds));
    $cq = $conn->query("SELECT employee_id, work_date, schedule_id, is_rest_day, status
                        FROM employee_day_schedule
                        WHERE employee_id IN ($ids)
                          AND work_date BETWEEN '" . $conn->real_escape_string($range['from']) . "'
                                            AND '" . $conn->real_escape_string($range['to']) . "'");
    while ($cq && ($c = $cq->fetch_assoc())) {
        $cells[$c['employee_id'] . '|' . $c['work_date']] = $c;
    }
}

/* ── Build the workbook ─────────────────────────────────────────────────── */

$ss = new Spreadsheet();
$ss->getProperties()->setTitle('Duty Roster')->setSubject($deptName);

// Both sheets are claimed up front, explicitly. createSheet() can move the
// active-sheet pointer, so a later getActiveSheet() handed back the sheet just
// created — the roster was then written on top of the shift list, and the
// employee rows filled with shift times.
$sh  = $ss->getActiveSheet();
$sh->setTitle('Roster');
$ref = $ss->createSheet();
$ref->setTitle('Shifts');
$ref->setCellValue('A1', 'CODE');
$ref->setCellValue('B1', 'Shift');
$ref->setCellValue('C1', 'Time');
$ref->setCellValue('D1', 'Hours');
$ref->setCellValue('E1', 'Colour');
$r = 2;
foreach ($shifts as $s) {
    $ref->setCellValueExplicit('A' . $r, $s['code'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $ref->setCellValue('B' . $r, $s['desc']);
    $ref->setCellValue('C' . $r, $s['start'] . ' – ' . $s['end']);
    $ref->setCellValue('D' . $r, $s['hours']);
    // The legend: the same fill the grid gives this shift, so the colour on the
    // roster can be looked up rather than guessed at.
    $ref->setCellValueExplicit('E' . $r, $s['code'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $ref->getStyle('E' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($s['bg']);
    $ref->getStyle('E' . $r)->getFont()->setBold(true)->getColor()->setARGB($s['fg']);
    $ref->getStyle('E' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $r++;
}
$ref->setCellValueExplicit('A' . $r, 'OFF', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
$ref->setCellValue('B' . $r, 'Rest day (off duty)');
$ref->setCellValueExplicit('E' . $r, 'OFF', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
$ref->getStyle('E' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF4F4F6');
$ref->getStyle('E' . $r)->getFont()->setBold(true)->getColor()->setARGB('FF8C8998');
$ref->getStyle('E' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$lastRefRow = $r;
$ref->getStyle('A1:E1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
$ref->getStyle('A1:E1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF6642AA');
$ref->getColumnDimension('A')->setWidth(10);
$ref->getColumnDimension('B')->setWidth(30);
$ref->getColumnDimension('C')->setWidth(22);
$ref->getColumnDimension('D')->setWidth(8);
$ref->getColumnDimension('E')->setWidth(10);
$ref->getStyle('A2:A' . $lastRefRow)->getFont()->setBold(true);

/**
 * Which cutoff and ward this sheet was cut for, stamped where the importer can
 * find it.
 *
 * Day columns are matched by DAY NUMBER, and every first-half cutoff in the year
 * is numbered 1…15 — so a September sheet uploaded while November is open lines
 * up perfectly and imports into the wrong month without a word. The heading on
 * the Roster tab says "Sep 1 – Sep 15" but it is a caption; nothing reads it.
 *
 * This is a guard against a mistake, not against tampering: it is an ordinary
 * cell and anyone can edit it. That is the right trade — the failure this
 * prevents is a head nurse reusing last cutoff's file, and a signature they
 * could not regenerate would also stop them building a sheet by hand.
 */
$ref->setCellValue('F1', 'SHEET STAMP — do not edit');
$ref->setCellValue('F2', 'Cutoff');
$ref->setCellValueExplicit('G2', $period, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
$ref->setCellValue('F3', 'Department');
$ref->setCellValueExplicit('G3', (string) $deptId, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
$ref->setCellValue('F4', $label . '  ·  ' . $deptName);
$ref->setCellValue('F5', 'The importer checks this against the cutoff and department you have open, and refuses the file if they disagree.');
$ref->getStyle('F1')->getFont()->setBold(true)->getColor()->setARGB('FF6642AA');
$ref->getStyle('F2:F5')->getFont()->setSize(9)->getColor()->setARGB('FF9895A3');
$ref->getStyle('G2:G3')->getFont()->setSize(9)->setBold(true)->getColor()->setARGB('FF6B6878');
$ref->getStyle('G2:G3')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
$ref->getColumnDimension('F')->setWidth(26);
$ref->getColumnDimension('G')->setWidth(14);
// Text here too — this column IS the dropdown's source list. If Excel read
// "6-2" back as a date the list would offer a date, and nothing would match.
$ref->getStyle('A2:A' . $lastRefRow)
    ->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

// ── Sheet 1: the roster grid ────────────────────────────────────────────────
$firstDayCol = 4;                                   // column D — A/B/C are No., Employee No, Employee
$lastCol     = Coordinate::stringFromColumnIndex($firstDayCol + count($days) - 1);

$sh->setCellValue('A1', 'DUTY ROSTER');
$sh->mergeCells('A1:' . $lastCol . '1');
$sh->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF4E3483');

$sh->setCellValue('A2', $deptName . '   ·   ' . $label . '   ·   ' . count($employees) . ' employees');
$sh->mergeCells('A2:' . $lastCol . '2');
$sh->getStyle('A2')->getFont()->setSize(10)->getColor()->setARGB('FF6B6878');

$sh->setCellValue('A3',
    'Pick a CODE from the dropdown (see the Shifts tab) — OFF = rest day, and the colours follow what you type.   '
  . 'MANY CELLS AT ONCE: select them, type the code, then press Ctrl+Enter (Cmd+Enter on Mac). Or drag the little square at a cell\'s bottom-right corner. '
  . 'Do NOT paste in from another workbook — that overwrites the Text format these cells need, and Excel then reads codes like 2-10 as a date.   '
  . 'A cell with a GREY HEAVY BORDER is locked (its DTR is approved) and changes there are ignored. '
  . 'A RED HEAVY BORDER means that person has approved leave that day — hover it to see which. Do not roster them to work.   '
  . 'A row left completely blank is skipped on import; blanking one day in a row that has other entries CLEARS that day.   '
  . 'Save as .xlsx, then upload it back with the Import button on the Duty Roster page.');
$sh->mergeCells('A3:' . $lastCol . '3');
$sh->getStyle('A3')->getFont()->setSize(9)->setItalic(true)->getColor()->setARGB('FF9895A3');
$sh->getStyle('A3')->getAlignment()->setWrapText(true);
$sh->getRowDimension(3)->setRowHeight(46);

// Header: row 5 day number, row 6 weekday.
$HEAD1 = 5; $HEAD2 = 6; $FIRST = 7;
$sh->setCellValue('A' . $HEAD1, '#');
$sh->setCellValue('B' . $HEAD1, 'Employee No');
$sh->setCellValue('C' . $HEAD1, 'Employee');
$sh->mergeCells('A' . $HEAD1 . ':A' . $HEAD2);
$sh->mergeCells('B' . $HEAD1 . ':B' . $HEAD2);
$sh->mergeCells('C' . $HEAD1 . ':C' . $HEAD2);

foreach ($days as $i => $d) {
    $col = Coordinate::stringFromColumnIndex($firstDayCol + $i);
    $sh->setCellValue($col . $HEAD1, $d['dom']);
    $sh->setCellValue($col . $HEAD2, $d['dow']);
    $sh->getColumnDimension($col)->setWidth(9);
    $weekend = ($d['w'] === 0 || $d['w'] === 6);
    $hol = isset($holidays[$d['date']]);
    if ($hol) {
        $sh->getStyle($col . $HEAD1 . ':' . $col . $HEAD2)->getFill()
           ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF4E6');
        $sh->getComment($col . $HEAD1)->getText()->createTextRun($holidays[$d['date']]);
    }
    if ($weekend) {
        $sh->getStyle($col . $HEAD1 . ':' . $col . $HEAD2)->getFont()->getColor()->setARGB('FFC1544F');
    }
}
$sh->getStyle('A' . $HEAD1 . ':' . $lastCol . $HEAD2)->getFont()->setBold(true);
$sh->getStyle('A' . $HEAD1 . ':' . $lastCol . $HEAD2)->getAlignment()
   ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sh->getStyle('B' . $HEAD1 . ':C' . $HEAD2)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sh->getStyle('A' . $HEAD1 . ':' . $lastCol . $HEAD2)->getFill()
   ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFECF6');
$sh->getColumnDimension('A')->setWidth(5);
$sh->getColumnDimension('B')->setWidth(14);
$sh->getColumnDimension('C')->setWidth(28);

// Rows
$shiftMeta = [];
foreach ($shifts as $s) $shiftMeta[$s['code']] = $s;
$COMMENT_BUDGET = 3000;
$comments = 0;
$lockedCells = [];
$leaveCells  = [];

$row = $FIRST;
$seq = 1;
foreach ($employees as $emp) {
    $sh->setCellValue('A' . $row, $seq++);
    $sh->setCellValueExplicit('B' . $row, (string) $emp['employee_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sh->setCellValue('C' . $row, $emp['name']);
    foreach ($days as $i => $d) {
        $col = Coordinate::stringFromColumnIndex($firstDayCol + $i);
        $k   = $emp['id'] . '|' . $d['date'];
        $val = '';
        if (isset($cells[$k])) {
            $c = $cells[$k];
            $val = ((int) $c['is_rest_day'] === 1) ? 'OFF' : ($codeById[$c['schedule_id']] ?? '');
        }
        if ($val !== '') {
            $sh->setCellValueExplicit($col . $row, $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            // A 7-wide column can only ever say "6-2", so the hours live in a
            // hover note instead — the cell keeps the code, the mouse gets the
            // detail. Budgeted: a comment is a whole XML part each, and a fully
            // plotted 368-person cutoff is 5,500 cells.
            if ($val !== 'OFF' && $comments < $COMMENT_BUDGET) {
                $s = $shiftMeta[$val] ?? null;
                if ($s) {
                    $sh->getComment($col . $row)->getText()->createTextRun(
                        $s['desc'] . "\n" . $s['start'] . ' – ' . $s['end'] . "\n" . $s['hours'] . ' hrs'
                        . ($s['noc'] ? "\nNight differential" : '')
                    );
                    $sh->getComment($col . $row)->setWidth('180pt')->setHeight('60pt');
                    $comments++;
                }
            }
        }
        // Locked days are marked with a BORDER, not a grey fill. The shift
        // colours below are conditional formatting, and conditional fill wins
        // over the cell's own — a locked day holding a shift would have come
        // out looking like any other. Border is a channel the colour rules do
        // not touch, so the two signals stop competing.
        // Approved leave, noted on the cell. The ward is plotting here, so this
        // is the earliest point the clash can be caught — long before publish.
        $lv = $leaves[$emp['id'] . '|' . $d['date']] ?? null;
        if ($lv && $comments < $COMMENT_BUDGET) {
            $sh->getComment($col . $row)->getText()->createTextRun(
                'ON LEAVE' . ($lv['half'] ? ' (half day)' : '') . "\n" . $lv['name']
                . ($val !== '' && $val !== 'OFF' ? "\n\nThis person is rostered to work and will not be here." : '')
            );
            $comments++;
            $leaveCells[] = $col . $row;
        }

        if (($zones[$emp['id'] . '|' . $d['date']] ?? '') === 'locked') {
            $sh->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8E8EC');
            // Applied after the grid-wide thin border below, which would
            // otherwise overwrite it.
            $lockedCells[] = $col . $row;
        } elseif ($d['w'] === 0 || $d['w'] === 6) {
            $sh->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFBFAFD');
        }
    }
    $row++;
}
$lastRow = $row - 1;

$sh->getStyle('A' . $FIRST . ':A' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sh->getStyle('A' . $FIRST . ':A' . $lastRow)->getFont()->getColor()->setARGB('FF9895A3');
$sh->getStyle('D' . $FIRST . ':' . $lastCol . $lastRow)->getAlignment()
   ->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Every day cell is formatted as TEXT, and this is not cosmetic — it is the
// only thing that lets half these codes be entered at all.
//
// Excel parses what you put in a cell according to that cell's number format.
// Under the default General format "2-10" is not a shift, it is 10 February:
// Excel converts it to a date serial and THEN re-checks the dropdown, which no
// longer matches any code, so the entry is rejected with "Unknown shift code".
// Same for 6-2, 10-6, 8-4, 11-8 — every one of the hyphenated shift names.
// Picking from the dropdown does not save you; the value is parsed either way.
// With "@" the input is stored verbatim and the dropdown matches.
//
// This must NOT be extended to the coverage rows below: a formula written into
// a Text-formatted cell is displayed as its own source instead of running.
$sh->getStyle('D' . $FIRST . ':' . $lastCol . $lastRow)
   ->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
$sh->getStyle('B' . $FIRST . ':B' . $lastRow)
   ->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

$sh->getStyle('A' . $HEAD1 . ':' . $lastCol . $lastRow)->getBorders()->getAllBorders()
   ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE1DFDD');

// Now the locked marker, on top of the grid-wide thin border.
foreach ($lockedCells as $cell) {
    $sh->getStyle($cell)->getBorders()->getOutline()
       ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FF9A93AD');
}
// Leave days get a red outline. Border again rather than fill, for the same
// reason the lock does: the shift colours are conditional formatting and would
// paint straight over a fill.
foreach ($leaveCells as $cell) {
    $sh->getStyle($cell)->getBorders()->getOutline()
       ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FFC62828');
}

$sh->freezePane('D' . $FIRST);

// The legend that pops up when a day cell is selected. Excel caps the prompt at
// 255 characters, so it takes as many shifts as fit and points at the Shifts tab
// for the rest rather than being silently truncated mid-shift.
$promptBits = [];
$promptLen  = 0;
foreach ($shifts as $s) {
    $bit = $s['code'] . ' = ' . $s['start'] . '–' . $s['end'];
    if ($promptLen + mb_strlen($bit) + 3 > 200) break;
    $promptBits[] = $bit;
    $promptLen += mb_strlen($bit) + 3;
}
$promptBits[] = 'OFF = rest day';
$promptText = implode("\n", $promptBits);
if (count($promptBits) - 1 < count($shifts)) $promptText .= "\n… see the Shifts tab";

// Dropdown on every day cell.
for ($i = 0; $i < count($days); $i++) {
    $col = Coordinate::stringFromColumnIndex($firstDayCol + $i);
    for ($r2 = $FIRST; $r2 <= $lastRow; $r2++) {
        $dv = $sh->getCell($col . $r2)->getDataValidation();
        $dv->setType(DataValidation::TYPE_LIST);
        $dv->setErrorStyle(DataValidation::STYLE_STOP);
        $dv->setAllowBlank(true);
        $dv->setShowDropDown(true);
        $dv->setShowErrorMessage(true);
        $dv->setErrorTitle('Unknown shift code');
        $dv->setError('Pick a code from the list, or see the Shifts tab.');
        // Shown on select, including on empty cells — where a hover comment
        // cannot help, because there is nothing there to hover.
        $dv->setShowInputMessage(true);
        $dv->setPromptTitle('Shift codes');
        $dv->setPrompt($promptText);
        $dv->setFormula1('Shifts!$A$2:$A$' . $lastRefRow);
    }
}

/**
 * Colour, as CONDITIONAL FORMATTING rather than a fill written into each cell.
 *
 * A fill applied at export time records what the cell held when the file was
 * built. The moment the planner changes 6-2 to NOC the colour stays on 6-2, and
 * a sheet whose colour disagrees with its text is worse than one with no colour
 * at all — colour is the first thing read and the last thing checked.
 *
 * A rule travels with the value instead: Excel recolours as they type, so the
 * sheet stays as legible as the grid it came from.
 *
 * There is a practical ceiling of 64 rules on a range; a dozen shifts is
 * nowhere near it, but a hospital that grows past ~60 will need banding.
 */
$dayRange = 'D' . $FIRST . ':' . $lastCol . $lastRow;
$conds = [];
foreach ($shifts as $s) {
    $c = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
    $c->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_CELLIS);
    $c->setOperatorType(\PhpOffice\PhpSpreadsheet\Style\Conditional::OPERATOR_EQUAL);
    $c->addCondition('"' . $s['code'] . '"');
    // BOTH ends. A conditional style is written as a dxf, where the fill is a
    // patternFill carrying only bgColor — and PhpSpreadsheet maps bgColor to
    // getEndColor(). Setting startColor alone, the way an ordinary cell fill is
    // set, produces a rule with no colour at all: it applies, and paints
    // nothing. startColor is kept so the object also reads back correctly.
    $c->getStyle()->getFill()->setFillType(Fill::FILL_SOLID);
    $c->getStyle()->getFill()->getStartColor()->setARGB($s['bg']);
    $c->getStyle()->getFill()->getEndColor()->setARGB($s['bg']);
    $c->getStyle()->getFont()->getColor()->setARGB($s['fg']);
    $c->getStyle()->getFont()->setBold(true);
    $conds[] = $c;
}
$cOff = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
$cOff->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_CELLIS);
$cOff->setOperatorType(\PhpOffice\PhpSpreadsheet\Style\Conditional::OPERATOR_EQUAL);
$cOff->addCondition('"OFF"');
$cOff->getStyle()->getFill()->setFillType(Fill::FILL_SOLID);
$cOff->getStyle()->getFill()->getStartColor()->setARGB('FFF4F4F6');
$cOff->getStyle()->getFill()->getEndColor()->setARGB('FFF4F4F6');
$cOff->getStyle()->getFont()->getColor()->setARGB('FF8C8998');
$conds[] = $cOff;
$sh->getStyle($dayRange)->setConditionalStyles($conds);

// ── Coverage: live formulas, so the numbers move as they type ───────────────
//
// The per-shift counts are SUMPRODUCT(--EXACT(...)) against the code held in
// column A of the same row, NOT COUNTIF with the code written into the formula.
// COUNTIF coerces its criteria, and half of these codes look like something
// else to Excel — "6-2" reads as 2 June, "10-6" as 6 October — so those rows
// counted zero no matter how many nurses were on the shift. EXACT compares as
// text and cannot be coerced.
$covStart = $lastRow + 2;
$codeCol  = 'A';

// Lay the rows out first: the ON DUTY row subtracts the OFF row, so it needs
// that row number before it can be written.
$shiftRows = [];
$r3 = $covStart + 1;
foreach ($shifts as $s) { $shiftRows[$r3] = $s; $r3++; }
$offRow = $r3;

foreach ($shiftRows as $rowNo => $s) {
    $sh->setCellValueExplicit($codeCol . $rowNo, $s['code'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sh->setCellValue('C' . $rowNo, $s['code'] . '  (' . $s['start'] . ')');
    foreach ($days as $i => $d) {
        $col = Coordinate::stringFromColumnIndex($firstDayCol + $i);
        $sh->setCellValue($col . $rowNo,
            '=SUMPRODUCT(--EXACT(' . $col . '$' . $FIRST . ':' . $col . '$' . $lastRow . ',$' . $codeCol . $rowNo . '))');
    }
}

$sh->setCellValueExplicit($codeCol . $offRow, 'OFF', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
$sh->setCellValue('C' . $offRow, 'OFF  (rest day)');
foreach ($days as $i => $d) {
    $col = Coordinate::stringFromColumnIndex($firstDayCol + $i);
    $sh->setCellValue($col . $offRow,
        '=SUMPRODUCT(--EXACT(' . $col . '$' . $FIRST . ':' . $col . '$' . $lastRow . ',$' . $codeCol . $offRow . '))');
}

$sh->setCellValue('C' . $covStart, 'ON DUTY / DAY');
$sh->getStyle('C' . $covStart)->getFont()->setBold(true)->getColor()->setARGB('FF4E3483');
foreach ($days as $i => $d) {
    $col = Coordinate::stringFromColumnIndex($firstDayCol + $i);
    $sh->setCellValue($col . $covStart,
        '=COUNTA(' . $col . $FIRST . ':' . $col . $lastRow . ')-' . $col . $offRow);
}

// The code column is scaffolding for the formulas, not something to read.
$sh->getStyle($codeCol . ($covStart + 1) . ':' . $codeCol . $offRow)->getFont()
   ->setSize(8)->getColor()->setARGB('FFC0BDC9');
$sh->getStyle('C' . $covStart . ':' . $lastCol . $r3)->getFill()
   ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF7F6FA');
$sh->getStyle('D' . $covStart . ':' . $lastCol . $r3)->getAlignment()
   ->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sh->getStyle('D' . $covStart . ':' . $lastCol . $covStart)->getFont()->setBold(true);
$sh->getStyle('C' . $covStart . ':' . $lastCol . $r3)->getBorders()->getAllBorders()
   ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE1DFDD');

$sh->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
$sh->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
$sh->getPageSetup()->setPrintArea('A1:' . $lastCol . $r3);
$sh->setSelectedCell('D' . $FIRST);

$ss->setActiveSheetIndex(0);

$slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($deptName));
$file = 'duty-roster-' . trim($slug, '-') . '-' . $range['from'] . '.xlsx';

// Action's constructor calls ob_start(), and anything sitting in a buffer would
// be written into the .xlsx ahead of the zip header and corrupt the file.
while (ob_get_level() > 0) ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $file . '"');
header('Cache-Control: max-age=0');
(new Xlsx($ss))->save('php://output');
exit;
