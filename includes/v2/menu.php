<?php
/**
 * v2 sidebar: one data table instead of the classic navbar's per-role
 * branches. Each item is shown only when page_allowed() lets the signed-in
 * role open it (the same check the router enforces server-side), so every
 * role gets exactly its own slice.
 *
 * Item: [slug, label, remixicon, (optional) href for standalone pages,
 *        (optional) extra slugs that also mark it active]
 */
return [
    ['title' => 'Overview', 'items' => [
        ['home',         'Dashboard',        'ri-dashboard-3-line'],
        ['daily-board',  'Daily Board',      'ri-layout-masonry-line'],
    ]],
    ['title' => 'Payroll', 'items' => [
        ['payroll',          'Payroll Runs',      'ri-money-dollar-circle-line', null, ['payroll_items', 'payroll_calculations']],
        ['dtr',              'DTR',               'ri-calendar-check-line', null, ['dtr-details']],
        ['loans',            'Loans',             'ri-hand-coin-line'],
        ['thirteenth-month', '13th Month',        'ri-gift-line'],
        ['contributions',    'Contributions',     'ri-shield-check-line'],
        ['deductions',       'Deductions',        'ri-subtract-line'],
        ['allowances',       'Allowances',        'ri-add-circle-line'],
        ['refunds',          'Refunds',           'ri-refund-2-line'],
        ['pay-settings',     'Pay Settings',      'ri-settings-4-line'],
    ]],
    ['title' => 'People', 'items' => [
        ['employee',    'Employees',   'ri-team-line', null, ['employee-details']],
        ['department',  'Departments', 'ri-building-line'],
        ['area',        'Areas',       'ri-map-pin-line'],
        ['position',    'Positions',   'ri-briefcase-line'],
    ]],
    ['title' => 'HR', 'items' => [
        ['hr-overview',      'HR Overview',       'ri-user-heart-line'],
        ['hr-announcements', 'Announcements',     'ri-megaphone-line'],
        ['hr-requests',      'Document Requests', 'ri-file-paper-2-line'],
        ['hr-offboarding',   'Offboarding',       'ri-logout-box-r-line'],
    ]],
    ['title' => 'Time & Attendance', 'items' => [
        ['attendance',          'Attendance',        'ri-fingerprint-line'],
        ['punch-logs',          'Punch Logs',        'ri-time-line'],
        ['pin-attendance',      'PIN Attendance',    'ri-lock-password-line'],
        ['kiosk-devices',       'Kiosk Devices',     'ri-tablet-line'],
        ['attendance-requests', 'Requests',          'ri-inbox-archive-line'],
        ['work-schedules',      'Work Schedules',    'ri-calendar-2-line'],
        // ri-calendar-schedule-line only exists from Remix Icon 4.x; the v2
        // shell loads 3.5.0 (includes/v2/head.php), so it rendered as a blank
        // gap. ri-calendar-todo-line is in both that and the local font the
        // classic shell uses — which is also what the classic navbar already
        // shows for this page.
        ['schedule-roster',     'Schedule Roster',   'ri-calendar-todo-line'],
        ['duty-roster',         'Duty Roster',       'ri-table-line', 'duty-roster.php'],
        ['similarity-reports',  'Face Similarity',   'ri-user-search-line'],
    ]],
    ['title' => 'Leave', 'items' => [
        ['leave-dashboard', 'Leave Dashboard', 'ri-pie-chart-2-line'],
        ['leaves',          'Leave Requests',  'ri-suitcase-line'],
        ['leave_balances',  'Leave Credits',   'ri-scales-3-line'],
        ['leave_types',     'Leave Types',     'ri-list-settings-line'],
        ['calendar',        'Calendar',        'ri-calendar-event-line'],
    ]],
    ['title' => 'Reports', 'items' => [
        ['reports',               'All Reports',        'ri-file-chart-line'],
        ['payroll-register',      'Payroll Register',   'ri-file-list-3-line'],
        ['remittance-report',     'Remittances',        'ri-government-line'],
        ['bank-payout',           'Bank Payout',        'ri-bank-line'],
        ['bir-alphalist',         'BIR Alphalist',      'ri-file-paper-2-line'],
        ['employee-masterlist',   'Masterlist',         'ri-contacts-book-line'],
        ['attendance-summary',    'Attendance Summary', 'ri-time-line'],
    ]],
    ['title' => 'System', 'items' => [
        ['sites',          'Sites',        'ri-community-line'],
        ['users',          'Users',        'ri-user-settings-line'],
        ['visitors-logs',  'Audit Logs',   'ri-history-line'],
        ['site_settings',  'Site Settings','ri-tools-line'],
    ]],
];
