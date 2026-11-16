<?php
/**
 * HR module 5 — announcements to employees.
 *
 * HR posts; the portal shows what is live (published, not expired) to the
 * audience it was meant for: everyone, or one department. Reads are recorded
 * so HR can see how many have opened it, and so the portal can badge unread.
 *
 * Notifying is OPT-IN per save ("Notify employees"). One announcement can ring
 * hundreds of phones, so it never happens as a side effect of an edit; and it
 * happens at most once per announcement (`notified_at`).
 */
trait HrAnnouncementsModule
{
    /** @return array every announcement with its audience size and read count */
    public function announcementsAdmin()
    {
        if (!$this->tableExists('hr_announcements')) return [];
        $res = $this->db->query("
            SELECT a.*, d.name AS department_name, u.name AS created_by_name,
                   (SELECT COUNT(*) FROM hr_announcement_reads r WHERE r.announcement_id = a.id) AS read_count,
                   (SELECT COUNT(*) FROM employee e WHERE e.status = 1
                        AND (a.department_id IS NULL OR e.department_id = a.department_id)) AS audience
            FROM hr_announcements a
            LEFT JOIN department d ON d.id = a.department_id
            LEFT JOIN users u ON u.id = a.created_by
            ORDER BY a.pinned DESC, a.publish_at DESC, a.id DESC");
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        return $rows;
    }

    /** @return array what one employee should see now, pinned first, with `is_read` */
    public function announcementsForEmployee($employee_id)
    {
        if (!$this->tableExists('hr_announcements')) return [];
        $stmt = $this->db->prepare("
            SELECT a.id, a.title, a.body, a.pinned, a.publish_at, a.expires_at, d.name AS department_name,
                   (r.employee_id IS NOT NULL) AS is_read
            FROM hr_announcements a
            JOIN employee e ON e.id = ?
            LEFT JOIN department d ON d.id = a.department_id
            LEFT JOIN hr_announcement_reads r ON r.announcement_id = a.id AND r.employee_id = e.id
            WHERE a.publish_at <= NOW()
              AND (a.expires_at IS NULL OR a.expires_at > NOW())
              AND (a.department_id IS NULL OR a.department_id = e.department_id)
            ORDER BY a.pinned DESC, a.publish_at DESC, a.id DESC
            LIMIT 50");
        $employee_id = (int) $employee_id;
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        return $rows;
    }

    /** "2026-11-14T08:30" (datetime-local) or "2026-11-14 08:30" → Y-m-d H:i:s, or null. */
    private function dateTimeField(array $src, $key)
    {
        $v = trim(str_replace('T', ' ', (string) ($src[$key] ?? '')));
        if ($v === '') return [null, null];
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $fmt) {
            $d = DateTime::createFromFormat('!' . $fmt, $v);
            if ($d && $d->format($fmt) === $v) return [$d->format('Y-m-d H:i:s'), null];
        }
        return [null, 'is not a valid date and time.'];
    }

    public function hr_announcement_save()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_announcements')) return $e;

        $id = (int) ($_POST['id'] ?? 0);
        list($title, $err) = $this->textField($_POST, 'title', 'Title', 150, true);
        if ($err) return $this->fail($err);
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '')             return $this->fail('The announcement text is required.');
        if (mb_strlen($body) > 20000) return $this->fail('The announcement text is too long.');

        $dept = (int) ($_POST['department_id'] ?? 0) ?: null;
        if ($dept !== null) {
            $chk = $this->rowsFor("SELECT id FROM department WHERE id = ?", $dept);
            if (!$chk) return $this->fail('That department no longer exists.');
        }
        list($publish, $err) = $this->dateTimeField($_POST, 'publish_at');
        if ($err) return $this->fail('Publish date ' . $err);
        list($expires, $err) = $this->dateTimeField($_POST, 'expires_at');
        if ($err) return $this->fail('Expiry date ' . $err);
        if ($publish === null) $publish = date('Y-m-d H:i:s');
        if ($expires !== null && $expires <= $publish) return $this->fail('The expiry must be after the publish date.');
        $pinned = !empty($_POST['pinned']) ? 1 : 0;
        $actor  = $this->actor();

