<?php
/**
 * HR module 8 — documents an employee asks HR for (Certificate of Employment).
 *
 * The employee requests in the portal; HR releases or declines. A released
 * certificate is NOT stored: hr-certificate.php renders it from the record at
 * the moment it is opened, signed by the name HR entered on release. That
 * keeps one source of truth — the 201 file — and nothing on disk to leak.
 *
 * HR can also print a certificate for anyone directly from the Document
 * Requests screen, without a request.
 */
trait HrRequestsModule
{
    public static function requestTypes()
    {
        return [
            'coe'      => 'Certificate of Employment',
            'coe_comp' => 'Certificate of Employment with Compensation',
        ];
    }

    /** @return array requests, pending first; $status '' = all */
    public function docRequests($status = '')
    {
        if (!$this->tableExists('hr_doc_requests')) return [];
        $where = in_array($status, ['pending', 'released', 'declined', 'cancelled'], true)
               ? "WHERE r.status = '" . $status . "'" : '';
        $res = $this->db->query("
            SELECT r.*, e.firstname, e.lastname, e.employee_no, d.name AS department_name, u.name AS handled_by_name
            FROM hr_doc_requests r
            JOIN employee e ON e.id = r.employee_id
            LEFT JOIN department d ON d.id = e.department_id
            LEFT JOIN users u ON u.id = r.handled_by
            $where
            ORDER BY (r.status = 'pending') DESC, r.created_at DESC, r.id DESC
            LIMIT 300");
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        return $rows;
    }

    public function docRequestsForEmployee($employee_id)
    {
        if (!$this->tableExists('hr_doc_requests')) return [];
        return $this->rowsFor("SELECT * FROM hr_doc_requests WHERE employee_id = ? ORDER BY created_at DESC, id DESC LIMIT 50", $employee_id);
    }

    public function docRequestRow($id)
    {
        $rows = $this->rowsFor("SELECT * FROM hr_doc_requests WHERE id = ?", $id);
        return $rows[0] ?? null;
    }

    public function my_request_create()
    {
        $me = $this->selfId();
        if ($me <= 0) return $this->fail('Please sign in again.');
        if ($e = $this->needTable('hr_doc_requests')) return $e;

        $type = (string) ($_POST['request_type'] ?? '');
        if (!isset(self::requestTypes()[$type])) return $this->fail('Choose what you are requesting.');
        list($purpose, $err) = $this->textField($_POST, 'purpose', 'Purpose', 190, true);
        if ($err) return $this->fail($err);

        // One open request per kind: a second click is a duplicate, not a second certificate.
        $stmt = $this->db->prepare("SELECT id FROM hr_doc_requests WHERE employee_id = ? AND request_type = ? AND status = 'pending' LIMIT 1");
        $stmt->bind_param('is', $me, $type);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) return $this->fail('You already have a pending request for this. HR will get to it.');

        $ins = $this->db->prepare("INSERT INTO hr_doc_requests (employee_id, request_type, purpose) VALUES (?, ?, ?)");
        $ins->bind_param('iss', $me, $type, $purpose);
        if (!$ins->execute()) return $this->fail('Could not send your request.');
        $newId = (int) $this->db->insert_id;   // before the notification insert replaces it

        $this->notifyHrStaff('Document request', $this->employeeName($me) . ' requested a ' . self::requestTypes()[$type] . '.',
            'ri-file-paper-2-line', 'info', 'index.php?page=hr-requests');

        return ['result' => true, 'id' => $newId, 'message' => 'Request sent to HR.'];
    }

    public function my_request_cancel()
    {
        $me = $this->selfId();
        if ($me <= 0) return $this->fail('Please sign in again.');
        if ($e = $this->needTable('hr_doc_requests')) return $e;
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("UPDATE hr_doc_requests SET status = 'cancelled' WHERE id = ? AND employee_id = ? AND status = 'pending'");
        $stmt->bind_param('ii', $id, $me);
        $stmt->execute();
        if (!$stmt->affected_rows) return $this->fail('Only a pending request of yours can be cancelled.');
        return ['result' => true, 'message' => 'Request cancelled.'];
    }

    /** HR releases: the employee can now open the certificate in the portal. */
    public function hr_request_release()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_doc_requests')) return $e;

