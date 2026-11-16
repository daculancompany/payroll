<?php
/**
 * HR module 9 — offboarding: the clearance a leaving employee goes through.
 *
 * Start a clearance → each office signs off its line → HR completes it. On
 * completion the 201 file gets the separation date and reason, the history
 * gets a "Separation" entry, and — only if HR ticks it — the employee is
 * deactivated.
 *
 * ⚠️ The ONE place this module writes a payroll table: deactivating sets
 * `employee.status = 0`, the same flag the employee edit screen's status
 * switch sets. Nothing else in payroll is touched. Final pay is tracked here
 * as a status only; it is computed in payroll as usual.
 */
trait HrOffboardingModule
{
    public static function separationTypes()
    {
        return ['Resignation', 'End of contract', 'Termination', 'Retirement', 'AWOL', 'Death', 'Other'];
    }

    /** The checklist a new clearance starts with; HR can add or drop lines. */
    public static function defaultClearanceItems()
    {
        return [
            'Immediate supervisor — turnover of work',
            'Department head',
            'Accounting — loans, cash advances and liabilities',
            'IT — accounts and equipment',
            'Property — company property and ID returned',
            'HR — exit interview and 201 file',
        ];
    }

    /** What the 201 file's employment_status becomes for each kind of separation. */
    private static function separationStatus($type)
    {
        $map = ['Resignation' => 'Resigned', 'Termination' => 'Terminated', 'Retirement' => 'Retired', 'AWOL' => 'Terminated'];
        return $map[$type] ?? null;
    }

