<?php
/**
 * DTR Documents export — .xlsx, two sheets.
 *
 *   Sheet "Summary"    one row per employee: totals, approval counts, flags
 *   Sheet "Day Matrix" one row per employee, one column per day of the cutoff
 *
 * POST only, from the Excel button on dtr-documents.php. The rows arrive from
 * the client, which assembled them by walking the SAME dtr-employee-server.php
 * ?action=docs endpoint the screen reads — filters included. That is deliberate:
 * re-querying here would duplicate ~500 lines of per-day aggregation and the two
 * copies would drift the first time either side changed.
 *
 *   payload = {"from":"2026-08-01","to":"2026-08-15","rows":[{...}, ...]}
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login']) && empty($_SESSION['login_id'])) {
    http_response_code(403);
    exit('Not authorized.');
}
if (!csrf_verify()) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('POST only.');
}

$conn = include 'db_connect.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$ddtrId  = (int) ($_POST['id'] ?? 0);
$payload = json_decode((string) ($_POST['payload'] ?? ''), true);
if (!is_array($payload) || empty($payload['rows']) || !is_array($payload['rows'])) {
    http_response_code(400);
    exit('Nothing to export.');
}

$rows = $payload['rows'];
$from = (string) ($payload['from'] ?? '');
$to   = (string) ($payload['to'] ?? '');
if (!strtotime($from) || !strtotime($to) || $to < $from) {
    http_response_code(400);
    exit('Bad period.');
}

// Batch header straight from the database — the client is not trusted to label
// which batch this is.
$batch = null;
if ($ddtrId > 0) {
    // Same joins dtr-documents.php uses for its own header chips.
    $st = $conn->prepare("
        SELECT DTR.date_from, DTR.date_to, DTR.status, DTR.local_id,
               sites.site_code, sites.site_name, employers.employer_name
        FROM DTR
        LEFT JOIN sites     ON sites.id = DTR.site_id
        LEFT JOIN employers ON employers.id = sites.employer_id
        WHERE DTR.id = ?");
    $st->bind_param('i', $ddtrId);
    $st->execute();
    $batch = $st->get_result()->fetch_assoc() ?: null;
}

// Every date in the cutoff, in order — the matrix columns.
$dates = [];
for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
    $dates[] = date('Y-m-d', $d);
}

$STATUS_LBL = [0 => 'Open', 1 => 'Pending Approval', 2 => 'Approved', 3 => 'Ready for Review'];
$periodLbl  = date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

$BRAND      = '4E3483';
$HEAD_FILL  = 'E7DFFA';
$FOOT_FILL  = 'DED2F5';

$ss = new Spreadsheet();
$ss->getProperties()
   ->setCreator('Payroll')
   ->setTitle('DTR Documents — ' . $periodLbl)
   ->setDescription('Exported ' . date('Y-m-d H:i'));

/** Title block shared by both sheets; returns the row the table header starts on. */
$titleBlock = function ($sheet, string $lastCol, string $subtitle) use ($batch, $periodLbl, $STATUS_LBL, $BRAND, $rows) {
    $sheet->mergeCells("A1:{$lastCol}1");
    $sheet->setCellValue('A1', 'DAILY TIME RECORD — ' . $subtitle);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($BRAND);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $meta = [];
    if ($batch) {
        if (!empty($batch['local_id']))      $meta[] = 'Batch ' . $batch['local_id'];
        if (!empty($batch['site_name']))     $meta[] = $batch['site_name'] . ' (' . $batch['site_code'] . ')';
        if (!empty($batch['employer_name'])) $meta[] = $batch['employer_name'];
        $meta[] = 'Status: ' . ($STATUS_LBL[(int) $batch['status']] ?? '—');
    }
    $meta[] = $periodLbl;
    $meta[] = count($rows) . ' employee/s';
    $meta[] = 'Exported ' . date('M j, Y g:i A');

    $sheet->mergeCells("A2:{$lastCol}2");
    $sheet->setCellValue('A2', implode('  ·  ', $meta));
    $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('6B6580');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    return 4; // one blank row, then the header
};

/** Style a header row band. */
$headBand = function ($sheet, string $range) use ($HEAD_FILL, $BRAND) {
    $s = $sheet->getStyle($range);
    $s->getFont()->setBold(true)->setSize(10)->getColor()->setRGB($BRAND);
    $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($HEAD_FILL);
    $s->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $s->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9B8EA');
};

/* ─────────────────────────── Sheet 1: Summary ─────────────────────────── */
$sh = $ss->getActiveSheet();
$sh->setTitle('Summary');

$hdr = ['#', 'Employee No', 'Employee', 'Department', 'Position', 'Days Logged',
        'Work Hours', 'Overtime', 'Undertime', 'Late', 'Approved', 'Pending',
        'Rejected', 'Flagged', 'Low Attendance'];
