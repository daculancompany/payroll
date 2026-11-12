<?php
/**
 * Admin login guard. Put it on the first line of every admin endpoint:
 *
 *     require_once __DIR__ . '/includes/require_login.php';
 *
 * Several root files (DataTables feeds, modal forms, print views and routed
 * fragments) only included db_connect.php. Nothing stopped someone who wasn't
 * signed in from requesting them directly, e.g. GET /employee-server.php or
 * /view_employee.php?id=1. This guard closes that hole in one place.
 *
 * It is safe to include from inside index.php or app.php: the session is
 * already running there and the check passes, so the include costs nothing.
 *
 * An employee-portal session (emp_is_login) is not an admin session and gets
 * refused here.
 */
require_once __DIR__ . '/session_bootstrap.php';

if (PHP_SAPI !== 'cli' && empty($_SESSION['is_login'])) {
    http_response_code(403);

    $wantsJson = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['result' => false, 'message' => 'Not authenticated']);
    } else {
        header('Location: login.php');
    }
    exit;
}
