<?php
/**
 * Paysheet — the payroll register in the layout of the old Crystal Reports
 * paysheet (PAYSHEET SAMPLE.xls, Sheet1): employees grouped by department,
 * each block "DEPT <id> | <name>" → rows → "TOTAL | <name>", then Grand Total.
 *
 * One builder feeds all three outputs — the Table View tab (paysheet.php), the
 * PDF (print-paysheet.php via pdf-payroll.php?src=paysheet) and the workbook
 * (export-paysheet.php) — so they can never disagree with each other.
 *
 * Every figure comes from the same pieces the Detailed table uses
 * (payroll_earnings, the run's ticked settings, payroll_item_extras), so GROSS
 * and NET match the Detailed table / payslip to the centavo. The paysheet only
 * re-buckets them into its fixed columns:
 *   OT/ND            overtime + night diff + holiday / rest-day premiums
 *   ALLOWANCE        allowance slots + one-off earnings not bucketed below
 *   HAZARD           one-off earnings labelled "…hazard…"
 *   MEAL             one-off earnings labelled "…meal…"
 *   BACKPAY          one-off earnings labelled "Backpay"
 *   SSS              the SSS contribution (id 1); MPF has no source yet → 0
 *   OTHER DEDUCTION  every other ticked contribution / deduction / loan
 *                    (PhilHealth, Pag-IBIG, …) + one-off deductions
 *   NET PAY          gross − deductions + refunds + adjustment (as on screen)
 */

if (!defined('PAYSHEET_COLUMNS')) {
    // key => [header, is_money]. 'sss' and 'mpf' sit under one merged header.
    define('PAYSHEET_COLUMNS', [
        'emp_no'   => ['EMP NO', false],
        'name'     => ['NAME', false],
        'rate'     => ['RATE', true],
        'basic'    => ['BASIC DAYS', true],
        'otnd'     => ['OT/ND', true],
        'allow'    => ['ALLOWANCE', true],
        'adj1'     => ['HAZARD', true],
        'adj2'     => ['MEAL ALLOWANCE', true],
        'adj3'     => ['BACKPAY', true],
        'under'    => ['UNDERTIME', true],
        'late'     => ['LATE', true],
        'gross'    => ['GROSS PAY', true],
        'sss'      => ['SSS CONTRIBUTION EMPLOYEE SHARE', true],
        'mpf'      => ['MPF', true],
        'tax'      => ['TAX', true],
        'other'    => ['OTHER DEDUCTION', true],
        'net'      => ['NET PAY', true],
    ]);
    // Contribution id of SSS in the `contributions` master.
    define('PAYSHEET_SSS_ID', 1);
    // Group key of the Non-ATM (paid in cash) block — always listed last.
    define('PAYSHEET_NONATM', -1);
    // O. DEDUCTIONS breakdown on the Department Summary, in print order.
    // Every peso of OTHER DEDUCTION lands in exactly one bucket (paysheet_ded_bucket).
    define('PAYSHEET_DED_BUCKETS', [
        'phic'       => 'PHILHEALTH',
        'hdmf'       => 'PAG-IBIG',
        'coop'       => 'COOP',
        'housing'    => 'HOUSING LOANS',
        'hdmf_loans' => 'HDMF LOANS',
        'sss_loans'  => 'SSS LOANS',
        'pharm'      => 'PHARMACY/HOSPITAL/LAB',
        'others'     => 'OTHERS',
    ]);
}

/**
 * Which breakdown bucket one ticked deduction belongs to.
 * $type: 1 contribution (contributions.id), 2 deduction (deductions.id),
 * 3 loan (contribution_loan_types.clt_id). Unknown ids fall to OTHERS.
 */
function paysheet_ded_bucket(int $type, int $id): string
{
    if ($type === 1) return [2 => 'phic', 3 => 'hdmf'][$id] ?? 'others';
    if ($type === 2) return [16 => 'coop', 15 => 'pharm', 17 => 'pharm', 18 => 'pharm'][$id] ?? 'others';
    if ($type === 3) {
        if ($id === 13) return 'housing';                    // HDMF SAFE LOAN
        if (in_array($id, [10, 11, 12], true)) return 'hdmf_loans';
        if (in_array($id, [7, 8, 9], true)) return 'sss_loans';
    }
    return 'others';
}

/** Columns summed on the TOTAL rows (RATE is not a sum). */
function paysheet_sum_keys(): array
{
    $keys = [];
    foreach (PAYSHEET_COLUMNS as $k => $c) if ($c[1] && $k !== 'rate') $keys[] = $k;
    return $keys;
}

/**
 * Build the grouped paysheet for one payroll run.
 * Returns ['payroll' => row, 'period' => 'July 01 - July 15, 2026',
 *          'groups' => [['id','name','rows'=>[…],'total'=>[…]], …], 'grand' => […]].
 */
