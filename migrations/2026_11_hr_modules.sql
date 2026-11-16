-- ============================================================================
-- HR modules: documents, movements, memos, announcements, trainings, assets,
-- offboarding/clearance and employee document requests (COE).
--
-- Builds on migrations/2026_11_hr_201_profile.sql (run that one first).
--
-- ADDITIVE ONLY: every statement creates a new table if it is missing. Nothing
-- here alters, fills or drops an existing table, so it is safe to run on the
-- live database and safe to run twice. No payroll query reads these tables.
--
-- MariaDB 10.4 (local XAMPP) and 11.8 (live) both accept this file as is.
-- ============================================================================

-- ── 201 documents ──────────────────────────────────────────────────────────
-- One row per file. The file itself sits in storage/hr/docs/ under a name WE
-- generate; it is only ever served through hr-file.php, which checks who is
-- asking. `source` says who put it there: HR, or the employee from the portal
-- (those wait for HR to verify them).
CREATE TABLE IF NOT EXISTS `hr_documents` (
    `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
    `employee_id`         INT(11)      NOT NULL,
    `category`            VARCHAR(40)  NOT NULL,
    `title`               VARCHAR(150) NOT NULL,
    `file_name`           VARCHAR(80)  NOT NULL,
    `original_name`       VARCHAR(190) NULL DEFAULT NULL,
    `mime`                VARCHAR(60)  NOT NULL,
    `size_bytes`          INT(11)      NOT NULL DEFAULT 0,
    `expiry_date`         DATE         NULL DEFAULT NULL,
    `notes`               VARCHAR(255) NULL DEFAULT NULL,
    `visible_to_employee` TINYINT(1)   NOT NULL DEFAULT 1,
    `source`              VARCHAR(10)  NOT NULL DEFAULT 'hr',
    `verified_at`         DATETIME     NULL DEFAULT NULL,
    `verified_by`         INT(11)      NULL DEFAULT NULL,
    `uploaded_by`         INT(11)      NULL DEFAULT NULL,
    `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hr_documents_emp` (`employee_id`),
    KEY `idx_hr_documents_expiry` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Employment history ─────────────────────────────────────────────────────
-- Promotions, transfers, pay changes and status changes, each with the date
-- it took effect. A RECORD, not a switch: writing a row here never changes
-- the employee's pay. From/to are kept as text so the history still reads
-- correctly after a department or position is renamed or deleted.
CREATE TABLE IF NOT EXISTS `hr_movements` (
    `id`              INT(11)       NOT NULL AUTO_INCREMENT,
    `employee_id`     INT(11)       NOT NULL,
    `movement_type`   VARCHAR(30)   NOT NULL,
    `effective_date`  DATE          NOT NULL,
    `from_position`   VARCHAR(120)  NULL DEFAULT NULL,
    `to_position`     VARCHAR(120)  NULL DEFAULT NULL,
    `from_department` VARCHAR(120)  NULL DEFAULT NULL,
    `to_department`   VARCHAR(120)  NULL DEFAULT NULL,
    `from_salary`     DECIMAL(12,2) NULL DEFAULT NULL,
    `to_salary`       DECIMAL(12,2) NULL DEFAULT NULL,
    `from_status`     VARCHAR(30)   NULL DEFAULT NULL,
    `to_status`       VARCHAR(30)   NULL DEFAULT NULL,
    `remarks`         VARCHAR(255)  NULL DEFAULT NULL,
    `recorded_by`     INT(11)       NULL DEFAULT NULL,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hr_movements_emp` (`employee_id`, `effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Memos and disciplinary records ─────────────────────────────────────────
-- Issued by HR, seen and acknowledged by the employee in the portal. A Notice
-- to Explain takes the employee's written explanation in `employee_response`.
CREATE TABLE IF NOT EXISTS `hr_memos` (
    `id`                INT(11)      NOT NULL AUTO_INCREMENT,
    `employee_id`       INT(11)      NOT NULL,
    `memo_type`         VARCHAR(30)  NOT NULL,
    `subject`           VARCHAR(150) NOT NULL,
    `body`              TEXT         NOT NULL,
    `issued_date`       DATE         NOT NULL,
    `respond_by`        DATE         NULL DEFAULT NULL,
    `requires_ack`      TINYINT(1)   NOT NULL DEFAULT 1,
    `acknowledged_at`   DATETIME     NULL DEFAULT NULL,
    `employee_response` TEXT         NULL DEFAULT NULL,
    `responded_at`      DATETIME     NULL DEFAULT NULL,
    `status`            VARCHAR(10)  NOT NULL DEFAULT 'open',
    `resolution`        VARCHAR(255) NULL DEFAULT NULL,
    `closed_at`         DATETIME     NULL DEFAULT NULL,
    `issued_by`         INT(11)      NULL DEFAULT NULL,
    `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hr_memos_emp` (`employee_id`),
    KEY `idx_hr_memos_ack` (`requires_ack`, `acknowledged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Announcements ──────────────────────────────────────────────────────────
-- Posted by HR, shown in the employee portal. department_id NULL = everyone.
CREATE TABLE IF NOT EXISTS `hr_announcements` (
    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(150) NOT NULL,
    `body`          TEXT         NOT NULL,
    `department_id` INT(11)      NULL DEFAULT NULL,
    `pinned`        TINYINT(1)   NOT NULL DEFAULT 0,
    `publish_at`    DATETIME     NOT NULL,
    `expires_at`    DATETIME     NULL DEFAULT NULL,
    `notified_at`   DATETIME     NULL DEFAULT NULL,
    `created_by`    INT(11)      NULL DEFAULT NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hr_ann_window` (`publish_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `hr_announcement_reads` (
    `announcement_id` INT(11)   NOT NULL,
    `employee_id`     INT(11)   NOT NULL,
    `read_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`announcement_id`, `employee_id`),
    KEY `idx_hr_ann_reads_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Trainings and certifications ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `hr_trainings` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `employee_id`    INT(11)      NOT NULL,
    `title`          VARCHAR(150) NOT NULL,
    `provider`       VARCHAR(150) NULL DEFAULT NULL,
    `start_date`     DATE         NULL DEFAULT NULL,
    `end_date`       DATE         NULL DEFAULT NULL,
    `hours`          DECIMAL(6,2) NULL DEFAULT NULL,
    `certificate_no` VARCHAR(80)  NULL DEFAULT NULL,
    `expiry_date`    DATE         NULL DEFAULT NULL,
    `remarks`        VARCHAR(255) NULL DEFAULT NULL,
    `recorded_by`    INT(11)      NULL DEFAULT NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hr_trainings_emp` (`employee_id`),
    KEY `idx_hr_trainings_expiry` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Company property issued to employees ───────────────────────────────────
-- returned_date NULL = still with the employee; offboarding lists these.
CREATE TABLE IF NOT EXISTS `hr_assets` (
    `id`               INT(11)      NOT NULL AUTO_INCREMENT,
    `employee_id`      INT(11)      NOT NULL,
    `item`             VARCHAR(120) NOT NULL,
    `category`         VARCHAR(40)  NULL DEFAULT NULL,
    `serial_no`        VARCHAR(80)  NULL DEFAULT NULL,
    `issued_date`      DATE         NOT NULL,
    `issued_condition` VARCHAR(20)  NULL DEFAULT NULL,
    `returned_date`    DATE         NULL DEFAULT NULL,
    `return_condition` VARCHAR(20)  NULL DEFAULT NULL,
    `remarks`          VARCHAR(255) NULL DEFAULT NULL,
    `issued_by`        INT(11)      NULL DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hr_assets_emp` (`employee_id`, `returned_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Offboarding / clearance ────────────────────────────────────────────────
-- One clearance per separation, with a checklist of sign-offs.
CREATE TABLE IF NOT EXISTS `hr_clearances` (
    `id`                INT(11)      NOT NULL AUTO_INCREMENT,
    `employee_id`       INT(11)      NOT NULL,
    `separation_type`   VARCHAR(30)  NOT NULL,
    `separation_date`   DATE         NOT NULL,
    `reason`            VARCHAR(255) NULL DEFAULT NULL,
    `status`            VARCHAR(12)  NOT NULL DEFAULT 'open',
    `final_pay_status`  VARCHAR(12)  NOT NULL DEFAULT 'pending',
    `final_pay_note`    VARCHAR(255) NULL DEFAULT NULL,
    `deactivated`       TINYINT(1)   NOT NULL DEFAULT 0,
    `started_by`        INT(11)      NULL DEFAULT NULL,
    `completed_by`      INT(11)      NULL DEFAULT NULL,
    `completed_at`      DATETIME     NULL DEFAULT NULL,
    `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hr_clearances_emp` (`employee_id`),
    KEY `idx_hr_clearances_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `hr_clearance_items` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `clearance_id` INT(11)      NOT NULL,
    `label`        VARCHAR(80)  NOT NULL,
    `sort_order`   INT(11)      NOT NULL DEFAULT 0,
    `cleared`      TINYINT(1)   NOT NULL DEFAULT 0,
    `remarks`      VARCHAR(255) NULL DEFAULT NULL,
    `cleared_by`   INT(11)      NULL DEFAULT NULL,
    `cleared_at`   DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_hr_clearance_items_cl` (`clearance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Document requests from employees (Certificate of Employment, …) ────────
-- The employee asks in the portal; HR releases (the PDF is generated on
-- demand by hr-certificate.php, never stored) or declines with a reason.
CREATE TABLE IF NOT EXISTS `hr_doc_requests` (
    `id`               INT(11)      NOT NULL AUTO_INCREMENT,
    `employee_id`      INT(11)      NOT NULL,
    `request_type`     VARCHAR(20)  NOT NULL,
    `purpose`          VARCHAR(190) NULL DEFAULT NULL,
    `status`           VARCHAR(12)  NOT NULL DEFAULT 'pending',
    `hr_remarks`       VARCHAR(255) NULL DEFAULT NULL,
    `signatory_name`   VARCHAR(120) NULL DEFAULT NULL,
    `signatory_title`  VARCHAR(120) NULL DEFAULT NULL,
    `released_at`      DATETIME     NULL DEFAULT NULL,
    `handled_by`       INT(11)      NULL DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hr_doc_requests_emp` (`employee_id`),
    KEY `idx_hr_doc_requests_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
