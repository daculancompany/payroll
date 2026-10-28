<?php
/**
 * Per-day Form 48 markers — holiday / leave / shift / day off.
 *
 * The shared window.DTRForm48 template renders a day's context from a `marks`
 * payload: a blank row explains itself (HOLIDAY / ANNUAL LEAVE / DAY OFF) and a
 * worked row carries the shift it ran on. The logic lived only inside
 * dtr-employee-server.php's `docs` action, so the payroll workbench's DTR
 * Details modal showed attendance-request marks and nothing else — a day the
 * employee was on paid leave read as a plain absence, even though
 * calculate_payroll() had already paid it.
 *
 * Extracted verbatim from dtr-employee-server.php so both screens draw the same
 * sheet from the same rules. Attendance-request ('req') marks stay with their
 * callers: each has its own extra per-day context to fold in.
 *
 * Requires db_connect.php (pick_schedule_window).
 */

if (!function_exists('dtr_day_marks')) {
    /**
     * @param int[] $empIds  employees to build marks for
     * @param array $stampBy eid => 'Y-m-d' => DTR_details.schedule_id — the shift
     *                       STAMPED on that day's row, which outranks any roster
     *                       lookup because it is what the figures were computed
     *                       against and survives later schedule edits.
     * @return array eid => 'Y-m-d' => list of marks, in render order
     *               (holiday, leave, sched, off)
     */
    function dtr_day_marks(mysqli $conn, array $empIds, string $dFrom, string $dTo, array $stampBy = []): array
    {
        $empIds = array_values(array_unique(array_map('intval', $empIds)));
        if (!$empIds) return [];
        $idList = implode(',', $empIds);

        // ── Holidays ── calendar_events types 1 (legal) and 3 (special), clipped
        // to the period so a holiday spanning a cutoff only marks its own days.
        $holidays = [];   // 'Y-m-d' => ['t' => 'legal'|'special', 'lbl' => title]
        $hq = $conn->prepare("SELECT title, start_date, end_date, type FROM calendar_events
                              WHERE type IN (1,3) AND start_date <= ?
                                AND COALESCE(end_date, start_date) >= ?");
        if ($hq) {
            $hq->bind_param('ss', $dTo, $dFrom);
            $hq->execute();
            $hres = $hq->get_result();
            while ($h = $hres->fetch_assoc()) {
                $hs = max(strtotime($h['start_date']), strtotime($dFrom));
                $he = min(strtotime($h['end_date'] ?: $h['start_date']), strtotime($dTo));
                for ($d = $hs; $d <= $he; $d = strtotime('+1 day', $d)) {
                    $holidays[date('Y-m-d', $d)] = [
                        't'   => ((int)$h['type'] === 1 ? 'legal' : 'special'),
                        'lbl' => $h['title'],
                    ];
                }
            }
        }

        // ── Leave ── pending included (status 0) so an admin sees a filing that
        // is still waiting on the approval chain; the template labels it
        // "(PENDING)". `dates` is the source of truth — a request's day list is
        // frequently non-contiguous, so date_from..date_to is an outer bound,
        // not the days taken. The range fallback is only for rows predating it.
        $leaveMap = [];   // eid => 'Y-m-d' => ['lbl','s','half']
        $lq = $conn->prepare("SELECT lr.employee_id, lr.dates, lr.date_from, lr.date_to, lr.status,
                                     lr.is_half_day, lr.half_date, lt.name AS type_name
                              FROM leave_requests lr
                              INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                              WHERE lr.employee_id IN ($idList) AND lr.status IN (0,1)
                                AND lr.date_from <= ? AND lr.date_to >= ?");
        if ($lq) {
            $lq->bind_param('ss', $dTo, $dFrom);
            $lq->execute();
            $lres = $lq->get_result();
            while ($lv = $lres->fetch_assoc()) {
                $lvDays = json_decode((string)($lv['dates'] ?? ''), true);
                if (!is_array($lvDays) || !$lvDays) {
                    $lvDays = [];
                    for ($d = strtotime($lv['date_from']); $d <= strtotime($lv['date_to']); $d = strtotime('+1 day', $d)) {
                        $lvDays[] = date('Y-m-d', $d);
                    }
                }
                foreach ($lvDays as $dy) {
                    $ymd = date('Y-m-d', strtotime($dy));
                    if ($ymd < $dFrom || $ymd > $dTo) continue;
                    $leaveMap[(int)$lv['employee_id']][$ymd] = [
                        'lbl'  => $lv['type_name'],
                        's'    => (int)$lv['status'],
                        'half' => ((int)$lv['is_half_day'] === 1 && !empty($lv['half_date'])
                                   && date('Y-m-d', strtotime($lv['half_date'])) === $ymd),
                    ];
                }
            }
        }

        // Shift catalogue, keyed by id — resolves a stamped DTR_details.schedule_id
        // without a join per day.
        $wsById = [];
        $wsq = $conn->query("SELECT id, description, start_time, end_time, is_graveyard FROM work_schedules");
        if ($wsq) while ($w = $wsq->fetch_assoc()) $wsById[(int)$w['id']] = $w;

        // EVERY schedule window these employees have, not just ones overlapping the
        // period: pick_schedule_window() falls back to the nearest window when none
        // covers a date, and it cannot fall back to rows that were never fetched.
        $schedMap = [];   // eid => all windows (effective_from ASC)
        $sq = $conn->prepare("SELECT es.employee_id, es.rest_days, es.effective_from, es.effective_to,
                                     ws.description AS sched_name, ws.start_time, ws.end_time, ws.is_graveyard
                              FROM employee_schedules es
                              LEFT JOIN work_schedules ws ON ws.id = es.schedule_id
                              WHERE es.employee_id IN ($idList)
                              ORDER BY es.effective_from ASC");
        if ($sq) {
            $sq->execute();
            $sres = $sq->get_result();
            while ($sr = $sres->fetch_assoc()) $schedMap[(int)$sr['employee_id']][] = $sr;
        }

        // Published duty-roster days. For rotating staff the weekday rest-day CSV
        // is not a rough answer but the wrong one — it stamps DAY OFF on days they
        // were rostered to work and leaves their real days off unmarked.
        $dutyMap = [];   // eid => ['Y-m-d' => row]
        $dq = @$conn->prepare("SELECT eds.employee_id, eds.work_date, eds.is_rest_day,
                                      ws.description AS sched_name, ws.start_time, ws.end_time, ws.is_graveyard
                               FROM employee_day_schedule eds
                               LEFT JOIN work_schedules ws ON ws.id = eds.schedule_id
                               WHERE eds.employee_id IN ($idList) AND eds.status = 1
                                 AND eds.work_date BETWEEN ? AND ?");
        if ($dq) {
            $dq->bind_param('ss', $dFrom, $dTo);
            $dq->execute();
            $dres = $dq->get_result();
            while ($dr = $dres->fetch_assoc()) {
                $dutyMap[(int)$dr['employee_id']][$dr['work_date']] = $dr;
            }
        }

        // ── Assemble: one pass per employee per calendar day ──
        $out = [];
        foreach ($empIds as $eid) {
            $sch = $schedMap[$eid] ?? [];
            for ($d = strtotime($dFrom); $d <= strtotime($dTo); $d = strtotime('+1 day', $d)) {
                $ymd = date('Y-m-d', $d);
                $m = [];
                if (isset($holidays[$ymd]))       $m[] = ['k' => 'holiday'] + $holidays[$ymd];
                if (isset($leaveMap[$eid][$ymd])) $m[] = ['k' => 'leave'] + $leaveMap[$eid][$ymd];

                // A published duty-roster day answers outright — it is the fact for
                // that date, and the weekday CSV below cannot speak for someone
                // whose day off moves through the week.
                $duty = $dutyMap[$eid][$ymd] ?? null;

                // Rest-day CSV: the latest schedule window COVERING this day wins.
                // Covering-only on purpose — marking someone off duty on the
                // strength of a window that doesn't cover the day would be
                // inventing a day off.
                $rest = null;
                foreach ($sch as $srow) {
                    if ($srow['effective_from'] <= $ymd
                        && (empty($srow['effective_to']) || $srow['effective_to'] >= $ymd)) {
                        $rest = (string)$srow['rest_days'];
                    }
                }

                // Which shift the day was worked under. A window that actually
                // COVERS the date is a fact; the nearest-window fallback is a
                // guess, and printing the two identically made a month with no
                // assignment look like a month of uniform shifts. The guess still
                // shows — an admin needs to see what the figures were computed
                // against — but flagged `inf` so the sheet renders it as inferred.
                $stampId = $stampBy[$eid][$ymd] ?? null;
                $shift = null;
                $inf   = 0;
                if ($stampId && isset($wsById[(int)$stampId])) {
                    // 1. The shift STAMPED on the DTR row wins outright: it is what
                    //    the day was recorded under, so it survives a later roster
                    //    edit and is never a guess.
                    $ws    = $wsById[(int)$stampId];
                    $shift = [
                        'sched_name'   => $ws['description'],
                        'start_time'   => $ws['start_time'],
                        'end_time'     => $ws['end_time'],
                        'is_graveyard' => $ws['is_graveyard'],
                    ];
                } elseif ($duty && !empty($duty['sched_name'])) {
                    // 1b. Unstamped, but the ward published a shift for this exact
                    //     date — a fact, not a guess. Same precedence
                    //     resolve_employee_schedule() applies.
                    $shift = $duty;
                } else {
                    // 2. Unstamped (no attendance, or a row predating the column).
                    $cover = null;
                    foreach ($sch as $srow) {
                        if ($srow['effective_from'] <= $ymd
                            && (empty($srow['effective_to']) || $srow['effective_to'] >= $ymd)) {
                            $cover = $srow;
                        }
                    }
                    $shift = $cover ?: pick_schedule_window($sch, $ymd);
                    $inf   = $cover ? 0 : 1;
                }
                if ($shift && !empty($shift['sched_name'])) {
                    $m[] = [
                        'k'   => 'sched',
                        'lbl' => $shift['sched_name'],
                        'st'  => date('g:i A', strtotime($shift['start_time'])),
                        'et'  => date('g:i A', strtotime($shift['end_time'])),
                        'g'   => (int)$shift['is_graveyard'],
                        'sh'  => (int)date('G', strtotime($shift['start_time'])),
                        'inf' => $inf,
                    ];
                }

                // Day off: the roster's own flag when the day is rostered, the
                // weekday CSV only for staff who are not.
                $isOff = $duty
                    ? ((int)$duty['is_rest_day'] === 1)
                    : ($rest !== null && $rest !== ''
                       && in_array((int)date('w', $d), array_map('intval', explode(',', $rest)), true));
                // An approved leave on a date the roster left blank is a work day
                // taken off — payroll pays it (isLeaveRestDay), so don't stamp OFF.
                if ($isOff && !$duty && !empty($dutyMap[$eid])
                    && (($leaveMap[$eid][$ymd]['s'] ?? null) === 1)) {
                    $isOff = false;
                }
                if ($isOff) $m[] = ['k' => 'off'];

                if ($m) $out[$eid][$ymd] = $m;
            }
        }
        return $out;
    }
}
