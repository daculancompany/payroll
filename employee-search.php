<?php
/**
 * Employee quick-find for the "Find" box on employee-details.
 *
 *   GET employee-search.php?q=<employee no. or name>
 *   → {"items":[{"id":1,"no":"2026-00001","name":"DELA CRUZ, JUAN M.","sub":"Cook · Dietary","active":1}, …]}
 *
 * Kept cheap on purpose (the browser already waits 300 ms after typing and
 * cancels stale requests): one indexed-prefix-friendly query, at most 15 rows,
 * same department/area scope as the employee list and Prev/Next.
 */
require_once __DIR__ . '/includes/require_login.php';
include 'db_connect.php';
require_once 'dept-scope.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

$login_role = $_SESSION['login_role'] ?? null;
if (!page_allowed('employee-details', $login_role)) {
    http_response_code(403);
    echo json_encode(['items' => [], 'message' => 'Not allowed']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$q = mb_substr(preg_replace('/\s+/', ' ', $q), 0, 60);
if ($q === '') { echo json_encode(['items' => []]); exit; }

// "dela cruz, juan" → last name part + first name part; otherwise one term
// matched against employee no., last name, first name.
$like = function ($s) { return addcslashes($s, '%_\\'); };
$parts = array_map('trim', explode(',', $q, 2));
$scope = dept_scope_sql('e.department_id');

if (count($parts) === 2 && $parts[1] !== '') {
    $where = "(e.lastname LIKE ? AND e.firstname LIKE ?)";
    $types = 'ss';
    $args  = [$like($parts[0]) . '%', $like($parts[1]) . '%'];
} else {
    $t = $like($parts[0]);
    $where = "(e.employee_no LIKE ? OR e.lastname LIKE ? OR e.firstname LIKE ? OR CONCAT(e.firstname, ' ', e.lastname) LIKE ?)";
    $types = 'ssss';
    $args  = ['%' . $t . '%', $t . '%', $t . '%', $t . '%'];
}

// Rank: exact employee no., then last-name prefix, then the rest; active first.
$stmt = $conn->prepare("SELECT e.id, e.employee_no, e.lastname, e.firstname, e.middlename, e.ext, e.status,
        p.name AS pos, d.name AS dept
    FROM employee e
    LEFT JOIN position p ON p.id = e.position_id
    LEFT JOIN department d ON d.id = e.department_id
    WHERE $where $scope
    ORDER BY (e.employee_no = ?) DESC, (e.lastname LIKE ?) DESC, e.status DESC, e.lastname, e.firstname, e.id
    LIMIT 15");
$types .= 'ss';
$args[] = $q;
$args[] = $like($parts[0]) . '%';
$stmt->bind_param($types, ...$args);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($r = $res->fetch_assoc()) {
    $mi = trim((string) $r['middlename']) !== '' ? ' ' . mb_strtoupper(mb_substr(trim($r['middlename']), 0, 1)) . '.' : '';
    $items[] = [
        'id'     => (int) $r['id'],
        'no'     => (string) $r['employee_no'],
        'name'   => trim($r['lastname'] . ', ' . $r['firstname'] . ($r['ext'] ? ' ' . $r['ext'] : '') . $mi),
        'sub'    => trim(ucwords(strtolower((string) $r['pos'])) . ($r['dept'] ? ' · ' . $r['dept'] : ''), ' ·'),
        'active' => (int) $r['status'] === 1 ? 1 : 0,
    ];
}
$stmt->close();

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
