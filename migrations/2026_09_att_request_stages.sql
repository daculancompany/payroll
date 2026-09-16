-- Attendance requests (incident / overtime / rest day / undertime) now run the
-- SAME four-stage, area-based approval chain as leave:
--     Section/Unit Head → Supervisor → Department Head → HR
-- (LEAVE_APPROVAL_STAGES in db_connect.php; approvers from area_approver;
-- optional stages auto-skip). Per stage the contract is the four columns
-- {stage}_status / {stage}_by / {stage}_remarks / {stage}_at, exactly as on
-- leave_requests (migrations/2026_08_area_approvers.sql).
--
-- The legacy single-decision columns (status / reviewed_by / reviewed_at /
-- reviewer_remarks) stay and are written on the FINAL decision only, so every
-- reader of `status = 1` (payroll OT cap, undertime excuse, rest-day DTR gate,
-- DTR marks, dashboards) keeps meaning "fully approved".
ALTER TABLE attendance_requests
    ADD COLUMN IF NOT EXISTS sec_status    TINYINT      NOT NULL DEFAULT 0 AFTER attachment,
    ADD COLUMN IF NOT EXISTS sec_by        INT          NULL AFTER sec_status,
    ADD COLUMN IF NOT EXISTS sec_remarks   VARCHAR(255) NULL AFTER sec_by,
    ADD COLUMN IF NOT EXISTS sec_at        DATETIME     NULL AFTER sec_remarks,
    ADD COLUMN IF NOT EXISTS sup_status    TINYINT      NOT NULL DEFAULT 0 AFTER sec_at,
    ADD COLUMN IF NOT EXISTS sup_by        INT          NULL AFTER sup_status,
    ADD COLUMN IF NOT EXISTS sup_remarks   VARCHAR(255) NULL AFTER sup_by,
    ADD COLUMN IF NOT EXISTS sup_at        DATETIME     NULL AFTER sup_remarks,
    ADD COLUMN IF NOT EXISTS admin_status  TINYINT      NOT NULL DEFAULT 0 AFTER sup_at,
    ADD COLUMN IF NOT EXISTS admin_by      INT          NULL AFTER admin_status,
    ADD COLUMN IF NOT EXISTS admin_remarks VARCHAR(255) NULL AFTER admin_by,
    ADD COLUMN IF NOT EXISTS admin_at      DATETIME     NULL AFTER admin_remarks,
    ADD COLUMN IF NOT EXISTS hr_status     TINYINT      NOT NULL DEFAULT 0 AFTER admin_at,
    ADD COLUMN IF NOT EXISTS hr_by         INT          NULL AFTER hr_status,
    ADD COLUMN IF NOT EXISTS hr_remarks    VARCHAR(255) NULL AFTER hr_by,
    ADD COLUMN IF NOT EXISTS hr_at         DATETIME     NULL AFTER hr_remarks;

-- Rows decided under the old single-decision flow: record the decision on the
-- final (HR) stage and mark the earlier stages passed, so the new trail reads
-- correctly instead of showing a decided request as "awaiting Section Head".
UPDATE attendance_requests
   SET sec_status = 1, sec_at = reviewed_at, sec_remarks = 'Decided before staged approval',
       sup_status = 1, sup_at = reviewed_at, sup_remarks = 'Decided before staged approval',
       admin_status = 1, admin_at = reviewed_at, admin_remarks = 'Decided before staged approval',
       hr_status = 1, hr_by = reviewed_by, hr_at = reviewed_at, hr_remarks = reviewer_remarks
 WHERE status = 1 AND hr_status = 0;

UPDATE attendance_requests
   SET sec_status = 1, sec_at = reviewed_at, sec_remarks = 'Decided before staged approval',
       sup_status = 1, sup_at = reviewed_at, sup_remarks = 'Decided before staged approval',
       admin_status = 1, admin_at = reviewed_at, admin_remarks = 'Decided before staged approval',
       hr_status = 2, hr_by = reviewed_by, hr_at = reviewed_at, hr_remarks = reviewer_remarks
 WHERE status = 2 AND hr_status = 0;

-- Still-pending rows keep every stage at 0; run
-- migrations/2026_09_att_request_stages_backfill.php --apply once after the
-- PHP is deployed to auto-skip the optional stages nobody holds for them.
