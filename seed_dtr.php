<?php
/**
 * DTR seeder — generates two semi-monthly batches (Jun 1–15 & Jun 16–30)
 * with realistic DTR_details for every active employee:
 *   - random lateness / undertime / overtime
 *   - 2-punch and 4-punch (with lunch) days
 *   - occasional single-punch (incomplete) and absent days
 *   - holiday day_type detection from calendar_events
 *   - a mix of approval statuses (pending / approved / disapproved)
 *
 * Idempotent: seeded batches are tagged note='SEED' and wiped on each run.
 *
 * Run:  php seed_dtr.php   (CLI only — never web-reachable)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this seeder can only be run from the command line.\n");
}

require __DIR__ . '/db_connect.php';
header('Content-Type: text/plain; charset=utf-8');

$YEAR = 2026; // June of this year
$periods = [
    ['from' => "$YEAR-06-01", 'to' => "$YEAR-06-15"],
    ['from' => "$YEAR-06-16", 'to' => "$YEAR-06-30"],
];

// ── Resolve site + responsible users ─────────────────────────────────────────
$site = $conn->query("SELECT id, employer_id, timekeeper_id FROM sites WHERE status = 1 ORDER BY id ASC LIMIT 1")->fetch_assoc();
if (!$site) exit("No active site found — cannot seed.\n");

$admin        = $conn->query("SELECT id FROM users WHERE role = 1 LIMIT 1")->fetch_assoc();
$admin_id     = $admin ? (int)$admin['id'] : 1;
$site_id      = (int)$site['id'];
$employer_id  = (int)$site['employer_id'];
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
$er = $conn->query("SELECT id, time_in, time_out FROM employee WHERE status = 1 ORDER BY id ASC");
while ($e = $er->fetch_assoc()) $emps[] = $e;
if (!$emps) exit("No active employees — cannot seed.\n");

// Weighted pick helper
function pick(array $vals) { return $vals[array_rand($vals)]; }

// Prepared insert for details
$detSql = "INSERT INTO DTR_details
    (ddtr_id, employee_id, date_time, work_hours, overtime, undertime, late, logs,
     attendance_type, day_type, nsd_hours, is_complete, notes, status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
$detStmt = $conn->prepare($detSql);
if (!$detStmt) exit("Prepare failed: " . $conn->error . "\n");

$totalBatches = 0; $totalDetails = 0;
$lateMins  = [0, 0, 0, 0, 5, 10, 12, 15, 20, 30, 45];   // weighted toward on-time
$outDelta  = [-45, -30, -15, 0, 0, 0, 0, 15, 30, 45, 60, 90]; // minus=undertime, plus=OT

foreach ($periods as $p) {
    // Parent DTR batch (Pending approval, biometric-style)
    $b = $conn->prepare("INSERT INTO DTR
        (local_id, site_id, timekeeper_id, employer_id, date_from, date_to, device_id,
         uploaded_by, approved_by, file, note, status, ptype, date_created)
        VALUES (0, ?, ?, ?, ?, ?, 0, ?, NULL, 'biometric', 'SEED', 1, 0, NOW())");
    $b->bind_param('iiissi', $site_id, $timekeeper_id, $employer_id, $p['from'], $p['to'], $admin_id);
    $b->execute();
    $ddtr_id = $conn->insert_id;
    $totalBatches++;

    // Days in the period
    $days = [];
    for ($d = strtotime($p['from']); $d <= strtotime($p['to']); $d = strtotime('+1 day', $d)) $days[] = $d;

    foreach ($emps as $emp) {
        $schedIn  = $emp['time_in']  ?: '08:00:00';
        $schedOut = $emp['time_out'] ?: '17:00:00';

        foreach ($days as $dts) {
            $ymd = date('Y-m-d', $dts);
            $dow = (int)date('N', $dts);

            // Sunday = rest day; ~8% random absences on other days
            if ($dow === 7) continue;
            if (mt_rand(1, 100) <= 8) continue;

            $schedInTs  = strtotime("$ymd $schedIn");
            $schedOutTs = strtotime("$ymd $schedOut");

            $late   = pick($lateMins) * 60;
            $inTs   = $schedInTs + $late;

            $day_type = $holidays[$ymd] ?? 'regular';
            $approvalStatus = (function () {
                $r = mt_rand(1, 100);
                if ($r <= 12) return 1; // approved
                if ($r <= 17) return 2; // disapproved
                return 0;               // pending
            })();

            // ~10% single-punch (forgot to time out) => incomplete
            $singlePunch = (mt_rand(1, 100) <= 10);

            if ($singlePunch) {
                $logs = [['dateTime' => date('Y-m-d H:i:s', $inTs), 'type' => 'bio']];
                $work_hours = 0; $overtime = 0; $undertime = 0;
                $late_h = round(max(0, $inTs - $schedInTs) / 3600, 2);
                $is_complete = 0;
            } else {
                $outTs = $schedOutTs + pick($outDelta) * 60;
                if ($outTs <= $inTs) $outTs = $inTs + 4 * 3600;

                // 50/50 : plain in/out  vs  in / lunch-out / lunch-in / out
                if (mt_rand(0, 1) === 1) {
                    $lunchOut = strtotime("$ymd 12:00:00") + pick([-10, -5, 0, 5, 10]) * 60;
                    $lunchIn  = strtotime("$ymd 13:00:00") + pick([-10, -5, 0, 5, 10]) * 60;
                    $logs = [
                        ['dateTime' => date('Y-m-d H:i:s', $inTs),      'type' => 'bio'],
                        ['dateTime' => date('Y-m-d H:i:s', $lunchOut),  'type' => 'bio'],
                        ['dateTime' => date('Y-m-d H:i:s', $lunchIn),   'type' => 'bio'],
                        ['dateTime' => date('Y-m-d H:i:s', $outTs),     'type' => 'bio'],
                    ];
                } else {
                    $logs = [
                        ['dateTime' => date('Y-m-d H:i:s', $inTs),  'type' => 'bio'],
                        ['dateTime' => date('Y-m-d H:i:s', $outTs), 'type' => 'bio'],
                    ];
                }

                $raw        = ($outTs - $inTs) / 3600;
                $work_hours = round(max(0, $raw - 1), 2); // 1h lunch break
                $late_h     = round(max(0, $inTs - $schedInTs) / 3600, 2);
                $undertime  = round(max(0, $schedOutTs - $outTs) / 3600, 2);
                $overtime   = round(max(0, $outTs - $schedOutTs) / 3600, 2);
                $is_complete = 1;
            }

            $logsJson       = json_encode($logs);
            $undertime      = $undertime ?? 0;
            $overtime       = $overtime ?? 0;
            $nsd_hours      = 0;
            $attendance     = 'biometric';
            $notes          = '';
            $empId          = (int)$emp['id'];

            $detStmt->bind_param(
                'iisddddsssdisi',
                $ddtr_id, $empId, $ymd, $work_hours, $overtime, $undertime, $late_h,
                $logsJson, $attendance, $day_type, $nsd_hours, $is_complete, $notes, $approvalStatus
            );
            $detStmt->execute();
            $totalDetails++;
        }
    }
}

echo "✔ Seed complete\n";
echo "  Site id ............ $site_id (employer $employer_id, timekeeper $timekeeper_id)\n";
echo "  Employees .......... " . count($emps) . "\n";
echo "  DTR batches ........ $totalBatches  (Jun 1–15, Jun 16–30 $YEAR, status = Pending)\n";
echo "  DTR_details rows ... $totalDetails\n";
echo "  Holidays detected .. " . count($holidays) . "\n";
echo "\nOpen DTR Review (index.php?page=dtr) to review & approve.\n";
