<?php
/**
 * AJAX source for the DTR details "By Employee" list.
 *
 * dtr-details.php used to render every attendance record of a batch three times
 * (by-day table, by-employee cards, print table). On a 15-day / 361-employee
 * batch that was 4,310 records => ~35 MB of HTML and ~300k DOM nodes. The page
 * now renders nothing but the first page of employee cards and asks this
 * endpoint for the rest.
 *
 * Actions
 *   list    — one page of employee cards (offset/limit, optional search)
 *   summary — batch-wide approval counters (the top stat cards)
 *   print   — the by-day print table, built on demand for the Print button
 *
 * Everything is scoped to one ddtr_id and every parameter is bound or cast.
 */

require_once __DIR__ . '/includes/session_bootstrap.php';

if (empty($_SESSION['is_login'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['result' => false, 'message' => 'Not authenticated']);
    exit;
}

include 'db_connect.php';
require_page_access('dtr-details', 'json');   // same boundary as the DTR screens
include_once 'component/dtr_employee_card.php';

$login_role = (int)($_SESSION['login_role'] ?? 0);

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$ddtrId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($ddtrId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['result' => false, 'message' => 'Missing DTR id']);
    exit;
}

/** Batch header (needed for the print action and to confirm the batch exists). */
$batchStmt = $conn->prepare("
    SELECT DTR.*, sites.site_code, sites.site_name, employers.employer_name
    FROM DTR
    LEFT JOIN sites ON sites.id = DTR.site_id
    LEFT JOIN employers ON sites.employer_id = employers.id
    WHERE DTR.id = ?
");
$batchStmt->bind_param('i', $ddtrId);
$batchStmt->execute();
$batch = $batchStmt->get_result()->fetch_assoc();

if (!$batch) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['result' => false, 'message' => 'DTR batch not found']);
    exit;
}

// Period length + the minimum logged days for "normal attendance"
// (see DTR_LOW_ATTENDANCE_PCT in db_connect.php).
$periodDays = 0;
if (!empty($batch['date_from']) && !empty($batch['date_to'])) {
    $pdF = date_create($batch['date_from']);
    $pdT = date_create($batch['date_to']);
    if ($pdF && $pdT) $periodDays = (int)$pdF->diff($pdT)->days + 1;
}
$minDays = dtr_min_days($periodDays);

/**
 * Batch-wide approval counters. Always computed over every record of the batch,
 * never over the loaded page, so the summary cards stay honest while only ten
 * employees are on screen.
 */
