-- Optional reference / control number per employee deduction (voucher no.,
-- cash advance slip no., etc.) — the same field loans got in
-- 2026_09_loan_reference_no.sql. Free text, trimmed to 100 chars by
-- save_employee_deduction() in admin_class.php; blank is stored as NULL.
--
-- Run BEFORE uploading the PHP: the save and the Loan & Deduction Ledger
-- both read this column.
ALTER TABLE employee_deductions
    ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100) NULL AFTER deduction_id;
