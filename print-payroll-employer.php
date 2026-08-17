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
$id = (int) $_GET['id'];
$type = $_GET['type'] ?? '';
$site_id = isset($_GET['site_id']) && $_GET['site_id'] !== '' ? (int) $_GET['site_id'] : 0;

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
    <title>COMC Payroll</title>
    <?php include __DIR__ . "/includes/favicon.php"; ?>
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
          //  visibility: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1em;
            font-size: 10px;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 8px;
            text-align: center;

        }

        th {
            font-weight: bold;
            text-transform: uppercase;
        }


        @media print {

            /* General Styles */
            body {
                font-family: Arial, sans-serif;
                font-size: 12pt;
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
            }

            th,
            td {
                border: 1px solid #000;
                padding: 8px;
                text-align: center;
            }

            th {
                background-color: #f2f2f2;
                font-weight: bold;
                text-transform: uppercase;
            }

            tr:nth-child(even) {
                background-color: #f9f9f9;
            }

            tr:hover {
                background-color: #f1f1f1;
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
            width: 200px;
            /* margin-top: 25px; */
        }

        .text-center {
            text-align: center;
        }

        p {
            line-height: 1;

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

    $query = $conn->query("SELECT COUNT(employee_id) AS total_employee, a.present, sum(a.under_time) AS under_time, sum(a.late) AS late
            , sum(a.ot_rate) AS ot_rate, sum(a.per_day) AS per_day, sum(a.ot_amount) AS ot_amount
            ,sum(a.ot_rate * a.ot) as overtime_amount , sum(a.ot) AS ot, sum(a.salary) AS salary, sum(a.per_minute) AS per_minute,  sum(a.deduction_amount) AS deduction_amount, sum(a.other_deduction) AS other_deduction, sum(a.net) AS net
            ,a.payroll_id, f.id AS site_id, f.site_code,f.site_name,f.site_address  FROM payroll_items AS a
            LEFT JOIN sites f ON f.id = a.site_id
            WHERE a.payroll_id = $id  GROUP BY a.site_id
    ");


    $contributions_settings = json_decode($payroll['settings'], true);
    $contributions_settings = isset($contributions_settings) ? $contributions_settings : [];
    ?>

<body>
    <div class="container-fluid">
        <div id="print-area">
            <?php if ($type === 'all') { ?>
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
            <br></br>
            <table cellspacing="0" id="table-1" class="table table-sm table-bordered table-striped">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Project Code</th>
                        <th>Project Name</th>
                        <th>NO OF WORKERS</th>
                        <th>GROSS SALARY</th>
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
                                <th><?= $name_deduction ?></th>
                        <?php }
                        } ?>
                        <th>Other Deduction</th>
                        <th>Total Deduction</th>
                        <th>Net Pay</th>
                        <th>No.</th>

                    </tr>
                </thead>
                <tbody>
                    <?php

                    $query2 = $conn->query("SELECT deductions, contributions, loans, site_id FROM payroll_items WHERE payroll_id = $id");

                    $groupedBySiteId = [];

                    while ($row2 = $query2->fetch_assoc()) {
                        $siteId = $row2['site_id'];

                        // Decode contributions and deductions
                        $contributions = json_decode($row2['contributions'], true);
                        $deductions = json_decode($row2['deductions'], true);
                        $loans = json_decode($row2['loans'], true);

                        // Initialize arrays for the site ID if not already initialized
                        if (!isset($groupedBySiteId[$siteId])) {
                            $groupedBySiteId[$siteId] = [
                                'total_contributions' => [],
                                'total_deductions' => []
                            ];
                        }

                        // Sum contributions
                        if (is_array($contributions)) {
                            foreach ($contributions as $contribution) {
                                $contributionId = $contribution['contribution_id'];
                                if (!isset($groupedBySiteId[$siteId]['total_contributions'][$contributionId])) {
                                    $groupedBySiteId[$siteId]['total_contributions'][$contributionId] = 0;
                                }
                                if (isset($contribution['amount'])) {
                                    $groupedBySiteId[$siteId]['total_contributions'][$contributionId] += $contribution['amount'];
                                }
                            }
                        }

                        // Sum deductions
                        if (is_array($deductions)) {
                            foreach ($deductions as $deduction) {
                                $deductionId = $deduction['deduction_id'];
                                if (!isset($groupedBySiteId[$siteId]['total_deductions'][$deductionId])) {
                                    $groupedBySiteId[$siteId]['total_deductions'][$deductionId] = 0;
                                }
                                if (isset($deduction['amount'])) {
                                    $groupedBySiteId[$siteId]['total_deductions'][$deductionId] += $deduction['amount'];
                                }
                            }
                        }

                        // Sum loans
                        if (is_array($loans)) {
                            foreach ($loans as $deduction) {
                                $deductionId = $deduction['deduction_id'];
                                if (!isset($groupedBySiteId[$siteId]['total_deductions'][$deductionId])) {
                                    $groupedBySiteId[$siteId]['total_deductions'][$deductionId] = 0;
                                }
                                if (isset($deduction['amount'])) {
                                    $groupedBySiteId[$siteId]['total_deductions'][$deductionId] += $deduction['amount'];
                                }
                            }
                        }
                    }

                    // Calculate the total sums
                    $totalContributionsSum = 0;
                    $totalDeductionsSum = 0;

                    foreach ($groupedBySiteId as $siteId => $data) {
                        $totalSiteContributions = array_sum($data['total_contributions']);
                        $totalSiteDeductions = array_sum($data['total_deductions']);

                        $totalContributionsSum += $totalSiteContributions;
                        $totalDeductionsSum += $totalSiteDeductions;
                    }




                    $total_number_days = 0;
                    $t_basic_rate = 0;
                    $t_per_day = 0;
                    $t_allowance = 0;
                    $t_gross = 0;
                    $total_deductions =  0;
                    $t_deduction = 0;
                    $t_net = 0;
                    while ($row = $query->fetch_assoc()) {
                        $i++;
                        $perMinute = $row['per_minute'];
                        // var_dump($row['present']);
                        // var_dump($row['per_day']);
                        $total_basic_rate = $row['present'] * $row['per_day'];

                        // The ONE shared formula (payroll_earnings, db_connect.php). The
                        // copy here ignored rate_type, allowance, holiday and rest-day pay
                        // and night differential, so the employer's own summary showed a
                        // smaller gross than the payroll it summarises.
                        $__e              = payroll_earnings($row);
                        $total_basic_rate = $__e['basic'];
                        $overtime_amount  = $__e['overtime'];
                        $late_amount      = $__e['late_amt'];
                        $undertime_amount = $__e['under_amt'];
                        $gross_salary     = $__e['gross'];

                        $t_gross += $gross_salary;

                        $deduction = $row['deduction_amount'];
                        $other_deduction = $row['other_deduction'];

                        $total_deduction = $deduction + $other_deduction;

                        $net = $row['net'];
                        $t_net += $net;
                        $site_id = $row['site_id'];
                        $payroll_id = $row['payroll_id']; //site_id = $site_id AND
                        //AND site_id = $site_id 
                        $query2 = $conn->query("SELECT deductions, contributions, site_id FROM payroll_items WHERE payroll_id = $id ");
                        $contributions = [];
                        $deductions = [];

                        while ($row2 = $query2->fetch_assoc()) {
                            $contributions += json_decode($row2['contributions'], true);
                            $deductions = json_decode($row2['deductions'], true);
                        }

                        $total_per_row = 0;

                    ?>
                        <tr>
                            <td class="text-center" style="min-width: 30px;"><b><?= $i ?></b></td>
                            <td><?= $row['site_code'] ?></td>
                            <td style="width: 300px; font-size: 11px"><?= $row['site_name'] ?>, <?= $row['site_address'] ?></td>
                            <td><?= $row['total_employee'] ?></td>
                            <td class="text-right"><?= number_format($gross_salary, 2) ?></td>
                            <?php if (count($contributions_settings) > 0) {

                                foreach ($contributions_settings as $k) {
                                    $deduction_amount = 0;
                                    $diddd = $k['id'];
                                    if ($k['type'] == 1) {
                                        if (isset($groupedBySiteId[$site_id]["total_contributions"][$diddd])) {
                                            $deduction_amount += $groupedBySiteId[$site_id]["total_contributions"][$diddd];
                                        }
                                    } else {
                                        if (isset($groupedBySiteId[$site_id]["total_deductions"][$diddd])) {
                                            $deduction_amount += $groupedBySiteId[$site_id]["total_deductions"][$diddd];
                                        }
                                    }
                                    $total_deductions += $deduction_amount;

                                    $total_per_row += $deduction_amount;
                                    // var_dump( $total_per_row);

                            ?>
                                    <td class="text-right" style="max-width: 100px;"><?= number_format($deduction_amount, 2) ?></td>
                            <?php }
                            } ?>
                            <?php
                            $total_deductions_all = $total_per_row  + $other_deduction;
                            $t_deduction += $total_deductions_all;
                            ?>
                            <td class="text-right"><?= number_format($other_deduction, 2) ?></td>
                            <td class="text-right"><?= number_format($total_deductions_all, 2) ?></td>

                            <td class="text-right"><?= number_format($net, 2) ?></td>
                            <td class="text-center" style="min-width: 30px;"><b><?= $i ?></b></td>

                        </tr>
                    <?php } ?>
                <tfoot>
                    <tr>
                        <th></th>
                        <th colspan="3" class="text-center">TOTAL</th>
                        <th class="text-right"><?= number_format($t_gross, 2) ?></th>
                        <th colspan="<?= count($contributions_settings) + 1 ?>"></th>
                        <th class="text-right"><?= number_format($t_deduction, 2) ?></th>
                        <th class="text-right"><?= number_format($t_net, 2) ?></th>
                        <th></th>
                    </tr>
                </tfoot>
                </tbody>
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
                        <p><b>JOVANIE ALAB</b></p>
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
</body>
<script>
    window.print();
    window.onafterprint = function() {
        window.close();
        history.back();
    };
</script>

</html>