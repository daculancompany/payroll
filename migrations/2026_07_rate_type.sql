-- Per-employee pay basis: 'daily' (pay = days present × daily rate) or
-- 'monthly' (pay = period salary share − unpaid absences × daily rate).
-- Default 'daily' preserves current behavior; monthly is opt-in per employee.
-- Also frozen onto each payroll_item at calc time so a later rate-type change
-- doesn't retro-alter an already-calculated payroll.

ALTER TABLE `employee`
    ADD COLUMN `rate_type` VARCHAR(10) NOT NULL DEFAULT 'daily' AFTER `basic_pay`;

ALTER TABLE `payroll_items`
    ADD COLUMN `rate_type` VARCHAR(10) NOT NULL DEFAULT 'daily' AFTER `basic_pay`;