function dtr_batch_summary(mysqli $conn, $ddtrId, int $minDays = 0)
{
    // clean_pending mirrors dtr_clean_condition_sql (db_connect.php) exactly —
    // it's the count of records a clean bulk-approval would touch.
    $cleanExpr = "status = 0" . dtr_clean_condition_sql((int)$ddtrId, $minDays);
    $stmt = $conn->prepare("
        SELECT
            COUNT(*)                                      AS total,
            SUM(status = 1)                               AS approved,
            SUM(status = 2)                               AS disapproved,
            SUM(status <> 1 AND status <> 2)              AS pending,
            COALESCE(SUM(work_hours), 0)                  AS work_hours,
            COALESCE(SUM(overtime), 0)                    AS overtime,
            COALESCE(SUM(undertime), 0)                   AS undertime,
            COALESCE(SUM(late), 0)                        AS late,
            COUNT(DISTINCT employee_id)                   AS employees,
            COUNT(DISTINCT date_time)                     AS days,
            SUM($cleanExpr)                               AS clean_pending
        FROM DTR_details WHERE ddtr_id = ?
    ");
    $stmt->bind_param('i', $ddtrId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    foreach (['total','approved','disapproved','pending','employees','days','clean_pending'] as $k) {
        $r[$k] = (int)($r[$k] ?? 0);
    }
    foreach (['work_hours','overtime','undertime','late'] as $k) {
        $r[$k] = (float)($r[$k] ?? 0);
    }
    return $r;
}

if ($action === 'summary') {
    header('Content-Type: application/json');
    echo json_encode(['result' => true, 'summary' => dtr_batch_summary($conn, $ddtrId, $minDays)]);
    exit;
}

if ($action === 'rec_msgs') {
    // Latest thread for one record (chat popover refresh). Scoped to this batch.
    $recId = (int)($_GET['rec'] ?? 0);
    $msgs  = [];
    if ($recId > 0) {
        $own = $conn->query("SELECT id FROM DTR_details WHERE id = $recId AND ddtr_id = " . (int)$ddtrId)->fetch_assoc();
        if ($own) {
            $mq = @$conn->query("SELECT m.message, m.created_at, m.sender_type, u.name AS sender
                                 FROM dtr_messages m LEFT JOIN users u ON u.id = m.sent_by
                                 WHERE m.dtr_detail_id = $recId ORDER BY m.id ASC");
            if ($mq) {
                while ($m = $mq->fetch_assoc()) {
                    $msgs[] = [
                        'from' => ($m['sender_type'] === 'employee') ? 'emp' : 'admin',
                        'msg'  => $m['message'],
                        'by'   => $m['sender'] ?? '',
                        'at'   => date('M j, g:i A', strtotime($m['created_at'])),
                    ];
                }
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['result' => true, 'msgs' => $msgs]);
    exit;
}

if ($action === 'filter_opts') {
    // Options for the document viewer's filter popover — only values that
    // actually occur in this batch, so no filter can ever return zero by design.
    $out = ['departments' => [], 'positions' => []];

    $q1 = $conn->prepare("SELECT DISTINCT dep.id, dep.name FROM DTR_details d
                          INNER JOIN employee e ON e.id = d.employee_id
                          INNER JOIN department dep ON dep.id = e.department_id
                          WHERE d.ddtr_id = ? ORDER BY dep.name");
    $q1->bind_param('i', $ddtrId);
    $q1->execute();
    $r1 = $q1->get_result();
    while ($row = $r1->fetch_assoc()) $out['departments'][] = ['id' => (int)$row['id'], 'name' => $row['name']];

    $q2 = $conn->prepare("SELECT DISTINCT p.id, p.name FROM DTR_details d
                          INNER JOIN employee e ON e.id = d.employee_id
                          INNER JOIN position p ON p.id = e.position_id
                          WHERE d.ddtr_id = ? ORDER BY p.name");
    $q2->bind_param('i', $ddtrId);
    $q2->execute();
    $r2 = $q2->get_result();
    while ($row = $r2->fetch_assoc()) $out['positions'][] = ['id' => (int)$row['id'], 'name' => $row['name']];

    // Work schedules actually assigned to someone in this batch, over the batch
    // period. Same rule the schedule filter below uses, so the dropdown can
    // never offer an option that returns nothing.
    $out['schedules'] = [];
    $q3 = $conn->prepare("SELECT DISTINCT ws.id, ws.description AS name, ws.start_time, ws.end_time
                          FROM DTR_details d
                          INNER JOIN employee_schedules es ON es.employee_id = d.employee_id
                               AND es.effective_from <= ?
                               AND (es.effective_to IS NULL OR es.effective_to >= ?)
                          INNER JOIN work_schedules ws ON ws.id = es.schedule_id
                          WHERE d.ddtr_id = ? ORDER BY ws.description");
    $q3->bind_param('ssi', $batch['date_to'], $batch['date_from'], $ddtrId);
    $q3->execute();
    $r3 = $q3->get_result();
    while ($row = $r3->fetch_assoc()) {
        $out['schedules'][] = [
            'id'   => (int)$row['id'],
            'name' => $row['name'] . ' (' . date('g:i A', strtotime($row['start_time']))
                    . '–' . date('g:i A', strtotime($row['end_time'])) . ')',
        ];
    }

    header('Content-Type: application/json');
    echo json_encode(['result' => true] + $out);
    exit;
}

if ($action === 'docs') {
    // ── Document viewer (dtr-documents.php) ─────────────────────────────────
    // One page of employees as structured JSON. Each record's biometric/manual
    // logs are merged per day and split into the four Form-48 cells
    // (AM arrival/departure, PM arrival/departure); the client renders the
    // paper document, so no HTML leaves this endpoint.
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = (int)($_GET['limit'] ?? 20);
    if ($limit <= 0 || $limit > 100) $limit = 20;
    $q = trim((string)($_GET['q'] ?? ''));

    $where  = "d.ddtr_id = ?";
    $types  = 'i';
    $params = [$ddtrId];

    if ($q !== '') {
        $where .= " AND (e.lastname LIKE ? OR e.firstname LIKE ? OR e.middlename LIKE ?
                         OR e.employee_no LIKE ? OR p.name LIKE ? OR dep.name LIKE ?)";
        $like = '%' . $q . '%';
        $types .= 'ssssss';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    // status filter → employees by their approval state within this batch:
    //   pending     = has at least one undecided record
    //   approved    = every record approved
    //   disapproved = has at least one rejected record
    $statusF = (string)($_GET['status'] ?? '');
    $idInt   = (int)$ddtrId;
    if ($statusF === 'pending') {
        $where .= " AND e.id IN (SELECT employee_id FROM DTR_details
                                 WHERE ddtr_id = $idInt AND status <> 1 AND status <> 2)";
    } elseif ($statusF === 'approved') {
        $where .= " AND e.id NOT IN (SELECT employee_id FROM DTR_details
                                     WHERE ddtr_id = $idInt AND status <> 1)";
    } elseif ($statusF === 'disapproved') {
        $where .= " AND e.id IN (SELECT employee_id FROM DTR_details
                                 WHERE ddtr_id = $idInt AND status = 2)";
    }

    // Employee-attribute filters (popover): department, position.
    $depF = (int)($_GET['dep'] ?? 0);
    if ($depF > 0) $where .= " AND e.department_id = $depF";
    $posF = (int)($_GET['pos'] ?? 0);
    if ($posF > 0) $where .= " AND e.position_id = $posF";

    // Batch period, escaped once — several of the filters below are period-scoped.
    $bF = $conn->real_escape_string((string)($batch['date_from'] ?? ''));
    $bT = $conn->real_escape_string((string)($batch['date_to'] ?? ''));

    // Schedule filter: employees on a given work schedule at any point in the
    // batch period. An employee whose schedule changed mid-period matches both.
    $schF = (int)($_GET['sch'] ?? 0);
    if ($schF > 0 && $bF !== '' && $bT !== '') {
        $where .= " AND e.id IN (SELECT employee_id FROM employee_schedules
                                 WHERE schedule_id = $schF AND effective_from <= '$bT'
                                   AND (effective_to IS NULL OR effective_to >= '$bF'))";
    }

    // "Has …" filters — employees with at least one day of the given kind.
    // Unlike the exception flags above these are not problems, they are things
    // a reviewer often wants to isolate (pay OT, check leave, audit night diff).
    $hasF = (string)($_GET['has'] ?? '');
    $dayConds = [
        'ot'   => "overtime > 0",
        'late' => "late > 0",
        'ut'   => "undertime > 0",
        'nsd'  => "nsd_hours > 0",
        'hol'  => "day_type <> 'regular'",
    ];
    if (isset($dayConds[$hasF])) {
        $where .= " AND e.id IN (SELECT employee_id FROM DTR_details
                                 WHERE ddtr_id = $idInt AND {$dayConds[$hasF]})";
    } elseif ($hasF === 'leave' && $bF !== '') {
        $where .= " AND e.id IN (SELECT employee_id FROM leave_requests
                                 WHERE status IN (0,1) AND date_from <= '$bT' AND date_to >= '$bF')";
    } elseif ($hasF === 'req' && $bF !== '') {
        $where .= " AND e.id IN (SELECT employee_id FROM attendance_requests
                                 WHERE status IN (0,1) AND request_date BETWEEN '$bF' AND '$bT')";
    } elseif ($hasF === 'lvclash') {
        // Worked hours AND an approved leave on the SAME day. Payroll caps the
        // two at one day, but the reviewer should still eyeball these — it is
        // usually either a half-day that was never flagged as one, or a leave
        // filed for a day the employee actually came in.
        $where .= " AND e.id IN (SELECT d3.employee_id FROM DTR_details d3
                                 INNER JOIN leave_requests lr
                                    ON lr.employee_id = d3.employee_id AND lr.status = 1
                                   AND d3.date_time BETWEEN lr.date_from AND lr.date_to
                                 WHERE d3.ddtr_id = $idInt AND d3.work_hours > 0)";
    }

    // Activity filters: employees with internal admin notes or a message thread
    // in this batch. Guarded with table-existence so older DBs don't error.
    $actF = (string)($_GET['act'] ?? '');
    if ($actF === 'notes') {
        $has = $conn->query("SHOW TABLES LIKE 'dtr_admin_notes'");
        if ($has && $has->num_rows) {
            $where .= " AND e.id IN (SELECT employee_id FROM dtr_admin_notes WHERE ddtr_id = $idInt)";
        }
    } elseif ($actF === 'msgs') {
        $has = $conn->query("SHOW TABLES LIKE 'dtr_messages'");
        if ($has && $has->num_rows) {
            $where .= " AND e.id IN (SELECT m.employee_id FROM dtr_messages m
                                     INNER JOIN DTR_details d2 ON d2.id = m.dtr_detail_id
                                     WHERE d2.ddtr_id = $idInt)";
        }
    }

    // Employee sign-off filter (erv): 1 confirmed, 2 disputed, 0 not yet
    // reviewed, m = left a message with their sign-off. Mirrors the payroll
    // workbench's "Employee review" chips.
    $ervF = (string)($_GET['erv'] ?? '');
    if ($ervF !== '') {
        $hasRv = $conn->query("SHOW TABLES LIKE 'dtr_employee_reviews'");
        if ($hasRv && $hasRv->num_rows) {
            $sub = "SELECT employee_id FROM dtr_employee_reviews WHERE ddtr_id = $idInt";
            if ($ervF === 'm') {
                $where .= " AND e.id IN ($sub AND comment IS NOT NULL AND TRIM(comment) <> '')";
            } elseif ($ervF === '0') {
                $where .= " AND e.id NOT IN ($sub AND status IN (1,2))";
            } elseif ($ervF === '1' || $ervF === '2') {
                $where .= " AND e.id IN ($sub AND status = " . (int)$ervF . ")";
            }
        }
    }

    // flag=<key> → employees with a specific exception on a still-pending
    // record (mirrors the client's recFlags rules); low_att is per-employee.
    $flagF = (string)($_GET['flag'] ?? '');
    if ($flagF !== '') {
        $otMax = (float)DTR_HIGH_OT_HOURS;
        $flagConds = [
            'no_out'     => "NOT (JSON_VALID(logs) AND JSON_LENGTH(logs) >= 2)",
            'zero_hours' => "work_hours <= 0",
            'high_ot'    => "overtime > $otMax",
            'manual'     => "(logs LIKE '%\"manual\"%' OR logs LIKE '%\"incident\"%')",
        ];
        if (isset($flagConds[$flagF])) {
            $where .= " AND e.id IN (SELECT employee_id FROM DTR_details
                                     WHERE ddtr_id = $idInt AND status = 0 AND {$flagConds[$flagF]})";
        } elseif ($flagF === 'low_att' && $minDays > 0) {
            $where .= " AND e.id IN (SELECT employee_id FROM DTR_details WHERE ddtr_id = $idInt
                                     GROUP BY employee_id HAVING COUNT(DISTINCT date_time) < $minDays)";
        }
    }

    // flagged=1 → only employees that still need a human decision: a pending
    // record with an exception flag, or attendance below the batch minimum.
    if (!empty($_GET['flagged'])) {
        $idInt  = (int)$ddtrId;
        $recBad = "SELECT DISTINCT employee_id FROM DTR_details
                   WHERE ddtr_id = $idInt AND status = 0
                     AND NOT (work_hours > 0 AND overtime <= " . (float)DTR_HIGH_OT_HOURS . "
                              AND JSON_VALID(logs) AND JSON_LENGTH(logs) >= 2)";
        $where .= " AND (e.id IN ($recBad)";
        if ($minDays > 0) {
            $where .= " OR e.id IN (SELECT employee_id FROM DTR_details WHERE ddtr_id = $idInt
                                    GROUP BY employee_id HAVING COUNT(DISTINCT date_time) < $minDays)";
        }
        $where .= ")";
    }

    $cs = $conn->prepare("SELECT COUNT(DISTINCT e.id) AS total
                          FROM DTR_details d
                          INNER JOIN employee e ON d.employee_id = e.id
                          LEFT JOIN position p ON e.position_id = p.id
                          LEFT JOIN department dep ON e.department_id = dep.id
                          WHERE $where");
    $cs->bind_param($types, ...$params);
    $cs->execute();
    $total = (int)($cs->get_result()->fetch_assoc()['total'] ?? 0);

    $is = $conn->prepare("SELECT e.id
                          FROM DTR_details d
                          INNER JOIN employee e ON d.employee_id = e.id
                          LEFT JOIN position p ON e.position_id = p.id
                          LEFT JOIN department dep ON e.department_id = dep.id
                          WHERE $where
                          GROUP BY e.id
                          ORDER BY e.lastname ASC, e.firstname ASC
                          LIMIT ?, ?");
    $is->bind_param($types . 'ii', ...array_merge($params, [$offset, $limit]));
    $is->execute();
    $empIds = [];
    $idRes = $is->get_result();
    while ($row = $idRes->fetch_assoc()) $empIds[] = (int)$row['id'];

    $employees = [];
    if ($empIds) {
        $idList = implode(',', $empIds);

        // Full admin↔employee thread per record (dtr_messages); the guard keeps
        // this page working on databases where the table doesn't exist yet.
        $msgMap = [];
        $mq = @$conn->query("SELECT m.dtr_detail_id, m.message, m.created_at, m.sender_type, u.name AS sender
                             FROM dtr_messages m LEFT JOIN users u ON u.id = m.sent_by
                             WHERE m.employee_id IN ($idList) ORDER BY m.id ASC");
        if ($mq) {
            while ($m = $mq->fetch_assoc()) {
                $msgMap[(int)$m['dtr_detail_id']][] = [
                    'from' => ($m['sender_type'] === 'employee') ? 'emp' : 'admin',
                    'msg'  => $m['message'],
                    'by'   => $m['sender'] ?? '',
                    'at'   => date('M j, g:i A', strtotime($m['created_at'])),
                ];
            }
        }

        $rs = $conn->prepare("
            SELECT a.*, e.employee_no, e.lastname, e.firstname, e.middlename,
                   dep.name AS department, p.name AS position,
                   du.name AS decided_by_name,
                   DATE(a.date_time) AS attendance_date
            FROM DTR_details a
            INNER JOIN employee e ON a.employee_id = e.id
            LEFT JOIN department dep ON e.department_id = dep.id
            LEFT JOIN position p ON e.position_id = p.id
            LEFT JOIN users du ON a.decided_by = du.id
            WHERE a.ddtr_id = ? AND a.employee_id IN ($idList)
            ORDER BY a.date_time ASC
        ");
        $rs->bind_param('i', $ddtrId);
        $rs->execute();
        $res = $rs->get_result();

        $byEmp = [];
        while ($row = $res->fetch_assoc()) {
            $eid = (int)$row['employee_id'];
            if (!isset($byEmp[$eid])) {
                $byEmp[$eid] = [
                    'id'         => $eid,
                    'no'         => $row['employee_no'],
                    'lastname'   => $row['lastname'],
                    'firstname'  => $row['firstname'],
                    'middlename' => $row['middlename'] ?? '',
                    'position'   => $row['position'] ?? '',
                    'department' => $row['department'] ?? '',
                    'totals'     => ['wh' => 0, 'ot' => 0, 'ut' => 0, 'late' => 0],
                    'appr'       => 0, 'pend' => 0, 'disa' => 0, 'exc' => 0,
                    '_logs'      => [],   // per-date raw log timestamps, merged across records
                    'days'       => [],   // per-date aggregates + the four form cells
                ];
            }
            $E = &$byEmp[$eid];
            $date = $row['attendance_date'];
            if (!isset($E['days'][$date])) {
                $E['days'][$date] = ['wh' => 0, 'ot' => 0, 'ut' => 0, 'late' => 0, 'status' => 1, 'logs' => 0, 'recs' => [], 'note' => '', 'sched_id' => null];
                $E['_logs'][$date] = [];
            }
            $D = &$E['days'][$date];
            // DTR_details.notes for the day — surfaced on hover over the day
            // number. A date can carry more than one record (a split shift, a
            // manual correction), so keep each distinct note rather than the last.
            $rowNote = trim((string)($row['notes'] ?? ''));
            if ($rowNote !== '' && strpos($D['note'], $rowNote) === false) {
                $D['note'] = $D['note'] === '' ? $rowNote : $D['note'] . ' · ' . $rowNote;
            }
            // The shift frozen on the row when the punch was recorded. This is
            // what the day was actually worked under, so it outranks anything
            // the roster says now — see the marks loop below.
            if ($D['sched_id'] === null && !empty($row['schedule_id'])) {
                $D['sched_id'] = (int) $row['schedule_id'];
            }
            $D['wh']   += (float)$row['work_hours'];
            $D['ot']   += (float)$row['overtime'];
            $D['ut']   += (float)$row['undertime'];
            $D['late'] += (float)$row['late'];
            $E['totals']['wh']   += (float)$row['work_hours'];
            $E['totals']['ot']   += (float)$row['overtime'];
            $E['totals']['ut']   += (float)$row['undertime'];
            $E['totals']['late'] += (float)$row['late'];

            $s = (int)$row['status'];
            if ($s === 1)     $E['appr']++;
            elseif ($s === 2) $E['disa']++;
            else              $E['pend']++;
            // Day status: any disapproved wins, then any pending, else approved
            if ($s === 2)                          $D['status'] = 2;
            elseif ($s !== 1 && $D['status'] !== 2) $D['status'] = 0;

            $recLogs   = [];
            $hasManual = false;
            foreach ((json_decode($row['logs']) ?: []) as $lg) {
                $ts = strtotime($lg->dateTime);
                if ($ts !== false) {
                    $E['_logs'][$date][] = $ts;
                    $isBio = (($lg->type ?? '') === 'bio');
                    if (!$isBio) $hasManual = true;
                    $recLogs[] = ['t' => date('g:i A', $ts), 'bio' => $isBio];
                }
                $D['logs']++;
            }

            // Exception flags. The first three block clean bulk-approval (rule
            // shared with dtr_clean_condition_sql); 'manual' is informational.
            $wh = (float)$row['work_hours'];
            $ot = (float)$row['overtime'];
            $flags = [];
            if (count($recLogs) < 2)        $flags[] = 'no_out';
            if ($wh <= 0)                   $flags[] = 'zero_hours';
            if ($ot > DTR_HIGH_OT_HOURS)    $flags[] = 'high_ot';
            if ($hasManual)                 $flags[] = 'manual';
            $isException = (count($recLogs) < 2 || $wh <= 0 || $ot > DTR_HIGH_OT_HOURS);
            if ($isException && $s !== 1 && $s !== 2) $E['exc']++;

            $D['recs'][] = [
                'id'     => (int)$row['id'],
                'status' => $s,
                'wh'     => $wh,
                'ot'     => $ot,
                'ut'     => (float)$row['undertime'],
                'late'   => (float)$row['late'],
                'logs'   => $recLogs,
                'flags'  => $flags,
                'note'   => $row['decision_note'] ?? '',
                'by'     => $row['decided_by_name'] ?? '',
                'at'     => !empty($row['decided_at']) ? date('M j, g:i A', strtotime($row['decided_at'])) : '',
                'msgs'   => $msgMap[(int)$row['id']] ?? [],
            ];
            unset($E, $D);
        }

        // ── Day markers: holidays, leaves, day-offs, portal requests ────────
        // Context the Form 48 sheet flags per day so a blank row explains
        // itself (holiday / on leave / rest day) and a logged day shows a
        // pending OT/incident request. Statuses: 0 pending, 1 approved.
        $dFrom = $batch['date_from'];
        $dTo   = $batch['date_to'];

        $holidays = [];   // 'Y-m-d' => ['t' => 'legal'|'special', 'lbl' => title]
        $hq = $conn->prepare("SELECT title, start_date, end_date, type FROM calendar_events
                              WHERE type IN (1,3) AND start_date <= ?
                                AND COALESCE(end_date, start_date) >= ?");
        $hq->bind_param('ss', $dTo, $dFrom);
        $hq->execute();
        $hres = $hq->get_result();
        while ($h = $hres->fetch_assoc()) {
            $hs = max(strtotime($h['start_date']), strtotime($dFrom));
            $he = min(strtotime($h['end_date'] ?: $h['start_date']), strtotime($dTo));
            for ($d = $hs; $d <= $he; $d = strtotime('+1 day', $d)) {
                $holidays[date('Y-m-d', $d)] = ['t' => ((int)$h['type'] === 1 ? 'legal' : 'special'), 'lbl' => $h['title']];
            }
        }

        $leaveMap = [];   // eid => 'Y-m-d' => ['lbl','s','half']
        $lq = $conn->prepare("SELECT lr.employee_id, lr.dates, lr.date_from, lr.date_to, lr.status,
                                     lr.is_half_day, lr.half_date, lt.name AS type_name
                              FROM leave_requests lr
                              INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                              WHERE lr.employee_id IN ($idList) AND lr.status IN (0,1)
                                AND lr.date_from <= ? AND lr.date_to >= ?");
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

        $reqMap = [];     // eid => 'Y-m-d' => [['t' => 'incident'|'overtime', 's', 'h'], ...]
        $aq = $conn->prepare("SELECT employee_id, request_type, request_date, status,
                                     COALESCE(ot_hours_requested, 0) AS hrs
                              FROM attendance_requests
                              WHERE employee_id IN ($idList) AND status IN (0,1)
                                AND request_date BETWEEN ? AND ?");
        $aq->bind_param('ss', $dFrom, $dTo);
        $aq->execute();
        $ares = $aq->get_result();
        while ($r = $ares->fetch_assoc()) {
            $reqMap[(int)$r['employee_id']][$r['request_date']][] =
                ['t' => $r['request_type'], 's' => (int)$r['status'], 'h' => (float)$r['hrs']];
        }

        // EVERY schedule window these employees have, not just the ones overlapping
        // the period. pick_schedule_window() falls back to the nearest period when
        // none covers a date, and it cannot fall back to rows that were never
        // fetched — an April batch for someone whose first assignment starts in May
        // came back with no shift at all, so no chip, no hover and no summary.
        // Shift catalogue, keyed by id — for resolving DTR_details.schedule_id
        // (the shift stamped on the row) without a join per day.
        $wsById = [];
        $wsq = $conn->query("SELECT id, description, start_time, end_time, is_graveyard FROM work_schedules");
        if ($wsq) while ($w = $wsq->fetch_assoc()) $wsById[(int)$w['id']] = $w;

        $schedMap = [];   // eid => all schedule windows (effective_from ASC)
        $sq = $conn->prepare("SELECT es.employee_id, es.rest_days, es.effective_from, es.effective_to,
                                     ws.description AS sched_name, ws.start_time, ws.end_time, ws.is_graveyard
                              FROM employee_schedules es
                              LEFT JOIN work_schedules ws ON ws.id = es.schedule_id
                              WHERE es.employee_id IN ($idList)
                              ORDER BY es.effective_from ASC");
        $sq->execute();
        $sres = $sq->get_result();
        while ($sr = $sres->fetch_assoc()) $schedMap[(int)$sr['employee_id']][] = $sr;

        // Internal admin notes for these employees in this batch (admin-only,
        // never sent to the employee side). Guarded for older databases.
        $noteMap = [];
        $nq = @$conn->query("SELECT n.id, n.employee_id, n.level, n.note, n.created_at, u.name AS author
                             FROM dtr_admin_notes n LEFT JOIN users u ON u.id = n.created_by
                             WHERE n.ddtr_id = " . (int)$ddtrId . " AND n.employee_id IN ($idList)
                             ORDER BY n.id ASC");
        if ($nq) {
            while ($nr = $nq->fetch_assoc()) {
                $noteMap[(int)$nr['employee_id']][] = [
                    'id'    => (int)$nr['id'],
                    'level' => $nr['level'],
                    'note'  => $nr['note'],
                    'by'    => $nr['author'] ?? '',
                    'at'    => date('M j, g:i A', strtotime($nr['created_at'])),
                ];
            }
        }

        foreach ($empIds as $eid) {
            if (!isset($byEmp[$eid])) continue;
            $E = $byEmp[$eid];
            $E['notes'] = $noteMap[$eid] ?? [];
            // Below the batch's minimum logged days → "Low attendance": marked
            // as an exception in the UI and excluded from clean bulk-approval.
            $E['low_att'] = ($minDays > 0 && count($E['days']) < $minDays);
            foreach ($E['days'] as $date => $d) {
                $logs = $E['_logs'][$date];
                sort($logs);
                $f = function ($ts) { return date('g:i', $ts); };
                // Single-mode columns are just Arrival/Departure, so unlike the
                // positional A.M./P.M. grid the cell itself must say which half
                // of the day the punch fell on.
                $fa = function ($ts) { return date('g:i A', $ts); };
                $n = count($logs);
                // Single-mode cells: plain first-in / last-out for the day.
                $cells = ['am_in' => '', 'am_out' => '', 'pm_in' => '', 'pm_out' => '', 'in' => '', 'out' => '',
                          'in_off' => 0, 'out_off' => 0];
                if ($n >= 1) $cells['in']  = $fa($logs[0]);
                if ($n >= 2) $cells['out'] = $fa($logs[$n - 1]);
                // How many calendar days past the row's own date each punch fell
                // on. A night shift's out is stored on the day the shift STARTED,
                // so "6:10" alone reads as if they left before they arrived —
                // the sheet renders these as "6:10 +1".
                $dayOff = function ($ts) use ($date) {
                    return (int) (new DateTime($date))->diff(new DateTime(date('Y-m-d', $ts)))->format('%r%a');
                };
                if ($n >= 1) $cells['in_off']  = max(0, $dayOff($logs[0]));
                if ($n >= 2) $cells['out_off'] = max(0, $dayOff($logs[$n - 1]));
                // Full stamp for the marker's tooltip — the cell shows only the
                // clock time, so the tooltip carries the actual calendar date.
                $tip = function ($ts) { return date('D, M j, Y · g:i A', $ts); };
                if ($cells['in_off']  > 0) $cells['in_tip']  = $tip($logs[0]);
                if ($cells['out_off'] > 0) $cells['out_tip'] = $tip($logs[$n - 1]);
                // Positional mapping, the way the paper form is filled:
                // in / lunch-out / lunch-in / out. Odd counts fall back on the
                // clock: a middle log before 1 PM is the lunch-out, after is the
                // lunch-in; a lone log lands on arrival (AM or PM by its hour).
                if ($n >= 4) {
                    $cells['am_in']  = $f($logs[0]);
                    $cells['am_out'] = $f($logs[1]);
                    $cells['pm_in']  = $f($logs[$n - 2]);
                    $cells['pm_out'] = $f($logs[$n - 1]);
                } elseif ($n === 3) {
                    $cells['am_in']  = $f($logs[0]);
                    $cells['pm_out'] = $f($logs[2]);
                    if ((int)date('G', $logs[1]) < 13) $cells['am_out'] = $f($logs[1]);
                    else                               $cells['pm_in']  = $f($logs[1]);
                } elseif ($n === 2) {
                    $h0 = (int)date('G', $logs[0]);
                    $h1 = (int)date('G', $logs[1]);
                    if ($h1 < 12)      { $cells['am_in'] = $f($logs[0]); $cells['am_out'] = $f($logs[1]); }
                    elseif ($h0 >= 12) { $cells['pm_in'] = $f($logs[0]); $cells['pm_out'] = $f($logs[1]); }
                    else               { $cells['am_in'] = $f($logs[0]); $cells['pm_out'] = $f($logs[1]); }
                } elseif ($n === 1) {
                    if ((int)date('G', $logs[0]) < 12) $cells['am_in'] = $f($logs[0]);
                    else                               $cells['pm_in'] = $f($logs[0]);
                }
                $E['days'][$date] = array_merge($d, $cells);
            }
            unset($E['_logs']);

            // Per-day markers (only days that have at least one are emitted).
            $marks = [];
            $sch = $schedMap[$eid] ?? [];
            for ($d = strtotime($dFrom); $d <= strtotime($dTo); $d = strtotime('+1 day', $d)) {
                $ymd = date('Y-m-d', $d);
                $m = [];
                if (isset($holidays[$ymd]))       $m[] = ['k' => 'holiday'] + $holidays[$ymd];
                if (isset($leaveMap[$eid][$ymd])) $m[] = ['k' => 'leave'] + $leaveMap[$eid][$ymd];
                // Rest day: the latest schedule window covering this day wins.
                // Deliberately covering-only — marking someone off duty on the
                // strength of an assignment that doesn't cover the day would be
                // inventing a day off.
                $rest = null;
                foreach ($sch as $srow) {
                    if ($srow['effective_from'] <= $ymd
                        && (empty($srow['effective_to']) || $srow['effective_to'] >= $ymd)) {
                        $rest = (string)$srow['rest_days'];
                    }
                }
                // Which shift the day was worked under. A period that actually
                // COVERS the date is a fact; the nearest-period fallback is a
                // guess, and printing the two identically made a month with no
                // assignment look like a month of uniform shifts. The guess is
                // still shown — an admin needs to see which shift the figures
                // were computed against — but flagged `inf` so the sheet can
                // render it as inferred rather than asserted.
                // 1. The shift STAMPED on the DTR row wins outright. It is what
                //    the day was recorded under, so it survives any later roster
                //    edit and is never a guess.
                $stampId = $E['days'][$ymd]['sched_id'] ?? null;
                $shift = null;
                $inf   = 0;
                if ($stampId && isset($wsById[$stampId])) {
                    $ws    = $wsById[$stampId];
                    $shift = [
                        'sched_name'  => $ws['description'],
                        'start_time'  => $ws['start_time'],
                        'end_time'    => $ws['end_time'],
                        'is_graveyard' => $ws['is_graveyard'],
                    ];
                } else {
                    // 2. Unstamped day (no attendance, or a row predating the
                    //    column) — fall back to employee_schedules. A period that
                    //    covers the date is a fact; the nearest one is a guess and
                    //    is flagged `inf` so the sheet renders it as inferred.
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
                if ($rest !== null && $rest !== ''
                    && in_array((int)date('w', $d), array_map('intval', explode(',', $rest)), true)) {
                    $m[] = ['k' => 'off'];
                }
                foreach (($reqMap[$eid][$ymd] ?? []) as $rq) $m[] = ['k' => 'req'] + $rq;
                if ($m) $marks[$ymd] = $m;
            }
            $E['marks'] = $marks ?: new stdClass();   // {} not [] when empty

            $employees[] = $E;
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'result'    => true,
        'employees' => $employees,
        'offset'    => $offset,
        'limit'     => $limit,
        'total'     => $total,
        'config'    => [
            'mode'     => DTR_LOG_MODE,
            'ot_hours' => (float)DTR_HIGH_OT_HOURS,
            'min_days' => $minDays,
            'low_pct'  => (int)DTR_LOW_ATTENDANCE_PCT,
        ],
    ]);
    exit;
}

if ($action === 'list') {
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = (int)($_GET['limit'] ?? 10);
    if ($limit <= 0 || $limit > 100) $limit = 10;
    $q = trim((string)($_GET['q'] ?? ''));

    // ── Which employees are on this page ────────────────────────────────────
    // Paginate over employees (not records) so a card is never split across
    // pages. Sorted by lastname to match the old in-PHP uasort.
    $where  = "d.ddtr_id = ?";
    $types  = 'i';
    $params = [$ddtrId];

    if ($q !== '') {
        $where .= " AND (e.lastname LIKE ? OR e.firstname LIKE ? OR e.middlename LIKE ?
                         OR e.employee_no LIKE ? OR p.name LIKE ? OR dep.name LIKE ?)";
        $like = '%' . $q . '%';
        $types .= 'ssssss';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $countSql = "SELECT COUNT(DISTINCT e.id) AS total
                 FROM DTR_details d
                 INNER JOIN employee e ON d.employee_id = e.id
                 LEFT JOIN position p ON e.position_id = p.id
                 LEFT JOIN department dep ON e.department_id = dep.id
                 WHERE $where";
    $cs = $conn->prepare($countSql);
    $cs->bind_param($types, ...$params);
    $cs->execute();
    $total = (int)($cs->get_result()->fetch_assoc()['total'] ?? 0);

    $idSql = "SELECT e.id
              FROM DTR_details d
              INNER JOIN employee e ON d.employee_id = e.id
              LEFT JOIN position p ON e.position_id = p.id
              LEFT JOIN department dep ON e.department_id = dep.id
              WHERE $where
              GROUP BY e.id
              ORDER BY e.lastname ASC, e.firstname ASC
              LIMIT ?, ?";
    $is = $conn->prepare($idSql);
    $is->bind_param($types . 'ii', ...array_merge($params, [$offset, $limit]));
    $is->execute();
    $empIds = [];
    $idRes = $is->get_result();
    while ($row = $idRes->fetch_assoc()) $empIds[] = (int)$row['id'];

    $html = '';
    if ($empIds) {
        // Only this page's records — the whole point of the endpoint.
        $idList = implode(',', $empIds);
        $rs = $conn->prepare("
            SELECT a.*, e.employee_no, e.lastname, e.firstname, e.middlename,
                   dep.name AS department, p.name AS position,
                   DATE(a.date_time) AS attendance_date
            FROM DTR_details a
            INNER JOIN employee e ON a.employee_id = e.id
            LEFT JOIN department dep ON e.department_id = dep.id
            LEFT JOIN position p ON e.position_id = p.id
            WHERE a.ddtr_id = ? AND a.employee_id IN ($idList)
            ORDER BY a.date_time ASC
        ");
        $rs->bind_param('i', $ddtrId);
        $rs->execute();
        $res = $rs->get_result();

        $byEmployee = $employeeTotals = [];
        while ($row = $res->fetch_assoc()) {
            $eid  = (int)$row['employee_id'];
            $date = $row['attendance_date'];
            if (!isset($byEmployee[$eid])) {
                $byEmployee[$eid]     = ['employee_info' => $row, 'dates' => []];
                $employeeTotals[$eid] = ['work_hours' => 0, 'overtime' => 0, 'undertime' => 0, 'late' => 0];
            }
            $byEmployee[$eid]['dates'][$date][] = $row;
            $employeeTotals[$eid]['work_hours'] += floatval($row['work_hours']);
            $employeeTotals[$eid]['overtime']   += floatval($row['overtime']);
            $employeeTotals[$eid]['undertime']  += floatval($row['undertime']);
            $employeeTotals[$eid]['late']       += floatval($row['late']);
        }

        // Emit in the order the id query returned (lastname), not hash order
        ob_start();
        foreach ($empIds as $eid) {
            if (!isset($byEmployee[$eid])) continue;
            render_dtr_employee_card($eid, $byEmployee[$eid], $employeeTotals[$eid], $login_role);
        }
        $html = preg_replace(['/^[ \t]+/m', '/\n{2,}/'], ['', "\n"], ob_get_clean());
    }

    header('Content-Type: application/json');
    echo json_encode([
        'result'  => true,
        'html'    => $html,
        'offset'  => $offset,
        'limit'   => $limit,
        'loaded'  => count($empIds),
        'total'   => $total,
        'hasMore' => ($offset + count($empIds)) < $total,
    ]);
    exit;
}

if ($action === 'print') {
    // The by-day print table, built only when the user actually prints.
    $rs = $conn->prepare("
        SELECT a.*, e.employee_no, e.lastname, e.firstname, e.middlename,
               dep.name AS department, p.name AS position,
               DATE(a.date_time) AS attendance_date
        FROM DTR_details a
        INNER JOIN employee e ON a.employee_id = e.id
        LEFT JOIN department dep ON e.department_id = dep.id
        LEFT JOIN position p ON e.position_id = p.id
        WHERE a.ddtr_id = ?
        ORDER BY a.date_time ASC, e.lastname ASC
    ");
    $rs->bind_param('i', $ddtrId);
    $rs->execute();
    $res = $rs->get_result();

    $groupedData = $dateTotals = [];
    $grand = ['work_hours' => 0, 'overtime' => 0, 'undertime' => 0, 'late' => 0];
    while ($row = $res->fetch_assoc()) {
        $date = $row['attendance_date'];
        $eid  = (int)$row['employee_id'];
        if (!isset($groupedData[$date])) {
            $groupedData[$date] = [];
            $dateTotals[$date]  = ['work_hours' => 0, 'overtime' => 0, 'undertime' => 0, 'late' => 0];
        }
        if (!isset($groupedData[$date][$eid])) {
            $groupedData[$date][$eid] = ['employee_info' => $row, 'totals' => ['work_hours' => 0, 'overtime' => 0, 'undertime' => 0, 'late' => 0]];
        }
        foreach (['work_hours', 'overtime', 'undertime', 'late'] as $k) {
            $v = floatval($row[$k]);
            $groupedData[$date][$eid]['totals'][$k] += $v;
            $dateTotals[$date][$k] += $v;
            $grand[$k] += $v;
        }
    }

    ob_start(); ?>
    <div class="print-header">
        <h2>Daily Time Record (DTR) Details</h2>
        <div class="print-info">
            <p><strong>Period:</strong> <?= date('F d', strtotime($batch['date_from'])) ?> - <?= date('F d, Y', strtotime($batch['date_to'])) ?></p>
            <p><strong>Site:</strong> <?= htmlspecialchars($batch['site_name']) ?> (<?= htmlspecialchars($batch['site_code']) ?>)</p>
            <p><strong>Employer:</strong> <?= htmlspecialchars($batch['employer_name']) ?></p>
        </div>
        <div class="print-summary">
            <div class="summary-item"><span class="label">Total Hours</span><span class="value"><?= number_format($grand['work_hours'], 2) ?></span></div>
            <div class="summary-item"><span class="label">Overtime</span><span class="value"><?= number_format($grand['overtime'], 2) ?></span></div>
            <div class="summary-item"><span class="label">Undertime</span><span class="value"><?= number_format($grand['undertime'], 2) ?></span></div>
            <div class="summary-item"><span class="label">Late</span><span class="value"><?= number_format($grand['late'], 2) ?></span></div>
        </div>
    </div>
    <table class="print-table">
        <thead>
            <tr>
                <th>Date</th><th>Employee No.</th><th>Employee Name</th><th>Department</th><th>Position</th>
                <th>Hours</th><th>OT</th><th>UT</th><th>Late</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groupedData as $date => $emps): ?>
            <tr class="date-separator">
                <td colspan="9" class="date-header">
                    <strong><?= date("l, F j, Y", strtotime($date)) ?></strong>
                    <span class="employee-count">(<?= count($emps) ?> employees)</span>
                    <span class="date-totals">
                        Hours: <?= number_format($dateTotals[$date]['work_hours'], 2) ?> |
                        OT: <?= number_format($dateTotals[$date]['overtime'], 2) ?> |
                        Late: <?= number_format($dateTotals[$date]['late'], 2) ?>
                    </span>
                </td>
            </tr>
            <?php foreach ($emps as $eid => $ed):
                $i = $ed['employee_info']; $t = $ed['totals']; ?>
            <tr class="employee-row">
                <td><?= date("M j", strtotime($date)) ?></td>
                <td><?= htmlspecialchars($i['employee_no']) ?></td>
                <td class="employee-name"><?= htmlspecialchars($i['lastname'] . ', ' . $i['firstname'] . ' ' . ($i['middlename'] ?? '')) ?></td>
                <td><?= htmlspecialchars($i['department']) ?></td>
                <td><?= htmlspecialchars($i['position']) ?></td>
                <td class="text-center"><?= number_format($t['work_hours'], 2) ?></td>
                <td class="text-center"><?= number_format($t['overtime'], 2) ?></td>
                <td class="text-center"><?= number_format($t['undertime'], 2) ?></td>
                <td class="text-center"><?= number_format($t['late'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
            <tr class="grand-total">
                <td colspan="5" class="text-end"><strong>Grand Total:</strong></td>
                <td class="text-center"><strong><?= number_format($grand['work_hours'], 2) ?></strong></td>
                <td class="text-center"><strong><?= number_format($grand['overtime'], 2) ?></strong></td>
                <td class="text-center"><strong><?= number_format($grand['undertime'], 2) ?></strong></td>
                <td class="text-center"><strong><?= number_format($grand['late'], 2) ?></strong></td>
            </tr>
        </tbody>
    </table>
    <div class="print-footer"><p>Generated on: <?= date('F j, Y g:i A') ?></p></div>
    <?php
    header('Content-Type: application/json');
    echo json_encode(['result' => true, 'html' => ob_get_clean()]);
    exit;
}

http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['result' => false, 'message' => 'Unknown action']);
