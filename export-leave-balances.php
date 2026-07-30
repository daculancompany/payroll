<?php
/**
 * Leave Balances Report export — Excel (.xlsx) and PDF.
 *
 *   export-leave-balances.php?format=xlsx&year=2026&dept=3&type=0&view=all
 *   export-leave-balances.php?format=pdf&year=2026&emp=17
 *
 * Filters are identical to the on-screen report (leave-balances-report.php) and
 * the figures come from the same builder, so exports can never drift from the
 * screen. Passing emp=<id> narrows to a single employee and adds that
 * employee's leave request history.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login']) && empty($_SESSION['login_id'])) {
    http_response_code(403);
    exit('Not authorized.');
}

$conn = include 'db_connect.php';
require_once 'includes/leave_balances_report.php';

$format = strtolower($_GET['format'] ?? 'xlsx');
if (!in_array($format, ['xlsx', 'pdf'], true)) $format = 'xlsx';

$f    = lbr_filters($_GET);
$rep  = lbr_data($conn, $f);
$types      = $rep['types'];
$rows       = $rep['rows'];
$typeTotals = $rep['type_totals'];
$T          = $rep['totals'];

$company  = lbr_company($conn);
$summary  = lbr_filter_summary($conn, $f);
$genAt    = date('M d, Y g:i A');
$title    = 'Leave Balances Report';

// Single-employee export → richer, employee-specific document.
$soloName = '';
$requests = [];
if ($f['emp'] && $rows) {
    $soloName = $rows[0]['name'];
    $requests = lbr_leave_requests($conn, (int) $f['emp'], (int) $f['year']);
}

$slug  = 'leave-balances-' . (int) $f['year'] . ($f['emp'] ? '-emp' . (int) $f['emp'] : '') . '-' . date('Ymd');
$stMap = [0 => 'Pending', 1 => 'Approved', 2 => 'Rejected'];

// Both outputs are binary — a stray notice or warning printed into the stream
// corrupts the download, so from here on errors go to the log, never the page.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_writable(__DIR__ . '/logs')) { ini_set('error_log', __DIR__ . '/logs/php-error.log'); }

/* ═══════════════════════════════════════════════════════════════════════════
   EXCEL
   ═══════════════════════════════════════════════════════════════════════════ */
