<?php
/**
 * One-off restatement: DTR_details.work_hours = hours actually RENDERED.
 *
 * Until 2026-08-19 dtr_shift_figures() capped work_hours at
 * (day_hours − late charge − undertime), so a bracketed late (19 min → 1.00 hr)
 * also shaved the sheet's work hours (7.68 → 7.00). The cap is gone; this
 * re-derives work_hours for the rows the cap touched, from the same logs and
 * the same STAMPED shift the row was priced under (dtr_compute_day, stamp
 * mode). Only work_hours is written — late / OT / UT / NSD stay exactly as
 * paid — and a row is only touched when the recomputed figure is HIGHER
 * (i.e. the cap had shaved it), so nothing is ever reduced. Payroll is
 * unaffected: its day credit is min(1, worked + late + UT).
 *
 *   php migrations/2026_08_work_hours_rendered.php            (dry run)
 *   php migrations/2026_08_work_hours_rendered.php --apply
 */
$conn  = require __DIR__ . '/../db_connect.php';
$apply = in_array('--apply', $argv ?? [], true);

$res = $conn->query("SELECT id, employee_id, date_time, logs, work_hours, late, undertime, overtime
                     FROM DTR_details
                     WHERE late > 0 AND is_complete = 1 AND schedule_id IS NOT NULL
                     ORDER BY id");
$upd = $conn->prepare("UPDATE DTR_details SET work_hours = ? WHERE id = ? AND work_hours = ?");
$n = $changed = 0;
while ($r = $res->fetch_assoc()) {
    $n++;
    $ts = [];
    foreach ((json_decode($r['logs'] ?? '[]', true) ?: []) as $lg) {
        $t = strtotime($lg['dateTime'] ?? '');
        if ($t) $ts[] = $t;
    }
    if (count($ts) < 2) continue;
    $c = dtr_compute_day($conn, (int)$r['employee_id'], date('Y-m-d', strtotime($r['date_time'])), $ts, true);
    // Same shift as stored → same late/OT/UT; otherwise the row is not what
    // we think it is (unstamped fields, edited row) and is left alone.
    if (abs($c['late'] - $r['late']) > 0.011 || abs($c['undertime'] - $r['undertime']) > 0.011
        || abs($c['overtime'] - $r['overtime']) > 0.011) {
        echo "skip #{$r['id']} {$r['date_time']}: figures differ (late {$r['late']}→{$c['late']}, ut {$r['undertime']}→{$c['undertime']}, ot {$r['overtime']}→{$c['overtime']})\n";
        continue;
    }
    $new = (float)$c['work_hours'];
    $old = (float)$r['work_hours'];
    if ($new <= $old + 0.004) continue;
    $changed++;
    printf("#%d emp %d %s: %.2f → %.2f (late %.2f)\n", $r['id'], $r['employee_id'], $r['date_time'], $old, $new, $r['late']);
    if ($apply) {
        $upd->bind_param('did', $new, $r['id'], $old);
        $upd->execute();
    }
}
echo "\n$n rows checked, $changed " . ($apply ? 'updated' : 'would change (dry run — pass --apply)') . "\n";
