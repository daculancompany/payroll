-- Standard working hours for one full day, per employee, from their work
-- schedule (work_schedules.total_hours) instead of a hardcoded 8.
--
-- Payroll converts hours to days present (work_hours / day) and a daily rate
-- to an hourly / per-minute rate (per_day / day). Both used the literal 8, so
-- an employee on a 6-hour shift was paid 0.75 day for a full day's work and
-- had their late deduction computed off an 8-hour rate.
--
-- Frozen onto each payroll_item at calculation time, exactly like rate_type:
-- payslips, prints and net recalculation read the stored value, so reprinting
-- an old payroll can't be retro-altered by a later schedule change.
-- DEFAULT 8 keeps every already-calculated row on its original basis.

ALTER TABLE `payroll_items`
    ADD COLUMN `day_hours` DOUBLE NOT NULL DEFAULT 8 AFTER `rate_type`;
