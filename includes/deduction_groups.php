<?php
// Deduction groups — several amortizing employee_deductions rows that share ONE
// per-payroll amount (see migrations/2026_10_deduction_groups.sql).
//
// Pure helpers: no DB access, so the payroll engine (admin_class.php) and the
// Employee Details queue labels agree on exactly the same allocation.

if (!function_exists('allocate_group')) {
    /**
     * Split one payroll's group amount across the group's rows, oldest first.
     *
     * Each row gets min(balance, what is left of $per_payroll); the next row only
     * starts once the one before it is fully covered. A row's own per-period
     * `amount` is ignored inside a group — the group amount governs.
     *
     * @param array $rows        rows with id, balance, effective_date (others kept)
     * @param float $per_payroll the group's amount per payroll
     * @return array rows that receive money this payroll, each with `alloc`
     */
    function allocate_group(array $rows, $per_payroll)
    {
        usort($rows, 'deduction_group_order');
        $left = round((float) $per_payroll, 2);
        $out  = [];
        foreach ($rows as $r) {
            if ($left <= 0) break;
            $bal = round((float) $r['balance'], 2);
            if ($bal <= 0) continue;
            $take = min($bal, $left);
            $r['alloc'] = $take;
            $out[] = $r;
            $left = round($left - $take, 2);
        }
        return $out;
    }

    /** Payment order inside a group: effective_date (blank first), then id. */
    function deduction_group_order($a, $b)
    {
        $da = (string) ($a['effective_date'] ?? '');
        $db = (string) ($b['effective_date'] ?? '');
        if ($da !== $db) return strcmp($da, $db);
        return (int) $a['id'] <=> (int) $b['id'];
    }
}
