-- ── Per-employee payroll review color marks ─────────────────────────────────
-- Admin marks each payroll row while checking it:
--   1 = green  (okay / verified — row inputs become read-only in the UI)
--   2 = orange (something wrong)
--   3 = blue   (ongoing review)
--   0 = no mark
-- review_comment holds the reviewer's note (shown as tooltip on the mark).

ALTER TABLE payroll_items
    ADD COLUMN IF NOT EXISTS review_status INT(1) NOT NULL DEFAULT 0 COMMENT '0=none;1=ok(green);2=issue(orange);3=reviewing(blue)',
    ADD COLUMN IF NOT EXISTS review_comment TEXT NULL;
