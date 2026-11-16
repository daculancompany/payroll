-- Employee mode on the UareUApp — kiosk Phase D.
--
-- Until now the app only ran as a kiosk: one tablet, operated by a
-- timekeeper, punching other people in. Employee mode is the other half the
-- app already ships — a member of staff signs in as themselves on their own
-- phone and punches for themselves, and nobody else.
--
-- That difference is why this table exists. The kiosk's bearer token is a
-- single shared key (BIOMETRIC_API_KEY): fine for a managed tablet, useless
-- here, because an employee punch carries no employee_id — the token IS the
-- identity. One token per sign-in, per device, revocable on its own.
--
-- What is stored is a SHA-256 of the token, never the token. A stolen copy of
-- this table cannot be used to punch as anybody. The app gets the only
-- readable copy, once, at login.
--
-- Additive and re-runnable (MariaDB 10.4 local / 11.8 live).

CREATE TABLE IF NOT EXISTS employee_api_tokens (
    id           INT(11)      NOT NULL AUTO_INCREMENT,
    employee_id  INT(11)      NOT NULL,
    token_hash   CHAR(64)     NOT NULL COMMENT 'sha256 of the bearer token — the token itself is never stored',
    device_id    VARCHAR(64)  NULL DEFAULT NULL,
    device_label VARCHAR(80)  NULL DEFAULT NULL COMMENT 'what to call this phone when the employee revokes it',
    ip           VARCHAR(45)  NULL DEFAULT NULL,
    user_agent   VARCHAR(255) NULL DEFAULT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME     NULL DEFAULT NULL,
    expires_at   DATETIME     NULL DEFAULT NULL,
    revoked_at   DATETIME     NULL DEFAULT NULL,
    revoked_by   INT(11)      NULL DEFAULT NULL COMMENT 'users.id when an administrator cut it off',
    PRIMARY KEY (id),
    UNIQUE KEY uq_employee_api_tokens_hash (token_hash),
    KEY idx_employee_api_tokens_emp (employee_id, revoked_at),
    KEY idx_employee_api_tokens_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Who may punch from their own phone ────────────────────────────────────
-- Off for everyone until it is switched on, one employee at a time. A phone
-- punch is not the same promise as a kiosk punch — nobody watched it happen —
-- so it is a decision someone makes per person, not a default.
ALTER TABLE employee
    ADD COLUMN IF NOT EXISTS mobile_punch_enabled TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = may punch from their own phone with the employee app';
