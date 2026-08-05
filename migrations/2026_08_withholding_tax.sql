-- Withholding tax engine.
--
-- There was no tax computation anywhere in this codebase: payroll_items.tax was
-- a text box, and calculate_payroll() only ever preserved whatever an admin
-- typed. The BIR alphalist meanwhile DERIVED taxable compensation but reported
-- Tax Withheld as the sum of those typed values, so the two columns came from
-- unrelated sources and could never reconcile.
--
-- Brackets are versioned by effectivity date for the same reason the statutory
-- schedules are: editing a rate in place would restate every payroll already
-- computed under the old one.
--
-- config is a JSON array of bands, ascending:
--   [{"over":0,"base":0,"rate":0}, {"over":10417,"base":0,"rate":0.15}, ...]
-- tax = base + (taxable − over) × rate, for the highest band whose `over` the
-- taxable amount reaches.
CREATE TABLE IF NOT EXISTS tax_brackets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period VARCHAR(12) NOT NULL COMMENT 'semi_monthly | monthly | annual',
    effective_from DATE NOT NULL,
    config TEXT NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    date_created DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period_from (period, effective_from)
);

-- TRAIN Law (RA 10963) rates in force from 1 January 2023 onward.
INSERT IGNORE INTO tax_brackets (period, effective_from, config, notes) VALUES
('semi_monthly', '2023-01-01',
 '[{"over":0,"base":0,"rate":0},{"over":10417,"base":0,"rate":0.15},{"over":16667,"base":937.50,"rate":0.20},{"over":33333,"base":4270.70,"rate":0.25},{"over":83333,"base":16770.70,"rate":0.30},{"over":333333,"base":91770.70,"rate":0.35}]',
 'RA 10963 revised withholding table, semi-monthly, 2023 onward'),
('monthly', '2023-01-01',
 '[{"over":0,"base":0,"rate":0},{"over":20833,"base":0,"rate":0.15},{"over":33333,"base":1875,"rate":0.20},{"over":66667,"base":8541.80,"rate":0.25},{"over":166667,"base":33541.80,"rate":0.30},{"over":666667,"base":183541.80,"rate":0.35}]',
 'RA 10963 revised withholding table, monthly, 2023 onward'),
('annual', '2023-01-01',
 '[{"over":0,"base":0,"rate":0},{"over":250000,"base":0,"rate":0.15},{"over":400000,"base":22500,"rate":0.20},{"over":800000,"base":102500,"rate":0.25},{"over":2000000,"base":402500,"rate":0.30},{"over":8000000,"base":2202500,"rate":0.35}]',
 'RA 10963 annual table — used by the cumulative/annualised method');

-- Was this row's tax typed by a human, or computed?
--
-- calculate_payroll() snapshots and restores hand-entered fields across a
-- recalculation (restore_manual_payroll_fields). Without a flag, an auto-computed
-- tax and that restore would fight each other and nobody could tell which number
-- won. 0 = computed and free to be recomputed, 1 = an admin typed it and it must
-- survive recalculation untouched.
ALTER TABLE payroll_items
    ADD COLUMN tax_override TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = tax was hand-entered and must survive recalculation' AFTER tax;

-- The computed suggestion, always written even when it is not posted, so the
-- sheet can show what the schedule says beside what is actually being withheld.
ALTER TABLE payroll_items
    ADD COLUMN tax_computed DECIMAL(12,2) NOT NULL DEFAULT 0
    COMMENT 'withholding the schedule produces for this row' AFTER tax_override;

-- Taxable compensation this row was assessed on — frozen so a payslip or an
-- alphalist can be audited without re-deriving it from components that may have
-- since changed.
ALTER TABLE payroll_items
    ADD COLUMN taxable_income DECIMAL(12,2) NOT NULL DEFAULT 0
    COMMENT 'taxable compensation used for tax_computed' AFTER tax_computed;

-- Settings. Auto-post defaults OFF: turning a manual field into a computed one
-- changes what employees are paid, so it must be a deliberate act, not a
-- side effect of deploying this migration.
INSERT IGNORE INTO pay_settings (setting_key, setting_value, description) VALUES
    ('tax_auto_post', 0, 'Write computed withholding tax into payroll (1=yes, 0=suggest only)'),
    ('tax_method', 1, 'Withholding method: 1 = per-cutoff table, 2 = cumulative/annualised');
