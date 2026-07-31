<!doctype html>
<html lang="en" data-layout="vertical" data-topbar="light" data-sidebar="light" data-sidebar-size="lg" data-sidebar-image="none" data-theme="default" data-theme-colors="default">

<head>

    <meta charset="utf-8" />
<?php
/* Per-page <title>.
 *
 * Every admin screen used to render the same literal "Payroll System", which
 * made browser tabs indistinguishable (payroll work routinely means half a
 * dozen open at once) and gave screen-reader users no page announcement on
 * navigation. index.php routes everything through ?page=<slug>, so deriving the
 * title from that slug fixes all of them in one place.
 *
 * A page can still override it by setting $page_title before the include.
 */
if (!isset($page_title)) {
    $__slug = (string) ($_GET['page'] ?? 'home');
    // Only ever a routing slug; never trust it into the document unescaped.
    $__slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $__slug);

    $__titles = [
        'home'                 => 'Dashboard',
        'dtr'                  => 'DTR',
        'dtr-details'          => 'DTR Details',
        'dtr-documents'        => 'DTR Documents',
        'compare-dtr'          => 'Compare DTR',
        'biometric-dtr'        => 'Biometric DTR',
        'payroll'              => 'Payroll',
        'payroll_items'        => 'Payroll Items',
        'payroll-register'     => 'Payroll Register',
        'payroll-report'       => 'Payroll Report',
        'payroll-comparison'   => 'Payroll Comparison',
        'bir-alphalist'        => 'BIR Alphalist',
        'bank-payout'          => 'Bank Payout',
        'thirteenth-month'     => '13th Month Pay',
        'leave_types'          => 'Leave Types',
        'leave_balances'       => 'Leave Balances',
        'leaves'               => 'Leave Requests',
        'leave-dashboard'      => 'Leave Dashboard',
        'leave-balances-report' => 'Leave Balances Report',
        'employee'             => 'Employees',
        'employee-details'     => 'Employee Details',
        'employee-masterlist'  => 'Employee Masterlist',
        'schedule-roster'      => 'Schedule Roster',
        'work-schedules'       => 'Work Schedules',
        'attendance'           => 'Attendance',
        'attendance-summary'   => 'Attendance Summary',
        'attendance-requests'  => 'Attendance Requests',
        'daily-board'          => 'Daily Board',
        'visitors-logs'        => 'Visitor Logs',
        'remittance-report'    => 'Remittance Report',
        'loan-deduction-ledger' => 'Loan Deduction Ledger',
        'pay-settings'         => 'Pay Settings',
        'users'                => 'Users',
        'sites'                => 'Sites',
        'clusters'             => 'Clusters',
        'branch'               => 'Branches',
        'department'           => 'Departments',
        'position'             => 'Positions',
        'calendar'             => 'Calendar & Holidays',
        'reports'              => 'Reports',
        'profile'              => 'My Profile',
    ];

    // Fall back to humanising the slug so a page added later still gets a
    // sensible title without touching this map.
    $page_title = $__titles[$__slug]
        ?? ucwords(str_replace(['-', '_'], ' ', $__slug ?: 'Dashboard'));
}
?>
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?> &middot; Payroll System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Payroll System" name="description" />
    <!-- Internal tool: never index payroll data, even if a box is reachable. -->
    <meta name="robots" content="noindex, nofollow" />

    <!-- CSRF: the token every non-GET request must echo back. csrf.js reads this
         tag and attaches it to all fetch() calls, so it must load first. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
    <script src="assets2/js/csrf.js"></script>
    <meta content="Niel Daculan" name="author" />
    <!-- App favicon -->
    <link rel="shortcut icon" href="assets/images/favicon.ico">

    <!-- ── PWA: installable admin app (separate manifest from the employee portal) ── -->
    <link rel="manifest" href="manifest-admin.webmanifest">
    <meta name="theme-color" content="#6642aa">
    <!-- iOS ignores the manifest; it reads these tags on "Add to Home Screen" -->
    <link rel="apple-touch-icon" href="assets2/images/pwa/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="COMC Payroll">
    <!-- Must load before the SW registration below so it catches
         beforeinstallprompt, which fires once and isn't replayed. -->
    <script src="assets2/js/pwa-install.js"></script>
    <script>
        // Registers the same root-scoped SW the portal uses; its no-op fetch
        // handler is what makes Chrome offer "Install app". HTTPS/localhost only.
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('firebase-messaging-sw.js')
                    .catch(function (e) {
                        console.warn('[PWA] SW registration failed:', e);
                        if (window.pwaNoteSwError) window.pwaNoteSwError(e.message || e);
                    });
            });
        }
    </script>

    <!-- Layout config Js -->
    <!-- <script src="assets/js/layout.js"></script> -->
    <!-- Bootstrap Css -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
    <!-- Icons Css -->
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <!-- App Css-->
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <!-- custom Css-->
    <link href="assets/css/custom.min.css" rel="stylesheet" type="text/css" />

    <!-- Bootstrap-Select CSS (replaces Select2) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet" />

    <!--datatable css-->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" />
    <!--datatable responsive css-->
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.2.9/css/responsive.bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

    <link rel="stylesheet" href="assets2/vendor/toastr/toastr.min.css">

    <!-- GLOBAL theme tokens (primary purple + every scrollbar in the app).
         Must load before reports.css / page styles so those can read the vars. -->
    <link rel="stylesheet" href="<?= av('assets2/css/theme.css') ?>">

    <!-- Shared soft-style report tables -->
    <link rel="stylesheet" href="<?= av('assets2/css/reports.css') ?>">

    <!-- Keeps SweetAlert dialogs above Bootstrap modals -->
    <link rel="stylesheet" href="assets2/css/modal-stacking.css">

    <!-- Shared read-only employee quick-view drawer: any element with
         data-emp-view="<employee_id>" opens it (see assets2/js/employee-view.js) -->
    <script src="<?= av('assets2/js/employee-view.js') ?>" defer></script>
    <style>
        /* Uniform DataTables empty-state (used by table.dataTables_empty cells) */
        td.dataTables_empty {
            padding: 0 !important;
        }
        .dt-empty-state {
            padding: 40px 16px;
            text-align: center;
            color: #6c757d;
        }
        .dt-empty-state .dt-empty-ic {
            width: 64px;
            height: 64px;
            margin: 0 auto 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(103, 59, 182, 0.10);
            color: #673bb6;
            font-size: 30px;
        }
        .dt-empty-state .dt-empty-ic.dt-empty-ic-search {
            background: rgba(108, 117, 125, 0.12);
            color: #6c757d;
        }
        .dt-empty-state .dt-empty-ic i {
            background: transparent !important;
            color: inherit !important;
            padding: 0 !important;
            font-size: inherit !important;
        }
        .dt-empty-state .dt-empty-title {
            margin: 0 0 4px;
            font-size: 15px;
            font-weight: 600;
            color: #495057;
        }
        .dt-empty-state .dt-empty-sub {
            font-size: 12.5px;
            color: #98a0a8;
        }

        .parsley-errors-list {
            color: #f36363;
            margin-top: 0px !important;
            list-style: none !important;
            padding: 0 !important;
        }



        .modal form .form-group {
            padding-bottom: 10px;

        }

        .pull-right {
            display: flex;
            align-items: flex-end;
            justify-content: flex-end;
            margin-bottom: 12px;
        }

        .site-wapper i {
            background: #E91E63;
            color: #fff;
            font-size: 14px;
            border-radius: 4px;
            padding: 2px;
        }

        .table-responsive {
            overflow: unset !important;
        }

        .card {
            padding: 0px;
        }

        .modal-title {
            font-size: 17px;
        }

        .logo {
            color: #fff;
            font-size: 20px;
        }

        .text-right {
            text-align: right;
        }

        .action-buttons {
            text-align: center;
            display: flex;
            gap: 5px;
            justify-content: center;
        }

        .logo-lg {
            color: #000;
        }

        .logo-sm {
            /* height: 30px  !important;
            width: 30px  !important; */
            /* background: #ffffff ; */
            border-radius: 50% !important;
            display: flex;
            align-items: center !important;
            justify-content: center !important;
            /* color: red  !important; */
            /* margin-top: 14px; */
            font-size: 15px;
        }

        /* .table-hover tbody tr:hover {
            background-color: #f8f9fa;
            transform: scale(1.01);
            transition: all 0.2s ease;
        } */

        /* ===== Clean white sidebar + solid purple active menu =====
           --sb-primary / --sb-primary-soft now live in assets2/css/theme.css
           (aliased to --app-primary). Change the colour there, not here. */

        .navbar-menu {
            background: #ffffff;
            border-right: 1px solid #eef0f2;
        }

        /* Solid purple logo bar */
        .navbar-menu .navbar-brand-box {
            background: var(--sb-primary);
            border-bottom: 1px solid var(--sb-primary);
        }

        .navbar-menu .logo-light {
            display: inline-block;
        }

        .navbar-menu .logo-light .logo,
        .navbar-menu .navbar-brand-box .logo-sm,
        .navbar-menu .navbar-brand-box .logo-lg .logo {
            color: #ffffff;
            font-weight: 600;
        }

        /* Collapse toggle button on the purple bar */
        .navbar-menu .navbar-brand-box #vertical-hover,
        .navbar-menu .navbar-brand-box #vertical-hover i {
            color: #ffffff;
        }

        /* Section labels */
        #navbar-nav .menu-title {
            color: #9aa2ab;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-size: 10.5px;
            font-weight: 600;
            padding: 16px 24px 6px;
        }

        /* Menu links */
        #navbar-nav .nav-link.menu-link,
        #navbar-nav .menu-dropdown .nav-link {
            color: #4b5563;
            border-radius: 6px;
            margin: 2px 12px;
            padding: 9px 12px;
            transition: background-color .15s ease, color .15s ease;
        }

        #navbar-nav .nav-link.menu-link i,
        #navbar-nav .menu-dropdown .nav-link i {
            color: #98a0ac;
            transition: color .15s ease;
        }

        /* Hover */
        #navbar-nav .nav-link.menu-link:hover,
        #navbar-nav .menu-dropdown .nav-link:hover {
            background: var(--sb-primary-soft);
            color: var(--sb-primary);
        }

        #navbar-nav .nav-link.menu-link:hover i,
        #navbar-nav .menu-dropdown .nav-link:hover i {
            color: var(--sb-primary);
        }

        /* Solid active state */
        #navbar-nav .nav-link.menu-link.active,
        #navbar-nav .menu-dropdown .nav-link.active {
            background: var(--sb-primary) !important;
            color: #ffffff !important;
            font-weight: 500;
            box-shadow: 0 2px 6px rgba(103, 59, 182, .28);
        }

        #navbar-nav .nav-link.menu-link.active i,
        #navbar-nav .menu-dropdown .nav-link.active i {
            color: #ffffff !important;
        }

        /* ===== Minimized (collapsed) sidebar fixes =====
           The Velzon template forces the brand box + flyout to --vz-vertical-menu-bg
           (a near-white), which killed our purple bar and left the hover menu washed out.
           [data-sidebar-size^="sm"] covers sm / sm-hover / sm-hover-active. */

        /* Keep the logo bar purple when collapsed, and only show the compact "HR" mark */
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-brand-box {
            background: var(--sb-primary) !important;
            border-bottom-color: var(--sb-primary) !important;
        }
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-brand-box .logo-lg {
            display: none !important;
        }
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-brand-box .logo-sm {
            display: inline-block !important;
            color: #ffffff !important;
        }

        /* Hovered parent item (the expanding row) — solid purple, readable label + icon */
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .nav-item:hover > a.menu-link,
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .nav-item:hover > a.menu-link span,
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .nav-item:hover > a.menu-link i,
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .nav-item:hover > a.menu-link:after {
            background-color: var(--sb-primary) !important;
            color: #ffffff !important;
        }

        /* Flyout submenu popup — solid white card instead of see-through */
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .nav-item:hover > .menu-dropdown {
            background: #ffffff !important;
            border: 1px solid #eef0f2;
            box-shadow: 0 8px 24px rgba(15, 34, 58, .18) !important;
            z-index: 1005;
        }
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .menu-dropdown .nav-link {
            color: #4b5563 !important;
        }
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .menu-dropdown .nav-link i {
            color: #98a0ac !important;
        }
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .menu-dropdown .nav-link:hover {
            background: var(--sb-primary-soft) !important;
            color: var(--sb-primary) !important;
        }
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .menu-dropdown .nav-link:hover i {
            color: var(--sb-primary) !important;
        }
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .menu-dropdown .nav-link.active,
        html[data-sidebar-size^="sm"] .navbar-menu .navbar-nav .menu-dropdown .nav-link.active i {
            background: var(--sb-primary) !important;
            color: #ffffff !important;
        }
    </style>

</head>