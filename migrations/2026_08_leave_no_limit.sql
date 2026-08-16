-- Per-leave-type "no balance limit" flag.
-- Filing this leave type is never blocked by insufficient/unset credits — it's
-- still tracked (deducted, shown in reports), just never a hard stop. Sick
-- Leave is flagged on by default: employees can file it even before HR has
-- set an explicit employee_leave_credits row for the year.
ALTER TABLE `leave_types`
    ADD COLUMN `no_limit` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_paid`;

UPDATE `leave_types` SET `no_limit` = 1 WHERE `name` = 'Sick Leave';