$lastCol = 'O';                                    // 15 columns
$r = $titleBlock($sh, $lastCol, 'Summary');

foreach ($hdr as $i => $h) $sh->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $r, $h);
$headBand($sh, "A{$r}:{$lastCol}{$r}");
$sh->getRowDimension($r)->setRowHeight(28);
$headerRow = $r;

$T = ['days' => 0, 'wh' => 0.0, 'ot' => 0.0, 'ut' => 0.0, 'late' => 0.0, 'appr' => 0, 'pend' => 0, 'disa' => 0, 'exc' => 0];
$n = 0;
foreach ($rows as $e) {
    $r++; $n++;
    $days = is_array($e['days'] ?? null) ? count($e['days']) : 0;
    $name = trim(($e['last'] ?? '') . ', ' . ($e['first'] ?? '') . ' ' . ($e['mid'] ?? ''));

    $sh->setCellValue("A$r", $n);
    $sh->setCellValueExplicit("B$r", (string) ($e['no'] ?? ''), DataType::TYPE_STRING);
    $sh->setCellValue("C$r", $name);
    $sh->setCellValue("D$r", (string) ($e['dep'] ?? ''));
    $sh->setCellValue("E$r", (string) ($e['pos'] ?? ''));
    $sh->setCellValue("F$r", $days);
    $sh->setCellValue("G$r", round((float) ($e['wh'] ?? 0), 2));
    $sh->setCellValue("H$r", round((float) ($e['ot'] ?? 0), 2));
    $sh->setCellValue("I$r", round((float) ($e['ut'] ?? 0), 2));
    $sh->setCellValue("J$r", round((float) ($e['late'] ?? 0), 2));
    $sh->setCellValue("K$r", (int) ($e['appr'] ?? 0));
    $sh->setCellValue("L$r", (int) ($e['pend'] ?? 0));
    $sh->setCellValue("M$r", (int) ($e['disa'] ?? 0));
    $sh->setCellValue("N$r", (int) ($e['exc'] ?? 0));
    $sh->setCellValue("O$r", !empty($e['low_att']) ? 'YES' : '');

    $T['days'] += $days;
    $T['wh']   += (float) ($e['wh'] ?? 0);
    $T['ot']   += (float) ($e['ot'] ?? 0);
    $T['ut']   += (float) ($e['ut'] ?? 0);
    $T['late'] += (float) ($e['late'] ?? 0);
    $T['appr'] += (int) ($e['appr'] ?? 0);
    $T['pend'] += (int) ($e['pend'] ?? 0);
    $T['disa'] += (int) ($e['disa'] ?? 0);
    $T['exc']  += (int) ($e['exc'] ?? 0);

    // Anything needing a human decision gets a tint, so the exceptions are
    // findable in the file the same way they are on screen.
    if (!empty($e['exc']) || !empty($e['low_att'])) {
        $sh->getStyle("A$r:{$lastCol}$r")->getFill()->setFillType(Fill::FILL_SOLID)
           ->getStartColor()->setRGB(!empty($e['exc']) ? 'FDECEC' : 'FFF6E0');
    }
}
$firstRow = $headerRow + 1;
$lastRow  = $r;

