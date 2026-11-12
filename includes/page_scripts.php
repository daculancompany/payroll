<?php
/* Per-page scripts, keyed off $page. Shared by index.php and app.php; the
 * v2 shell also captures this output to list the scripts it must load after
 * an in-place (SPA) navigation. Moved verbatim out of index.php. */
?>
    <?php if ($page == 'home') { ?>
        <script src="assets2/js/home.js"></script>
    <?php } ?>

    <?php if ($page == 'employee' || $page == 'employee-details') { ?>
        <script src="<?= av('assets2/js/employee.js') ?>"></script>
    <?php } ?>
    <?php if ($page == 'schedule-roster') { ?>
        <script src="assets2/js/schedule-roster.js?v=1"></script>
    <?php } ?>
    <?php if ($page == 'daily-board') { ?>
        <script src="assets2/js/daily-board.js?v=4"></script>
    <?php } ?>
    <?php // department.js was written against the old markup (#form-add, #data-table,
          // #modal, #name). department.php now ships its own inline handler, so the
          // file matched nothing — it only leaked the globals `id` and `btnText`. ?>
    <?php if ($page == 'branch') { ?>
        <script src="assets2/js/branch.js"></script>
    <?php } ?>
    <?php if ($page == 'position') { ?>
        <script src="assets2/js/position.js"></script>
    <?php } ?>
    <?php if ($page == 'deductions') { ?>
        <script src="assets2/js/deductions.js"></script>
    <?php } ?>
    <?php if ($page == 'payroll'  || $page == 'payroll_items') { ?>
        <?php /* av() appends the file mtime so a changed script is never served from browser cache */ ?>
        <script src="<?= av('assets2/js/payroll.js') ?>"></script>
    <?php } ?>
    <?php if ($page == 'attendance') { ?>
        <script src="assets2/js/attendance.js"></script>
    <?php } ?>
    <?php if ($page == 'time-logs') { ?>
        <script src="assets2/js/time_logs.js"></script>
    <?php } ?>
    <?php if ($page == 'allowances') { ?>
        <script src="assets2/js/allowances.js"></script>
    <?php } ?>

    <?php if ($page == 'sites') { ?>
        <script src="assets2/js/sites.js"></script>
    <?php } ?>

    <?php if ($page == 'users') { ?>
        <script src="<?= av('assets2/js/user.js') ?>"></script>
    <?php } ?>

    <?php if ($page == 'dtr') { ?>
        <script src="assets2/js/dtr.js"></script>
    <?php } ?>
    <?php if ($page == 'dtr-details') { ?>
        <script src="<?= av('assets2/js/dtr-details.js') ?>"></script>
    <?php } ?>
    <?php if ($page == 'clusters') { ?>
        <script src="assets2/js/clusters.js"></script>
    <?php } ?>
    <?php if ($page == 'visitors-logs') { ?>
        <script src="assets2/js/logs.js"></script>
    <?php } ?>
    <?php if ($page == 'visitors-logs') { ?>
        <script src="assets2/js/visitors-logs.js"></script>
    <?php } ?>
    <?php if ($page == 'payroll_calculations') { ?>
        <script src="<?= av('assets2/js/payroll_calculations.js') ?>"></script>
    <?php } ?>
    <?php if (in_array($page, ['payroll-report','loan-deduction-ledger','payroll-register','employee-masterlist','attendance-summary'])) { ?>
        <script src="assets2/js/reports.js"></script>
    <?php } ?>
    <?php if ($page == 'refunds') { ?>
        <script src="assets2/js/refunds.js"></script>
    <?php } ?>

