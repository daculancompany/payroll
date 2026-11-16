<?php
/**
 * HR module 4 — memos and disciplinary records.
 *
 * HR issues; the employee reads it in the portal and acknowledges it. A Notice
 * to Explain asks for the employee's written explanation, which they type in
 * the portal (`employee_response`). HR closes the matter with a resolution.
 *
 * Once the employee has acknowledged a memo its wording is frozen: what they
 * signed for must stay what HR has on file. HR can still close it or delete it
 * (a memo issued to the wrong person), but not rewrite it.
 */
trait HrMemosModule
{
    public static function memoTypes()
    {
        return [
            'Memo', 'Notice to Explain', 'Written Warning', 'Final Warning',
            'Suspension', 'Commendation',
        ];
    }

    /** @return array memos of one employee, newest first */
    public function memos($employee_id)
    {
        if (!$this->tableExists('hr_memos')) return [];
        return $this->rowsFor("SELECT m.*, u.name AS issued_by_name
                               FROM hr_memos m LEFT JOIN users u ON u.id = m.issued_by
                               WHERE m.employee_id = ?
                               ORDER BY m.issued_date DESC, m.id DESC", $employee_id);
    }

    public function memoRow($id)
    {
        $rows = $this->rowsFor("SELECT * FROM hr_memos WHERE id = ?", $id);
        return $rows[0] ?? null;
    }

    public function hr_memo_save()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_memos')) return $e;

        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $id          = (int) ($_POST['id'] ?? 0);
        if ($employee_id <= 0 || !$this->employeeExists($employee_id)) return $this->fail('That employee no longer exists.');

        if ($id > 0) {
            $memo = $this->memoRow($id);
            if (!$memo || (int) $memo['employee_id'] !== $employee_id) return $this->fail('That memo is not on this employee.');
            if ($memo['acknowledged_at']) {
                return $this->fail('The employee has already acknowledged this memo, so its wording can no longer change.');
            }
        }

        list($type, $err) = $this->textField($_POST, 'memo_type', 'Type', 30, true, self::memoTypes());
        if ($err) return $this->fail($err);
        list($subject, $err) = $this->textField($_POST, 'subject', 'Subject', 150, true);
        if ($err) return $this->fail($err);
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '')                return $this->fail('The memo text is required.');
        if (mb_strlen($body) > 20000)    return $this->fail('The memo text is too long.');
        list($issued, $err) = $this->dateField($_POST, 'issued_date', 'Issued date', true, 'past');
        if ($err) return $this->fail($err);
        list($respondBy, $err) = $this->dateField($_POST, 'respond_by', 'Respond by');
        if ($err) return $this->fail($err);
        if ($respondBy !== null && $respondBy < $issued) return $this->fail('"Respond by" cannot be before the issue date.');
        $requiresAck = !empty($_POST['requires_ack']) ? 1 : 0;

