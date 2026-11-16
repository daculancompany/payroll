-- Kiosk API for the UareUApp mobile kiosk (kiosk-api.php / kiosk_api_class.php).
--
-- Additive only: one nullable column on employee and three new tables. Nothing
-- existing is altered or dropped, and every statement is guarded, so the file
-- is safe to re-run. Works on MariaDB 10.4 (local XAMPP) and 11.8 (live).
--
-- Must be applied on LIVE before a kiosk is pointed at the live payroll.

-- ── Employee kiosk PIN ────────────────────────────────────────────────────
-- 6 digits, typed on the kiosk when face recognition fails. Stored as typed so
-- HR can tell the employee what it is (same as zweldo's users.pin_code). The
-- kiosk only ever receives sha256("<employee_id>:<pin>"), never the PIN.
-- UNIQUE because the kiosk treats a PIN shared by two people as unknown;
-- NULLs do not collide, so employees without a PIN are unaffected.
ALTER TABLE employee
    ADD COLUMN IF NOT EXISTS kiosk_pin VARCHAR(6) NULL DEFAULT NULL;
ALTER TABLE employee
    ADD UNIQUE INDEX IF NOT EXISTS uq_employee_kiosk_pin (kiosk_pin);

-- ── One row per punch the kiosk API received ──────────────────────────────
-- The punch itself lives where it always has (DTR_details.logs, written by
-- Action::save_biometric_attendance). This row is the kiosk's side of it:
--   * punch_ref   — the offline queue's idempotency key; a replay returns the
--                   cached `response` instead of punching twice
--   * approval    — 'pending' for a PIN punch until an admin reviews it on the
--                   PIN Attendance page; rejecting removes it from the DTR
--   * selfie      — path of the full-frame photo (also in biometric_kiosk_selfies)
CREATE TABLE IF NOT EXISTS kiosk_punches (
    id                INT(11)      NOT NULL AUTO_INCREMENT,
    employee_id       INT(11)      NOT NULL,
    punch_ref         VARCHAR(64)  NULL DEFAULT NULL,
    scan_time         DATETIME     NOT NULL,
    dtr_date          DATE         NULL DEFAULT NULL  COMMENT 'shift day the punch was filed under',
    dtr_detail_id     INT(11)      NULL DEFAULT NULL,
    punch             VARCHAR(12)  NOT NULL DEFAULT 'PENDING' COMMENT 'TIME_IN | TIME_OUT | NONE (duplicate) | PENDING (being recorded)',
    duplicate         TINYINT(1)   NOT NULL DEFAULT 0,
    entry_type        VARCHAR(10)  NOT NULL DEFAULT 'bio' COMMENT 'bio | manual | pin',
    approval          VARCHAR(10)  NULL DEFAULT NULL  COMMENT 'pending | approved | rejected (PIN punches only)',
    reviewed_by       INT(11)      NULL DEFAULT NULL,
    reviewed_at       DATETIME     NULL DEFAULT NULL,
    review_note       VARCHAR(255) NULL DEFAULT NULL,
    authorized_by     VARCHAR(80)  NULL DEFAULT NULL,
    site_id           INT(11)      NULL DEFAULT NULL,
    device_id         VARCHAR(64)  NULL DEFAULT NULL,
    selfie            VARCHAR(255) NULL DEFAULT NULL,
    latitude          DECIMAL(10,7) NULL DEFAULT NULL,
    longitude         DECIMAL(10,7) NULL DEFAULT NULL,
    location_accuracy DECIMAL(10,2) NULL DEFAULT NULL,
    location_mocked   TINYINT(1)   NULL DEFAULT NULL,
    offline           TINYINT(1)   NOT NULL DEFAULT 0,
    response          LONGTEXT     NULL DEFAULT NULL  COMMENT 'JSON answer sent to the kiosk, replayed for a repeated punch_ref',
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kiosk_punches_ref (punch_ref),
    KEY idx_kiosk_punches_emp_time (employee_id, scan_time),
    KEY idx_kiosk_punches_approval (approval)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Kiosk devices (heartbeat) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS kiosk_devices (
    id             INT(11)     NOT NULL AUTO_INCREMENT,
    device_id      VARCHAR(64) NOT NULL,
    name           VARCHAR(80) NULL DEFAULT NULL,
    site_id        INT(11)     NULL DEFAULT NULL,
    last_seen_at   DATETIME    NULL DEFAULT NULL,
    last_sync_at   DATETIME    NULL DEFAULT NULL,
    queue_depth    INT(11)     NOT NULL DEFAULT 0,
    rejected_depth INT(11)     NOT NULL DEFAULT 0,
    app_version    VARCHAR(32) NULL DEFAULT NULL,
    reader         VARCHAR(32) NULL DEFAULT NULL,
    camera         VARCHAR(32) NULL DEFAULT NULL,
    ip             VARCHAR(45) NULL DEFAULT NULL,
    created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kiosk_devices_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Recognition attempts (hit or miss) reported by the kiosk ──────────────
CREATE TABLE IF NOT EXISTS kiosk_recognition_events (
    id          INT(11)      NOT NULL AUTO_INCREMENT,
    device_id   VARCHAR(64)  NULL DEFAULT NULL,
    employee_id INT(11)      NULL DEFAULT NULL,
    method      VARCHAR(10)  NOT NULL COMMENT 'face | finger',
    outcome     VARCHAR(12)  NOT NULL COMMENT 'match | nomatch | ambiguous | quality | liveness',
    score       DECIMAL(8,4) NULL DEFAULT NULL,
    threshold   DECIMAL(8,4) NULL DEFAULT NULL,
    occurred_at DATETIME     NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_kiosk_recog_occurred (occurred_at),
    KEY idx_kiosk_recog_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