if ($format === 'xlsx') {
    require 'vendor/autoload.php';

    $ss    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $col   = fn(int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    $BRAND = '4F3288';
    $HEAD  = 'E1DCEC';
    $BAND  = 'F3F9F1';
    $NETBG = 'EEF4FC';

    $ss->getProperties()->setTitle($title)->setCreator($company)->setDescription($summary);

    // ── Sheet 1: the employee × leave type ledger ───────────────────────────
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Leave Balances');

    $fixed    = ['#', 'Employee No', 'Employee Name', 'Department', 'Position', 'Classification'];
    $nFixed   = count($fixed);
    $lastCol  = $col($nFixed + count($types) * 3 + 4);

    $sheet->setCellValue('A1', $company);
    $sheet->setCellValue('A2', $title . ' — ' . (int) $f['year'] . ($soloName ? ' — ' . $soloName : ''));
    $sheet->setCellValue('A3', $summary);
    $sheet->setCellValue('A4', 'Generated ' . $genAt);
    foreach (['A1', 'A2', 'A3', 'A4'] as $c) $sheet->mergeCells($c . ':' . $lastCol . substr($c, 1));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($BRAND);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('A3:A4')->getFont()->setSize(9)->getColor()->setRGB('7A828C');

    // Header rows (6 = leave type group, 7 = A/U/R)
    $hr1 = 6; $hr2 = 7;
    for ($i = 1; $i <= $nFixed; $i++) {
        $sheet->setCellValue($col($i) . $hr1, $fixed[$i - 1]);
        $sheet->mergeCells($col($i) . $hr1 . ':' . $col($i) . $hr2);
    }
    $c = $nFixed + 1;
    foreach ($types as $t) {
        $sheet->setCellValue($col($c) . $hr1, $t['name']);
        $sheet->mergeCells($col($c) . $hr1 . ':' . $col($c + 2) . $hr1);
        $sheet->getStyle($col($c) . $hr1)->getAlignment()->setHorizontal('center');
        $sheet->setCellValue($col($c) . $hr2, 'Available');
        $sheet->setCellValue($col($c + 1) . $hr2, 'Used');
        $sheet->setCellValue($col($c + 2) . $hr2, 'Remaining');
        $c += 3;
    }
    $totStart = $c;
    $sheet->setCellValue($col($c) . $hr1, 'TOTAL');
    $sheet->mergeCells($col($c) . $hr1 . ':' . $col($c + 3) . $hr1);
    $sheet->getStyle($col($c) . $hr1)->getAlignment()->setHorizontal('center');
    foreach (['Entitled', 'Used', 'Pending', 'Remaining'] as $k => $lbl) {
        $sheet->setCellValue($col($c + $k) . $hr2, $lbl);
    }

    $sheet->getStyle('A' . $hr1 . ':' . $lastCol . $hr2)->applyFromArray([
        'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => $BRAND]],
        'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $HEAD]],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'CFC8DE']]],
    ]);
    $sheet->getStyle($col($totStart) . $hr1 . ':' . $lastCol . $hr2)->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('DBE8FB');

    // Data
    $r = $hr2 + 1;
    $i = 1;
    foreach ($rows as $row) {
        $vals = [$i++, $row['employee_no'], $row['name'], $row['dept'], $row['position'],
                 $row['clasif'] . ($row['eligible'] ? '' : ' (not eligible)')];
        foreach ($types as $tid => $t) {
            $cell   = $row['cells'][$tid];
            $vals[] = (float) $cell['credits'];
            $vals[] = (float) $cell['used'];
            $vals[] = (float) $cell['remaining'];
        }
        $vals[] = (float) $row['tot']['credits'];
        $vals[] = (float) $row['tot']['used'];
        $vals[] = (float) $row['tot']['pending'];
        $vals[] = (float) $row['tot']['remaining'];
        foreach ($vals as $k => $v) $sheet->setCellValue($col($k + 1) . $r, $v);
        if ($i % 2 === 0) {
            $sheet->getStyle('A' . $r . ':' . $lastCol . $r)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($BAND);
        }
        $r++;
    }
    if (!$rows) {
        $sheet->setCellValue('A' . $r, 'No employees match these filters.');
        $sheet->mergeCells('A' . $r . ':' . $lastCol . $r);
        $r++;
    }

    // Totals
    $tr = $r;
    $sheet->setCellValue('A' . $tr, 'TOTAL (' . count($rows) . ' employees)');
    $sheet->mergeCells('A' . $tr . ':' . $col($nFixed) . $tr);
    $c = $nFixed + 1;
    foreach ($types as $tid => $t) {
        $sheet->setCellValue($col($c) . $tr,     (float) $typeTotals[$tid]['credits']);
        $sheet->setCellValue($col($c + 1) . $tr, (float) $typeTotals[$tid]['used']);
        $sheet->setCellValue($col($c + 2) . $tr, (float) $typeTotals[$tid]['remaining']);
        $c += 3;
    }
    $sheet->setCellValue($col($c) . $tr,     (float) $T['credits']);
    $sheet->setCellValue($col($c + 1) . $tr, (float) $T['used']);
    $sheet->setCellValue($col($c + 2) . $tr, (float) $T['pending']);
    $sheet->setCellValue($col($c + 3) . $tr, (float) $T['remaining']);
    $sheet->getStyle('A' . $tr . ':' . $lastCol . $tr)->applyFromArray([
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '0B5E31']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EEDD']],
        'borders' => ['top' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'B8D8C2']]],
    ]);

    // Body cosmetics
    if ($r > $hr2 + 1) {
        $sheet->getStyle('A' . ($hr2 + 1) . ':' . $lastCol . $tr)->applyFromArray([
            'font'    => ['size' => 9],
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E4ECE8']]],
        ]);
        $sheet->getStyle($col($nFixed + 1) . ($hr2 + 1) . ':' . $lastCol . $tr)
              ->getNumberFormat()->setFormatCode('0.#');
        $sheet->getStyle($col($totStart) . ($hr2 + 1) . ':' . $lastCol . $tr)->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($NETBG);
    }
    $widths = [5, 14, 28, 20, 20, 16];
    foreach ($widths as $k => $w) $sheet->getColumnDimension($col($k + 1))->setWidth($w);
    for ($k = $nFixed + 1; $k <= $nFixed + count($types) * 3 + 4; $k++) {
        $sheet->getColumnDimension($col($k))->setWidth(11);
    }
    $sheet->freezePane($col($nFixed + 1) . ($hr2 + 1));
    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
          ->setFitToWidth(1)->setFitToHeight(0);

    // ── Sheet 2: per leave type summary ─────────────────────────────────────
    $s2 = $ss->createSheet();
    $s2->setTitle('By Leave Type');
    $s2->setCellValue('A1', 'Utilization by Leave Type — ' . (int) $f['year']);
    $s2->mergeCells('A1:H1');
    $s2->getStyle('A1')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($BRAND);
    $s2hdr = ['Leave Type', 'Default Days', 'Year-End Policy', 'Entitled', 'Used', 'Pending', 'Remaining', 'Utilization %'];
    foreach ($s2hdr as $k => $h) $s2->setCellValue($col($k + 1) . '3', $h);
    $s2->getStyle('A3:H3')->applyFromArray([
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => $BRAND]],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $HEAD]],
        'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'CFC8DE']]],
    ]);
    $rr = 4;
    foreach ($types as $tid => $t) {
        $tt   = $typeTotals[$tid];
        $pct  = $tt['credits'] > 0 ? round($tt['used'] / $tt['credits'] * 100, 1) : 0;
        $pol  = $t['carryover']
            ? 'Carry over' . ($t['carryover_cap'] !== null ? ' (cap ' . lbr_fmt($t['carryover_cap']) . ')' : '')
            : 'Reset to default';
        foreach ([$t['name'], (float) $t['days_allowed'], $pol, (float) $tt['credits'],
                  (float) $tt['used'], (float) $tt['pending'], (float) $tt['remaining'], $pct] as $k => $v) {
            $s2->setCellValue($col($k + 1) . $rr, $v);
        }
        $rr++;
    }
    $s2->setCellValue('A' . $rr, 'TOTAL');
    $s2->setCellValue('D' . $rr, (float) $T['credits']);
    $s2->setCellValue('E' . $rr, (float) $T['used']);
    $s2->setCellValue('F' . $rr, (float) $T['pending']);
    $s2->setCellValue('G' . $rr, (float) $T['remaining']);
    $s2->setCellValue('H' . $rr, (float) $T['utilization']);
    $s2->getStyle('A' . $rr . ':H' . $rr)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '0B5E31']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EEDD']],
    ]);
    $s2->getStyle('A4:H' . $rr)->getFont()->setSize(9);
    foreach ([30, 13, 22, 12, 10, 10, 12, 13] as $k => $w) $s2->getColumnDimension($col($k + 1))->setWidth($w);

    // ── Sheet 3 (single employee only): leave request history ───────────────
    if ($f['emp'] && $requests) {
        $s3 = $ss->createSheet();
        $s3->setTitle('Leave Requests');
        $s3->setCellValue('A1', 'Leave Requests — ' . $soloName . ' (' . (int) $f['year'] . ')');
        $s3->mergeCells('A1:F1');
        $s3->getStyle('A1')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($BRAND);
        foreach (['Date Applied', 'Leave Type', 'From', 'To', 'Days', 'Status', 'Reason'] as $k => $h) {
            $s3->setCellValue($col($k + 1) . '3', $h);
        }
        $s3->getStyle('A3:G3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => $BRAND]],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $HEAD]],
        ]);
        $rr = 4;
        foreach ($requests as $q) {
            foreach ([
                date('Y-m-d', strtotime($q['date_applied'])), $q['type_name'],
                date('Y-m-d', strtotime($q['date_from'])), date('Y-m-d', strtotime($q['date_to'])),
                (float) $q['duration'], $stMap[(int) $q['status']] ?? 'Unknown',
                (string) ($q['reason'] ?? ''),
            ] as $k => $v) {
                $s3->setCellValue($col($k + 1) . $rr, $v);
            }
            $rr++;
        }
        $s3->getStyle('A4:G' . ($rr - 1))->getFont()->setSize(9);
        foreach ([14, 22, 13, 13, 8, 12, 50] as $k => $w) $s3->getColumnDimension($col($k + 1))->setWidth($w);
    }

    $ss->setActiveSheetIndex(0);

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $slug . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
   PDF
   ═══════════════════════════════════════════════════════════════════════════ */
