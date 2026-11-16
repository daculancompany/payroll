<?php
require_once __DIR__ . '/includes/require_login.php';

include 'db_connect.php';
// punch_source_html() / kiosk_punch_meta(): how each punch was made.
require_once __DIR__ . '/includes/kiosk_admin.php';

$draw        = intval($_POST['draw'] ?? 1);
$start       = intval($_POST['start'] ?? 0);
$length      = intval($_POST['length'] ?? 25);
$search      = $_POST['search']['value'] ?? '';
$orderCol    = intval($_POST['order'][0]['column'] ?? 0);
$orderDirRaw = strtolower($_POST['order'][0]['dir'] ?? 'desc');
$orderDir    = in_array($orderDirRaw, ['asc', 'desc']) ? $orderDirRaw : 'desc';

// Default to today so a request with no date filter (e.g. hitting this
// endpoint directly) can't force an unbounded scan/aggregate over the
// entire attendance history — that was the source of the reported lag.
$today        = date('Y-m-d');
$fromRaw      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['from'] ?? '') ? $_POST['from'] : $today;
$toRaw        = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['to'] ?? '')   ? $_POST['to']   : $today;
$from         = mysqli_real_escape_string($conn, $fromRaw);
$to           = mysqli_real_escape_string($conn, $toRaw);
$employee_ids = preg_replace('/[^0-9,]/', '', $_POST['employee_ids'] ?? '');
$site_id      = intval($_POST['site_id'] ?? 0);

// Orderable columns
$cols = [
    0 => 'a.date_time',
    1 => 'e.lastname',
    2 => 's.site_name',
    3 => 'a.work_hours',
    4 => 'a.overtime',
    5 => 'a.undertime',
    6 => 'a.late',
];
$orderColumn = $cols[$orderCol] ?? 'a.date_time';

// Base WHERE
$where = '1=1';
if ($from && $to) {
    $where .= " AND a.date_time BETWEEN '$from' AND '$to 23:59:59'";
}
if ($employee_ids) {
    $where .= " AND a.employee_id IN ($employee_ids)";
}
if ($site_id > 0) {
    $where .= " AND d.site_id = $site_id";
}
if ($search) {
    $s = mysqli_real_escape_string($conn, $search);
    $where .= " AND (e.firstname LIKE '%$s%' OR e.lastname LIKE '%$s%'
                OR CONCAT(e.lastname,', ',e.firstname) LIKE '%$s%'
                OR s.site_name LIKE '%$s%' OR s.site_code LIKE '%$s%')";
}

$base_join = "FROM DTR_details a
              INNER JOIN employee e ON a.employee_id = e.id
              INNER JOIN DTR d ON a.ddtr_id = d.id
              LEFT JOIN sites s ON d.site_id = s.id";

// Stats (filtered, no pagination)
$stats_sql = "SELECT
    COUNT(*) AS total_records,
    COALESCE(SUM(a.work_hours),0) AS total_hours,
    COALESCE(SUM(a.overtime),0) AS total_overtime,
    COALESCE(AVG(a.undertime),0) AS avg_undertime,
    COALESCE(AVG(a.late),0) AS avg_late,
    COUNT(DISTINCT a.employee_id) AS unique_employees
$base_join WHERE $where";
$stats = $conn->query($stats_sql)->fetch_assoc();

// Total filtered count
$filtered_count = (int) $conn->query("SELECT COUNT(*) AS c $base_join WHERE $where")->fetch_assoc()['c'];

// Total unfiltered count
$total_count = (int) $conn->query("SELECT COUNT(*) AS c FROM DTR_details")->fetch_assoc()['c'];

// Data query
$data_sql = "SELECT a.*, e.employee_no, e.lastname, e.firstname, e.middlename,
             d.status AS dtr_status, d.site_id,
             s.site_code, s.site_name, s.site_address
             $base_join
             WHERE $where
             ORDER BY $orderColumn $orderDir
             LIMIT $start, $length";

$result = $conn->query($data_sql);
$data   = [];

// Kiosk facts for every punch on screen, fetched once for the whole page
// rather than per row. Empty when the kiosk tables are not there yet, in
// which case every punch simply falls back to what its log entry says.
$punch_meta = kiosk_punch_meta($conn, $fromRaw, $toRaw);

