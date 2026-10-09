-- Group several amortizing employee deductions (Hospital, Pharmacy, Lab…) under
-- ONE per-payroll amount. Payroll then takes at most `per_payroll` from the
-- group each run, paying its rows one at a time, oldest effective_date first.
-- Ungrouped rows (group_id NULL) keep their own per-period amount, unchanged.

CREATE TABLE IF NOT EXISTS `deduction_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `per_payroll` decimal(12,2) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`)
);

ALTER TABLE `employee_deductions`
  ADD COLUMN `group_id` int(11) DEFAULT NULL AFTER `status`,
  ADD KEY `group_id` (`group_id`);
