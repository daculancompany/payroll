-- Free-text note on an employee deduction: what the charge is for
-- (e.g. "CBC + Urinalysis", "Amoxicillin 500mg x2"). Optional.
ALTER TABLE `employee_deductions`
  ADD COLUMN `description` varchar(255) DEFAULT NULL AFTER `reference_no`;
