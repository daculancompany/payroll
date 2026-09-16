<?php
/**
 * One-off, after migrations/2026_09_att_request_stages.sql and the PHP deploy.
 *
 * Attendance requests that were still PENDING when the staged chain arrived
 * have every stage at 0. A freshly filed request gets its unstaffed optional
 * stages (Supervisor, Section Head) auto-skipped at filing time; these older
 * rows never went through that, so they would sit waiting on a Supervisor
 * that does not exist for the ward. This runs the same auto-skip over them —
 * no notifications are sent, the approvers see them in their queue instead.
 *
 *   php migrations/2026_09_att_request_stages_backfill.php            (dry run)
 *   php migrations/2026_09_att_request_stages_backfill.php --apply
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
chdir(__DIR__ . '/..');
$_SERVER['REQUEST_METHOD'] = 'GET';
$__sock = '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock';   // local XAMPP only; no-op on live
if (getenv('DB_HOST') === false && file_exists($__sock)) ini_set('mysqli.default_socket', $__sock);
require_once 'db_connect.php';
$apply = in_array('--apply', $argv, true);

$q = $conn->query("SELECT id, employee_id, request_type, request_date
                   FROM attendance_requests
                   WHERE status = 0 AND sec_status = 0 AND sup_status = 0 AND admin_status = 0 AND hr_status = 0
                   ORDER BY id");
$rows = $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
printf("%s: %d pending request(s) with no stage resolved yet\n", $apply ? 'APPLY' : 'DRY RUN', count($rows));

foreach ($rows as $r) {
    $eid = (int) $r['employee_id'];
    $skip = [];
    foreach (leave_stages() as $key => $cfg) {
        if (leave_stage_has_approver($conn, $key, $eid)) continue;
        if (empty($cfg['optional']) && !leave_stage_blocked_by_self($conn, $key, $eid)) continue;
        $skip[] = $cfg['label'];
    }
    printf("  #%-5d emp %-4d %-9s %s  → skip: %s\n", $r['id'], $eid, $r['request_type'], $r['request_date'], $skip ? implode(', ', $skip) : '(none)');
    if ($apply && $skip) {
        leave_autoskip_stages($conn, (int) $r['id'], $eid, 'attendance_requests');
    }
}
echo $apply ? "done\n" : "nothing changed — re-run with --apply\n";
