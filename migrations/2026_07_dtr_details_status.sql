-- ── Per-record DTR_details approval status ──────────────────────────────────
-- calculate_payroll() only counts DTR_details rows with status = 1 (approved),
-- so the column must exist and pre-existing rows of already-approved batches
-- must be backfilled — otherwise recalculating an old payroll zeroes it out.

ALTER TABLE DTR_details
    ADD COLUMN IF NOT EXISTS status INT(1) DEFAULT 0 COMMENT '0=pending; 1=approved; 2=disapproved';

-- Backfill: every detail row of a batch that was approved (DTR.status = 2)
-- before per-record approval existed is treated as approved.
UPDATE DTR_details dd
JOIN DTR d ON d.id = dd.ddtr_id
SET dd.status = 1
WHERE d.status = 2 AND (dd.status IS NULL OR dd.status = 0);

-- Index for the date-range filters used by payroll calculation and the
-- attendance-summary / daily-board reports (DTR_details previously had no
-- index on date_time at all).
ALTER TABLE DTR_details
    ADD INDEX IF NOT EXISTS idx_emp_date (employee_id, date_time);
