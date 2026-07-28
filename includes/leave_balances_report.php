<?php
/**
 * Shared data builder for the Leave Balances report.
 *
 * Used by leave-balances-report.php (on-screen) and export-leave-balances.php
 * (Excel / PDF) so every output renders exactly the same figures from exactly
 * the same filters.
 *
 * Credits are tracked per calendar year in employee_leave_credits; a missing
 * row means the employee is simply on the leave type's default entitlement
 * (leave_types.days_allowed), which is what the balances editor assumes too.
 */
require_once __DIR__ . '/../dept-scope.php';

if (!function_exists('lbr_filters')) {
    /** Normalise + whitelist the report's query-string filters. */
    function lbr_filters(array $q): array
    {
        $year = isset($q['year']) && (int) $q['year'] ? (int) $q['year'] : 0;
        if (!$year) $year = function_exists('leave_current_year') ? leave_current_year() : (int) date('Y');

        $view = $q['view'] ?? 'all';
        if (!in_array($view, ['all', 'remaining', 'exhausted', 'unused', 'pending'], true)) $view = 'all';

        return [
            'year'       => $year,
            'dept'       => isset($q['dept']) ? (int) $q['dept'] : 0,
            'type'       => isset($q['type']) ? (int) $q['type'] : 0,
            'emp'        => isset($q['emp']) ? (int) $q['emp'] : 0,
            'search'     => trim((string) ($q['search'] ?? '')),
            'view'       => $view,
            'ineligible' => !empty($q['ineligible']),
        ];
    }
}

if (!function_exists('lbr_fmt')) {
    /** 5.0 → "5", 5.5 → "5.5" — leave days are always halves at most. */
    function lbr_fmt($n): string
    {
        return rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
    }
}