        $actor = $this->actor();
        if ($id > 0) {
            $stmt = $this->db->prepare("UPDATE hr_memos SET memo_type = ?, subject = ?, body = ?, issued_date = ?,
                                        respond_by = ?, requires_ack = ?
                                        WHERE id = ? AND employee_id = ? AND acknowledged_at IS NULL");
            $stmt->bind_param('sssssiii', $type, $subject, $body, $issued, $respondBy, $requiresAck, $id, $employee_id);
        } else {
            $stmt = $this->db->prepare("INSERT INTO hr_memos
                (employee_id, memo_type, subject, body, issued_date, respond_by, requires_ack, issued_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssssii', $employee_id, $type, $subject, $body, $issued, $respondBy, $requiresAck, $actor);
        }
        if (!$stmt->execute()) return $this->fail('Could not save the memo.');
        // Read the new id NOW: the notification insert below replaces insert_id.
        $savedId = $id > 0 ? $id : (int) $this->db->insert_id;

        if ($id === 0 && !empty($_POST['notify'])) {
            $this->notifyEmployee($employee_id, 'New ' . strtolower($type) . ' from HR', $subject,
                $type === 'Commendation' ? 'ri-award-line' : 'ri-file-text-line',
                $type === 'Commendation' ? 'success' : 'warning');
        }

        return [
            'result'  => true,
            'id'      => $savedId,
            'message' => $id > 0 ? 'Memo updated.' : 'Memo issued.',
        ];
    }

    /** HR closes the matter. */
    public function hr_memo_close()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_memos')) return $e;

        $id          = (int) ($_POST['id'] ?? 0);
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        list($resolution, $err) = $this->textField($_POST, 'resolution', 'Resolution', 255);
        if ($err) return $this->fail($err);

        $stmt = $this->db->prepare("UPDATE hr_memos SET status = 'closed', resolution = ?, closed_at = NOW()
                                    WHERE id = ? AND employee_id = ? AND status = 'open'");
        $stmt->bind_param('sii', $resolution, $id, $employee_id);
        $stmt->execute();
        if (!$stmt->affected_rows) return $this->fail('That memo is not open on this employee.');
        return ['result' => true, 'message' => 'Memo closed.'];
    }

    public function hr_memo_delete()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_memos')) return $e;
        $id          = (int) ($_POST['id'] ?? 0);
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $stmt = $this->db->prepare("DELETE FROM hr_memos WHERE id = ? AND employee_id = ?");
        $stmt->bind_param('ii', $id, $employee_id);
        if (!$stmt->execute() || !$stmt->affected_rows) return $this->fail('That memo is not on this employee.');
        return ['result' => true, 'message' => 'Memo deleted.'];
    }

    /**
     * The employee acknowledges a memo, optionally with their written
     * explanation. One explanation per memo: it is a statement on the record,
     * so it cannot be rewritten afterwards.
     */
    public function my_memo_ack()
    {
        $me = $this->selfId();
        if ($me <= 0) return $this->fail('Please sign in again.');
        if ($e = $this->needTable('hr_memos')) return $e;

        $memo = $this->memoRow((int) ($_POST['id'] ?? 0));
        if (!$memo || (int) $memo['employee_id'] !== $me) return $this->fail('That memo is not yours.');

        $response = trim((string) ($_POST['response'] ?? ''));
        if (mb_strlen($response) > 5000) return $this->fail('Your explanation is too long (max 5,000 characters).');
        if ($memo['memo_type'] === 'Notice to Explain' && $response === '' && !$memo['employee_response']) {
            return $this->fail('A Notice to Explain needs your written explanation.');
        }
        if ($response !== '' && $memo['employee_response']) {
            return $this->fail('You have already sent your explanation for this memo.');
        }
        if ($memo['acknowledged_at'] && $response === '') {
            return $this->fail('You have already acknowledged this memo.');
        }

        if ($response !== '') {
            $stmt = $this->db->prepare("UPDATE hr_memos SET employee_response = ?, responded_at = NOW(),
                                        acknowledged_at = COALESCE(acknowledged_at, NOW())
                                        WHERE id = ? AND employee_id = ?");
            $stmt->bind_param('sii', $response, $memo['id'], $me);
        } else {
            $stmt = $this->db->prepare("UPDATE hr_memos SET acknowledged_at = NOW() WHERE id = ? AND employee_id = ?");
            $stmt->bind_param('ii', $memo['id'], $me);
        }
        if (!$stmt->execute()) return $this->fail('Could not record your acknowledgment.');

        $this->notifyHrStaff(
            $response !== '' ? 'Memo explanation received' : 'Memo acknowledged',
            $this->employeeName($me) . ' — ' . $memo['subject'],
            'ri-file-check-line', 'info',
            'index.php?page=employee-details&id=' . $me . '#arrow-hrmemos');

        return ['result' => true, 'message' => $response !== '' ? 'Your explanation was sent to HR.' : 'Acknowledged.'];
    }
}
