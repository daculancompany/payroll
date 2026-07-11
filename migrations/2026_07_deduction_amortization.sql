-- Make employee deductions optionally amortize like loans.
-- A deduction with total_amount = 0 stays a flat recurring deduction (unchanged).
-- Set total_amount > 0 and it behaves like a loan: `balance` decrements each
-- payroll at Lock, stops when paid (status = 1), and is tracked in deduction_history.
-- effective_date (already present) is the "first deduction date" start gate.

ALTER TABLE `employee_deductions`
  ADD COLUMN `total_amount` double NOT NULL DEFAULT 0 AFTER `amount`,
  ADD COLUMN `balance` double NOT NULL DEFAULT 0 AFTER `total_amount`,
  ADD COLUMN `status` int(1) NOT NULL DEFAULT 0 COMMENT '0 = active, 1 = fully paid' AFTER `balance`;

-- Mirror of loan_history, keyed to the employee_deductions row (ded_id).
CREATE TABLE IF NOT EXISTS `deduction_history` (
  `ded_his_id` int(20) NOT NULL AUTO_INCREMENT,
  `ded_id` int(20) NOT NULL,
  `amount` double NOT NULL,
  `current_bal` double NOT NULL,
  `new_bal` double NOT NULL,
  `payroll_id` int(20) NOT NULL,
  `employee_id` int(20) NOT NULL,
  PRIMARY KEY (`ded_his_id`),
  KEY `ded_id` (`ded_id`),
  KEY `payroll_id` (`payroll_id`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
