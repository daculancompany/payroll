-- Pre-lock sanity check: net-swing threshold (percent) used to flag employees
-- whose net pay moved more than this vs. the previous payroll period.
INSERT IGNORE INTO pay_settings (setting_key, setting_value, description) VALUES
    ('sanity_net_swing_pct', 30, 'Pre-lock check: flag net pay changes above this percent vs previous period');
