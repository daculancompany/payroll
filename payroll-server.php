<?php
require_once __DIR__ . '/includes/require_login.php';

require_once __DIR__ . '/includes/session_bootstrap.php';
include 'db_connect.php';
require_once __DIR__ . '/includes/paysheet.php';

// Period label; a run saved without dates (0000-00-00) printed "Nov 30, -0001".
function pr_period_label($from, $to, $html = true)
{
    $blank = function ($d) { return !$d || strpos($d, '0000-00-00') === 0 || strtotime($d) === false; };
    if ($blank($from) || $blank($to)) return $html ? '<span class="text-muted fst-italic">No period set</span>' : 'No period set';
    return date("M d", strtotime($from)) . ($html ? ' &ndash; ' : ' – ') . date("M d, Y", strtotime($to));
}

// Employee count + GROSS / NET exactly as the paysheet prints them. The stored
// payroll_items.net goes stale after Calculate (deductions, extras, settings
// changed later), so it is never summed here — see includes/payslip_figures.php.
function pr_batch_totals(mysqli $conn, int $payroll_id)
{
    $ps = paysheet_build($conn, $payroll_id);
    $n = 0;
    foreach ($ps['groups'] as $g) $n += count($g['rows']);
    return ['emp_count' => $n, 'gross' => round((float) ($ps['grand']['gross'] ?? 0), 2), 'net' => round((float) ($ps['grand']['net'] ?? 0), 2)];
}
function pr_peso($v) { return '&#8369;' . number_format((float) $v, 2); }

// Initialize response array
$response = array();

// Get the request parameters
$limit = (int) $_POST['length']; if ($limit < 1) $limit = 1000;   // -1 = DataTables "All"
$offset = max(0, (int) $_POST['start']);
$search = $_POST['search']['value'];
$orderColumnIndex = $_POST['order'][0]['column'];
$orderDirection = $_POST['order'][0]['dir'];
$p2 = $_POST['p2'];

// Define column mappings (Period sorts by the actual start date, not the label).
$columns = ["ref_no", "date_from", null, "status"];   // [2] Employees / Net Pay is computed, not sortable
$orderColumn = $columns[$orderColumnIndex] ?? "date_from";
$orderDirection = strtoupper($orderDirection) === 'ASC' ? 'ASC' : 'DESC';

// Query to count total records
$totalRecordsQuery = "SELECT COUNT(*) AS total FROM payroll";
$totalRecordsResult = $conn->query($totalRecordsQuery);
$totalRecords = $totalRecordsResult->fetch_assoc()['total'];

// Base query (Employer column removed — no employers join needed)
$query = "SELECT payroll.*, clusters.cluster
          FROM payroll
          LEFT JOIN clusters ON clusters.id = payroll.category
          WHERE payroll.p2 = '" . mysqli_real_escape_string($conn, $p2) . "'";

// Add search filter
if (!empty($search)) {
    $search = mysqli_real_escape_string($conn, $search);
    $query .= " AND (ref_no LIKE '%$search%'
                OR clusters.cluster LIKE '%$search%') ";
}

// Add ordering and pagination
$query .= " ORDER BY $orderColumn $orderDirection LIMIT $limit OFFSET $offset";

$result = $conn->query($query);

// Prepare data for DataTables
$data = array();
while ($row = $result->fetch_assoc()) {
    $data[] = array(
        "ref_no" => '<span class="payroll-ref">' . htmlspecialchars($row['ref_no']) . '</span>',
        "period" => '<span class="payroll-period"><i class="ri-calendar-2-line me-1 text-muted"></i>'
                  . pr_period_label($row['date_from'], $row['date_to']) . '</span>',
        "totals" => pr_totals_cell(pr_batch_totals($conn, (int) $row['id'])),
        "status" => getStatusBadge($row['status']),
        "action" => getActionButtons($row)
    );
}

// Employees / Net Pay cell: net on top, head count + gross underneath.
function pr_totals_cell(array $t)
{
    if ($t['emp_count'] === 0) return '<span class="text-muted">&mdash;</span>';
    return '<div class="pr-net">' . pr_peso($t['net']) . '</div>'
         . '<div class="pr-net-sub"><i class="ri-group-line me-1"></i>' . number_format($t['emp_count']) . ' employee' . ($t['emp_count'] === 1 ? '' : 's')
         . ' &middot; Gross ' . pr_peso($t['gross']) . '</div>';
}

// Function to return status badges
function getStatusBadge($status)
{
    switch ($status) {
        case 0:
            return '<span class="badge rounded-pill bg-primary"><i class="ri-file-add-line me-1"></i>New</span>';
        case 1:
            return '<span class="badge rounded-pill bg-success"><i class="ri-checkbox-circle-line me-1"></i>Calculated</span>';
        case 3:
            return '<span class="badge rounded-pill bg-warning text-dark"><i class="ri-eye-line me-1"></i>Ready for Review</span>';
        case 2:
            return '<span class="badge rounded-pill bg-danger"><i class="ri-lock-fill me-1"></i>Locked</span>';
        default:
            return '<span class="badge rounded-pill bg-secondary">Unknown</span>';
    }
}

