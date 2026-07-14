<?php
// Server-side DataTables source for the employee self-service portal's
// "Requests" tab (OT / incident requests). Scoped strictly to the logged-in
// employee — employee_id always comes from the session, never client input.
// Feeds both the desktop DataTable (#att-req-tbl) and the mobile
// infinite-scroll card feed (#areq-mlist).
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
];
$statusMap = [
    0 => ['Pending',  '#e6a817', 'pending'],
    1 => ['Approved', '#219688', 'approved'],
    2 => ['Rejected', '#c62828', 'rejected'],
];

// Total (unfiltered) count for this employee. No date filter here — the request
// history is always shown in full, just paginated to avoid dumping everything.
$tc = $conn->prepare("SELECT COUNT(*) AS c FROM attendance_requests WHERE employee_id = ?");
$tc->bind_param('i', $emp_id);
$tc->execute();
$totalRecords = (int)($tc->get_result()->fetch_assoc()['c'] ?? 0);

// Page of data.
$sql = "SELECT ar.*, ru.name AS reviewer_name
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
    $type_key   = $isIncident ? 'incident' : 'overtime';
    $type_label = $isIncident ? 'Incident' : 'OT Request';

    $typeHtml = $isIncident
        ? '<span style="background:#fff3cd;color:#856404;border-radius:8px;padding:2px 8px;font-size:10px;font-weight:700;"><i class="ri-error-warning-line me-1"></i>Incident</span>'
        : '<span style="background:#cff4fc;color:#055160;border-radius:8px;padding:2px 8px;font-size:10px;font-weight:700;"><i class="ri-timer-flash-line me-1"></i>OT Request</span>';

    // Details — claimed in/out window OR requested OT hours.
    $details = '';
    if ($row['claimed_time_in'] || $row['claimed_time_out']) {
        $details = ($row['claimed_time_in']  ? date('g:i A', strtotime($row['claimed_time_in']))  : '—')
                 . ' – '
                 . ($row['claimed_time_out'] ? date('g:i A', strtotime($row['claimed_time_out'])) : '—');
    } elseif ($row['ot_hours_requested']) {
        $details = htmlspecialchars($row['ot_hours_requested']) . ' hrs OT';
    }
    $notesFull = trim((string)($row['notes'] ?? ''));
    $notesShort = $notesFull !== ''
        ? htmlspecialchars(mb_strimwidth($notesFull, 0, 40, '…'))
        : '';
    $detailsHtml = $details;
    if ($notesShort !== '') {
        $detailsHtml .= '<div style="color:#aaa;font-size:10px;">' . $notesShort . '</div>';
    }
    if ($detailsHtml === '') $detailsHtml = '<span style="color:#ccc;">—</span>';

    [$slabel, $scolor, $sslug] = $statusMap[$row['status']] ?? ['Unknown', '#aaa', 'unknown'];
    $statusHtml = '<span style="background:' . $scolor . ';color:#fff;border-radius:10px;padding:2px 10px;font-size:11px;font-weight:700;">' . $slabel . '</span>';

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
        'reviewer_html'=> $reviewerCard,
    ];
}

echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $totalRecords,
    'recordsFiltered' => $totalRecords,
    'data'            => $data,
]);
