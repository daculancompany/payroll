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

session_start();

if (empty($_SESSION['is_login'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['result' => false, 'message' => 'Not authenticated']);
    exit;
}

include 'db_connect.php';
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
                $E['days'][$date] = ['wh' => 0, 'ot' => 0, 'ut' => 0, 'late' => 0, 'status' => 1, 'logs' => 0, 'recs' => []];
                $E['_logs'][$date] = [];
            }
            $D = &$E['days'][$date];
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
            ];
            unset($E, $D);
        }

        foreach ($empIds as $eid) {
            if (!isset($byEmp[$eid])) continue;
            $E = $byEmp[$eid];
            // Below the batch's minimum logged days → "Low attendance": marked
            // as an exception in the UI and excluded from clean bulk-approval.
            $E['low_att'] = ($minDays > 0 && count($E['days']) < $minDays);
            foreach ($E['days'] as $date => $d) {
                $logs = $E['_logs'][$date];
                sort($logs);
                $f = function ($ts) { return date('g:i', $ts); };
                $n = count($logs);
                // Single-mode cells: plain first-in / last-out for the day.
                $cells = ['am_in' => '', 'am_out' => '', 'pm_in' => '', 'pm_out' => '', 'in' => '', 'out' => ''];
                if ($n >= 1) $cells['in']  = $f($logs[0]);
                if ($n >= 2) $cells['out'] = $f($logs[$n - 1]);
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
