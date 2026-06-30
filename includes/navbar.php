<?php $page = isset($_GET['page']) ? $_GET['page'] : 'home'; ?>

<div class="app-menu navbar-menu">
    <!-- LOGO -->
    <div class="navbar-brand-box">
        <a href="home" class="logo logo-dark">
            <span class="logo-sm">JP</span>
            <span class="logo-lg"><img src="assets/images/logo-dark.png" alt="" height="17"></span>
        </a>
        <a href="home" class="logo logo-light">
            <span class="logo-sm">JP</span>
            <span class="logo-lg"><div class="logo">JEJORS Payroll</div></span>
        </a>
        <button type="button" class="btn btn-sm p-0 fs-20 header-item float-end btn-vertical-sm-hover" id="vertical-hover">
            <i class="ri-record-circle-line"></i>
        </button>
    </div>

    <div id="scrollbar">
        <div class="container-fluid">
            <div id="two-column-menu"></div>

            <ul class="navbar-nav" id="navbar-nav">

                <?php if ($login_role !== 6 && $login_role !== 7): ?>
                <li class="menu-title"><span>Menu</span></li>

                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link menu-link <?= $page === 'home' ? 'active' : '' ?>" href="home">
                        <i class="ri-dashboard-fill"></i> <span>Dashboard</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="employee" class="nav-link <?= in_array($page, ['employee','employee-details']) ? 'active' : '' ?>">
                        <i class="ri-group-line"></i> <span>Employees</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="department" class="nav-link <?= $page === 'department' ? 'active' : '' ?>">
                        <i class="ri-building-3-line"></i> <span>Department</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="position" class="nav-link <?= $page === 'position' ? 'active' : '' ?>">
                        <i class="ri-briefcase-4-line"></i> <span>Position</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="work-schedules" class="nav-link <?= $page === 'work-schedules' ? 'active' : '' ?>">
                        <i class="ri-time-line"></i> <span>Work Schedules</span>
                    </a>
                </li>
                <!-- <li class="nav-item">
                    <a href="branch" class="nav-link <?= $page === 'branch' ? 'active' : '' ?>">
                        <i class="ri-building-2-line"></i> <span>Branches</span>
                    </a>
                </li> -->

                <!-- Payroll -->
                <li class="nav-item">
                    <a class="nav-link menu-link <?= (in_array($page, ['payroll','payroll_items','payroll_calculations']) && (!isset($_GET['p2']) || $_GET['p2'] === 'false')) ? 'active' : '' ?>"
                        href="payroll?p2=false">
                        <i class="ri-calculator-line"></i> <span>Payroll</span>
                    </a>
                </li>

                <!-- Payroll tools sub-menu -->
                <?php $pt_pages = ['payroll-comparison','loans','remittance-report']; ?>
                <li class="nav-item">
                    <a class="nav-link menu-link <?= in_array($page, $pt_pages) ? 'active' : '' ?>"
                        href="#sidebarPayrollTools" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, $pt_pages) ? 'true' : 'false' ?>">
                        <i class="ri-tools-line"></i> <span>Payroll Tools</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, $pt_pages) ? 'show' : '' ?>" id="sidebarPayrollTools">
                        <ul class="nav nav-sm flex-column">
                            <li class="nav-item">
                                <a href="payroll-comparison" class="nav-link <?= $page === 'payroll-comparison' ? 'active' : '' ?>">
                                    <i class="ri-arrow-left-right-line me-1"></i>Payroll Comparison
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="loans" class="nav-link <?= $page === 'loans' ? 'active' : '' ?>">
                                    <i class="ri-bank-line me-1"></i>Active Loans
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="remittance-report" class="nav-link <?= $page === 'remittance-report' ? 'active' : '' ?>">
                                    <i class="ri-government-line me-1"></i>Remittance Report
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                <!-- <li class="nav-item">
                    <a class="nav-link menu-link <?= (in_array($page, ['payroll','payroll_items','payroll_calculations']) && isset($_GET['p2']) && $_GET['p2'] === 'true') ? 'active' : '' ?>"
                        href="payroll?p2=true">
                        <i class="ri-calculator-line"></i> <span>P2</span>
                    </a>
                </li> -->

                <!-- Benefits & Compensation -->
                <li class="nav-item">
                    <a class="nav-link menu-link" href="#sidebarBenefits" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, ['deductions','contributions','refunds']) ? 'true' : 'false' ?>">
                        <i class="ri-gift-line"></i> <span>Benefits & Compensation</span>
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

                <!-- Time & Attendance -->
                <li class="nav-item">
                    <a class="nav-link menu-link" href="#sidebarAttendance" data-bs-toggle="collapse" role="button"
                        aria-expanded="<?= in_array($page, ['attendance','dtr','dtr-details']) ? 'true' : 'false' ?>">
                        <i class="ri-calendar-line"></i> <span>Time & Attendance</span>
                    </a>
                    <div class="menu-dropdown collapse <?= in_array($page, ['attendance','dtr','dtr-details']) ? 'show' : '' ?>" id="sidebarAttendance">
                        <ul class="nav nav-sm flex-column">
                            <li class="nav-item">
                                <a href="attendance" class="nav-link <?= $page === 'attendance' ? 'active' : '' ?>">
                                    <i class="ri-calendar-check-line me-1"></i>Attendance Record
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Leave Management -->
                <?php $lv_pages = ['leaves','leave_types','leave_balances','calendar']; ?>
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

                <!-- Projects -->
                <!-- <li class="nav-item">
                    <a href="clusters" class="nav-link menu-link <?= $page === 'clusters' ? 'active' : '' ?>">
                        <i class="ri-global-line"></i> <span>Clusters</span>
                    </a>
                </li> -->
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
                <!-- Users -->
                <li class="nav-item">
                    <a class="nav-link menu-link <?= $page === 'users' ? 'active' : '' ?>" href="users">
                        <i class="ri-shield-user-line"></i> <span>User Management</span>
                    </a>
                </li>
                <?php endif; ?>

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

            </ul>
        </div>
    </div>

    <div class="sidebar-background"></div>
</div>