if (!function_exists('lbr_data')) {
    /**
     * Builds the whole report: leave types, one row per employee with a cell
     * per leave type, per-type totals and grand totals.
     */
    function lbr_data(mysqli $conn, array $f): array
    {
        $year = (int) $f['year'];

        // ── Leave types in scope (paid + active; credits only exist for these) ──
        $types = [];
        $typeSql = "SELECT id, name, days_allowed, carryover, carryover_cap
                    FROM leave_types WHERE status = 1 AND is_paid = 1"
                 . ($f['type'] ? " AND id = " . (int) $f['type'] : "")
                 . " ORDER BY name ASC";
        $tq = $conn->query($typeSql);
        if ($tq) while ($t = $tq->fetch_assoc()) {
            $types[(int) $t['id']] = [
                'id'            => (int) $t['id'],
                'name'          => $t['name'],
                'days_allowed'  => (float) $t['days_allowed'],
                'carryover'     => (int) $t['carryover'],
                'carryover_cap' => $t['carryover_cap'] === null ? null : (float) $t['carryover_cap'],
            ];
        }

        // ── Employees in scope ──────────────────────────────────────────────
        $where = "WHERE e.status = 1" . dept_scope_sql('e.department_id');
        if ($f['dept'])   $where .= " AND e.department_id = " . (int) $f['dept'];
        if ($f['emp'])    $where .= " AND e.id = " . (int) $f['emp'];
        if ($f['search'] !== '') {
            $s = $conn->real_escape_string($f['search']);
            $where .= " AND (e.firstname LIKE '%$s%' OR e.lastname LIKE '%$s%' OR e.employee_no LIKE '%$s%')";
        }

        $emps = [];
        $eq = $conn->query("
            SELECT e.id, e.employee_no, e.firstname, e.lastname, e.leave_override,
                   COALESCE(d.name, '—') AS dept, COALESCE(p.name, '—') AS position,
                   UPPER(COALESCE(cl.clasification, '')) AS clasif
            FROM employee e
            LEFT JOIN department d ON d.id = e.department_id
            LEFT JOIN position p ON p.id = e.position_id
            LEFT JOIN clasification cl ON cl.id = e.clasification_id
            $where
            ORDER BY d.name ASC, e.lastname ASC, e.firstname ASC
        ");
        if ($eq) while ($e = $eq->fetch_assoc()) $emps[(int) $e['id']] = $e;

        // ── Credit overrides for the year ───────────────────────────────────
        $credits = [];
        $cq = $conn->query("SELECT employee_id, leave_type_id, credits
                            FROM employee_leave_credits WHERE year = $year");
        if ($cq) while ($c = $cq->fetch_assoc()) {
            $credits[(int) $c['employee_id']][(int) $c['leave_type_id']] = (float) $c['credits'];
        }

        // ── Consumed (approved) + in-flight (pending) days for the year ─────
        $used = $pending = [];
        $uq = $conn->query("
            SELECT employee_id, leave_type_id, status, SUM(duration) AS days
            FROM leave_requests
            WHERE YEAR(date_from) = $year AND status IN (0, 1)
            GROUP BY employee_id, leave_type_id, status
        ");
        if ($uq) while ($u = $uq->fetch_assoc()) {
            $bucket = (int) $u['status'] === 1 ? 'used' : 'pending';
            ${$bucket}[(int) $u['employee_id']][(int) $u['leave_type_id']] = (float) $u['days'];
        }

        // ── Assemble ────────────────────────────────────────────────────────
        $rows        = [];
        $typeTotals  = [];
        $totals      = ['credits' => 0.0, 'used' => 0.0, 'pending' => 0.0, 'remaining' => 0.0,
                        'employees' => 0, 'exhausted' => 0, 'untouched' => 0, 'ineligible' => 0];
        foreach ($types as $tid => $t) {
            $typeTotals[$tid] = ['credits' => 0.0, 'used' => 0.0, 'pending' => 0.0, 'remaining' => 0.0, 'takers' => 0];
        }

        foreach ($emps as $eid => $e) {
            $eligible = leave_eligibility_from($e['clasif'], $e['leave_override']);
            if (!$eligible && !$f['ineligible']) { $totals['ineligible']++; continue; }

            $cells = [];
            $rowTot = ['credits' => 0.0, 'used' => 0.0, 'pending' => 0.0, 'remaining' => 0.0];
            foreach ($types as $tid => $t) {
                $cr  = $credits[$eid][$tid] ?? $t['days_allowed'];
                if (!$eligible) $cr = 0.0;                       // shown for context only
                $us  = $used[$eid][$tid] ?? 0.0;
                $pd  = $pending[$eid][$tid] ?? 0.0;
                $rem = $cr - $us;
                $cells[$tid] = ['credits' => $cr, 'used' => $us, 'pending' => $pd, 'remaining' => $rem];

                $rowTot['credits']   += $cr;
                $rowTot['used']      += $us;
                $rowTot['pending']   += $pd;
                $rowTot['remaining'] += $rem;
            }

            // View filter runs on the computed row, not in SQL.
            if ($f['view'] === 'remaining'  && $rowTot['remaining'] <= 0)  continue;
            if ($f['view'] === 'exhausted'  && $rowTot['remaining'] > 0)   continue;
            if ($f['view'] === 'unused'     && $rowTot['used'] > 0)        continue;
            if ($f['view'] === 'pending'    && $rowTot['pending'] <= 0)    continue;

            $rows[] = [
                'id'          => $eid,
                'employee_no' => $e['employee_no'],
                'name'        => $e['lastname'] . ', ' . $e['firstname'],
                'dept'        => $e['dept'],
                'position'    => $e['position'],
                'clasif'      => $e['clasif'] !== '' ? ucfirst(strtolower($e['clasif'])) : 'Unclassified',
                'eligible'    => $eligible,
                'cells'       => $cells,
                'tot'         => $rowTot,
            ];

            $totals['employees']++;
            $totals['credits']   += $rowTot['credits'];
            $totals['used']      += $rowTot['used'];
            $totals['pending']   += $rowTot['pending'];
            $totals['remaining'] += $rowTot['remaining'];
            if ($rowTot['remaining'] <= 0) $totals['exhausted']++;
            if ($rowTot['used'] <= 0)      $totals['untouched']++;

            foreach ($types as $tid => $t) {
                $typeTotals[$tid]['credits']   += $cells[$tid]['credits'];
                $typeTotals[$tid]['used']      += $cells[$tid]['used'];
                $typeTotals[$tid]['pending']   += $cells[$tid]['pending'];
                $typeTotals[$tid]['remaining'] += $cells[$tid]['remaining'];
                if ($cells[$tid]['used'] > 0) $typeTotals[$tid]['takers']++;
            }
        }

        $totals['utilization'] = $totals['credits'] > 0
            ? round($totals['used'] / $totals['credits'] * 100, 1) : 0.0;

        return [
            'filters'     => $f,
            'types'       => $types,
            'rows'        => $rows,
            'type_totals' => $typeTotals,
            'totals'      => $totals,
        ];
    }
}

if (!function_exists('lbr_departments')) {
    /** Departments available to the current session, for the filter dropdown. */
    function lbr_departments(mysqli $conn): array
    {
        $out = [];
        $q = $conn->query("SELECT id, name FROM department WHERE 1" . dept_scope_sql('id') . " ORDER BY name ASC");
        if ($q) while ($r = $q->fetch_assoc()) $out[] = $r;
        return $out;
    }
}

if (!function_exists('lbr_all_types')) {
    /** Every paid, active leave type — the type filter's option list. */
    function lbr_all_types(mysqli $conn): array
    {
        $out = [];
        $q = $conn->query("SELECT id, name FROM leave_types WHERE status = 1 AND is_paid = 1 ORDER BY name ASC");
        if ($q) while ($r = $q->fetch_assoc()) $out[] = $r;
        return $out;
    }
}

if (!function_exists('lbr_company')) {
    /** Company name for export headers. */
    function lbr_company(mysqli $conn): string
    {
        $q = $conn->query("SELECT employer_name FROM employers ORDER BY id ASC LIMIT 1");
        $r = $q ? $q->fetch_assoc() : null;
        return $r && $r['employer_name'] !== '' ? $r['employer_name'] : 'Company';
    }
}

if (!function_exists('lbr_filter_summary')) {
    /** Human-readable "Year: 2026 · Department: All · …" line for exports. */
    function lbr_filter_summary(mysqli $conn, array $f): string
    {
        $parts = ['Year: ' . $f['year']];

        $dept = 'All departments';
        if ($f['dept']) {
            $d = $conn->query("SELECT name FROM department WHERE id = " . (int) $f['dept']);
            $dr = $d ? $d->fetch_assoc() : null;
            if ($dr) $dept = $dr['name'];
        }
        $parts[] = 'Department: ' . $dept;

        $type = 'All paid leave types';
        if ($f['type']) {
            $t = $conn->query("SELECT name FROM leave_types WHERE id = " . (int) $f['type']);
            $tr = $t ? $t->fetch_assoc() : null;
            if ($tr) $type = $tr['name'];
        }
        $parts[] = 'Leave type: ' . $type;

        $views = [
            'all'       => 'All employees',
            'remaining' => 'With remaining credits',
            'exhausted' => 'Fully consumed',
            'unused'    => 'No leave taken',
            'pending'   => 'With pending requests',
        ];
        $parts[] = 'View: ' . ($views[$f['view']] ?? 'All employees');
        if ($f['search'] !== '')  $parts[] = 'Search: "' . $f['search'] . '"';
        if ($f['ineligible'])     $parts[] = 'Includes non-eligible employees';

        return implode('  ·  ', $parts);
    }
}

if (!function_exists('lbr_leave_requests')) {
    /** Leave request history for a single employee + year (single-employee export). */
    function lbr_leave_requests(mysqli $conn, int $empId, int $year): array
    {
        $out = [];
        $q = $conn->query("
            SELECT lr.date_applied, lr.date_from, lr.date_to, lr.duration, lr.status, lr.reason,
                   lt.name AS type_name
            FROM leave_requests lr
            INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
            WHERE lr.employee_id = $empId AND YEAR(lr.date_from) = $year
            ORDER BY lr.date_from DESC, lr.id DESC
        ");
        if ($q) while ($r = $q->fetch_assoc()) $out[] = $r;
        return $out;
    }
}
