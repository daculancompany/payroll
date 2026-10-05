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
        /* Header + signature footer repeat on EVERY page: both are position:fixed
           inside the @page margins (dompdf paints fixed boxes on each page). The
           margins are restated in pdf-payroll.php's 'paysheet' density rule,
           since its generic @page override is injected after this block. */
        @page { margin: 78px 14px 74px; }
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; }
        .ps-head { position: fixed; top: -70px; left: 0; right: 0; height: 62px; text-align: center; font-size: 13px; }
        .ps-head > div { display: inline-block; vertical-align: middle; text-align: center; }
        .logo-area { width: 70px; }
        .ps-foot { position: fixed; bottom: -68px; left: 0; right: 0; height: 62px; font-size: 11px; }
        .ps-sigs { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .ps-sigs td { width: 25%; padding: 0 18px; vertical-align: top; }
        .ps-sig-lbl { font-weight: bold; }
        .ps-sig-line { margin-top: 26px; border-top: 1px solid #000; }
        .paysheet-table { font-size: 10px !important; }
        .paysheet-table th, .paysheet-table td { padding: 2px 3px !important; }
        .paysheet-table thead { display: table-header-group; }
        .paysheet-table tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <div class="ps-head">
        <div class="logo-area"><img style="width: 60px;" src="assets2/images/logo.jpeg" alt="Logo"></div>
        <div>
            <div>Cagayan de Oro medical center,Inc.</div>
            <div>Tiano-Nacalaban Street, Cagayan de Oro City</div>
            <div>PAYSHEET &mdash; PAYROLL PERIOD: <strong><?= htmlspecialchars($ps['period']) ?></strong></div>
        </div>
    </div>
    <div class="ps-foot">
        <table class="ps-sigs"><tr>
            <?php foreach (['Prepared By:', 'Checked By:', 'Approved By:', 'Noted By:'] as $lbl): ?>
            <td><div class="ps-sig-lbl"><?= $lbl ?></div><div class="ps-sig-line"></div></td>
            <?php endforeach; ?>
        </tr></table>
        <?php /* "Page X of Y" is stamped by pdf-payroll.php after layout. */ ?>
    </div>
    <?= paysheet_table_html($ps) ?>
</body>
</html>
