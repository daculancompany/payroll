<?php
/**
 * One-off cleanup after the leave-notification scoping fix (2026-09-14).
 *
 * Until that fix, "New leave request" / "Leave needs your approval" bells were
 * written to EVERY Section Head / Supervisor / Department Head in the hospital
 * instead of only the requester's area approvers. The fix stops new ones; this
 * script removes the ones already sitting in people's bells.
 *
 * A notification is KEPT when its recipient may decide some stage for the named
 * employee (area_approver, or the role/department fallback for employees with no
 * area) — i.e. exactly the set the fixed code would have written. Admin (role 1)
 * and HR (role 9) are global observers and are never touched.
 *
 * Run from CLI, from the payroll directory:
 *   php migrations/2026_09_prune_misrouted_leave_notifs.php            (dry run)
 *   php migrations/2026_09_prune_misrouted_leave_notifs.php --apply
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
chdir(__DIR__ . '/..');
$_SERVER['REQUEST_METHOD'] = 'GET';
// Local XAMPP: CLI php resolves "localhost" to the system socket path, which
// does not exist, so point mysqli at XAMPP's socket when it is there. On a
// live box that path is absent and this is a no-op; DB_HOST env still wins.
$__sock = '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock';
if (getenv('DB_HOST') === false && file_exists($__sock)) {
    ini_set('mysqli.default_socket', $__sock);
}
require_once 'db_connect.php';
$apply = in_array('--apply', $argv, true);

$q = $conn->query(
    "SELECT n.id, n.user_id, n.title, n.message, n.is_read, u.role, u.name
     FROM notifications n JOIN users u ON u.id = n.user_id
     WHERE n.recipient_type = 'user'
       AND n.title IN ('New leave request', 'Leave needs your approval')
       AND u.role IN (8, 10, 11)
     ORDER BY n.id"
);

$stages   = array_keys(leave_stages());
$empCache = [];
$okCache  = [];
$del = []; $keep = 0; $unmatched = 0; $perUser = [];

while ($n = $q->fetch_assoc()) {
    // "NAME requested Annual Leave (...)"  or  "NAME's Sick Leave (...) is awaiting ..."
    $name = null;
    if (preg_match("/^(.+?) requested /u", $n['message'], $m))       $name = $m[1];
    elseif (preg_match("/^(.+?)'s .+ is awaiting /u", $n['message'], $m)) $name = $m[1];
    elseif (preg_match("/^(.+?) filed a /u", $n['message'], $m))     $name = $m[1];
    if ($name === null) { $unmatched++; continue; }

    $key = mb_strtoupper(trim($name));
    if (!array_key_exists($key, $empCache)) {
        $s = $conn->prepare("SELECT id FROM employee WHERE UPPER(CONCAT(firstname,' ',lastname)) = ? ORDER BY status DESC, id LIMIT 1");
        $s->bind_param('s', $key); $s->execute();
        $empCache[$key] = (int) (($s->get_result()->fetch_assoc()['id'] ?? 0));
    }
    $eid = $empCache[$key];
    if ($eid <= 0) { $unmatched++; continue; }

    if (!isset($okCache[$eid])) {
        $ids = [];
        foreach ($stages as $st) $ids = array_merge($ids, leave_stage_approver_ids($conn, $st, $eid));
        $okCache[$eid] = array_fill_keys($ids, true);
    }
    if (isset($okCache[$eid][(int) $n['user_id']])) { $keep++; continue; }

    $del[] = (int) $n['id'];
    $perUser[$n['name']] = ($perUser[$n['name']] ?? 0) + 1;
}

printf("%s: keep %d, delete %d, unmatched (left alone) %d\n", $apply ? 'APPLY' : 'DRY RUN', $keep, count($del), $unmatched);
arsort($perUser);
foreach ($perUser as $who => $c) printf("  -%3d  %s\n", $c, $who);

if ($apply && $del) {
    foreach (array_chunk($del, 500) as $chunk) {
        $conn->query("DELETE FROM notifications WHERE id IN (" . implode(',', $chunk) . ")");
    }
    echo "deleted " . count($del) . " misrouted leave notifications\n";
} elseif (!$apply) {
    echo "nothing changed — re-run with --apply to delete\n";
}
