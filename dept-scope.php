<?php
// Visibility scoping for the approver roles: Department Head (8), Supervisor
// (10) and Section/Unit Head (11).
//
// TWO SCOPES, AREA WINS
// The original scope was a department id on the user. That cannot express the
// real hierarchy: an approver may cover several wards (the Director of Nursing
// covers ten, spanning seven departments), and a department may hold eleven
// sections that must not see each other. So scoping now prefers AREA — resolved
// from area_approver, the same table the leave chain reads — and falls back to
// the department id for accounts that predate areas.
//
// Callers did not change. dept_scope_sql()/dept_scope_emp_sql() keep their
// names and emit an area predicate instead when the session is area-scoped,
// so the ten pages already using them narrow automatically.
//
// An account with neither an area nor a department keeps full visibility, so
// existing unassigned admin accounts are not locked out.
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session_bootstrap.php';
}

/** Roles whose visibility is scoped at all. Others (admin, HR) see everything. */
if (!defined('SCOPED_ROLES')) define('SCOPED_ROLES', [8, 10, 11]);

/**
 * Area ids this session may see, or [] when the session is not area-scoped.
 * Cached per request: the scoping helpers are called many times per page.
 */
function area_scope_ids(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = [];

    if (!in_array((int) ($_SESSION['login_role'] ?? 0), SCOPED_ROLES, true)) return $memo;
    $uid = (int) ($_SESSION['login_id'] ?? 0);
    if ($uid <= 0) return $memo;

    // db_connect.php may not be loaded yet on every entry point.
    if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
        $f = __DIR__ . '/db_connect.php';
        if (is_file($f)) require_once $f;
    }
    $db = $GLOBALS['conn'] ?? null;
    if (!($db instanceof mysqli)) return $memo;

    $r = $db->query("SELECT DISTINCT area_id FROM area_approver WHERE user_id = $uid");
    while ($r && ($x = $r->fetch_assoc())) $memo[] = (int) $x['area_id'];
    return $memo;
}

/** True when this session is pinned to a set of areas. */
function area_scope_active(): bool
{
    return area_scope_ids() !== [];
}

/**
 * Area ids this session may EDIT the duty roster for: the 'admin' stage only.
 * Section heads and supervisors read the grid but never paint it — the roster
 * is released by the Department/Division Head who owns the whole ward.
 */
function roster_editable_area_ids(): array
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = [];

    $uid = (int) ($_SESSION['login_id'] ?? 0);
    if ($uid <= 0) return $memo;
    if (!in_array((int) ($_SESSION['login_role'] ?? 0), SCOPED_ROLES, true)) return $memo;

    $db = $GLOBALS['conn'] ?? null;
    if (!($db instanceof mysqli)) return $memo;

    $r = $db->query("SELECT area_id FROM area_approver WHERE user_id = $uid AND stage = 'admin'");
    while ($r && ($x = $r->fetch_assoc())) $memo[] = (int) $x['area_id'];
    return $memo;
}

/** May this session paint the roster for $area_id? Unscoped roles always may. */
function roster_can_edit_area(int $area_id): bool
{
    if (!area_scope_active()) return true;            // admin / HR / unassigned
    return in_array($area_id, roster_editable_area_ids(), true);
}

/** Department id the current session is locked to, or 0 when unscoped. */
function dept_scope_id(): int
{
    if (!in_array((int) ($_SESSION['login_role'] ?? 0), SCOPED_ROLES, true)) return 0;
    return (int) ($_SESSION['login_department_id'] ?? 0);
}

/** " AND <col> IN (…)" over a list of ints, or ' AND 0' when the list is empty. */
function scope_in_sql(string $col, array $ids): string
{
    if ($ids === []) return ' AND 0';
    return " AND $col IN (" . implode(',', array_map('intval', $ids)) . ')';
}

/**
 * " AND <col> = N" against a department-id column, or ''.
 *
 * When the session is area-scoped the department column is too coarse to say
 * what we mean, so the predicate is rewritten onto the sibling area_id column.
 * Every caller passes a column on the employee table ('department_id' or
 * 'e.department_id'), which is what makes the swap safe.
 */
function dept_scope_sql(string $col): string
{
    if (area_scope_active()) {
        return scope_in_sql(str_replace('department_id', 'area_id', $col), area_scope_ids());
    }
    $d = dept_scope_id();
    return $d > 0 ? " AND $col = $d" : '';
}

/**
 * " AND <emp_id_col> IN (SELECT id FROM employee WHERE …)" for tables that only
 * carry employee_id (leave_requests, DTR_details, …).
 */
function dept_scope_emp_sql(string $emp_id_col): string
{
    if (area_scope_active()) {
        $ids = implode(',', array_map('intval', area_scope_ids()));
        return " AND $emp_id_col IN (SELECT id FROM employee WHERE area_id IN ($ids))";
    }
    $d = dept_scope_id();
    return $d > 0 ? " AND $emp_id_col IN (SELECT id FROM employee WHERE department_id = $d)" : '';
}
