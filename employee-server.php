<?php
include 'db_connect.php';
require_once 'dept-scope.php';


$request = $_REQUEST;
$status        = isset($request['status'])        && $request['status']        !== '' ? (int)$request['status']        : 2;
$position_id   = isset($request['position_id'])   && $request['position_id']   !== '' ? (int)$request['position_id']   : null;
$department_id = isset($request['department_id']) && $request['department_id'] !== '' ? (int)$request['department_id'] : null;

// Department Heads are locked to their own department regardless of the UI filter.
if (dept_scope_id() > 0) {
    $department_id = dept_scope_id();
}

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



// Kiosk biometric indicators. Guarded by a table check so the employee list
// keeps working on an install where the kiosk migration has not run yet.
$kiosk_bio_ready =
    ($r = mysqli_query($conn, "SHOW TABLES LIKE 'biometric_kiosk_faces'")) && mysqli_num_rows($r) > 0 &&
    ($r = mysqli_query($conn, "SHOW TABLES LIKE 'biometric_kiosk_templates'")) && mysqli_num_rows($r) > 0;

$bio_cols = $kiosk_bio_ready
    ? ", (SELECT COUNT(*) FROM biometric_kiosk_templates t WHERE t.employee_id = e.id) AS fp_count,
        (SELECT COUNT(*) FROM biometric_kiosk_faces f WHERE f.employee_id = e.id) AS face_count"
    : ", 0 AS fp_count, 0 AS face_count";

$sql = "SELECT e.id, e.loan, e.employee_no, e.firstname, e.middlename, e.lastname, e.salary, e.basic_pay, e.ot_rate, e.status, e.weekly_payroll, d.name AS department, p.name AS position, cl.clasification AS clasification $bio_cols FROM employee e
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

    // Kiosk biometric indicators: fingerprint icon with enrolled-finger
    // count; face icon is a plain registered/not indicator — an employee
    // only ever has ONE face, so a number would be noise.
    $fp_count = (int) ($row['fp_count'] ?? 0);
    $has_face = (int) ($row['face_count'] ?? 0) > 0;
    $bio_icons = '<span class="emp-bio">'
        . '<span class="emp-bio-item ' . ($fp_count ? 'on' : '') . '" title="'
        . ($fp_count ? $fp_count . ' fingerprint' . ($fp_count > 1 ? 's' : '') . ' enrolled' : 'No fingerprints enrolled') . '">'
        . '<i class="ri-fingerprint-line"></i>' . ($fp_count ? $fp_count : '') . '</span>'
        . '<span class="emp-bio-item ' . ($has_face ? 'on' : '') . '" title="'
        . ($has_face ? 'Face registered' : 'No face registered') . '">'
        . '<i class="ri-body-scan-line"></i></span>'
        . '</span>';

    // Merged Employee column: avatar + name + employee number + biometrics
    $subdata[] = '<div class="d-flex align-items-center gap-2">'
        . '<div class="emp-avatar">' . $initials . '</div>'
        . '<div><div class="emp-name">' . $fullname . '</div>'
        . '<div class="emp-id"><i class="ri-hashtag" style="font-size:10px;opacity:.6;"></i>' . htmlspecialchars($row['employee_no'])
        . $bio_icons . '</div></div>'
        . '</div>';
    $subdata[] = '<span class="emp-position">' . htmlspecialchars($row['position'] ?? '—') . '</span>';
    $subdata[] = '<span class="emp-position">' . htmlspecialchars($row['department'] ?? '—') . '</span>';
    $subdata[] = '<span class="emp-currency">&#8369; ' . number_format($row['basic_pay'], 2) . '</span>';
    $subdata[] = '<span class="emp-currency">&#8369; ' . number_format($row['salary'], 2) . '</span>';
    $subdata[] = '<span class="emp-currency">&#8369; ' . number_format($row['ot_rate'], 2) . '</span>';
    $subdata[] = number_format($row['loan'], 2); // kept but not displayed in table
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
    $data[] = $subdata;
}

$json_data = array(
    "draw"              =>  intval($request['draw']),
    "recordsTotal"      =>  intval($totalData),
    "recordsFiltered"   =>  intval($totalFilter),
    "data"              =>  $data
);

echo json_encode($json_data);