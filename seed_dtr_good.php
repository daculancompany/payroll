<?php
/**
 * DTR seeder (CLEAN) — one semi-monthly batch, Jun 1–15 only, all "good" rows.
 *
 * Every working day for every active employee gets a perfect record:
 *   - on-time punch in, on-time punch out (exactly on schedule)
 *   - full 4-punch day with a 1-hour lunch
 *   - NO late, NO undertime, NO overtime, NO incomplete/single-punch
 *   - NO random absences, NO disapprovals — nothing that would flag a row
 *   - rest days and holidays are still skipped (no work expected)
 *
 * Idempotent: seeded batch is tagged note='SEED' and wiped on each run.
 *
 * Run:  php seed_dtr_good.php   (CLI only — never web-reachable)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this seeder can only be run from the command line.\n");
}

require __DIR__ . '/db_connect.php';
header('Content-Type: text/plain; charset=utf-8');

$YEAR   = 2026;
$period = ['from' => "$YEAR-06-01", 'to' => "$YEAR-06-15"];

// ── Resolve site + responsible users ─────────────────────────────────────────
$site = $conn->query("SELECT id, employer_id, timekeeper_id FROM sites WHERE status = 1 ORDER BY id ASC LIMIT 1")->fetch_assoc();
if (!$site) exit("No active site found — cannot seed.\n");

$admin         = $conn->query("SELECT id FROM users WHERE role = 1 LIMIT 1")->fetch_assoc();
$admin_id      = $admin ? (int)$admin['id'] : 1;
$site_id       = (int)$site['id'];
$employer_id   = (int)$site['employer_id'];
$timekeeper_id = $site['timekeeper_id'] ? (int)$site['timekeeper_id'] : $admin_id;

// ── Holiday map from calendar_events (legal=1, special=3) ─────────────────────
$holidays = []; // 'Y-m-d' => 'legal_holiday' | 'special_holiday'
$hr = $conn->query("SELECT start_date, COALESCE(end_date, start_date) AS end_date, type
                    FROM calendar_events WHERE type IN (1,3)");
if ($hr) while ($h = $hr->fetch_assoc()) {
    $type = ((int)$h['type'] === 1) ? 'legal_holiday' : 'special_holiday';
    for ($d = strtotime($h['start_date']); $d <= strtotime($h['end_date']); $d = strtotime('+1 day', $d)) {
        $holidays[date('Y-m-d', $d)] = $type;
    }
}

// ── Wipe previous seed ────────────────────────────────────────────────────────
$conn->query("DELETE dd FROM DTR_details dd INNER JOIN DTR d ON d.id = dd.ddtr_id WHERE d.note = 'SEED'");
$conn->query("DELETE FROM DTR WHERE note = 'SEED'");

// ── Active employees ──────────────────────────────────────────────────────────
$emps = [];
$er = $conn->query("SELECT e.id, e.time_in, e.time_out,
                           COALESCE(es.rest_days, '0') AS rest_days
                    FROM employee e
                    LEFT JOIN employee_schedules es ON es.employee_id = e.id AND es.effective_to IS NULL
                    WHERE e.status = 1 ORDER BY e.id ASC");
while ($e = $er->fetch_assoc()) $emps[] = $e;
if (!$emps) exit("No active employees — cannot seed.\n");

// Prepared insert for details
$detSql = "INSERT INTO DTR_details
    (ddtr_id, employee_id, date_time, work_hours, overtime, undertime, late, logs,
     attendance_type, day_type, nsd_hours, is_complete, notes, status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
$detStmt = $conn->prepare($detSql);
if (!$detStmt) exit("Prepare failed: " . $conn->error . "\n");

$totalBatches = 0; $totalDetails = 0;

// Parent DTR batch (Pending approval, biometric-style)
$b = $conn->prepare("INSERT INTO DTR
    (local_id, site_id, timekeeper_id, employer_id, date_from, date_to, device_id,
     uploaded_by, approved_by, file, note, status, ptype, date_created)
    VALUES (0, ?, ?, ?, ?, ?, 0, ?, NULL, 'biometric', 'SEED', 1, 0, NOW())");
$b->bind_param('iiissi', $site_id, $timekeeper_id, $employer_id, $period['from'], $period['to'], $admin_id);
$b->execute();
$ddtr_id = $conn->insert_id;
$totalBatches++;

// Days in the period
$days = [];
for ($d = strtotime($period['from']); $d <= strtotime($period['to']); $d = strtotime('+1 day', $d)) $days[] = $d;

foreach ($emps as $emp) {
    $schedIn  = $emp['time_in']  ?: '08:00:00';
    $schedOut = $emp['time_out'] ?: '17:00:00';
    $restDays = array_map('intval', array_filter(explode(',', $emp['rest_days']), fn($x) => $x !== ''));

    foreach ($days as $dts) {
        $ymd = date('Y-m-d', $dts);
        $dow = (int)date('w', $dts); // 0=Sun … 6=Sat, matches rest_days

        // Skip rest days and holidays — no work expected, so no row to flag.
        if (in_array($dow, $restDays, true)) continue;
        if (isset($holidays[$ymd])) continue;

        $schedInTs  = strtotime("$ymd $schedIn");
        $schedOutTs = strtotime("$ymd $schedOut");

        // Perfect 4-punch day: in, lunch out 12:00, lunch in 13:00, out — exactly on schedule.
        $lunchOut = strtotime("$ymd 12:00:00");
        $lunchIn  = strtotime("$ymd 13:00:00");
        $logs = [
            ['dateTime' => date('Y-m-d H:i:s', $schedInTs),  'type' => 'bio'],
            ['dateTime' => date('Y-m-d H:i:s', $lunchOut),   'type' => 'bio'],
            ['dateTime' => date('Y-m-d H:i:s', $lunchIn),    'type' => 'bio'],
            ['dateTime' => date('Y-m-d H:i:s', $schedOutTs), 'type' => 'bio'],
        ];

        $work_hours  = round(max(0, ($schedOutTs - $schedInTs) / 3600 - 1), 2); // minus 1h lunch
        $overtime    = 0;
        $undertime   = 0;
        $late_h      = 0;
        $nsd_hours   = 0;
        $is_complete = 1;
        $day_type    = 'regular';
        $attendance  = 'biometric';
        $notes       = '';
        $status      = 0; // pending / unflagged
        $logsJson    = json_encode($logs);
        $empId       = (int)$emp['id'];

        $detStmt->bind_param(
            'iisddddsssdisi',
            $ddtr_id, $empId, $ymd, $work_hours, $overtime, $undertime, $late_h,
            $logsJson, $attendance, $day_type, $nsd_hours, $is_complete, $notes, $status
        );
        $detStmt->execute();
        $totalDetails++;
    }
}

echo "✔ Clean seed complete\n";
echo "  Site id ............ $site_id (employer $employer_id, timekeeper $timekeeper_id)\n";
echo "  Employees .......... " . count($emps) . "\n";
echo "  DTR batches ........ $totalBatches  (Jun 1–15 $YEAR, status = Pending)\n";
echo "  DTR_details rows ... $totalDetails  (all complete, no late/UT/OT, no flags)\n";
echo "  Holidays skipped ... " . count($holidays) . "\n";
echo "\nOpen DTR Review (index.php?page=dtr) to review & approve.\n";
