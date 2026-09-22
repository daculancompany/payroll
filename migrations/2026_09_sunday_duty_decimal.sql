-- Rest-day duty is now paid on every approved hour at 130% (the employee files
-- the whole rendered time as Overtime), so a 13-hour rest day is 1.625 days.
-- sunday_duty was int(2) and rounded that to 2 — keep the fraction instead.
-- Widening only: existing whole-day values are unchanged.
ALTER TABLE payroll_items MODIFY sunday_duty DECIMAL(6,3) NOT NULL DEFAULT 0;
