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
        SUM(COALESCE(pi.adjustment, 0))                               AS total_adjustment,
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

$g = ['emp'=>0,'basic'=>0,'allow'=>0,'absent'=>0,'amount'=>0,'ot'=>0,'legal'=>0,'sunday'=>0,'special'=>0,'late'=>0,'gross'=>0,'contribs'=>0,'other_ded'=>0,'tax'=>0,'jei'=>0,'jcc'=>0,'sss'=>0,'adjustment'=>0,'net'=>0];
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
    $g['adjustment'] += $r['total_adjustment'];
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
* { box-sizing:border-box; margin:0; padding:0; }
@page { size: legal landscape; margin: 8mm 10mm; }
body { font-family: Arial, sans-serif; color: #000; background: #fff; }

.top { text-align: center; font-size: 12px; }
.top > div { display: inline-block; vertical-align: middle; text-align: center; }
.logo-area { width: 70px; }
.text-center { text-align: center; }

table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9px; }
th, td { border: 1px solid #000; padding: 3px; text-align: center; }
th { background-color: #f2f2f2; font-weight: bold; text-transform: uppercase; }
td.dept { font-weight: bold; text-align: left; text-transform: uppercase; }
td.r { text-align: right; }
tfoot th { text-align: right; }
tfoot th.c { text-align: center; }
tfoot th.l { text-align: left; }

tr { page-break-inside: avoid; }
thead { display: table-header-group; }

.sigs { display: table; width: 100%; margin-top: 40px; font-size: 11px; }
.sig-block { display: table-cell; padding: 0 10px; }
.sig-block p { line-height: 1; margin: 4px 0; }
.sig-inner { margin-left: 20px; }
</style>
</head>
<body>

  <div class="top">
    <div class="logo-area">
      <img style="width: 60px;" src="assets2/images/logo.jpeg" alt="Logo">
    </div>
    <div>
      <div>COMC</div>
      <div>TIU SONS, BUILDING BARANGAY 33, GUILLERMO COGON CAGAYAN DE ORO CITY</div>
      <div class="text-center">PAYROLL SUMMARY BY DEPARTMENT</div>
      <div class="text-center">PAYROLL PERIOD:
        <strong><?= date('F d', strtotime($payroll['date_from'])) ?> - <?= date('F j, Y', strtotime($payroll['date_to'])) ?></strong>
      </div>
    </div>
  </div>

  <table cellspacing="0">
    <thead>
      <tr>
        <th rowspan="2" style="text-align:left;">Department</th>
        <th rowspan="2">Emp.</th>
        <th colspan="9">Earnings</th>
        <th colspan="7">Deductions</th>
        <th rowspan="2">Adjustment</th>
        <th rowspan="2">Net Pay</th>
      </tr>
      <tr>
        <th>Basic Pay</th>
        <th>Allowance</th>
        <th>Absent</th>
        <th>Total Amt</th>
        <th>Overtime</th>
        <th>Legal Hol.</th>
        <th>Rest Duty</th>
        <th>Sp. Hol.</th>
        <th>Late</th>
        <th>Gross</th>
        <th>Contribs.</th>
        <th>SSS Fund</th>
        <th>Tax</th>
        <th>JEI Adv.</th>
        <th>JCC Adv.</th>
        <th>Other Ded.</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($dept_rows as $r): ?>
      <tr>
        <td class="dept"><?= htmlspecialchars($r['dept_name']) ?></td>
        <td><?= $r['emp_count'] ?></td>
        <td class="r"><?= n2($r['total_basic']) ?></td>
        <td class="r"><?= n2($r['total_allowance']) ?></td>
        <td class="r">(<?= n2($r['total_absent']) ?>)</td>
        <td class="r"><?= n2($r['total_amount']) ?></td>
        <td class="r"><?= n2($r['total_ot']) ?></td>
        <td class="r"><?= n2($r['total_legal']) ?></td>
        <td class="r"><?= n2($r['total_sunday']) ?></td>
        <td class="r"><?= n2($r['total_special']) ?></td>
        <td class="r">(<?= n2($r['total_late']) ?>)</td>
        <td class="r"><?= n2($r['total_gross']) ?></td>
        <td class="r"><?= n2($r['total_contributions']) ?></td>
        <td class="r"><?= n2($r['total_sss']) ?></td>
        <td class="r"><?= n2($r['total_tax']) ?></td>
        <td class="r"><?= n2($r['total_jei']) ?></td>
        <td class="r"><?= n2($r['total_jcc']) ?></td>
        <td class="r"><?= n2($r['total_other_ded']) ?></td>
        <td class="r"><?= n2($r['total_adjustment']) ?></td>
        <td class="r"><b><?= n2($r['total_net']) ?></b></td>
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
        <th><?= n2($g['other_ded']) ?></th>
        <th><?= n2($g['adjustment']) ?></th>
        <th><?= n2($g['net']) ?></th>
      </tr>
    </tfoot>
  </table>

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
      <div class="sig-inner">
        <p><b>JAY O. VERAS</b></p>
        <p>HR HEAD</p>
      </div>
    </div>
    <div class="sig-block">
      Checked By:
      <div class="sig-inner">
        <p><b>JOVANIE ALAB</b></p>
        <p>ACCOUNTING PAYABLE TEAM LEADER</p>
      </div>
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
