<?php
/**
 * HR module 3 — employment history: hire, promotion, transfer, pay change,
 * status change, each with the date it took effect.
 *
 * A RECORD, NOT A SWITCH. Saving a movement never changes the employee's pay,
 * position or department in the payroll tables — those stay on the employee's
 * own edit screen, where payroll expects them. What a movement CAN do is keep
 * the 201 file in step: a "Status change" to Regular may also stamp
 * employment_status and regularization_date, which are HR's own columns.
 *
 * From/to are stored as text (not ids) so the history still reads correctly
 * after a position or department is renamed or deleted.
 */
trait HrMovementsModule
{
    public static function movementTypes()
    {
        return [
            'Hired', 'Promotion', 'Transfer', 'Salary adjustment', 'Status change',
            'Position change', 'Demotion', 'Separation', 'Other',
        ];
    }

    /** @return array one employee's history, newest first */
    public function movements($employee_id)
    {
        if (!$this->tableExists('hr_movements')) return [];
        return $this->rowsFor("SELECT m.*, u.name AS recorded_by_name
                               FROM hr_movements m LEFT JOIN users u ON u.id = m.recorded_by
                               WHERE m.employee_id = ?
                               ORDER BY m.effective_date DESC, m.id DESC", $employee_id);
    }

    /**
     * Where the employee stands today — prefills the "from" side of a new
     * movement so HR only types what changed.
     */
    public function currentSnapshot($employee_id)
    {
        $rows = $this->rowsFor("SELECT p.name AS position, d.name AS department, e.basic_pay
                                FROM employee e
                                LEFT JOIN position p ON p.id = e.position_id
                                LEFT JOIN department d ON d.id = e.department_id
                                WHERE e.id = ?", $employee_id);
        $snap = $rows[0] ?? ['position' => null, 'department' => null, 'basic_pay' => null];
        $profile = $this->profileRow($employee_id);
        $snap['employment_status'] = $profile['employment_status'] ?? null;
        $snap['date_hired']        = $profile['date_hired'] ?? null;
        return $snap;
    }

    public function hr_movement_save()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_movements')) return $e;

        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $id          = (int) ($_POST['id'] ?? 0);
        if ($employee_id <= 0 || !$this->employeeExists($employee_id)) return $this->fail('That employee no longer exists.');
        if ($id > 0 && !$this->ownsRow('hr_movements', $id, $employee_id)) {
            return $this->fail('That entry is not on this employee.');
        }

        $statuses = HrManagement::profileFieldMap()['employment_status']['values'];
        $f = [];
        foreach ([
            ['movement_type', 'Type', 30, true, self::movementTypes()],
            ['from_position', 'From position', 120, false, []],
            ['to_position', 'To position', 120, false, []],
            ['from_department', 'From department', 120, false, []],
            ['to_department', 'To department', 120, false, []],
            ['from_status', 'From status', 30, false, $statuses],
            ['to_status', 'To status', 30, false, $statuses],
            ['remarks', 'Remarks', 255, false, []],
        ] as $spec) {
            list($v, $err) = $this->textField($_POST, $spec[0], $spec[1], $spec[2], $spec[3], $spec[4]);
            if ($err) return $this->fail($err);
            $f[$spec[0]] = $v;
        }
        list($effective, $err) = $this->dateField($_POST, 'effective_date', 'Effective date', true);
        if ($err) return $this->fail($err);
        list($fromSalary, $err) = $this->moneyField($_POST, 'from_salary', 'From salary');
        if ($err) return $this->fail($err);
        list($toSalary, $err) = $this->moneyField($_POST, 'to_salary', 'To salary');
        if ($err) return $this->fail($err);

        // A movement that records no change at all is a typo, not history.
        $changed = $f['to_position'] !== null || $f['to_department'] !== null
                || $toSalary !== null || $f['to_status'] !== null || $f['remarks'] !== null;
        if (!$changed) return $this->fail('Fill in what changed (a "to" value or a remark).');

        $actor = $this->actor();
        if ($id > 0) {
            $stmt = $this->db->prepare("UPDATE hr_movements SET movement_type = ?, effective_date = ?,
                from_position = ?, to_position = ?, from_department = ?, to_department = ?,
                from_salary = ?, to_salary = ?, from_status = ?, to_status = ?, remarks = ?
                WHERE id = ? AND employee_id = ?");
            $stmt->bind_param('ssssssddsssii', $f['movement_type'], $effective,
                $f['from_position'], $f['to_position'], $f['from_department'], $f['to_department'],
                $fromSalary, $toSalary, $f['from_status'], $f['to_status'], $f['remarks'], $id, $employee_id);
        } else {
            $stmt = $this->db->prepare("INSERT INTO hr_movements (employee_id, movement_type, effective_date,
                from_position, to_position, from_department, to_department, from_salary, to_salary,
                from_status, to_status, remarks, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssddsssi', $employee_id, $f['movement_type'], $effective,
                $f['from_position'], $f['to_position'], $f['from_department'], $f['to_department'],
                $fromSalary, $toSalary, $f['from_status'], $f['to_status'], $f['remarks'], $actor);
        }
        if (!$stmt->execute()) return $this->fail('Could not save the entry.');
        $savedId = $id > 0 ? $id : (int) $this->db->insert_id;   // before the 201 upsert below

        // Keep the 201 file in step — only HR's own columns, only when asked,
        // and only for a change that has already taken effect.
        $note = '';
        if (!empty($_POST['apply_201']) && $effective <= date('Y-m-d')) {
            $fields = [];
            if ($f['to_status'] !== null) {
                $fields['employment_status'] = $f['to_status'];
                $current = $this->profileRow($employee_id);
                if ($f['to_status'] === 'Regular' && empty($current['regularization_date'])) {
                    $fields['regularization_date'] = $effective;
                }
            }
            if ($f['movement_type'] === 'Hired') {
                $current = $current ?? $this->profileRow($employee_id);
                if (empty($current['date_hired'])) $fields['date_hired'] = $effective;
            }
            if ($fields && $this->upsertProfile($employee_id, $fields, $actor)) {
                $note = ' The 201 file was updated too.';
            }
        }

        return [
            'result'  => true,
            'id'      => $savedId,
            'message' => ($id > 0 ? 'History entry updated.' : 'History entry added.') . $note,
        ];
    }

    public function hr_movement_delete()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_movements')) return $e;
        $id          = (int) ($_POST['id'] ?? 0);
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $stmt = $this->db->prepare("DELETE FROM hr_movements WHERE id = ? AND employee_id = ?");
        $stmt->bind_param('ii', $id, $employee_id);
        if (!$stmt->execute() || !$stmt->affected_rows) return $this->fail('That entry is not on this employee.');
        return ['result' => true, 'message' => 'History entry removed.'];
    }
}
