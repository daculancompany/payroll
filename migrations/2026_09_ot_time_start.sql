-- Start time on an hours-based filing (overtime / undertime).
--
-- Purely descriptive: the employee says WHEN the overtime began, or when they
-- left early, so the approver reads the claim against the day's scans without
-- guessing. It is display only — nothing in ot_request_limit(),
-- undertime_request_limit(), the DTR write on approval, or payroll aggregation
-- reads this column. The hours in ot_hours_requested remain the only figure
-- that computes anything.
--
-- Its own column rather than the idle claimed_time_in, because both request
-- lists branch on "claimed_time_in is set → render as an incident's in–out
-- pair", which would hide the hours on an OT row.
ALTER TABLE attendance_requests
    ADD COLUMN ot_time_start TIME NULL DEFAULT NULL AFTER ot_hours_requested;
