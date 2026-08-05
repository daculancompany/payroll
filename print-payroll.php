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
// Both were interpolated raw into the item query below — ?site_id= was a
// straight SQL injection for anyone who could open this page.
$id = (int) $_GET['id'];
$site_id = isset($_GET['site_id']) && $_GET['site_id'] !== '' ? (int) $_GET['site_id'] : '';

$custom_query = '';
if ($site_id !== '') {
    $custom_query = "AND a.site_id = $site_id ";
}


$site_query = "SELECT * FROM sites WHERE id = ?";
$stmt_site = $conn->prepare($site_query);
$stmt_site->bind_param("i", $site_id);
$stmt_site->execute();
$result_site = $stmt_site->get_result();
$site_details = $result_site->fetch_assoc();


?>

<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=Edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <meta name="description" content="COMC Payroll">
    <meta name="author" content="design by: Niel Daculan">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <!-- VENDOR CSS -->
    <!-- <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css"> -->
    <style>
        body {
            visibility: hidden;
        }

        @media print {

            /* General Styles */
            body {
                font-family: Arial, sans-serif;
                color: #000;
                background: #fff;
                visibility: visible;
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
                margin-bottom: 1em;
                font-size: 10px;
            }

            th,
            td {
                border: 1px solid #000;
                padding: 3px;
                text-align: center;
            }

            th {
                background-color: #f2f2f2;
                font-weight: bold;
            }



            /* Page Breaks */
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }

            /* Prevent breaking table rows across pages */
            tbody tr {
                page-break-inside: avoid;
            }
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
            font-size: 12px;
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
            width: 70px;
            /* margin-top: 25px; */
        }

        .text-center {
            text-align: center;
        }

        .flip-text {
            writing-mode: vertical-rl;
        }
    </style>


    <?php
    $query2 = "SELECT payroll.*, employer_name, employee_address FROM payroll INNER JOIN employers ON payroll.employer_id = employers.id WHERE payroll.id = ?";
    $stmt = $conn->prepare($query2);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payroll = $result->fetch_assoc();
    $site_ids = json_decode($payroll['site_ids'], true);
    $site_ids = isset($site_ids) ? $site_ids : [];

    $i = 0;

    $query = $conn->query("SELECT  a.*, f.site_code,f.site_name,f.site_address, e.employee_no, e.lastname, e.firstname, e.middlename, e.basic_pay, d.name as department, p.name as position
FROM payroll_items a 
INNER JOIN employee e ON a.employee_id = e.id 
LEFT JOIN department d ON e.department_id = d.id 
LEFT JOIN position p ON e.position_id = p.id 
LEFT JOIN sites f ON f.id = a.site_id 
        WHERE  a.payroll_id = $id $custom_query ORDER BY lastname ASC
    ");

    // Named one-off items per employee (payroll_item_extras): kind 1 deducts,
    // kind 2 adds. Without these the register's net disagrees with the payslip.
    $ppExtras = [];
    if ($conn->query("SHOW TABLES LIKE 'payroll_item_extras'")->num_rows) {
        $xq = $conn->query("SELECT payroll_item_id, kind, label, amount FROM payroll_item_extras WHERE payroll_id = $id ORDER BY id ASC");
        if ($xq) while ($x = $xq->fetch_assoc()) $ppExtras[(int)$x['payroll_item_id']][] = $x;
    }
    $contributions_settings = json_decode($payroll['settings'], true) ?: [];

    $refunds_settings  = array_filter($contributions_settings, function ($item) {
        return $item["type"] === 4;
    });

    $contributions_settings = array_filter($contributions_settings, function ($item) {
        return $item["type"] !== 4;
    });

    $contributions_settings = array_values($contributions_settings);

    $payroll_type = $payroll['type'];
    ?>
    <title>PAYROLL PERIOD-<?php
                            $date = strtotime($payroll['date_from']);
                            $formattedDate = date("F d", $date);
                            echo $formattedDate;
                            ?>
        - <?php
            $date = strtotime($payroll['date_to']);
            $formattedDate = date("F j, Y", $date);
            echo $formattedDate;
            ?></title>

<body>
    <div class="container-fluid">
        <div id="print-area">
            <?php if ($site_id === '') { ?>
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
            <?php } ?>

            <?php if ($site_id !== '') { ?>
                <br></br>
                <div class="company-wrapper">
                    <div class="name">Name of Company: </div>
                    <div class="details"><?= $payroll['employer_name'] ?></div>
                </div>
                <div class="company-wrapper">
                    <div class="name">Address: </div>
                    <div class="details"><?= $payroll['employee_address'] ?></div>
                </div>
                <div class="company-wrapper">
                    <div class="name">Name of Project Site: </div>
                    <div class="details"><?= $site_details['site_name'] ?>, <?= $site_details['site_address'] ?></div>
                </div>
                <div class="company-wrapper">
                    <div class="name">Payroll Period: </div>
                    <div class="details"><strong>
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
                        </strong></div>
                </div>
                <div class="company-wrapper">
                    <div class="name">Remarks: </div>
                    <div class="details"></div>
                </div>

            <?php } ?>
            <table cellspacing="0" id="table-1" class="table table-sm table-bordered table-striped">
                <thead>
                    <tr>
                        <th rowspan="2" class="text-center primary-header">No.</th>
                        <th rowspan="2" class="text-center primary-header">Name</th>
                        <th rowspan="2" class="text-center primary-header">Position</th>
                        <th rowspan="2" class="text-center   flip-text">
                            <div class="flip-text">No.</div>
                            <div class="flip-text">of Days</div>
                        </th>
                        <th rowspan="2" class="text-center  primary-header">Basic Rate</th>
                        <th rowspan="2" class="text-center   flip-text">
                            <div class="flip-text">Total</div>
                            <div class="flip-text">Basic Rate</div>
                        </th>
                        <th colspan="3" class="text-center  info-header">Allowance</th>
                        <th colspan="3" class="text-center info-header">Overtime</th>
                        <th colspan="2" class="text-center info-header">Night Diff</th>
                        <th colspan="3" class="text-center info-header">Late</th>
                        <th rowspan="2" class="text-center success-header">GROSS SALARY</th>
                        <th colspan="<?= count($contributions_settings) + 5 ?>" class="text-center danger-header">Deduction</th>
                        <th rowspan="2" class="text-center danger-header">Total Deduction</th>
                        <?php if (count($refunds_settings) > 0) { ?>
                            <th colspan="<?= count($refunds_settings) ?>" class="text-center primary-header">Refunds</th>
                        <?php } ?>
                        <th rowspan="2" class="text-center info-header">Adjustment</th>
                        <th rowspan="2" class="text-center success-header">Net Pay</th>
                        <th rowspan="2" class="text-center  primary-header" style="width: 130px;">Signature</th>
                        <th rowspan="2" class="text-center  primary-header">No.</th>
                    </tr>
                    <tr>
                        <th class="text-center  info-header">No. dys</th>
                        <th class="text-center  info-header">Rate</th>
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
                        <th class="text-center  danger-header">SSS Provident Fund</th>
                        <th class="text-center  danger-header">JEI Advance</th>
                        <th class="text-center  danger-header">JCC Advances</th>
                        <th class="text-center  danger-header">Tax</th>
                        <th class="text-center  danger-header">Other Deduction</th>
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
                        // Minutes in this employee's working day (their shift length,
                        // frozen on the payroll item at calc time), not a fixed 8h.
                        $minutesPerDay = day_hours_or_default($row['day_hours'] ?? null) * 60;
                        $perMinute = $row['per_day'] / $minutesPerDay;
                        $employee_id = $row['employee_id'];
                        $total_basic_rate = $row['present'] * $row['per_day'];
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
                        // Daily rate → the +30% premium only (the worked rest day is already
                        // inside `present`); monthly/fixed → the full extra day. Flat
                        // `× per_day` here printed a rest-day figure gross never contained.
                        $__rt = $row['rate_type'] ?? 'daily';
                        $sunday_duty_amount = ($__rt === 'monthly' || $__rt === 'fixed')
                            ? $sunday_duty * $perDay
                            : rest_day_premium($sunday_duty, $perDay);
                        $special_holiday = $row['special_holiday'];
                        // /8 * 2.4 is the 30% special-holiday premium (= * 0.3), NOT a day-length divisor.
                        $special_holiday_amount =  (($perDay / 8) * 2.4) *  $special_holiday;
                        // Night differential — hours from the DTR, amount priced at calc
                        // time; part of gross (mirrors the workbench and view_payslip).
                        $nsd_hours  = (float)($row['nsd_hours'] ?? 0);
                        $nsd_amount = (float)($row['nsd_amount'] ?? 0);
                        $t_nsd_hrs  = ($t_nsd_hrs ?? 0) + $nsd_hours;
                        $t_nsd_amt  = ($t_nsd_amt ?? 0) + $nsd_amount;

                        // The ONE shared formula. This sheet used to halve basic, allowance
                        // AND absences regardless of rate_type, and its gross dropped
                        // holiday, rest-day and undertime — so the printed payroll and the
                        // payslip generated from the same row disagreed.
                        $__e              = payroll_earnings($row);
                        $total_basic_rate = $__e['basic'];
                        $total_amount     = $__e['subtotal'];
                        $t_total_amount  += $total_amount;
                        $gross_salary     = $__e['gross'];

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
                            <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>
                            <td style="min-width:130px;">
                                <b><?= $row['lastname'] ?>, <?= $row['firstname'] ?></b>
                            </td>
                            <td style="min-width: 90px;" class="text-center">
                                <?= $row['position'] ?>
                            </td>
                            <td class="text-center">
                                <?= $row['present'] ?>
                            </td>
                            <td class="text-right">
                                <?= number_format($row['per_day'], 2) ?>
                            </td>
                            <td class="text-right">
                                <?= number_format($total_basic_rate, 2) ?>
                            </td>
                            <!-- Late -->
                            <td class="text-center">
                                <?= $row['allowance_days'] ?>
                            </td>
                            <td class="text-right">
                                <b><?= number_format($allowance_amount, 2) ?></b>
                            </td>
                            <td class="text-right">
                                <b><?= number_format($total_allowance, 2) ?></b>
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
                            <!-- Fixed deductions + Other Deduction — mirror payroll_calculations.php -->
                            <?php
                            $other_deduction = (float) ($row['other_deduction'] ?? 0);
                            $total_deductions += $sss_fund + $jei_advances + $jcc_advances + $tax + $other_deduction;
                            $t_sss_fund = ($t_sss_fund ?? 0) + $sss_fund;
                            $t_jei = ($t_jei ?? 0) + $jei_advances;
                            $t_jcc = ($t_jcc ?? 0) + $jcc_advances;
                            $t_tax = ($t_tax ?? 0) + $tax;
                            $t_other_ded = ($t_other_ded ?? 0) + $other_deduction;
                            ?>
                            <td class="text-right"><?= number_format($sss_fund, 2) ?></td>
                            <td class="text-right"><?= number_format($jei_advances, 2) ?></td>
                            <td class="text-right"><?= number_format($jcc_advances, 2) ?></td>
                            <td class="text-right"><?= number_format($tax, 2) ?></td>
                            <td class="text-right"><?= number_format($other_deduction, 2) ?></td>
                            <?php $t_deduction += $total_deductions;  ?>
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
                                    <td class="text-right">
                                        <?= number_format($refund_amount, 2) ?>
                                    </td>
                            <?php
                                }
                            } ?>
                            <!-- Adjustment — signed manual correction added to net -->
                            <?php
                            $adjustment  = (float) ($row['adjustment'] ?? 0);
                            $adj_remarks = trim((string) ($row['adjustment_remarks'] ?? ''));
                            $t_adjust = ($t_adjust ?? 0) + $adjustment;
                            ?>
                            <td class="text-right">
                                <?= number_format($adjustment, 2) ?>
                                <?php if ($adj_remarks !== ''): ?><div style="font-size:6px;color:#666;"><?= htmlspecialchars($adj_remarks) ?></div><?php endif; ?>
                            </td>
                            <?php

                            // Fold this employee's one-off items into the printed figures.
                            $rowX = $ppExtras[(int)$row['id']] ?? [];
                            $xAdd = $xLess = 0;
                            foreach ($rowX as $x) { if ((int)$x['kind'] === 2) $xAdd += (float)$x['amount']; else $xLess += (float)$x['amount']; }
                            $gross_salary     += $xAdd;
                            $total_deductions += $xLess;
                            $net = $gross_salary -  $total_deductions + $total_refunds + $adjustment;
                            $t_net += $net;
                            ?>
                            <td class="text-right net-content">
                                <b><?= number_format($net, 2) ?></b>
                            </td>
                            <td></td>
                            <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>

                        </tr>
                        <input style="display: none;" name="id[]" value="<?= $row['id'] ?>" />
                        <input style="display: none;" name="net[]" value="<?= $net ?>" />
                    <?php } ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th></th>
                        <th colspan="2" class="text-center">TOTAL AMOUNT</th>
                        <th class="text-center"><?= $total_number_days ?></th>
                        <th class="text-right"><?= number_format($t_per_day, 2) ?></th>
                        <th class="text-right"><?= number_format($t_basic_rate, 2) ?></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th class="text-center"><?= number_format($t_nsd_hrs ?? 0, 2) ?></th>
                        <th class="text-right"><?= number_format($t_nsd_amt ?? 0, 2) ?></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th class="text-right"><?= number_format($t_gross, 2) ?></th>
                        <th colspan="<?= count($contributions_settings) ?>"></th>
                        <th class="text-right"><?= number_format($t_sss_fund ?? 0, 2) ?></th>
                        <th class="text-right"><?= number_format($t_jei ?? 0, 2) ?></th>
                        <th class="text-right"><?= number_format($t_jcc ?? 0, 2) ?></th>
                        <th class="text-right"><?= number_format($t_tax ?? 0, 2) ?></th>
                        <th class="text-right"><?= number_format($t_other_ded ?? 0, 2) ?></th>
                        <th class="text-right"><?= number_format($t_deduction, 2) ?></th>
                        <th colspan="<?= count($refunds_settings) ?>"></th>
                        <th class="text-right"><?= number_format($t_adjust ?? 0, 2) ?></th>
                        <th class="text-right"><?= number_format($t_net, 2) ?></th>
                        <th></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>

        </div>
