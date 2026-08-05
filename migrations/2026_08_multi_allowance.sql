-- Multi-allowance support.
--
-- `allowances` and `employee_allowances` already existed, with a management UI
-- (view_employee.php → manage_employee_allowances.php) — but the only code that
-- ever READ them was calculate_payrollOld(), the dead legacy function. The live
-- calculation used employee.allowance_rate: ONE flat number per employee, with
-- no name and no type. Recurring allowances therefore had nowhere to live and
-- ended up hand-keyed into the generic `adjustment` column every cutoff.
--
-- Two changes make the existing tables usable:

-- 1. Somewhere to freeze the resolved allowances onto the payroll item, so a
--    later change to an employee's allowances cannot restate a closed run —
--    the same reason contributions/deductions/loans are already frozen as JSON.
--    Shape: [{"allowance_id":3,"label":"Meal","amount":825.00,"type":2}, ...]
ALTER TABLE payroll_items
    ADD COLUMN allowances TEXT DEFAULT NULL
    COMMENT 'frozen per-run allowance breakdown (JSON)' AFTER allowance_days;

--    …plus a denormalised total of that JSON. The dashboards and print sheets
--    aggregate gross in SQL (sql_gross), and no portable expression sums a JSON
--    array across MySQL/MariaDB versions. PHP sums the breakdown; this column
--    mirrors it, written in the same statement. payroll_reconcile() compares the
--    two, so the mirror cannot drift from the JSON unnoticed.
ALTER TABLE payroll_items
    ADD COLUMN allowance_total DECIMAL(12,2) NOT NULL DEFAULT 0
    COMMENT 'sum of the allowances JSON, for SQL aggregates' AFTER allowances;

-- 2. An end date. employee_allowances had effective_from but no effective_to,
--    so stopping an allowance meant DELETing the row — which also erased the
--    history of it ever having been paid.
ALTER TABLE employee_allowances
    ADD COLUMN effective_to DATE DEFAULT NULL AFTER effective_date;

-- Taxability, needed once withholding is computed: a de minimis allowance (meal,
-- rice, uniform, laundry) is non-taxable up to its BIR ceiling, while a standing
-- job allowance is taxable compensation. Blended into one figure they cannot be
-- told apart, which is why the taxable base could not be derived before.
ALTER TABLE allowances
    ADD COLUMN is_taxable TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = taxable compensation, 0 = non-taxable de minimis';

ALTER TABLE allowances
    ADD COLUMN de_minimis_monthly_cap DECIMAL(10,2) DEFAULT NULL
    COMMENT 'BIR monthly ceiling for the non-taxable portion; NULL = no cap';
