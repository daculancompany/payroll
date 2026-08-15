-- 2026_08_area_approvers.sql
-- AREA = ang operating unit (ward/section). Mas pino kaysa department:
-- 36 ka area sa 19 ka department. Ang department magpabilin para sa payroll;
-- ang area mo-drive sa leave approval ug sa duty roster scoping.
--
-- NGANO area_approver IMBES column sa `users`: ang usa ka approver mahimong
--   * mo-cover og daghang area — ILOGON = 8 ka nursing ward, mo-tabok sa 7 ka department
--   * magkalahi og papel kada area — BACONGUIS = sec sa HEAD NURSE, sup sa 3 ka ward
--   * duha ka tawo sa usa ka slot — MORADO/RATILLA sa STATION 2, bisan kinsa maka-approve
-- Walay usa niini ang mahaom sa usa ka users.department_id.
--
-- STAGES (mo-tugma sa LEAVE_APPROVAL_STAGES ug sa {key}_* nga column):
--   sec   Section/Unit Head        role 11   optional
--   sup   Supervisor               role 10   optional
--   admin Department/Division Head role 8
--   hr    HR                       role 9    (global — wala sa area_approver)
-- 9 ka area ang mogamit sa tanan 4 (8 nursing ward + HOUSEKEEPING); ang uban
-- mo-skip sa NULL nga stage pinaagi sa 'optional' flag.
--
-- DUTY ROSTER: ang stage='admin' ra ang maka-edit sa schedule.

START TRANSACTION;

-- ===== 1) IKA-4 NGA STAGE SA LEAVE_REQUESTS =====
-- Parehong pattern sa 2026_07_leave_supervisor.sql. Ang naa nang requests
-- kay wala pa nakaila sa Section/Unit Head stage, so i-mark nga approved ang
-- ilang sec stage aron magpabiling coherent ang timeline ug ang overall status.
ALTER TABLE `leave_requests`
    ADD COLUMN `sec_status`  TINYINT      NOT NULL DEFAULT 0 AFTER `filed_by`,
    ADD COLUMN `sec_by`      INT          NULL         AFTER `sec_status`,
    ADD COLUMN `sec_remarks` VARCHAR(255) NULL         AFTER `sec_by`,
    ADD COLUMN `sec_at`      DATETIME     NULL         AFTER `sec_remarks`;

UPDATE `leave_requests`
   SET `sec_status` = 1,
       `sec_at`     = COALESCE(`sup_at`, `admin_at`, `hr_at`, `created_at`)
 WHERE `sec_status` = 0 AND `status` IN (1, 2);

