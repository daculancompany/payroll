<?php
/**
 * Paysheet print sheet (by department) — rendered to PDF by
 * pdf-payroll.php?src=paysheet&id=N. Layout / figures: includes/paysheet.php.
 */
include_once 'db_connect.php';
require_once __DIR__ . '/includes/session_bootstrap.php';
// Reachable directly as well as through pdf-payroll.php — guarded here too.
if (empty($_SESSION['is_login'])) { http_response_code(403); exit('Not authorized.'); }
require_page_access('payroll', 'text');
require_once __DIR__ . '/includes/paysheet.php';

if (!isset($_GET['id'])) { return; }
$ps = paysheet_build($conn, (int) $_GET['id']);
if (!$ps['payroll']) { exit('Payroll not found.'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PAYSHEET - <?= htmlspecialchars($ps['period']) ?></title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; }
        .top { text-align: center; font-size: 12px; margin-bottom: 8px; }
        .top > div { display: inline-block; vertical-align: middle; text-align: center; }
        .logo-area { width: 70px; }
        .paysheet-table { font-size: 7.5px !important; }
        .paysheet-table th, .paysheet-table td { padding: 2px 3px !important; }
        .paysheet-table thead { display: table-header-group; }
        .paysheet-table tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <div class="top">
        <div class="logo-area"><img style="width: 60px;" src="assets2/images/logo.jpeg" alt="Logo"></div>
        <div>
            <div>Cagayan de Oro medical center,Inc.</div>
            <div>Tiano-Nacalaban Street, Cagayan de Oro City</div>
            <div>PAYSHEET &mdash; PAYROLL PERIOD: <strong><?= htmlspecialchars($ps['period']) ?></strong></div>
        </div>
    </div>
    <?= paysheet_table_html($ps) ?>
</body>
</html>
