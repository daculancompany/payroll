-- HR Management, phase 1: the employee 201 file (hr_class.php).
--
-- Additive only and safe to re-run. Two NEW tables; `employee` is not touched.
--
-- ── WHY NEW TABLES INSTEAD OF ~40 COLUMNS ON `employee` ─────────────────────
-- `employee` is the payroll-critical table: it is read with `SELECT e.*` in
-- dozens of payroll, DTR and report queries. None of the columns below take
-- part in any computation — they are HR record-keeping — so they live in their
-- own tables, joined on employee_id. A deployment that skips this file
-- degrades (the readers check the table exists) instead of failing.
--
-- No foreign keys, like every other employee child table here
-- (employee_contributions, employee_deductions, employee_schedules).
--
-- Same columns as the 201 file in the HRIS / hris-next apps, plus `photo`.

-- ── 1:1 with employee. PK is employee_id, so saving is one
--    INSERT ... ON DUPLICATE KEY UPDATE and never needs a SELECT first.
--
--    Every column is NULL-able with no default. "Not answered yet" is the
--    normal state of a 201 file HR is still filling in, and it must be
--    distinguishable from an answered one. Blank form fields are stored as
--    NULL — never '' and never '0000-00-00'.
CREATE TABLE IF NOT EXISTS `employee_profile` (
    `employee_id`              INT(11)      NOT NULL,

    -- Profile picture: a generated FILENAME only (never a path or URL). The
    -- file sits in storage/hr/photos/, which the web server refuses to serve;
    -- every read goes through hr-file.php and its permission check.
    `photo`                    VARCHAR(120) NULL DEFAULT NULL,

    -- ── Personal ───────────────────────────────────────────────────────────
    -- Free-text VARCHAR rather than ENUM: adding a value to an ENUM needs an
    -- ALTER, and HR does meet unforeseen ones. The allowed values are enforced
    -- in PHP (HrManagement::profileFieldMap).
    `sex`                      VARCHAR(10)  NULL DEFAULT NULL,
    `civil_status`             VARCHAR(20)  NULL DEFAULT NULL,
    `blood_type`               VARCHAR(5)   NULL DEFAULT NULL,
    `nationality`              VARCHAR(50)  NULL DEFAULT NULL,
    `religion`                 VARCHAR(50)  NULL DEFAULT NULL,
    `birth_place`              VARCHAR(150) NULL DEFAULT NULL,
    `height_cm`                DECIMAL(5,2) NULL DEFAULT NULL,
    `weight_kg`                DECIMAL(5,2) NULL DEFAULT NULL,
    `allergies`                VARCHAR(255) NULL DEFAULT NULL,
    `medical_conditions`       VARCHAR(255) NULL DEFAULT NULL,

    -- ── Contact ────────────────────────────────────────────────────────────
    -- personal_email is NOT the portal login (that is
    -- employee_portal_accounts.username); this is where HR reaches the person.
    `mobile_no`                VARCHAR(30)  NULL DEFAULT NULL,
    `telephone_no`             VARCHAR(30)  NULL DEFAULT NULL,
    `personal_email`           VARCHAR(100) NULL DEFAULT NULL,
    `present_address`          VARCHAR(255) NULL DEFAULT NULL,
    `permanent_address`        VARCHAR(255) NULL DEFAULT NULL,

    -- ── Emergency contact ──────────────────────────────────────────────────
    `emergency_name`           VARCHAR(120) NULL DEFAULT NULL,
    `emergency_relation`       VARCHAR(50)  NULL DEFAULT NULL,
    `emergency_mobile`         VARCHAR(30)  NULL DEFAULT NULL,
    `emergency_address`        VARCHAR(255) NULL DEFAULT NULL,

    -- ── Employment ─────────────────────────────────────────────────────────
    `date_hired`               DATE         NULL DEFAULT NULL,
    `employment_status`        VARCHAR(30)  NULL DEFAULT NULL,
    `regularization_date`      DATE         NULL DEFAULT NULL,
    `date_separated`           DATE         NULL DEFAULT NULL,
    `separation_reason`        VARCHAR(255) NULL DEFAULT NULL,

    -- ── HMO / insurance ────────────────────────────────────────────────────
    `hmo_provider`             VARCHAR(100) NULL DEFAULT NULL,
    `hmo_card_no`              VARCHAR(50)  NULL DEFAULT NULL,
    `hmo_effective_date`       DATE         NULL DEFAULT NULL,
    `hmo_expiry_date`          DATE         NULL DEFAULT NULL,
    `life_insurance_provider`  VARCHAR(100) NULL DEFAULT NULL,
    `life_insurance_policy_no` VARCHAR(50)  NULL DEFAULT NULL,
    `insurance_beneficiary`    VARCHAR(150) NULL DEFAULT NULL,

    -- ── PRC / licence ──────────────────────────────────────────────────────
    `prc_license_no`           VARCHAR(50)  NULL DEFAULT NULL,
    `prc_profession`           VARCHAR(80)  NULL DEFAULT NULL,
    `prc_issued_date`          DATE         NULL DEFAULT NULL,
    `prc_expiry_date`          DATE         NULL DEFAULT NULL,

    -- ── Education ──────────────────────────────────────────────────────────
    `highest_education`        VARCHAR(60)  NULL DEFAULT NULL,
    `school_name`              VARCHAR(150) NULL DEFAULT NULL,
    `course`                   VARCHAR(150) NULL DEFAULT NULL,
    `year_graduated`           VARCHAR(9)   NULL DEFAULT NULL,

    `updated_at`               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    -- users.id of the Admin/HR who last saved it; NULL when the employee
    -- edited their own contact details from the portal.
    `updated_by`               INT(11)      NULL DEFAULT NULL,
    PRIMARY KEY (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A database that already has the table from the HRIS app lacks `photo`.
ALTER TABLE `employee_profile`
    ADD COLUMN IF NOT EXISTS `photo` VARCHAR(120) NULL DEFAULT NULL AFTER `employee_id`;

-- ── Dependants / beneficiaries: many per employee ───────────────────────────
CREATE TABLE IF NOT EXISTS `employee_dependents` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `employee_id`    INT(11)      NOT NULL,
    `name`           VARCHAR(120) NOT NULL,
    `relationship`   VARCHAR(40)  NULL DEFAULT NULL,
    `birthdate`      DATE         NULL DEFAULT NULL,
    `is_beneficiary` TINYINT(1)   NOT NULL DEFAULT 0,
    `remarks`        VARCHAR(255) NULL DEFAULT NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dependents_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
