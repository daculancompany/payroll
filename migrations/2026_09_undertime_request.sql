-- Fourth attendance-request type: UNDERTIME (left before the shift end).
--
-- Filed like overtime — hours against one date, in ot_hours_requested — but
-- approval writes NOTHING onto the DTR row. Payroll subtracts the approved
-- hours from the day's computed undertime at aggregation time (next to the
-- paid-leave reduction), so the excuse survives a DTR recompute and needs no
-- parked row when attendance for the date has not been imported yet.
ALTER TABLE attendance_requests
    MODIFY request_type ENUM('incident','overtime','rest_day','undertime')
    COLLATE utf8mb4_unicode_ci NOT NULL;
