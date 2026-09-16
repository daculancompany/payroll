-- Cancelling an APPROVED leave (HR / Administrator only).
-- The request is kept as status 3 = Cancelled instead of being deleted, so the
-- audit trail survives. Balances are derived (credits − pending/approved
-- durations), so status 3 drops out of every balance/payroll query and the
-- days return to the employee automatically; admin_class::cancel_leave_request
-- also writes a 'restore' row to leave_credit_history.
ALTER TABLE `leave_requests`
    MODIFY COLUMN `status` TINYINT(4) NOT NULL DEFAULT 0 COMMENT '0=Pending, 1=Approved, 2=Rejected, 3=Cancelled',
    ADD COLUMN `cancelled_by` INT(11) NULL DEFAULT NULL AFTER `remarks`,
    ADD COLUMN `cancelled_at` DATETIME NULL DEFAULT NULL AFTER `cancelled_by`,
    ADD COLUMN `cancel_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `cancelled_at`;
