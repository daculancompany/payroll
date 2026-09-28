-- Non-ATM (cash-paid) employees per payroll run — picked in Payroll Settings.
-- JSON array of employee ids. NULL = never set: the run falls back to the
-- employees in it with no bank account on file (payroll_non_atm_ids()).
-- Drives the NONATM block on the paysheet and the ATM / NON ATM split on the
-- Department Summary.
ALTER TABLE payroll ADD COLUMN non_atm TEXT NULL DEFAULT NULL;
