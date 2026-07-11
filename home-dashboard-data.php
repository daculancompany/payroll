<?php
// Shared dashboard data helpers — used by home.php (initial render) and
// home-dashboard-ajax.php (dynamic period switching), so both always agree.

/**
 * Per-department payroll breakdown for one payroll run.
 * Returns labels + employee count, net, gross, deductions, average net,
 * OT pay and late deductions per department (top 12 by net).
 */
function dashboard_deptpay(mysqli $conn, int $payroll_id): array
{
    $out = ['labels'=>[], 'emp'=>[], 'net'=>[], 'gross'=>[], 'ded'=>[], 'avg'=>[], 'ot'=>[], 'late'=>[]];
    if ($payroll_id <= 0) return $out;

    $res = $conn->query("
        SELECT COALESCE(d.name,'No Dept') AS dept,
               COUNT(pi.id) AS emp,
               COALESCE(SUM(pi.net),0) AS net,
               COALESCE(SUM(
                   ((pi.basic_pay + (pi.allowance_amount * pi.allowance_days) - (pi.absent * pi.per_day)) / 2)
                   + (pi.ot * pi.ot_rate)
                   + (pi.legal_holiday * pi.per_day)
                   + (pi.sunday_duty * pi.per_day)
                   + ((pi.per_day / 8 * 2.4) * pi.special_holiday)
                   - (pi.late * (pi.per_day / 480))
               ),0) AS gross,
               COALESCE(SUM(
                   pi.deduction_amount + pi.other_deduction + pi.tax
                   + pi.jei_advances + pi.jcc_advances + pi.sss_fund
               ),0) AS ded,
               COALESCE(SUM(pi.ot * pi.ot_rate),0) AS ot_amt,
               COALESCE(SUM(pi.late * (pi.per_day / 480)),0) AS late_amt
        FROM payroll_items pi
        INNER JOIN employee e ON pi.employee_id = e.id
        LEFT JOIN department d ON e.department_id = d.id
        WHERE pi.payroll_id = " . $payroll_id . "
        GROUP BY d.id, d.name ORDER BY net DESC LIMIT 12
    ");
    if (!$res) return $out;

    while ($r = $res->fetch_assoc()) {
        $emp = max(1, (int)$r['emp']);
        $out['labels'][] = $r['dept'];
        $out['emp'][]    = (int)$r['emp'];
        $out['net'][]    = round((float)$r['net'], 2);
        $out['gross'][]  = round((float)$r['gross'], 2);
        $out['ded'][]    = round((float)$r['ded'], 2);
        $out['avg'][]    = round((float)$r['net'] / $emp, 2);
        $out['ot'][]     = round((float)$r['ot_amt'], 2);
        $out['late'][]   = round((float)$r['late_amt'], 2);
    }
    return $out;
}

/**
 * Live dashboard counters (for the 60s auto-refresh).
 */
function dashboard_live_stats(mysqli $conn): array
{
    $count = function (string $sql) use ($conn): int {
        $r = $conn->query($sql);
        return ($r && $r !== true) ? (int)$r->fetch_assoc()['c'] : 0;
    };
    return [
        'employees'             => $count("SELECT COUNT(*) AS c FROM employee WHERE status=1"),
        'pending_dtr'           => $count("SELECT COUNT(*) AS c FROM DTR WHERE status=1"),
        'pending_leaves'        => $count("SELECT COUNT(*) AS c FROM leave_requests WHERE status=0"),
        'pending_att_req'       => $count("SELECT COUNT(*) AS c FROM attendance_requests WHERE status=0"),
        'open_dtr_disputes'     => $count("SELECT COUNT(*) AS c FROM dtr_employee_reviews WHERE status=2 AND resolved_at IS NULL"),
        'open_payroll_disputes' => $count("SELECT COUNT(*) AS c FROM payroll_employee_reviews WHERE status=2 AND resolved_at IS NULL"),
    ];
}
