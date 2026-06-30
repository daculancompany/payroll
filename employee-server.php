<?php
include 'db_connect.php';


$request = $_REQUEST;
$status        = isset($request['status'])        && $request['status']        !== '' ? (int)$request['status']        : 2;
$weekly_payroll = isset($request['weekly_payroll']) && $request['weekly_payroll'] !== '' ? (int)$request['weekly_payroll'] : 2;
$position_id   = isset($request['position_id'])   && $request['position_id']   !== '' ? (int)$request['position_id']   : null;
$department_id = isset($request['department_id']) && $request['department_id'] !== '' ? (int)$request['department_id'] : null;

$col = array(
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
$_filter_payroll_type = '';
if ($weekly_payroll === 0 || $weekly_payroll === 1) {
    $_filter_payroll_type = " AND e.weekly_payroll = $weekly_payroll";
}
$filter_position = '';
if ($position_id) {
    $filter_position = " AND e.position_id = $position_id";
}
$filter_department = '';
if ($department_id) {
    $filter_department = " AND e.department_id = $department_id";
}



$sql = "SELECT e.id, e.loan, e.employee_no, e.firstname, e.middlename, e.lastname, e.salary, e.basic_pay, e.ot_rate, e.status, e.weekly_payroll, d.name AS department, p.name AS position, cl.clasification AS clasification FROM employee e
        LEFT JOIN department d ON e.department_id = d.id
        LEFT JOIN position p ON e.position_id = p.id
        LEFT JOIN clasification cl ON e.clasification_id = cl.id WHERE e.id != 0 $filter_status $_filter_payroll_type $filter_position $filter_department";

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

$sql .= " ORDER BY " . $col[$request['order'][0]['column']] . "   " . $request['order'][0]['dir'] . "  LIMIT " . $request['start'] . " ," . $request['length'] . "  ";
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
    $subdata[] = '<span class="emp-currency">&#8369; ' . number_format($row['basic_pay'], 2) . '</span>';
    $subdata[] = '<span class="emp-currency">&#8369; ' . number_format($row['salary'], 2) . '</span>';
    $subdata[] = '<span class="emp-currency">&#8369; ' . number_format($row['ot_rate'], 2) . '</span>';
    $subdata[] = number_format($row['loan'], 2); // kept but not displayed in table
    $subdata[] = !empty($row['clasification'])
        ? '<span class="badge bg-info-subtle text-info border border-info-subtle"><i class="ri-shield-check-line me-1"></i>' . htmlspecialchars($row['clasification']) . '</span>'
        : '<span class="text-muted">—</span>';
    $subdata[] = ($row['status'] == 1)
        ? '<span class="badge rounded-pill bg-success"><i class="ri-checkbox-circle-line me-1"></i>Active</span>'
        : '<span class="badge rounded-pill bg-danger"><i class="ri-close-circle-line me-1"></i>Inactive</span>';
    $subdata[] = '<div class="emp-actions">'
        . '<a href="index.php?page=employee-details&id=' . $row['id'] . '" class="btn btn-sm btn-outline-success" data-bs-toggle="tooltip" data-bs-placement="top" title="View Employee Details">'
        . '<i class="ri-eye-line me-1"></i>View</a>'
        . '</div>';
    $data[] = $subdata;
}

$json_data = array(
    "draw"              =>  intval($request['draw']),
    "recordsTotal"      =>  intval($totalData),
    "recordsFiltered"   =>  intval($totalFilter),
    "data"              =>  $data
);

echo json_encode($json_data);