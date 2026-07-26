-- ── Named one-off deductions / allowances for a single employee ────────────
--
-- Before this, a per-employee correction had exactly two slots: the Adjustment
-- (+/−, one remark) and Other Deduction (an amount with no label at all, and
-- hidden entirely on monthly batches). Two corrections for the same employee
-- had to be netted into one figure with both reasons crammed into one line.
--
-- An "extra" is a free line item attached to ONE payroll_items row: a label, an
-- amount, and whether it adds (allowance) or subtracts (deduction). Unlimited
-- per employee, applied without recalculating the batch — which matters because
-- recalculation rebuilds payroll_items and would discard manual corrections.
--
-- kind: 1 = deduction (reduces net), 2 = allowance (adds to gross).

CREATE TABLE IF NOT EXISTS payroll_item_extras (
    id              INT(11)      NOT NULL AUTO_INCREMENT,
    payroll_item_id INT(11)      NOT NULL,
    payroll_id      INT(11)      NOT NULL,   -- denormalised: batch totals and the
    employee_id     INT(11)      NOT NULL,   -- remittance read by payroll, not item
    kind            TINYINT(1)   NOT NULL DEFAULT 1,
    label           VARCHAR(120) NOT NULL,
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_by      INT(11)      DEFAULT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_item (payroll_item_id),
    KEY idx_payroll (payroll_id),
    KEY idx_emp (payroll_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
