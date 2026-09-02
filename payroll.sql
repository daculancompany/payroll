-- Payroll — baseline database
--
-- Full structure for every table the application uses, plus seed rows for the
-- lookup/config tables only. No employee, DTR, payroll, attendance, leave or
-- notification data is included, and no user accounts.
--
-- Regenerate after a schema change:
--   mysqldump -u root --no-data --skip-add-drop-table payroll
--   mysqldump -u root --no-create-info --complete-insert --skip-extended-insert \
--       payroll banks clasification clusters contributions contribution_loan_types \
--       deductions department employers leave_types loans pay_settings position \
--       sites work_schedules
--
-- Import into an EMPTY database — there are deliberately no DROP TABLE
-- statements, so this can never wipe an existing installation.
--
--   mysql -u root -e "CREATE DATABASE payroll CHARACTER SET utf8mb4"
--   mysql -u root payroll < payroll.sql
--
-- Then create the first admin account (bcrypt hash, cost 10):
--   INSERT INTO users (name, employer_id, username, password, role, status)
--   VALUES ('Admin', 1, 'admin', '<bcrypt-hash>', 1, 1);
--
-- Table names are case-sensitive on Linux: `DTR` and `DTR_details` are
-- uppercase and the code depends on that spelling.
--
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `DTR`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `DTR` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `local_id` int(11) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL,
  `timekeeper_id` int(11) NOT NULL,
  `employer_id` int(11) NOT NULL,
  `date_from` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_to` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_id` int(11) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `file` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` int(1) NOT NULL DEFAULT 1 COMMENT '1=pending;2=approved',
  `ptype` int(1) NOT NULL DEFAULT 0,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `site_id` (`site_id`),
  KEY `timekeeper_id` (`timekeeper_id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `employer_id` (`employer_id`),
  CONSTRAINT `dtr_ibfk_1` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `dtr_ibfk_2` FOREIGN KEY (`timekeeper_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `dtr_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `dtr_ibfk_5` FOREIGN KEY (`employer_id`) REFERENCES `employers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `DTR_details`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `DTR_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ddtr_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `date_time` date NOT NULL,
  `work_hours` float NOT NULL DEFAULT 0,
  `overtime` double DEFAULT 0,
  `undertime` double NOT NULL DEFAULT 0,
  `late` double NOT NULL DEFAULT 0,
  `logs` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attendance_type` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `day_type` enum('regular','legal_holiday','special_holiday') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'regular',
  `nsd_hours` float NOT NULL DEFAULT 0,
  `is_complete` tinyint(1) NOT NULL DEFAULT 0,
  `notes` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` int(1) DEFAULT 0 COMMENT '0=pending; 1= approive',
  `decision_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ddtr_id` (`ddtr_id`),
  KEY `dtr_details_ibfk_2` (`employee_id`),
  KEY `idx_emp_date` (`employee_id`,`date_time`),
  CONSTRAINT `dtr_details_ibfk_1` FOREIGN KEY (`ddtr_id`) REFERENCES `DTR` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `dtr_details_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `allowances`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `allowances` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `allowance` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attendance`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(20) NOT NULL,
  `log_type` tinyint(1) NOT NULL COMMENT '1 = AM IN,2 = AM out, 3= PM IN, 4= PM out\r\n',
  `datetime_log` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `type` int(1) NOT NULL DEFAULT 1 COMMENT '1=manual;2=auto',
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `payroll` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attendance_requests`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `request_type` enum('incident','overtime') NOT NULL,
  `request_date` date NOT NULL,
  `reason` varchar(50) NOT NULL,
  `claimed_time_in` time DEFAULT NULL,
  `claimed_time_out` time DEFAULT NULL,
  `ot_hours_requested` float DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewer_remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `attendance_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `attendance_requests_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `banks`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `banks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(100) NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bank_name` (`bank_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `biometric_kiosk_faces`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `biometric_kiosk_faces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `model` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'facenet',
  `face_index` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'face-1',
  `dimensions` smallint(5) unsigned NOT NULL,
  `embedding` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `photo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_employee_model_face` (`employee_id`,`model`,`face_index`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `biometric_kiosk_selfies`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `biometric_kiosk_selfies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `log_date` date NOT NULL,
  `log_datetime` datetime NOT NULL,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'face',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_employee_date` (`employee_id`,`log_date`),
  KEY `idx_log_datetime` (`log_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `biometric_kiosk_templates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `biometric_kiosk_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `finger_index` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `format` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sourceafis',
  `template` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `quality` smallint(6) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_employee_finger_format` (`employee_id`,`finger_index`,`format`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_events`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendar_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `type` tinyint(4) NOT NULL DEFAULT 1,
  `blocks_leave` tinyint(4) NOT NULL DEFAULT 1,
  `color` varchar(20) DEFAULT '#dc3545',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dates` (`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clasification`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clasification` (
  `id` int(20) NOT NULL AUTO_INCREMENT,
  `clasification` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clusters`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clusters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cluster` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contribution_loan_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contribution_loan_types` (
  `clt_id` int(20) NOT NULL AUTO_INCREMENT,
  `loan_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`clt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contributions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contributions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contribution` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `deduction_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deduction_history` (
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `deductions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deductions` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `deduction` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `department`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `department` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dtr_admin_notes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dtr_admin_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ddtr_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `level` enum('info','good','watch','critical') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `note` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_batch_emp` (`ddtr_id`,`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dtr_employee_reviews`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dtr_employee_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ddtr_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `comment` varchar(255) DEFAULT NULL,
  `reviewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `admin_reply` varchar(255) DEFAULT NULL,
  `seen_at` datetime DEFAULT NULL,
  `seen_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dtr_emp` (`ddtr_id`,`employee_id`),
  KEY `idx_ddtr` (`ddtr_id`),
  KEY `idx_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dtr_messages`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dtr_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dtr_detail_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `date_time` date NOT NULL,
  `message` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `sender_type` enum('admin','employee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_detail` (`dtr_detail_id`),
  KEY `idx_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee` (
  `id` int(20) NOT NULL AUTO_INCREMENT,
  `clasification_id` int(20) NOT NULL DEFAULT 1,
  `employee_no` varchar(100) NOT NULL,
  `employee_code` varchar(30) DEFAULT NULL,
  `firstname` varchar(50) NOT NULL,
  `middlename` varchar(225) DEFAULT NULL,
  `ext` varchar(5) DEFAULT NULL,
  `lastname` varchar(50) NOT NULL,
  `department_id` int(30) DEFAULT NULL,
  `position_id` int(30) NOT NULL DEFAULT 1,
  `ot_rate` double NOT NULL,
  `salary` double NOT NULL,
  `basic_pay` double NOT NULL,
  `rate_type` varchar(10) NOT NULL DEFAULT 'daily',
  `time_in` varchar(10) DEFAULT NULL,
  `time_out` varchar(10) DEFAULT NULL,
  `loan` double NOT NULL DEFAULT 0,
  `loan_deduction` double NOT NULL DEFAULT 0,
  `sss_no` varchar(100) DEFAULT NULL,
  `ph_no` varchar(100) DEFAULT NULL,
  `hdmf_no` varchar(100) DEFAULT NULL,
  `tin_no` varchar(100) DEFAULT NULL,
  `loan_id` int(20) DEFAULT NULL,
  `isAutoDeduct` int(1) NOT NULL DEFAULT 0 COMMENT '0=no;1=yes	',
  `weekly_payroll` int(1) NOT NULL DEFAULT 1,
  `status` int(1) NOT NULL DEFAULT 1 COMMENT '0=inactive;1=active',
  `sss_fund` double DEFAULT 0,
  `allowance_rate` double NOT NULL DEFAULT 0,
  `bank_id` int(11) DEFAULT NULL,
  `bank_account_no` varchar(50) DEFAULT NULL,
  `bday` varchar(50) DEFAULT NULL,
  `portal_username` varchar(100) DEFAULT NULL,
  `portal_password` varchar(255) DEFAULT NULL,
  `leave_override` tinyint(4) DEFAULT NULL COMMENT 'NULL=auto(classification), 1=force allow, 0=force block',
  PRIMARY KEY (`id`),
  UNIQUE KEY `portal_username` (`portal_username`),
  KEY `loan_id` (`loan_id`),
  KEY `clasification_id` (`clasification_id`),
  CONSTRAINT `employee_ibfk_1` FOREIGN KEY (`clasification_id`) REFERENCES `clasification` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_allowances`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_allowances` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `employee_id` int(30) NOT NULL,
  `allowance_id` int(30) NOT NULL,
  `type` tinyint(1) NOT NULL COMMENT '1 = Monthly, 2= Semi-Montly, 3 = once',
  `amount` float NOT NULL,
  `effective_date` date NOT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `employee_allowances_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_bio`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_bio` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `device_id` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_id` int(11) NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `employee_bio_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_contributions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_contributions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(30) NOT NULL,
  `contribution_id` int(11) NOT NULL,
  `amount` float NOT NULL,
  `payroll_type` int(1) NOT NULL DEFAULT 2,
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `employee_contributions_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_deductions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_deductions` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `employee_id` int(30) NOT NULL,
  `deduction_id` int(30) NOT NULL,
  `type` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = Monthly, 2= Semi-Montly, 3 = once',
  `amount` float NOT NULL,
  `total_amount` double NOT NULL DEFAULT 0,
  `balance` double NOT NULL DEFAULT 0,
  `status` int(1) NOT NULL DEFAULT 0 COMMENT '0 = active, 1 = fully paid',
  `effective_date` date DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `employee_deductions_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_fingerprints`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_fingerprints` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `finger_index` varchar(20) NOT NULL,
  `template` mediumtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_finger` (`employee_id`,`finger_index`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_leave_credits`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_leave_credits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `year` int(11) NOT NULL DEFAULT 0,
  `credits` decimal(6,1) NOT NULL DEFAULT 0.0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_type_year` (`employee_id`,`leave_type_id`,`year`),
  KEY `idx_emp` (`employee_id`),
  KEY `fk_elc_type` (`leave_type_id`),
  CONSTRAINT `fk_elc_employee` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_elc_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_portal_accounts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_portal_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `must_change` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `employee_portal_accounts_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_schedules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `rest_days` varchar(15) NOT NULL DEFAULT '0',
  `changed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `schedule_id` (`schedule_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `employee_schedules_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `employee_schedules_ibfk_2` FOREIGN KEY (`schedule_id`) REFERENCES `work_schedules` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `employee_schedules_ibfk_3` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employer_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employee_address` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fcm_tokens`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fcm_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'users.id of the admin who registered this browser',
  `recipient_type` enum('user','employee') NOT NULL DEFAULT 'user',
  `token` varchar(500) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_type` (`token`,`recipient_type`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_credit_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_credit_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `old_credits` decimal(6,1) NOT NULL DEFAULT 0.0,
  `new_credits` decimal(6,1) NOT NULL DEFAULT 0.0,
  `change_type` varchar(10) NOT NULL DEFAULT 'set',
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emp` (`employee_id`),
  KEY `fk_lch_type` (`leave_type_id`),
  CONSTRAINT `fk_lch_employee` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lch_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_requests`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `date_applied` date NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `duration` decimal(5,1) NOT NULL DEFAULT 0.0,
  `is_half_day` tinyint(1) NOT NULL DEFAULT 0,
  `half_period` enum('AM','PM') DEFAULT NULL,
  `half_date` date DEFAULT NULL,
  `dates` text DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `filed_by` int(11) DEFAULT NULL,
  `sup_status` tinyint(4) NOT NULL DEFAULT 0,
  `sup_by` int(11) DEFAULT NULL,
  `sup_remarks` varchar(255) DEFAULT NULL,
  `sup_at` datetime DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT 0,
  `hr_status` tinyint(4) NOT NULL DEFAULT 0,
  `hr_by` int(11) DEFAULT NULL,
  `hr_remarks` varchar(255) DEFAULT NULL,
  `hr_at` datetime DEFAULT NULL,
  `admin_status` tinyint(4) NOT NULL DEFAULT 0,
  `admin_by` int(11) DEFAULT NULL,
  `admin_remarks` varchar(255) DEFAULT NULL,
  `admin_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_leave_type` (`leave_type_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_leave_employee` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_leave_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `days_allowed` int(11) NOT NULL DEFAULT 0,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `description` varchar(255) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `carryover` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=reset to days_allowed each year, 1=carry unused credits into next year',
  `carryover_cap` decimal(6,1) DEFAULT NULL COMMENT 'max days carried over when carryover=1; NULL = no cap',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_history` (
  `loan_his_id` int(20) NOT NULL AUTO_INCREMENT,
  `loan_id` int(20) NOT NULL,
  `amount` double NOT NULL,
  `current_bal` double NOT NULL,
  `new_bal` double NOT NULL,
  `payroll_id` int(20) NOT NULL,
  `employee_id` int(20) NOT NULL,
  PRIMARY KEY (`loan_his_id`),
  KEY `loan_id` (`loan_id`),
  KEY `payroll_id` (`payroll_id`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loans`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loans` (
  `loan_id` int(20) NOT NULL AUTO_INCREMENT,
  `employee_id` int(20) NOT NULL,
  `loan_type` int(20) NOT NULL,
  `loan_date` date NOT NULL,
  `effective_date` date DEFAULT NULL,
  `loan_amount` double NOT NULL,
  `loan_balance` double NOT NULL,
  `loan_status` int(1) NOT NULL DEFAULT 0,
  `damount` double NOT NULL,
  PRIMARY KEY (`loan_id`),
  KEY `employee_id` (`employee_id`),
  KEY `loan_type` (`loan_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `login_attempts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `recipient_type` enum('user','employee') NOT NULL DEFAULT 'user',
  `title` varchar(150) NOT NULL,
  `message` varchar(500) DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'ri-notification-3-line',
  `color` varchar(20) DEFAULT 'primary',
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `idx_recipient` (`recipient_type`,`user_id`,`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pay_settings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pay_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` float NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `pay_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `employer_id` int(11) NOT NULL,
  `ref_no` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `type` tinyint(1) NOT NULL COMMENT '1 = monthly ,2 semi-monthly',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 =New,1 = computed, 2:lock\r\n',
  `deferential` int(1) NOT NULL DEFAULT 1,
  `site_ids` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` int(1) NOT NULL COMMENT '1=site; 2; cluster',
  `settings` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_by_role` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_by_role` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by_role` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p2` enum('no','yes') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'no',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employer_id` (`employer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_employee_reviews`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_employee_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `comment` varchar(255) DEFAULT NULL,
  `reviewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `admin_reply` varchar(255) DEFAULT NULL,
  `seen_at` datetime DEFAULT NULL,
  `seen_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_emp` (`payroll_id`,`employee_id`),
  KEY `idx_payroll` (`payroll_id`),
  KEY `idx_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_item_extras`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_item_extras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_item_id` int(11) NOT NULL,
  `payroll_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `kind` tinyint(1) NOT NULL DEFAULT 1,
  `label` varchar(120) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item` (`payroll_item_id`),
  KEY `idx_payroll` (`payroll_id`),
  KEY `idx_emp` (`payroll_id`,`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_items` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `payroll_id` int(30) NOT NULL,
  `employee_id` int(30) NOT NULL,
  `site_id` int(11) NOT NULL,
  `present` double NOT NULL DEFAULT 0,
  `absent` int(10) NOT NULL DEFAULT 0,
  `paid_leave` decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT 'approved paid-leave day-fractions counted into this run',
  `under_time` double NOT NULL DEFAULT 0,
  `late` double NOT NULL DEFAULT 0,
  `ot_rate` double NOT NULL DEFAULT 0,
  `per_day` double NOT NULL DEFAULT 0,
  `ot_amount` int(11) NOT NULL DEFAULT 0,
  `ot` double NOT NULL DEFAULT 0,
  `salary` double NOT NULL DEFAULT 0,
  `per_minute` double NOT NULL,
  `allowance_amount` double NOT NULL DEFAULT 0,
  `allowance_days` int(3) NOT NULL DEFAULT 0,
  `deduction_amount` double NOT NULL DEFAULT 0,
  `tax` double NOT NULL DEFAULT 0,
  `other_deduction` double NOT NULL DEFAULT 0,
  `adjustment` double NOT NULL DEFAULT 0,
  `adjustment_remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contribute_amount` double NOT NULL DEFAULT 0,
  `deductions` text COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `contributions` text COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `loans` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refunds` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `time_logs` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_hours` double NOT NULL DEFAULT 0,
  `sss_fund` double DEFAULT 0,
  `basic_pay` double NOT NULL DEFAULT 0,
  `rate_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'daily',
  `legal_holiday` int(2) NOT NULL DEFAULT 0,
  `sunday_duty` int(2) NOT NULL DEFAULT 0,
  `special_holiday` int(2) NOT NULL DEFAULT 0,
  `jei_advances` double NOT NULL DEFAULT 0,
  `jcc_advances` double NOT NULL DEFAULT 0,
  `net` double NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `review_status` int(1) NOT NULL DEFAULT 0 COMMENT '0=none;1=ok(green);2=issue(orange);3=reviewing(blue)',
  `review_comment` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `review_sent_count` int(11) NOT NULL DEFAULT 0,
  `review_sent_at` datetime DEFAULT NULL,
  `unlocked_at` datetime DEFAULT NULL,
  `unlocked_by` int(11) DEFAULT NULL,
  `unlocked_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_id` (`payroll_id`),
  KEY `employee_id` (`employee_id`),
  KEY `site_id` (`site_id`),
  KEY `idx_unlocked` (`payroll_id`,`unlocked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_logs` (
  `log_id` int(20) NOT NULL AUTO_INCREMENT,
  `payroll_id` int(20) NOT NULL,
  `user_id` int(20) NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `payroll_id` (`payroll_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `position`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `position` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `department_id` int(30) DEFAULT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `refunds`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `refunds` (
  `id` int(20) NOT NULL AUTO_INCREMENT,
  `refunds` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `schedule_plan`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schedule_plan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `effective_from` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `rest_days` varchar(15) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `applied_by` int(11) DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sites`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employer_id` int(11) NOT NULL,
  `site_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cluster_id` int(11) NOT NULL,
  `timekeeper_id` int(11) DEFAULT NULL,
  `pic` int(30) DEFAULT NULL,
  `site_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` int(1) NOT NULL DEFAULT 1 COMMENT '	0=inactive;1=active	',
  PRIMARY KEY (`id`),
  KEY `manager_id` (`cluster_id`),
  KEY `timekeeper_id` (`timekeeper_id`),
  KEY `pic` (`pic`),
  KEY `employer_id` (`employer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `thirteenth_month`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `thirteenth_month` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `basic_earned` double NOT NULL DEFAULT 0,
  `cutoffs` int(11) NOT NULL DEFAULT 0,
  `unlocked_cutoffs` int(11) NOT NULL DEFAULT 0,
  `amount` double NOT NULL DEFAULT 0,
  `override_amount` double DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT 0,
  `generated_by` int(11) DEFAULT NULL,
  `date_created` datetime DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_th13_year_emp` (`year`,`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `time_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `time_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `total_hours` double NOT NULL,
  `memo` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(30) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `employer_id` int(1) NOT NULL DEFAULT 1,
  `address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `role` tinyint(1) NOT NULL DEFAULT 2 COMMENT '1=admin , 2 = staff,  3 = auditor, 4= manager',
  `status` int(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `site_id` (`site_id`),
  KEY `employer_id` (`employer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `visitors_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `visitors_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` int(11) NOT NULL,
  `image` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_visited` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `site_id` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `work_schedules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `work_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` varchar(100) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `total_hours` float NOT NULL DEFAULT 8,
  `break_minutes` int(11) NOT NULL DEFAULT 60,
  `is_graveyard` tinyint(1) NOT NULL DEFAULT 0,
  `has_nsd` tinyint(1) NOT NULL DEFAULT 0,
  `nsd_rate` float NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `description` (`description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `banks`
--

INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (1,'BDO Unibank',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (2,'Bank of the Philippine Islands (BPI)',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (3,'Metrobank',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (4,'Land Bank of the Philippines',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (5,'Philippine National Bank (PNB)',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (6,'Security Bank',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (7,'UnionBank',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (8,'RCBC',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (9,'China Banking Corp (Chinabank)',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (10,'EastWest Bank',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (11,'Development Bank of the Philippines (DBP)',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (12,'PSBank',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (13,'GCash',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (14,'Maya',1);
INSERT INTO `banks` (`id`, `bank_name`, `status`) VALUES (15,'Cash (no bank)',1);

--
-- Dumping data for table `clasification`
--

INSERT INTO `clasification` (`id`, `clasification`) VALUES (1,'Regular');
INSERT INTO `clasification` (`id`, `clasification`) VALUES (2,'Temporary');
INSERT INTO `clasification` (`id`, `clasification`) VALUES (3,'Interm');
INSERT INTO `clasification` (`id`, `clasification`) VALUES (4,'Contractual');
INSERT INTO `clasification` (`id`, `clasification`) VALUES (5,'Probationary');
INSERT INTO `clasification` (`id`, `clasification`) VALUES (6,'Executive');

--
-- Dumping data for table `clusters`
--

INSERT INTO `clusters` (`id`, `cluster`) VALUES (1,'CDO');

--
-- Dumping data for table `contributions`
--

INSERT INTO `contributions` (`id`, `contribution`) VALUES (1,'SSS');
INSERT INTO `contributions` (`id`, `contribution`) VALUES (2,'PHIC');
INSERT INTO `contributions` (`id`, `contribution`) VALUES (3,'HDMF');

--
-- Dumping data for table `contribution_loan_types`
--

INSERT INTO `contribution_loan_types` (`clt_id`, `loan_type`) VALUES (1,'SSS S-LOAN');
INSERT INTO `contribution_loan_types` (`clt_id`, `loan_type`) VALUES (2,'HDMF MPL-LOAN');

--
-- Dumping data for table `deductions`
--

INSERT INTO `deductions` (`id`, `deduction`, `description`) VALUES (1,'CASHBOND','');
INSERT INTO `deductions` (`id`, `deduction`, `description`) VALUES (2,'PPE','');
INSERT INTO `deductions` (`id`, `deduction`, `description`) VALUES (3,'PENALTY','');
INSERT INTO `deductions` (`id`, `deduction`, `description`) VALUES (4,'CASH ADVANCE','');

--
-- Dumping data for table `department`
--

INSERT INTO `department` (`id`, `name`) VALUES (1,'ADMINISTRATION/FINANCE/HR/BILLING/PHILHEALTH/RECORDS');
INSERT INTO `department` (`id`, `name`) VALUES (2,'CENTRAL SUPPLY ROOM');
INSERT INTO `department` (`id`, `name`) VALUES (3,'DIETARY');
INSERT INTO `department` (`id`, `name`) VALUES (4,'EYE CENTER');
INSERT INTO `department` (`id`, `name`) VALUES (5,'HOUSEKEEPING/LINEN');
INSERT INTO `department` (`id`, `name`) VALUES (6,'LABORATORY/XRAY');
INSERT INTO `department` (`id`, `name`) VALUES (7,'MAINTENANCE');
INSERT INTO `department` (`id`, `name`) VALUES (8,'RADIOLOGY/MRI/XTRAY');
INSERT INTO `department` (`id`, `name`) VALUES (10,'NURSING SERVICE/RES.PHY');
INSERT INTO `department` (`id`, `name`) VALUES (11,'CARDIOVASCULAR LABORATORY');
INSERT INTO `department` (`id`, `name`) VALUES (12,'DELIVERY ROOM/NICU');
INSERT INTO `department` (`id`, `name`) VALUES (13,'EMERGENCY/OUT PATIENT');
INSERT INTO `department` (`id`, `name`) VALUES (14,'HEMODIALYSIS/HDU TECHNICIAN');
INSERT INTO `department` (`id`, `name`) VALUES (15,'INTENSIVE CARE UNIT');
INSERT INTO `department` (`id`, `name`) VALUES (16,'NUCLEAR MEDICINE');
INSERT INTO `department` (`id`, `name`) VALUES (17,'ONCOLOGY');
INSERT INTO `department` (`id`, `name`) VALUES (18,'OPERATING ROOM');
INSERT INTO `department` (`id`, `name`) VALUES (19,'PHARMACY');
INSERT INTO `department` (`id`, `name`) VALUES (20,'RESIDENT PHYSICIAN');

--
-- Dumping data for table `employers`
--

INSERT INTO `employers` (`id`, `employer_name`, `description`, `employee_address`) VALUES (1,'ADMIN','Main','Cagayan de Oro, officially the City of Cagayan de Oro');

--
-- Dumping data for table `leave_types`
--

INSERT INTO `leave_types` (`id`, `name`, `days_allowed`, `is_paid`, `description`, `status`, `created_at`, `carryover`, `carryover_cap`) VALUES (1,'Sick Leave',15,1,'Leave for illness or medical reasons',1,'2026-06-30 01:05:23',0,NULL);
INSERT INTO `leave_types` (`id`, `name`, `days_allowed`, `is_paid`, `description`, `status`, `created_at`, `carryover`, `carryover_cap`) VALUES (2,'Vacation Leave',15,1,'Planned time off / personal vacation',1,'2026-06-30 01:05:23',0,NULL);
INSERT INTO `leave_types` (`id`, `name`, `days_allowed`, `is_paid`, `description`, `status`, `created_at`, `carryover`, `carryover_cap`) VALUES (10,'Leave Without Pay',0,0,'Unpaid leave (LWOP) — days are unpaid absences in payroll.',1,'2026-07-22 13:55:31',0,NULL);

--
-- Dumping data for table `loans`
--

INSERT INTO `loans` (`loan_id`, `employee_id`, `loan_type`, `loan_date`, `effective_date`, `loan_amount`, `loan_balance`, `loan_status`, `damount`) VALUES (1,238,1,'2026-07-26',NULL,10000,9500,0,500);

--
-- Dumping data for table `pay_settings`
--

INSERT INTO `pay_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES (1,'legal_holiday_rate',2,'Legal holiday pay multiplier (DOLE: 200%)',NULL,'2026-06-30 21:43:56');
INSERT INTO `pay_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES (2,'special_holiday_rate',1.3,'Special holiday pay multiplier (DOLE: 130%)',NULL,'2026-06-30 21:43:56');
INSERT INTO `pay_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES (3,'ot_regular_rate',1.25,'Overtime on regular day (DOLE: 125%)',NULL,'2026-06-30 21:43:56');
INSERT INTO `pay_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES (4,'ot_holiday_multiplier',1.3,'OT multiplier on top of holiday rate (DOLE: 130%)',NULL,'2026-06-30 21:43:56');
INSERT INTO `pay_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES (5,'nsd_rate',0.1,'Night shift differential (DOLE: 10%), overridden by work schedule',NULL,'2026-06-30 21:43:56');
INSERT INTO `pay_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES (6,'rest_day_rate',1.3,'Rest day premium (DOLE: 130%)',NULL,'2026-06-30 21:43:56');
INSERT INTO `pay_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES (7,'th13_include_paid_leave',1,'13th month basis includes approved paid-leave days (1=yes, 0=no)',NULL,'2026-07-23 13:38:07');
INSERT INTO `pay_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES (8,'th13_include_allowance',0,'13th month basis includes allowances (1=yes, 0=strict basic — DOLE default)',NULL,'2026-07-23 13:38:07');
INSERT INTO `pay_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES (9,'th13_round_to_peso',0,'Round 13th month pay to whole pesos (1=yes, 0=centavo-exact)',NULL,'2026-07-23 13:38:07');
INSERT INTO `pay_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES (10,'sanity_net_swing_pct',30,'Pre-lock check: flag net pay changes above this percent vs previous period',NULL,'2026-07-23 13:53:26');

--
-- Dumping data for table `position`
--

INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (1,NULL,'Unassigned');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (53,NULL,'PURCHASING');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (54,NULL,'ADMIN STAFF');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (55,NULL,'General Services Supervisor');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (56,NULL,'RECEPTIONIST');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (57,NULL,'Human Resource Director');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (58,NULL,'HR STAFF');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (59,NULL,'CSR HEAD/ PCO');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (60,NULL,'CSR STAFF');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (61,NULL,'HOUSEKEEPING HEAD');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (62,NULL,'STAFF');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (63,NULL,'LAUNDRY OIC');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (64,NULL,'LINEN HEAD');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (65,NULL,'HEAD');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (66,NULL,'CLERK');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (67,NULL,'SOCIAL WORKER');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (68,NULL,'IT IN-CHARGE');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (69,NULL,'HEAD DIETITIAN');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (70,NULL,'ASST. DIETITIAN');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (71,NULL,'PURCHASER/DIETARY STAFF');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (72,NULL,'COOK');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (73,NULL,'COOK/AIDE');
INSERT INTO `position` (`id`, `department_id`, `name`) VALUES (74,NULL,'AIDE');

--
-- Dumping data for table `sites`
--

INSERT INTO `sites` (`id`, `employer_id`, `site_code`, `cluster_id`, `timekeeper_id`, `pic`, `site_name`, `site_address`, `status`) VALUES (1,1,'MAIN-0001',1,71,NULL,'Main Site','CDO',1);

--
-- Dumping data for table `work_schedules`
--

INSERT INTO `work_schedules` (`id`, `description`, `start_time`, `end_time`, `total_hours`, `break_minutes`, `is_graveyard`, `has_nsd`, `nsd_rate`, `status`, `created_at`) VALUES (1,'Day Shift','08:00:00','17:00:00',8,60,0,0,0,1,'2026-06-30 10:17:57');
INSERT INTO `work_schedules` (`id`, `description`, `start_time`, `end_time`, `total_hours`, `break_minutes`, `is_graveyard`, `has_nsd`, `nsd_rate`, `status`, `created_at`) VALUES (2,'Evening Shift','15:00:00','00:00:00',8,60,0,1,0.1,1,'2026-06-30 10:17:57');
INSERT INTO `work_schedules` (`id`, `description`, `start_time`, `end_time`, `total_hours`, `break_minutes`, `is_graveyard`, `has_nsd`, `nsd_rate`, `status`, `created_at`) VALUES (3,'Night Shift','23:00:00','08:00:00',8,60,1,1,0.1,1,'2026-06-30 10:17:57');

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

