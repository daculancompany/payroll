-- End time on an hours-based filing (overtime / undertime), the partner of
-- ot_time_start added in 2026_09_ot_time_start.sql.
--
-- Same contract as the start: descriptive only. Nothing in ot_request_limit(),
-- undertime_request_limit(), the DTR write on approval, or payroll aggregation
-- reads it. ot_hours_requested stays the one figure that computes anything.
--
-- Deliberately NOT validated against ot_time_start: overtime that runs past
-- midnight ends at a clock time EARLIER than it started (10:00 PM → 2:00 AM),
-- so "end must be after start" would reject the most common night-shift filing.
ALTER TABLE attendance_requests
    ADD COLUMN ot_time_end TIME NULL DEFAULT NULL AFTER ot_time_start;