while ($row = $result->fetch_assoc()) {
    $mi = $row['middlename'] ? ' ' . strtoupper(substr($row['middlename'], 0, 1)) . '.' : '';

    // Time Logs column: show only first IN / last OUT; full list opens in a
    // modal via the View button (mirrors the payroll details View style).
    $logs    = json_decode($row['logs']);
    $logHtml = '';
    if ($logs && count($logs)) {
        usort($logs, function ($a, $b) {
            return strtotime($a->dateTime) - strtotime($b->dateTime);
        });
        $first = $logs[0];
        $last  = count($logs) > 1 ? $logs[count($logs) - 1] : null;

        // How each punch was made (face / fingerprint / PIN / manual), as the
        // same chip Punch Logs uses — icon only here, where the column is
        // narrow, with the wording in the tooltip. Also carried into the
        // modal below so the full list says it too.
        $srcChip = function ($log) use ($row, $punch_meta) {
            $meta = kiosk_punch_meta_for($punch_meta, $row['employee_id'],
                date('Y-m-d H:i:s', strtotime($log->dateTime)));
            // Source first, then where it was made — the pin only appears
            // when the kiosk actually sent coordinates.
            return punch_source_html($log->type ?? 'bio', $meta) . punch_location_html($meta);
        };
        $empLabel = $row['lastname'] . ', ' . $row['firstname'] . $mi;
        foreach ($logs as $lg) {
            $lg->srcHtml = $srcChip($lg);          // icon only — the table cell
            $meta = kiosk_punch_meta_for($punch_meta, $row['employee_id'],
                date('Y-m-d H:i:s', strtotime($lg->dateTime)));
            $lg->srcFull = punch_source_html($lg->type ?? 'bio', $meta, true); // with label — the modal
            $lg->geoHtml = punch_location_html($meta);
            // The photo the kiosk took at the moment of the punch. Click to
            // enlarge through the shared viewer (component/photo_viewer.php).
            $lg->photoHtml = !empty($meta['photo'])
                ? '<img class="pl-shot pl-shot-sm" src="' . htmlspecialchars($meta['photo'], ENT_QUOTES) . '"'
                  . ' alt="" loading="lazy" onclick="kioskPhotoView(this.src, '
                  . htmlspecialchars(json_encode($empLabel . ' · ' . date('M j, g:i A', strtotime($lg->dateTime))), ENT_QUOTES) . ')">'
                : '<span class="pl-noshot pl-noshot-sm" data-tip="No photo for this punch"><i class="ri-camera-off-line"></i></span>';
        }

        $logHtml .= '<div class="d-flex align-items-center gap-1 mb-1">'
                  . '<span class="att-log-dir att-log-in">IN</span>'
                  . '<small class="fw-semibold">' . date('g:i A', strtotime($first->dateTime)) . '</small>'
                  . $first->srcHtml
                  . '</div>'
                  . '<div class="d-flex align-items-center gap-1 mb-1">'
                  . '<span class="att-log-dir att-log-out">OUT</span>'
                  . '<small class="fw-semibold">' . ($last ? date('g:i A', strtotime($last->dateTime)) : '&mdash;') . '</small>'
                  . ($last ? $last->srcHtml : '')
                  . '</div>'
                  . '<button type="button" class="btn btn-sm btn-success view_time_logs mt-1"'
                  . ' data-logs="' . htmlspecialchars(json_encode($logs), ENT_QUOTES) . '"'
                  . ' data-employee="' . htmlspecialchars($row['lastname'] . ', ' . $row['firstname'] . $mi, ENT_QUOTES) . '"'
                  . ' data-date="' . date('M j, Y', strtotime($row['date_time'])) . '"'
                  . ' data-bs-toggle="tooltip" data-bs-placement="top" title="View Time Log Details">'
                  . '<i class="ri-eye-line me-1"></i>View</button>';
    } else {
        $logHtml = '<span class="text-muted small">No logs</span>';
    }

    $data[] = [
        'date'       => '<div class="att-date-main">' . date('M j, Y', strtotime($row['date_time'])) . '</div>'
                      . '<small class="text-muted">' . date('l', strtotime($row['date_time'])) . '</small>',
        // The name opens the shared quick-view drawer rather than jumping
        // straight to the full employee page — an admin scanning attendance
        // usually wants to check who this is, not leave the screen. The href is
        // kept so middle-click / "open in new tab" still reaches the full page.
        'employee'   => '<div class="fw-semibold"><a href="index.php?page=employee-details&id=' . (int) $row['employee_id'] . '"'
                      . ' data-emp-quickview="' . (int) $row['employee_id'] . '" class="rpt-emp-link">'
                      . htmlspecialchars($row['lastname'] . ', ' . $row['firstname'] . $mi) . '</a></div>'
                      . '<small class="att-emp-id">ID: ' . htmlspecialchars($row['employee_no']) . '</small>',
        'site'       => '<span class="att-site-badge">' . htmlspecialchars($row['site_code'] ?? '—') . '</span>'
                      . '<div class="att-site-name">' . htmlspecialchars($row['site_name'] ?? '—') . '</div>'
                      . '<small class="text-muted" style="font-size:11px;">' . htmlspecialchars($row['site_address'] ?? '') . '</small>',
        'work_hours' => '<span class="att-pill att-pill-work">' . htmlspecialchars($row['work_hours']) . 'h</span>',
        'overtime'   => '<span class="att-pill att-pill-ot">' . htmlspecialchars($row['overtime']) . 'h</span>',
        'undertime'  => '<span class="att-pill att-pill-ut">' . htmlspecialchars($row['undertime']) . 'm</span>',
        'late'       => '<span class="att-pill att-pill-late">' . htmlspecialchars($row['late']) . 'm</span>',
        'logs'       => $logHtml,
        'status'     => ($row['dtr_status'] == 2)
            ? '<span class="badge rounded-pill bg-success"><i class="ri-check-line me-1"></i>Approved</span>'
            : '<span class="badge rounded-pill bg-secondary"><i class="ri-time-line me-1"></i>Pending</span>',
    ];
}

echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $total_count,
    'recordsFiltered' => $filtered_count,
    'data'            => $data,
    'stats'           => $stats,
]);
