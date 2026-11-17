-- Holiday duty follows the hours of the shift, on an 8-hour day: a special
-- holiday worked on an 8-12 shift is 0.5 day, not 1. Both columns were int(2),
-- which rounded 0.5 up to a whole day and kept HR from typing a half day.
-- Same widening sunday_duty got in 2026_09_sunday_duty_decimal.sql.
--
-- Widening only: existing whole-day values are unchanged.
-- Run on live BEFORE deploying the code that writes fractions.
ALTER TABLE payroll_items
  MODIFY legal_holiday   DECIMAL(6,3) NOT NULL DEFAULT 0,
  MODIFY special_holiday DECIMAL(6,3) NOT NULL DEFAULT 0;
