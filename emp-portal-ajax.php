<?php
// ── Employee self-service portal AJAX ──────────────────────────────────────
// Strictly scoped to the logged-in employee (id from session, never client input).
// Handles the portal notification bell and the DTR employee-review sign-off.
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['emp_is_login'])) {
    http_response_code(403);
    echo json_encode(['result' => false, 'message' => 'Not authenticated']);
    exit;
}

include 'db_connect.php';

$emp_id = (int) $_SESSION['emp_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Insert a staff (users) notification — mirrors Action::notify().
function notify_user($conn, $user_id, $title, $message, $icon, $color, $link)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) return;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link) VALUES (?, 'user', ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssss', $user_id, $title, $message, $icon, $color, $link);
    $stmt->execute();
}

switch ($action) {

    // ── Firebase Cloud Messaging: register this employee browser for push ──
    case 'save_fcm_token': {
        $token = trim($_POST['token'] ?? '');
        if ($token === '' || strlen($token) > 500) {
            echo json_encode(0);
            break;
        }
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        // Single-device, single-recipient policy. Registering this browser evicts:
        //  a) the employee's tokens on OTHER devices (and rotated tokens on this
        //     one), so each push lands exactly once, on the most recent login; and
        //  b) any STAFF registration of this same browser token — a browser
        //     belongs to whoever logged in last. users.id and employee.id overlap,
        //     so recipient_type is always part of the match — never user_id alone.
        $del = $conn->prepare(
            "DELETE FROM fcm_tokens
             WHERE (user_id = ? AND recipient_type = 'employee' AND token <> ?)
                OR (token = ? AND recipient_type = 'user')"
        );
        $del->bind_param('iss', $emp_id, $token, $token);
        $del->execute();
        $st = $conn->prepare(
            "INSERT INTO fcm_tokens (user_id, recipient_type, token, user_agent) VALUES (?, 'employee', ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id),
                                     user_agent = VALUES(user_agent), last_seen = NOW()"
        );
        $st->bind_param('iss', $emp_id, $token, $ua);
        echo json_encode($st->execute() ? 1 : 0);
        break;
    }

    // ── Notification bell ──
    case 'emp_notifications': {
        $items = [];
        $st = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND recipient_type = 'employee' ORDER BY created_at DESC LIMIT 30");
        $st->bind_param('i', $emp_id);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $items[] = $r;

        $unread = (int) ($conn->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = $emp_id AND recipient_type = 'employee' AND is_read = 0")->fetch_assoc()['c'] ?? 0);
        echo json_encode(['result' => true, 'items' => $items, 'unread' => $unread]);
        break;
    }

    case 'emp_mark_read': {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ? AND recipient_type = 'employee'");
        $st->bind_param('ii', $id, $emp_id);
        $st->execute();
        echo json_encode(['result' => true]);
        break;
    }

    case 'emp_mark_all_read': {
        $conn->query("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = $emp_id AND recipient_type = 'employee' AND is_read = 0");
        echo json_encode(['result' => true]);
        break;
    }

    // ── DTR review: list this employee's DTR batches that are ready / done ──
    case 'my_dtr_list': {
        // Batches the employee has records in, that are in review (3) or approved (2).
        $rows = [];
        $sql = "SELECT DTR.id, DTR.date_from, DTR.date_to, DTR.status,
                    sites.site_code, sites.site_name,
                    r.status AS review_status, r.comment AS review_comment, r.reviewed_at,
                    COUNT(dd.id) AS day_count,
                    SUM(dd.work_hours) AS total_hours, SUM(dd.overtime) AS total_ot
                FROM DTR_details dd
                INNER JOIN DTR ON DTR.id = dd.ddtr_id
                LEFT JOIN sites ON sites.id = DTR.site_id
                LEFT JOIN dtr_employee_reviews r ON r.ddtr_id = DTR.id AND r.employee_id = ?
                WHERE dd.employee_id = ? AND DTR.status IN (2, 3)
                GROUP BY DTR.id
                ORDER BY (DTR.status = 3) DESC, DTR.id DESC
                LIMIT 60";
        $st = $conn->prepare($sql);
        $st->bind_param('ii', $emp_id, $emp_id);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['result' => true, 'rows' => $rows]);
        break;
    }

    // ── DTR review: the employee's own day rows for one batch ──
    case 'my_dtr_details': {
        $ddtr_id = (int) ($_POST['ddtr_id'] ?? 0);
        if (!$ddtr_id) { echo json_encode(['result' => false, 'message' => 'Invalid DTR']); break; }

        // Confirm the employee actually belongs to this batch.
        $own = $conn->query("SELECT COUNT(*) AS c FROM DTR_details WHERE ddtr_id = $ddtr_id AND employee_id = $emp_id")->fetch_assoc();
        if (!$own || (int)$own['c'] === 0) { echo json_encode(['result' => false, 'message' => 'Not authorized for this DTR']); break; }

        $dtr = $conn->query("SELECT DTR.*, sites.site_code, sites.site_name FROM DTR LEFT JOIN sites ON sites.id = DTR.site_id WHERE DTR.id = $ddtr_id")->fetch_assoc();

        // Employee name for the Form 48 header.
        $me = $conn->query("SELECT firstname, middlename, lastname FROM employee WHERE id = $emp_id")->fetch_assoc();
        $empName = trim(($me['lastname'] ?? '') . ', ' . ($me['firstname'] ?? '') . ' ' . ($me['middlename'] ?? ''));

        // Per-record admin↔employee message threads (guarded: the dtr_messages
        // table may not exist on older databases).
        $msgMap = [];
        $mq = @$conn->query("SELECT m.dtr_detail_id, m.message, m.created_at, m.sender_type, u.name AS sender
                             FROM dtr_messages m LEFT JOIN users u ON u.id = m.sent_by
                             WHERE m.employee_id = $emp_id AND m.dtr_detail_id IN
                                   (SELECT id FROM DTR_details WHERE ddtr_id = $ddtr_id AND employee_id = $emp_id)
                             ORDER BY m.id ASC");
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

        $days = [];
        $st = $conn->prepare("SELECT id, date_time, work_hours, overtime, undertime, late, logs, attendance_type, status, decision_note
                              FROM DTR_details WHERE ddtr_id = ? AND employee_id = ? ORDER BY date_time ASC");
        $st->bind_param('ii', $ddtr_id, $emp_id);
        $st->execute();
        $res = $st->get_result();
        while ($d = $res->fetch_assoc()) {
            $logs = json_decode($d['logs'], true) ?: [];
            $tIn = $tOut = '';
            if (!empty($logs)) {
                $tIn  = date('g:i A', strtotime($logs[0]['dateTime']));
                $tOut = count($logs) > 1 ? date('g:i A', strtotime(end($logs)['dateTime'])) : '';
            }
            // Full punch list so the detail view can mirror the admin DTR card
            // (IN / OUT / #n chips + biometric-vs-manual marker per punch).
            $punches = [];
            $lc = count($logs);
            foreach ($logs as $li => $lg) {
                $punches[] = [
                    'label' => ($li === 0) ? 'IN' : (($li === $lc - 1) ? 'OUT' : '#' . ($li + 1)),
                    'time'  => date('g:i A', strtotime($lg['dateTime'])),
                    'bio'   => (($lg['type'] ?? '') === 'bio'),
                ];
            }
            $days[] = [
                'rec_id'     => (int) $d['id'],
                'msgs'       => $msgMap[(int)$d['id']] ?? [],
                'iso'        => date('Y-m-d', strtotime($d['date_time'])),
                'date'       => date('D, M j, Y', strtotime($d['date_time'])),
                'time_in'    => $tIn,
                'time_out'   => $tOut,
                'work_hours' => (float) $d['work_hours'],
                'overtime'   => (float) $d['overtime'],
                'undertime'  => (float) $d['undertime'],
                'late'       => (float) $d['late'],
                'type'       => $d['attendance_type'],
                'punches'    => $punches,
                'status'     => (int) $d['status'],
                // Why the timekeeper rejected this day — shown so a dispute
                // can answer the actual reason instead of guessing it.
                'note'       => (int)$d['status'] === 2 ? (string)($d['decision_note'] ?? '') : '',
            ];
        }

        $review = $conn->query("SELECT status, comment, reviewed_at, admin_reply, resolved_at FROM dtr_employee_reviews WHERE ddtr_id = $ddtr_id AND employee_id = $emp_id")->fetch_assoc();

        echo json_encode([
            'result' => true,
            'name' => $empName,
            'dtr' => [
                'id' => (int) $dtr['id'],
                'period' => date('M j', strtotime($dtr['date_from'])) . ' – ' . date('M j, Y', strtotime($dtr['date_to'])),
                'date_from' => date('Y-m-d', strtotime($dtr['date_from'])),
                'date_to' => date('Y-m-d', strtotime($dtr['date_to'])),
                'site' => trim(($dtr['site_code'] ? $dtr['site_code'] . ' — ' : '') . $dtr['site_name']),
                'status' => (int) $dtr['status'],
            ],
            'days' => $days,
            'review' => $review ?: null,
        ]);
        break;
    }

    // ── DTR conversation: employee sends/replies about one attendance date ──
    case 'reply_dtr_message': {
        $rec_id  = (int) ($_POST['rec_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        if (!$rec_id || $message === '') { echo json_encode(['result' => false, 'message' => 'Please write a message.']); break; }
        if (mb_strlen($message) > 500)   { echo json_encode(['result' => false, 'message' => 'Message is too long (max 500 characters).']); break; }

        // The record must be the employee's own.
        $rec = $conn->query("SELECT id, date_time FROM DTR_details WHERE id = $rec_id AND employee_id = $emp_id")->fetch_assoc();
        if (!$rec) { echo json_encode(['result' => false, 'message' => 'Record not found.']); break; }

        $ins = $conn->prepare("INSERT INTO dtr_messages (dtr_detail_id, employee_id, date_time, message, sent_by, sender_type)
                               VALUES (?,?,?,?,NULL,'employee')");
        $ins->bind_param('iiss', $rec_id, $emp_id, $rec['date_time'], $message);
        if (!$ins->execute()) { echo json_encode(['result' => false, 'message' => 'Could not send your message.']); break; }

        // Notify the deciding admins: bell for Admin / Dept Head / HR Head + push.
        $erow    = $conn->query("SELECT CONCAT(firstname,' ',lastname) AS n FROM employee WHERE id = $emp_id")->fetch_assoc();
        $ename   = $erow['n'] ?? 'Employee';
        $dateLbl = date('M j, Y', strtotime($rec['date_time']));
        $ttl     = $conn->real_escape_string('DTR message from ' . $ename);
        $bodyN   = $conn->real_escape_string("About their $dateLbl attendance: \u{201C}" . mb_substr($message, 0, 150) . "\u{201D}");
        $conn->query("INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link)
                      SELECT id, 'user', '$ttl', '$bodyN', 'ri-chat-3-line', 'info', 'index.php?page=dtr' FROM users WHERE role IN (1, 8, 9)");
        // Browser push to the deciding admins (fcm.php isn't loaded globally here).
        try {
            require_once __DIR__ . '/fcm.php';
            if (function_exists('fcm_push_role')) {
                fcm_push_role($conn, [1, 8, 9], 'DTR message from ' . $ename,
                    "About their $dateLbl attendance.", 'index.php?page=dtr');
            }
        } catch (\Throwable $e) { /* push is best-effort */ }

        echo json_encode(['result' => true, 'at' => date('M j, g:i A')]);
        break;
    }

    // ── Refresh one record's message thread (attendance details refresh) ──
    case 'dtr_message_thread': {
        $rec_id = (int) ($_POST['rec_id'] ?? 0);
        $msgs   = [];
        if ($rec_id > 0) {
            $own = $conn->query("SELECT id FROM DTR_details WHERE id = $rec_id AND employee_id = $emp_id")->fetch_assoc();
            if ($own) {
                $mq = @$conn->query("SELECT m.message, m.created_at, m.sender_type, u.name AS sender
                                     FROM dtr_messages m LEFT JOIN users u ON u.id = m.sent_by
                                     WHERE m.dtr_detail_id = $rec_id ORDER BY m.id ASC");
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
        echo json_encode(['result' => true, 'msgs' => $msgs]);
        break;
    }

    // ── DTR review: employee submits confirm / dispute ──
    case 'submit_dtr_review': {
        $ddtr_id  = (int) ($_POST['ddtr_id'] ?? 0);
        $decision = (int) ($_POST['decision'] ?? 0); // 1 = confirm, 2 = dispute
        $comment  = trim($_POST['comment'] ?? '');

        if (!$ddtr_id || !in_array($decision, [1, 2], true)) {
            echo json_encode(['result' => false, 'message' => 'Invalid submission']); break;
        }
        if ($decision === 2 && $comment === '') {
            echo json_encode(['result' => false, 'message' => 'Please describe the issue when disputing.']); break;
        }

        $dtr = $conn->query("SELECT DTR.*, sites.site_code FROM DTR LEFT JOIN sites ON sites.id = DTR.site_id WHERE DTR.id = $ddtr_id")->fetch_assoc();
        if (!$dtr) { echo json_encode(['result' => false, 'message' => 'DTR not found']); break; }
        if ((int)$dtr['status'] !== 3) {
            echo json_encode(['result' => false, 'message' => 'This DTR is no longer open for review.']); break;
        }

        $own = $conn->query("SELECT COUNT(*) AS c FROM DTR_details WHERE ddtr_id = $ddtr_id AND employee_id = $emp_id")->fetch_assoc();
        if (!$own || (int)$own['c'] === 0) { echo json_encode(['result' => false, 'message' => 'Not authorized for this DTR']); break; }

        // Upsert the employee's sign-off.
        $st = $conn->prepare("INSERT INTO dtr_employee_reviews (ddtr_id, employee_id, status, comment)
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE status = VALUES(status), comment = VALUES(comment), reviewed_at = CURRENT_TIMESTAMP");
        $st->bind_param('iiis', $ddtr_id, $emp_id, $decision, $comment);
        if (!$st->execute()) { echo json_encode(['result' => false, 'message' => $st->error]); break; }

        // Notify the relevant staff (uploader + Admin / Dept Head / HR).
        $emp = $conn->query("SELECT CONCAT(firstname,' ',lastname) AS n FROM employee WHERE id = $emp_id")->fetch_assoc();
        $ename  = $emp['n'] ?? 'An employee';
        $period = date('M j', strtotime($dtr['date_from'])) . ' – ' . date('M j, Y', strtotime($dtr['date_to']));
        $verb   = $decision === 1 ? 'confirmed' : 'disputed';
        $icon   = $decision === 1 ? 'ri-checkbox-circle-line' : 'ri-error-warning-line';
        $color  = $decision === 1 ? 'success' : 'danger';
        // Link straight to the standalone paper-DTR workbench (dtr-documents.php),
        // matching how dtr.php builds the view URL — not the old dtr-details page.
        $link   = 'dtr-documents.php?id=' . base64_encode($ddtr_id) . '&timekeeper_name=' . base64_encode('') . '&device_id=' . base64_encode($dtr['device_id']) . '&site_id=' . base64_encode($dtr['site_id']) . '&status=' . base64_encode($dtr['status']);
        $msg = "$ename $verb their DTR for $period." . ($comment !== '' ? " Note: $comment" : '');

        $recipients = [];
        if (!empty($dtr['uploaded_by'])) $recipients[] = (int) $dtr['uploaded_by'];
        $staff = $conn->query("SELECT id FROM users WHERE role IN (1, 8, 9) AND status = 1");
        if ($staff) while ($u = $staff->fetch_assoc()) $recipients[] = (int) $u['id'];
        foreach (array_unique($recipients) as $uid) {
            notify_user($conn, $uid, "DTR $verb by employee", $msg, $icon, $color, $link);
        }

        $dtr_review_pending_count = (int) ($conn->query("SELECT COUNT(DISTINCT DTR.id) AS c
            FROM DTR_details dd
            INNER JOIN DTR ON DTR.id = dd.ddtr_id
            LEFT JOIN dtr_employee_reviews r ON r.ddtr_id = DTR.id AND r.employee_id = $emp_id
            WHERE dd.employee_id = $emp_id AND DTR.status = 3 AND r.id IS NULL")->fetch_assoc()['c'] ?? 0);

        echo json_encode([
            'result' => true,
            'message' => $decision === 1 ? 'Thanks! Your DTR is confirmed.' : 'Your dispute has been sent for review.',
            'dtr_review_pending_count' => $dtr_review_pending_count,
        ]);
        break;
    }

    // ── Payslip review: employee submits confirm / dispute ──
    case 'submit_payroll_review': {
        $payroll_id = (int) ($_POST['payroll_id'] ?? 0);
        $decision   = (int) ($_POST['decision'] ?? 0); // 1 = confirm, 2 = dispute
        $comment    = trim($_POST['comment'] ?? '');

        if (!$payroll_id || !in_array($decision, [1, 2], true)) {
            echo json_encode(['result' => false, 'message' => 'Invalid submission']); break;
        }
        if ($decision === 2 && $comment === '') {
            echo json_encode(['result' => false, 'message' => 'Please describe the issue when disputing.']); break;
        }

        $payroll = $conn->query("SELECT * FROM payroll WHERE id = $payroll_id")->fetch_assoc();
        if (!$payroll) { echo json_encode(['result' => false, 'message' => 'Payroll not found']); break; }
        if ((int)$payroll['status'] !== 3) {
            echo json_encode(['result' => false, 'message' => 'This payroll is no longer open for review.']); break;
        }

        $own = $conn->query("SELECT COUNT(*) AS c FROM payroll_items WHERE payroll_id = $payroll_id AND employee_id = $emp_id")->fetch_assoc();
        if (!$own || (int)$own['c'] === 0) { echo json_encode(['result' => false, 'message' => 'Not authorized for this payroll']); break; }

        // The employee's previous decision, read *before* the upsert overwrites it.
        // Needed below to tell our own auto-mark apart from an admin's manual one.
        $prevRow = $conn->query("SELECT status FROM payroll_employee_reviews
                                 WHERE payroll_id = $payroll_id AND employee_id = $emp_id")->fetch_assoc();
        $prevDecision = $prevRow ? (int) $prevRow['status'] : 0;

        // Upsert the employee's sign-off.
        $st = $conn->prepare("INSERT INTO payroll_employee_reviews (payroll_id, employee_id, status, comment)
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE status = VALUES(status), comment = VALUES(comment), reviewed_at = CURRENT_TIMESTAMP");
        $st->bind_param('iiis', $payroll_id, $emp_id, $decision, $comment);
        if (!$st->execute()) { echo json_encode(['result' => false, 'message' => $st->error]); break; }

        // ── Mirror the sign-off onto the reviewer's row mark ──────────────────
        // payroll_items.review_status is the admin's per-row colour mark
        // (0=none, 1=ok/green, 2=issue/orange, 3=reviewing/blue). Employees
        // confirming their own payslip is the same signal, so write it here and
        // save the admin marking every row by hand.
        //
        // An admin's manual mark always wins: we only touch a row that is still
        // unmarked (0), or one that still carries the mark *we* set from this
        // employee's previous decision — otherwise a change of mind would leave
        // a green row sitting above a listed dispute. Any other value means a
        // human deliberately set it, so it is left alone.
        $autoMark = $decision === 1 ? 1 : 2;
        $claimable = [0];
        if ($prevDecision === 1 || $prevDecision === 2) {
            $claimable[] = $prevDecision === 1 ? 1 : 2;
        }
        $inList = implode(',', array_unique($claimable));
        $mk = $conn->prepare("UPDATE payroll_items SET review_status = ?
                              WHERE payroll_id = ? AND employee_id = ?
                              AND review_status IN ($inList)");
        $mk->bind_param('iii', $autoMark, $payroll_id, $emp_id);
        $mk->execute();

        // Notify staff (Admin / Dept Head / HR).
        $emp = $conn->query("SELECT CONCAT(firstname,' ',lastname) AS n FROM employee WHERE id = $emp_id")->fetch_assoc();
        $ename  = $emp['n'] ?? 'An employee';
        $period = date('M j', strtotime($payroll['date_from'])) . ' – ' . date('M j, Y', strtotime($payroll['date_to']));
        $verb   = $decision === 1 ? 'confirmed' : 'disputed';
        $icon   = $decision === 1 ? 'ri-checkbox-circle-line' : 'ri-error-warning-line';
        $color  = $decision === 1 ? 'success' : 'danger';
        $link   = 'index.php?page=payroll_calculations&id=' . $payroll_id;
        $msg = "$ename $verb their payslip for $period." . ($comment !== '' ? " Note: $comment" : '');

        $staff = $conn->query("SELECT id FROM users WHERE role IN (1, 8, 9) AND status = 1");
        if ($staff) while ($u = $staff->fetch_assoc()) {
            notify_user($conn, (int) $u['id'], "Payslip $verb by employee", $msg, $icon, $color, $link);
        }

        $payroll_review_pending_count = (int) ($conn->query("SELECT COUNT(*) AS c
            FROM payroll_items pi
            INNER JOIN payroll p ON pi.payroll_id = p.id
            LEFT JOIN payroll_employee_reviews r ON r.payroll_id = p.id AND r.employee_id = $emp_id
            WHERE pi.employee_id = $emp_id AND p.status = 3 AND r.id IS NULL")->fetch_assoc()['c'] ?? 0);

        echo json_encode([
            'result' => true,
            'message' => $decision === 1 ? 'Thanks! Your payslip is confirmed.' : 'Your dispute has been sent for review.',
            'payroll_review_pending_count' => $payroll_review_pending_count,
        ]);
        break;
    }

    // ── Leave / LWOP request: employee files a new leave request ──
    case 'submit_leave_request': {
        $lt_id      = (int) ($_POST['leave_type_id'] ?? 0);
        $lreason    = trim($_POST['reason'] ?? '');
        $is_half    = intval($_POST['is_half_day'] ?? 0);
        $half_per   = in_array($_POST['half_period'] ?? '', ['AM', 'PM'], true) ? $_POST['half_period'] : null;
        // Which selected day the half falls on ('first' or 'last' of the range).
        $half_on    = in_array($_POST['half_on'] ?? '', ['first', 'last'], true) ? $_POST['half_on'] : 'first';
        $dates_raw  = trim($_POST['dates'] ?? '');

        $lt_check = $lt_id > 0 ? $conn->query("SELECT is_paid FROM leave_types WHERE id = $lt_id LIMIT 1")->fetch_assoc() : null;
        $is_lwop_req = $lt_check && $lt_check['is_paid'] == 0;

        $elig = $conn->query("SELECT UPPER(COALESCE(cl.clasification,'')) AS c, e.leave_override FROM employee e LEFT JOIN clasification cl ON cl.id = e.clasification_id WHERE e.id = $emp_id")->fetch_assoc();
        $eligible = $elig && leave_eligibility_from($elig['c'], $elig['leave_override']);

        if (!$eligible && !$is_lwop_req) {
            echo json_encode(['result' => false, 'message' => 'Only Regular and Executive employees are entitled to leave.']);
            break;
        }

        $days = array_filter(array_map('trim', explode(',', $dates_raw)));
        if ($lt_id <= 0 || empty($days) || $lreason === '') {
            echo json_encode(['result' => false, 'message' => 'Please complete all fields and select at least one date.']);
            break;
        }

        sort($days);
        $d_from = $days[0];
        $d_to   = end($days);
        // Half-day = exactly ONE of the selected days counts as 0.5 (the first
        // or last, per $half_on). A single-date half request stays 0.5 (1 − 0.5).
        $dur       = $is_half ? count($days) - 0.5 : (float) count($days);
        $half_date = $is_half ? ($half_on === 'last' ? $d_to : $d_from) : null;
        $today  = date('Y-m-d');
        $dates_json = json_encode($days);

        // Duplicate-date guard: block days already covered by another of this
        // employee's PENDING or APPROVED requests. Rejected requests don't block.
        $taken = [];
        $dupq  = $conn->query("SELECT dates, date_from, date_to FROM leave_requests WHERE employee_id = $emp_id AND status IN (0,1)");
        if ($dupq) while ($dx = $dupq->fetch_assoc()) {
            $dd = [];
            if (!empty($dx['dates'])) { $j = json_decode($dx['dates'], true); if (is_array($j)) $dd = $j; }
            if (!$dd) { for ($t = strtotime($dx['date_from']); $t <= strtotime($dx['date_to']); $t = strtotime('+1 day', $t)) $dd[] = date('Y-m-d', $t); }
            foreach ($dd as $d1) $taken[date('Y-m-d', strtotime($d1))] = true;
        }
        $dup_hit = array_values(array_filter($days, function ($d1) use ($taken) { return isset($taken[date('Y-m-d', strtotime($d1))]); }));
        if ($dup_hit) {
            $nice = array_map(function ($d1) { return date('M d', strtotime($d1)); }, array_slice($dup_hit, 0, 5));
            echo json_encode(['result' => false, 'message' => 'You already have a pending or approved leave on: '
                . implode(', ', $nice) . (count($dup_hit) > 5 ? '…' : '') . '. Please pick different day(s).']);
            break;
        }

        // Balance guard (paid leave only — LWOP consumes no credits): remaining
        // counts approved AND still-pending requests so filings can't stack past
        // the employee's credits.
        if (!$is_lwop_req) {
            $ly = leave_current_year();
            $balq = $conn->query("
                SELECT COALESCE(c.credits, lt.days_allowed) - COALESCE(u.used, 0) AS remaining
                FROM leave_types lt
                LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = $emp_id AND c.year = $ly
                LEFT JOIN (
                    SELECT leave_type_id, SUM(duration) AS used
                    FROM leave_requests WHERE employee_id = $emp_id AND status IN (0,1) AND YEAR(date_from) = $ly
                    GROUP BY leave_type_id
                ) u ON u.leave_type_id = lt.id
                WHERE lt.id = $lt_id");
            $remaining = $balq ? (float) ($balq->fetch_assoc()['remaining'] ?? 0) : 0.0;
            if ($dur > $remaining + 0.001) {
                $fmtd = function ($v) { return rtrim(rtrim(number_format((float) $v, 1), '0'), '.'); };
                echo json_encode(['result' => false, 'message' =>
                    'Not enough leave credits — this request needs ' . $fmtd($dur) . ' day(s) but you only have '
                    . $fmtd(max(0, $remaining)) . ' left for this leave type (pending requests included).']);
                break;
            }
        }

        $ins = $conn->prepare("INSERT INTO leave_requests (employee_id, leave_type_id, date_applied, date_from, date_to, duration, is_half_day, half_period, half_date, dates, reason, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,0)");
        $ins->bind_param('iisssdissss', $emp_id, $lt_id, $today, $d_from, $d_to, $dur, $is_half, $half_per, $half_date, $dates_json, $lreason);
        if (!$ins->execute()) {
            echo json_encode(['result' => false, 'message' => 'Could not submit your request. Please try again.']);
            break;
        }
        $new_id = $ins->insert_id;

        $tname_q = $conn->query("SELECT name FROM leave_types WHERE id = $lt_id");
        $tname   = ($tname_q && $tr = $tname_q->fetch_assoc()) ? $tr['name'] : 'leave';
        $erow    = $conn->query("SELECT CONCAT(firstname,' ',lastname) AS n FROM employee WHERE id = $emp_id")->fetch_assoc();
        $ename   = $erow['n'] ?? 'Employee';
        $durLabel = $is_half
            ? ($dur . ' day/s — ' . $half_per . ' half on ' . date('M j', strtotime($half_date)))
            : $dur . ' day/s';
        // First approver in the configured chain (currently the Supervisor).
        $stages     = leave_stages();
        $firstCfg   = $stages[array_key_first($stages)];
        $firstRole  = (int) $firstCfg['role'];
        $firstLabel = $firstCfg['label'];
        $edept      = (int) ($conn->query("SELECT department_id FROM employee WHERE id = $emp_id")->fetch_assoc()['department_id'] ?? 0);

        $msg   = $conn->real_escape_string("$ename requested $tname ($durLabel) via portal. Needs {$firstLabel} approval.");
        $title = $conn->real_escape_string('New leave request');
        // Notify the FIRST approver (scoped to the employee's department) plus the
        // Administrator (role 1) as an observer, so it also shows in the admin bell.
        $hrs = $conn->query(
            "SELECT id FROM users WHERE status = 1 AND (
                 role = 1
                 OR (role = $firstRole AND (department_id = $edept OR department_id IS NULL OR department_id = 0))
             )"
        );
        if ($hrs) while ($hu = $hrs->fetch_assoc()) {
            $uid = (int) $hu['id'];
            $conn->query("INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link) VALUES ($uid,'user','$title','$msg','ri-calendar-event-line','warning','index.php?page=leaves')");
        }
        // Mirror to reviewer staff browsers as a push (best-effort, never fatal).
        try {
            require_once __DIR__ . '/fcm.php';
            fcm_push_role($conn, [1, $firstRole], 'New leave request',
                "$ename requested $tname ($durLabel) via portal.", 'index.php?page=leaves');
        } catch (\Throwable $e) { /* ignore */ }

        $leave_pending_count = (int) ($conn->query("SELECT COUNT(*) AS c FROM leave_requests WHERE employee_id = $emp_id AND status = 0")->fetch_assoc()['c'] ?? 0);

        echo json_encode([
            'result'  => true,
            'message' => "Leave request submitted! Your {$firstLabel} will review it shortly.",
            'leave_pending_count' => $leave_pending_count,
            'request' => [
                'id' => (int) $new_id,
                'leave_type_id' => $lt_id,
                'leave_type_name' => $tname,
                'date_applied' => $today,
                'date_from' => $d_from,
                'date_to' => $d_to,
                'duration' => $dur,
                'reason' => $lreason,
                'status' => 0, 'sup_status' => 0, 'hr_status' => 0, 'admin_status' => 0,
            ],
        ]);
        break;
    }

    // ── Attendance / OT request: employee files an incident or OT request ──
    case 'submit_attendance_request': {
        $req_type  = trim($_POST['request_type'] ?? '');
        $req_date  = trim($_POST['request_date'] ?? '');
        $reason    = trim($_POST['reason'] ?? '');
        $time_in   = trim($_POST['claimed_time_in'] ?? '') ?: null;
        $time_out  = trim($_POST['claimed_time_out'] ?? '') ?: null;
        $ot_hours  = trim($_POST['ot_hours_requested'] ?? '') !== '' ? (float) $_POST['ot_hours_requested'] : null;
        $att_notes = trim($_POST['notes'] ?? '');

        if (!in_array($req_type, ['incident', 'overtime'], true) || !$req_date || !$reason) {
            echo json_encode(['result' => false, 'message' => 'Please complete all required fields.']);
            break;
        }
        if ($req_type === 'incident' && (!$time_in || !$time_out)) {
            echo json_encode(['result' => false, 'message' => 'Please provide your claimed time in and time out.']);
            break;
        }
        if ($req_type === 'overtime' && !$ot_hours) {
            echo json_encode(['result' => false, 'message' => 'Please provide the number of OT hours requested.']);
            break;
        }

        $ins = $conn->prepare("INSERT INTO attendance_requests (employee_id, request_type, request_date, reason, claimed_time_in, claimed_time_out, ot_hours_requested, notes) VALUES (?,?,?,?,?,?,?,?)");
        $ins->bind_param('isssssds', $emp_id, $req_type, $req_date, $reason, $time_in, $time_out, $ot_hours, $att_notes);
        if (!$ins->execute()) {
            echo json_encode(['result' => false, 'message' => 'Could not submit your request. Please try again.']);
            break;
        }
        $new_id = $ins->insert_id;

        $erow  = $conn->query("SELECT CONCAT(firstname,' ',lastname) AS n FROM employee WHERE id = $emp_id")->fetch_assoc();
        $ename = $erow['n'] ?? 'Employee';
        $label = $req_type === 'incident' ? 'attendance incident report' : 'overtime request';
        $msg   = $conn->real_escape_string("$ename filed a $label for " . date('M d, Y', strtotime($req_date)) . '.');
        $title = $conn->real_escape_string('New ' . $label);
        $reviewers = $conn->query("SELECT id FROM users WHERE role IN (1,8,9) AND status = 1");
        if ($reviewers) while ($ru = $reviewers->fetch_assoc()) {
            $uid = (int) $ru['id'];
            $conn->query("INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link) VALUES ($uid, 'user', '$title', '$msg', 'ri-error-warning-line', 'warning', 'index.php?page=attendance-requests')");
        }
        // Mirror to reviewer staff browsers as a push (best-effort, never fatal).
        try {
            require_once __DIR__ . '/fcm.php';
            fcm_push_role($conn, [1, 8, 9], 'New ' . $label,
                "$ename filed a $label for " . date('M d, Y', strtotime($req_date)) . '.',
                'index.php?page=attendance-requests');
        } catch (\Throwable $e) { /* ignore */ }

        $att_req_pending_count = (int) ($conn->query("SELECT COUNT(*) AS c FROM attendance_requests WHERE employee_id = $emp_id AND status = 0")->fetch_assoc()['c'] ?? 0);

        echo json_encode([
            'result'  => true,
            'message' => 'Request submitted! It will be reviewed shortly.',
            'att_req_pending_count' => $att_req_pending_count,
            'request' => [
                'id' => (int) $new_id,
                'request_type' => $req_type,
                'request_date' => $req_date,
                'reason' => $reason,
                'claimed_time_in' => $time_in,
                'claimed_time_out' => $time_out,
                'ot_hours_requested' => $ot_hours,
                'notes' => $att_notes,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 0,
            ],
        ]);
        break;
    }

    default:
        echo json_encode(['result' => false, 'message' => 'Unknown action']);
}
