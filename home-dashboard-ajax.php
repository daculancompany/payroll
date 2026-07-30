<?php
// Dynamic data source for the admin dashboard (home.php).
// Admin sessions only — employees and guests get a 403.
require_once __DIR__ . '/includes/session_bootstrap.php';
header('Content-Type: application/json');

if (empty($_SESSION['is_login'])) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

include 'db_connect.php';
require_once 'home-dashboard-data.php';

$action = $_GET['action'] ?? '';

if ($action === 'deptpay') {
    // Per-department breakdown for one LOCKED payroll (validated server-side).
    $pid = (int)($_GET['payroll_id'] ?? 0);
    $chk = $conn->query("SELECT id FROM payroll WHERE id = $pid AND status = 2 LIMIT 1");
    if (!$chk || !$chk->num_rows) {
        http_response_code(404);
        echo json_encode(['error' => 'payroll not found or not locked']);
        exit;
    }
    echo json_encode(dashboard_deptpay($conn, $pid));
    exit;
}

if ($action === 'stats') {
    echo json_encode(dashboard_live_stats($conn));
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