// Function to return action buttons
function getActionButtons($row)
{
    $id       = (int) $row['id'];
    $settings = htmlspecialchars(json_encode(json_decode($row['settings'], true)), ENT_QUOTES);
    // One segmented group per row: the primary action leads (solid), the
    // rest are quiet icon/label segments. Classes and onclick handlers are the
    // ones assets2/js/payroll.js binds to, so behaviour is unchanged.
    $tip = function ($t) { return ' data-bs-toggle="tooltip" data-bs-placement="top" title="' . $t . '"'; };
    $btn = '<div class="btn-group btn-group-sm pr-actions" role="group" aria-label="Payroll actions">';

    if ($row['status'] != 2) {
        if ($row['status'] == 0) {
            $btn .= '<button type="button" class="btn pr-act pr-act--primary calculate_payroll" data-id="' . $id . '"' . $tip('Calculate payroll') . '>'
                  . '<i class="ri-calculator-line"></i><span>Calculate</span></button>';
        } else {
            $btn .= '<button type="button" class="btn pr-act pr-act--primary view_payroll" data-id="' . $id . '"' . $tip('Open payroll details') . '>'
                  . '<i class="ri-eye-line"></i><span>View</span></button>';
        }
        if ($row['status'] == 1) {
            $btn .= '<button type="button" class="btn pr-act" onclick="recalculate(' . $id . ')"' . $tip('Recompute pay from the latest DTR and settings') . '>'
                  . '<i class="ri-refresh-line"></i><span>Recompute</span></button>';
        }
        $btn .= '<button type="button" class="btn pr-act pr-act--icon add_settings" data-id="' . $id . '" settings=\'' . $settings . '\'' . $tip('Payroll settings') . ' aria-label="Payroll settings">'
              . '<i class="ri-settings-3-line"></i></button>';
        $btn .= '<button type="button" class="btn pr-act pr-act--icon pr-act--danger remove_payroll" data-id="' . $id . '"' . $tip('Delete payroll') . ' aria-label="Delete payroll">'
              . '<i class="ri-delete-bin-line"></i></button>';
    } else {
        $btn .= '<button type="button" class="btn pr-act pr-act--primary view_payroll" data-id="' . $id . '"' . $tip('Open payroll details') . '>'
              . '<i class="ri-eye-line"></i><span>View</span></button>';
        if ($_SESSION['login_role'] == 1) {
            $btn .= '<button type="button" class="btn pr-act pr-act--warn" onclick="islock(' . $id . ',1)"' . $tip('Unlock payroll for editing') . '>'
                  . '<i class="ri-lock-unlock-line"></i><span>Unlock</span></button>';
        }
    }

    $btn .= '<button type="button" class="btn pr-act pr-act--icon" onclick="payroll_history(' . $id . ')"' . $tip('Version history') . ' aria-label="Version history">'
          . '<i class="ri-history-line"></i></button>';

    $btn .= '</div>';
    return $btn;
}

// Count filtered records
$filteredRecordsQuery = "SELECT COUNT(*) AS total FROM payroll";
$filteredRecordsResult = $conn->query($filteredRecordsQuery);
$filteredRecords = $filteredRecordsResult->fetch_assoc()['total'];

// ── Summary figures for the stat cards above the list (same p2 scope as the table).
//    Only computed when the client asks (first load / after create-delete-lock) so
//    search keystrokes and pagination stay as light as before. ──
$summary = null;
if (!empty($_POST['with_summary'])) {
$p2esc = mysqli_real_escape_string($conn, $p2);

$counts = $conn->query("SELECT COUNT(*) AS total,
        COALESCE(SUM(status = 0), 0) AS s_new,
        COALESCE(SUM(status = 1), 0) AS s_calculated,
        COALESCE(SUM(status = 3), 0) AS s_review,
        COALESCE(SUM(status = 2), 0) AS s_locked,
        COALESCE(SUM(YEAR(date_from) = YEAR(CURDATE())), 0) AS this_year
    FROM payroll WHERE p2 = '$p2esc'")->fetch_assoc();

$latest = $conn->query("SELECT p.id, p.ref_no, p.date_from, p.date_to, p.status
    FROM payroll p WHERE p.p2 = '$p2esc'
    ORDER BY p.date_from DESC, p.id DESC LIMIT 1")->fetch_assoc();
$latestT = $latest ? pr_batch_totals($conn, (int) $latest['id']) : null;

$summary = array(
    "total"        => (int) $counts['total'],
    "this_year"    => (int) $counts['this_year'],
    "in_progress"  => (int) $counts['s_new'] + (int) $counts['s_calculated'] + (int) $counts['s_review'],
    "s_new"        => (int) $counts['s_new'],
    "s_calculated" => (int) $counts['s_calculated'],
    "s_review"     => (int) $counts['s_review'],
    "s_locked"     => (int) $counts['s_locked'],
    "latest"       => $latest ? array(
        "ref_no"    => htmlspecialchars($latest['ref_no']),
        "period"    => pr_period_label($latest['date_from'], $latest['date_to'], false),
        "emp_count" => $latestT['emp_count'],
        "net_total" => $latestT['net'],
    ) : null,
);
}

// Prepare response
$response = array(
    "draw" => intval($_POST['draw']),
    "recordsTotal" => $totalRecords,
    "recordsFiltered" => $filteredRecords,
    "summary" => $summary,
    "data" => $data
);

echo json_encode($response);