-- ===== 2) AREA =====
CREATE TABLE IF NOT EXISTS `area` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `department_id` INT(11) NOT NULL,
  `name`          VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status`        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_area_name` (`name`),
  KEY `idx_dept` (`department_id`),
  CONSTRAINT `area_dept_fk` FOREIGN KEY (`department_id`) REFERENCES `department`(`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `area_approver` (
  `area_id` INT(11) NOT NULL,
  `stage`   VARCHAR(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` INT(30) NOT NULL,
  PRIMARY KEY (`area_id`,`stage`,`user_id`),
  KEY `idx_user_stage` (`user_id`,`stage`),
  CONSTRAINT `aa_area_fk` FOREIGN KEY (`area_id`) REFERENCES `area`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `aa_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- area_id NULL = wala pa ma-assign; ang scoping mo-fallback sa department,
-- mao nga dili maguba ang naa na samtang wala pa kompleto ang rollout.
ALTER TABLE `employee` ADD COLUMN `area_id` INT(11) NULL DEFAULT NULL AFTER `department_id`;
ALTER TABLE `employee` ADD KEY `idx_area` (`area_id`);
ALTER TABLE `employee` ADD CONSTRAINT `emp_area_fk` FOREIGN KEY (`area_id`) REFERENCES `area`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ===== 3) AREAS (36) =====
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'ADMINISTRATION' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='ADMINISTRATION');
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'HUMAN RESOURCE' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='HUMAN RESOURCE');
INSERT INTO `area` (`department_id`,`name`) SELECT 2,'CENTRAL SUPPLY ROOM' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='CENTRAL SUPPLY ROOM');
INSERT INTO `area` (`department_id`,`name`) SELECT 5,'HOUSEKEEPING' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='HOUSEKEEPING');
INSERT INTO `area` (`department_id`,`name`) SELECT 5,'LAUNDRY' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='LAUNDRY');
INSERT INTO `area` (`department_id`,`name`) SELECT 5,'LINEN' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='LINEN');
INSERT INTO `area` (`department_id`,`name`) SELECT 7,'MAINTENANCE' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='MAINTENANCE');
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'MEDICAL RECORDS' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='MEDICAL RECORDS');
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'ADMITTING SECTION' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='ADMITTING SECTION');
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'SOCIAL WORKS' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='SOCIAL WORKS');
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'INFORMATION TECHNOLOGY' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='INFORMATION TECHNOLOGY');
INSERT INTO `area` (`department_id`,`name`) SELECT 3,'DIETARY' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='DIETARY');
INSERT INTO `area` (`department_id`,`name`) SELECT 11,'CARDIO VASCULAR LABORATORY' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='CARDIO VASCULAR LABORATORY');
INSERT INTO `area` (`department_id`,`name`) SELECT 19,'PHARMACY' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='PHARMACY');
INSERT INTO `area` (`department_id`,`name`) SELECT 8,'RESPIRATORY UNIT' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='RESPIRATORY UNIT');
INSERT INTO `area` (`department_id`,`name`) SELECT 4,'EYE CENTER' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='EYE CENTER');
INSERT INTO `area` (`department_id`,`name`) SELECT 6,'LABORATORY' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='LABORATORY');
INSERT INTO `area` (`department_id`,`name`) SELECT 16,'NUCLEAR MEDICINE' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='NUCLEAR MEDICINE');
INSERT INTO `area` (`department_id`,`name`) SELECT 13,'OPD KONSULTA' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='OPD KONSULTA');
INSERT INTO `area` (`department_id`,`name`) SELECT 8,'RADIOLOGY' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='RADIOLOGY');
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'FINANCE' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='FINANCE');
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'ACCOUNTING' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='ACCOUNTING');
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'BILLING' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='BILLING');
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'CASHIERING' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='CASHIERING');
INSERT INTO `area` (`department_id`,`name`) SELECT 1,'PHILHEALTH' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='PHILHEALTH');
INSERT INTO `area` (`department_id`,`name`) SELECT 10,'NURSING ADMIN' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='NURSING ADMIN');
INSERT INTO `area` (`department_id`,`name`) SELECT 10,'HEAD NURSE' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='HEAD NURSE');
INSERT INTO `area` (`department_id`,`name`) SELECT 13,'ER-DISPENSARY' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='ER-DISPENSARY');
INSERT INTO `area` (`department_id`,`name`) SELECT 14,'HDU/ONCO' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='HDU/ONCO');
INSERT INTO `area` (`department_id`,`name`) SELECT 15,'ICU' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='ICU');
INSERT INTO `area` (`department_id`,`name`) SELECT 10,'STATION 2' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='STATION 2');
INSERT INTO `area` (`department_id`,`name`) SELECT 10,'NURSES STATION 3' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='NURSES STATION 3');
INSERT INTO `area` (`department_id`,`name`) SELECT 10,'NURSE STATION 4' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='NURSE STATION 4');
INSERT INTO `area` (`department_id`,`name`) SELECT 10,'NURSE STATION 5' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='NURSE STATION 5');
INSERT INTO `area` (`department_id`,`name`) SELECT 18,'OR/DR/NICU COMPLEX' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='OR/DR/NICU COMPLEX');
INSERT INTO `area` (`department_id`,`name`) SELECT 20,'RESIDENT ON DUTY' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `area` WHERE `name`='RESIDENT ON DUTY');

-- ===== 4) USERS (46 bag-o) =====
-- Si DOPLON wala dinhi — naa na siyay account (id 73, 'hr-head').
-- role: 11 = section head, 10 = supervisor, 8 = division head.
-- Ang tinuod nga stage magikan sa area_approver, dili sa role — 5 ka tawo
-- ang naay lain-laing papel depende sa area.
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'Dr. Abbie Lim','abbie.lim','$2y$12$8S7tJW0pM32HFVmnbgkNBOdIenwTh4YcXH5uKVWsLpp5FfrlF1Jui',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='abbie.lim');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'ARTAJO, AGUSTO CESAR CENIZA','agusto.artajo','$2y$12$DORKIppufcSS4P8gXOAvUONZYpN0pkf6BT28O779sBombdqFmS7F6',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='agusto.artajo');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'ABRIAM, AIZA MARIE VALMORES','aiza.abriam','$2y$12$77aDmkaqo1R7IuxbMzhAJuXRCJiMVjU7Sv801Zfs7MlDRsIzNzKUy',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='aiza.abriam');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'MORADO, BARBARA LOU P.','barbara.morado','$2y$12$oX7U8U.g5VpROMjqd10EpObiYqsA9KHH5C4mlxS6AKV4DEzTaMFzi',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='barbara.morado');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'BERNARD LINAAC II','bernard.linaac','$2y$12$UESm2JR0dOONqo4rXmYao.prUgHhp9L6Tia4/UBpFi7gpzIduVyLi',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='bernard.linaac');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'JANAO, CHRISTINE ANGELI A.','christine.janao','$2y$12$yrqLAkR7.0iEl6qLenr9a.D5jEf3kMxJVawUdf65oAV62w9kRjdaa',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='christine.janao');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'CRISNON MAGDALE','crisnon.magdale','$2y$12$pwU0x.SAAxzqPmLv9Z2KW.FjKn9/NQHUzW191TPf9BRTn0X0lPvI2',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='crisnon.magdale');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'DARRYL JOHN APOLINARIO','darryl.apolinario','$2y$12$1NGuXCSsk0ffX2KAI6gs/udCEamLppSsroWztTdk1M3n9fEiz3bYC',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='darryl.apolinario');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'MAYUGA, DODIE KARL A.','dodie.mayuga','$2y$12$Y4BCIpvgASQlSZ1CXQjCR.R2GVk4bkhoi.sZXxISNllY4bdSBd6Ge',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='dodie.mayuga');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'DR. DELOS SANTOS, EDWIN','edwin.delossantosjr','$2y$12$CSgOlBqwbKHS4HRkKGD4lus8NLksA52ougRtznLfQTsFBK5wUg6Sa',8,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='edwin.delossantosjr');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'ESTER ABALES','ester.abales','$2y$12$MtzUtpPgl8wYR0Vx/SymnOa3PiuMNJTUufifqRlbvN3LzQtf7lcKG',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='ester.abales');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'ILOGON, FE A.','fe.ilogon','$2y$12$At2AyBiAi3/eRjvjVUlM8e5PPMynoAW/TMMh9WfLWRDS3xd8Hclde',8,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='fe.ilogon');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'SANTIAS, FE','fe.santias','$2y$12$mpSwS05QILbfOVHlepLzTOtXTmJkhkrwDC9Q7WW2WukkzoazZJXwG',8,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='fe.santias');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'MANALO, FRENCH JANE','french.manalo','$2y$12$LDqsniVFiK.J8Pw8VWmwUunddFDyolQ8UwTFj.NHkEpP2VLzBHAJe',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='french.manalo');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'FACTURA, HANNAH MARIE M.','hannah.factura','$2y$12$kOqhbcf0tSnfudHpD4JbIOz8eanL4mP1KXBW0irgVevYLMg3YLbyq',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='hannah.factura');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'ERESO, JANETH T.','janeth.ereso','$2y$12$uaWCGnPKT7XlVgd8BHLLZu0goLg7.WRnZ0JFTle6PVl6qn17cPZSK',10,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='janeth.ereso');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'SULOG, JOHAIRA D.','johairia.sulog','$2y$12$1LL1uDlL60wyduMCVTjib.ibW5.lRYhXn9Ofvg2ZKqZ0PKRtQEGEi',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='johairia.sulog');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'EBOÑA, JUDY PAZ E.','judy.ebona','$2y$12$DLj8.xPuh0XMgPXXrPf2/.YK4IIfC3SiaH1m4.1IaOHQKNrMtAuGy',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='judy.ebona');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'ABANALES, KAREN MARTINEZ','karen.abanales','$2y$12$DxBBg4UAPhAxMwW.rT2C..wsqbFV1QlYyoczDJrJ..EfzWkSo407G',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='karen.abanales');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'RATILLA, KATHLEEN BLESS V.','kathleen.ratilla','$2y$12$ZxhWed2pZNlp/bzOzQGRBev/U9Q/.E5MjBvEG7d6L3fraHKwIm94S',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='kathleen.ratilla');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'DOMINGO, MA TERESA','ma.domingo','$2y$12$QGGxwDRe9MflzmEM6TYy3.Lzws9CUP1FTtmLnUNLky4GGBlpbl.XW',10,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='ma.domingo');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'CASIÑO, MARIA CECILIA GONZALES','maria.casino','$2y$12$P3pfedOvakN0jDFd6AlHTOHM0jAjmCiMBoqaKZOqyO8iYSTGi1GM6',8,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='maria.casino');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'DR. MARIA MERCEDITAS A. SERIÑA','maria.serina','$2y$12$m1HWYbcNwQyEhP.dvEsF6eNzxo1SrrXcnPnrZ.ls6gmbyRNCoOt9K',8,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='maria.serina');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'ANDALOC, MARIE JANE E.','marie.andaloc','$2y$12$CrWfyi7Lw0LQSCT9YsTMZu.iANnE3AUJ1GG5nF8n7kzBxCQ/ysBd2',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='marie.andaloc');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'BACONGUIS, MARK BRYAN','mark.baconguis','$2y$12$xr47nOkqlvEQmckK8Rx.suMkHj8/V/q19L19CvuNWB57GeRes3n6q',10,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='mark.baconguis');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'VILLANUEVA, MARY IRENE','mary.villanueva','$2y$12$Ovhr/C3zm7CrfhUNBqhqu.xSOxenxPWU1THjq9szZxDtf2DDoCXlG',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='mary.villanueva');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'DAMASING, MIKEE LOU','mikee.damasing','$2y$12$76KCOyqNkcV9ztVPlTCEuevHQTuiBiRjCVaxLlse6Y0dP14dEgUCq',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='mikee.damasing');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'NIEZELLE DUPITAS','niezelle.dupitas','$2y$12$NQ69cp2qcXuWkzv.LIM87ekcjqKAVEVU4uiSdCLAFIT635aj62Z.u',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='niezelle.dupitas');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'MONTEROS, OLIVA','oliva.monteros','$2y$12$Obxkp5p4O5aHhhIgGXSL3O7BBCkSGt4renqnkJ2nBsXswVdBG5R52',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='oliva.monteros');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'BLANCO, PERLITA','perlita.blanco','$2y$12$v.vLhIGtLalVj8UQAqoL9OesAxZ4tVh0.5S8yya9hTqUPXbVqCoui',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='perlita.blanco');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'LINA-AC, PRINCESS DHANA D.','princess.linaac','$2y$12$4PlLg7Hr2vQfvmMZ7z2cFOdpGqbX.vEsRrlUGzQ4LnfrrV8BVJr8G',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='princess.linaac');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'DR. ANNE CHRISTINE CO-KHO','raphy.kho','$2y$12$jqWdk24YduppI59xOVgm3OeL0v9i7.G/dZTQKWuo65/batntykh3.',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='raphy.kho');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'NAVALES, REY BAJUYO','rey.navales','$2y$12$SVyHHF8fBDs41cNNjemqC./y4/kPvOvdGUfueEYxBo4XwwWuorVr2',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='rey.navales');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'Dr. Ronald Caumban','ronald.caumban','$2y$12$.YweS12.LZLw3p8MYZDhDuiSlzEJWDzFKOacT0jjoNYNwXSTcz/c2',8,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='ronald.caumban');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'ZACARIAS, ROWENA B.','rowena.zacarias','$2y$12$9rwVszpQGGr4tjHnwwGGqepUAoRf/nwesFtwQvz5L5tC3lM0wYAE6',10,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='rowena.zacarias');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'BONSAYON, RUBY T.','ruby.bonsayon','$2y$12$oTN5giT3C7MeRyaPB521mevQjs0euSzVs4Iars9td7tded/dpkoeC',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='ruby.bonsayon');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'BROCES, RUFA','rufa.broces','$2y$12$wMqAVOnmxmN0X4iQUd0Kve0AY.3ECNYvLd3b5VINjNWc53WACR02y',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='rufa.broces');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'Dr. Sarah Casio','sarah.casio','$2y$12$nU6COJhagOMD86sLOX.FsexQeA/xkHfmO8F/Z9bhfFqqhKDDOK1pW',8,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='sarah.casio');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'DR. STEPHANIE JACUTIN','stephanie.jacutin','$2y$12$cHxxfL4bz0tKaFGAa.K/muHSyi8v4gF3MrLPvT9u6ZQONNIFp4ksW',8,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='stephanie.jacutin');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'Dr. Susan Camomot','susan.camomot','$2y$12$R/UScM8XvJ4XYyjKApgi3eCHMBkCveqDr8ISSFCU6tUKcS7y4ktIy',8,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='susan.camomot');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'TRILE M. CARPIO','trile.carpio','$2y$12$7OiZbRrmbKjml4HYXbUdPegDz10V9G99ztZ8z/m9sJsWhRzKAqG6S',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='trile.carpio');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'VANISA C. ACTUB','vanisa.actub','$2y$12$wnfFalP7cLPlEubeIvR8rOOpaddGbzWLlsr5IH6YuEj9V3HbFfOlC',10,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='vanisa.actub');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'BELACHO, VERNON CLYDE N.','vernon.belacho','$2y$12$gDyonh7OSbkBNLXv.dZgvuD514A.E/NtkBUMStM3FwtuYJdExz3.S',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='vernon.belacho');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'CUCHARO, VIERJOHN C.','vierjohn.cucharo','$2y$12$UhNLmVatFULLxBYSw71FreCWrLvfAzS3HEAFVQaZ5yTkOHnDVlyha',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='vierjohn.cucharo');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'RUGAY, WILMA P.','wilma.rugay','$2y$12$MdXFSvnftWQ4I63JI4gtnuR.xmEYAd8rRJZnFOHPP2iuWiDY1yJA6',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='wilma.rugay');
INSERT INTO `users` (`name`,`username`,`password`,`role`,`status`) SELECT 'ZENITH MAHILOM','zenith.mahilom','$2y$12$UT86OCKQbIcv6UgUeKs.wO3nqhy2FTvTmu06I.5pWwSbbhPWcfony',11,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username`='zenith.mahilom');

