<?php
/**
 * DTR Documents export — .xlsx, three sheets.
 *
 *   Sheet "Time and Attendance"  the Bio-office report format payroll works
 *                                from: one block per employee, every scan in
 *                                an In 1 … Out 6 grid, then a report summary
 *   Sheet "Summary"              one row per employee: totals, approval counts, flags
 *   Sheet "Day Matrix"           one row per employee, one column per day of the cutoff
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
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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

// Holidays inside the cutoff — the Time and Attendance summary reports them,
// and the working-day count is the period minus them. Same source and types
// the document viewer marks days with (1 legal, 3 special).
$holiDays = [];
$hq = $conn->prepare("SELECT start_date, end_date FROM calendar_events
                      WHERE type IN (1,3) AND start_date <= ?
                        AND COALESCE(end_date, start_date) >= ?");
if ($hq) {
    $hq->bind_param('ss', $to, $from);
    $hq->execute();
    $hres = $hq->get_result();
    while ($h = $hres->fetch_assoc()) {
        $hs = max(strtotime($h['start_date']), strtotime($from));
        $he = min(strtotime($h['end_date'] ?: $h['start_date']), strtotime($to));
        for ($d = $hs; $d <= $he; $d = strtotime('+1 day', $d)) $holiDays[date('Y-m-d', $d)] = 1;
    }
}

$STATUS_LBL = [0 => 'Open', 1 => 'Pending Approval', 2 => 'Approved', 3 => 'Ready for Review'];
$periodLbl  = date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

/* ───────────────────────────── Look & feel ─────────────────────────────
 * One palette for both sheets, mirroring the purple the screen uses, so the
 * workbook reads as part of the same system rather than a raw dump.
 */
$BRAND      = '4E3483';   // headline band / strong text
$BRAND_DK   = '3A2563';   // header-band underline
$HEAD_FILL  = 'E7DFFA';   // table header
$FOOT_FILL  = 'DED2F5';   // total row
$SUB_FILL   = 'F4F0FC';   // subtitle strip
$ZEBRA      = 'FAF8FF';   // alternating body rows
$GRID       = 'E4DCF6';   // body cell borders
$RULE       = 'C9B8EA';   // header cell borders
$EXC_FILL   = 'FCE4E4';   // has exceptions
$LOW_FILL   = 'FFF2D9';   // low attendance
$WKND_FILL  = 'F1EEF7';   // weekend columns in the matrix
$MUTED      = '6B6580';
$DANGER     = 'B3261E';

// The Time and Attendance sheet's own two constants: the line it signs itself
// with, and the day length its "Total Hours Required" is reckoned in.
const TA_STANDARD_DAY = 8.0;
// Only the Time and Attendance sheet ships. The Summary and Day Matrix builders
// below are kept intact but skipped — flip this to false to bring them back
// rather than rewriting them.
const TA_ONLY = true;
$reportName = 'Mustard Seed Systems Bio-office Time and Attendance';

$ss = new Spreadsheet();
$ss->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
$ss->getProperties()
   ->setCreator('Payroll')
   ->setTitle('DTR Documents — ' . $periodLbl)
   ->setDescription('Exported ' . date('Y-m-d H:i'));

/** Title block shared by both sheets; returns the row the table header starts on. */
$titleBlock = function ($sheet, string $lastCol, string $subtitle) use ($batch, $periodLbl, $STATUS_LBL, $BRAND, $SUB_FILL, $MUTED, $rows) {
    $sheet->mergeCells("A1:{$lastCol}1");
    $sheet->setCellValue('A1', 'DAILY TIME RECORD — ' . strtoupper($subtitle));
    $t = $sheet->getStyle("A1:{$lastCol}1");
    $t->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
    $t->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($BRAND);
    $t->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(26);

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
    $m = $sheet->getStyle("A2:{$lastCol}2");
    $m->getFont()->setSize(9)->getColor()->setRGB($MUTED);
    $m->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($SUB_FILL);
    $m->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(2)->setRowHeight(18);
    $sheet->getRowDimension(3)->setRowHeight(7);   // hairline gap, not a full blank row

    return 4; // the table header
};

