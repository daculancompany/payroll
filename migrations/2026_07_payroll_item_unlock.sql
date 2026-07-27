-- ── Per-employee unlock while a payroll is out for review ──────────────────
--
-- A payroll sent for employee review (status 3) used to be entirely read-only,
-- so correcting one disputed employee meant pulling the whole batch back and
-- voiding the sign-offs of everyone who had already confirmed correctly.
--
-- An admin can now unlock a SINGLE payroll_items row instead. Only unlocked
-- rows accept writes while the batch is in review (enforced server-side in
-- payroll_item_write_block()); every other row stays frozen.
--
-- unlocked_at IS NULL  → locked (the default, and the state after re-locking).
-- reason is required by the UI so the audit trail says why it was reopened.

ALTER TABLE payroll_items
    ADD COLUMN IF NOT EXISTS unlocked_at     DATETIME     DEFAULT NULL AFTER review_sent_at,
    ADD COLUMN IF NOT EXISTS unlocked_by     INT(11)      DEFAULT NULL AFTER unlocked_at,
    ADD COLUMN IF NOT EXISTS unlocked_reason VARCHAR(255) DEFAULT NULL AFTER unlocked_by;

-- Finding the reopened rows in a 400-employee batch should not scan the table.
ALTER TABLE payroll_items
    ADD INDEX idx_unlocked (payroll_id, unlocked_at);
