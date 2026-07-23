-- Supervisor approval stage for leave requests.
-- New flow: Employee → Supervisor → Department Head → HR (final).
-- The Supervisor is a new user role (10), department-scoped (one per department).
-- These sup_* columns mirror the existing hr_* / admin_* stage columns and are
-- driven by LEAVE_APPROVAL_STAGES in db_connect.php.

ALTER TABLE leave_requests
    ADD COLUMN sup_status  TINYINT      NOT NULL DEFAULT 0 AFTER filed_by,
    ADD COLUMN sup_by      INT          NULL         AFTER sup_status,
    ADD COLUMN sup_remarks VARCHAR(255) NULL         AFTER sup_by,
    ADD COLUMN sup_at      DATETIME     NULL         AFTER sup_remarks;

-- Backfill: requests already decided under the old 2-stage flow predate the
-- Supervisor stage. Mark their Supervisor stage as approved so the timeline and
-- overall status stay coherent (the later HR/Head decision still stands).
UPDATE leave_requests
SET sup_status = 1,
    sup_at     = COALESCE(admin_at, hr_at, created_at)
WHERE sup_status = 0 AND status IN (1, 2);
