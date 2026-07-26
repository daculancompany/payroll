-- ── Retire the unlabelled "Other Deduction" field ──────────────────────────
--
-- payroll_items.other_deduction was a single per-employee amount with NO label,
-- and it was hidden entirely on monthly batches. Named one-off items
-- (payroll_item_extras) do the same job properly: unlimited per employee, each
-- with its own description that reaches the payslip.
--
-- Existing values are migrated across as a one-off deduction literally labelled
-- "Other Deduction" so nothing is lost and every payslip keeps totalling the
-- same, then the column is zeroed. The COLUMN ITSELF IS KEPT (not dropped) so
-- older reports still referencing it read 0 instead of erroring.
--
-- Requires 2026_07_payroll_item_extras.sql to have run first.

INSERT INTO payroll_item_extras
    (payroll_item_id, payroll_id, employee_id, kind, label, amount, created_by, created_at)
SELECT i.id, i.payroll_id, i.employee_id, 1, 'Other Deduction', i.other_deduction, NULL, NOW()
FROM payroll_items i
WHERE i.other_deduction > 0
  AND NOT EXISTS (
      SELECT 1 FROM payroll_item_extras x
      WHERE x.payroll_item_id = i.id AND x.label = 'Other Deduction'
  );

UPDATE payroll_items SET other_deduction = 0 WHERE other_deduction <> 0;
