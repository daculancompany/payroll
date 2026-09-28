<?php
/**
 * Department Summary — the "PAYROLL SUMMARY" sheet of PAYSHEET SAMPLE.xls:
 * one row per department (by id), the NONATM (cash-paid) block last, TOTAL,
 * then the ATM / NON ATM / TOTAL net split and the O. DEDUCTIONS breakdown.
 *
 * Every figure is a department TOTAL row of the paysheet (includes/paysheet.php),
 * so this summary always equals the paysheet it summarises.
 * Rendered to PDF by pdf-payroll.php?src=dept&id=N.
 */
include_once 'db_connect.php';
require_once __DIR__ . "/includes/session_bootstrap.php";
// Reachable directly as well as through pdf-payroll.php, and it had no
// access control: a plain GET rendered a whole payroll. Guarded here too so
// the URL and the PDF entry point can never disagree about who may read it.
if (empty($_SESSION["is_login"])) { http_response_code(403); exit("Not authorized."); }
require_page_access("payroll", "text");
require_once __DIR__ . '/includes/paysheet.php';

if (!isset($_GET['id'])) { return; }
$ps = paysheet_build($conn, (int) $_GET['id']);
$payroll = $ps['payroll'];
if (!$payroll) { exit('Payroll not found.'); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payroll Summary</title>
<?php include __DIR__ . "/includes/favicon.php"; ?>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
@page { size: legal landscape; margin: 8mm 10mm; }
body { font-family: Arial, sans-serif; color: #000; background: #fff; }

.title { text-align: center; font-size: 12px; line-height: 1.4; }
.title .co { font-size: 14px; font-weight: bold; }

tr { page-break-inside: avoid; }
thead { display: table-header-group; }

.sigs { display: table; width: 100%; margin-top: 36px; font-size: 11px; }
.sig-block { display: table-cell; padding: 0 10px; }
.sig-block p { line-height: 1; margin: 4px 0; }
.sig-inner { margin-left: 20px; }
</style>
</head>
<body>

  <div class="title">
    <div class="co">CDO Medical Center</div>
    <div><b>PAYROLL REPORT</b></div>
    <div><?= htmlspecialchars(strtoupper($ps['period'])) ?></div>
  </div>

  <div style="margin-top:10px;"><?= paysheet_summary_html($ps) ?></div>

  <div class="sigs">
    <div class="sig-block">
      Prepared By:
      <div class="sig-inner">
        <p><b><?= htmlspecialchars($payroll['prepared_by'] ?? '') ?></b></p>
        <p><?= htmlspecialchars($payroll['prepared_by_role'] ?? '') ?></p>
      </div>
    </div>
    <div class="sig-block">
      Verified By:
      <div class="sig-inner">
        <p><b><?= htmlspecialchars($payroll['verified_by'] ?? '') ?></b></p>
        <p><?= htmlspecialchars($payroll['verified_by_role'] ?? '') ?></p>
      </div>
    </div>
    <div class="sig-block">
      Noted By:
      <div class="sig-inner"><p><b></b></p><p></p></div>
    </div>
    <div class="sig-block">
      Checked By:
      <div class="sig-inner"><p><b></b></p><p></p></div>
    </div>
    <div class="sig-block">
      Approved By:
      <div class="sig-inner">
        <p><b><?= htmlspecialchars($payroll['approved_by'] ?? '') ?></b></p>
        <p><?= htmlspecialchars($payroll['approved_by_role'] ?? '') ?></p>
      </div>
    </div>
  </div>

<script>
window.print();
window.onafterprint = function() { window.close(); };
</script>
</body>
</html>
