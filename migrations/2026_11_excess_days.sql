-- Excess Day(s) for monthly staff: days HR pays on top of the half-month
-- salary, typed on the Pay Computation Sheet and priced at the daily rate
-- (excess_days x per_day, added to gross). Replaces hand-computed
-- "Add earning" lines for the same purpose.
--
-- Additive only: every existing row stays 0. Run on live BEFORE the code.
ALTER TABLE payroll_items ADD COLUMN excess_days DECIMAL(6,3) NOT NULL DEFAULT 0;
