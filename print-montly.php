<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=Edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
<meta name="description" content="COMC Payroll">
<meta name="author" content="design by: Niel Daculan">
<link rel="icon" href="favicon.ico" type="image/x-icon">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script> -->

<?php
include 'db_connect.php';
require_once __DIR__ . "/includes/session_bootstrap.php";
// Reachable directly as well as through pdf-payroll.php, and it had no
// access control: a plain GET rendered a whole payroll. Guarded here too so
// the URL and the PDF entry point can never disagree about who may read it.
if (empty($_SESSION["is_login"])) { http_response_code(403); exit("Not authorized."); }
require_page_access("payroll", "text");

if (!isset($_GET['id'])) {
    return;
}
// Cast: both were interpolated raw into the item query below.
$id = (int) $_GET['id'];


$filter_query = "";
$sid = 0;
if (isset($_GET['site_id'])  && $_GET['site_id'] !== 'all' && $_GET['site_id'] !== '') {
    $sid = (int) $_GET['site_id'];
    $filter_query = " AND a.site_id = $sid ";
}
// LEFT JOIN payroll g ON g.id = a.payroll_id 
// LEFT JOIN employers  h ON g.employer_id = h.id

$query = "SELECT  employer_name, category,  clusters.cluster FROM payroll  
        LEFT JOIN employers  ON payroll.employer_id = employers.id  
        LEFT JOIN clusters  ON clusters.id = payroll.category 
        WHERE payroll.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$payroll_r = $result->fetch_assoc();

?>
<?php
$query2 = "SELECT * FROM payroll   WHERE id = ?";
$stmt = $conn->prepare($query2);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$payroll = $result->fetch_assoc();
$site_ids = json_decode($payroll['site_ids'], true);
$site_ids = isset($site_ids) ? $site_ids : [];
$commaSeparatedSites = implode(',', $site_ids);
$status = $payroll['status'];