function paysheet_build(mysqli $conn, int $payroll_id): array
{
    $payroll = $conn->query("SELECT * FROM payroll WHERE id = " . (int) $payroll_id)->fetch_assoc();
    if (!$payroll) return ['payroll' => null, 'period' => '', 'groups' => [], 'grand' => []];

    // Non-ATM (cash-paid) employees leave their department for the NONATM block.
    $nonAtm = array_flip(payroll_non_atm($conn, $payroll_id)['ids']);
    $breakdown = array_fill_keys(array_keys(PAYSHEET_DED_BUCKETS), 0.0);

    $split    = payroll_settings_split($payroll['settings']);
    $deds     = $split['deds'];      // ticked contributions (1) / deductions (2) / loans (3)
    $refundsS = $split['refunds'];

    // One-off lines per item (kind 2 earning, kind 1 deduction).
    $extras = [];
    if ($conn->query("SHOW TABLES LIKE 'payroll_item_extras'")->num_rows) {
        $xq = $conn->query("SELECT payroll_item_id, kind, label, amount FROM payroll_item_extras WHERE payroll_id = " . (int) $payroll_id);
        while ($xq && ($x = $xq->fetch_assoc())) $extras[(int) $x['payroll_item_id']][] = $x;
    }

    $q = $conn->query("SELECT a.*, e.employee_no, e.lastname, e.firstname, e.middlename,
                              e.department_id AS dept_id, d.name AS department
                       FROM payroll_items a
                       INNER JOIN employee e ON a.employee_id = e.id
                       LEFT JOIN department d ON e.department_id = d.id
                       WHERE a.payroll_id = " . (int) $payroll_id . "
                       ORDER BY e.lastname ASC, e.firstname ASC");

    $sumKeys = paysheet_sum_keys();
    $zero    = array_fill_keys($sumKeys, 0.0);
    $groups  = [];
    while ($q && ($row = $q->fetch_assoc())) {
        $row['payroll_type'] = $payroll['type'];   // payroll_earnings' legacy monthly-run override
        $e = payroll_earnings($row);

        // One-off earnings, bucketed by label; deductions summed.
        $hazard = $meal = $backpay = $otherEarn = $extraLess = 0.0;
        foreach ($extras[(int) $row['id']] ?? [] as $x) {
            $amt = (float) $x['amount'];
            if ((int) $x['kind'] !== 2) { $extraLess += $amt; continue; }
            if (payroll_is_backpay_label($x['label']))       $backpay += $amt;
            elseif (preg_match('/hazard/i', $x['label']))    $hazard  += $amt;
            elseif (preg_match('/meal/i', $x['label']))      $meal    += $amt;
            else                                             $otherEarn += $amt;
        }
        $gross = $e['gross'] + $hazard + $meal + $backpay + $otherEarn;

        // Ticked deductions only — same lookup as the Detailed table.
        $jsonFor = [1 => 'contributions', 2 => 'deductions', 3 => 'loans'];
        $idKey   = [1 => 'contribution_id', 2 => 'deduction_id', 3 => 'deduction_id'];
        $decoded = [];
        $sss = $otherDed = 0.0;
        foreach ($deds as $k) {
            $t = (int) $k['type'];
            if (!isset($jsonFor[$t])) continue;
            $decoded[$t] ??= (json_decode($row[$jsonFor[$t]] ?? '', true) ?: []);
            $amt = 0.0;
            foreach ($decoded[$t] as $d) if (($d[$idKey[$t]] ?? null) == $k['id']) $amt = (float) $d['amount'];
            if ($t === 1 && (int) $k['id'] === PAYSHEET_SSS_ID) { $sss += $amt; continue; }
            $otherDed += $amt;
            $breakdown[paysheet_ded_bucket($t, (int) $k['id'])] += $amt;
        }
        $otherDed += $extraLess;
        $breakdown['others'] += $extraLess;   // one-off deductions
        $tax = (float) $row['tax'];

        $refunds = 0.0;
        $rj = json_decode($row['refunds'] ?? '', true) ?: [];
        foreach ($refundsS as $k) foreach ($rj as $d) if (($d['refund_id'] ?? null) == $k['id']) $refunds += (float) $d['amount'];

        $net = $gross - ($sss + $tax + $otherDed) + $refunds + (float) ($row['adjustment'] ?? 0);

        $line = [
            'emp_no' => (string) $row['employee_no'],
            'name'   => strtoupper(trim($row['lastname'] . ', ' . $row['firstname'] . ' ' . ($row['middlename'] ?? ''))),
            'rate'   => $e['is_monthly'] ? (float) $row['basic_pay'] : (float) $row['per_day'],
            'basic'  => $e['subtotal'],
            'otnd'   => $e['overtime'] + $e['nsd_amt'] + $e['legal_amt'] + $e['rest_amt'] + $e['special_amt'],
            'allow'  => $e['allowance'] + $otherEarn,
            'adj1'   => $hazard,
            'adj2'   => $meal,
            'adj3'   => $backpay,
            'under'  => $e['under_amt'],
            'late'   => $e['late_amt'],
            'gross'  => $gross,
            'sss'    => $sss,
            'mpf'    => 0.0,
            'tax'    => $tax,
            'other'  => $otherDed,
            'net'    => $net,
        ];

        $isNonAtm = isset($nonAtm[(int) $row['employee_id']]);
        $gid = $isNonAtm ? PAYSHEET_NONATM : (int) ($row['dept_id'] ?? 0);
        if (!isset($groups[$gid])) {
            $groups[$gid] = [
                'id'    => $gid,
                'name'  => $isNonAtm ? 'NONATM'
                         : (strtoupper(trim((string) ($row['department'] ?? '')) ?: 'NO DEPARTMENT')),
                'rows'  => [],
                'total' => $zero,
            ];
        }
        $groups[$gid]['rows'][] = $line;
        foreach ($sumKeys as $k) $groups[$gid]['total'][$k] += $line[$k];
    }

    // Department order: by id (the sheet's "DEPT n" labels); unassigned, then NONATM, last.
    $rank = fn($k) => $k === PAYSHEET_NONATM ? 2 : ($k === 0 ? 1 : 0);
    uksort($groups, fn($a, $b) => $rank($a) <=> $rank($b) ?: $a <=> $b);

    $grand = $zero;
    foreach ($groups as $g) foreach ($sumKeys as $k) $grand[$k] += $g['total'][$k];
    $nonAtmNet = (float) ($groups[PAYSHEET_NONATM]['total']['net'] ?? 0);

    $period = date('F d', strtotime($payroll['date_from'])) . ' - ' . date('F j, Y', strtotime($payroll['date_to']));
    return [
        'payroll'    => $payroll,
        'period'     => $period,
        'groups'     => array_values($groups),
        'grand'      => $grand,
        'atm_net'    => $grand['net'] - $nonAtmNet,
        'nonatm_net' => $nonAtmNet,
        'breakdown'  => $breakdown,   // sums to grand['other']
    ];
}

/** "DEPT 13" label for a group ('' for the unassigned and NONATM blocks). */
function paysheet_dept_label(array $g): string
{
    return $g['id'] > 0 ? 'DEPT ' . $g['id'] : '';
}

/**
 * The paysheet as an HTML <table> (inline styles only, so the same markup works
 * on screen and in dompdf). $money formats numbers.
 */
function paysheet_table_html(array $ps): string
{
    $cols  = PAYSHEET_COLUMNS;
    $n     = count($cols);
    $fmt   = fn($v) => number_format((float) $v, 2);
    $h     = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $hdr   = 'background:#ffff00;font-weight:bold;text-align:center;vertical-align:middle;border:1px solid #000;padding:3px 4px;white-space:normal;';
    $cell  = 'border:1px solid #bbb;padding:2px 4px;';
    $num   = $cell . 'text-align:right;white-space:nowrap;';
    $tot   = 'font-weight:bold;border-top:1px solid #000;border-bottom:2px solid #000;';

    $out  = '<table class="paysheet-table" style="border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;font-size:11px;width:100%;">';
    $out .= '<thead><tr>';
    foreach ($cols as $k => $c) {
        if ($k === 'mpf') continue;
        $label = $k === 'rate' ? '' : $c[0];
        $span  = $k === 'sss' ? ' colspan="2"' : '';
        $out  .= '<th' . $span . ' style="' . $hdr . '">' . $h($label) . '</th>';
    }
    $out .= '</tr></thead><tbody>';

    if (!$ps['groups']) {
        $out .= '<tr><td colspan="' . $n . '" style="' . $cell . 'text-align:center;color:#888;">No employees in this payroll.</td></tr>';
    }
    foreach ($ps['groups'] as $g) {
        $out .= '<tr class="ps-dept"><td style="' . $cell . 'font-weight:bold;white-space:nowrap;">' . $h(paysheet_dept_label($g)) . '</td>'
              . '<td colspan="' . ($n - 1) . '" style="' . $cell . 'font-weight:bold;">' . $h($g['name']) . '</td></tr>';
        foreach ($g['rows'] as $r) {
            $out .= '<tr>';
            foreach ($cols as $k => $c) {
                $out .= $c[1]
                    ? '<td style="' . $num . '">' . $fmt($r[$k]) . '</td>'
                    : '<td style="' . $cell . 'white-space:nowrap;' . '">' . $h($r[$k]) . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '<tr class="ps-total"><td style="' . $cell . $tot . '">TOTAL</td>'
              . '<td style="' . $cell . $tot . 'white-space:nowrap;">' . $h($g['name']) . '</td><td style="' . $cell . $tot . '"></td>';
        foreach (paysheet_sum_keys() as $k) $out .= '<td style="' . $num . $tot . '">' . $fmt($g['total'][$k]) . '</td>';
        $out .= '</tr>';
    }
    if ($ps['groups']) {
        $gt = 'font-weight:bold;background:#fff7c2;border-top:2px solid #000;border-bottom:3px double #000;';
        $out .= '<tr class="ps-grand"><td colspan="3" style="' . $cell . $gt . 'text-align:right;">Grand Total</td>';
        foreach (paysheet_sum_keys() as $k) $out .= '<td style="' . $num . $gt . '">' . $fmt($ps['grand'][$k]) . '</td>';
        $out .= '</tr>';
    }
    return $out . '</tbody></table>';
}

/**
 * The Department Summary ("PAYROLL SUMMARY" sheet) as HTML: one row per group
 * total, TOTAL, then the ATM / NON ATM / TOTAL box and the O. DEDUCTIONS
 * breakdown. Inline styles only — the same markup is shown in the web view
 * (paysheet.php?view=summary) and printed to PDF (print-payroll-dept.php).
 */
function paysheet_summary_html(array $ps): string
{
    // Summary columns → paysheet total keys (SSS PROV. = the paysheet's MPF column).
    $cols = [
        'basic' => 'BASIC PAY', 'otnd' => 'OT/ND', 'allow' => 'ALLOWANCE',
        'adj1' => 'HAZARD', 'adj2' => 'MEAL ALLOWANCE', 'adj3' => 'BACKPAY',
        'under' => 'UT', 'late' => 'LATE', 'gross' => 'GROSS AMOUNT',
        'sss' => 'SSS', 'mpf' => 'SSS PROV.', 'tax' => 'W/TAX',
        'other' => 'O. DEDUCTIONS', 'net' => 'AMOUNT DUE',
    ];
    $fmt  = fn($v) => number_format((float) $v, 2);
    $h    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $cell = 'border:1px solid #000;padding:3px 5px;';
    $num  = $cell . 'text-align:right;white-space:nowrap;';
    $tot  = 'font-weight:bold;border-top:2px solid #000;';

    $out  = '<table class="ps-summary" style="width:100%;border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;font-size:11px;">';
    $out .= '<thead><tr><th style="' . $cell . 'background:#ffff00;text-align:left;">DEPARTMENT</th>';
    foreach ($cols as $label) $out .= '<th style="' . $cell . 'background:#ffff00;text-align:center;">' . $label . '</th>';
    $out .= '</tr></thead><tbody>';
    foreach ($ps['groups'] as $g) {
        $out .= '<tr><td style="' . $cell . 'font-weight:bold;">' . $h($g['name']) . '</td>';
        foreach ($cols as $k => $label) $out .= '<td style="' . $num . '">' . $fmt($g['total'][$k]) . '</td>';
        $out .= '</tr>';
    }
    $out .= '<tr><td style="' . $cell . $tot . '">TOTAL</td>';
    foreach ($cols as $k => $label) $out .= '<td style="' . $num . $tot . '">' . $fmt($ps['grand'][$k]) . '</td>';
    $out .= '</tr></tbody></table>';

    // Footer: payout split (left) and O. DEDUCTIONS breakdown (right).
    $row  = fn($label, $amt, $grand = false) =>
        '<tr><td' . ($grand ? ' class="ps-line"' : '') . ' style="padding:2px 8px;font-weight:bold;' . ($grand ? 'border-top:1px solid #000;' : '') . '">' . $label . '</td>'
        . '<td' . ($grand ? ' class="ps-line"' : '') . ' style="padding:2px 8px;font-weight:bold;text-align:right;min-width:110px;'
        . ($grand ? 'border-top:1px solid #000;text-decoration:underline;' : '') . '">' . $fmt($amt) . '</td></tr>';
    $box  = '<table style="border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;font-size:11px;">';
    $left = $box . $row('ATM', $ps['atm_net']) . $row('NON ATM', $ps['nonatm_net'])
          . $row('TOTAL', $ps['grand']['net'], true) . '</table>';
    $right = $box;
    foreach ($ps['breakdown'] as $key => $amt) {
        if (abs($amt) >= 0.005) $right .= $row(PAYSHEET_DED_BUCKETS[$key], $amt);
    }
    $right .= $row('TOTAL O. DEDUCTIONS', $ps['grand']['other'], true) . '</table>';

    return $out . '<table class="ps-sum-foot" style="width:100%;border-collapse:collapse;margin-top:18px;"><tr>'
        . '<td style="width:50%;vertical-align:top;">' . $left . '</td>'
        . '<td style="width:50%;vertical-align:top;"><div style="float:right;">' . $right . '</div></td>'
        . '</tr></table>';
}
