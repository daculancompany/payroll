-- Complete the frozen-shift stamp on DTR_details. schedule_id / day_hours /
-- is_rest_day were already stamped at punch time, but late / undertime /
-- overtime / nsd_hours are computed from the shift's START/END/BREAK/GRAVEYARD
-- fields — and dtr_compute_day read those LIVE from work_schedules through the
-- schedule_id join. Editing a shift definition (in the app OR directly in the
-- DB) therefore silently re-priced every already-closed row the next time it
-- was re-derived (manual edit, incident repair, recompute).
--
-- These columns make the row self-sufficient: everything its figures were
-- computed from is on the row itself. NULL means "not stamped" (legacy row) —
-- dtr_compute_day falls back to the live work_schedules values exactly as
-- before, so old data keeps working without a backfill.
--
-- nsd_rate is deliberately NOT stamped here: it never enters DTR figure math
-- (nsd_hours is pure 22:00–06:00 window overlap), and the peso pricing of
-- those hours freezes on payroll_items at payroll-calculation time.
ALTER TABLE DTR_details
    ADD COLUMN IF NOT EXISTS sched_start     TIME       NULL AFTER is_rest_day,
    ADD COLUMN IF NOT EXISTS sched_end       TIME       NULL AFTER sched_start,
    ADD COLUMN IF NOT EXISTS sched_break     INT        NULL AFTER sched_end,
    ADD COLUMN IF NOT EXISTS sched_graveyard TINYINT(1) NULL AFTER sched_break;

-- Backfill every already-stamped row from the shift definition as it stands
-- NOW. Run this BEFORE anyone edits a work_schedules row post-migration — the
-- current values are the ones those rows were computed under.
UPDATE DTR_details d
INNER JOIN work_schedules ws ON ws.id = d.schedule_id
SET d.sched_start     = ws.start_time,
    d.sched_end       = ws.end_time,
    d.sched_break     = ws.break_minutes,
    d.sched_graveyard = ws.is_graveyard
WHERE d.schedule_id IS NOT NULL AND d.sched_start IS NULL;