require 'vendor/autoload.php';

$h    = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$span = 3 + count($types) * 3 + 4;

ob_start();
?>
<style>
    @page { margin: 16px 18px 26px 18px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1a1a1a; }
    .hdr { border-bottom: 2px solid #673bb6; padding-bottom: 6px; margin-bottom: 8px; }
    .hdr .co { font-size: 14px; font-weight: bold; color: #4f3288; }
    .hdr .ti { font-size: 11px; font-weight: bold; margin-top: 1px; }
    .hdr .mt { font-size: 7.5px; color: #7a828c; margin-top: 2px; }
    .kpis { width: 100%; border-collapse: separate; border-spacing: 4px 0; margin-bottom: 8px; }
    .kpis td { width: 16.6%; background: #f5f3f8; border: 1px solid #ddd9e7; border-top: 2px solid #673bb6; padding: 5px 7px; }
    .kpis .k { font-size: 6.5px; text-transform: uppercase; letter-spacing: .4px; color: #7a828c; font-weight: bold; }
    .kpis .v { font-size: 12px; font-weight: bold; color: #4f3288; }
    .kpis .s { font-size: 6.5px; color: #96a0a8; }
    h2 { font-size: 9px; color: #4f3288; text-transform: uppercase; letter-spacing: .5px;
         margin: 10px 0 4px; border-left: 3px solid #673bb6; padding-left: 5px; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { background: #e1dcec; color: #4f3288; font-size: 7px; text-transform: uppercase;
                    padding: 4px 3px; border: 1px solid #cfc8de; }
    table.grid td { font-size: 7.5px; padding: 3px; border: 1px solid #e4ece8; }
    table.grid tbody tr:nth-child(even) td { background: #f6faf6; }
    table.grid tfoot td { background: #d9eedd; color: #0b5e31; font-weight: bold; border-top: 1.5px solid #b8d8c2; }
    .num { text-align: right; }
    .ctr { text-align: center; }
    .net { background: #eef4fc !important; color: #1e50a0; font-weight: bold; }
    .muted { color: #9aa3a9; }
    .sub { font-size: 6.5px; color: #8a9299; }
    .note { font-size: 6.5px; color: #7a828c; margin-top: 8px; border-top: 1px solid #e4ece8; padding-top: 5px; }
    .st-1 { color: #1c7a43; font-weight: bold; }
    .st-0 { color: #a76b09; font-weight: bold; }
    .st-2 { color: #b3352f; font-weight: bold; }
</style>

<div class="hdr">
    <div class="co"><?= $h($company) ?></div>
    <div class="ti"><?= $h($title) ?> — <?= (int) $f['year'] ?><?= $soloName ? ' — ' . $h($soloName) : '' ?></div>
    <div class="mt"><?= $h($summary) ?></div>
    <div class="mt">Generated <?= $h($genAt) ?></div>
</div>

<table class="kpis"><tr>
    <td><div class="k">Employees</div><div class="v"><?= number_format($T['employees']) ?></div><div class="s"><?= count($types) ?> leave type(s)</div></td>
    <td><div class="k">Total Entitled</div><div class="v"><?= lbr_fmt($T['credits']) ?></div><div class="s">days granted</div></td>
    <td><div class="k">Days Used</div><div class="v"><?= lbr_fmt($T['used']) ?></div><div class="s"><?= $T['utilization'] ?>% utilized</div></td>
    <td><div class="k">Days Remaining</div><div class="v"><?= lbr_fmt($T['remaining']) ?></div><div class="s"><?= (int) $T['exhausted'] ?> at zero</div></td>
    <td><div class="k">Pending</div><div class="v"><?= lbr_fmt($T['pending']) ?></div><div class="s">awaiting approval</div></td>
    <td><div class="k">No Leave Taken</div><div class="v"><?= number_format($T['untouched']) ?></div><div class="s">employees</div></td>
</tr></table>

<h2>Utilization by Leave Type</h2>
<table class="grid">
    <thead><tr>
        <th style="text-align:left;">Leave Type</th><th>Default</th><th>Year-End Policy</th>
        <th>Entitled</th><th>Used</th><th>Pending</th><th>Remaining</th><th>Takers</th><th>Utilization</th>
    </tr></thead>
    <tbody>
        <?php if (!$types): ?>
            <tr><td colspan="9" class="ctr muted">No paid leave types configured.</td></tr>
        <?php else: foreach ($types as $tid => $t): $tt = $typeTotals[$tid];
            $pct = $tt['credits'] > 0 ? round($tt['used'] / $tt['credits'] * 100, 1) : 0; ?>
            <tr>
                <td><b><?= $h($t['name']) ?></b></td>
                <td class="ctr"><?= lbr_fmt($t['days_allowed']) ?></td>
                <td class="ctr"><?= $t['carryover'] ? 'Carry over' . ($t['carryover_cap'] !== null ? ' (cap ' . lbr_fmt($t['carryover_cap']) . ')' : '') : 'Reset to default' ?></td>
                <td class="num"><?= lbr_fmt($tt['credits']) ?></td>
                <td class="num"><?= lbr_fmt($tt['used']) ?></td>
                <td class="num"><?= lbr_fmt($tt['pending']) ?></td>
                <td class="num"><b><?= lbr_fmt($tt['remaining']) ?></b></td>
                <td class="ctr"><?= (int) $tt['takers'] ?></td>
                <td class="num"><?= $pct ?>%</td>
            </tr>
        <?php endforeach; endif; ?>
    </tbody>
    <tfoot><tr>
        <td colspan="3">TOTAL</td>
        <td class="num"><?= lbr_fmt($T['credits']) ?></td>
        <td class="num"><?= lbr_fmt($T['used']) ?></td>
        <td class="num"><?= lbr_fmt($T['pending']) ?></td>
        <td class="num"><?= lbr_fmt($T['remaining']) ?></td>
        <td class="ctr">—</td>
        <td class="num"><?= $T['utilization'] ?>%</td>
    </tr></tfoot>
</table>

<h2>Employee Leave Ledger &nbsp;<span style="font-weight:normal;text-transform:none;color:#8a9299;">(A = available, U = used, R = remaining)</span></h2>
<table class="grid">
    <thead>
        <tr>
            <th rowspan="2" style="text-align:left;">#</th>
            <th rowspan="2" style="text-align:left;">Employee</th>
            <th rowspan="2" style="text-align:left;">Department</th>
            <?php foreach ($types as $t): ?><th colspan="3"><?= $h($t['name']) ?></th><?php endforeach; ?>
            <th colspan="4" class="net">Total</th>
        </tr>
        <tr>
            <?php foreach ($types as $t): ?><th>A</th><th>U</th><th>R</th><?php endforeach; ?>
            <th class="net">Entitled</th><th class="net">Used</th><th class="net">Pending</th><th class="net">Remaining</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $span ?>" class="ctr muted" style="padding:10px;">No employees match these filters.</td></tr>
        <?php else: $i = 1; foreach ($rows as $row): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= $h($row['name']) ?><div class="sub"><?= $h($row['employee_no']) ?> · <?= $h($row['position']) ?><?= $row['eligible'] ? '' : ' · not eligible' ?></div></td>
                <td><?= $h($row['dept']) ?><div class="sub"><?= $h($row['clasif']) ?></div></td>
                <?php foreach ($types as $tid => $t): $c = $row['cells'][$tid]; ?>
                    <td class="num"><?= lbr_fmt($c['credits']) ?></td>
                    <td class="num<?= $c['used'] <= 0 ? ' muted' : '' ?>"><?= $c['used'] > 0 ? lbr_fmt($c['used']) : '—' ?></td>
                    <td class="num"<?= $c['remaining'] <= 0 ? ' style="color:#b3352f;font-weight:bold;"' : '' ?>><?= lbr_fmt($c['remaining']) ?></td>
                <?php endforeach; ?>
                <td class="num net"><?= lbr_fmt($row['tot']['credits']) ?></td>
                <td class="num net"><?= lbr_fmt($row['tot']['used']) ?></td>
                <td class="num net"><?= $row['tot']['pending'] > 0 ? lbr_fmt($row['tot']['pending']) : '—' ?></td>
                <td class="num net"><?= lbr_fmt($row['tot']['remaining']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot><tr>
        <td colspan="3">TOTAL (<?= count($rows) ?> employees)</td>
        <?php foreach ($types as $tid => $t): $tt = $typeTotals[$tid]; ?>
            <td class="num"><?= lbr_fmt($tt['credits']) ?></td>
            <td class="num"><?= lbr_fmt($tt['used']) ?></td>
            <td class="num"><?= lbr_fmt($tt['remaining']) ?></td>
        <?php endforeach; ?>
        <td class="num net"><?= lbr_fmt($T['credits']) ?></td>
        <td class="num net"><?= lbr_fmt($T['used']) ?></td>
        <td class="num net"><?= lbr_fmt($T['pending']) ?></td>
        <td class="num net"><?= lbr_fmt($T['remaining']) ?></td>
    </tr></tfoot>
    <?php endif; ?>
</table>

<?php if ($f['emp'] && $requests): ?>
<h2>Leave Requests — <?= $h($soloName) ?> (<?= (int) $f['year'] ?>)</h2>
<table class="grid">
    <thead><tr>
        <th style="text-align:left;">Date Applied</th><th style="text-align:left;">Leave Type</th>
        <th>From</th><th>To</th><th>Days</th><th>Status</th><th style="text-align:left;">Reason</th>
    </tr></thead>
    <tbody>
        <?php foreach ($requests as $q): ?>
        <tr>
            <td><?= date('M d, Y', strtotime($q['date_applied'])) ?></td>
            <td><?= $h($q['type_name']) ?></td>
            <td class="ctr"><?= date('M d, Y', strtotime($q['date_from'])) ?></td>
            <td class="ctr"><?= date('M d, Y', strtotime($q['date_to'])) ?></td>
            <td class="num"><?= lbr_fmt($q['duration']) ?></td>
            <td class="ctr st-<?= (int) $q['status'] ?>"><?= $h($stMap[(int) $q['status']] ?? 'Unknown') ?></td>
            <td><?= $h(mb_strimwidth((string) ($q['reason'] ?? ''), 0, 90, '…')) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div class="note">
    Available credits come from each employee's <?= (int) $f['year'] ?> balance, falling back to the leave type's default entitlement when no balance has been set.
    Used days count approved requests starting within <?= (int) $f['year'] ?>; pending days are filed but not yet fully approved and are not deducted from remaining.
    Only paid, active leave types are included.
</div>
<?php
$html = ob_get_clean();

/**
 * dompdf writes a temporary subset of every embedded font while building the
 * PDF. On XAMPP the web-server user (daemon) usually cannot write to PHP's
 * default temp dir, and dompdf then dies deep inside FontLib with
 * "Font not found in:" — which reaches the browser as a blank 500.
 * So hand it the first directory we can actually prove is writable.
 */
$tmpDir = null;
foreach ([__DIR__ . '/tmp', sys_get_temp_dir(), ini_get('upload_tmp_dir'), session_save_path()] as $cand) {
    if ($cand && is_dir($cand) && is_writable($cand)) { $tmpDir = $cand; break; }
}

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
if ($tmpDir) { $options->set('tempDir', $tmpDir); }
$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Page x of y footer.
$canvas = $dompdf->getCanvas();
$canvas->page_text(760, 566, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 7, [0.55, 0.58, 0.6]);
$canvas->page_text(18, 566, $company . ' · ' . $title . ' · ' . $genAt, null, 7, [0.55, 0.58, 0.6]);

// Render to a string first, then throw away anything already buffered (PHP
// startup warnings, vendor notices) so the download is a clean PDF and never
// the browser's "failed to load document". Sent as an attachment — the inline
// viewer is what breaks when a single stray byte leads the response.
$pdf = $dompdf->output();
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $slug . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $pdf;
exit;
