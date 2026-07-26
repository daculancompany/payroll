-- The employee form used to capture a single-letter middle initial (M.I.);
-- it now captures the full middle name. Old schemas declared the column as
-- VARCHAR(20) NOT NULL, which truncates longer middle names and forces an
-- empty string when the employee has none.
--
-- Widen it and allow NULL. This DB is already VARCHAR(225) NULL, so the
-- statement is a no-op here — it exists for environments still on the
-- original payroll.sql schema. Displays that want an initial derive it with
-- SUBSTR(middlename, 1, 1) instead of relying on the stored value.

ALTER TABLE `employee`
    MODIFY COLUMN `middlename` VARCHAR(225) NULL DEFAULT NULL;
