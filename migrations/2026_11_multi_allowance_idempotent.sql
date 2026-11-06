-- Idempotent re-run of 2026_08_multi_allowance.sql.
--
-- The original uses plain ALTER TABLE ... ADD COLUMN, which ABORTS if the
-- column is already there, so it cannot safely be pointed at a server whose
-- state is unknown. This file adds the same columns with IF NOT EXISTS, so it
-- is a no-op on a server that already has them and completes the job on one
-- that does not. Nothing here drops or rewrites data.
--
-- Needs MariaDB 10.0+ (live is 11.8.x). Run the ORIGINAL file instead on a
-- server without IF NOT EXISTS support.
--
-- Why it matters: calculate_payroll()'s INSERT lists `allowances` and
-- `allowance_total` with no guard (admin_class.php), so a server missing them
-- cannot run payroll at all. The other three columns degrade gracefully.

-- Frozen per-run allowance breakdown, so a later edit to an employee's
-- allowances cannot restate a closed run.
-- Shape: [{"allowance_id":3,"label":"Meal","amount":825.00,"type":2}, ...]
ALTER TABLE payroll_items
    ADD COLUMN IF NOT EXISTS allowances TEXT DEFAULT NULL
    COMMENT 'frozen per-run allowance breakdown (JSON)' AFTER allowance_days;

-- Denormalised sum of that JSON, for the SQL aggregates that build gross.
-- payroll_reconcile() compares the two so the mirror cannot drift unnoticed.
ALTER TABLE payroll_items
    ADD COLUMN IF NOT EXISTS allowance_total DECIMAL(12,2) NOT NULL DEFAULT 0
    COMMENT 'sum of the allowances JSON, for SQL aggregates' AFTER allowances;

-- End date for a recurring allowance. Without it, stopping one meant DELETing
-- the row, which also erased the record that it was ever paid.
ALTER TABLE employee_allowances
    ADD COLUMN IF NOT EXISTS effective_to DATE DEFAULT NULL AFTER effective_date;

-- Taxability: a de minimis allowance (meal, rice, uniform, laundry) is
-- non-taxable up to its BIR ceiling; a standing job allowance is taxable.
ALTER TABLE allowances
    ADD COLUMN IF NOT EXISTS is_taxable TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = taxable compensation, 0 = non-taxable de minimis';

ALTER TABLE allowances
    ADD COLUMN IF NOT EXISTS de_minimis_monthly_cap DECIMAL(10,2) DEFAULT NULL
    COMMENT 'BIR monthly ceiling for the non-taxable portion; NULL = no cap';
