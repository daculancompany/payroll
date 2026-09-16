-- Optional reference / control number per loan (SSS loan no., Pag-IBIG
-- application no., voucher no., etc.). Free text, trimmed to 100 chars by
-- save_employee_loan() in admin_class.php; blank is stored as NULL.
ALTER TABLE loans
    ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100) NULL AFTER loan_type;
