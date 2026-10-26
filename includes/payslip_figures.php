<?php
/**
 * Every figure on one printed payslip, computed from the payroll item's live
 * data — earnings via payroll_earnings(), the run's ticked deductions, one-off
 * extras, refunds and the adjustment — with NET = gross − deductions + refunds
 * + adjustment, the same formula as view_payslip.php and the paysheet.
 *
 * Shared by the bulk payslip sheet (view_payslip_bulk.php) and the employee
 * portal, which used to show the STORED payroll_items.net / deduction_amount.
 * Those are saved at Calculate and go stale whenever a deduction, loan, extra
 * or ticked setting changes afterwards, so employees saw a different net from
 * what the bank list paid.
 */
if (!function_exists('buildPayslip')) {
    function buildPayslip($conn, $id) {
        $query = "SELECT a.*,
            l.date_from, l.date_to, l.settings,
            f.site_code, f.site_name, f.site_address,
            e.employee_no, e.lastname, e.firstname, e.middlename, e.basic_pay,
            d.name as department, p.name as position
        FROM payroll_items AS a
        INNER JOIN payroll l ON l.id = a.payroll_id
        INNER JOIN employee e ON a.employee_id = e.id
        LEFT JOIN department d ON e.department_id = d.id
        LEFT JOIN position p ON e.position_id = p.id
        LEFT JOIN sites f ON f.id = a.site_id
        WHERE a.id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        if (!$p) return null;

        $settings = json_decode($p['settings'], true) ?: [];
        // Per-minute = daily rate / (day hours × 60m), the day length being the
        // employee's shift frozen on the item — matches the payroll page and
        // view_payslip.php (NOT the stored per_minute column, which is /1440).
        $dayHours         = day_hours_or_default($p['day_hours'] ?? null);
        $perMinute        = $p['per_day'] / ($dayHours * 60);
        // The ONE shared formula (payroll_earnings, db_connect.php). The copy that
        // lived here halved absences and the allowance on the monthly basis, and its
        // daily branch dropped holiday, rest-day and night-differential pay — so a
        // bulk-printed payslip could show a different gross than the same employee's
        // individually printed one.
        $__e = payroll_earnings($p);
        $overtime_amount  = $__e['overtime'];
        $undertime_amount = $__e['under_amt'];
        $late_amount      = $__e['late_amt'];
        $absent_amount    = $__e['absent_amt'];
        $allowance_amount = $__e['allowance'];
        $legal_amt        = $__e['legal_amt'];
        $sunday_amt       = $__e['rest_amt'];
        $special_amt      = $__e['special_amt'];
        $rate_type        = $p['rate_type'] ?? 'daily';
        $is_monthly       = $__e['is_monthly'];
        $total_basic_rate = $__e['is_monthly'] ? $p['basic_pay'] : $__e['basic'];
        $gross            = $__e['gross'];

        $contributions_list = []; $deductions_list = []; $loans_list = [];
        $total_cont = 0; $total_ded = 0; $total_loan = 0;
        $contrib_raw = json_decode($p['contributions'], true) ?: [];
        $deduct_raw  = json_decode($p['deductions'],    true) ?: [];
        require_once __DIR__ . '/deductions_withheld.php';
        $ded_notes   = dw_ded_notes($conn, $deduct_raw);   // descriptions per deduction type
        $loans_raw   = json_decode($p['loans'],         true) ?: [];

        foreach ($settings as $k) {
            $amt = 0;
            if ($k['type'] == 1) {
                $r = $conn->query("SELECT contribution FROM contributions WHERE id={$k['id']}")->fetch_assoc();
                $name = $r['contribution'] ?? '—';
                foreach ($contrib_raw as $kd) { if ($kd['contribution_id'] == $k['id']) $amt += (float) $kd['amount']; }
                $contributions_list[] = ['name' => $name, 'amount' => $amt];
                $total_cont += $amt;
            } elseif ($k['type'] == 2) {
                $r = $conn->query("SELECT deduction FROM deductions WHERE id={$k['id']}")->fetch_assoc();
                $name = $r['deduction'] ?? '—';
                foreach ($deduct_raw as $kd) { if ($kd['deduction_id'] == $k['id']) $amt += (float) $kd['amount']; }
                $deductions_list[] = ['name' => $name, 'amount' => $amt, 'notes' => $ded_notes[(int) $k['id']] ?? []];
                $total_ded += $amt;
            } elseif ($k['type'] == 3) {
                $r = $conn->query("SELECT loan_type FROM contribution_loan_types WHERE clt_id={$k['id']}")->fetch_assoc();
                $name = $r['loan_type'] ?? '—';
                foreach ($loans_raw as $kd) { if ($kd['deduction_id'] == $k['id']) $amt += (float) $kd['amount']; }
                $loans_list[] = ['name' => $name, 'amount' => $amt];
                $total_loan += $amt;
            }
        }

        $other  = floatval($p['other_deduction']);
        $tax    = floatval($p['tax']);
        $jei    = floatval($p['jei_advances']);
        $jcc    = floatval($p['jcc_advances']);
        $sss    = floatval($p['sss_fund']);
        // Named one-off items for this employee: kind 1 deducts, kind 2 adds.
        $ps_extras = []; $ps_x_add = $ps_x_less = 0.0;
        if ($conn->query("SHOW TABLES LIKE 'payroll_item_extras'")->num_rows) {
            $xq = $conn->query("SELECT kind, label, amount FROM payroll_item_extras WHERE payroll_item_id = " . (int)$p['id'] . " ORDER BY id ASC");
            if ($xq) while ($x = $xq->fetch_assoc()) {
                $ps_extras[] = $x;
                if ((int)$x['kind'] === 2) $ps_x_add += (float)$x['amount']; else $ps_x_less += (float)$x['amount'];
            }
        }
        $gross += $ps_x_add;
        $total_all_ded = $total_cont + $total_ded + $total_loan + $other + $tax + $jei + $jcc + $sss + $ps_x_less;
        // Manual signed adjustment (+ addition / − recovery), applied to net pay.
        $adjustment  = floatval($p['adjustment'] ?? 0);
        $adj_remarks = trim((string) ($p['adjustment_remarks'] ?? ''));
        $ps_refunds  = 0.0;
        foreach ((json_decode($p['refunds'] ?? '', true) ?: []) as $__r) $ps_refunds += (float) ($__r['amount'] ?? 0);
        // NET from the figures on this page — the same formula as view_payslip.php,
        // the paysheet and the bank list. The stored payroll_items.net goes stale
        // whenever a deduction, extra or ticked setting changes after Calculate, and
        // printing it gave payslips that disagreed with what the bank paid.
        $net_pay = round($gross - $total_all_ded + $ps_refunds + $adjustment, 2, PHP_ROUND_HALF_EVEN);
        $gross   = round($net_pay + $total_all_ded - $ps_refunds - $adjustment, 2);

        return compact('ps_extras','ps_x_add','ps_x_less','p','gross','allowance_amount','total_basic_rate','absent_amount',
                       'overtime_amount','late_amount','undertime_amount',
                       'legal_amt','sunday_amt','special_amt',
                       'contributions_list','deductions_list','loans_list',
                       'total_cont','total_ded','total_loan',
                       'other','tax','jei','jcc','sss','total_all_ded',
                       'adjustment','adj_remarks','ps_refunds','net_pay');
    }
}