    /** @return array clearances with progress; $status '' = all */
    public function clearances($status = 'open')
    {
        if (!$this->tableExists('hr_clearances')) return [];
        $where = in_array($status, ['open', 'completed'], true) ? "WHERE c.status = '" . $status . "'" : '';
        $res = $this->db->query("
            SELECT c.*, e.firstname, e.lastname, e.employee_no, e.status AS employee_status,
                   d.name AS department_name, u.name AS started_by_name,
                   (SELECT COUNT(*) FROM hr_clearance_items i WHERE i.clearance_id = c.id) AS items_total,
                   (SELECT COUNT(*) FROM hr_clearance_items i WHERE i.clearance_id = c.id AND i.cleared = 1) AS items_done
            FROM hr_clearances c
            JOIN employee e ON e.id = c.employee_id
            LEFT JOIN department d ON d.id = e.department_id
            LEFT JOIN users u ON u.id = c.started_by
            $where
            ORDER BY (c.status = 'open') DESC, c.separation_date DESC, c.id DESC
            LIMIT 300");
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        if ($this->tableExists('hr_assets')) {
            foreach ($rows as &$r) $r['assets_out'] = count($this->unreturnedAssets((int) $r['employee_id']));
            unset($r);
        }
        return $rows;
    }

    public function clearanceRow($id)
    {
        $rows = $this->rowsFor("SELECT * FROM hr_clearances WHERE id = ?", $id);
        return $rows[0] ?? null;
    }

    public function clearanceItems($clearance_id)
    {
        return $this->rowsFor("SELECT i.*, u.name AS cleared_by_name FROM hr_clearance_items i
                               LEFT JOIN users u ON u.id = i.cleared_by
                               WHERE i.clearance_id = ? ORDER BY i.sort_order, i.id", $clearance_id);
    }

    /** The open clearance of one employee, or null. */
    public function openClearanceFor($employee_id)
    {
        if (!$this->tableExists('hr_clearances')) return null;
        $rows = $this->rowsFor("SELECT * FROM hr_clearances WHERE employee_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", $employee_id);
        return $rows[0] ?? null;
    }

    public function hr_clearance_start()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_clearances')) return $e;

        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        if ($employee_id <= 0 || !$this->employeeExists($employee_id)) return $this->fail('Choose an employee.');
        if ($this->openClearanceFor($employee_id)) return $this->fail('This employee already has an open clearance.');

        list($type, $err) = $this->textField($_POST, 'separation_type', 'Type of separation', 30, true, self::separationTypes()); if ($err) return $this->fail($err);
        list($date, $err) = $this->dateField($_POST, 'separation_date', 'Last day', true);                                       if ($err) return $this->fail($err);
        list($reason, $err) = $this->textField($_POST, 'reason', 'Reason', 255);                                               if ($err) return $this->fail($err);

        $items = [];
        $posted = $_POST['items'] ?? null;
        foreach (is_array($posted) ? $posted : self::defaultClearanceItems() as $label) {
            $label = trim((string) $label);
            if ($label === '') continue;
            if (mb_strlen($label) > 80) return $this->fail('A checklist line is too long (max 80 characters).');
            $items[] = $label;
        }
        if (!$items) return $this->fail('The clearance needs at least one checklist line.');

        $actor = $this->actor();
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO hr_clearances (employee_id, separation_type, separation_date, reason, started_by)
                                        VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('isssi', $employee_id, $type, $date, $reason, $actor);
            if (!$stmt->execute()) throw new RuntimeException('clearance');
            $cid = (int) $this->db->insert_id;

            $ins = $this->db->prepare("INSERT INTO hr_clearance_items (clearance_id, label, sort_order) VALUES (?, ?, ?)");
            foreach ($items as $i => $label) {
                $ins->bind_param('isi', $cid, $label, $i);
                if (!$ins->execute()) throw new RuntimeException('item');
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return $this->fail('Could not start the clearance.');
        }

        return ['result' => true, 'id' => $cid, 'message' => 'Clearance started.'];
    }

    /** One office signs off (or un-signs) its line. */
    public function hr_clearance_item()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_clearance_items')) return $e;

        $item_id = (int) ($_POST['item_id'] ?? 0);
        $rows = $this->rowsFor("SELECT i.id, c.status FROM hr_clearance_items i JOIN hr_clearances c ON c.id = i.clearance_id
                                WHERE i.id = ?", $item_id);
        if (!$rows) return $this->fail('That checklist line no longer exists.');
        if ($rows[0]['status'] !== 'open') return $this->fail('This clearance is already completed.');

        $cleared = !empty($_POST['cleared']) ? 1 : 0;
        list($remarks, $err) = $this->textField($_POST, 'remarks', 'Remarks', 255);
        if ($err) return $this->fail($err);
        $actor = $cleared ? $this->actor() : null;

        $stmt = $this->db->prepare("UPDATE hr_clearance_items SET cleared = ?, remarks = ?, cleared_by = ?,
                                    cleared_at = IF(? = 1, NOW(), NULL) WHERE id = ?");
        $stmt->bind_param('isiii', $cleared, $remarks, $actor, $cleared, $item_id);
        if (!$stmt->execute()) return $this->fail('Could not save the checklist line.');
        return ['result' => true, 'message' => $cleared ? 'Signed off.' : 'Sign-off removed.'];
    }

    public function hr_clearance_final_pay()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_clearances')) return $e;

        $id = (int) ($_POST['id'] ?? 0);
        list($status, $err) = $this->textField($_POST, 'final_pay_status', 'Final pay status', 12, true, ['pending', 'processing', 'released']);
        if ($err) return $this->fail($err);
        list($note, $err) = $this->textField($_POST, 'final_pay_note', 'Note', 255);
        if ($err) return $this->fail($err);

        $stmt = $this->db->prepare("UPDATE hr_clearances SET final_pay_status = ?, final_pay_note = ? WHERE id = ?");
        $stmt->bind_param('ssi', $status, $note, $id);
        if (!$stmt->execute()) return $this->fail('Could not save the final pay status.');
        if (!$this->clearanceRow($id)) return $this->fail('That clearance no longer exists.');
        return ['result' => true, 'message' => 'Final pay status saved.'];
    }

    /**
     * Close the clearance. Every line must be signed off first. Writes the
     * separation to the 201 file and the history; deactivates the employee
     * only when `deactivate` is posted.
     */
    public function hr_clearance_complete()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_clearances')) return $e;

        $id = (int) ($_POST['id'] ?? 0);
        $c  = $this->clearanceRow($id);
        if (!$c || $c['status'] !== 'open') return $this->fail('That clearance is not open.');

        $pending = array_filter($this->clearanceItems($id), function ($i) { return (int) $i['cleared'] !== 1; });
        if ($pending) {
            return $this->fail(count($pending) . ' checklist line' . (count($pending) === 1 ? ' is' : 's are') . ' not signed off yet.');
        }

        $employee_id = (int) $c['employee_id'];
        $deactivate  = !empty($_POST['deactivate']) ? 1 : 0;
        $actor       = $this->actor();

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("UPDATE hr_clearances SET status = 'completed', completed_by = ?, completed_at = NOW(), deactivated = ?
                                        WHERE id = ? AND status = 'open'");
            $stmt->bind_param('iii', $actor, $deactivate, $id);
            $stmt->execute();
            if (!$stmt->affected_rows) throw new RuntimeException('raced');

            $fields = [
                'date_separated'    => $c['separation_date'],
                'separation_reason' => mb_substr($c['separation_type'] . ($c['reason'] ? ' — ' . $c['reason'] : ''), 0, 255),
            ];
            if ($status = self::separationStatus($c['separation_type'])) $fields['employment_status'] = $status;
            $before = $this->profileRow($employee_id)['employment_status'] ?? null;
            if (!$this->upsertProfile($employee_id, $fields, $actor)) throw new RuntimeException('profile');

            if ($this->tableExists('hr_movements')) {
                $type = 'Separation';
                $to   = $fields['employment_status'] ?? null;
                $mv = $this->db->prepare("INSERT INTO hr_movements (employee_id, movement_type, effective_date, from_status, to_status, remarks, recorded_by)
                                          VALUES (?, ?, ?, ?, ?, ?, ?)");
                $mv->bind_param('isssssi', $employee_id, $type, $c['separation_date'], $before, $to, $fields['separation_reason'], $actor);
                if (!$mv->execute()) throw new RuntimeException('movement');
            }

            if ($deactivate) {
                // The same flag the employee screen's status switch sets.
                $off = $this->db->prepare("UPDATE employee SET status = 0 WHERE id = ?");
                $off->bind_param('i', $employee_id);
                if (!$off->execute()) throw new RuntimeException('deactivate');
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return $this->fail('Could not complete the clearance.');
        }

        return [
            'result'  => true,
            'message' => 'Clearance completed. The 201 file now shows the separation'
                       . ($deactivate ? ', and the employee is deactivated.' : '.'),
        ];
    }

    /** Drop an open clearance started by mistake. A completed one stays on record. */
    public function hr_clearance_cancel()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_clearances')) return $e;
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("DELETE FROM hr_clearances WHERE id = ? AND status = 'open'");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute() || !$stmt->affected_rows) return $this->fail('Only an open clearance can be cancelled.');
        $i = $this->db->prepare("DELETE FROM hr_clearance_items WHERE clearance_id = ?");
        $i->bind_param('i', $id);
        $i->execute();
        return ['result' => true, 'message' => 'Clearance cancelled.'];
    }
}
