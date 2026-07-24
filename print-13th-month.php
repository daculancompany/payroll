<?php
// 13th Month Pay register — printable sheet with signature column.
// Routed through pdf-payroll.php?src=13th&id=<year> (id carries the YEAR).
include 'db_connect.php';
if (!isset($_GET['id'])) { return; }
$year = (int) $_GET['id'];

$rows = [];
$q = $conn->query("SELECT t.*, e.employee_no, e.lastname, e.firstname, d.name AS department
    FROM thirteenth_month t
    INNER JOIN employee e ON e.id = t.employee_id
    LEFT JOIN department d ON d.id = e.department_id
    WHERE t.year = $year
    ORDER BY e.lastname ASC, e.firstname ASC");
if ($q) while ($r = $q->fetch_assoc()) $rows[] = $r;

$t_basic = 0; $t_final = 0; $finalized = false;
foreach ($rows as $r) {
    $t_basic += (float) $r['basic_earned'];
    $t_final += $r['override_amount'] !== null ? (float) $r['override_amount'] : (float) $r['amount'];
    if ((int) $r['status'] === 1) $finalized = true;
}
function n13($v) { return number_format((float) $v, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>13th Month Pay Register <?= $year ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
@page { size: legal landscape; margin: 8mm 10mm; }
body { font-family: Arial, sans-serif; color: #000; background: #fff; }
.top { text-align: center; font-size: 12px; }
.top > div { display: inline-block; vertical-align: middle; text-align: center; }
.logo-area { width: 70px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9px; }
th, td { border: 1px solid #000; padding: 3px 4px; text-align: center; }
th { background-color: #f2f2f2; font-weight: bold; text-transform: uppercase; }
td.l { text-align: left; }
td.r, th.r { text-align: right; }
td.sig { width: 130px; }
tfoot th { text-align: right; }
tr { page-break-inside: avoid; }
thead { display: table-header-group; }
.sigs { display: table; width: 100%; margin-top: 40px; font-size: 11px; }
.sig-block { display: table-cell; padding: 0 10px; }
.sig-block p { line-height: 1; margin: 4px 0; }
</style>
</head>
<body>
  <div class="top">
    <div class="logo-area"><img style="width:60px;" src="assets2/images/logo.jpeg" alt="Logo"></div>
    <div>
      <div>COMC</div>
      <div>TIU SONS, BUILDING BARANGAY 33, GUILLERMO COGON CAGAYAN DE ORO CITY</div>
      <div><strong>13TH MONTH PAY REGISTER &mdash; <?= $year ?></strong><?= $finalized ? '' : ' (DRAFT)' ?></div>
    </div>
  </div>

  <table cellspacing="0">
    <thead>
      <tr>
        <th style="width:30px;">No.</th>
        <th style="width:80px;">Emp. No.</th>
        <th>Employee Name</th>
        <th>Department</th>
        <th style="width:55px;">Cutoffs</th>
        <th class="r" style="width:100px;">Basic Earned</th>
        <th class="r" style="width:100px;">13th Month Pay</th>
        <th style="width:150px;">Remarks</th>
        <th class="sig">Signature</th>
      </tr>
    </thead>
    <tbody>
      <?php $i = 0; foreach ($rows as $r): $i++;
          $final = $r['override_amount'] !== null ? (float) $r['override_amount'] : (float) $r['amount']; ?>
      <tr>
        <td><?= $i ?></td>
        <td><?= htmlspecialchars($r['employee_no']) ?></td>
        <td class="l"><b><?= htmlspecialchars($r['lastname'] . ', ' . $r['firstname']) ?></b></td>
        <td class="l"><?= htmlspecialchars($r['department'] ?? '') ?></td>
        <td><?= (int) $r['cutoffs'] ?></td>
        <td class="r"><?= n13($r['basic_earned']) ?></td>
        <td class="r"><b><?= n13($final) ?></b></td>
        <td class="l" style="font-size:8px;"><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
        <td class="sig"></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="5">TOTAL</th>
        <th class="r"><?= n13($t_basic) ?></th>
        <th class="r"><?= n13($t_final) ?></th>
        <th colspan="2"></th>
      </tr>
    </tfoot>
  </table>

  <div class="sigs">
    <div class="sig-block">
      Prepared By:
      <p>&nbsp;</p><p>&nbsp;</p>
      <p>_______________________</p>
    </div>
    <div class="sig-block">
      Checked By:
      <p>&nbsp;</p><p>&nbsp;</p>
      <p>_______________________</p>
    </div>
    <div class="sig-block">
      Approved By:
      <p>&nbsp;</p><p>&nbsp;</p>
      <p>_______________________</p>
    </div>
  </div>
</body>
</html>
