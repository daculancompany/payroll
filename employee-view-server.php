<?php
/**
 * Employee quick-view — read-only JSON behind the shared EmployeeView drawer
 * (assets2/js/employee-view.js).
 *
 * One GET, one employee, only the "important details": identity, employment,
 * current schedule, and masked government/bank numbers. It deliberately returns
 * NO editable surface and no full pay breakdown — the drawer is a glance, the
 * employee-details page stays the workbench.
 *
 * Visibility rules
 *   - Department-scoped accounts (Dept Head 8 / Supervisor 10) only get their
 *     own department's employees (dept-scope.php), same as every other screen.
 *   - Compensation (rate type + rate + allowance) is omitted for Timekeepers
 *     (role 5) and the view-only DTR role (6) — mirrors the server-side pay
 *     blanking those roles already get elsewhere.
 *   - Government IDs and the bank account ship masked (last 4) for everyone;
 *     the full numbers live only on employee-details.php.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
header('Content-Type: application/json');

if (empty($_SESSION['is_login'])) {
    http_response_code(403);
    echo json_encode(['result' => false, 'message' => 'Not authenticated']);
    exit;
}

include 'db_connect.php';
require_once 'dept-scope.php';

$empId = (int)($_GET['id'] ?? 0);
if ($empId <= 0) {
    http_response_code(400);
    echo json_encode(['result' => false, 'message' => 'Missing employee id']);
    exit;
}

$role       = (int)($_SESSION['login_role'] ?? 0);
$canSeePay  = !(function_exists('is_timekeeper') && is_timekeeper($role)) && $role !== 6;

$stmt = $conn->prepare("
    SELECT e.id, e.employee_no, e.employee_code, e.firstname, e.middlename, e.lastname, e.ext,
           e.status, e.rate_type, e.salary, e.basic_pay, e.allowance_rate, e.bday,
           e.sss_no, e.ph_no, e.hdmf_no, e.tin_no, e.bank_account_no,
           d.name AS department, p.name AS position, c.clasification AS classification,
           b.bank_name
    FROM employee e
    LEFT JOIN department d    ON d.id = e.department_id
    LEFT JOIN position p      ON p.id = e.position_id
    LEFT JOIN clasification c ON c.id = e.clasification_id
    LEFT JOIN banks b         ON b.id = e.bank_id
    WHERE e.id = ?" . dept_scope_sql('e.department_id'));
$stmt->bind_param('i', $empId);
$stmt->execute();
$e = $stmt->get_result()->fetch_assoc();

if (!$e) {
    http_response_code(404);
    echo json_encode(['result' => false, 'message' => 'Employee not found']);
    exit;
}

// Current work schedule (effective today), if any.
$schedule = null;
$sq = $conn->prepare("
    SELECT ws.description, ws.start_time, ws.end_time, es.rest_days
    FROM employee_schedules es
    INNER JOIN work_schedules ws ON ws.id = es.schedule_id
    WHERE es.employee_id = ? AND es.effective_from <= CURDATE()
      AND (es.effective_to IS NULL OR es.effective_to >= CURDATE())
    ORDER BY es.effective_from DESC LIMIT 1");
$sq->bind_param('i', $empId);
$sq->execute();
if ($s = $sq->get_result()->fetch_assoc()) {
    $DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $rest = implode(', ', array_map(
        fn($d) => $DAYS[(int)$d] ?? $d,
        array_filter(array_map('trim', explode(',', (string)$s['rest_days'])), fn($v) => $v !== '')
    ));
    $schedule = [
        'label' => $s['description'],
        'time'  => date('g:i A', strtotime($s['start_time'])) . ' – ' . date('g:i A', strtotime($s['end_time'])),
        'rest'  => $rest,
    ];
}

/** Everything but the last 4 characters becomes dots; empty stays null. */
$mask = function (?string $v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    return strlen($v) <= 4 ? $v : '•••• ' . substr($v, -4);
};

echo json_encode(['result' => true, 'employee' => [
    'id'             => (int)$e['id'],
    'name'           => trim($e['lastname'] . ', ' . $e['firstname'] . ' ' . ($e['middlename'] ?? '') . ' ' . ($e['ext'] ?? '')),
    'employee_no'    => $e['employee_no'],
    'employee_code'  => $e['employee_code'],
    'active'         => (int)$e['status'] === 1,
    'department'     => $e['department'],
    'position'       => $e['position'],
    'classification' => $e['classification'],
    'bday'           => $e['bday'] ? date('M j, Y', strtotime($e['bday'])) : null,
    'schedule'       => $schedule,
    'pay'            => $canSeePay ? [
        'rate_type' => $e['rate_type'],
        'rate'      => (float)($e['rate_type'] === 'monthly' ? $e['salary'] : $e['basic_pay']),
        'allowance' => (float)$e['allowance_rate'],
    ] : null,
    'gov'            => [
        'sss'        => $mask($e['sss_no']),
        'philhealth' => $mask($e['ph_no']),
        'pagibig'    => $mask($e['hdmf_no']),
        'tin'        => $mask($e['tin_no']),
    ],
    'bank'           => $e['bank_name'] ? [
        'name'    => $e['bank_name'],
        'account' => $mask($e['bank_account_no']),
    ] : null,
    'profile_url'    => 'index.php?page=employee-details&id=' . (int)$e['id'],
]], JSON_UNESCAPED_UNICODE);
