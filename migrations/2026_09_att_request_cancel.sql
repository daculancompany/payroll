-- Cancelling an APPROVED attendance request (HR / Administrator only).
-- Same shape as 2026_09_leave_cancel.sql: the request is kept as status 3 =
-- Cancelled for audit. Payroll reads approved OT / undertime requests live, so
-- status 3 drops out of pay on its own; admin_class::cancel_attendance_request
-- also rewinds whatever the approval wrote to DTR_details (incident punches,
-- parked / overwritten OT hours).
ALTER TABLE `attendance_requests`
    MODIFY COLUMN `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=Pending, 1=Approved, 2=Rejected, 3=Cancelled',
    ADD COLUMN `cancelled_by` INT(11) NULL DEFAULT NULL AFTER `reviewer_remarks`,
    ADD COLUMN `cancelled_at` DATETIME NULL DEFAULT NULL AFTER `cancelled_by`,
    ADD COLUMN `cancel_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `cancelled_at`;
