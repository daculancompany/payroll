<?php
// Server-side DataTables source for the employee self-service portal's
// Attendance Records tab. Scoped strictly to the logged-in employee —
// employee_id always comes from the session, never from client input.
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['emp_is_login'])) {
    http_response_code(403);
    echo json_encode(['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    exit;
}

include 'db_connect.php';

$emp_id = (int)$_SESSION['emp_id'];

$draw   = intval($_POST['draw'] ?? 1);
$start  = intval($_POST['start'] ?? 0);
$length = intval($_POST['length'] ?? 15);
if ($length <= 0 || $length > 100) $length = 15;

// Date range — default to today so the portal never dumps a full history at once.
$today = date('Y-m-d');
$from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['from'] ?? '') ? $_POST['from'] : $today;
$to    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['to'] ?? '')   ? $_POST['to']   : $today;

$orderCol  = intval($_POST['order'][0]['column'] ?? 0);
$orderDirRaw = strtolower($_POST['order'][0]['dir'] ?? 'desc');
$orderDir  = in_array($orderDirRaw, ['asc', 'desc']) ? $orderDirRaw : 'desc';
$cols = [0 => 'date_time', 1 => 'attendance_type', 2 => 'work_hours', 3 => 'overtime', 5 => 'notes'];
$orderColumn = $cols[$orderCol] ?? 'date_time';

// Total (unfiltered) count for this employee.
$tc = $conn->prepare("SELECT COUNT(*) AS c FROM DTR_details WHERE employee_id = ?");
$tc->bind_param('i', $emp_id);
$tc->execute();
$totalRecords = (int)($tc->get_result()->fetch_assoc()['c'] ?? 0);

// Filtered count (date range applied).
$fc = $conn->prepare("SELECT COUNT(*) AS c FROM DTR_details WHERE employee_id = ? AND DATE(date_time) BETWEEN ? AND ?");
$fc->bind_param('iss', $emp_id, $from, $to);
$fc->execute();
$filteredRecords = (int)($fc->get_result()->fetch_assoc()['c'] ?? 0);

// Page of data.
$sql = "SELECT date_time, work_hours, logs, attendance_type, overtime, notes
        FROM DTR_details
        WHERE employee_id = ? AND DATE(date_time) BETWEEN ? AND ?
        ORDER BY $orderColumn $orderDir
        LIMIT ?, ?";
$st = $conn->prepare($sql);
$st->bind_param('issii', $emp_id, $from, $to, $start, $length);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$data = [];
foreach ($rows as $att) {
    $dt        = $att['date_time'];
    $wh        = (float)$att['work_hours'];
    $ot_h      = (float)$att['overtime'];
    $atype     = strtoupper(substr($att['attendance_type'] ?? 'P', 0, 1));
    $atype_lbl = $att['attendance_type'] ?? 'Present';
    $pct_wh    = min(100, ($wh / 8) * 100);
    $att_cls   = in_array($atype, ['P', 'A', 'H', 'S', 'O']) ? 'att-' . $atype : 'att-P';

    $logs_obj = $att['logs'] ? json_decode($att['logs']) : [];
    if (!is_array($logs_obj)) $logs_obj = [];
    $timeIn  = '';
    $timeOut = '';
    if (!empty($logs_obj)) {
        $timeIn  = date('g:i A', strtotime($logs_obj[0]->dateTime ?? ''));
        $timeOut = count($logs_obj) > 1 ? date('g:i A', strtotime(end($logs_obj)->dateTime ?? '')) : '';
    }

    $popLines = '';
    foreach ($logs_obj as $li => $lg) {
        $isBio = isset($lg->type) && $lg->type === 'bio';
        $chip  = $isBio ? 'bio' : 'manual';
        $icon  = $isBio ? 'ri-fingerprint-line' : 'ri-edit-line';
        $lbl   = ($li === 0) ? 'IN' : (($li === count($logs_obj) - 1) ? 'OUT' : '#' . ($li + 1));
        $ltime = date('g:i A', strtotime($lg->dateTime ?? ''));
        $popLines .= '<div style="display:flex;align-items:center;gap:6px;padding:3px 0;">'
            . '<span style="font-size:10px;font-weight:700;color:#888;min-width:26px;">' . $lbl . '</span>'
            . '<span class="dtr-log-chip ' . $chip . '"><i class="' . $icon . '"></i> ' . $ltime . '</span>'
            . '</div>';
    }
    if (!$popLines) $popLines = '<span style="color:#aaa;font-size:11px;">No logs</span>';
    $popContent = htmlspecialchars('<div style="min-width:150px;">' . $popLines . '</div>');
    $totalLogs  = count($logs_obj);

    $dateHtml = '<div style="font-weight:700;">' . date('M d, Y', strtotime($dt)) . '</div>'
              . '<div style="font-size:10px;color:#aaa;">' . date('l', strtotime($dt)) . '</div>';

    $typeHtml = '<span class="att-type ' . $att_cls . '">' . htmlspecialchars($atype_lbl) . '</span>';

    $whHtml = '<div style="font-weight:700;">' . ($wh > 0 ? $wh . 'h' : '—') . '</div>';
    if ($wh > 0) {
        $whHtml .= '<div class="hrs-bar"><div class="hrs-fill" style="width:' . $pct_wh . '%;"></div></div>';
    }

    $otHtml = $ot_h > 0
        ? '<span style="color:#fd7e14;font-weight:700;">' . $ot_h . 'h</span>'
        : '<span style="color:#ccc;">—</span>';

    if ($totalLogs > 0) {
        $ioHtml = '<div class="time-io">'
            . '<span class="dtr-time-chip in">' . ($timeIn ?: '—') . '</span>';
        if ($timeOut) {
            $ioHtml .= '<span style="color:#ccc;font-size:10px;">→</span>'
                . '<span class="dtr-time-chip out">' . $timeOut . '</span>';
        } else {
            $ioHtml .= '<span class="dtr-time-chip na">No Out</span>';
        }
        $ioHtml .= '</div>'
            . '<span class="dtr-logs-pill mt-1"'
            . ' data-bs-toggle="popover" data-bs-trigger="click" data-bs-placement="left" data-bs-html="true"'
            . ' data-bs-content="' . $popContent . '" title="All Logs">'
            . '<span class="dtr-logs-count"><i class="ri-list-check"></i> ' . $totalLogs . ' log' . ($totalLogs > 1 ? 's' : '') . ' — view details</span>'
            . '</span>';
    } else {
        $ioHtml = '<span class="dtr-time-chip na">No logs</span>';
    }

    $data[] = [
        'date'      => $dateHtml,
        'type'      => $typeHtml,
        'work_hours'=> $whHtml,
        'ot_hours'  => $otHtml,
        'time_io'   => $ioHtml,
        'notes'     => '<span style="font-size:11px;color:#888;">' . htmlspecialchars($att['notes'] ?? '—') . '</span>',
    ];
}

echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $totalRecords,
    'recordsFiltered' => $filteredRecords,
    'data'            => $data,
]);
