<?php
// Department scoping for Department Head (role 8) and Supervisor (role 10).
// A Dept Head / Supervisor with a department assigned only sees that
// department's employees, leaves, and attendance. Accounts with no department
// set (0 / NULL) keep full visibility so existing unassigned accounts are not
// locked out.
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session_bootstrap.php';
}

/** Department id the current session is locked to, or 0 when unscoped. */
function dept_scope_id(): int
{
    if (!in_array((int)($_SESSION['login_role'] ?? 0), [8, 10], true)) return 0;
    return (int)($_SESSION['login_department_id'] ?? 0);
}

/** " AND <col> = N" SQL fragment against a department-id column, or ''. */
function dept_scope_sql(string $col): string
{
    $d = dept_scope_id();
    return $d > 0 ? " AND $col = $d" : '';
}

/**
 * " AND <emp_id_col> IN (SELECT id FROM employee WHERE department_id = N)"
 * for tables that only carry employee_id (leave_requests, DTR_details, …).
 */
function dept_scope_emp_sql(string $emp_id_col): string
{
    $d = dept_scope_id();
    return $d > 0 ? " AND $emp_id_col IN (SELECT id FROM employee WHERE department_id = $d)" : '';
}
