-- ---------------------------------------------------------------------------
-- Biometric kiosk (mobile app) support tables.
--
-- Additive only: nothing here alters an existing table. The desktop scanner's
-- `employee_fingerprints` rows are deliberately left untouched.
--
-- Run once:  mysql -u root payroll < migrations/2026_07_biometric_kiosk.sql
-- Safe to re-run — every statement is IF NOT EXISTS.
-- ---------------------------------------------------------------------------

-- Face profiles enrolled on the mobile kiosk.
--
-- The embedding is stored as a JSON array of floats in LONGTEXT rather than a
-- blob: it is small, stays readable, and survives a MySQL version that has no
-- JSON column type. Recognition happens on the device — this table exists so a
-- kiosk that is wiped or replaced can pull the enrolled faces back down.
--
-- `model` matters: embeddings produced by different networks are NOT
-- comparable, so a future model change must not silently match old vectors.
CREATE TABLE IF NOT EXISTS `biometric_kiosk_faces` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `employee_id` INT(11) NOT NULL,
  `model`       VARCHAR(64) NOT NULL DEFAULT 'facenet',
  `dimensions`  SMALLINT UNSIGNED NOT NULL,
  `embedding`   LONGTEXT NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- One row per employee per model: re-enrolling REPLACES the vector instead
  -- of leaving a stale face to match against.
  UNIQUE KEY `uniq_employee_model` (`employee_id`, `model`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Fingerprint templates captured by the MOBILE kiosk.
--
-- Kept in its own table on purpose. The mobile app matches with SourceAFIS
-- while the desktop scanner uses DigitalPersona, and neither SDK can read the
-- other's templates. Sharing `employee_fingerprints` would eventually hand the
-- desktop matcher a template it cannot parse.
CREATE TABLE IF NOT EXISTS `biometric_kiosk_templates` (
  `id`           INT(11) NOT NULL AUTO_INCREMENT,
  `employee_id`  INT(11) NOT NULL,
  `finger_index` VARCHAR(32) NOT NULL,
  `format`       VARCHAR(32) NOT NULL DEFAULT 'sourceafis',
  `template`     MEDIUMTEXT NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_employee_finger_format` (`employee_id`, `finger_index`, `format`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Full-frame attendance photos.
--
-- Only the FILENAME lives in the database; the image itself is written to
-- uploads/attendance/YYYY/MM/ so it is served straight from the web root.
--
-- A separate table rather than a column on `attendance`/`DTR_details`: the
-- kiosk may photograph a scan that the attendance logic then debounces away,
-- and those existing tables are not ours to widen.
CREATE TABLE IF NOT EXISTS `biometric_kiosk_selfies` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `employee_id`   INT(11) NOT NULL,
  `log_date`      DATE NOT NULL,
  `log_datetime`  DATETIME NOT NULL,
  -- Path relative to the app root, e.g. uploads/attendance/2026/07/xxx.jpg
  `filename`      VARCHAR(255) NOT NULL,
  `source`        VARCHAR(32) NOT NULL DEFAULT 'face',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_employee_date` (`employee_id`, `log_date`),
  KEY `idx_log_datetime` (`log_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
