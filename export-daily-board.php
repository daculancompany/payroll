<?php
/**
 * Daily Attendance Board export — CSV.
 *
 *   export-daily-board.php?date=2026-08-17
 *   export-daily-board.php?date=2026-08-17&status=Absent
 *   export-daily-board.php?date=2026-08-17&status=Late&q=nursing
 *
 * status / q mirror the board's summary-card filter and search box, and the rows
 * come from the same builder the screen uses (includes/daily_board_data.php), so
 * an exported absent list can never disagree with the one on screen. Department /
 * area scoping is applied there too — an approver exports only their own people.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login']) && empty($_SESSION['login_id'])) {
    http_response_code(403);
    exit('Not authorized.');
}

$conn = include 'db_connect.php';
require_once __DIR__ . '/includes/daily_board_data.php';

$date   = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$q      = strtolower(trim($_GET['q'] ?? ''));
$group  = ($_GET['group'] ?? 'shift') === 'dept' ? 'dept' : 'shift';

if (!isset(DAILY_BOARD_STATUSES[$status])) $status = '';

$B    = daily_board_data($conn, $date);
$date = $B['date'];

// Same two predicates applyFilters() runs client-side.
$rows = [];
foreach ($B['employees'] as $r) {
    if ($status !== '' && $r['att']['label'] !== $status) continue;
    if ($q !== '') {
        $hay = strtolower($r['lastname'] . ' ' . $r['firstname'] . ' ' . $r['employee_no'] . ' ' . ($r['dept_name'] ?? '') . ' ' . ($r['shift_desc'] ?? ''));
        if (strpos($hay, $q) === false) continue;
    }
    $rows[] = $r;
}

// Sort into the order the board shows them, so the file reads like the screen.
usort($rows, function ($a, $b) use ($group) {
    $ka = $group === 'dept' ? ($a['dept_name'] ?: '~') : sprintf('%s', $a['shift_start'] ?: '~');
    $kb = $group === 'dept' ? ($b['dept_name'] ?: '~') : sprintf('%s', $b['shift_start'] ?: '~');
    return $ka === $kb ? strcmp($a['lastname'] . $a['firstname'], $b['lastname'] . $b['firstname']) : strcmp($ka, $kb);
});

$slug = 'daily-board-' . $date . ($status !== '' ? '-' . strtolower(str_replace([' ', '-'], '', $status)) : '');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $slug . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 names correctly

$put = function (array $f) use ($out) { fputcsv($out, $f); };

// Header block — the figures the board shows above the cards
$put(['Daily Attendance Board']);
$put(['Date', date('l, F j, Y', strtotime($date))]);
if ($B['holiday']) $put(['Holiday', $B['holiday']['title'] . ' (' . $B['holiday']['kind'] . ')']);
$put(['Filter', ($status !== '' ? $status : 'All statuses') . ($q !== '' ? ' · search "' . $q . '"' : '')]);
if (!$B['is_future']) {
    $put(['Clocked in', $B['attended'] . ' of ' . $B['expected'] . ' expected (' . $B['att_rate'] . '%)']);
}
$put(['Employees listed', count($rows)]);
$put([]);
$put(array_merge(['Summary'], array_keys(DAILY_BOARD_STATUSES)));
$put(array_merge([''], array_values($B['summary'])));
$put([]);

$put([
    'Employee No', 'Name', 'Department', 'Shift', 'Shift Time', 'Status',
    'Time In', 'Time Out', 'Late', 'Undertime', 'Overtime', 'Leave', 'Pending Requests',
]);

$hm = fn($h) => daily_board_hm((float) $h) ?: '';

foreach ($rows as $r) {
    $att = $r['att'];
    $shift_time = ($r['shift_start'] && $r['shift_end'])
        ? date('h:i A', strtotime($r['shift_start'])) . ' - ' . date('h:i A', strtotime($r['shift_end']))
        : '';
    $leave = '';
    if (!empty($att['leave_name'])) {
        $leave = $att['leave_name'] . (!empty($att['leave_half']) ? ' (' . $att['leave_part'] . ')' : '');
    }
    $put([
        $r['employee_no'],
        $r['lastname'] . ', ' . $r['firstname'] . ($r['middlename'] ? ' ' . substr($r['middlename'], 0, 1) . '.' : ''),
        $r['dept_name'] ?: '',
        $r['shift_desc'] ?: '',
        $shift_time,
        $att['label'],
        $att['in']  ? date('h:i A', strtotime($att['in']))  : '',
        $att['out'] ? date('h:i A', strtotime($att['out'])) : '',
        $hm($att['late'] ?? 0),
        $hm($att['ut'] ?? 0),
        $hm($att['ot'] ?? 0),
        $leave,
        !empty($r['pending']) ? $r['pending']['n'] . ' (' . $r['pending']['types'] . ')' : '',
    ]);
}

fclose($out);