-- ===== 5) AREA APPROVERS =====
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='ADMINISTRATION' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='HUMAN RESOURCE' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='HUMAN RESOURCE' AND u.username='maria.serina';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='HUMAN RESOURCE' AND u.username='stephanie.jacutin';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='CENTRAL SUPPLY ROOM' AND u.username='ester.abales';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='CENTRAL SUPPLY ROOM' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='HOUSEKEEPING' AND u.username='crisnon.magdale';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sup',u.id FROM `area` a, `users` u WHERE a.name='HOUSEKEEPING' AND u.username='vanisa.actub';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='HOUSEKEEPING' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='LAUNDRY' AND u.username='bernard.linaac';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='LAUNDRY' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='LINEN' AND u.username='zenith.mahilom';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='LINEN' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sup',u.id FROM `area` a, `users` u WHERE a.name='MAINTENANCE' AND u.username='vanisa.actub';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='MAINTENANCE' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='MEDICAL RECORDS' AND u.username='trile.carpio';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='MEDICAL RECORDS' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='ADMITTING SECTION' AND u.username='darryl.apolinario';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='ADMITTING SECTION' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='SOCIAL WORKS' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='INFORMATION TECHNOLOGY' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='DIETARY' AND u.username='niezelle.dupitas';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='DIETARY' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='CARDIO VASCULAR LABORATORY' AND u.username='raphy.kho';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='CARDIO VASCULAR LABORATORY' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='CARDIO VASCULAR LABORATORY' AND u.username='fe.santias';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='PHARMACY' AND u.username='mikee.damasing';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='PHARMACY' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='RESPIRATORY UNIT' AND u.username='abbie.lim';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='RESPIRATORY UNIT' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='EYE CENTER' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='EYE CENTER' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='LABORATORY' AND u.username='mary.villanueva';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='LABORATORY' AND u.username='sarah.casio';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='NUCLEAR MEDICINE' AND u.username='judy.ebona';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='NUCLEAR MEDICINE' AND u.username='susan.camomot';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='OPD KONSULTA' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='OPD KONSULTA' AND u.username='hr-head';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='RADIOLOGY' AND u.username='dodie.mayuga';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='RADIOLOGY' AND u.username='ronald.caumban';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='FINANCE' AND u.username='wilma.rugay';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='FINANCE' AND u.username='edwin.delossantosjr';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='ACCOUNTING' AND u.username='wilma.rugay';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='ACCOUNTING' AND u.username='edwin.delossantosjr';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='BILLING' AND u.username='rufa.broces';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='BILLING' AND u.username='wilma.rugay';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='BILLING' AND u.username='edwin.delossantosjr';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='CASHIERING' AND u.username='oliva.monteros';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='CASHIERING' AND u.username='wilma.rugay';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='CASHIERING' AND u.username='edwin.delossantosjr';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='PHILHEALTH' AND u.username='perlita.blanco';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='PHILHEALTH' AND u.username='wilma.rugay';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='PHILHEALTH' AND u.username='edwin.delossantosjr';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='NURSING ADMIN' AND u.username='fe.ilogon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='HEAD NURSE' AND u.username='mark.baconguis';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='HEAD NURSE' AND u.username='ma.domingo';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='HEAD NURSE' AND u.username='janeth.ereso';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='HEAD NURSE' AND u.username='french.manalo';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='HEAD NURSE' AND u.username='rowena.zacarias';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='HEAD NURSE' AND u.username='fe.ilogon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='ER-DISPENSARY' AND u.username='rey.navales';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sup',u.id FROM `area` a, `users` u WHERE a.name='ER-DISPENSARY' AND u.username='mark.baconguis';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='ER-DISPENSARY' AND u.username='fe.ilogon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='HDU/ONCO' AND u.username='aiza.abriam';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='HDU/ONCO' AND u.username='agusto.artajo';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sup',u.id FROM `area` a, `users` u WHERE a.name='HDU/ONCO' AND u.username='mark.baconguis';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='HDU/ONCO' AND u.username='fe.ilogon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='ICU' AND u.username='karen.abanales';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sup',u.id FROM `area` a, `users` u WHERE a.name='ICU' AND u.username='mark.baconguis';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='ICU' AND u.username='fe.ilogon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='STATION 2' AND u.username='barbara.morado';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='STATION 2' AND u.username='kathleen.ratilla';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sup',u.id FROM `area` a, `users` u WHERE a.name='STATION 2' AND u.username='ma.domingo';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='STATION 2' AND u.username='fe.ilogon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='NURSES STATION 3' AND u.username='ruby.bonsayon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='NURSES STATION 3' AND u.username='vierjohn.cucharo';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sup',u.id FROM `area` a, `users` u WHERE a.name='NURSES STATION 3' AND u.username='ma.domingo';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='NURSES STATION 3' AND u.username='fe.ilogon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='NURSE STATION 4' AND u.username='princess.linaac';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='NURSE STATION 4' AND u.username='johairia.sulog';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sup',u.id FROM `area` a, `users` u WHERE a.name='NURSE STATION 4' AND u.username='janeth.ereso';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='NURSE STATION 4' AND u.username='fe.ilogon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='NURSE STATION 5' AND u.username='marie.andaloc';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='NURSE STATION 5' AND u.username='vernon.belacho';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sup',u.id FROM `area` a, `users` u WHERE a.name='NURSE STATION 5' AND u.username='janeth.ereso';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='NURSE STATION 5' AND u.username='fe.ilogon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='OR/DR/NICU COMPLEX' AND u.username='hannah.factura';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sec',u.id FROM `area` a, `users` u WHERE a.name='OR/DR/NICU COMPLEX' AND u.username='christine.janao';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'sup',u.id FROM `area` a, `users` u WHERE a.name='OR/DR/NICU COMPLEX' AND u.username='rowena.zacarias';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='OR/DR/NICU COMPLEX' AND u.username='fe.ilogon';
INSERT IGNORE INTO `area_approver` (`area_id`,`stage`,`user_id`)
  SELECT a.id,'admin',u.id FROM `area` a, `users` u WHERE a.name='RESIDENT ON DUTY' AND u.username='maria.casino';

