<?php
// ── Bulk payslip print for the employee self-service portal ────────────────
// Renders several of THIS employee's payslips on one page (page-break each) so
// the browser's Save-as-PDF produces a single multi-payslip file.
// Security: ids are always re-scoped to the logged-in employee — any payslip
// that isn't theirs (or whose payroll isn't Ready-for-Review / Locked) is dropped.
session_start();

if (empty($_SESSION['emp_is_login'])) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

include 'db_connect.php';
$emp_id = (int) $_SESSION['emp_id'];

// Parse + sanitise the requested payslip-item ids.
$raw = $_GET['ids'] ?? '';
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), function ($v) { return $v > 0; })));

$valid = [];
if ($ids) {
    $inList = implode(',', $ids);
    $res = $conn->query("SELECT pi.id
        FROM payroll_items pi
        INNER JOIN payroll p ON p.id = pi.payroll_id
        WHERE pi.id IN ($inList) AND pi.employee_id = $emp_id AND p.status IN (2, 3)");
    if ($res) while ($r = $res->fetch_assoc()) $valid[] = (int) $r['id'];
}

if (!$valid) {
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:Segoe UI,Arial,sans-serif;padding:40px;text-align:center;color:#666;">'
       . '<h3>No payslips to print</h3><p>The selected payslips are not available for your account.</p>'
       . '<button onclick="window.close()" style="padding:8px 16px;">Close</button></body>';
    exit;
}

$GLOBALS['PAYSLIP_EMBED'] = true;   // tells view_payslip.php to emit body-only fragments
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Payslips (<?= count($valid) ?>)</title>
<link href="assets/css/icons.min.css" rel="stylesheet">
<style>
    body { background:#f0f2f5; }
    .bulk-toolbar { position:sticky; top:0; z-index:50; display:flex; gap:10px; align-items:center;
        background:#0e6b37; color:#fff; padding:10px 16px; font-family:'Segoe UI',Arial,sans-serif; }
    .bulk-toolbar .bt-title { font-weight:700; flex:1; }
    .bulk-toolbar button { border:0; border-radius:7px; padding:7px 14px; font-weight:700; cursor:pointer; }
    .bt-print { background:#fff; color:#0e6b37; }
    .bt-close { background:rgba(255,255,255,.18); color:#fff; }
    @media print { .bulk-toolbar { display:none !important; } body { background:#fff; } }
</style>
</head>
<body>
<div class="bulk-toolbar">
    <span class="bt-title"><i class="ri-file-list-3-line"></i> My Payslips — <?= count($valid) ?> selected</span>
    <button class="bt-print" onclick="window.print()"><i class="ri-printer-line"></i> Print / Save PDF</button>
    <button class="bt-close" onclick="window.close()"><i class="ri-close-line"></i> Close</button>
</div>

<?php foreach ($valid as $pid): $_GET['id'] = $pid; include 'view_payslip.php'; endforeach; ?>

<script>
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
</script>
</body>
</html>