        $id  = (int) ($_POST['id'] ?? 0);
        $req = $this->docRequestRow($id);
        if (!$req || $req['status'] !== 'pending') return $this->fail('That request is no longer pending.');

        list($signName, $err) = $this->textField($_POST, 'signatory_name', 'Signatory name', 120, true);   if ($err) return $this->fail($err);
        list($signTitle, $err) = $this->textField($_POST, 'signatory_title', 'Signatory title', 120, true); if ($err) return $this->fail($err);
        list($remarks, $err) = $this->textField($_POST, 'hr_remarks', 'Remarks', 255);                    if ($err) return $this->fail($err);

        $actor = $this->actor();
        $stmt = $this->db->prepare("UPDATE hr_doc_requests SET status = 'released', signatory_name = ?, signatory_title = ?,
                                    hr_remarks = ?, released_at = NOW(), handled_by = ? WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('sssii', $signName, $signTitle, $remarks, $actor, $id);
        $stmt->execute();
        if (!$stmt->affected_rows) return $this->fail('That request is no longer pending.');

        $this->notifyEmployee((int) $req['employee_id'], 'Your document is ready',
            self::requestTypes()[$req['request_type']] . ' — open it from HR › Requests in the portal.',
            'ri-file-check-line', 'success');

        return ['result' => true, 'message' => 'Released. The employee can open it in the portal.'];
    }

    public function hr_request_decline()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_doc_requests')) return $e;

        $id  = (int) ($_POST['id'] ?? 0);
        $req = $this->docRequestRow($id);
        if (!$req || $req['status'] !== 'pending') return $this->fail('That request is no longer pending.');
        list($remarks, $err) = $this->textField($_POST, 'hr_remarks', 'Reason', 255, true);
        if ($err) return $this->fail($err);

        $actor = $this->actor();
        $stmt = $this->db->prepare("UPDATE hr_doc_requests SET status = 'declined', hr_remarks = ?, handled_by = ?
                                    WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('sii', $remarks, $actor, $id);
        $stmt->execute();
        if (!$stmt->affected_rows) return $this->fail('That request is no longer pending.');

        $this->notifyEmployee((int) $req['employee_id'], 'Document request declined',
            self::requestTypes()[$req['request_type']] . ': ' . $remarks, 'ri-file-forbid-line', 'danger');

        return ['result' => true, 'message' => 'Declined. The employee was told why.'];
    }

    /**
     * Everything a certificate says about one employee, from the record.
     * Missing pieces come back null; the certificate leaves them out rather
     * than printing a blank or a guess.
     */
    public function certificateData($employee_id)
    {
        $rows = $this->rowsFor("SELECT e.id, e.firstname, e.middlename, e.lastname, e.ext, e.employee_no, e.status,
                                       e.basic_pay, p.name AS position, d.name AS department
                                FROM employee e
                                LEFT JOIN position p ON p.id = e.position_id
                                LEFT JOIN department d ON d.id = e.department_id
                                WHERE e.id = ?", $employee_id);
        if (!$rows) return null;
        $e = $rows[0];
        $profile = $this->profileRow($employee_id);

        $sys = $this->db->query("SELECT name, email, contact FROM system_settings LIMIT 1");
        $sys = $sys ? $sys->fetch_assoc() : null;

        return [
            'name'              => $this->employeeName($employee_id),
            'sex'               => $profile['sex'] ?? null,
            'employee_no'       => $e['employee_no'] ?: null,
            'position'          => $e['position'] ?: null,
            'department'        => $e['department'] ?: null,
            'date_hired'        => $profile['date_hired'] ?? null,
            'date_separated'    => $profile['date_separated'] ?? null,
            'employment_status' => $profile['employment_status'] ?? null,
            'active'            => (int) $e['status'] === 1,
            'monthly_pay'       => (float) $e['basic_pay'] > 0 ? (float) $e['basic_pay'] : null,
            'company'           => trim((string) ($sys['name'] ?? '')) ?: 'The Company',
            'company_email'     => trim((string) ($sys['email'] ?? '')) ?: null,
            'company_contact'   => trim((string) ($sys['contact'] ?? '')) ?: null,
        ];
    }
}
