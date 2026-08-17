-- ============================================================================
-- 2026_08_live_catchup.sql — one-shot catch-up for a LIVE database that is
-- behind on the August 2026 migrations.
--
-- Replaces running these four in sequence:
--   2026_08_leave_no_limit.sql
--   2026_08_retire_vacation_leave.sql
--   2026_08_delete_vacation_leave_and_cap_sick.sql
--   2026_08_dtr_early_grace_setting.sql
--
-- Why this file exists: running them in order fails on a live DB that never got
-- the first one — `UPDATE leave_types SET no_limit = 0` returns
-- "#1054 Unknown column 'no_limit'" because the ALTER that creates the column
-- lives in a separate migration.
--
-- Every statement here is IDEMPOTENT: safe to run on a fresh live DB, on one
-- that is partly migrated, or twice by accident. The column add and the
-- leave-type delete are guarded through information_schema, because MySQL has
-- no ADD COLUMN IF NOT EXISTS (MariaDB does, but this stays portable).
--
-- END STATE this produces:
--   leave_types.no_limit            column exists, default 0
--   Sick Leave (id 1)               no_limit = 0  → blocked once balance hits 0
--   Vacation Leave (id 2)           row deleted   → only if unreferenced
--   pay_settings.dtr_early_grace_hours  present, 4 hours
--
-- ⚠ READ BEFORE RUNNING — see the notes at the bottom of this file.
-- ============================================================================

-- Nothing here is destructive except the leave_types delete, which is guarded,
-- but a live DB deserves a transaction anyway. DDL (ALTER) commits implicitly
-- in MySQL/MariaDB, so this covers the DML only.
SET @dbname := DATABASE();


-- ── 1. leave_types.no_limit — add the column if it is missing ───────────────
-- From 2026_08_leave_no_limit.sql. Per-type "no balance limit" flag: filing is
-- never blocked by insufficient/unset credits, though the days are still
-- tracked and deducted.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'leave_types'
      AND COLUMN_NAME = 'no_limit'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `leave_types` ADD COLUMN `no_limit` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_paid`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 2. Sick Leave is capped again ───────────────────────────────────────────
-- From 2026_08_delete_vacation_leave_and_cap_sick.sql. The earlier migration
-- switched Sick Leave ON (no_limit = 1); this is the later decision that turns
-- it back OFF, so it behaves like Annual Leave. Applied by NAME, not id — a
-- live DB may have seeded its rows in a different order.
-- ⚠ This BLOCKS sick-leave filing for anyone without a credit row. See note A.
UPDATE `leave_types` SET `no_limit` = 0 WHERE `name` = 'Sick Leave';


-- ── 3. Retire and delete "Vacation Leave" ───────────────────────────────────
-- From 2026_08_retire_vacation_leave.sql + the delete that superseded it.
-- It was payroll-identical to Leave Without Pay (both is_paid = 0, both
-- excluded from the paid leaveMap in calculate_payroll, both filed under the
-- same "File LWOP" button) while colliding by name with the PAID Annual Leave.
--
-- The delete is guarded on zero references, mirroring delete_leave_type(),
-- which refuses to drop a type once any request points at it. On a live DB
-- with real history the guard will decline to delete — that is the correct
-- outcome, and step 3a leaves it deactivated and out of every picker instead.
SET @vac_id := (SELECT `id` FROM `leave_types` WHERE `name` = 'Vacation Leave' LIMIT 1);

-- 3a. Always deactivate first, so the type disappears from the UI either way.
UPDATE `leave_types` SET `status` = 0 WHERE `id` = @vac_id;

-- Credits on an unpaid type are never consumed or shown; they would only
-- resurface as noise in a future year-end rollover.
DELETE FROM `employee_leave_credits` WHERE `leave_type_id` = @vac_id;

-- 3b. Hard-delete only when genuinely unreferenced.
SET @refs := IF(@vac_id IS NULL, 1, (
      (SELECT COUNT(*) FROM `leave_requests`         WHERE `leave_type_id` = @vac_id)
    + (SELECT COUNT(*) FROM `employee_leave_credits` WHERE `leave_type_id` = @vac_id)
));
SET @sql := IF(@refs = 0,
    CONCAT('DELETE FROM `leave_types` WHERE `id` = ', @vac_id),
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 4. DTR early-punch grace window → pay_settings ──────────────────────────
-- From 2026_08_dtr_early_grace_setting.sql. Moves the window out of the
-- DTR_EARLY_GRACE_HOURS constant so an administrator can tune it without a
-- code edit. It decides which tap becomes the day's time-in: a punch earlier
-- than (shift start − grace) is discarded as noise so it cannot steal the IN
-- slot from the real punch. NOT a pay rule — clocking in early already earns
-- nothing, because dtr_compute_day clamps eff_in to the shift start.
--
-- dtr_early_grace_hours() falls back to the PHP constant when this row is
-- missing, so deploying the code before this migration is safe.
-- The ON DUPLICATE clause deliberately leaves setting_value alone: if an admin
-- has already tuned it on the live box, a re-run must not stomp their value.
INSERT INTO `pay_settings` (`setting_key`, `setting_value`, `description`)
VALUES ('dtr_early_grace_hours', 4,
        'Hours before shift start that a punch still counts as an early arrival; earlier taps are discarded as noise (does not affect pay — early time is never paid)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);


-- ── Verify — the result set below should match the END STATE above ──────────
SELECT 'leave_types' AS check_name,
       `id`, `name`, `is_paid`, `no_limit`, `status`
FROM `leave_types` ORDER BY `id`;

SELECT 'pay_settings' AS check_name, `setting_key`, `setting_value`
FROM `pay_settings` WHERE `setting_key` = 'dtr_early_grace_hours';


-- ============================================================================
-- NOTES
--
-- A. ⚠ SICK LEAVE GOES FROM "always fileable" TO "blocked at 0".
--    Any leave-eligible employee without a 2026 employee_leave_credits row for
--    Sick Leave can no longer file it. On the local DB that was 329 employees.
--    Check yours BEFORE running:
--
--      SELECT COUNT(*) FROM employee e
--      LEFT JOIN clasification cl ON cl.id = e.clasification_id
--      LEFT JOIN employee_leave_credits c
--             ON c.employee_id = e.id AND c.year = YEAR(CURDATE())
--            AND c.leave_type_id = (SELECT id FROM leave_types WHERE name = 'Sick Leave')
--      WHERE c.id IS NULL AND e.status = 1
--        AND UPPER(COALESCE(cl.clasification,'')) IN ('REGULAR','EXECUTIVE');
--
--    Then seed them with "Initialize Missing Credits" on the Leave Balances
--    page (one pass, uses the type's days_allowed default of 15).
--
-- B. pay_settings needs a UNIQUE index on setting_key for step 4's
--    ON DUPLICATE KEY UPDATE to behave. Confirm on live:
--      SHOW INDEX FROM pay_settings WHERE Column_name = 'setting_key';
--
-- C. Take a backup first:
--      mysqldump -u USER -p DBNAME leave_types employee_leave_credits \
--        leave_requests pay_settings > backup_before_catchup.sql
--
-- D. ROLLBACK:
--      UPDATE leave_types SET no_limit = 1 WHERE name = 'Sick Leave';
--      -- restoring a hard-deleted Vacation Leave needs the backup in C
-- ============================================================================
