-- Staging area for planned (future) shift changes.
-- Drafts live here ONLY — never in employee_schedules — so employees cannot see a
-- planned change until an admin clicks "Apply All", which commits it to employee_schedules.
CREATE TABLE IF NOT EXISTS `schedule_plan` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `employee_id` INT(11) NOT NULL,
  `schedule_id` INT(11) NOT NULL,
  `effective_from` DATE NOT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 0,   -- 0 = pending (draft), 1 = applied, 2 = cancelled
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `applied_by` INT(11) DEFAULT NULL,
  `applied_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
