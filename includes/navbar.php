<?php
$page = isset($_GET['page']) ? $_GET['page'] : 'home';
// Pending leave-request count for the "Leave Requests" menu pill, scoped the
// same way leaves.php scopes its list (a Department Head sees only their own
// ward's). Wrapped in try so a DB hiccup never breaks the whole sidebar.
$__lv_pending = 0;
if (function_exists('page_allowed') && page_allowed('leaves') && isset($conn)) {
    try {
        require_once __DIR__ . '/../dept-scope.php';
        $__q = $conn->query("SELECT COUNT(*) c FROM leave_requests WHERE status = 0 " . dept_scope_emp_sql('employee_id'));
        if ($__q) $__lv_pending = (int) ($__q->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) { $__lv_pending = 0; }
}
$__lv_pill = $__lv_pending > 0
    ? '<span class="badge rounded-pill bg-warning text-dark ms-auto sb-count" title="' . $__lv_pending . ' pending request' . ($__lv_pending === 1 ? '' : 's') . '">' . ($__lv_pending > 99 ? '99+' : $__lv_pending) . '</span>'
    : '';

// Same idea for "Attendance Requests" (incident reports / OT filing), scoped
// the way attendance-requests.php scopes its own Pending tile.
$__ar_pending = 0;
if (function_exists('page_allowed') && page_allowed('attendance-requests') && isset($conn)) {
    try {
        require_once __DIR__ . '/../dept-scope.php';
        $__q = $conn->query("SELECT COUNT(*) c FROM attendance_requests WHERE status = 0 " . dept_scope_emp_sql('employee_id'));
        if ($__q) $__ar_pending = (int) ($__q->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) { $__ar_pending = 0; }
}
$__ar_pill = $__ar_pending > 0
    ? '<span class="badge rounded-pill bg-warning text-dark ms-auto sb-count" title="' . $__ar_pending . ' pending request' . ($__ar_pending === 1 ? '' : 's') . '">' . ($__ar_pending > 99 ? '99+' : $__ar_pending) . '</span>'
    : '';
?>

<div class="app-menu navbar-menu">
    <!-- LOGO -->
    <div class="navbar-brand-box">
        <a href="home" class="logo logo-dark">
            <span class="logo-sm"><img src="assets2/images/pwa/icon-192.png" alt="COMC" class="brand-mark"></span>
            <span class="logo-lg"><img src="assets2/images/pwa/icon-192.png" alt="COMC" class="brand-mark"><div class="logo">Payroll System</div></span>
        </a>
        <a href="home" class="logo logo-light">
            <span class="logo-sm"><img src="assets2/images/pwa/icon-192.png" alt="COMC" class="brand-mark"></span>
            <span class="logo-lg"><img src="assets2/images/pwa/icon-192.png" alt="COMC" class="brand-mark"><div class="logo">Payroll System</div></span>
        </a>
        <button type="button" class="btn btn-sm p-0 fs-20 header-item float-end btn-vertical-sm-hover" id="vertical-hover">
            <i class="ri-record-circle-line"></i>
        </button>
    </div>

    <div id="scrollbar">
        <div class="container-fluid">
            <div id="two-column-menu"></div>

            <ul class="navbar-nav" id="navbar-nav">

                <?php if (app_is_local()): ?>
                <!-- ═══ OFFLINE PAYROLL MACHINE (APP_ROLE=local) ═══
                     Admin-only box kept off the internet: the menu carries just
                     the two screens that belong here — DTR review and Payroll. -->
                <li class="menu-title"><span>Payroll Workstation</span></li>

                <li class="nav-item">
                    <a href="dtr" class="nav-link menu-link <?= in_array($page, ['dtr','dtr-details']) ? 'active' : '' ?>">
                        <i class="ri-time-line"></i> <span>DTR Review</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="payroll-list" class="nav-link menu-link <?= in_array($page, ['payroll','payroll-list','payroll_items','payroll_calculations']) ? 'active' : '' ?>">
                        <i class="ri-calculator-line"></i> <span>Payroll List</span>
                    </a>
                </li>

                <?php elseif (is_leave_approver($login_role)): ?>
                <!-- ═══ SUPERVISOR / DEPARTMENT HEAD ═══
                     Their own department only: leave they must decide, and the
                     duty roster they plan it against. No DTR, no payroll, no
                     salary.

                     Every item is gated on page_allowed() rather than simply
                     written out. This branch used to be a hardcoded list of
                     three, and when duty-roster was added to
                     LEAVE_APPROVER_ALLOWED_PAGES the page became reachable by
                     URL while no menu item ever appeared — the list and the
                     allowlist had silently drifted apart. Asking the same
                     function index.php asks keeps them in step. -->
                <li class="menu-title"><span>Leave Management</span></li>

                <?php if (page_allowed('leave-dashboard')): ?>
                <li class="nav-item">
                    <a href="leave-dashboard" class="nav-link menu-link <?= $page === 'leave-dashboard' ? 'active' : '' ?>">
                        <i class="ri-dashboard-fill"></i> <span>Leave Dashboard</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (page_allowed('leaves')): ?>
                <li class="nav-item">
                    <a href="leaves" class="nav-link menu-link <?= $page === 'leaves' ? 'active' : '' ?>">
                        <i class="ri-file-list-3-line"></i> <span>Leave Requests</span>
                        <?= $__lv_pill ?>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (page_allowed('calendar')): ?>
                <li class="nav-item">
                    <a href="calendar" class="nav-link menu-link <?= $page === 'calendar' ? 'active' : '' ?>">
                        <i class="ri-calendar-2-line"></i> <span>Holiday Calendar</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (page_allowed('duty-roster')): ?>
                <li class="menu-title"><span>Scheduling</span></li>
                <li class="nav-item">
                    <!-- Full-viewport page of its own, so it is linked directly
                         rather than through index.php's ?page= router. -->
                    <a href="duty-roster.php" class="nav-link menu-link">
                        <i class="ri-calendar-schedule-line"></i> <span>Duty Roster</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php else: ?>

                <?php if ($login_role !== 6 && $login_role !== 7 && !is_timekeeper($login_role)): ?>

                <!-- ===== MENU ===== -->
                <li class="menu-title"><span>Menu</span></li>

                <li class="nav-item">
                    <a class="nav-link menu-link <?= $page === 'home' ? 'active' : '' ?>" href="home">
                        <i class="ri-dashboard-fill"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="daily-board" class="nav-link menu-link <?= $page === 'daily-board' ? 'active' : '' ?>">
                        <i class="ri-dashboard-3-line"></i> <span>Daily Board</span>
                    </a>
                </li>

                <!-- ===== ORGANIZATION ===== -->
                <li class="menu-title"><span>Organization</span></li>

                <li class="nav-item">
                    <a href="employee" class="nav-link menu-link <?= in_array($page, ['employee','employee-details']) ? 'active' : '' ?>">
                        <i class="ri-group-line"></i> <span>Employees</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="department" class="nav-link menu-link <?= $page === 'department' ? 'active' : '' ?>">
                        <i class="ri-building-3-line"></i> <span>Departments</span>
                    </a>
                </li>
                <?php if (page_allowed('area')): ?>
                <li class="nav-item">
                    <a href="area" class="nav-link menu-link <?= $page === 'area' ? 'active' : '' ?>">
                        <i class="ri-node-tree"></i> <span>Areas</span>
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a href="position" class="nav-link menu-link <?= $page === 'position' ? 'active' : '' ?>">
                        <i class="ri-briefcase-4-line"></i> <span>Positions</span>
                    </a>
                </li>

                <!-- ===== SCHEDULING & TIME =====
                     Every link below asks page_allowed() — the same function
                     index.php routes through — so a visible menu item and an
                     openable URL are guaranteed to be the same set. -->
                <?php
                $att_pages  = ['attendance','dtr','dtr-details','biometric-dtr','attendance-requests','punch-logs','similarity-reports'];
                $att_shown  = array_filter($att_pages, 'page_allowed');
                $sched_shown = array_filter(['work-schedules','schedule-roster','duty-roster'], 'page_allowed');
                ?>
                <?php if ($sched_shown || $att_shown): ?>
                <li class="menu-title"><span>Scheduling &amp; Time</span></li>
                <?php endif; ?>

                <?php if (page_allowed('work-schedules')): ?>
                <li class="nav-item">
                    <a href="work-schedules" class="nav-link menu-link <?= $page === 'work-schedules' ? 'active' : '' ?>">
                        <i class="ri-time-line"></i> <span>Work Schedules</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (page_allowed('schedule-roster')): ?>
                <li class="nav-item">
                    <a href="schedule-roster" class="nav-link menu-link <?= $page === 'schedule-roster' ? 'active' : '' ?>">
                        <i class="ri-calendar-todo-line"></i> <span>Shift Roster</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (page_allowed('duty-roster')): ?>
                <li class="nav-item">
                    <!-- Full-viewport page of its own (like dtr-documents.php), so it is
                         linked directly rather than through index.php's ?page= router. -->
                    <a href="duty-roster.php" class="nav-link menu-link">
                        <i class="ri-calendar-schedule-line"></i> <span>Duty Roster</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Attendance sub-menu -->
                <?php if ($att_shown): ?>
                <li class="nav-item">
                    <a class="nav-link menu-link <?= in_array($page, $att_pages) ? 'active' : '' ?>"
                        href="#sidebarAtt" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, $att_pages) ? 'true' : 'false' ?>">
                        <i class="ri-calendar-check-line"></i> <span>Attendance</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, $att_pages) ? 'show' : '' ?>" id="sidebarAtt">
                        <ul class="nav nav-sm flex-column">
                            <?php if (page_allowed('attendance')): ?>
                            <li class="nav-item">
                                <a href="attendance" class="nav-link <?= $page === 'attendance' ? 'active' : '' ?>">
                                    <i class="ri-list-check me-1"></i>Attendance Records
                                    <?php if (hr_readonly_page('attendance') && is_hr($login_role)): ?>
                                        <span class="badge bg-light text-muted border ms-1" style="font-size:9px;">View</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (page_allowed('dtr')): ?>
                            <li class="nav-item">
                                <a href="dtr" class="nav-link <?= in_array($page, ['dtr','dtr-details']) ? 'active' : '' ?>">
                                    <i class="ri-fingerprint-line me-1"></i>DTR Review
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (page_allowed('punch-logs')): ?>
                            <li class="nav-item">
                                <!-- The raw scans behind the DTR — one row per punch, not per day. -->
                                <a href="punch-logs" class="nav-link <?= $page === 'punch-logs' ? 'active' : '' ?>">
                                    <i class="ri-scan-line me-1"></i>Punch Logs
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (page_allowed('similarity-reports')): ?>
                            <li class="nav-item">
                                <!-- Scans the fingerprint scanner flagged as matching 2+ employees. -->
                                <a href="similarity-reports" class="nav-link <?= $page === 'similarity-reports' ? 'active' : '' ?>">
                                    <i class="ri-fingerprint-2-line me-1"></i>Similarity Reports
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (page_allowed('attendance-requests')): ?>
                            <li class="nav-item">
                                <a href="attendance-requests" class="nav-link <?= $page === 'attendance-requests' ? 'active' : '' ?>">
                                    <i class="ri-error-warning-line me-1"></i>Attendance Requests
                                    <?php if (is_hr($login_role)): ?>
                                        <span class="badge bg-light text-muted border ms-1" style="font-size:9px;">View</span>
                                    <?php endif; ?>
                                    <?= $__ar_pill ?>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </li>
                <?php endif; ?>

                <!-- Leave sub-menu -->
                <?php $lv_pages = ['leave-dashboard','leaves','leave_types','leave_balances','leave-balances-report','calendar']; ?>
                <li class="nav-item">
                    <a class="nav-link menu-link <?= in_array($page, $lv_pages) ? 'active' : '' ?>"
                        href="#sidebarLeave" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, $lv_pages) ? 'true' : 'false' ?>">
                        <i class="ri-calendar-event-line"></i> <span>Leave Management</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, $lv_pages) ? 'show' : '' ?>" id="sidebarLeave">
                        <ul class="nav nav-sm flex-column">
                            <li class="nav-item">
                                <a href="leave-dashboard" class="nav-link <?= $page === 'leave-dashboard' ? 'active' : '' ?>">
                                    <i class="ri-dashboard-3-line me-1"></i>Leave Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="leaves" class="nav-link <?= $page === 'leaves' ? 'active' : '' ?>">
                                    <i class="ri-file-list-3-line me-1"></i>Leave Requests
                                    <?= $__lv_pill ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="leave_balances" class="nav-link <?= $page === 'leave_balances' ? 'active' : '' ?>">
                                    <i class="ri-coins-line me-1"></i>Leave Balances
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="leave-balances-report" class="nav-link <?= $page === 'leave-balances-report' ? 'active' : '' ?>">
                                    <i class="ri-bar-chart-box-line me-1"></i>Leave Balances Report
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="calendar" class="nav-link <?= $page === 'calendar' ? 'active' : '' ?>">
                                    <i class="ri-calendar-2-line me-1"></i>Holiday Calendar
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="leave_types" class="nav-link <?= $page === 'leave_types' ? 'active' : '' ?>">
                                    <i class="ri-settings-4-line me-1"></i>Leave Types
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- ===== PAYROLL =====
                     Hidden whole for anyone outside the pay slice (HR included):
                     no batches, no pay settings, no 13th month, no loans, no
                     benefits. -->
                <?php
                $pay_pages  = ['payroll','pay-settings','thirteenth-month','loans','contributions','deductions','refunds'];
                $pay_shown  = array_filter($pay_pages, 'page_allowed');
                $ben_pages  = ['contributions','deductions','refunds'];
                $ben_shown  = array_filter($ben_pages, 'page_allowed');
                ?>
                <?php if ($pay_shown): ?>
                <li class="menu-title"><span>Payroll</span></li>
                <?php endif; ?>

                <?php if (page_allowed('payroll')): ?>
                <li class="nav-item">
                    <a class="nav-link menu-link <?= (in_array($page, ['payroll','payroll-list','payroll_items','payroll_calculations']) && (!isset($_GET['p2']) || $_GET['p2'] === 'false')) ? 'active' : '' ?>"
                        href="payroll-list">
                        <i class="ri-calculator-line"></i> <span>Payroll List</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (page_allowed('pay-settings')): ?>
                <li class="nav-item">
                    <a href="pay-settings" class="nav-link menu-link <?= $page === 'pay-settings' ? 'active' : '' ?>">
                        <i class="ri-money-dollar-circle-line"></i> <span>Pay Settings</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (page_allowed('thirteenth-month')): ?>
                <li class="nav-item">
                    <a href="index.php?page=thirteenth-month" class="nav-link menu-link <?= $page === 'thirteenth-month' ? 'active' : '' ?>">
                        <i class="ri-hand-coin-line"></i> <span>13th Month Pay</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Loan management (Payroll Comparison & Remittance now live under Reports) -->
                <?php if (page_allowed('loans')): ?>
                <li class="nav-item">
                    <a href="loans" class="nav-link menu-link <?= $page === 'loans' ? 'active' : '' ?>">
                        <i class="ri-bank-line"></i> <span>Active Loans</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Benefits & Compensation sub-menu -->
                <?php if ($ben_shown): ?>
                <li class="nav-item">
                    <a class="nav-link menu-link" href="#sidebarBenefits" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, $ben_pages) ? 'true' : 'false' ?>">
                        <i class="ri-gift-line"></i> <span>Benefits &amp; Compensation</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, $ben_pages) ? 'show' : '' ?>" id="sidebarBenefits">
                        <ul class="nav nav-sm flex-column">
                            <?php if (page_allowed('contributions')): ?>
                            <li class="nav-item">
                                <a href="contributions" class="nav-link <?= $page === 'contributions' ? 'active' : '' ?>">
                                    <i class="ri-hand-coin-line me-1"></i>Contributions
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (page_allowed('deductions')): ?>
                            <li class="nav-item">
                                <a href="deductions" class="nav-link <?= $page === 'deductions' ? 'active' : '' ?>">
                                    <i class="ri-subtract-line me-1"></i>Deductions
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php /* Refunds — hidden
                            <?php if (page_allowed('refunds')): ?>
                            <li class="nav-item">
                                <a href="refunds" class="nav-link <?= $page === 'refunds' ? 'active' : '' ?>">
                                    <i class="ri-refund-2-line me-1"></i>Refunds
                                </a>
                            </li>
                            <?php endif; ?>
                            */ ?>
                        </ul>
                    </div>
                </li>
                <?php endif; ?>

                <!-- ===== REPORTS ===== -->
                <?php
                $rep_acct = ['payroll-register','loan-deduction-ledger','remittance-report','payroll-comparison','payroll-report','bank-payout','bir-alphalist'];
                $rep_hris = ['employee-masterlist','attendance-summary'];
                $rep_all  = array_merge(['reports'], $rep_acct, $rep_hris);
                $rep_shown = array_filter($rep_all, 'page_allowed');
                ?>
                <?php if ($rep_shown): ?>
                <li class="menu-title"><span>Reports</span></li>
                <?php endif; ?>
                <?php if (page_allowed('reports')): ?>
                <li class="nav-item">
                    <a href="index.php?page=reports" class="nav-link menu-link <?= $page === 'reports' ? 'active' : '' ?>">
                        <i class="ri-bar-chart-box-line"></i> <span>All Reports</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (array_filter($rep_acct, 'page_allowed')): ?>
                <li class="nav-item">
                    <a class="nav-link menu-link <?= in_array($page, $rep_acct) ? 'active' : '' ?>"
                        href="#sidebarAcctReports" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, $rep_acct) ? 'true' : 'false' ?>">
                        <i class="ri-calculator-line"></i> <span>Accounting</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, $rep_acct) ? 'show' : '' ?>" id="sidebarAcctReports">
                        <ul class="nav nav-sm flex-column">
                            <li class="nav-item"><a href="payroll-register" class="nav-link <?= $page === 'payroll-register' ? 'active' : '' ?>"><i class="ri-file-list-3-line me-1"></i>Payroll Register</a></li>
                            <li class="nav-item"><a href="loan-deduction-ledger" class="nav-link <?= $page === 'loan-deduction-ledger' ? 'active' : '' ?>"><i class="ri-bank-card-line me-1"></i>Loan &amp; Deduction Ledger</a></li>
                            <li class="nav-item"><a href="remittance-report" class="nav-link <?= $page === 'remittance-report' ? 'active' : '' ?>"><i class="ri-government-line me-1"></i>Remittance Report</a></li>
                            <li class="nav-item"><a href="index.php?page=bank-payout" class="nav-link <?= $page === 'bank-payout' ? 'active' : '' ?>"><i class="ri-bank-line me-1"></i>Bank Payout</a></li>
                            <li class="nav-item"><a href="index.php?page=bir-alphalist" class="nav-link <?= $page === 'bir-alphalist' ? 'active' : '' ?>"><i class="ri-file-shield-2-line me-1"></i>BIR Alphalist</a></li>
                            <li class="nav-item"><a href="payroll-comparison" class="nav-link <?= $page === 'payroll-comparison' ? 'active' : '' ?>"><i class="ri-arrow-left-right-line me-1"></i>Payroll Comparison</a></li>
                            <li class="nav-item"><a href="payroll-report" class="nav-link <?= $page === 'payroll-report' ? 'active' : '' ?>"><i class="ri-list-check-2 me-1"></i>Payroll List</a></li>
                        </ul>
                    </div>
                </li>
                <?php endif; ?>
                <?php if (array_filter($rep_hris, 'page_allowed')): ?>
                <li class="nav-item">
                    <a class="nav-link menu-link <?= in_array($page, $rep_hris) ? 'active' : '' ?>"
                        href="#sidebarHrisReports" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, $rep_hris) ? 'true' : 'false' ?>">
                        <i class="ri-team-line"></i> <span>HRIS</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, $rep_hris) ? 'show' : '' ?>" id="sidebarHrisReports">
                        <ul class="nav nav-sm flex-column">
                            <li class="nav-item"><a href="employee-masterlist" class="nav-link <?= $page === 'employee-masterlist' ? 'active' : '' ?>"><i class="ri-team-line me-1"></i>Employee Masterlist</a></li>
                            <li class="nav-item"><a href="attendance-summary" class="nav-link <?= $page === 'attendance-summary' ? 'active' : '' ?>"><i class="ri-time-line me-1"></i>Attendance Summary</a></li>
                        </ul>
                    </div>
                </li>
                <?php endif; ?>

                <!-- ===== SYSTEM ===== -->
                <?php if (page_allowed('users')): ?>
                <li class="menu-title"><span>System</span></li>
                <?php endif; ?>

                <?php /* Biometric Sites — hidden
                <?php if (page_allowed('sites')): ?>
                <li class="nav-item">
                    <a href="sites" class="nav-link menu-link <?= $page === 'sites' ? 'active' : '' ?>">
                        <i class="ri-fingerprint-line"></i> <span>Biometric Sites</span>
                    </a>
                </li>
                <?php endif; ?>
                */ ?>

                <?php if (page_allowed('users')): ?>
                <?php /* Visitors Logs — hidden
                <li class="nav-item">
                    <a class="nav-link menu-link <?= $page === 'visitors-logs' ? 'active' : '' ?>" href="visitors-logs">
                        <i class="ri-user-search-line"></i> <span>Visitors Logs</span>
                    </a>
                </li>
                */ ?>
                <li class="nav-item">
                    <a class="nav-link menu-link <?= $page === 'users' ? 'active' : '' ?>" href="users">
                        <i class="ri-shield-user-line"></i> <span>User Management</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php endif; ?>

                <!-- Role 5 (Timekeeper) — scanner operator. Two screens only:
                     the employee attendance report and the employee list (whose
                     detail page shows just the enrolled fingerprints). -->
                <?php if (is_timekeeper($login_role)): ?>
                <li class="menu-title"><span>Menu</span></li>
                <li class="nav-item">
                    <a href="index.php?page=attendance-summary" class="nav-link menu-link <?= $page === 'attendance-summary' ? 'active' : '' ?>">
                        <i class="ri-time-line"></i> <span>Attendance Report</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="employee" class="nav-link menu-link <?= in_array($page, ['employee','employee-details']) ? 'active' : '' ?>">
                        <i class="ri-group-line"></i> <span>Employees</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Role 6 (PIC) -->
                <?php if ($login_role === 6 && page_allowed('dtr')): ?>
                <li class="menu-title"><span>Menu</span></li>
                <li class="nav-item">
                    <a class="nav-link menu-link" href="#sidebarAttendance6" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, ['attendance','dtr','dtr-details']) ? 'true' : 'false' ?>">
                        <i class="ri-calendar-line"></i> <span>Time & Attendance</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, ['attendance','dtr','dtr-details']) ? 'show' : '' ?>" id="sidebarAttendance6">
                        <ul class="nav nav-sm flex-column">
                            <li class="nav-item">
                                <a href="dtr" class="nav-link <?= in_array($page, ['dtr','dtr-details']) ? 'active' : '' ?>">
                                    <i class="ri-time-line me-1"></i>Daily Time Record
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                <?php endif; ?>

                <!-- Role 7 (Auditor) -->
                <?php if ($login_role === 7): ?>
                <li class="menu-title"><span>Menu</span></li>
                <li class="nav-item">
                    <a class="nav-link menu-link <?= $page === 'home' ? 'active' : '' ?>" href="home">
                        <i class="ri-dashboard-fill"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link menu-link" href="#sidebarAttendance7" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, ['attendance','dtr','dtr-details']) ? 'true' : 'false' ?>">
                        <i class="ri-calendar-line"></i> <span>Time & Attendance</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, ['attendance','dtr','dtr-details']) ? 'show' : '' ?>" id="sidebarAttendance7">
                        <ul class="nav nav-sm flex-column">
                            <?php if (page_allowed('dtr')): ?>
                            <li class="nav-item">
                                <a href="dtr" class="nav-link <?= in_array($page, ['dtr','dtr-details']) ? 'active' : '' ?>">
                                    <i class="ri-time-line me-1"></i>Daily Time Record
                                </a>
                            </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a href="attendance" class="nav-link <?= $page === 'attendance' ? 'active' : '' ?>">
                                    <i class="ri-calendar-check-line me-1"></i>Attendance Record
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                <?php endif; ?>

                <?php endif; /* APP_ROLE=local menu switch */ ?>

            </ul>
        </div>
    </div>

    <div class="sidebar-background"></div>
</div>
