<?php
include 'db_connect.php';
require_once 'dept-scope.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session_bootstrap.php';
}

// Timekeeper (role 5) = scanner operator. They may see who's on the roster,
// never what anyone is paid — the pay cells come back blank regardless of what
// the browser asks for.
$tk_hide_pay = is_timekeeper();

$request = $_REQUEST;
$status        = isset($request['status'])        && $request['status']        !== '' ? (int)$request['status']        : 2;
$position_id   = isset($request['position_id'])   && $request['position_id']   !== '' ? (int)$request['position_id']   : null;
$department_id = isset($request['department_id']) && $request['department_id'] !== '' ? (int)$request['department_id'] : null;
$fingerprint   = isset($request['fingerprint'])   && $request['fingerprint']   !== '' ? (int)$request['fingerprint']   : null;

// Department Heads are locked to their own department regardless of the UI filter.
if (dept_scope_id() > 0) {
    $department_id = dept_scope_id();
}

// Sort map, keyed by the DataTables column index the browser sends. The
// timekeeper table drops the four pay columns, so its indexes shift.
$col = $tk_hide_pay
    ? array(
        0 => 'e.lastname',       // Employee (name + no.)
        1 => 'p.name',           // Position
        2 => 'd.name',           // Department
        3 => 'cl.clasification', // Classification
        4 => 'e.status',         // Status
        5 => 'e.employee_no',    // Action (fallback)
    )
    : array(
        0 => 'e.lastname',       // Employee (name + no.)
        1 => 'p.name',           // Position
        2 => 'd.name',           // Department
        3 => 'e.basic_pay',
        4 => 'e.salary',
        5 => 'e.ot_rate',
        6 => 'cl.clasification',
        7 => 'e.status',
        8 => 'e.employee_no',    // Action (fallback)
    );

$filter_status = '';
if ($status === 0 || $status === 1) {
    $filter_status = " AND e.status = $status";
}
// Weekly payroll was removed — the payroll-type filter no longer exists.
$_filter_payroll_type = '';
$filter_position = '';
if ($position_id) {
    $filter_position = " AND e.position_id = $position_id";
}
$filter_department = '';
if ($department_id) {
    $filter_department = " AND e.department_id = $department_id";
}
// Fingerprint enrollment: 1 = has at least one template, 0 = none
$filter_fingerprint = '';
if ($fingerprint === 1) {
    $filter_fingerprint = " AND EXISTS (SELECT 1 FROM employee_fingerprints f WHERE f.employee_id = e.id)";
} elseif ($fingerprint === 0) {
    $filter_fingerprint = " AND NOT EXISTS (SELECT 1 FROM employee_fingerprints f WHERE f.employee_id = e.id)";
}



$sql = "SELECT e.id, e.loan, e.employee_no, e.firstname, e.middlename, e.lastname, e.salary, e.basic_pay, e.ot_rate, e.status, e.rate_type, d.name AS department, p.name AS position, cl.clasification AS clasification FROM employee e
        LEFT JOIN department d ON e.department_id = d.id
        LEFT JOIN position p ON e.position_id = p.id
        LEFT JOIN clasification cl ON e.clasification_id = cl.id WHERE e.id != 0 $filter_status $_filter_payroll_type $filter_position $filter_department $filter_fingerprint";

if (!empty($request['search']['value'])) {
    $searchValue = mysqli_real_escape_string($conn, $request['search']['value']);
    $sql .= " AND (e.firstname LIKE '%$searchValue%'
                  OR e.lastname LIKE '%$searchValue%'
                  OR e.employee_no LIKE '%$searchValue%'
                  OR CONCAT(e.lastname, ', ', e.firstname) LIKE '%$searchValue%'
                  OR CONCAT(e.firstname, ' ', e.lastname) LIKE '%$searchValue%'
                  OR CONCAT(e.lastname, ' ', e.firstname) LIKE '%$searchValue%' ) ";
}

$query = mysqli_query($conn, $sql);
$totalData = mysqli_num_rows($query);
$totalFilter = $totalData;






$query = mysqli_query($conn, $sql);
$totalData = mysqli_num_rows($query);