</body>
<!-- https://chatgpt.com/c/67d02b73-2db0-800f-a4c5-1abb2c64ecf8
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js" integrity="sha256-xNzN2a4ltkB44Mc/Jz3pT4iU1cmeR0FkXs4pru/JxaQ=" crossorigin="anonymous"></script>
<script src="xlsx.full.min.js"></script> -->

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
    //     let ws = wb.Sheets["Sheet1"];

    //     // Set column widths (Adjust if necessary)
    //     ws['!cols'] = [{
    //             wch: 15
    //         }, // Column A
    //         {
    //             wch: 12
    //         }, // Column B
    //         {
    //             wch: 15
    //         }, // Column C
    //         {
    //             wch: 12
    //         }, // Column D
    //         {
    //             wch: 12
    //         }, // Column E (Index 4)
    //         {
    //             wch: 15
    //         } // Column F (Index 5)
    //     ];

    //     // Get worksheet range
    //     let range = XLSX.utils.decode_range(ws["!ref"]);

    //     // Loop through each cell and apply styles
    //     for (let R = range.s.r; R <= range.e.r; ++R) {
    //         for (let C = range.s.c; C <= range.e.c; ++C) {
    //             let cellAddress = XLSX.utils.encode_cell({
    //                 r: R,
    //                 c: C
    //             });
    //             if (!ws[cellAddress]) continue; // Skip empty cells

    //             let cell = ws[cellAddress];

    //             // Apply default styling
    //             cell.s = {
    //                 font: {
    //                     bold: R === 0, // Bold for header row
    //                     color: {
    //                         rgb: R === 0 ? "FFFFFF" : "000000"
    //                     } // White for headers, black for others
    //                 },
    //                 fill: R === 0 ? {
    //                     fgColor: {
    //                         rgb: "4F81BD"
    //                     }
    //                 } : {
    //                     fgColor: {
    //                         rgb: "FFFFFF"
    //                     }
    //                 }, // Blue header, white body
    //                 alignment: {
    //                     horizontal: (C === 4 || C === 5) ? "right" : "left", // Right-align columns 5 & 6, left-align others
    //                     vertical: "center"
    //                 },
    //                 border: {
    //                     top: {
    //                         style: "thin",
    //                         color: {
    //                             rgb: "000000"
    //                         }
    //                     },
    //                     bottom: {
    //                         style: "thin",
    //                         color: {
    //                             rgb: "000000"
    //                         }
    //                     },
    //                     left: {
    //                         style: "thin",
    //                         color: {
    //                             rgb: "000000"
    //                         }
    //                     },
    //                     right: {
    //                         style: "thin",
    //                         color: {
    //                             rgb: "000000"
    //                         }
    //                     }
    //                 }
    //             };

    //             // Convert columns 5 & 6 (Indexes 4 & 5) to number format with comma
    //             if ((C === 4 || C === 5) && R !== 0) { // Skip header row
    //                 let rawValue = parseFloat(cell.v);
    //                 if (!isNaN(rawValue)) {
    //                     cell.v = rawValue; // Set the numeric value
    //                     cell.t = "n"; // Mark cell as a number
    //                     cell.z = "#,##0.00"; // Format with comma and 2 decimal places
    //                 }
    //             }
    //         }
    //     }

    //     // Export file
    //     XLSX.writeFile(wb, filename);
    // }



    // $(document).ready(function() {
    //     exportTableToExcel("table-1");
    // });
</script>

</html>