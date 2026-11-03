-- A half-day of unpaid leave is half a day of absence, but payroll_items.absent
-- was int(10) while paid_leave is already DECIMAL(6,2), so the fraction rounded
-- away and the half day was never charged. Match paid_leave's precision.
-- Widening only: existing whole-day values are unchanged.
ALTER TABLE payroll_items MODIFY absent DECIMAL(6,2) NOT NULL DEFAULT 0.00;