// An out-of-range column index (or one the current role's table doesn't have)
// falls back to the name so the query can never break on it.
$order_col = $col[$request['order'][0]['column'] ?? 0] ?? 'e.lastname';
$order_dir = strtolower($request['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$sql .= " ORDER BY $order_col $order_dir LIMIT " . (int) $request['start'] . " ," . (int) $request['length'] . "  ";
// Apply status filter

$query = mysqli_query($conn, $sql);

$data = array();

while ($row = mysqli_fetch_array($query)) {
    $subdata = array();

    $initials = strtoupper(substr($row['firstname'], 0, 1)) . strtoupper(substr($row['lastname'], 0, 1));
    $fullname = htmlspecialchars($row['lastname'] . ', ' . $row['firstname']);

    // Merged Employee column: avatar + name + employee number
    $subdata[] = '<div class="d-flex align-items-center gap-2">'
        . '<div class="emp-avatar">' . $initials . '</div>'
        . '<div><div class="emp-name">' . $fullname . '</div>'
        . '<div class="emp-id"><i class="ri-hashtag" style="font-size:10px;opacity:.6;"></i>' . htmlspecialchars($row['employee_no']) . '</div></div>'
        . '</div>';
    $subdata[] = '<span class="emp-position">' . htmlspecialchars($row['position'] ?? '—') . '</span>';
    $subdata[] = '<span class="emp-position">' . htmlspecialchars($row['department'] ?? '—') . '</span>';
    // Pay figures never leave the server for a timekeeper.
    $money = function ($v) use ($tk_hide_pay) {
        return $tk_hide_pay
            ? '<span class="text-muted">—</span>'
            : '<span class="emp-currency">&#8369; ' . number_format($v, 2) . '</span>';
    };
    $subdata[] = $money($row['basic_pay']);
    $subdata[] = $money($row['salary']);
    $subdata[] = $money($row['ot_rate']);
    $subdata[] = $tk_hide_pay ? '' : number_format($row['loan'], 2); // kept but not displayed in table
    $subdata[] = !empty($row['clasification'])
        ? '<span class="badge" style="' . clasif_badge_style($row['clasification']) . '"><i class="ri-shield-check-line me-1"></i>' . htmlspecialchars($row['clasification']) . '</span>'
        : '<span class="text-muted">—</span>';
    $subdata[] = ($row['status'] == 1)
        ? '<span class="badge rounded-pill bg-success"><i class="ri-checkbox-circle-line me-1"></i>Active</span>'
        : '<span class="badge rounded-pill bg-danger"><i class="ri-close-circle-line me-1"></i>Inactive</span>';
    $subdata[] = '<div class="emp-actions">'
        . '<a href="index.php?page=employee-details&id=' . $row['id'] . '" class="btn btn-sm btn-outline-success" data-bs-toggle="tooltip" data-bs-placement="top" title="View Employee Details">'
        . '<i class="ri-eye-line me-1"></i>View</a>'
        . '</div>';
    // Rate Type chip (index 10) — Daily / Monthly / Fixed pay basis.
    $rt = in_array($row['rate_type'] ?? 'daily', ['daily', 'monthly', 'fixed'], true) ? $row['rate_type'] : 'daily';
    $rtMeta = [
        'daily'   => ['#eef1f5', '#475569', 'Daily'],
        'monthly' => ['#dbeafe', '#1d4ed8', 'Monthly'],
        'fixed'   => ['#ede9fe', '#6d28d9', 'Fixed'],
    ][$rt];
    $subdata[] = $tk_hide_pay ? '' : '<span style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;background:'
        . $rtMeta[0] . ';color:' . $rtMeta[1] . ';" title="' . ($rt === 'fixed' ? 'Full salary, no attendance' : ($rt === 'monthly' ? 'Salary minus absences' : 'Paid per day present')) . '">'
        . $rtMeta[2] . '</span>';
    $data[] = $subdata;
}

$json_data = array(
    "draw"              =>  intval($request['draw']),
    "recordsTotal"      =>  intval($totalData),
    "recordsFiltered"   =>  intval($totalFilter),
    "data"              =>  $data
);

echo json_encode($json_data);