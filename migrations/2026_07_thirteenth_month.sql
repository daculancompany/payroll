-- 13th Month Pay (PD 851): one row per employee per coverage year.
-- basic_earned  = total BASIC salary actually paid across the year's payrolls
--                 (per-cutoff: daily → (present [+ paid_leave]) × per_day;
--                  monthly/fixed → (basic_pay − absent × per_day) / 2),
--                 components configurable in Pay Settings (th13_* keys).
-- amount        = basic_earned ÷ 12 (rounded per th13_round_to_peso)
-- override_amount / remarks = manual final amount (NULL = use amount)
-- status        = 0 draft (editable, regenerable) · 1 finalized (locked)
CREATE TABLE IF NOT EXISTS thirteenth_month (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    employee_id INT NOT NULL,
    basic_earned DOUBLE NOT NULL DEFAULT 0,
    cutoffs INT NOT NULL DEFAULT 0,
    unlocked_cutoffs INT NOT NULL DEFAULT 0,
    amount DOUBLE NOT NULL DEFAULT 0,
    override_amount DOUBLE DEFAULT NULL,
    remarks VARCHAR(255) DEFAULT NULL,
    status TINYINT NOT NULL DEFAULT 0,
    generated_by INT DEFAULT NULL,
    date_created DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_th13_year_emp (year, employee_id)
);

-- Config knobs (numeric flags — pay_settings.setting_value is a float column).
INSERT IGNORE INTO pay_settings (setting_key, setting_value, description) VALUES
    ('th13_include_paid_leave', 1, '13th month basis includes approved paid-leave days (1=yes, 0=no)'),
    ('th13_include_allowance',  0, '13th month basis includes allowances (1=yes, 0=strict basic — DOLE default)'),
    ('th13_round_to_peso',      0, 'Round 13th month pay to whole pesos (1=yes, 0=centavo-exact)');
