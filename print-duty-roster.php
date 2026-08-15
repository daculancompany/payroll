<?php
/**
 * Duty Roster → PDF (print view).
 *
 * Same data sources as export-duty-roster.php (dutyRosterEmployees,
 * dutyZoneMap, dutyLeaveMap, dutyShiftCodes, duty_shift_palette) so the
 * printed sheet can never disagree with the Excel export or the on-screen
 * grid about who is on it or what colour a shift gets.
 *
 * print-duty-roster.php?period=2026-08-2&department_id=10&area_id=31
 *
 * Inline, not attachment — this is meant to open straight into the browser's
 * PDF viewer so Ctrl+P / the viewer's own Print button works immediately,
 * the way a "Print" action is expected to behave (export-duty-roster.php's
 * .xlsx stays a download; a spreadsheet has no such viewer to open into).
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login'])) {
    header('Location: index.php');
    exit;
}
require 'vendor/autoload.php';
include 'db_connect.php';
include 'admin_class.php';

require_page_access('duty-roster');

$crud   = new Action();
$period = (string) ($_GET['period'] ?? '');
$range  = $crud->dutyPeriodRange($period);
if (!$range) { http_response_code(400); echo 'Invalid cutoff period.'; exit; }
$deptId = (int) ($_GET['department_id'] ?? 0);
$areaId = (int) ($_GET['area_id'] ?? 0);
// A Department Head / Supervisor is pinned to their own ward whatever the URL
// says — same rule export-duty-roster.php enforces, for the same reason.
$scopeId = $crud->dutyScopeId();
if ($scopeId > 0) $deptId = $scopeId;

$label = date('M j', strtotime($range['from'])) . ' – ' . date('M j, Y', strtotime($range['to']));

$deptName = 'All departments';
if ($deptId > 0) {
    $dq = $conn->query("SELECT name FROM department WHERE id = $deptId LIMIT 1");
    $deptName = ($dq && ($d = $dq->fetch_assoc())) ? $d['name'] : ('Department #' . $deptId);
}
$areaName = '';
if ($areaId > 0) {
    $aq = $conn->query("SELECT name FROM area WHERE id = $areaId LIMIT 1");
    $areaName = ($aq && ($a = $aq->fetch_assoc())) ? $a['name'] : '';
}

$employees = $crud->dutyRosterEmployees($deptId, $range['from'], $range['to'], $areaId);
if (!$employees) { http_response_code(404); echo 'No employees in this view.'; exit; }
$empIds = array_column($employees, 'id');
$zones  = $crud->dutyZoneMap($range['from'], $range['to'], $empIds);
$leaves = $crud->dutyLeaveMap($range['from'], $range['to'], $empIds);

$days = [];
for ($t = strtotime($range['from']); $t <= strtotime($range['to']); $t = strtotime('+1 day', $t)) {
    $days[] = ['date' => date('Y-m-d', $t), 'dom' => (int) date('j', $t), 'dow' => strtoupper(date('D', $t)), 'w' => (int) date('w', $t)];
}

$holidays = [];
$hq = $conn->query("SELECT start_date, end_date, title FROM calendar_events
    WHERE type IN (1,3) AND start_date <= '" . $conn->real_escape_string($range['to']) . "'
      AND COALESCE(end_date, start_date) >= '" . $conn->real_escape_string($range['from']) . "'");
while ($hq && ($h = $hq->fetch_assoc())) {
    for ($t = strtotime($h['start_date']); $t <= strtotime($h['end_date'] ?: $h['start_date']); $t = strtotime('+1 day', $t)) {
        $holidays[date('Y-m-d', $t)] = $h['title'];
    }
}

// Same shift catalogue + colour ramp export-duty-roster.php and the web grid
// use — one definition, so a printed 6-2 is the same pale blue everywhere.
$shifts  = $crud->dutyShiftCodes();
$palette = duty_shift_palette($conn);
foreach ($shifts as $i => $s) {
    $p = $palette[$s['id']] ?? null;
    $shifts[$i]['bg'] = $p['bg'] ?? '#f4f1fa';
    $shifts[$i]['fg'] = $p['fg'] ?? '#28223b';
}
$shiftById = [];
foreach ($shifts as $s) $shiftById[$s['id']] = $s;

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

// Per-day coverage counts — a static snapshot of the same numbers the web
// grid's live footer and the Excel export's COUNTIF formulas show.
$coverage = [];   // date => [shift_id => n], plus 'OFF' => n
foreach ($days as $d) $coverage[$d['date']] = ['OFF' => 0];
foreach ($cells as $c) {
    if (!isset($coverage[$c['work_date']])) continue;
    if ((int) $c['is_rest_day'] === 1) {
        $coverage[$c['work_date']]['OFF']++;
        if ($c['schedule_id'] !== null) {
            $coverage[$c['work_date']][$c['schedule_id']] = ($coverage[$c['work_date']][$c['schedule_id']] ?? 0) + 1;
        }
    } elseif ($c['schedule_id'] !== null) {
        $coverage[$c['work_date']][$c['schedule_id']] = ($coverage[$c['work_date']][$c['schedule_id']] ?? 0) + 1;
    }
}
$anyDraft = false;
foreach ($cells as $c) if ((int) $c['status'] === 0) { $anyDraft = true; break; }

$genAt = date('M d, Y g:i A');
$h     = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug  = 'duty-roster-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($deptName)) . '-' . $range['from'];

ob_start();
?>
<style>
    @page { margin: 14px 16px 30px 16px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 7px; color: #1a1a1a; }
    .hdr { border-bottom: 2px solid #6642aa; padding-bottom: 6px; margin-bottom: 7px; }
    .hdr .ti { font-size: 13px; font-weight: bold; color: #4e3483; }
    .hdr .sub { font-size: 8.5px; color: #4b4658; margin-top: 2px; }
    .hdr .mt { font-size: 7px; color: #8a869a; margin-top: 2px; }

    table.roster { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.roster th, table.roster td { border: 0.75px solid #ddd9e7; padding: 2px 1px; text-align: center; }
    table.roster col.namecol { width: 108px; }
    table.roster th.namehdr, table.roster td.namecell { text-align: left; padding-left: 4px; }
    table.roster thead th { background: #eae5f6; color: #3d2b6b; font-weight: bold; font-size: 7px; }
    table.roster thead th.dom { font-size: 9px; }
    table.roster thead th.we { color: #c1544f; }
    table.roster thead th.hol { background: #fff4e6; }
    table.roster td.namecell b { font-size: 7.5px; }
    table.roster td.namecell .no { font-size: 6px; color: #8a869a; }
    table.roster tbody tr:nth-child(even) td.namecell { background: #faf9fc; }
    table.roster td.off { background: #f4f4f6; color: #8c8998; font-weight: bold; }
    table.roster td.combo { background: #ffe0b2; color: #7a3c00; font-weight: bold; }
    table.roster td.locked { background: #f0f0f2; color: #a9a5b6; }
    td.leaveclash { background: #fdecea !important; color: #c62828 !important; font-weight: bold; border: 1px solid #e79b98 !important; }
    td.onleave { border-bottom: 2px solid #b9a3e8 !important; }
    .draftmark { color: #b8580a; }

    .legend { margin-top: 8px; font-size: 6.5px; color: #4b4658; }
    .legend span { display: inline-block; margin-right: 10px; }
    .legend i.sw { display: inline-block; width: 8px; height: 8px; border: 0.75px solid #ccc; margin-right: 3px; vertical-align: middle; }

    h2 { font-size: 8.5px; color: #4e3483; text-transform: uppercase; letter-spacing: .4px;
         margin: 10px 0 3px; border-left: 3px solid #6642aa; padding-left: 5px; }
    table.cov { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.cov th, table.cov td { border: 0.75px solid #e1dfdd; padding: 2px 1px; text-align: center; font-size: 6.5px; }
    table.cov th.namehdr, table.cov td.shiftname { text-align: left; padding-left: 4px; }
    table.cov col.namecol { width: 108px; }
    table.cov thead th { background: #f7f6fa; color: #4e3483; font-weight: bold; }
    table.cov tr.total td { background: #eae5f6; color: #3d2b6b; font-weight: bold; }
</style>

<div class="hdr">
    <div class="ti">Duty Roster — <?= $h($deptName) ?><?= $areaName ? ' · ' . $h($areaName) : '' ?></div>
    <div class="sub"><?= $h($label) ?> · <?= count($employees) ?> employee<?= count($employees) === 1 ? '' : 's' ?></div>
    <div class="mt">Generated <?= $h($genAt) ?><?= $anyDraft ? ' · cells marked with * are still drafts, not yet published' : '' ?></div>
</div>

<table class="roster">
    <col class="namecol">
    <?php foreach ($days as $d): ?><col><?php endforeach; ?>
    <thead>
        <tr>
            <th class="namehdr" rowspan="2">Employee</th>
            <?php foreach ($days as $d): $hol = isset($holidays[$d['date']]); $we = ($d['w'] === 0 || $d['w'] === 6); ?>
                <th class="dom<?= $we ? ' we' : '' ?><?= $hol ? ' hol' : '' ?>"><?= $d['dom'] ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach ($days as $d): $we = ($d['w'] === 0 || $d['w'] === 6); ?>
                <th class="<?= $we ? 'we' : '' ?>"><?= $d['dow'] ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($employees as $emp): ?>
        <tr>
            <td class="namecell"><b><?= $h($emp['name']) ?></b><div class="no"><?= $h($emp['employee_no']) ?></div></td>
            <?php foreach ($days as $d):
                $k    = $emp['id'] . '|' . $d['date'];
                $c    = $cells[$k] ?? null;
                $zone = $zones[$k] ?? 'free';
                $lv   = $leaves[$k] ?? null;
                $cls  = [];
                $txt  = '';
                $style = '';
                if ($c) {
                    $rest = (int) $c['is_rest_day'] === 1;
                    $sid  = $c['schedule_id'] !== null ? (int) $c['schedule_id'] : null;
                    if ($rest && $sid !== null && isset($shiftById[$sid])) {
                        $cls[] = 'combo';
                        $txt   = $shiftById[$sid]['code'] . '+OFF';
                    } elseif ($rest) {
                        $cls[] = 'off';
                        $txt   = 'OFF';
                    } elseif ($sid !== null && isset($shiftById[$sid])) {
                        $txt   = $shiftById[$sid]['code'];
                        $style = 'background:' . $shiftById[$sid]['bg'] . ';color:' . $shiftById[$sid]['fg'] . ';font-weight:bold;';
                    }
                    if ((int) $c['status'] === 0 && $txt !== '') $txt .= '<span class="draftmark">*</span>';
                }
                if ($zone === 'locked') $cls[] = 'locked';
                if ($lv) {
                    if ((int) ($c['is_rest_day'] ?? 0) === 0 && $c && $c['schedule_id'] !== null) $cls[] = 'leaveclash';
                    else $cls[] = 'onleave';
                }
            ?>
            <td class="<?= implode(' ', $cls) ?>" style="<?= $style ?>" title="<?= $h(($lv['name'] ?? '') . ($holidays[$d['date']] ?? '')) ?>"><?= $txt ?></td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="legend">
    <span><i class="sw" style="background:#f4f4f6;"></i>OFF — rest day</span>
    <span><i class="sw" style="background:#ffe0b2;"></i>CODE+OFF — rest day with a shift on file</span>
    <span><i class="sw" style="background:#f0f0f2;"></i>Grey border — locked (DTR approved)</span>
    <span><i class="sw" style="background:#fdecea;border-color:#e79b98;"></i>Red — shift planned on approved leave</span>
    <span><i class="sw" style="background:#fff;border-bottom:2px solid #b9a3e8;"></i>Purple underline — on approved leave</span>
</div>

<h2>Shift Legend</h2>
<table class="cov">
    <col class="namecol">
    <thead><tr><th class="namehdr">Code</th><th>Shift</th><th>Time</th><th>Hours</th></tr></thead>
    <tbody>
        <?php foreach ($shifts as $s): ?>
        <tr>
            <td class="shiftname" style="background:<?= $h($s['bg']) ?>;color:<?= $h($s['fg']) ?>;font-weight:bold;"><?= $h($s['code']) ?></td>
            <td class="shiftname"><?= $h($s['desc']) ?></td>
            <td><?= $h($s['start']) ?> – <?= $h($s['end']) ?></td>
            <td><?= $h($s['hours']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h2>Coverage — on duty per day</h2>
<table class="cov">
    <col class="namecol">
    <?php foreach ($days as $d): ?><col><?php endforeach; ?>
    <thead>
        <tr>
            <th class="namehdr">Shift</th>
            <?php foreach ($days as $d): ?><th><?= $d['dom'] ?></th><?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($shifts as $s): ?>
        <tr>
            <td class="shiftname"><?= $h($s['code']) ?></td>
            <?php foreach ($days as $d): ?>
                <td><?= (int) ($coverage[$d['date']][$s['id']] ?? 0) ?: '·' ?></td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td class="shiftname">OFF</td>
            <?php foreach ($days as $d): ?>
                <td><?= (int) ($coverage[$d['date']]['OFF'] ?? 0) ?: '·' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr class="total">
            <td class="shiftname">On duty</td>
            <?php foreach ($days as $d):
                $onDuty = 0;
                foreach ($shifts as $s) $onDuty += (int) ($coverage[$d['date']][$s['id']] ?? 0);
            ?>
                <td><?= $onDuty ?></td>
            <?php endforeach; ?>
        </tr>
    </tbody>
</table>
<?php
$html = ob_get_clean();

// Same writable-tempdir guard as export-leave-balances.php / payslip.php —
// dompdf writes embedded-font subsets to disk while building the PDF, and
// XAMPP's web-server user often cannot write to PHP's default temp dir.
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
$dompdf->setPaper('legal', 'landscape');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$canvas->page_text(940, 605, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 7, [0.55, 0.58, 0.6]);
$canvas->page_text(16, 605, $deptName . ' · ' . $label . ' · ' . $genAt, null, 7, [0.55, 0.58, 0.6]);

$pdf = $dompdf->output();
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/pdf');
// Inline: opens straight into the browser's PDF viewer so Print is one click
// away, instead of landing in Downloads first.
header('Content-Disposition: inline; filename="' . $slug . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $pdf;
exit;
