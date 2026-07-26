-- ── "HR has read this" marker on employee sign-off messages ────────────────
--
-- An employee can attach a message when they confirm or dispute a DTR batch or
-- a payslip. On a 400-employee run HR had no way to tell an unread message from
-- one already handled, so both review panels now show an UNREAD dot until the
-- message is opened.
--
-- seen_at / seen_by are set the first time a staff member opens the message
-- popup (ajax.php?action=mark_review_seen). They are deliberately separate from
-- resolved_at: reading a dispute is not the same as answering it.

ALTER TABLE dtr_employee_reviews
    ADD COLUMN IF NOT EXISTS seen_at DATETIME DEFAULT NULL AFTER admin_reply,
    ADD COLUMN IF NOT EXISTS seen_by INT(11) DEFAULT NULL AFTER seen_at;

ALTER TABLE payroll_employee_reviews
    ADD COLUMN IF NOT EXISTS seen_at DATETIME DEFAULT NULL AFTER admin_reply,
    ADD COLUMN IF NOT EXISTS seen_by INT(11) DEFAULT NULL AFTER seen_at;

-- Anything already answered was obviously read — backfill so existing batches
-- do not light up as a wall of unread messages after this migration runs.
UPDATE dtr_employee_reviews
    SET seen_at = resolved_at, seen_by = resolved_by
    WHERE resolved_at IS NOT NULL AND seen_at IS NULL;

UPDATE payroll_employee_reviews
    SET seen_at = resolved_at, seen_by = resolved_by
    WHERE resolved_at IS NOT NULL AND seen_at IS NULL;