-- ===== 6) EMPLOYEE -> AREA (354) =====
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='ADMINISTRATION')
  WHERE `id` IN (5,19,39,40,354);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='HUMAN RESOURCE')
  WHERE `id` IN (28,56);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='CENTRAL SUPPLY ROOM')
  WHERE `id` IN (272,273,274,275);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='HOUSEKEEPING')
  WHERE `id` IN (299,300,302,303,304,306,308,309,313,315,316,317,318,346,364,366,368);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='LAUNDRY')
  WHERE `id` IN (305,310,314);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='LINEN')
  WHERE `id` IN (307,312,319);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='MAINTENANCE')
  WHERE `id` IN (347,348,349,350,351,352,353);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='MEDICAL RECORDS')
  WHERE `id` IN (17,24,30,36);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='ADMITTING SECTION')
  WHERE `id` IN (9,15);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='SOCIAL WORKS')
  WHERE `id` IN (41);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='INFORMATION TECHNOLOGY')
  WHERE `id` IN (22);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='DIETARY')
  WHERE `id` IN (283,284,285,286,288,289,290,291,292,293,294,295,296,297);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='CARDIO VASCULAR LABORATORY')
  WHERE `id` IN (210,211);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='PHARMACY')
  WHERE `id` IN (261,262,263,264,265,266,267,268,269,270,271,370);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='RESPIRATORY UNIT')
  WHERE `id` IN (68);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='EYE CENTER')
  WHERE `id` IN (298);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='LABORATORY')
  WHERE `id` IN (321,322,324,325,326,328,329,330,332,333,334,335,336,337,338,340,341,342,343,344,345);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='NUCLEAR MEDICINE')
  WHERE `id` IN (248,249,250,251);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='OPD KONSULTA')
  WHERE `id` IN (42,44,141,200);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='RADIOLOGY')
  WHERE `id` IN (323,327,331,355,356,357,358,359,360,361);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='FINANCE')
  WHERE `id` IN (3,14,27,57);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='ACCOUNTING')
  WHERE `id` IN (2,6,7,21,23,34,38,45,49,53,55,59);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='BILLING')
  WHERE `id` IN (4,12,16,20,26,29,31,32,48,52,54);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='CASHIERING')
  WHERE `id` IN (1,8,13,25,46);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='PHILHEALTH')
  WHERE `id` IN (10,11,18,35,47,50,51,60);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='NURSING ADMIN')
  WHERE `id` IN (102,114,116,121,140,208,232,239);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='HEAD NURSE')
  WHERE `id` IN (72,87,91,156,169,192,213,221,224,230,237,245,252,256);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='ER-DISPENSARY')
  WHERE `id` IN (71,95,107,123,125,126,132,147,153,157,166,167,194,207,209,219,220,222,225,226,227,244);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='HDU/ONCO')
  WHERE `id` IN (81,97,109,120,158,164,172,176,189,205,231,233,234,235,236,253,254,255);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='ICU')
  WHERE `id` IN (74,89,93,106,110,118,136,139,144,145,160,174,175,179,186,193,204,223,240,246);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='STATION 2')
  WHERE `id` IN (64,66,82,84,92,112,113,115,117,129,146,152,159,180,181,188,198,202,203,238,247);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='NURSES STATION 3')
  WHERE `id` IN (62,79,86,96,119,122,124,127,138,151,154,163,171,173,177,178,184,187,196,228,229,242);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='NURSE STATION 4')
  WHERE `id` IN (65,67,77,80,98,99,128,131,134,143,149,170,182,183,185,190,191,197,199,243,367);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='NURSE STATION 5')
  WHERE `id` IN (63,70,75,76,78,83,100,101,103,137,148,150,155,165,201,206,215,218,363);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='OR/DR/NICU COMPLEX')
  WHERE `id` IN (69,73,85,88,90,94,104,105,108,111,133,135,142,161,168,195,212,214,216,217,257,258,259,260);
