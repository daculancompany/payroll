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

/**
 * Batch-wide approval counters. Always computed over every record of the batch,
 * never over the loaded page, so the summary cards stay honest while only ten
 * employees are on screen.
 */
function dtr_batch_summary(mysqli $conn, $ddtrId)
{
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
            COUNT(DISTINCT date_time)                     AS days
        FROM DTR_details WHERE ddtr_id = ?
    ");
    $stmt->bind_param('i', $ddtrId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    foreach (['total','approved','disapproved','pending','employees','days'] as $k) {
        $r[$k] = (int)($r[$k] ?? 0);
    }
    foreach (['work_hours','overtime','undertime','late'] as $k) {
        $r[$k] = (float)($r[$k] ?? 0);
    }
    return $r;
}

if ($action === 'summary') {
    header('Content-Type: application/json');
    echo json_encode(['result' => true, 'summary' => dtr_batch_summary($conn, $ddtrId)]);
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
