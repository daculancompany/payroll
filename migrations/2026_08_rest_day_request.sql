-- ── Rest-day work as its own request type ──────────────────────────────────
--
-- Rest-day work and overtime were filed through the SAME 'overtime' request,
-- and that single label had to mean two different things:
--
--   regular day → "add these OT hours to my DTR"   (approval WRITES
--                 DTR_details.overtime = the filed hours)
--   rest day    → "authorize the duty I rendered on my day off"
--
-- Writing the filed hours onto a rest-day row is what made the same span
-- payable twice: the row's work_hours are already paid as a present day plus
-- the 30% rest-day premium, so an approved 7.5-hr filing on a 7.00 + 0.68 row
-- paid the 7 duty hours a second time at the OT rate.
--
-- Splitting the type makes the branch explicit instead of leaving it to be
-- re-derived from is_rest_day at every call site. A 'rest_day' request:
--   • authorizes the day — it is what satisfies the approval gate
--     (pay_settings.rest_day_auto_authorize off)
--   • NEVER overwrites the DTR figures; the scans keep deciding them
--   • still caps what payroll pays, exactly like an 'overtime' request does
--     (payroll pays min(row.overtime, approved hours))
--
-- Existing rows keep their type — no data migration is needed, because a
-- rest-day filing made before this change is still an 'overtime' row and the
-- approval gate accepts either type for a rest day.

ALTER TABLE attendance_requests
    MODIFY request_type ENUM('incident','overtime','rest_day')
    COLLATE utf8mb4_unicode_ci NOT NULL;