UPDATE `employee` SET `area_id`=(SELECT id FROM `area` WHERE `name`='RESIDENT ON DUTY')
  WHERE `id` IN (276,277,278,279,280,281,282);

-- ===== VERIFY =====
SELECT (SELECT COUNT(*) FROM area) areas,
       (SELECT COUNT(*) FROM area_approver) approver_rows,
       (SELECT COUNT(*) FROM users WHERE role IN (8,10,11)) new_users,
       (SELECT COUNT(*) FROM employee WHERE area_id IS NOT NULL) emp_naay_area,
       (SELECT COUNT(*) FROM employee WHERE area_id IS NULL) emp_walay_area;
-- dapat 0: area nga walay bisan usa ka approver
SELECT a.id,a.name FROM area a LEFT JOIN area_approver ap ON ap.area_id=a.id WHERE ap.area_id IS NULL;
-- dapat 0: area nga walay 'admin' (sila ang maka-edit sa duty roster)
SELECT a.id,a.name FROM area a LEFT JOIN area_approver ap ON ap.area_id=a.id AND ap.stage='admin' WHERE ap.area_id IS NULL;
-- pila ka stage kada area
SELECT COUNT(DISTINCT stage)+1 AS ka_stage, COUNT(*) AS ka_area FROM (
  SELECT a.id, ap.stage FROM area a JOIN area_approver ap ON ap.area_id=a.id) t GROUP BY t.id;

-- COMMIT;
-- ROLLBACK;
