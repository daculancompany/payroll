-- ============================================================================
-- DTR seeder · Jul 1 – Jul 15, 2026 · 2 employees
-- Creates one DTR batch (status 1 = Pending) with 12 daily records per
-- employee (status 0 = pending, so you can approve them through DTR Review).
-- Punches live only in DTR_details.logs (JSON) — no other table is touched.
--
-- BEFORE RUNNING on live: set the 4 variables below to real ids.
--   SELECT id, firstname, lastname FROM employee WHERE status = 1;
--   SELECT id, username FROM users WHERE role IN (1,8);   -- timekeeper/admin
--   SELECT id, name FROM sites;
-- Re-running is safe: the guard at the top aborts if the batch already exists.
-- RUN THE WHOLE FILE IN ONE GO (phpMyAdmin → SQL tab → paste all → Go). The
-- SET @variables only live for one session; running it in pieces gives
-- error #1452 (foreign key) because @dtr / @emp1 / @emp2 are NULL.
-- ============================================================================
SET @emp1        := 6;   -- first employee id  (local: CRISANTO AJOC)
SET @emp2        := 7;   -- second employee id (local: JENALYN AJOC)
SET @timekeeper  := 1;   -- users.id that "uploaded" the batch
SET @site        := 1;   -- sites.id
SET @date_from   := '2026-07-01' COLLATE utf8mb4_unicode_ci;
SET @date_to     := '2026-07-15' COLLATE utf8mb4_unicode_ci;

-- Pre-checks — read this result BEFORE looking at anything else. If any row
-- says ABORT/MISSING, fix the variable above and run again.
SET @exists := (SELECT COUNT(*) FROM DTR WHERE date_from = @date_from AND date_to = @date_to);
SELECT 'batch'       AS item, IF(@exists > 0, 'ABORT: a DTR batch for this period already exists — delete it first or change the dates', 'OK') AS check_result
UNION ALL SELECT 'emp1',       IF(EXISTS(SELECT 1 FROM employee WHERE id = @emp1), CONCAT('OK: ', (SELECT CONCAT(firstname,' ',lastname) FROM employee WHERE id = @emp1)), 'MISSING: employee @emp1 not found')
UNION ALL SELECT 'emp2',       IF(EXISTS(SELECT 1 FROM employee WHERE id = @emp2), CONCAT('OK: ', (SELECT CONCAT(firstname,' ',lastname) FROM employee WHERE id = @emp2)), 'MISSING: employee @emp2 not found')
UNION ALL SELECT 'timekeeper', IF(EXISTS(SELECT 1 FROM users    WHERE id = @timekeeper), 'OK', 'MISSING: users.id @timekeeper not found')
UNION ALL SELECT 'site',       IF(EXISTS(SELECT 1 FROM sites    WHERE id = @site), 'OK', 'MISSING: sites.id @site not found');

INSERT INTO DTR (local_id, site_id, timekeeper_id, employer_id, date_from, date_to, device_id, uploaded_by, approved_by, file, note, status, ptype)
SELECT 0, @site, @timekeeper, 1, @date_from, @date_to, 0, NULL, NULL, 'biometric', 'seeded', 1, 0
FROM DUAL WHERE @exists = 0;
-- Resolve the batch id from the table itself (not LAST_INSERT_ID), so this
-- works even if phpMyAdmin runs statements in separate sessions. NULL when the
-- guard fired → the NOT NULL ddtr_id makes the next INSERT fail, nothing seeded.
SET @dtr := IF(@exists = 0, (SELECT MAX(id) FROM DTR WHERE date_from = @date_from AND date_to = @date_to AND note = 'seeded'), NULL);

INSERT INTO DTR_details
 (ddtr_id, employee_id, date_time, work_hours, overtime, undertime, late, logs, attendance_type, schedule_id, day_hours, is_rest_day, sched_start, sched_end, sched_break, sched_graveyard, day_type, nsd_hours, is_complete, notes, status)
