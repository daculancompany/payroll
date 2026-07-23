-- Manual payroll adjustment per payroll item.
--   adjustment          signed amount ADDED to Net Pay (+ bonus/correction, − recovery)
--   adjustment_remarks  short reason shown beside the amount and on the payslip
-- "Other Deduction" needs no migration — payroll_items.other_deduction already
-- exists; it is now surfaced as an editable column on the Payroll Details page.
ALTER TABLE payroll_items
    ADD COLUMN adjustment DOUBLE NOT NULL DEFAULT 0 AFTER other_deduction,
    ADD COLUMN adjustment_remarks VARCHAR(255) DEFAULT NULL AFTER adjustment;