/** Style a header row band: fill, brand text, and a solid rule under it. */
$headBand = function ($sheet, string $range) use ($HEAD_FILL, $BRAND, $BRAND_DK, $RULE) {
    $s = $sheet->getStyle($range);
    $s->getFont()->setBold(true)->setSize(10)->getColor()->setRGB($BRAND);
    $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($HEAD_FILL);
    $s->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $s->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($RULE);
    $s->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB($BRAND_DK);
};

/** Print setup shared by both sheets — one page wide, header row repeated. */
$printSetup = function ($sheet, int $headerRow, string $lastCol, int $lastRow) {
    $ps = $sheet->getPageSetup();
    $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
       ->setPaperSize(PageSetup::PAPERSIZE_A4)
       ->setFitToWidth(1)->setFitToHeight(0)->setFitToPage(true)
       ->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow);
    $ps->setPrintArea("A1:{$lastCol}{$lastRow}");
    $sheet->getPageMargins()->setTop(0.4)->setBottom(0.45)->setLeft(0.3)->setRight(0.3);
    $sheet->getHeaderFooter()->setOddFooter('&L&8Payroll · DTR Documents&R&8Page &P of &N');
    $sheet->setShowGridlines(false);
};

/* ─────────────── Sheet 1: Time and Attendance (Bio-office format) ───────────
 * The report payroll has always worked from, rebuilt on our own figures: one
 * block per employee, every scan of the day laid out across In 1 … Out 6, and
 * the five hour columns frozen on the record — never re-derived here.
 *
 *   A Date · B (the Days Present value sits here) · C–N In 1 … Out 6
 *   O Hrs Required · P Tot Hrs Break · Q Hrs Worked · R Hrs OT · S Hrs UT
 */
$ta = $ss->getActiveSheet();
$ta->setTitle('Time and Attendance');
$ta->getTabColor()->setRGB($BRAND);

$TA_LAST  = 'S';
$TA_PUNCH = 12;                                   // In 1 … Out 6
$taHdr = ['Date', ''];
for ($i = 1; $i <= $TA_PUNCH / 2; $i++) { $taHdr[] = "In $i"; $taHdr[] = "Out $i"; }
array_push($taHdr, 'Hrs Required', 'Tot Hrs Break', 'Hrs Worked', 'Hrs OT', 'Hrs UT');

$r = $titleBlock($ta, $TA_LAST, 'Time and Attendance');
$r++;                                             // the report opens on a blank line

