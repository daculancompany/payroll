-- ── Firebase Cloud Messaging browser tokens ─────────────────────────────────
-- One row per admin browser that granted notification permission. Used to
-- push "new attendance" alerts even when the browser tab is closed.
-- Tokens that FCM reports as UNREGISTERED are deleted by fcm.php on send.

CREATE TABLE IF NOT EXISTS fcm_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'users.id (staff) or employee.id — see recipient_type',
    recipient_type ENUM('user','employee') NOT NULL DEFAULT 'user',
    token VARCHAR(500) NOT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT current_timestamp(),
    last_seen DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    UNIQUE KEY uniq_token_type (token, recipient_type),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrade path for installs created before employee-portal push support.
-- One browser may hold BOTH a staff and an employee registration (someone who
-- logs into both), so uniqueness is per (token, recipient_type).
ALTER TABLE fcm_tokens
    ADD COLUMN IF NOT EXISTS recipient_type ENUM('user','employee') NOT NULL DEFAULT 'user' AFTER user_id;
ALTER TABLE fcm_tokens
    DROP INDEX IF EXISTS uniq_token,
    ADD UNIQUE KEY IF NOT EXISTS uniq_token_type (token, recipient_type);
