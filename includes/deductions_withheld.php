<?php
// What payroll actually withheld, per employee and per deduction / loan type —
// read from the payroll items' own deduction / loan lines (the same lines the
// payroll sheet, paysheet and payslip show) plus one-off item deductions.
// Shared by deductions-report.php and the Employee Details Deductions tab.

if (!function_exists('dw_type_names')) {
    /** @return array{0: array<int,string>, 1: array<int,string>} [deduction names, loan type names] */
    function dw_type_names(mysqli $conn)
    {
        $clean = function ($s) { return trim(preg_replace('/\s+/', ' ', (string) $s)); };
        $ded = $loan = [];
        $q = $conn->query("SELECT id, deduction FROM deductions");
        while ($q && ($r = $q->fetch_assoc())) $ded[(int) $r['id']] = $clean($r['deduction']);
        $q = $conn->query("SELECT clt_id, loan_type FROM contribution_loan_types");
        while ($q && ($r = $q->fetch_assoc())) $loan[(int) $r['clt_id']] = $clean($r['loan_type']);
        return [$ded, $loan];
    }

    /**
     * Descriptions behind payroll deduction lines. Each line that carries a
     * ded_row_id (every line calculated after the description feature; older
     * runs only for balance deductions) maps back to its employee_deductions
     * row, whose optional description says what the charge was for.
     *
     * @param array $lines decoded payroll_items.deductions lines (one item or many)
     * @return array<int, string> ded_row_id => description (non-empty only)
     */
    function dw_ded_descriptions(mysqli $conn, array $lines)
    {
        static $has = null;
        if ($has === null) {
            $r = $conn->query("SHOW COLUMNS FROM employee_deductions LIKE 'description'");
            $has = $r && $r->num_rows > 0;
        }
        $ids = [];
        foreach ($lines as $l) if (!empty($l['ded_row_id'])) $ids[(int) $l['ded_row_id']] = true;
        if (!$has || !$ids) return [];
        $out = [];
        $q = $conn->query("SELECT id, description FROM employee_deductions
                           WHERE id IN (" . implode(',', array_keys($ids)) . ") AND description IS NOT NULL AND description <> ''");
        while ($q && ($r = $q->fetch_assoc())) $out[(int) $r['id']] = trim($r['description']);
        return $out;
    }

    /**
     * Group one item's deduction lines into notes per deduction type:
     * deduction_id => [['desc' => ..., 'amount' => ...], …] — only lines with a description.
     */
    function dw_ded_notes(mysqli $conn, array $lines)
    {
        $desc = dw_ded_descriptions($conn, $lines);
        $notes = [];
        foreach ($lines as $l) {
            $rid = (int) ($l['ded_row_id'] ?? 0);
            if ($rid && isset($desc[$rid]) && abs((float) ($l['amount'] ?? 0)) >= 0.005) {
                $notes[(int) $l['deduction_id']][] = ['desc' => $desc[$rid], 'amount' => (float) $l['amount']];
            }
        }
        return $notes;
    }

    /**
     * Collect withheld amounts for the given payroll runs.
     *
     * @param array $pays  payroll rows keyed by id
     * @param array $opt   employee_id (int), q (search), kind (all|ded|loan|extra), type (column key)
     * @return array{cols: array, emps: array}
     *   cols: key => [label, kind] in display order (deductions, loans, one-off)
     *   emps: employee_id => [emp, no, dept, tot[key], sum, pay[payroll_id][key],
     *         notes[payroll_id][key][] = [desc, amount] for described deduction lines]
     */
    function dw_collect(mysqli $conn, array $pays, array $opt = [])
    {
        $cols = $emps = [];
        if (!$pays) return ['cols' => $cols, 'emps' => $emps];
        [$ded_names, $loan_names] = dw_type_names($conn);
        $emp_id = (int) ($opt['employee_id'] ?? 0);
        $q      = (string) ($opt['q'] ?? '');
        $kind_f = (string) ($opt['kind'] ?? 'all');
        $type_f = (string) ($opt['type'] ?? '');
        $in     = implode(',', array_map('intval', array_keys($pays)));
        $emp_w  = $emp_id ? " AND pi.employee_id = $emp_id" : '';

        // One-off item deductions (payroll_item_extras kind 1), when that table exists.
        $extras = [];
        if ($conn->query("SHOW TABLES LIKE 'payroll_item_extras'")->num_rows) {
            $xq = $conn->query("SELECT x.payroll_item_id, x.label, x.amount FROM payroll_item_extras x
                                INNER JOIN payroll_items pi ON pi.id = x.payroll_item_id
                                WHERE x.kind = 1 AND x.payroll_id IN ($in)$emp_w");
            while ($xq && ($x = $xq->fetch_assoc())) $extras[(int) $x['payroll_item_id']][] = $x;
        }

        $iq = $conn->query("SELECT pi.id, pi.payroll_id, pi.employee_id, pi.deductions, pi.loans,
                                   e.employee_no, CONCAT(e.lastname, ', ', e.firstname) AS emp, COALESCE(d.name, '') AS dept
                            FROM payroll_items pi
                            INNER JOIN employee e ON e.id = pi.employee_id
                            LEFT JOIN department d ON d.id = e.department_id
                            WHERE pi.payroll_id IN ($in)$emp_w
                            ORDER BY e.lastname, e.firstname");
        $item_rows = [];
        $all_ded_lines = [];
        while ($iq && ($row = $iq->fetch_assoc())) {
            if ($q !== '' && stripos($row['emp'] . ' ' . $row['employee_no'] . ' ' . $row['dept'], $q) === false) continue;
            $row['ded_lines'] = json_decode((string) $row['deductions'], true) ?: [];
            foreach ($row['ded_lines'] as $d) $all_ded_lines[] = $d;
            $item_rows[] = $row;
        }
        // Descriptions for every deduction line, in one query.
        $descs = dw_ded_descriptions($conn, $all_ded_lines);
        foreach ($item_rows as $row) {
            $lines = [];
            foreach ($row['ded_lines'] as $d) {
                $id = (int) ($d['deduction_id'] ?? 0);
                $rid = (int) ($d['ded_row_id'] ?? 0);
                $lines[] = ['D' . $id, $ded_names[$id] ?? ('Deduction #' . $id), 'ded', (float) ($d['amount'] ?? 0), $rid && isset($descs[$rid]) ? $descs[$rid] : ''];
            }
            foreach (json_decode((string) $row['loans'], true) ?: [] as $l) {
                $id = (int) ($l['deduction_id'] ?? 0);
                $lines[] = ['L' . $id, $loan_names[$id] ?? ('Loan #' . $id), 'loan', (float) ($l['amount'] ?? 0)];
            }
            foreach ($extras[(int) $row['id']] ?? [] as $x) {
                $lbl = trim(preg_replace('/\s+/', ' ', (string) $x['label'])) ?: 'Other';
                $lines[] = ['X' . strtoupper($lbl), $lbl, 'extra', (float) $x['amount']];
            }
            foreach ($lines as $line) {
                [$key, $label, $kind, $amt] = $line;
                $note = $line[4] ?? '';
                if (abs($amt) < 0.005) continue;
                if ($kind_f !== 'all' && $kind !== $kind_f) continue;
                if ($type_f !== '' && $key !== $type_f) continue;
                $cols[$key] = $cols[$key] ?? ['label' => $label, 'kind' => $kind];
                $eid = (int) $row['employee_id'];
                if (!isset($emps[$eid])) {
                    $emps[$eid] = ['emp' => $row['emp'], 'no' => $row['employee_no'], 'dept' => $row['dept'], 'tot' => [], 'sum' => 0, 'pay' => []];
                }
                $pid = (int) $row['payroll_id'];
                $emps[$eid]['tot'][$key] = ($emps[$eid]['tot'][$key] ?? 0) + $amt;
                $emps[$eid]['pay'][$pid][$key] = ($emps[$eid]['pay'][$pid][$key] ?? 0) + $amt;
                $emps[$eid]['sum'] += $amt;
                if ($note !== '') $emps[$eid]['notes'][$pid][$key][] = ['desc' => $note, 'amount' => $amt];
            }
        }
        // Columns: deductions, then loans, then one-off — alphabetical inside each.
        $order = ['ded' => 0, 'loan' => 1, 'extra' => 2];
        uksort($cols, function ($a, $b) use ($cols, $order) {
            return [$order[$cols[$a]['kind']], $cols[$a]['label']] <=> [$order[$cols[$b]['kind']], $cols[$b]['label']];
        });
        return ['cols' => $cols, 'emps' => $emps];
    }
}
