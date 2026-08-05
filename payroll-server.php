<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include 'db_connect.php';

// Initialize response array
$response = array();

// Get the request parameters
$limit = $_POST['length'];
$offset = $_POST['start'];
$search = $_POST['search']['value'];
$orderColumnIndex = $_POST['order'][0]['column'];
$orderDirection = $_POST['order'][0]['dir'];
$p2 = $_POST['p2'];

// Define column mappings (Period sorts by the actual start date, not the label).
$columns = ["ref_no", "date_from", "status"];
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
                  . date("M d", strtotime($row['date_from'])) . ' &ndash; ' . date("M d, Y", strtotime($row['date_to'])) . '</span>',
        "status" => getStatusBadge($row['status']),
        "action" => getActionButtons($row)
    );
}

// Function to return status badges
function getStatusBadge($status)
{
    switch ($status) {
        case 0:
            return '<span class="badge rounded-pill bg-primary"><i class="ri-file-add-line me-1"></i>New</span>';
        case 1:
            return '<span class="badge rounded-pill bg-success"><i class="ri-check-circle-line me-1"></i>Calculated</span>';
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
    $id       = $row['id'];
    $settings = htmlspecialchars(json_encode(json_decode($row['settings'], true)), ENT_QUOTES);
    $btn      = '<div class="action-buttons">';

    if ($row['status'] != 2) {
        // Primary action
        if ($row['status'] == 0) {
            $btn .= '<button class="btn btn-sm btn-primary calculate_payroll" data-id="' . $id . '" data-bs-toggle="tooltip" data-bs-placement="top" title="Calculate Payroll">'
                  . '<i class="ri-calculator-line me-1"></i>Calculate</button>';
        } else {
            $btn .= '<button class="btn btn-sm btn-success view_payroll" data-id="' . $id . '" data-bs-toggle="tooltip" data-bs-placement="top" title="View Payroll Details">'
                  . '<i class="ri-eye-line me-1"></i>View</button>';
        }
        // Recalculate (status 1 only)
        if ($row['status'] == 1) {
            $btn .= '<button class="btn btn-sm btn-warning text-dark" onclick="recalculate(' . $id . ')" data-bs-toggle="tooltip" data-bs-placement="top" title="Recalculate Payroll">'
                  . '<i class="ri-refresh-line me-1"></i>Recalculate</button>';
        }
        // Settings
        $btn .= '<button class="btn btn-sm btn-outline-secondary add_settings" data-id="' . $id . '" settings=\'' . $settings . '\' data-bs-toggle="tooltip" data-bs-placement="top" title="Payroll Settings">'
              . '<i class="ri-settings-3-line"></i></button>';
        // Delete
        $btn .= '<button class="btn btn-sm btn-outline-danger remove_payroll" data-id="' . $id . '" data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Payroll">'
              . '<i class="ri-delete-bin-line"></i></button>';
    } else {
        // Locked: view always, unlock for admin
        $btn .= '<button class="btn btn-sm btn-success view_payroll" data-id="' . $id . '" data-bs-toggle="tooltip" data-bs-placement="top" title="View Payroll Details">'
              . '<i class="ri-eye-line me-1"></i>View</button>';
        if ($_SESSION['login_role'] == 1) {
            $btn .= '<button class="btn btn-sm btn-outline-warning" onclick="islock(' . $id . ',1)" data-bs-toggle="tooltip" data-bs-placement="top" title="Unlock Payroll">'
                  . '<i class="ri-lock-unlock-line me-1"></i>Unlock</button>';
        }
    }

    // History — always visible
    $btn .= '<button class="btn btn-sm btn-outline-secondary" onclick="payroll_history(' . $id . ')" data-bs-toggle="tooltip" data-bs-placement="top" title="View History">'
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

$latest = $conn->query("SELECT p.ref_no, p.date_from, p.date_to, p.status,
        (SELECT COUNT(*) FROM payroll_items pi WHERE pi.payroll_id = p.id) AS emp_count,
        (SELECT COALESCE(SUM(pi.net), 0) FROM payroll_items pi WHERE pi.payroll_id = p.id) AS net_total
    FROM payroll p WHERE p.p2 = '$p2esc'
    ORDER BY p.date_from DESC, p.id DESC LIMIT 1")->fetch_assoc();

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
        "period"    => date("M d", strtotime($latest['date_from'])) . " – " . date("M d, Y", strtotime($latest['date_to'])),
        "emp_count" => (int) $latest['emp_count'],
        "net_total" => round((float) $latest['net_total'], 2),
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
