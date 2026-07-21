-- Per-employee rest days on the shift roster.
-- rest_days = CSV of weekday numbers, 0=Sunday … 6=Saturday (matches PHP date('w')).
-- Default '0' preserves today's "Sunday is the rest day" behavior for existing rows.
-- Lives on employee_schedules (the active assignment) AND schedule_plan (staged drafts)
-- so a planned rest-day change survives "Apply All".

ALTER TABLE `employee_schedules`
    ADD COLUMN `rest_days` VARCHAR(15) NOT NULL DEFAULT '0' AFTER `notes`;

ALTER TABLE `schedule_plan`
    ADD COLUMN `rest_days` VARCHAR(15) NOT NULL DEFAULT '0' AFTER `notes`;