        if ($id > 0) {
            $stmt = $this->db->prepare("UPDATE hr_announcements SET title = ?, body = ?, department_id = ?, pinned = ?,
                                        publish_at = ?, expires_at = ? WHERE id = ?");
            $stmt->bind_param('ssiissi', $title, $body, $dept, $pinned, $publish, $expires, $id);
            if (!$stmt->execute()) return $this->fail('Could not save the announcement.');
            $chk = $this->rowsFor("SELECT id FROM hr_announcements WHERE id = ?", $id);
            if (!$chk) return $this->fail('That announcement no longer exists.');
        } else {
            $stmt = $this->db->prepare("INSERT INTO hr_announcements (title, body, department_id, pinned, publish_at, expires_at, created_by)
                                        VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssiissi', $title, $body, $dept, $pinned, $publish, $expires, $actor);
            if (!$stmt->execute()) return $this->fail('Could not save the announcement.');
            $id = (int) $this->db->insert_id;
        }

        $message = $id > 0 && empty($_POST['id']) ? 'Announcement posted.' : 'Announcement updated.';
        if (!empty($_POST['notify'])) {
            $sent = $this->notifyAnnouncement($id);
            $message .= $sent === null ? ' Employees were already notified about it before.'
                      : ($publish > date('Y-m-d H:i:s') ? ' Notification sent now, though it shows from its publish date.'
                      : ' ' . $sent . ' employee' . ($sent === 1 ? '' : 's') . ' notified.');
        }
        return ['result' => true, 'id' => $id, 'message' => $message];
    }

    /**
     * Ring the audience once: a portal bell row each, and one push for the
     * whole group. Returns how many employees were told, or null when this
     * announcement had already been sent.
     */
    private function notifyAnnouncement($id)
    {
        $rows = $this->rowsFor("SELECT * FROM hr_announcements WHERE id = ?", $id);
        $a = $rows[0] ?? null;
        if (!$a || $a['notified_at']) return null;

        $mark = $this->db->prepare("UPDATE hr_announcements SET notified_at = NOW() WHERE id = ? AND notified_at IS NULL");
        $mark->bind_param('i', $id);
        $mark->execute();
        if (!$mark->affected_rows) return null;   // another request got here first

        $where = 'status = 1' . ($a['department_id'] ? ' AND department_id = ' . (int) $a['department_id'] : '');
        $res   = $this->db->query("SELECT id FROM employee WHERE $where");
        $ids   = [];
        while ($res && ($r = $res->fetch_assoc())) $ids[] = (int) $r['id'];
        if (!$ids) return 0;

        $title   = mb_substr('Announcement: ' . $a['title'], 0, 150);
        $message = mb_substr(preg_replace('/\s+/', ' ', strip_tags($a['body'])), 0, 180);
        $stmt = $this->db->prepare("INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link)
                                    VALUES (?, 'employee', ?, ?, 'ri-megaphone-line', 'primary', 'employee-portal.php')");
        foreach ($ids as $eid) {
            $stmt->bind_param('iss', $eid, $title, $message);
            $stmt->execute();
        }

        if (self::$push) try {
            require_once __DIR__ . '/../../fcm.php';
            if (function_exists('fcm_push_where')) {
                fcm_push_where($this->db, "recipient_type = 'employee' AND user_id IN (" . implode(',', $ids) . ")",
                    $title, $message, 'employee-portal.php', 'hr-ann-' . $id);
            }
        } catch (\Throwable $e) { /* the bell rows are the record */ }

        return count($ids);
    }

    public function hr_announcement_delete()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_announcements')) return $e;
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("DELETE FROM hr_announcements WHERE id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute() || !$stmt->affected_rows) return $this->fail('That announcement no longer exists.');
        $r = $this->db->prepare("DELETE FROM hr_announcement_reads WHERE announcement_id = ?");
        $r->bind_param('i', $id);
        $r->execute();
        return ['result' => true, 'message' => 'Announcement deleted.'];
    }

    /** The employee opened an announcement. Only one they can actually see. */
    public function my_announcement_read()
    {
        $me = $this->selfId();
        if ($me <= 0) return $this->fail('Please sign in again.');
        if ($e = $this->needTable('hr_announcements')) return $e;

        $id = (int) ($_POST['id'] ?? 0);
        $visible = false;
        foreach ($this->announcementsForEmployee($me) as $a) {
            if ((int) $a['id'] === $id) { $visible = true; break; }
        }
        if (!$visible) return $this->fail('That announcement is not available.');

        $stmt = $this->db->prepare("INSERT IGNORE INTO hr_announcement_reads (announcement_id, employee_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $id, $me);
        $stmt->execute();
        return ['result' => true, 'message' => 'Marked as read.'];
    }
}
