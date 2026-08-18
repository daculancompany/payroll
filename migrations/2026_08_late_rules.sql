-- Tiered late (tardiness) rules + break window on shifts.
--
-- Until now `late` on a DTR row was the exact clock gap between the shift start
-- and the time-in, priced per minute. HR wants the usual PH-office bracket rule
-- instead — measured from EACH employee's own shift start, so it applies to
-- every schedule (8-5, Evening, Night) without per-shift setup:
--
--   0–15 min after shift start   -> grace, not late
--   16–30 min                    -> charged 1 hour
--   31–60 min                    -> charged 2 hours
--   more than 60 min             -> half day (day_hours / 2)
--
-- All of it lives in pay_settings so HR can tune it without a code edit.
-- Read by dtr_late_rules() / dtr_charge_late() in db_connect.php, which fall
-- back to the old exact-minute behaviour when these rows are missing, so
-- deploying the PHP before this migration is safe.
--
-- late_mode: 0 = exact minutes past the shift start (old behaviour; grace
-- still honoured), 1 = brackets above.
--
-- Effect on data: figures are FROZEN on the DTR row at punch time, so nothing
-- already recorded changes by itself. New scans use the rules immediately; an
-- explicit Recompute re-derives old rows under them (approved rows go back to
-- pending, as any recompute does).
INSERT INTO pay_settings (setting_key, setting_value, description) VALUES
 ('late_mode',            1,  'Late rule: 0 = exact minutes after shift start, 1 = grace + brackets + half day'),
 ('late_grace_minutes',   15, 'Minutes after the shift start that are NOT counted as late (applies in both modes)'),
 ('late_bracket_1_max',   30, 'Bracket 1: late up to this many minutes after shift start ...'),
 ('late_bracket_1_hours', 1,  '... is charged this many hours'),
 ('late_bracket_2_max',   60, 'Bracket 2: late up to this many minutes after shift start ...'),
 ('late_bracket_2_hours', 2,  '... is charged this many hours'),
 ('late_half_day_after',  60, 'Later than this many minutes after shift start = half day (day_hours / 2 charged, work hours capped at the other half)')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- WHEN the shift's break falls. Needed so only the part of the break that
-- overlaps the employee's actual presence is deducted (a 12:34 PM arrival on an
-- 8-5 shift with a 12-1 lunch was losing the whole hour), and so late/undertime
-- measure paid time only (that same 12:34 arrival is 4 paid hours late, not
-- 4.57). NULL = assume the break sits in the middle of the shift, which is what
-- every existing shift meant anyway.
ALTER TABLE work_schedules ADD COLUMN IF NOT EXISTS break_start TIME NULL AFTER break_minutes;