$G = ['brk' => 0.0, 'wh' => 0.0, 'ot' => 0.0, 'ut' => 0.0, 'pres' => 0, 'abs' => 0];
foreach ($rows as $e) {
    $name = strtoupper(trim(($e['last'] ?? '') . ', ' . ($e['first'] ?? '') . ' ' . ($e['mid'] ?? '')));
    $ta->mergeCells("A$r:E$r");
    $ta->setCellValue("A$r", 'Employee: ' . $name . '  (' . ($e['no'] ?? '') . ')');
    $ta->getStyle("A$r")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB($BRAND);
    $ta->getRowDimension($r)->setRowHeight(18);
    $r += 3;                                      // two blank lines under the name

    foreach ($taHdr as $i => $h) $ta->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $r, $h);
    $headBand($ta, "A{$r}:{$TA_LAST}{$r}");
    $ta->getRowDimension($r)->setRowHeight(24);
    $blkHead = $r;
    $r += 2;                                      // blank line under the header
    $blkFirst = $r;

    $B = ['brk' => 0.0, 'wh' => 0.0, 'ot' => 0.0, 'ut' => 0.0, 'pres' => 0, 'abs' => 0];
    $days = is_array($e['days'] ?? null) ? $e['days'] : [];
    ksort($days);
    // EVERY date in the cutoff gets a line, in order — the sheet is read as a
    // calendar, so a missing date makes the reader count rows to work out which
    // day is which. A day with nothing to report (day off, rest day, a date the
    // employee simply has no record for) prints its date and stops there:
    // blank, not a row of 0.00s that reads as an absence.
    foreach ($dates as $date) {
        $d = $days[$date] ?? null;
        $hasPunch = $d !== null && !empty($d['p']);
        $isOff    = $d === null
            || (!empty($d['rest']) && !$hasPunch && (float) ($d['wh'] ?? 0) <= 0);
        if ($isOff) {
            $ta->setCellValue("A$r", date('m/d/Y', strtotime($date)));
            $ta->getRowDimension($r)->setRowHeight(16);
            $r++;
            continue;
        }

        $wh  = round((float) ($d['wh'] ?? 0), 2);
        $ot  = round((float) ($d['ot'] ?? 0), 2);
        $ut  = round((float) ($d['ut'] ?? 0), 2);
        $req = round((float) ($d['req'] ?? 0), 2);
        // The unpaid break is only ever deducted from a day that produced work
        // hours — a rest day worked, an absence and a punchless shell all carry
        // the shift's break minutes but never spend them.
        $brk = $wh > 0 ? round(((float) ($d['brk'] ?? 0)) / 60, 2) : 0.0;

        $ta->setCellValue("A$r", date('m/d/Y', strtotime($date)));
        // Mirror the paper DTR EXACTLY: the same early-punch filter the figures
        // were computed under, then first-in / last-out — In 1 is the Arrival,
        // Out 1 the Departure the DTR prints beside these same hours. A tap the
        // pairing discarded (the previous night's out, a stray morning scan)
        // must not occupy the In 1 slot of a shift it never belonged to; it goes
        // to the unlabeled column B, exactly where the Bio-office report shows
        // it. An open shift therefore prints its one real punch as the In with
        // no Out — matching the sheet — instead of a fake-complete In/Out pair
        // sitting beside 0.00 worked hours.
        $stamps = [];
        foreach ((is_array($d['p'] ?? null) ? $d['p'] : []) as $pstr) {
            $ts = strtotime(str_replace('T', ' ', (string) $pstr));
            if ($ts !== false) $stamps[] = $ts;
        }
        sort($stamps);
        $kept  = $stamps;
        $stray = [];
        if (!empty($d['st']) && $stamps) {
            $cut = strtotime($date . ' ' . $d['st']) - dtr_early_grace_hours() * 3600;
            $k   = array_values(array_filter($stamps, function ($t) use ($cut) { return $t >= $cut; }));
            // Same fallback as the DTR sheet: if EVERY punch precedes the
            // cutoff the filter is skipped and all punches stand.
            if ($k) { $stray = array_values(array_diff($stamps, $k)); $kept = $k; }
        }
        if ($stray) {
            $ta->setCellValueExplicit("B$r",
                implode('  ', array_map(function ($t) { return date('g:i A', $t); }, $stray)),
                DataType::TYPE_STRING);
            $ta->getStyle("B$r")->getFont()->setSize(9)->getColor()->setRGB($MUTED);
        }
        if ($kept) {
            $ta->setCellValueExplicit("C$r", date('g:i A', $kept[0]), DataType::TYPE_STRING);
            if (count($kept) >= 2) {
                $ta->setCellValueExplicit("D$r", date('g:i A', $kept[count($kept) - 1]), DataType::TYPE_STRING);
            }
            // Scans between the arrival and the departure (a lunch-out, a stray
            // double tap). The DTR counts the day first-in -> last-out, so these
            // print as extra pairs the way the Bio-office grid always has —
            // muted, so they read as recorded taps rather than separate stints.
            $mid = array_slice($kept, 1, count($kept) - 2);
            foreach (array_slice($mid, 0, $TA_PUNCH - 2) as $i => $t) {
                $col = Coordinate::stringFromColumnIndex(5 + $i) . $r;   // E onward: In 2 …
                $ta->setCellValueExplicit($col, date('g:i A', $t), DataType::TYPE_STRING);
                $ta->getStyle($col)->getFont()->setSize(9)->getColor()->setRGB($MUTED);
            }
        }
        $ta->setCellValue("O$r", $req);
        $ta->setCellValue("P$r", $brk);
        $ta->setCellValue("Q$r", $wh);
        $ta->setCellValue("R$r", $ot);
        $ta->setCellValue("S$r", $ut);
        $ta->getRowDimension($r)->setRowHeight(16);

        // Present = the day rendered hours, paid or overtime. Absent = a day
        // that owed hours and rendered none; a rest day owes nothing, so it is
        // neither.
        if ($wh > 0 || $ot > 0)      $B['pres']++;
        elseif ($req > 0)            $B['abs']++;
        $B['brk'] += $brk; $B['wh'] += $wh; $B['ot'] += $ot; $B['ut'] += $ut;
        $r++;
    }
    $blkLast = $r - 1;

    if ($blkLast >= $blkFirst) {
        $body = $ta->getStyle("A{$blkFirst}:{$TA_LAST}{$blkLast}");
        $body->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($GRID);
        $body->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $ta->getStyle("A{$blkFirst}:N{$blkLast}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ta->getStyle("O{$blkFirst}:{$TA_LAST}{$blkLast}")->getNumberFormat()->setFormatCode('0.00');
        $ta->getStyle("O{$blkFirst}:{$TA_LAST}{$blkLast}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        for ($rr = $blkFirst; $rr <= $blkLast; $rr++) {
            if (($rr - $blkFirst) % 2 === 1) {
                $ta->getStyle("A{$rr}:{$TA_LAST}{$rr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($ZEBRA);
            }
        }
    }

    $ta->setCellValue("A$r", 'Total:');
    $ta->getStyle("A$r")->getFont()->setBold(true);
    $r++;
    $ta->setCellValue("A$r", 'Days Present:');
    $ta->setCellValue("C$r", $B['pres']);
    $ta->setCellValue("D$r", 'Days Absent:');
    $ta->setCellValue("G$r", $B['abs']);
    $ta->setCellValue("P$r", round($B['brk'], 2));
    $ta->setCellValue("Q$r", round($B['wh'], 2));
    $ta->setCellValue("R$r", round($B['ot'], 2));
    $ta->setCellValue("S$r", round($B['ut'], 2));
    $tf = $ta->getStyle("A$r:{$TA_LAST}$r");
    $tf->getFont()->setBold(true)->getColor()->setRGB('46297A');
    $tf->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($FOOT_FILL);
    $tf->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB($BRAND_DK);
    $ta->getStyle("P$r:{$TA_LAST}$r")->getNumberFormat()->setFormatCode('#,##0.00');
    $ta->getStyle("P$r:{$TA_LAST}$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ta->getStyle("C$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $ta->getStyle("G$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $ta->getRowDimension($r)->setRowHeight(18);

    foreach (['brk', 'wh', 'ot', 'ut', 'pres', 'abs'] as $k) $G[$k] += $B[$k];
    $r += 4;                                      // breathing room before the next block
}

/* Report summary — the tail of the Bio-office sheet. */
$r++;
$workDays = max(0, count($dates) - count($holiDays));
$sumTop   = $r;
$summary  = [
    $r     => ['TOTAL EMPLOYEES:', count($rows)],
    $r + 3 => ['Total Working Days:', $workDays],
    $r + 4 => ['Total Holidays W/in the Period:', count($holiDays)],
    $r + 6 => ['Total Hours Required:', round($workDays * TA_STANDARD_DAY, 2)],
];
foreach ($summary as $sr => $pair) {
    $ta->mergeCells("A$sr:C$sr");
    $ta->setCellValue("A$sr", $pair[0]);
    $ta->setCellValue("D$sr", $pair[1]);
}
$r += 6;
$ta->getStyle("D$r")->getNumberFormat()->setFormatCode('0.00');
$ta->getStyle("A{$sumTop}:D$r")->getFont()->setBold(true);
$ta->getStyle("D{$sumTop}:D$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$r += 3;
$ta->mergeCells("A$r:F$r");
$ta->mergeCells("G$r:K$r");
$ta->mergeCells("L$r:{$TA_LAST}$r");
$ta->setCellValue("A$r", $reportName);
$ta->setCellValue("G$r", 'Page -1 of 1');
$ta->setCellValue("L$r", 'Report Date: ' . date('F d, Y'));
$ta->getStyle("G$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$ta->getStyle("L$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$tfoot = $ta->getStyle("A$r:{$TA_LAST}$r");
$tfoot->getFont()->setSize(9)->getColor()->setRGB($MUTED);
$tfoot->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($RULE);
$taLastRow = $r;

$ta->getColumnDimension('A')->setWidth(12);
$ta->getColumnDimension('B')->setWidth(11);
for ($i = 0; $i < $TA_PUNCH; $i++) $ta->getColumnDimension(Coordinate::stringFromColumnIndex(3 + $i))->setWidth(11);
foreach (['O' => 13, 'P' => 14, 'Q' => 12, 'R' => 10, 'S' => 10] as $col => $w) {
    $ta->getColumnDimension($col)->setWidth($w);
}
$printSetup($ta, 4, $TA_LAST, $taLastRow);
$ta->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 3);   // blocks carry their own header

if (!TA_ONLY) {
    /* ─────────────────────────── Sheet 2: Summary ─────────────────────────── */
    $sh = $ss->createSheet();
    $sh->setTitle('Summary');
    $sh->getTabColor()->setRGB($BRAND);

    $hdr = ['#', 'Employee No', 'Employee', 'Department', 'Position', 'Days Logged',
            'Work Hours', 'Overtime', 'Undertime', 'Late', 'Approved', 'Pending',
            'Rejected', 'Flagged', 'Low Attendance'];
    $lastCol = 'O';                                    // 15 columns
    $r = $titleBlock($sh, $lastCol, 'Summary');

    foreach ($hdr as $i => $h) $sh->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $r, $h);
    $headBand($sh, "A{$r}:{$lastCol}{$r}");
    $sh->getRowDimension($r)->setRowHeight(30);
    $headerRow = $r;

    $T = ['days' => 0, 'wh' => 0.0, 'ot' => 0.0, 'ut' => 0.0, 'late' => 0.0, 'appr' => 0, 'pend' => 0, 'disa' => 0, 'exc' => 0];
    $n = 0;
    $tint    = [];   // row => fill, applied after the zebra so it wins
    $lowRows = [];   // rows whose "Low Attendance" cell reads YES
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
        $sh->getRowDimension($r)->setRowHeight(17);

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
        if (!empty($e['exc']))          $tint[$r] = $EXC_FILL;
        elseif (!empty($e['low_att']))  $tint[$r] = $LOW_FILL;
        if (!empty($e['low_att']))      $lowRows[] = $r;
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
    $foot->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB($BRAND_DK);
    $foot->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sh->getStyle("A$r:E$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sh->getRowDimension($r)->setRowHeight(20);
    $totalRow = $r;

    if ($lastRow >= $firstRow) {
        $body = $sh->getStyle("A{$firstRow}:{$lastCol}{$lastRow}");
        $body->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($GRID);
        $body->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        // Zebra first, then the exception tints on top of it.
        for ($rr = $firstRow; $rr <= $lastRow; $rr++) {
            if (isset($tint[$rr])) continue;
            if (($rr - $firstRow) % 2 === 1) {
                $sh->getStyle("A{$rr}:{$lastCol}{$rr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($ZEBRA);
            }
        }
        foreach ($tint as $rr => $fill) {
            $sh->getStyle("A{$rr}:{$lastCol}{$rr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        }
        foreach ($lowRows as $rr) {
            $sh->getStyle("O{$rr}")->getFont()->setBold(true)->getColor()->setRGB($DANGER);
        }
        $sh->getStyle("G{$firstRow}:J{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00;;"—"');
        $sh->getStyle("G{$totalRow}:J{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sh->getStyle("A{$firstRow}:B{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sh->getStyle("F{$firstRow}:F{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sh->getStyle("K{$firstRow}:O{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sh->getStyle("K{$firstRow}:N{$lastRow}")->getNumberFormat()->setFormatCode('0;;"—"');
        $sh->getStyle("C{$firstRow}:E{$lastRow}")->getAlignment()->setIndent(1);
    }

    // Legend — the tints mean nothing to whoever opens the file three weeks later.
    $lg = $totalRow + 2;
    $sh->mergeCells("A{$lg}:{$lastCol}{$lg}");
    $sh->setCellValue("A{$lg}", 'Legend:  red row = has flagged records needing a decision   ·   amber row = low attendance   ·   “—” = none');
    $sh->getStyle("A{$lg}")->getFont()->setSize(9)->setItalic(true)->getColor()->setRGB($MUTED);

    foreach (['A' => 5, 'B' => 14, 'C' => 30, 'D' => 26, 'E' => 22, 'F' => 8,
              'G' => 11, 'H' => 10, 'I' => 11, 'J' => 9, 'K' => 10, 'L' => 9,
              'M' => 10, 'N' => 9, 'O' => 15] as $col => $w) {
        $sh->getColumnDimension($col)->setWidth($w);
    }
    $sh->freezePane('D' . ($headerRow + 1));
    $sh->setAutoFilter("A{$headerRow}:{$lastCol}{$lastRow}");
    $printSetup($sh, $headerRow, $lastCol, $lg);

    /* ────────────────────────── Sheet 3: Day Matrix ───────────────────────── */
    $mx = $ss->createSheet();
    $mx->setTitle('Day Matrix');
    $mx->getTabColor()->setRGB('8C6FD1');

    // 3 identity columns + one per date + Total
    $mxLastIdx = 3 + count($dates) + 1;
    $mxLastCol = Coordinate::stringFromColumnIndex($mxLastIdx);
    $r = $titleBlock($mx, $mxLastCol, 'Day Matrix (work hours per day)');

    $mx->setCellValue("A$r", '#');
    $mx->setCellValue("B$r", 'Employee No');
    $mx->setCellValue("C$r", 'Employee');
    $weekendCols = [];
    foreach ($dates as $i => $d) {
        // Two lines: day number over the weekday initial — the whole cutoff still
        // fits on one screen, and weekends stay recognisable.
        $col = Coordinate::stringFromColumnIndex(4 + $i);
        $mx->setCellValue($col . $r, (int) date('j', strtotime($d)) . "\n" . date('D', strtotime($d)));
        if (in_array(date('N', strtotime($d)), ['6', '7'], true)) $weekendCols[] = $col;
    }
    $mx->setCellValue($mxLastCol . $r, 'TOTAL');
    $headBand($mx, "A{$r}:{$mxLastCol}{$r}");
    $mx->getRowDimension($r)->setRowHeight(32);
    $mxHeader = $r;

    $n = 0;
    $mxTotals = array_fill(0, count($dates), 0.0);
    $grand = 0.0;
    $zeroCells = [];   // logged days that produced no hours — tinted last so they win
    foreach ($rows as $e) {
        $r++; $n++;
        $mx->setCellValue("A$r", $n);
        $mx->setCellValueExplicit("B$r", (string) ($e['no'] ?? ''), DataType::TYPE_STRING);
        $mx->setCellValue("C$r", trim(($e['last'] ?? '') . ', ' . ($e['first'] ?? '')));
        $mx->getRowDimension($r)->setRowHeight(17);

        $days = is_array($e['days'] ?? null) ? $e['days'] : [];
        foreach ($dates as $i => $d) {
            $col = 4 + $i;
            if (!isset($days[$d])) continue;                 // no record that day — left blank
            $wh = round((float) ($days[$d]['wh'] ?? 0), 2);
            $mx->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $wh);
            $mxTotals[$i] += $wh;
            $grand += $wh;
            if ($wh <= 0) $zeroCells[] = Coordinate::stringFromColumnIndex($col) . $r;
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
    $mfoot->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB($BRAND_DK);
    $mfoot->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $mx->getStyle("A$r:C$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $mx->getRowDimension($r)->setRowHeight(20);
    $mxTotalRow = $r;

    if ($mxLast >= $mxFirst) {
        $rng  = "A{$mxFirst}:{$mxLastCol}{$mxLast}";
        $body = $mx->getStyle($rng);
        $body->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($GRID);
        $body->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        // Zebra → weekend columns → empty-day tints; each layer overrides the last.
        for ($rr = $mxFirst; $rr <= $mxLast; $rr++) {
            if (($rr - $mxFirst) % 2 === 1) {
                $mx->getStyle("A{$rr}:{$mxLastCol}{$rr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($ZEBRA);
            }
        }
        foreach ($weekendCols as $col) {
            $mx->getStyle("{$col}{$mxFirst}:{$col}{$mxLast}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($WKND_FILL);
        }
        foreach ($zeroCells as $cell) {
            $mx->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($EXC_FILL);
            $mx->getStyle($cell)->getFont()->getColor()->setRGB($DANGER);
        }
        $dFirst = Coordinate::stringFromColumnIndex(4);
        // Blank stays blank (no record); a logged-but-empty day prints a dash.
        $mx->getStyle("{$dFirst}{$mxFirst}:{$mxLastCol}{$mxLast}")->getNumberFormat()->setFormatCode('0.00;;"—"');
        $mx->getStyle("{$dFirst}{$mxFirst}:{$mxLastCol}{$mxLast}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $mx->getStyle("{$dFirst}{$mxTotalRow}:{$mxLastCol}{$mxTotalRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $mx->getStyle("A{$mxFirst}:B{$mxLast}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $mx->getStyle("C{$mxFirst}:C{$mxLast}")->getAlignment()->setIndent(1);
        $mx->getStyle("{$mxLastCol}{$mxFirst}:{$mxLastCol}{$mxLast}")->getFont()->setBold(true);
    }

    $mxLegend = $mxTotalRow + 2;
    $mx->mergeCells("A{$mxLegend}:{$mxLastCol}{$mxLegend}");
    $mx->setCellValue("A{$mxLegend}", 'Legend:  blank = no record that day   ·   red “—” = record logged but no work hours   ·   grey columns = weekends');
    $mx->getStyle("A{$mxLegend}")->getFont()->setSize(9)->setItalic(true)->getColor()->setRGB($MUTED);

    $mx->getColumnDimension('A')->setWidth(5);
    $mx->getColumnDimension('B')->setWidth(14);
    $mx->getColumnDimension('C')->setWidth(30);
    for ($i = 0; $i < count($dates); $i++) {
        $mx->getColumnDimension(Coordinate::stringFromColumnIndex(4 + $i))->setWidth(6.5);
    }
    $mx->getColumnDimension($mxLastCol)->setWidth(10);
    $mx->freezePane('D' . ($mxHeader + 1));
    $printSetup($mx, $mxHeader, $mxLastCol, $mxLegend);

}

$ss->setActiveSheetIndex(0);

// Filename the payroll staff asked for: the period spelled out the way they say
// it aloud — "may-16-312026" for May 16-31, 2026. The month is repeated only
// when the period crosses one, and the start year only when it crosses a year.
$tsF = strtotime($from);
$tsT = strtotime($to);
$slug = strtolower(date('F', $tsF)) . '-' . date('j', $tsF);
if (date('Y', $tsF) !== date('Y', $tsT))   $slug .= '-' . date('Y', $tsF);
if (date('Ym', $tsF) !== date('Ym', $tsT)) $slug .= '-' . strtolower(date('F', $tsT));
$slug .= '-' . date('j', $tsT) . date('Y', $tsT);

if (ob_get_length()) ob_end_clean();      // any stray notice would corrupt the zip

// Download-completion beacon for the Excel button's spinner. A form-submit
// download gives the page no load event, so the button cannot know when the
// file actually starts arriving; this cookie rides the download response
// itself, and dtr-documents.php polls document.cookie for it. Deliberately
// NOT HttpOnly — being read from JS is its entire job.
$dlToken = substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_POST['download_token'] ?? '')), 0, 40);
if ($dlToken !== '') {
    setcookie('dtr_export_done', $dlToken, [
        'expires'  => time() + 120,
        'path'     => '/',
        'samesite' => 'Lax',
    ]);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $slug . '.xlsx"');
header('Cache-Control: max-age=0, no-store');

(new Xlsx($ss))->save('php://output');
exit;
