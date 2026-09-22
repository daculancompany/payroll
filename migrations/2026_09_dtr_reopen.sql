-- Final approval (DTR.status = 2) can now be undone by Admin/HR via "Reopen"
-- (reopen_dtr in admin_class.php), as long as no locked or in-review payroll
-- covers the batch. Who reopened it last, and when, is kept on the batch.
ALTER TABLE DTR
    ADD COLUMN IF NOT EXISTS reopened_by INT NULL DEFAULT NULL AFTER approved_by,
    ADD COLUMN IF NOT EXISTS reopened_at DATETIME NULL DEFAULT NULL AFTER reopened_by;
