-- Approved PAID leave counted into a payroll run. Day-fractions of approved
-- leave whose type is paid (leave_types.is_paid = 1): excluded from absences
-- (monthly) and paid (daily). Own column so it shows as its own payslip line.
ALTER TABLE payroll_items
    ADD COLUMN paid_leave DECIMAL(6,2) NOT NULL DEFAULT 0
    COMMENT 'approved paid-leave day-fractions counted into this run' AFTER absent;
