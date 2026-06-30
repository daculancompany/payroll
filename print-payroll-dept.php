<?php
include 'db_connect.php';
if (!isset($_GET['id'])) { return; }
$id = (int)$_GET['id'];

$stmt = $conn->prepare("SELECT payroll.*, employer_name FROM payroll INNER JOIN employers ON payroll.employer_id = employers.id WHERE payroll.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$payroll = $stmt->get_result()->fetch_assoc();

$stmt2 = $conn->prepare("
    SELECT
        COALESCE(d.name, 'NO DEPARTMENT')                            AS dept_name,
        COUNT(pi.id)                                                  AS emp_count,
        SUM(pi.basic_pay)                                             AS total_basic,
        SUM(pi.allowance_amount * pi.allowance_days)                  AS total_allowance,
        SUM(pi.absent * pi.per_day)                                   AS total_absent,
        SUM((pi.basic_pay + (pi.allowance_amount * pi.allowance_days) - (pi.absent * pi.per_day)) / 2)
                                                                      AS total_amount,
        SUM(pi.ot * pi.ot_rate)                                       AS total_ot,
        SUM(pi.legal_holiday * pi.per_day)                            AS total_legal,
        SUM(pi.sunday_duty * pi.per_day)                              AS total_sunday,
        SUM((pi.per_day / 8 * 2.4) * pi.special_holiday)             AS total_special,
        SUM(pi.late * (pi.per_day / 480))                             AS total_late,
        SUM(
            ((pi.basic_pay + (pi.allowance_amount * pi.allowance_days) - (pi.absent * pi.per_day)) / 2)
            + (pi.ot * pi.ot_rate)
            + (pi.legal_holiday * pi.per_day)
            + (pi.sunday_duty * pi.per_day)
            + ((pi.per_day / 8 * 2.4) * pi.special_holiday)
            - (pi.late * (pi.per_day / 480))
        )                                                             AS total_gross,
        SUM(COALESCE(pi.deduction_amount, 0))                         AS total_contributions,
        SUM(COALESCE(pi.other_deduction, 0))                          AS total_other_ded,
        SUM(COALESCE(pi.tax, 0))                                      AS total_tax,
        SUM(COALESCE(pi.jei_advances, 0))                             AS total_jei,
        SUM(COALESCE(pi.jcc_advances, 0))                             AS total_jcc,
        SUM(COALESCE(pi.sss_fund, 0))                                 AS total_sss,
        SUM(pi.net)                                                   AS total_net
    FROM payroll_items pi
    INNER JOIN employee e ON pi.employee_id = e.id
    LEFT JOIN department d ON e.department_id = d.id
    WHERE pi.payroll_id = ?
    GROUP BY d.id, d.name
    ORDER BY d.name ASC
");
$stmt2->bind_param("i", $id);
$stmt2->execute();
$dept_rows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

$g = ['emp'=>0,'basic'=>0,'allow'=>0,'absent'=>0,'amount'=>0,'ot'=>0,'legal'=>0,'sunday'=>0,'special'=>0,'late'=>0,'gross'=>0,'contribs'=>0,'other_ded'=>0,'tax'=>0,'jei'=>0,'jcc'=>0,'sss'=>0,'net'=>0];
foreach ($dept_rows as $r) {
    $g['emp']      += $r['emp_count'];
    $g['basic']    += $r['total_basic'];
    $g['allow']    += $r['total_allowance'];
    $g['absent']   += $r['total_absent'];
    $g['amount']   += $r['total_amount'];
    $g['ot']       += $r['total_ot'];
    $g['legal']    += $r['total_legal'];
    $g['sunday']   += $r['total_sunday'];
    $g['special']  += $r['total_special'];
    $g['late']     += $r['total_late'];
    $g['gross']    += $r['total_gross'];
    $g['contribs'] += $r['total_contributions'];
    $g['other_ded']+= $r['total_other_ded'];
    $g['tax']      += $r['total_tax'];
    $g['jei']      += $r['total_jei'];
    $g['jcc']      += $r['total_jcc'];
    $g['sss']      += $r['total_sss'];
    $g['net']      += $r['total_net'];
}
function n2($v){ return number_format((float)$v, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payroll Dept Summary</title>
<style>
* { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; box-sizing:border-box; margin:0; padding:0; }
@page { size: A4 landscape; margin: 8mm 10mm; }
body { font-family: Arial, sans-serif; font-size: 7.5pt; color: #111; background: #eee; }

@media print {
    body { background: #fff; }
    .wrapper { box-shadow: none !important; margin: 0 !important; max-width: 100% !important; }
    .hdr-bar  { background: #219688 !important; }
    .hdr-sub  { background: #176358 !important; }
    .tbl thead tr.hd1 th { background: #219688 !important; color:#fff !important; }
    .tbl thead tr.hd2 th { background: #2aa897 !important; color:#fff !important; }
    .tbl tfoot th         { background: #176358 !important; color:#fff !important; }
    .tbl tbody tr:nth-child(even) td { background: #f4fbfa !important; }
    .grp-earn   { background: #e8f7f5 !important; }
    .grp-ded    { background: #fdf0f0 !important; }
}

.wrapper { max-width: 1060px; margin: 14px auto; background: #fff; border: 2px solid #176358; box-shadow: 0 4px 18px rgba(0,0,0,.15); }

.hdr-bar {
    background: #219688;
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px; border-bottom: 3px solid #176358;
}
.hdr-bar img  { width: 40px; height: 40px; border-radius: 5px; background:#fff; padding:2px; }
.hdr-co   { font-size: 11pt; font-weight: 900; color: #fff; line-height:1.2; }
.hdr-addr { font-size: 7pt; color: rgba(255,255,255,.8); margin-top:2px; }
.hdr-badge{ margin-left:auto; border:2px solid rgba(255,255,255,.5); color:#fff; font-size:8pt; font-weight:800; padding:4px 10px; letter-spacing:1px; white-space:nowrap; text-align:center; }

.hdr-sub {
    background: #176358; color: rgba(255,255,255,.9);
    font-size: 8pt; padding: 4px 16px; display:flex; gap:24px;
}
.hdr-sub strong { color:#fff; }

.tbl-wrap { padding: 10px 12px 0; overflow-x:auto; }
.tbl { width:100%; border-collapse:collapse; font-size:7pt; white-space:nowrap; }

.tbl thead tr.hd1 th {
    background:#219688; color:#fff; font-size:7pt; font-weight:800;
    text-transform:uppercase; letter-spacing:.3px;
    border:1px solid #176358; padding:5px 6px; text-align:center;
}
.tbl thead tr.hd2 th {
    background:#2aa897; color:#fff; font-size:6.5pt; font-weight:700;
    border:1px solid #176358; padding:4px 5px; text-align:center;
}
.tbl thead tr.hd2 th.grp-earn { background:#1a8a7a !important; }
.tbl thead tr.hd2 th.grp-ded  { background:#c0392b !important; }

.tbl tbody td {
    padding:5px 6px; border:1px solid #dce8e6;
    font-size:7pt; color:#111; vertical-align:middle;
}
.tbl tbody tr:nth-child(even) td { background:#f4fbfa; }
.tbl tbody td.dept  { font-weight:800; text-transform:uppercase; letter-spacing:.2px; white-space:normal; min-width:90px; }
.tbl tbody td.c     { text-align:center; color:#555; }
.tbl tbody td.r     { text-align:right; }
.tbl tbody td.earn  { text-align:right; }
.tbl tbody td.ded   { text-align:right; color:#c0392b; }
.tbl tbody td.gross { text-align:right; font-weight:800; color:#176358; }
.tbl tbody td.net   { text-align:right; font-weight:900; color:#176358; background:#e8f7f5; }

.tbl tfoot th {
    background:#176358; color:#fff; padding:6px 6px;
    font-size:7.5pt; font-weight:800; border:1px solid #176358;
    text-align:right;
}
.tbl tfoot th.c { text-align:center; }
.tbl tfoot th.l { text-align:left; }

.sigs {
    display:flex; justify-content:space-between;
    padding:16px 16px 14px; gap:8px;
    border-top:2px solid #219688; margin-top:12px;
}
.sig-block { text-align:center; flex:1; }
.sig-lbl  { font-size:7pt; color:#888; }
.sig-name { font-size:8pt; font-weight:800; border-top:1px solid #333; padding-top:3px; margin-top:28px; }
.sig-role { font-size:7pt; color:#555; margin-top:1px; }

.pg-foot {
    background:#f4fbfa; border-top:1px solid #c8e6e2;
    padding:4px 16px; font-size:7pt; color:#888;
    display:flex; justify-content:space-between;
}
</style>
</head>
<body>
<div class="wrapper">

  <!-- Header -->
  <div class="hdr-bar">
    <img src="assets/images/logo.jpeg" alt="">
    <div>
      <div class="hdr-co">JEJORS CONSTRUCTION CORPORATION</div>
      <div class="hdr-addr">Tiu Sons, Building Barangay 33, Guillermo Cogon, Cagayan de Oro City</div>
    </div>
    <div class="hdr-badge">PAYROLL SUMMARY<br>BY DEPARTMENT</div>
  </div>

  <!-- Sub bar -->
  <div class="hdr-sub">
    <span>Pay Period: <strong><?= date('M d', strtotime($payroll['date_from'])) ?> – <?= date('M d, Y', strtotime($payroll['date_to'])) ?></strong></span>
    <span>Employer: <strong><?= htmlspecialchars($payroll['employer_name']) ?></strong></span>
    <span>Total Employees: <strong><?= number_format($g['emp']) ?></strong></span>
  </div>

  <!-- Table -->
  <div class="tbl-wrap">
    <table class="tbl" cellspacing="0">
      <thead>
        <tr class="hd1">
          <th rowspan="2" style="min-width:90px; text-align:left;">Department</th>
          <th rowspan="2" style="width:38px;">Emp.</th>
          <!-- Earnings group -->
          <th colspan="9" style="background:#176358 !important;">EARNINGS</th>
          <!-- Deductions group -->
          <th colspan="6" style="background:#a93226 !important;">DEDUCTIONS</th>
          <!-- Net -->
          <th rowspan="2" style="width:72px;">NET PAY</th>
        </tr>
        <tr class="hd2">
          <!-- Earnings sub-headers -->
          <th class="grp-earn" style="width:60px;">Basic Pay</th>
          <th class="grp-earn" style="width:58px;">Allowance</th>
          <th class="grp-earn" style="width:52px;">Absent</th>
          <th class="grp-earn" style="width:60px;">Total Amt</th>
          <th class="grp-earn" style="width:52px;">Overtime</th>
          <th class="grp-earn" style="width:50px;">Legal Hol.</th>
          <th class="grp-earn" style="width:50px;">Sun. Duty</th>
          <th class="grp-earn" style="width:52px;">Sp. Hol.</th>
          <th class="grp-earn" style="width:50px; color:#ffcccc;">Late</th>
          <!-- Deductions sub-headers -->
          <th class="grp-ded" style="width:58px;">Gross</th>
          <th class="grp-ded" style="width:55px;">Contribs.</th>
          <th class="grp-ded" style="width:50px;">SSS Fund</th>
          <th class="grp-ded" style="width:50px;">Tax</th>
          <th class="grp-ded" style="width:50px;">JEI Adv.</th>
          <th class="grp-ded" style="width:50px;">JCC Adv.</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dept_rows as $r): ?>
        <tr>
          <td class="dept"><?= htmlspecialchars($r['dept_name']) ?></td>
          <td class="c"><?= $r['emp_count'] ?></td>
          <!-- Earnings -->
          <td class="earn"><?= n2($r['total_basic']) ?></td>
          <td class="earn"><?= n2($r['total_allowance']) ?></td>
          <td class="ded">(<?= n2($r['total_absent']) ?>)</td>
          <td class="earn"><?= n2($r['total_amount']) ?></td>
          <td class="earn"><?= n2($r['total_ot']) ?></td>
          <td class="earn"><?= n2($r['total_legal']) ?></td>
          <td class="earn"><?= n2($r['total_sunday']) ?></td>
          <td class="earn"><?= n2($r['total_special']) ?></td>
          <td class="ded">(<?= n2($r['total_late']) ?>)</td>
          <!-- Gross then deductions -->
          <td class="gross"><?= n2($r['total_gross']) ?></td>
          <td class="ded"><?= n2($r['total_contributions']) ?></td>
          <td class="ded"><?= n2($r['total_sss']) ?></td>
          <td class="ded"><?= n2($r['total_tax']) ?></td>
          <td class="ded"><?= n2($r['total_jei']) ?></td>
          <td class="ded"><?= n2($r['total_jcc']) ?></td>
          <!-- Net -->
          <td class="net"><?= n2($r['total_net']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th class="l">TOTAL</th>
          <th class="c"><?= number_format($g['emp']) ?></th>
          <th><?= n2($g['basic']) ?></th>
          <th><?= n2($g['allow']) ?></th>
          <th><?= n2($g['absent']) ?></th>
          <th><?= n2($g['amount']) ?></th>
          <th><?= n2($g['ot']) ?></th>
          <th><?= n2($g['legal']) ?></th>
          <th><?= n2($g['sunday']) ?></th>
          <th><?= n2($g['special']) ?></th>
          <th><?= n2($g['late']) ?></th>
          <th><?= n2($g['gross']) ?></th>
          <th><?= n2($g['contribs']) ?></th>
          <th><?= n2($g['sss']) ?></th>
          <th><?= n2($g['tax']) ?></th>
          <th><?= n2($g['jei']) ?></th>
          <th><?= n2($g['jcc']) ?></th>
          <th><?= n2($g['net']) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Signatures -->
  <div class="sigs">
    <div class="sig-block">
      <div class="sig-lbl">Prepared By</div>
      <div class="sig-name"><?= htmlspecialchars($payroll['prepared_by'] ?? '') ?></div>
      <div class="sig-role"><?= htmlspecialchars($payroll['prepared_by_role'] ?? '') ?></div>
    </div>
    <div class="sig-block">
      <div class="sig-lbl">Verified By</div>
      <div class="sig-name"><?= htmlspecialchars($payroll['verified_by'] ?? '') ?></div>
      <div class="sig-role"><?= htmlspecialchars($payroll['verified_by_role'] ?? '') ?></div>
    </div>
    <div class="sig-block">
      <div class="sig-lbl">Noted By</div>
      <div class="sig-name">JAY O. VERAS</div>
      <div class="sig-role">HR HEAD</div>
    </div>
    <div class="sig-block">
      <div class="sig-lbl">Checked By</div>
      <div class="sig-name">JOVANIE ALAB</div>
      <div class="sig-role">A/P Team Leader</div>
    </div>
    <div class="sig-block">
      <div class="sig-lbl">Approved By</div>
      <div class="sig-name"><?= htmlspecialchars($payroll['approved_by'] ?? '') ?></div>
      <div class="sig-role"><?= htmlspecialchars($payroll['approved_by_role'] ?? '') ?></div>
    </div>
  </div>

  <!-- Footer -->
  <div class="pg-foot">
    <span>JEJORS CONSTRUCTION CORPORATION — Confidential Payroll Document</span>
    <span>Generated: <?= date('M d, Y g:i A') ?></span>
  </div>

</div>
<script>
window.print();
window.onafterprint = function() { window.close(); };
</script>
</body>
</html>
