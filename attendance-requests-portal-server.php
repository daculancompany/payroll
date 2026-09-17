<?php
// Server-side DataTables source for the employee self-service portal's
// "Requests" tab (OT / incident requests). Scoped strictly to the logged-in
// employee — employee_id always comes from the session, never client input.
// Feeds both the desktop DataTable (#att-req-tbl) and the mobile
// infinite-scroll card feed (#areq-mlist).
require_once __DIR__ . '/includes/session_bootstrap.php';
header('Content-Type: application/json');

if (empty($_SESSION['emp_is_login'])) {
    http_response_code(403);
    echo json_encode(['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    exit;
}

include 'db_connect.php';
require_once __DIR__ . '/includes/leave_timeline.php';   // stage chips + trail (same chain as leave)

$emp_id = (int)$_SESSION['emp_id'];

$draw   = intval($_POST['draw'] ?? 1);
$start  = intval($_POST['start'] ?? 0);
$length = intval($_POST['length'] ?? 15);
if ($length <= 0 || $length > 100) $length = 15;

// Ordering — only the date columns are sortable; everything else falls back to
// "most recently filed first" (the portal's default view).
$orderCol    = intval($_POST['order'][0]['column'] ?? 0);
$orderDirRaw = strtolower($_POST['order'][0]['dir'] ?? 'desc');
$orderDir    = in_array($orderDirRaw, ['asc', 'desc']) ? $orderDirRaw : 'desc';
$cols        = [0 => 'created_at', 2 => 'request_date'];
$orderColumn = $cols[$orderCol] ?? 'created_at';

$reasonLabels = [
    'forgot_scan'  => 'Forgot to Scan',
    'device_error' => 'Device Error',
    'system_down'  => 'System Down',
    'overtime'     => 'Overtime',
    'other'        => 'Other',
    'rest_day_work' => 'Rest Day Work',
];
$statusMap = [
    0 => ['Pending',  '#e6a817', 'pending'],
    1 => ['Approved', '#6642aa', 'approved'],
    2 => ['Rejected', '#c62828', 'rejected'],
    3 => ['Cancelled', '#6c757d', 'cancelled'],
];

// Total (unfiltered) count for this employee. No date filter here — the request
// history is always shown in full, just paginated to avoid dumping everything.
$tc = $conn->prepare("SELECT COUNT(*) AS c FROM attendance_requests WHERE employee_id = ?");
$tc->bind_param('i', $emp_id);
$tc->execute();
$totalRecords = (int)($tc->get_result()->fetch_assoc()['c'] ?? 0);

// Page of data.
$sql = "SELECT ar.*, ru.name AS reviewer_name, " . att_request_rendered_sql('ar') . "
        FROM attendance_requests ar
        LEFT JOIN users ru ON ru.id = ar.reviewed_by
        WHERE ar.employee_id = ?
        ORDER BY $orderColumn $orderDir
        LIMIT ?, ?";
$st = $conn->prepare($sql);
$st->bind_param('iii', $emp_id, $start, $length);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$data = [];
foreach ($rows as $row) {
    $isIncident = ($row['request_type'] === 'incident');
    $isRestDay  = ($row['request_type'] === 'rest_day');
    $isUt       = ($row['request_type'] === 'undertime');
    $type_key   = $row['request_type'];
    $type_label = $isIncident ? 'Incident' : ($isRestDay ? 'Rest Day' : ($isUt ? 'Undertime' : 'OT Request'));

    $typeChip = [
        'incident'  => ['#fff3cd', '#856404', 'ri-error-warning-line', 'Incident'],
        'rest_day'  => ['#ece4fb', '#4c2f96', 'ri-moon-line',          'Rest Day'],
        'overtime'  => ['#cff4fc', '#055160', 'ri-timer-flash-line',   'OT Request'],
        'undertime' => ['#fde2e4', '#8a1c2b', 'ri-logout-box-r-line',  'Undertime'],
    ][$type_key] ?? ['#cff4fc', '#055160', 'ri-timer-flash-line', 'OT Request'];
    $typeHtml = '<span style="background:' . $typeChip[0] . ';color:' . $typeChip[1]
        . ';border-radius:8px;padding:2px 8px;font-size:10px;font-weight:700;"><i class="'
        . $typeChip[2] . ' me-1"></i>' . $typeChip[3] . '</span>';

    // Details — claimed in/out window OR requested OT hours.
    $details = '';
    if ($row['claimed_time_in'] || $row['claimed_time_out']) {
        $details = ($row['claimed_time_in']  ? date('g:i A', strtotime($row['claimed_time_in']))  : '—')
                 . ' – '
                 . ($row['claimed_time_out'] ? date('g:i A', strtotime($row['claimed_time_out'])) : '—');
    } elseif ($row['ot_hours_requested']) {
        $details = htmlspecialchars($row['ot_hours_requested']) . ($isRestDay ? ' hrs rest-day duty' : ($isUt ? ' hrs undertime to excuse' : ' hrs OT'));
        // The start time the employee gave, when they gave one. Descriptive
        // only — the hours beside it are what was filed and what pays.
        if (!empty($row['ot_time_start'])) {
            $details .= '<div style="color:#6b6386;font-size:10.5px;">'
                . ($isUt ? 'Left ' : 'From ') . date('g:i A', strtotime($row['ot_time_start'])) . '</div>';
        }
    }
    $notesFull = trim((string)($row['notes'] ?? ''));
    $notesShort = $notesFull !== ''
        ? htmlspecialchars(mb_strimwidth($notesFull, 0, 40, '…'))
        : '';
    $detailsHtml = $details;
    // Authorized vs rendered, once the date is over (att_request_rendered):
    // the employee sees whether their advance filing was borne out by scans.
    if ($rd = att_request_rendered($row)) {
        [$rdBg, $rdFg, $rdIcon] = [
            'none'  => ['#fdeaea', '#b3261e', 'ri-close-circle-line'],
            'short' => ['#fff4e2', '#a86206', 'ri-timer-flash-line'],
            'met'   => ['#eef6ee', '#1b5e20', 'ri-checkbox-circle-line'],
        ][$rd['state']];
        $detailsHtml .= '<div style="margin-top:3px;"><span style="background:' . $rdBg . ';color:' . $rdFg
            . ';border-radius:8px;padding:2px 8px;font-size:10px;font-weight:700;white-space:nowrap;" title="What your DTR shows for that date against the approved hours — pay follows the smaller of the two"><i class="'
            . $rdIcon . ' me-1"></i>' . htmlspecialchars($rd['label']) . '</span></div>';
    }
    if ($notesShort !== '') {
        $detailsHtml .= '<div style="color:#aaa;font-size:10px;">' . $notesShort . '</div>';
    }
    if ($detailsHtml === '') $detailsHtml = '<span style="color:#ccc;">—</span>';

    [$slabel, $scolor, $sslug] = $statusMap[$row['status']] ?? ['Unknown', '#aaa', 'unknown'];
    $statusHtml = '<span style="background:' . $scolor . ';color:#fff;border-radius:10px;padding:2px 10px;font-size:11px;font-weight:700;">' . $slabel . '</span>';

    // Same staged chain as leave: one chip per stage, plus who it waits on.
    $stageChips = leave_stage_chips($row);
    $curStage   = leave_current_stage($row);
    $awaiting   = '';
    if ($curStage !== null && (int) $row['status'] === 0) {
        $who = leave_stage_approver_names($conn, $curStage, $emp_id);
        $awaiting = 'Awaiting ' . leave_stages()[$curStage]['label'] . ($who ? ' · ' . implode(' / ', $who) : '');
    }
    $statusHtml .= '<div class="lv-chips" style="margin-top:3px;font-size:13px;line-height:1;">' . $stageChips . '</div>'
        . ($awaiting !== '' ? '<div style="font-size:10px;color:#888;margin-top:2px;">' . htmlspecialchars($awaiting) . '</div>' : '');

    $reasonLabel = $reasonLabels[$row['reason']] ?? $row['reason'];

    // Reviewer notes — remarks, plus the reviewer's name once decided.
    $reviewerRemarks = trim((string)($row['reviewer_remarks'] ?? ''));
    $reviewerName    = trim((string)($row['reviewer_name'] ?? ''));
    $reviewerParts   = [];
    if ($reviewerRemarks !== '') $reviewerParts[] = htmlspecialchars($reviewerRemarks);
    if ($reviewerName !== '' && $row['status'] != 0) $reviewerParts[] = '<span style="color:#c5bfb0;">— ' . htmlspecialchars($reviewerName) . '</span>';
    $reviewerHtml    = $reviewerParts ? implode(' ', $reviewerParts) : '—';
    $reviewerCard    = $reviewerParts ? implode(' ', $reviewerParts) : '';

    $data[] = [
        // Desktop DataTable columns.
        'filed'    => date('M d, Y', strtotime($row['created_at'])),
        'type'     => $typeHtml,
        'date'     => '<span style="font-weight:700;">' . date('M d, Y', strtotime($row['request_date'])) . '</span>',
        'reason'   => '<span style="font-size:11px;">' . htmlspecialchars($reasonLabel) . '</span>',
        'details'  => '<span style="font-size:11px;">' . $detailsHtml . '</span>',
        'status'   => $statusHtml,
        'reviewer' => '<span style="font-size:11px;color:#888;">' . $reviewerHtml . '</span>',
        // Raw fields for the mobile card renderer.
        'date_plain'   => date('M d, Y', strtotime($row['request_date'])),
        'type_key'     => $type_key,
        'type_label'   => $type_label,
        'reason_plain' => htmlspecialchars($reasonLabel),
        'details_html' => $detailsHtml,
        'status_label' => $slabel,
        'status_color' => $scolor,
        'status_slug'  => $sslug,
        'stage_chips'  => $stageChips,
        'awaiting'     => $awaiting,
        'timeline'     => leave_timeline_html($row),
        'reviewer_html'=> $reviewerCard,
        'attachment'   => $row['attachment'] ?? null,
    ];
}

echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $totalRecords,
    'recordsFiltered' => $totalRecords,
    'data'            => $data,
]);
