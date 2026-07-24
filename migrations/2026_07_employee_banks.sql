-- Employee bank/payout details + master list of banks.
-- Used by the employee form (bank dropdown + account no) and the
-- Bank Payout report (per-payroll net pay list / CSV for bank upload).
CREATE TABLE IF NOT EXISTS banks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_name VARCHAR(100) NOT NULL UNIQUE,
    status TINYINT NOT NULL DEFAULT 1
);

INSERT IGNORE INTO banks (bank_name) VALUES
    ('BDO Unibank'),
    ('Bank of the Philippine Islands (BPI)'),
    ('Metrobank'),
    ('Land Bank of the Philippines'),
    ('Philippine National Bank (PNB)'),
    ('Security Bank'),
    ('UnionBank'),
    ('RCBC'),
    ('China Banking Corp (Chinabank)'),
    ('EastWest Bank'),
    ('Development Bank of the Philippines (DBP)'),
    ('PSBank'),
    ('GCash'),
    ('Maya'),
    ('Cash (no bank)');

ALTER TABLE employee
    ADD COLUMN bank_id INT DEFAULT NULL AFTER allowance_rate,
    ADD COLUMN bank_account_no VARCHAR(50) DEFAULT NULL AFTER bank_id;
