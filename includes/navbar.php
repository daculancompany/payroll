<?php $page = isset($_GET['page']) ? $_GET['page'] : 'home'; ?>

<div class="app-menu navbar-menu">
    <!-- LOGO -->
    <div class="navbar-brand-box">
        <a href="home" class="logo logo-dark">
            <span class="logo-sm">HR</span>
            <span class="logo-lg"><div class="logo">Payroll System</div></span>
        </a>
        <a href="home" class="logo logo-light">
            <span class="logo-sm">HR</span>
            <span class="logo-lg"><div class="logo">Payroll System</div></span>
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
                    <a href="payroll?p2=false" class="nav-link menu-link <?= in_array($page, ['payroll','payroll_items','payroll_calculations']) ? 'active' : '' ?>">
                        <i class="ri-calculator-line"></i> <span>Payroll</span>
                    </a>
                </li>

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
                <li class="nav-item">
                    <a href="position" class="nav-link menu-link <?= $page === 'position' ? 'active' : '' ?>">
                        <i class="ri-briefcase-4-line"></i> <span>Positions</span>
                    </a>
                </li>

                <!-- ===== SCHEDULING & TIME ===== -->
                <li class="menu-title"><span>Scheduling &amp; Time</span></li>

                <li class="nav-item">
                    <a href="work-schedules" class="nav-link menu-link <?= $page === 'work-schedules' ? 'active' : '' ?>">
                        <i class="ri-time-line"></i> <span>Work Schedules</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="schedule-roster" class="nav-link menu-link <?= $page === 'schedule-roster' ? 'active' : '' ?>">
                        <i class="ri-calendar-todo-line"></i> <span>Shift Roster</span>
                    </a>
                </li>

                <!-- Attendance sub-menu -->
                <?php $att_pages = ['attendance','dtr','dtr-details','biometric-dtr','attendance-requests']; ?>
                <li class="nav-item">
                    <a class="nav-link menu-link <?= in_array($page, $att_pages) ? 'active' : '' ?>"
                        href="#sidebarAtt" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, $att_pages) ? 'true' : 'false' ?>">
                        <i class="ri-calendar-check-line"></i> <span>Attendance</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, $att_pages) ? 'show' : '' ?>" id="sidebarAtt">
                        <ul class="nav nav-sm flex-column">
                            <li class="nav-item">
                                <a href="attendance" class="nav-link <?= $page === 'attendance' ? 'active' : '' ?>">
                                    <i class="ri-list-check me-1"></i>Attendance Records
                                </a>
                            </li>
                            <?php if (in_array($login_role, [1, 8, 9], true)): ?>
                            <li class="nav-item">
                                <a href="dtr" class="nav-link <?= in_array($page, ['dtr','dtr-details']) ? 'active' : '' ?>">
                                    <i class="ri-fingerprint-line me-1"></i>DTR Review
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="attendance-requests" class="nav-link <?= $page === 'attendance-requests' ? 'active' : '' ?>">
                                    <i class="ri-error-warning-line me-1"></i>Attendance Requests
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </li>

                <!-- Leave sub-menu -->
                <?php $lv_pages = ['leaves','leave_types','leave_balances','leave-balances-report','calendar']; ?>
                <li class="nav-item">
                    <a class="nav-link menu-link <?= in_array($page, $lv_pages) ? 'active' : '' ?>"
                        href="#sidebarLeave" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, $lv_pages) ? 'true' : 'false' ?>">
                        <i class="ri-calendar-event-line"></i> <span>Leave Management</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, $lv_pages) ? 'show' : '' ?>" id="sidebarLeave">
                        <ul class="nav nav-sm flex-column">
                            <li class="nav-item">
                                <a href="leaves" class="nav-link <?= $page === 'leaves' ? 'active' : '' ?>">
                                    <i class="ri-file-list-3-line me-1"></i>Leave Requests
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

                <!-- ===== PAYROLL ===== -->
                <li class="menu-title"><span>Payroll</span></li>

                <li class="nav-item">
                    <a class="nav-link menu-link <?= (in_array($page, ['payroll','payroll_items','payroll_calculations']) && (!isset($_GET['p2']) || $_GET['p2'] === 'false')) ? 'active' : '' ?>"
                        href="payroll?p2=false">
                        <i class="ri-calculator-line"></i> <span>Payroll</span>
                    </a>
                </li>
                <?php if (in_array($login_role, [1, 8, 9], true)): ?>
                <li class="nav-item">
                    <a href="pay-settings" class="nav-link menu-link <?= $page === 'pay-settings' ? 'active' : '' ?>">
                        <i class="ri-money-dollar-circle-line"></i> <span>Pay Settings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="index.php?page=thirteenth-month" class="nav-link menu-link <?= $page === 'thirteenth-month' ? 'active' : '' ?>">
                        <i class="ri-hand-coin-line"></i> <span>13th Month Pay</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Loan management (Payroll Comparison & Remittance now live under Reports) -->
                <li class="nav-item">
                    <a href="loans" class="nav-link menu-link <?= $page === 'loans' ? 'active' : '' ?>">
                        <i class="ri-bank-line"></i> <span>Active Loans</span>
                    </a>
                </li>

                <!-- Benefits & Compensation sub-menu -->
                <li class="nav-item">
                    <a class="nav-link menu-link" href="#sidebarBenefits" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, ['deductions','contributions','refunds']) ? 'true' : 'false' ?>">
                        <i class="ri-gift-line"></i> <span>Benefits &amp; Compensation</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, ['deductions','contributions','refunds']) ? 'show' : '' ?>" id="sidebarBenefits">
                        <ul class="nav nav-sm flex-column">
                            <li class="nav-item">
                                <a href="contributions" class="nav-link <?= $page === 'contributions' ? 'active' : '' ?>">
                                    <i class="ri-hand-coin-line me-1"></i>Contributions
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="deductions" class="nav-link <?= $page === 'deductions' ? 'active' : '' ?>">
                                    <i class="ri-subtract-line me-1"></i>Deductions
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="refunds" class="nav-link <?= $page === 'refunds' ? 'active' : '' ?>">
                                    <i class="ri-refund-2-line me-1"></i>Refunds
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- ===== REPORTS ===== -->
                <?php
                $rep_acct = ['payroll-register','loan-deduction-ledger','remittance-report','payroll-comparison','payroll-report','bank-payout','bir-alphalist'];
                $rep_hris = ['employee-masterlist','attendance-summary'];
                $rep_all  = array_merge(['reports'], $rep_acct, $rep_hris);
                ?>
                <li class="menu-title"><span>Reports</span></li>
                <li class="nav-item">
                    <a href="index.php?page=reports" class="nav-link menu-link <?= $page === 'reports' ? 'active' : '' ?>">
                        <i class="ri-bar-chart-box-line"></i> <span>All Reports</span>
                    </a>
                </li>
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

                <!-- ===== SYSTEM ===== -->
                <li class="menu-title"><span>System</span></li>

                <li class="nav-item">
                    <a href="sites" class="nav-link menu-link <?= $page === 'sites' ? 'active' : '' ?>">
                        <i class="ri-fingerprint-line"></i> <span>Biometric Sites</span>
                    </a>
                </li>

                <?php if ($login_role !== 4): ?>
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
                <?php if ($login_role === 6): ?>
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
                            <li class="nav-item">
                                <a href="dtr" class="nav-link <?= in_array($page, ['dtr','dtr-details']) ? 'active' : '' ?>">
                                    <i class="ri-time-line me-1"></i>Daily Time Record
                                </a>
                            </li>
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