VALUES
 (@dtr, @emp1, '2026-07-01', 8, 0.33, 0, 0, '[{"dateTime":"2026-07-01 06:19:54","type":"bio"},{"dateTime":"2026-07-01 17:19:54","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-02', 8, 0, 0, 0, '[{"dateTime":"2026-07-02 07:39:20","type":"bio"},{"dateTime":"2026-07-02 17:09:11","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-03', 8, 0, 0, 0, '[{"dateTime":"2026-07-03 07:39:15","type":"bio"},{"dateTime":"2026-07-03 17:06:41","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-04', 0, 0, 0, 0, '[{"dateTime":"2026-07-04 07:19:54","type":"bio"}]', 'biometric', 1, 8.00, 1, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 0, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-06', 7.67, 0, 0, 0.33, '[{"dateTime":"2026-07-06 08:19:31","type":"bio"},{"dateTime":"2026-07-06 17:06:59","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-07', 7.77, 0, 0, 0.23, '[{"dateTime":"2026-07-07 08:13:44","type":"bio"},{"dateTime":"2026-07-07 17:06:48","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-08', 8, 0, 0, 0, '[{"dateTime":"2026-07-08 07:44:49","type":"bio"},{"dateTime":"2026-07-08 17:07:31","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-09', 7.75, 0, 0, 0.25, '[{"dateTime":"2026-07-09 08:15:01","type":"bio"},{"dateTime":"2026-07-09 17:00:55","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-10', 8, 0, 0, 0, '[{"dateTime":"2026-07-10 07:40:03","type":"bio"},{"dateTime":"2026-07-10 17:10:15","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-13', 8, 0.98, 0, 0, '[{"dateTime":"2026-07-13 07:41:52","type":"bio"},{"dateTime":"2026-07-13 17:58:46","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-14', 8, 0, 0, 0, '[{"dateTime":"2026-07-14 07:42:54","type":"bio"},{"dateTime":"2026-07-14 17:02:53","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp1, '2026-07-15', 8, 0, 0, 0, '[{"dateTime":"2026-07-15 07:41:39","type":"bio"},{"dateTime":"2026-07-15 17:10:47","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-01', 8, 0.68, 0, 0, '[{"dateTime":"2026-07-01 07:40:54","type":"bio"},{"dateTime":"2026-07-01 17:40:54","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, 'Day Shift · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-02', 8, 0, 1.65, 0, '[{"dateTime":"2026-07-02 08:20:54","type":"bio"},{"dateTime":"2026-07-02 15:20:54","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0.65, 1, 'Day Shift · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-03', 7.69, 0, 0, 0.31, '[{"dateTime":"2026-07-03 08:18:19","type":"bio"},{"dateTime":"2026-07-03 17:04:01","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-04', 0, 0, 0, 0, '[{"dateTime":"2026-07-04 07:19:54","type":"bio"}]', 'biometric', 1, 8.00, 1, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 0, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-06', 8, 0, 0, 0, '[{"dateTime":"2026-07-06 07:46:32","type":"bio"},{"dateTime":"2026-07-06 17:05:47","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-07', 7.82, 0, 0, 0.18, '[{"dateTime":"2026-07-07 08:10:45","type":"bio"},{"dateTime":"2026-07-07 17:00:55","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-08', 7.78, 0, 0, 0.22, '[{"dateTime":"2026-07-08 08:13:14","type":"bio"},{"dateTime":"2026-07-08 17:02:57","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-09', 8, 0, 0, 0, '[{"dateTime":"2026-07-09 07:41:53","type":"bio"},{"dateTime":"2026-07-09 17:01:41","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-10', 8, 0, 0, 0, '[{"dateTime":"2026-07-10 07:46:46","type":"bio"},{"dateTime":"2026-07-10 17:03:05","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-13', 7.68, 0, 0, 0.32, '[{"dateTime":"2026-07-13 08:19:12","type":"bio"},{"dateTime":"2026-07-13 17:04:52","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-14', 8, 0.51, 0, 0, '[{"dateTime":"2026-07-14 07:50:30","type":"bio"},{"dateTime":"2026-07-14 17:30:33","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0),
 (@dtr, @emp2, '2026-07-15', 8, 0, 0, 0, '[{"dateTime":"2026-07-15 07:46:26","type":"bio"},{"dateTime":"2026-07-15 17:02:19","type":"bio"}]', 'biometric', 1, 8.00, 0, '08:00:00', '17:00:00', 60, 0, 'regular', 0, 1, '8-5 (8AM-5PM) · 8:00 AM–5:00 PM', 0);

SELECT @dtr AS new_dtr_id, COUNT(*) AS records_inserted FROM DTR_details WHERE ddtr_id = @dtr;
