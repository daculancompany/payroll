<?php
/**
 * HR module 6 — trainings, seminars and certifications.
 *
 * A certificate with an expiry (BLS, ACLS, safety officer…) feeds the HR
 * reminders, so a lapse is seen before it matters. The certificate scan
 * itself, when there is one, goes in Documents (category "Certificate").
 */
trait HrTrainingsModule
{
    /** @return array one employee's trainings, most recent first */
    public function trainings($employee_id)
    {
        if (!$this->tableExists('hr_trainings')) return [];
        return $this->rowsFor("SELECT * FROM hr_trainings WHERE employee_id = ?
                               ORDER BY COALESCE(end_date, start_date, DATE(created_at)) DESC, id DESC", $employee_id);
    }

    public function hr_training_save()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_trainings')) return $e;

        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $id          = (int) ($_POST['id'] ?? 0);
        if ($employee_id <= 0 || !$this->employeeExists($employee_id)) return $this->fail('That employee no longer exists.');
        if ($id > 0 && !$this->ownsRow('hr_trainings', $id, $employee_id)) return $this->fail('That training is not on this employee.');

        list($title, $err) = $this->textField($_POST, 'title', 'Title', 150, true);           if ($err) return $this->fail($err);
        list($provider, $err) = $this->textField($_POST, 'provider', 'Provider', 150);         if ($err) return $this->fail($err);
        list($start, $err) = $this->dateField($_POST, 'start_date', 'Start date');             if ($err) return $this->fail($err);
        list($end, $err) = $this->dateField($_POST, 'end_date', 'End date');                   if ($err) return $this->fail($err);
        list($certNo, $err) = $this->textField($_POST, 'certificate_no', 'Certificate no.', 80); if ($err) return $this->fail($err);
        list($expiry, $err) = $this->dateField($_POST, 'expiry_date', 'Certificate expiry');   if ($err) return $this->fail($err);
        list($remarks, $err) = $this->textField($_POST, 'remarks', 'Remarks', 255);            if ($err) return $this->fail($err);
        list($hours, $err) = $this->moneyField($_POST, 'hours', 'Hours', 9999);                if ($err) return $this->fail($err);
        if ($start && $end && $end < $start) return $this->fail('The end date cannot be before the start date.');
        if ($expiry && ($end ?? $start) && $expiry < ($end ?? $start)) return $this->fail('The certificate cannot expire before the training ended.');

        $actor = $this->actor();
        if ($id > 0) {
            $stmt = $this->db->prepare("UPDATE hr_trainings SET title = ?, provider = ?, start_date = ?, end_date = ?, hours = ?,
                                        certificate_no = ?, expiry_date = ?, remarks = ? WHERE id = ? AND employee_id = ?");
            $stmt->bind_param('ssssdsssii', $title, $provider, $start, $end, $hours, $certNo, $expiry, $remarks, $id, $employee_id);
        } else {
            $stmt = $this->db->prepare("INSERT INTO hr_trainings (employee_id, title, provider, start_date, end_date, hours,
                                        certificate_no, expiry_date, remarks, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssdsssi', $employee_id, $title, $provider, $start, $end, $hours, $certNo, $expiry, $remarks, $actor);
        }
        if (!$stmt->execute()) return $this->fail('Could not save the training.');
        return ['result' => true, 'id' => $id ?: (int) $this->db->insert_id, 'message' => $id ? 'Training updated.' : 'Training added.'];
    }

    public function hr_training_delete()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_trainings')) return $e;
        $id = (int) ($_POST['id'] ?? 0);
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $stmt = $this->db->prepare("DELETE FROM hr_trainings WHERE id = ? AND employee_id = ?");
        $stmt->bind_param('ii', $id, $employee_id);
        if (!$stmt->execute() || !$stmt->affected_rows) return $this->fail('That training is not on this employee.');
        return ['result' => true, 'message' => 'Training removed.'];
    }
}