$r++;
$sh->setCellValue("A$r", 'TOTAL');
$sh->mergeCells("A$r:E$r");
$sh->setCellValue("F$r", $T['days']);
$sh->setCellValue("G$r", round($T['wh'], 2));
$sh->setCellValue("H$r", round($T['ot'], 2));
$sh->setCellValue("I$r", round($T['ut'], 2));
$sh->setCellValue("J$r", round($T['late'], 2));
$sh->setCellValue("K$r", $T['appr']);
$sh->setCellValue("L$r", $T['pend']);
$sh->setCellValue("M$r", $T['disa']);
$sh->setCellValue("N$r", $T['exc']);
$foot = $sh->getStyle("A$r:{$lastCol}$r");
$foot->getFont()->setBold(true)->getColor()->setRGB('46297A');
$foot->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($FOOT_FILL);
$sh->getStyle("A$r:E$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

if ($lastRow >= $firstRow) {
    $sh->getStyle("A{$firstRow}:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()
       ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('EFEAF8');
    $sh->getStyle("G{$firstRow}:J{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sh->getStyle("G$r:J$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $sh->getStyle("A{$firstRow}:B{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle("F{$firstRow}:F{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle("K{$firstRow}:O{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}
foreach (['A' => 5, 'B' => 14, 'C' => 30, 'D' => 26, 'E' => 22, 'F' => 8,
          'G' => 11, 'H' => 10, 'I' => 11, 'J' => 9, 'K' => 10, 'L' => 9,
          'M' => 10, 'N' => 9, 'O' => 15] as $col => $w) {
    $sh->getColumnDimension($col)->setWidth($w);
}
$sh->freezePane('D' . ($headerRow + 1));
$sh->setAutoFilter("A{$headerRow}:{$lastCol}{$lastRow}");

/* ────────────────────────── Sheet 2: Day Matrix ───────────────────────── */
$mx = $ss->createSheet();
$mx->setTitle('Day Matrix');

// 3 identity columns + one per date + Total
$mxLastIdx = 3 + count($dates) + 1;
$mxLastCol = Coordinate::stringFromColumnIndex($mxLastIdx);
$r = $titleBlock($mx, $mxLastCol, 'Day Matrix (work hours per day)');

$mx->setCellValue("A$r", '#');
$mx->setCellValue("B$r", 'Employee No');
$mx->setCellValue("C$r", 'Employee');
foreach ($dates as $i => $d) {
    // Two lines: day number over the weekday initial — the whole cutoff still
    // fits on one screen, and weekends stay recognisable.
    $mx->setCellValue(Coordinate::stringFromColumnIndex(4 + $i) . $r, (int) date('j', strtotime($d)) . "\n" . date('D', strtotime($d)));
}
$mx->setCellValue($mxLastCol . $r, 'TOTAL');
$headBand($mx, "A{$r}:{$mxLastCol}{$r}");
$mx->getRowDimension($r)->setRowHeight(30);
$mxHeader = $r;

$n = 0;
$mxTotals = array_fill(0, count($dates), 0.0);
$grand = 0.0;
foreach ($rows as $e) {
    $r++; $n++;
    $mx->setCellValue("A$r", $n);
    $mx->setCellValueExplicit("B$r", (string) ($e['no'] ?? ''), DataType::TYPE_STRING);
    $mx->setCellValue("C$r", trim(($e['last'] ?? '') . ', ' . ($e['first'] ?? '')));

    $days = is_array($e['days'] ?? null) ? $e['days'] : [];
    foreach ($dates as $i => $d) {
        $col = 4 + $i;
        if (!isset($days[$d])) continue;                 // no record that day — left blank
        $wh = round((float) ($days[$d]['wh'] ?? 0), 2);
        $mx->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $wh);
        $mxTotals[$i] += $wh;
        $grand += $wh;
        // A logged day that produced no hours is the thing worth finding in a
        // matrix this wide, so it is tinted rather than left to read as 0.00.
        if ($wh <= 0) {
            $cell = Coordinate::stringFromColumnIndex($col) . $r;
            $mx->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDECEC');
        }
    }
    $mx->setCellValue($mxLastCol . $r, round((float) ($e['wh'] ?? 0), 2));
}
$mxFirst = $mxHeader + 1;
$mxLast  = $r;

$r++;
$mx->setCellValue("A$r", 'TOTAL');
$mx->mergeCells("A$r:C$r");
foreach ($dates as $i => $d) $mx->setCellValue(Coordinate::stringFromColumnIndex(4 + $i) . $r, round($mxTotals[$i], 2));
$mx->setCellValue($mxLastCol . $r, round($grand, 2));
$mfoot = $mx->getStyle("A$r:{$mxLastCol}$r");
$mfoot->getFont()->setBold(true)->getColor()->setRGB('46297A');
$mfoot->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($FOOT_FILL);
$mx->getStyle("A$r:C$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

if ($mxLast >= $mxFirst) {
    $rng = "A{$mxFirst}:{$mxLastCol}{$mxLast}";
    $mx->getStyle($rng)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('EFEAF8');
    $dFirst = Coordinate::stringFromColumnIndex(4);
    $mx->getStyle("{$dFirst}{$mxFirst}:{$mxLastCol}{$mxLast}")->getNumberFormat()->setFormatCode('0.00;;""');
    $mx->getStyle("{$dFirst}{$mxFirst}:{$mxLastCol}{$mxLast}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $mx->getStyle("{$dFirst}$r:{$mxLastCol}$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $mx->getStyle("A{$mxFirst}:B{$mxLast}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}
$mx->getColumnDimension('A')->setWidth(5);
$mx->getColumnDimension('B')->setWidth(14);
$mx->getColumnDimension('C')->setWidth(30);
for ($i = 0; $i < count($dates); $i++) {
    $mx->getColumnDimension(Coordinate::stringFromColumnIndex(4 + $i))->setWidth(6.5);
}
$mx->getColumnDimension($mxLastCol)->setWidth(10);
$mx->freezePane('D' . ($mxHeader + 1));

$ss->setActiveSheetIndex(0);

$slug = 'dtr-documents-' . date('Ymd', strtotime($from)) . '-' . date('Ymd', strtotime($to));
if (ob_get_length()) ob_end_clean();      // any stray notice would corrupt the zip
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $slug . '.xlsx"');
header('Cache-Control: max-age=0, no-store');

(new Xlsx($ss))->save('php://output');
exit;