$i = 0;
$query = $conn->query("SELECT  a.*, f.site_code,f.site_name,f.site_address, e.employee_no, e.lastname, e.firstname, e.middlename, e.basic_pay, d.name as department, p.name as position
FROM payroll_items a 
INNER JOIN employee e ON a.employee_id = e.id 
LEFT JOIN department d ON e.department_id = d.id 
LEFT JOIN position p ON e.position_id = p.id 
LEFT JOIN sites f ON f.id = a.site_id 
WHERE  a.payroll_id = $id $filter_query  ORDER BY lastname ASC ");

// Columns follow Payroll Settings exactly as payroll_calculations.php does.
$settings_split         = payroll_settings_split($payroll['settings']);
$contributions_settings = $settings_split['deds'];
$refunds_settings       = $settings_split['refunds'];
$fixed_keys             = array_keys(PAYROLL_FIXED_DEDS);   // always-on fixed columns (Tax)
$allow_settings         = $settings_split['allow'];
$allow_names            = payroll_allowance_column_names($conn, $allow_settings);
$t_allow_type           = [];
$t_allow_oneoff         = 0;   // other one-time earnings (not backpay)
$t_backpay              = 0;
$t_fixed                = [];

// Named one-off items per employee (payroll_item_extras): kind 1 deducts,
// kind 2 adds. Shown in the One-off allowance column and folded into the
// printed gross / net so this sheet agrees with the payslip.
$ppExtras = [];
if ($conn->query("SHOW TABLES LIKE 'payroll_item_extras'")->num_rows) {
    $xq = $conn->query("SELECT payroll_item_id, kind, label, amount FROM payroll_item_extras WHERE payroll_id = $id ORDER BY id ASC");
    if ($xq) while ($x = $xq->fetch_assoc()) $ppExtras[(int)$x['payroll_item_id']][] = $x;
}



$payroll_type = $payroll['type'];

?>
<title>Payroll <?php
                $dateFrom = date("F d", strtotime($payroll['date_from']));
                $dateTo = date("F j, Y", strtotime($payroll['date_to']));
                echo $dateFrom . " - " . $dateTo;
                ?>l</title>
<?php include __DIR__ . "/includes/favicon.php"; ?>

<body>
    <style>
        /* General Styles */
        body {
            font-family: Arial, sans-serif;
            color: #000;
            background: #fff;
            visibility: visible;
            overflow-x: visible;

        }



        /* Hide unnecessary elements */
        .no-print,
        .no-print * {
            display: none !important;
        }

        /* Table Styles */

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 3px;
            text-align: center;

        }

        th {
            text-transform: uppercase;
            width: 80px;
        }

        .text-right {
            text-align: right;
        }

        .company-wrapper {
            font-weight: bold;
            line-height: 2;
        }

        .company-wrapper .name,
        .company-wrapper .details {
            display: inline-block;
            vertical-align: top;
        }

        .name {
            width: 250px;
        }

        .top {
            text-align: center;
        }

        .top > div {
            display: inline-block;
            vertical-align: middle;
            text-align: center;
        }

        .top h1,
        .top b {
            line-height: 0;
        }

        .logo-area {
            width: 80px;
            /* margin-top: 25px; */
        }

        .text-center {
            text-align: center;
        }

        p {
            line-height: 1;

        }

        .flip-text {
            writing-mode: vertical-rl;
        }
    </style>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <div id="print-area">

                    <div class="top">
                        <div class="logo-area">
                            <img style="width: 60px;" src="assets2/images/logo.jpeg" alt="Logo">
                        </div>
                        <div>
                            <div>COMC</div>
                            <div>TIU SONS, BUILDING BARANGAY 33, GUILLERMO COGON CAGAYAN DE ORO CITY</h4>
                            </div>
                            <div class="text-center">PAYROLL PERIOD:
                                <strong>
                                    <?php
                                    $date = strtotime($payroll['date_from']);
                                    $formattedDate = date("F d", $date);
                                    echo $formattedDate;
                                    ?>
                                    - <?php
                                        $date = strtotime($payroll['date_to']);
                                        $formattedDate = date("F j, Y", $date);
                                        echo $formattedDate;
                                        ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                    <br></br>
                    <!-- <button id="exportBtn">Export to Excel</button> -->
                    <table cellspacing="0" id="table-1" class="table">
                        <thead>
                            <tr>
                                <th rowspan="2" class="text-center primary-header">No.</th>
                                <th rowspan="2" class="text-center primary-header">Name</th>
                                <th rowspan="2" class="text-center primary-header">Position</th>
                                <th rowspan="2" class="text-center   flip-text">
                                    <div class="flip-text">Monthly</div>
                                    <div class="flip-text">Basic Pay</div>
                                </th>
                                <th rowspan="2" class="text-center   flip-text">
                                    <div class="flip-text">Quinsina</div>
                                    <div class="flip-text">Pay</div>
                                </th>
                                <th colspan="<?= 4 + count($allow_settings) ?>" class="text-center  primary-header">Allowance</th>
                                <!-- <th rowspan="2" class="text-center  primary-header">Allowance Quinsina</th> -->
                                <th rowspan="2" class="text-center   flip-text">
                                    <div class="flip-text">Basic</div>
                                    <div class="flip-text">Daily Rate</div>
                                </th>
                                <!-- <th rowspan="2" class="text-center  primary-header">Allowance Daily Rate</th> -->
                                <th rowspan="2" class="text-center  primary-header flip-text">
                                    <div class="flip-text">No. of</div>
                                    <div class="flip-text">Duty</div>
                                </th>
                                <th rowspan="2" class="text-center  primary-header">Absences</th>
                                <th rowspan="2" class="text-center   flip-text">
                                    <div class="flip-text">Amount</div>
                                    <div class="flip-text">Absences</div>
                                </th>
                                <th rowspan="2" class="text-center  success-header">Total Amount</th>
                                <th rowspan="2" class="text-center   flip-text">
                                    <div class="flip-text">Legal</div>
                                    <div class="flip-text">Holiday</div>
                                </th>
                                <th rowspan="2" class="text-center  primary-header">Amount</th>
                                <th rowspan="2" class="text-center   flip-text">
                                    <div class="flip-text">Rest Day</div>
                                    <div class="flip-text">Duty</div>
                                </th>
                                <th rowspan="2" class="text-center  primary-header">Amount</th>
                                <th rowspan="2" class="text-center   flip-text">
                                    <div class="flip-text">Special</div>
                                    <div class="flip-text">Holiday</div>
                                </th>
                                <th rowspan="2" class="text-center  primary-header">Amount</th>
                                <th colspan="3" class="text-center info-header">Overtime</th>

                                <th colspan="2" class="text-center info-header">Night Diff</th>

                                <th colspan="3" class="text-center info-header">Late</th>
                                <th rowspan="2" class="text-center success-header">Backpay</th>
                                <th rowspan="2" class="text-center success-header">Total Gross PAY</th>
                                <th colspan="<?= count($contributions_settings) + 1 + count($fixed_keys) ?>" class="text-center danger-header">Deduction</th>
                                <th rowspan="2" class="text-center danger-header">Total Deduction</th>
                                <?php if (count($refunds_settings) > 0) { ?>
                                    <th colspan="<?= count($refunds_settings) ?>" class="text-center primary-header">Refunds</th>
                                <?php } ?>
                                <th rowspan="2" class="text-center success-header">Net Pay</th>
                                <th rowspan="2" class="text-center  primary-header">No.</th>
                            </tr>
                            <tr>
                                <th class="text-center  info-header">No. dys</th>
                                <th class="text-center  info-header">Rate</th>
                                <?php foreach ($allow_settings as $aid): ?>
                                    <th class="text-center  info-header"><?= htmlspecialchars($allow_names[$aid]) ?></th>
                                <?php endforeach; ?>
                                <th class="text-center  info-header">Other Earnings</th>
                                <th class="text-center  info-header">Amount</th>

                                <th class="text-center  info-header">No. hr</th>
                                <th class="text-center  info-header">Rate</th>
                                <th class="text-center  info-header">Amount</th>

                                <th class="text-center  info-header">ND Hrs</th>
                                <th class="text-center  info-header">ND Amount</th>

                                <th class="text-center  info-header">Min</th>
                                <th class="text-center  info-header">Rate</th>
                                <th class="text-center  info-header">Amount</th>

                                <?php if (count($contributions_settings) > 0) {
                                    foreach ($contributions_settings as $k) {
                                        if ($k['type'] == 1) {
                                            $query_con = "SELECT * FROM contributions   WHERE id = ?";
                                            $stmt_con = $conn->prepare($query_con);
                                            $stmt_con->bind_param("i", $k['id']);
                                            $stmt_con->execute();
                                            $result_con = $stmt_con->get_result();
                                            $contribution = $result_con->fetch_assoc();
                                            $name_deduction = $contribution['contribution'];
                                        } else if ($k['type'] == 3) {
                                            $query_con = "SELECT * FROM contribution_loan_types   WHERE clt_id = ?";
                                            $stmt_con = $conn->prepare($query_con);
                                            $stmt_con->bind_param("i", $k['id']);
                                            $stmt_con->execute();
                                            $result_con = $stmt_con->get_result();
                                            $contribution = $result_con->fetch_assoc();
                                            $name_deduction =  $contribution['loan_type'];
                                        } else if ($k['type'] == 2) {
                                            $query_con = "SELECT * FROM deductions   WHERE id = ?";
                                            $stmt_con = $conn->prepare($query_con);
                                            $stmt_con->bind_param("i", $k['id']);
                                            $stmt_con->execute();
                                            $result_con = $stmt_con->get_result();
                                            $contribution = $result_con->fetch_assoc();
                                            $name_deduction =  $contribution['deduction'];
                                        }


                                ?>
                                        <th class="text-center danger-header"><?= $name_deduction ?></th>
                                    <?php } ?>
                                <?php } else { ?>

                                <?php } ?>
                                <th class="text-center  danger-header">OTHER DEDUCTIONS</th>
                                <?php foreach ($fixed_keys as $fk): ?>
                                    <th class="text-center  danger-header"><?= htmlspecialchars(strtoupper(PAYROLL_FIXED_DEDS[$fk])) ?></th>
                                <?php endforeach; ?>
                                <?php if (count($refunds_settings) > 0) {
                                    foreach ($refunds_settings as $k) {
                                        $query_con = "SELECT * FROM refunds   WHERE id = ?";
                                        $stmt_con = $conn->prepare($query_con);
                                        $stmt_con->bind_param("i", $k['id']);
                                        $stmt_con->execute();
                                        $result_con = $stmt_con->get_result();
                                        $rfund = $result_con->fetch_assoc();
                                        $name_refunds =  $rfund['refunds'];

                                ?>
                                        <th class="text-center success-header"><?= $name_refunds ?></th>
                                <?php }
                                } ?>

                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total_number_days = 0;
                            $t_basic_rate = 0;
                            $t_per_day = 0;
                            $t_allowance = 0;
                            $t_gross = 0;
                            $t_deduction = 0;
                            $t_net = 0;
                            $t_perDay = 0;
                            $t_total_amount = 0;
                            $t_ot_rate = 0;
                            $t_late = 0;

                            while ($row = $query->fetch_assoc()) {
                                $i++;
                                // Minutes in this employee's working day (their shift
                                // length, frozen on the item at calc), not a fixed 8h.
                                $minutesPerDay = day_hours_or_default($row['day_hours'] ?? null) * 60;
                                $perMinute = $row['per_day'] / $minutesPerDay;
                                $employee_id = $row['employee_id'];
                                $total_basic_rate = $row['basic_pay'];
                                $overtime_amount = $row['ot'] * $row['ot_rate'];
                                $t_ot_rate += $row['ot_rate'];
                                $late_amount = $row['late'] * $perMinute;
                                $t_late += $perMinute;
                                $undertime_amount = $row['under_time'] * $perMinute;
                                $allowance_amount = $row['allowance_amount'];
                                $allowance_days = $row['allowance_days'];
                                $total_allowance = $allowance_amount *  $allowance_days;
                                $tax = $row['tax'];
                                $absent_amount = $row['absent'] *  $row['per_day'];
                                $jei_advances = $row['jei_advances'];
                                $jcc_advances = $row['jcc_advances'];
                                $sss_fund = $row['sss_fund'];
                                $perDay = $row['per_day'];
                                $t_perDay += $perDay;
                                $legal_holiday = $row['legal_holiday'];
                                $legal_holiday_amount =  $legal_holiday * $perDay;
                                $sunday_duty = $row['sunday_duty'];
                                $sunday_duty_amount =  $sunday_duty * $perDay;
                                $special_holiday = $row['special_holiday'];
                                // /8 * 2.4 is the 30% special-holiday premium (= * 0.3), NOT a day-length divisor.
                                $special_holiday_amount =  (($perDay / 8) * 2.4) *  $special_holiday;
                                // Night differential — part of gross (mirrors the workbench and view_payslip).
                                $nsd_hours  = (float)($row['nsd_hours'] ?? 0);
                                $nsd_amount = (float)($row['nsd_amount'] ?? 0);
                                $t_nsd_hrs  = ($t_nsd_hrs ?? 0) + $nsd_hours;
                                $t_nsd_amt  = ($t_nsd_amt ?? 0) + $nsd_amount;

                                // The ONE shared formula (payroll_earnings, db_connect.php).
                                // Was: basic, allowance and absences all halved regardless of
                                // rate_type, and undertime never deducted.
                                $__e = payroll_earnings($row);
                                $total_amount =  $__e['subtotal'];
                                $t_total_amount += $total_amount;
                                $gross_salary =  $__e['gross'];
                                // This employee's one-off items: allowances add to gross here,
                                // deductions join the other deductions before net (as print-payroll.php does).
                                $rowX = $ppExtras[(int)$row['id']] ?? [];
                                // Backpay (a one-off earning) gets its own column; other earnings share one.
                                $xAdd = $xLess = $xBack = 0;
                                foreach ($rowX as $x) {
                                    if ((int)$x['kind'] !== 2) { $xLess += (float)$x['amount']; continue; }
                                    if (payroll_is_backpay_label($x['label'])) $xBack += (float)$x['amount']; else $xAdd += (float)$x['amount'];
                                }
                                $gross_salary += $xAdd + $xBack;
                                $t_backpay += $xBack;

                                $contributions = json_decode($row['contributions'], true);
                                $deductions = json_decode($row['deductions'], true);
                                $loans = json_decode($row['loans'], true);
                                $refunds = json_decode($row['refunds'], true);
                                $total_deductions =  0;
                                $total_refunds =  0;



                                $total_number_days += $row['present'];
                                $t_basic_rate += $total_basic_rate;
                                $t_per_day += $perDay;
                                $t_allowance += $allowance_amount;
                                $t_gross += $gross_salary;

                            ?>
                                <tr>
                                    <td class="text-center"><b><?= $i ?></b></td>
                                    <td style="min-width:100px;">
                                        <b><?= $row['lastname'] ?>, <?= $row['firstname'] ?></b>
                                    </td>
                                    <td class="text-center">
                                        <?= $row['position'] ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($row['basic_pay'], 2) ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($row['basic_pay'] / 2, 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $row['allowance_days'] ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($allowance_amount, 2) ?>
                                    </td>
                                    <?php $__aby = payroll_allowance_by_id($row);
                                    foreach ($allow_settings as $aid) {
                                        $__aamt = (float) ($__aby[$aid] ?? 0);
                                        $t_allow_type[$aid] = ($t_allow_type[$aid] ?? 0) + $__aamt; ?>
                                    <td class="text-right"><?= number_format($__aamt, 2) ?></td>
                                    <?php }
                                    // Named one-off allowances on this payslip (folded into Gross above).
                                    $t_allow_oneoff += $xAdd; ?>
                                    <td class="text-right"><?= number_format($xAdd, 2) ?></td>
                                    <td class="text-right">
                                        <?php /* blended: hand-typed days × rate + the configured types — same figure the payroll table shows */ ?>
                                        <?= number_format(payroll_allowance_total($row), 2) ?>
                                    </td>
                                    <!-- Basic Daily Rate -->
                                    <td class="text-right">
                                        <?= number_format($row['per_day'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $row['present'] ?>
                                    </td>

                                    <td class="text-center">
                                        <?= $row['absent'] ?>
                                    </td>

                                    <td class="text-right">
                                        <?= number_format($absent_amount, 2) ?>
                                    </td>
                                    <!-- total amount -->
                                    <td class="text-right">
                                        <?= number_format($total_amount, 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $row['legal_holiday'] ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($legal_holiday_amount, 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $row['sunday_duty'] ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($sunday_duty_amount, 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $row['special_holiday'] ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($special_holiday_amount, 2) ?>
                                    </td>
                                    <!-- ot -->
                                    <td class="text-center">
                                        <?= $row['ot'] ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($row['ot_rate'], 2) ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($overtime_amount, 2) ?>
                                    </td>

                                    <!-- /ot -->

                                    <!-- Night diff -->
                                    <td class="text-center">
                                        <?= number_format($nsd_hours, 2) ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($nsd_amount, 2) ?>
                                    </td>
                                    <!-- /Night diff -->

                                    <!-- Late -->
                                    <td class="text-center">
                                        <?= $row['late'] ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($perMinute, 2) ?>
                                    </td>
                                    <td class="text-right">
                                        <?= number_format($late_amount, 2) ?>
                                    </td>
                                    <!-- /Late -->
                                    <td class="text-right"><?= number_format($xBack, 2) ?></td>
                                    <td class="text-right">
                                        <?= number_format($gross_salary, 2) ?>
                                    </td>
                                    <?php

                                    if (count($contributions_settings) > 0) {
                                        foreach ($contributions_settings as $i2 =>  $k) {
                                            $deduction_amount = 0;
                                            if ($k['type'] == 1) {
                                                foreach ($contributions as $kd) {
                                                    if ($kd["contribution_id"] == $k["id"]) {
                                                        $deduction_amount = $kd["amount"];
                                                    }
                                                }
                                            }
                                            if ($k['type'] == 2) {
                                                foreach ($deductions as $kd) {
                                                    if ($kd["deduction_id"] == $k["id"]) {
                                                        $deduction_amount = $kd["amount"];
                                                    }
                                                }
                                            }
                                            if ($k['type'] == 3) {
                                                foreach ($loans as $kd) {
                                                    if ($kd["deduction_id"] == $k["id"]) {
                                                        $deduction_amount = $kd["amount"];
                                                    }
                                                }
                                            }

                                            $total_deductions += $deduction_amount;


                                    ?>
                                            <td class="text-right">
                                                <?= number_format($deduction_amount, 2) ?>
                                            </td>
                                        <?php } ?>
                                    <?php  } else { ?>

                                    <?php } ?>
                                    <?php
                                    // One-time deductions added on this payslip (payroll_item_extras kind 1).
                                    $total_deductions += $xLess;
                                    $t_other_ded = ($t_other_ded ?? 0) + $xLess; ?>
                                    <td class="text-right"><?= number_format($xLess, 2) ?></td>
                                    <?php
                                    // Fixed columns (Tax).
                                    $fixed_vals = compact('sss_fund', 'jei_advances', 'jcc_advances', 'tax');
                                    foreach ($fixed_keys as $fk) {
                                        $fd_val = (float) $fixed_vals[$fk];
                                        $total_deductions += $fd_val;
                                        $t_fixed[$fk] = ($t_fixed[$fk] ?? 0) + $fd_val; ?>
                                    <td class="text-right"><?= number_format($fd_val, 2) ?></td>
                                    <?php } ?>
                                    <?php $t_deduction += $total_deductions; ?>

                                    <td class="text-right">
                                        <?= number_format($total_deductions, 2) ?>
                                    </td>
                                    <!-- refunds -->
                                    <?php if (count($refunds_settings) > 0) {;
                                        foreach ($refunds_settings as $kd) {
                                            $refund_amount = 0;
                                            foreach ($refunds as $cd) {
                                                if ($cd["refund_id"] == $kd["id"]) {
                                                    $refund_amount = $cd["amount"];
                                                }
                                            }
                                            $total_refunds += $refund_amount;
                                    ?>
                                            <td>
                                                <?= number_format($refund_amount, 2) ?>
                                            </td>
                                    <?php
                                        }
                                    } ?>
                                    <?php

                                    $net = $gross_salary -  $total_deductions + $total_refunds;
                                    $t_net += $net;
                                    ?>
                                    <td class="text-right">
                                        <?= number_format($net, 2) ?>
                                    </td>
                                    <td class="text-center"><b><?= $i ?></b></td>

                                </tr>
                                <input style="display: none;" name="id[]" value="<?= $row['id'] ?>" />
                                <input style="display: none;" name="net[]" value="<?= $net ?>" />
                            <?php } ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th></th>
                                <th colspan="2" class="text-center">SUBTOTAL AMOUNT</th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <?php foreach ($allow_settings as $aid): ?>
                                <th class="text-right"><?= number_format($t_allow_type[$aid] ?? 0, 2) ?></th>
                                <?php endforeach; ?>
                                <th class="text-right"><?= number_format($t_allow_oneoff, 2) ?></th>
                                <th></th>
                                <th class="text-right"><?= number_format($t_perDay, 2) ?></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th class="text-right"><?= number_format($t_total_amount, 2) ?></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th class="text-right"><?= number_format($t_ot_rate, 2) ?></th>

                                <th></th>
                                <th class="text-center"><?= number_format($t_nsd_hrs ?? 0, 2) ?></th>
                                <th class="text-right"><?= number_format($t_nsd_amt ?? 0, 2) ?></th>
                                <th></th>
                                <th class="text-right"><?= number_format($t_late, 2) ?></th>
                                <th></th>
                                <th class="text-right"><?= number_format($t_backpay, 2) ?></th>
                                <th class="text-right"><?= number_format($t_gross, 2) ?></th>
                                <th colspan="<?= count($contributions_settings) ?>"></th>
                                <th class="text-right"><?= number_format($t_other_ded ?? 0, 2) ?></th>
                                <?php foreach ($fixed_keys as $fk): ?>
                                <th class="text-right"><?= number_format($t_fixed[$fk] ?? 0, 2) ?></th>
                                <?php endforeach; ?>
                                <th class="text-right"><?= number_format($t_deduction, 2) ?></th>
                                <th colspan="<?= count($refunds_settings) ?>"></th>
                                <th class="text-right"><?= number_format($t_net, 2) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                    <div style="margin-top: 40px; display: table; width: 100%; font-size: 11px;">
                        <div style="display: table-cell; padding: 0 10px;">
                            Prepared By:
                            <div style="margin-left: 20px;">
                                <p><b><?= $payroll['prepared_by'] ?></b></p>
                                <p><?= $payroll['prepared_by_role'] ?></p>
                            </div>
                        </div>
                        <div style="display: table-cell; padding: 0 10px;">
                            Verified By:
                            <div style="margin-left: 20px;">
                                <p><b><?= $payroll['verified_by'] ?></b></p>
                                <p><?= $payroll['verified_by_role'] ?></p>
                            </div>
                        </div>
                        <div style="display: table-cell; padding: 0 10px;">
                            Noted By:
                            <div style="margin-left: 20px;">
                                <p><b>JAY 0. VERAS</b></p>
                                <p>HR HEAD</p>
                            </div>
                        </div>
                        <div style="display: table-cell; padding: 0 10px;">
                            Checked By:
                            <div style="margin-left: 20px;">
                                <p><b>Jovanie Alab</b></p>
                                <p> ACCOUNTING PAYABLE TEAM LEADER</p>
                            </div>
                        </div>
                        <div style="display: table-cell; padding: 0 10px;">
                            Approved By:
                            <p><b><?= $payroll['approved_by'] ?></b></p>
                            <p><?= $payroll['approved_by_role'] ?></p>
                        </div>
                    </div>


                </div>
            </div>
            <br>

            <br> </br>
            <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

    </div>
</body>
<!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js" integrity="sha256-xNzN2a4ltkB44Mc/Jz3pT4iU1cmeR0FkXs4pru/JxaQ=" crossorigin="anonymous"></script> -->
<!-- <script src="html2pdf.bundle.min.js"></script> -->
<!-- <script src="xlsx.full.min.js"></script> -->
<script>
    window.print();
    window.onafterprint = function() {
        window.close();
        history.back();
    };
    // function exportTableToExcel(tableID, filename = "PAYROLL.xlsx") {
    //     let table = document.getElementById(tableID);
    //     let wb = XLSX.utils.table_to_book(table, {
    //         sheet: "Sheet1"
    //     });

    //     // Access the worksheet
    //     let ws = wb.Sheets["Sheet1"];

    //     // Set custom column widths:
    //     // For example, setting the first column width to 20 characters and the second to 30 characters.
    //     // Set custom column widths
    //     ws['!cols'] = [{
    //         wch: 4
    //     }, {
    //         wch: 20
    //     },{
    //         wch: 20
    //     }];


    //     // // Get the sheet range
    //     // let range = XLSX.utils.decode_range(ws['!ref']);
    //     // let firstRow = range.s.r; // First row index

    //     // for (let C = range.s.c; C <= range.e.c; ++C) {
    //     //     let cell_address = XLSX.utils.encode_cell({
    //     //         r: firstRow,
    //     //         c: C
    //     //     });

    //     //     if (ws[cell_address]) {
    //     //         // Ensure the value is treated as text
    //     //         ws[cell_address].t = "s"; // 's' means string (text)

    //     //         // Apply center alignment
    //     //         ws[cell_address].s = {
    //     //             alignment: {
    //     //                 horizontal: "center",
    //     //                 vertical: "center"
    //     //             }
    //     //         };
    //     //     }
    //     // }

    //     // Export the file
    //     XLSX.writeFile(wb, filename);
    // }

    // // Example: Export when a button is clicked
    // $(document).ready(function() {
    //     $("#exportBtn").click(function() {
    //         exportTableToExcel("table-1");
    //     });
    // });
</script>