-- ── DTR employee-review feature migration ──────────────────────────────────

-- 1) Reuse the existing notifications table for employees too.
--    recipient_type distinguishes a users.id ('user') from an employee.id ('employee'),
--    since those two id sequences overlap.
ALTER TABLE notifications
    ADD COLUMN recipient_type ENUM('user','employee') NOT NULL DEFAULT 'user' AFTER user_id;

-- Index to keep the portal bell query fast.
ALTER TABLE notifications
    ADD INDEX idx_recipient (recipient_type, user_id, is_read);

-- 2) Per-employee sign-off on a DTR batch (whole-batch model).
--    status: 1 = confirmed/OK, 2 = disputed.
CREATE TABLE IF NOT EXISTS dtr_employee_reviews (
    id           INT(11) NOT NULL AUTO_INCREMENT,
    ddtr_id      INT(11) NOT NULL,
    employee_id  INT(11) NOT NULL,
    status       TINYINT(1) NOT NULL DEFAULT 1,
    comment      VARCHAR(255) DEFAULT NULL,
    reviewed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_dtr_emp (ddtr_id, employee_id),
    KEY idx_ddtr (ddtr_id),
    KEY idx_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
