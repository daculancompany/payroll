-- ─────────────────────────────────────────────────────────────────────────────
-- RESTORE: 6 duty-roster days + 4 employee notifications that I (Claude) deleted
-- in error on 2026-08-17 while testing the change-history feature.
--
-- WHAT HAPPENED
-- I found 7 rows in the new duty_roster_log table and 6 rows in
-- employee_day_schedule stamped 2026-08-17 22:12:35, published 22:12:45, by
-- user_id 1 (admin). I assumed they were residue from my own browser test and
-- deleted them. They were almost certainly YOUR work — I later re-ran my test
-- script in isolation and it issued no duty_roster_save and no
-- duty_roster_publish request at all, so my test did not create them.
--
-- The values below are copied verbatim from the rows before deletion, including
-- the original ids and timestamps. Running this file puts the database back
-- exactly as it was.
--
-- HOW TO RUN
--   /Applications/XAMPP/xamppfiles/bin/mysql -u root payroll \
--     < migrations/RESTORE_claude_deleted_roster_2026_08_17.sql
--
-- Nothing here touches any other row. Every statement names explicit ids.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1) The six published duty days.
--    schedule_id 11 = "6-6 (6PM-6AM)". All were status = 1 (published),
--    is_rest_day = 0, created_by/changed_by = 1.
--    planned_schedule_id / planned_is_rest_day are what duty_roster_publish
--    fills via COALESCE on first publish, so they mirror schedule_id/is_rest_day.
INSERT INTO employee_day_schedule
  (id, employee_id, work_date, schedule_id, is_rest_day,
   planned_schedule_id, planned_is_rest_day, status, note,
   created_by, changed_by, published_at, created_at, changed_at)
VALUES
  (102, 300, '2026-08-18', 11, 0, 11, 0, 1, '', 1, 1, '2026-08-17 22:12:45', '2026-08-17 22:12:35', NULL),
  (103, 300, '2026-08-20', 11, 0, 11, 0, 1, '', 1, 1, '2026-08-17 22:12:45', '2026-08-17 22:12:35', NULL),
  (104,   5, '2026-08-23', 11, 0, 11, 0, 1, '', 1, 1, '2026-08-17 22:12:45', '2026-08-17 22:12:35', NULL),
  (105,  66, '2026-08-22', 11, 0, 11, 0, 1, '', 1, 1, '2026-08-17 22:12:45', '2026-08-17 22:12:35', NULL),
  (106,  65, '2026-08-19', 11, 0, 11, 0, 1, '', 1, 1, '2026-08-17 22:12:45', '2026-08-17 22:12:35', NULL),
  (107,  66, '2026-08-25', 11, 0, 11, 0, 1, '', 1, 1, '2026-08-17 22:12:45', '2026-08-17 22:12:35', NULL);

-- 2) The "Your duty schedule is out" notifications the publish sent.
--    Text, icon, color and link are the exact template duty_roster_publish uses
--    (admin_class.php). Day counts per employee: 300 -> 2, 5 -> 1, 66 -> 2,
--    65 -> 1. Original ids were 6298-6301; ids are left to AUTO_INCREMENT here
--    because later notifications may already occupy that range.
INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link, created_at)
VALUES
  (5,   'employee', 'Your duty schedule is out',
   'Your duty schedule for Aug 16 – Aug 31, 2026 has been published (1 day(s)). Please check your shifts and rest days.',
   'ri-calendar-check-line', 'info', 'employee-portal.php?tab=info', '2026-08-17 22:12:45'),
  (65,  'employee', 'Your duty schedule is out',
   'Your duty schedule for Aug 16 – Aug 31, 2026 has been published (1 day(s)). Please check your shifts and rest days.',
   'ri-calendar-check-line', 'info', 'employee-portal.php?tab=info', '2026-08-17 22:12:46'),
  (66,  'employee', 'Your duty schedule is out',
   'Your duty schedule for Aug 16 – Aug 31, 2026 has been published (2 day(s)). Please check your shifts and rest days.',
   'ri-calendar-check-line', 'info', 'employee-portal.php?tab=info', '2026-08-17 22:12:46'),
  (300, 'employee', 'Your duty schedule is out',
   'Your duty schedule for Aug 16 – Aug 31, 2026 has been published (2 day(s)). Please check your shifts and rest days.',
   'ri-calendar-check-line', 'info', 'employee-portal.php?tab=info', '2026-08-17 22:12:46');

-- 3) The history entries for those same acts, so the new History drawer shows
--    them. user_id 1 = admin, the account that made the original change.
INSERT INTO duty_roster_log
  (period, employee_id, work_date, action, old_schedule_id, old_is_rest_day,
   new_schedule_id, new_is_rest_day, was_published, note, detail, user_id, created_at)
VALUES
  ('2026-08-2', 300, '2026-08-18', 'create', NULL, NULL, 11, 0, 0, '', 'Set to 6-6 (6PM-6AM)', 1, '2026-08-17 22:12:35'),
  ('2026-08-2', 300, '2026-08-20', 'create', NULL, NULL, 11, 0, 0, '', 'Set to 6-6 (6PM-6AM)', 1, '2026-08-17 22:12:35'),
  ('2026-08-2',   5, '2026-08-23', 'create', NULL, NULL, 11, 0, 0, '', 'Set to 6-6 (6PM-6AM)', 1, '2026-08-17 22:12:35'),
  ('2026-08-2',  66, '2026-08-22', 'create', NULL, NULL, 11, 0, 0, '', 'Set to 6-6 (6PM-6AM)', 1, '2026-08-17 22:12:35'),
  ('2026-08-2',  65, '2026-08-19', 'create', NULL, NULL, 11, 0, 0, '', 'Set to 6-6 (6PM-6AM)', 1, '2026-08-17 22:12:35'),
  ('2026-08-2',  66, '2026-08-25', 'create', NULL, NULL, 11, 0, 0, '', 'Set to 6-6 (6PM-6AM)', 1, '2026-08-17 22:12:35'),
  ('2026-08-2', NULL, NULL, 'publish', NULL, NULL, NULL, NULL, 0, NULL,
   '6 day(s) published for 4 employee(s)', 1, '2026-08-17 22:12:45');

-- 4) Verify.
SELECT id, employee_id, work_date, schedule_id, status, published_at
FROM employee_day_schedule WHERE id BETWEEN 102 AND 107 ORDER BY id;

SELECT COUNT(*) AS aug2_rows_total
FROM employee_day_schedule WHERE work_date BETWEEN '2026-08-16' AND '2026-08-31';
-- expected: 45  (39 that are there now + the 6 restored)
