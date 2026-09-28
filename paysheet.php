<?php
/**
 * Paysheet table (by department) for one payroll — returned as an HTML fragment
 * and loaded into the Table View's "Paysheet" tab (payroll_calculations.php),
 * fetched fresh each time the tab opens so it reflects edits made in the
 * Detailed tab. Layout / figures: includes/paysheet.php.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login'])) {
    http_response_code(403);
    exit('Not authorised.');
}
if (!isset($conn)) include 'db_connect.php';
require_page_access('payroll', 'text');
require_once __DIR__ . '/includes/paysheet.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid payroll.');
}
header('Content-Type: text/html; charset=utf-8');
$ps = paysheet_build($conn, $id);
// ?view=summary → the Department Summary (web view of print-payroll-dept.php).
echo ($_GET['view'] ?? '') === 'summary' ? paysheet_summary_html($ps) : paysheet_table_html($ps);
