<?php
/**
 * HR module 7 — company property issued to an employee (laptop, phone,
 * uniform, ID, keys…). Anything not yet returned is listed on the
 * employee's clearance when they leave.
 */
trait HrAssetsModule
{
    public static function assetCategories()
    {
        return ['Laptop / Computer', 'Phone', 'ID / Access card', 'Uniform', 'Keys', 'Tools / Equipment', 'Vehicle', 'Other'];
    }

    public static function assetConditions()
    {
        return ['New', 'Good', 'Fair', 'Damaged', 'Lost'];
    }

    /** @return array one employee's property, still-held first */
    public function assets($employee_id)
    {
        if (!$this->tableExists('hr_assets')) return [];
        return $this->rowsFor("SELECT * FROM hr_assets WHERE employee_id = ?
                               ORDER BY (returned_date IS NOT NULL), issued_date DESC, id DESC", $employee_id);
    }

    /** @return array items the employee still holds */
    public function unreturnedAssets($employee_id)
    {
        if (!$this->tableExists('hr_assets')) return [];
        return $this->rowsFor("SELECT * FROM hr_assets WHERE employee_id = ? AND returned_date IS NULL ORDER BY issued_date", $employee_id);
    }

    public function hr_asset_save()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_assets')) return $e;

        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $id          = (int) ($_POST['id'] ?? 0);
        if ($employee_id <= 0 || !$this->employeeExists($employee_id)) return $this->fail('That employee no longer exists.');
        if ($id > 0 && !$this->ownsRow('hr_assets', $id, $employee_id)) return $this->fail('That item is not on this employee.');

        list($item, $err) = $this->textField($_POST, 'item', 'Item', 120, true);                                       if ($err) return $this->fail($err);
        list($category, $err) = $this->textField($_POST, 'category', 'Category', 40, false, self::assetCategories());  if ($err) return $this->fail($err);
        list($serial, $err) = $this->textField($_POST, 'serial_no', 'Serial / tag no.', 80);                          if ($err) return $this->fail($err);
        list($issued, $err) = $this->dateField($_POST, 'issued_date', 'Issued date', true, 'past');                   if ($err) return $this->fail($err);
        list($cond, $err) = $this->textField($_POST, 'issued_condition', 'Condition', 20, false, self::assetConditions()); if ($err) return $this->fail($err);
        list($remarks, $err) = $this->textField($_POST, 'remarks', 'Remarks', 255);                                   if ($err) return $this->fail($err);

        $actor = $this->actor();
        if ($id > 0) {
            $stmt = $this->db->prepare("UPDATE hr_assets SET item = ?, category = ?, serial_no = ?, issued_date = ?,
                                        issued_condition = ?, remarks = ? WHERE id = ? AND employee_id = ?");
            $stmt->bind_param('ssssssii', $item, $category, $serial, $issued, $cond, $remarks, $id, $employee_id);
        } else {
            $stmt = $this->db->prepare("INSERT INTO hr_assets (employee_id, item, category, serial_no, issued_date, issued_condition, remarks, issued_by)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssi', $employee_id, $item, $category, $serial, $issued, $cond, $remarks, $actor);
        }
        if (!$stmt->execute()) return $this->fail('Could not save the item.');
        return ['result' => true, 'id' => $id ?: (int) $this->db->insert_id, 'message' => $id ? 'Item updated.' : 'Item issued.'];
    }

    /** Mark an item returned (or record it lost). */
    public function hr_asset_return()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_assets')) return $e;

        $id          = (int) ($_POST['id'] ?? 0);
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        list($returned, $err) = $this->dateField($_POST, 'returned_date', 'Returned date', true, 'past');           if ($err) return $this->fail($err);
        list($cond, $err) = $this->textField($_POST, 'return_condition', 'Condition', 20, true, self::assetConditions()); if ($err) return $this->fail($err);
        list($remarks, $err) = $this->textField($_POST, 'remarks', 'Remarks', 255);                                if ($err) return $this->fail($err);

        $rows = $this->rowsFor("SELECT issued_date, remarks FROM hr_assets WHERE id = ? AND returned_date IS NULL", $id);
        if (!$rows || !$this->ownsRow('hr_assets', $id, $employee_id)) return $this->fail('That item is not out with this employee.');
        if ($returned < $rows[0]['issued_date']) return $this->fail('It cannot be returned before it was issued.');
        $remarks = $remarks ?? $rows[0]['remarks'];

        $stmt = $this->db->prepare("UPDATE hr_assets SET returned_date = ?, return_condition = ?, remarks = ?
                                    WHERE id = ? AND employee_id = ? AND returned_date IS NULL");
        $stmt->bind_param('sssii', $returned, $cond, $remarks, $id, $employee_id);
        if (!$stmt->execute() || !$stmt->affected_rows) return $this->fail('Could not record the return.');
        return ['result' => true, 'message' => 'Return recorded.'];
    }

    public function hr_asset_delete()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_assets')) return $e;
        $id = (int) ($_POST['id'] ?? 0);
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $stmt = $this->db->prepare("DELETE FROM hr_assets WHERE id = ? AND employee_id = ?");
        $stmt->bind_param('ii', $id, $employee_id);
        if (!$stmt->execute() || !$stmt->affected_rows) return $this->fail('That item is not on this employee.');
        return ['result' => true, 'message' => 'Item removed.'];
    }
}
