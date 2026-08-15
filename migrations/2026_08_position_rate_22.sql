-- 2026_08_position_rate_22.sql
-- Source: migrations/COMC DEPARTMNENT EMPLOYEES.xlsx (Sheet1)
-- Updates POSITION + RATE only. Daily/monthly divisor changed 26 -> 22.
--   daily-basis  (Excel rate <= 5000): salary = rate,      basic_pay = rate * 22
--   monthly-basis(Excel rate >  5000): basic_pay = rate,   salary    = rate / 22
-- ot_rate recomputed as salary / 8 * 1.30 (approved by user).
-- id 181 POQUIZ forced to 995.00 daily (approved; DB salary 1095 was the outlier).
-- Employees updated: 365   New positions: 48   Positions linked to dept: 5

START TRANSACTION;

-- 1) ROLLBACK SNAPSHOT ------------------------------------------------
DROP TABLE IF EXISTS `employee_rate_backup_2026_08`;
CREATE TABLE `employee_rate_backup_2026_08` AS
  SELECT id, position_id, salary, basic_pay, ot_rate, rate_type FROM employee;

-- 2) NEW POSITIONS ----------------------------------------------------
--    department_id set only where the position exists in exactly ONE dept.
--    Shared names (STAFF NURSE, STAFF, HEAD, ...) stay NULL on purpose:
--    position lookup elsewhere is by name, so duplicate names would break it.
INSERT INTO `position` (`name`,`department_id`) SELECT 'AIDE/PURCHASER',3 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('AIDE/PURCHASER'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'AIDE/STOCKMAN',19 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('AIDE/STOCKMAN'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'AMBULANCE DRIVER',13 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('AMBULANCE DRIVER'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'ASST. CHIEF NURSE',10 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('ASST. CHIEF NURSE'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'AUDITOR IN CHIEF',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('AUDITOR IN CHIEF'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'BOARD SECRETARY',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('BOARD SECRETARY'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'BOOKKEEPER',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('BOOKKEEPER'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'CHIEF COMPLIANCE OFFICER',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF COMPLIANCE OFFICER'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'CHIEF DIETITIAN',3 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF DIETITIAN'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'CHIEF FINANCE OFFICER',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF FINANCE OFFICER'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'CHIEF MEDTECH',6 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF MEDTECH'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'CHIEF RADTECH',NULL FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF RADTECH'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'CHIEF ROD',20 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF ROD'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'DATA ENGINEER',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('DATA ENGINEER'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'DIRECTOR OF NURSING',10 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('DIRECTOR OF NURSING'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'ECHO TECH',11 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('ECHO TECH'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'ENDOSCOPY AIDE',18 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('ENDOSCOPY AIDE'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'GENERAL SUPERVISOR',10 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('GENERAL SUPERVISOR'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'HDU TECHNICIAN',14 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('HDU TECHNICIAN'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'HEAD NURSE',NULL FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'HEAD/PCO',2 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('HEAD/PCO'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'HISTOTECH',6 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('HISTOTECH'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'HOSPITAL PRESIDENT',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('HOSPITAL PRESIDENT'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'INFECTION CONTROL NURSE',10 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('INFECTION CONTROL NURSE'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'IT',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('IT'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'LAB. RECEPTIONIST',6 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('LAB. RECEPTIONIST'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'LIAISON',16 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('LIAISON'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'LIAISON/MESSENGER',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('LIAISON/MESSENGER'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'MEDICAL DIRECTOR',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('MEDICAL DIRECTOR'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'MEDTECH',6 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'MIDWIFE',NULL FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'NURSING ATTENDANT',NULL FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'OIC',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('OIC'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'OIC HOSPITAL ADMINISTRATOR/ HUMAN  RESOURCE DIRECTOR',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('OIC HOSPITAL ADMINISTRATOR/ HUMAN  RESOURCE DIRECTOR'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'PAYROLL IN CHARGE',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('PAYROLL IN CHARGE'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'PHARMACIST I',19 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('PHARMACIST I'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'PHARMACIST II',19 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('PHARMACIST II'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'PHLEBOTOMIST',6 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('PHLEBOTOMIST'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'PURCHASER',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('PURCHASER'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'RADTECH',NULL FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('RADTECH'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'RESPIRATORY THERAPIST',8 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('RESPIRATORY THERAPIST'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'ROD',20 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('ROD'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'STAFF NURSE',NULL FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'SUPERVISOR',NULL FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('SUPERVISOR'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'TELEPHONE OPERATOR/ INFORMATION STAFF',1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('TELEPHONE OPERATOR/ INFORMATION STAFF'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'WARDMAN',NULL FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'XRAY TECHNICIAN',8 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('XRAY TECHNICIAN'));
INSERT INTO `position` (`name`,`department_id`) SELECT 'YAKAP CLINIC DOCTOR',20 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `position` WHERE LOWER(`name`)=LOWER('YAKAP CLINIC DOCTOR'));

-- 3) LINK EXISTING POSITIONS TO THEIR DEPARTMENT ----------------------
UPDATE `position` SET `department_id`=3 WHERE `id`=70 AND `department_id` IS NULL; -- ASST. DIETITIAN
UPDATE `position` SET `department_id`=1 WHERE `id`=66 AND `department_id` IS NULL; -- CLERK
UPDATE `position` SET `department_id`=3 WHERE `id`=72 AND `department_id` IS NULL; -- COOK
UPDATE `position` SET `department_id`=1 WHERE `id`=55 AND `department_id` IS NULL; -- GENERAL SERVICES SUPERVISOR
UPDATE `position` SET `department_id`=1 WHERE `id`=67 AND `department_id` IS NULL; -- SOCIAL WORKER

-- 4) EMPLOYEE POSITION + RATE ----------------------------------------
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=514.00, `basic_pay`=11308.00, `ot_rate`=83.53 WHERE `id`=1; -- ABALLE, JUNELYN DAVIN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=2; -- ABEJO, HAZEL MELALLOS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=589.00, `basic_pay`=12958.00, `ot_rate`=95.71 WHERE `id`=3; -- ABOT, ANGEL ANDAM
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=4; -- ABRIOL, ROSE MAE YAÑEZ
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('GENERAL SERVICES SUPERVISOR') ORDER BY `id` LIMIT 1), `salary`=650.00, `basic_pay`=14300.00, `ot_rate`=105.63 WHERE `id`=5; -- ACTUB, VANISA COMEROS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=6; -- AJOC, CRISANTO ALVARICO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('PAYROLL IN CHARGE') ORDER BY `id` LIMIT 1), `salary`=639.00, `basic_pay`=14058.00, `ot_rate`=103.84 WHERE `id`=7; -- AJOC, JENALYN DELANTAR
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=8; -- ALMONIA, CRISTINE JANE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=9; -- APOLINARIO, DARRYL JOHN ARTAJO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=10; -- BACAL, JOEL-LYN B.
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=11; -- BACAL, LAARNIE FAYE LOMBRINO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=12; -- BACTONG, MARY JOY RANAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=520.00, `basic_pay`=11440.00, `ot_rate`=84.50 WHERE `id`=13; -- BALANGYAO, LILIBETH CERNADA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=607.45, `basic_pay`=13364.00, `ot_rate`=98.71 WHERE `id`=14; -- BARLISO, BENDERLY JANE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=520.00, `basic_pay`=11440.00, `ot_rate`=84.50 WHERE `id`=15; -- BARSOBIA, DEARHA PACTURAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=16; -- BATINO, JESSABEL CALDERON
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=17; -- BENTOZAL, RENMARK BADILLA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD') ORDER BY `id` LIMIT 1), `salary`=776.82, `basic_pay`=17090.00, `ot_rate`=126.23 WHERE `id`=18; -- BLANCO, PERLITA RIOS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('PURCHASER') ORDER BY `id` LIMIT 1), `salary`=849.27, `basic_pay`=18684.00, `ot_rate`=138.01 WHERE `id`=19; -- BONBON, BARRY NOTINGGO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=20; -- BROCES, RUFA LUMAGOD
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=539.00, `basic_pay`=11858.00, `ot_rate`=87.59 WHERE `id`=21; -- BUENAVIDES, GEIVE ALBURO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('IT') ORDER BY `id` LIMIT 1), `salary`=712.00, `basic_pay`=15664.00, `ot_rate`=115.70 WHERE `id`=22; -- CAGALAWAN, PAUL CHRISTIAN SACALA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('BOOKKEEPER') ORDER BY `id` LIMIT 1), `salary`=689.00, `basic_pay`=15158.00, `ot_rate`=111.96 WHERE `id`=23; -- CAGALITAN, PIA MONICA DEALCA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD') ORDER BY `id` LIMIT 1), `salary`=700.91, `basic_pay`=15420.00, `ot_rate`=113.90 WHERE `id`=24; -- CARPIO, TRILE MALAGUM
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=25; -- DECRETALES, JUSTINE MARIE BACAL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=558.00, `basic_pay`=12276.00, `ot_rate`=90.68 WHERE `id`=26; -- DEGAMON, JEANILYN RONGCALES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF FINANCE OFFICER') ORDER BY `id` LIMIT 1), `salary`=4090.91, `basic_pay`=90000.00, `ot_rate`=664.77 WHERE `id`=27; -- DELOS SANTOS JR, EDWIN MAGANDING
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('OIC HOSPITAL ADMINISTRATOR/ HUMAN  RESOURCE DIRECTOR') ORDER BY `id` LIMIT 1), `salary`=3636.36, `basic_pay`=80000.00, `ot_rate`=590.91 WHERE `id`=28; -- DOPLON, MARIA SCICHOLLONE B.
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=29; -- DOTILLOS, MICAELLA SOTTO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=562.00, `basic_pay`=12364.00, `ot_rate`=91.33 WHERE `id`=30; -- DURAN, VELY YACAPIN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=31; -- ENCILAY, ANGELICA MACATIGUIB
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=32; -- ENERIO, LYZAH MAY GALLARDE
UPDATE `employee` SET `salary`=1818.18, `basic_pay`=40000.00, `ot_rate`=295.45 WHERE `id`=33; -- FLOIRENDO II, LICERIO ALCANTARA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=34; -- FRANCISCO, ANNE JELU OLIVEROS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=35; -- GUINEA, GLIEZL MAE VILLAROSA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=576.56, `basic_pay`=12684.32, `ot_rate`=93.69 WHERE `id`=36; -- IMPERIO, MARY ANN TADEO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT') ORDER BY `id` LIMIT 1), `salary`=510.00, `basic_pay`=11220.00, `ot_rate`=82.88 WHERE `id`=141; -- IMUS, RAYMART LABALAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDICAL DIRECTOR') ORDER BY `id` LIMIT 1), `salary`=2727.27, `basic_pay`=60000.00, `ot_rate`=443.18 WHERE `id`=37; -- JACUTIN, STEPHANIE ECHEM
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=38; -- JERUSALEM, EUDISA BULAHAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=612.00, `basic_pay`=13464.00, `ot_rate`=99.45 WHERE `id`=39; -- LAGARE, ARTTROY JHON ACTUB
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('TELEPHONE OPERATOR/ INFORMATION STAFF') ORDER BY `id` LIMIT 1), `salary`=568.91, `basic_pay`=12516.02, `ot_rate`=92.45 WHERE `id`=40; -- LANGALA, RONALYN GETUABAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('SOCIAL WORKER') ORDER BY `id` LIMIT 1), `salary`=772.00, `basic_pay`=16984.00, `ot_rate`=125.45 WHERE `id`=41; -- LICAYAN, IVY FRANCISCO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('OIC') ORDER BY `id` LIMIT 1), `salary`=639.00, `basic_pay`=14058.00, `ot_rate`=103.84 WHERE `id`=42; -- MAAGAD, PRINCESS HEART PAREDES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=43; -- MALAGUM, JOEVY MAE OLASO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=44; -- MANSEGUIAO, KAYE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AUDITOR IN CHIEF') ORDER BY `id` LIMIT 1), `salary`=607.45, `basic_pay`=13364.00, `ot_rate`=98.71 WHERE `id`=45; -- MARIMON, ERA ACERO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD') ORDER BY `id` LIMIT 1), `salary`=1035.91, `basic_pay`=22790.00, `ot_rate`=168.34 WHERE `id`=46; -- MONTEROS, OLIVA BACARRISAS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=572.00, `basic_pay`=12584.00, `ot_rate`=92.95 WHERE `id`=47; -- OMONGOS, ZYRAH MAGDADARO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=48; -- PAGARA, CHRISTIE JOY LICO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=49; -- PAHAPAY, ROSABELLA LEGASPI
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=50; -- PAJARON, JESSA ABEJO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=51; -- POLINAR, JINKY RUGAY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=567.73, `basic_pay`=12490.06, `ot_rate`=92.26 WHERE `id`=52; -- RAGANDANG, CAROL CUBING
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('LIAISON/MESSENGER') ORDER BY `id` LIMIT 1), `salary`=589.00, `basic_pay`=12958.00, `ot_rate`=95.71 WHERE `id`=53; -- REBUCAS, ARCHIE LOPERA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=570.80, `basic_pay`=12557.60, `ot_rate`=92.76 WHERE `id`=54; -- ROMULO, MARIA KATHERINE CALLAO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('DATA ENGINEER') ORDER BY `id` LIMIT 1), `salary`=909.09, `basic_pay`=20000.00, `ot_rate`=147.73 WHERE `id`=55; -- ROQUE, CLAUDE VAN CALONZO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=589.00, `basic_pay`=12958.00, `ot_rate`=95.71 WHERE `id`=56; -- ROSALINA, CECILE RUGAY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF COMPLIANCE OFFICER') ORDER BY `id` LIMIT 1), `salary`=3181.82, `basic_pay`=70000.00, `ot_rate`=517.05 WHERE `id`=57; -- RUGAY, WILMA POLLEY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HOSPITAL PRESIDENT') ORDER BY `id` LIMIT 1), `salary`=4545.45, `basic_pay`=100000.00, `ot_rate`=738.64 WHERE `id`=58; -- SERIÑA, MARIA MERCEDITAS A.
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=780.24, `basic_pay`=17165.25, `ot_rate`=126.79 WHERE `id`=59; -- TORREFLORES, FELY JO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=200; -- UMAYA, JOELA MARIE AGOT
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CLERK') ORDER BY `id` LIMIT 1), `salary`=535.00, `basic_pay`=11770.00, `ot_rate`=86.94 WHERE `id`=60; -- YAMO, JEMARIE CABUNOC
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('BOARD SECRETARY') ORDER BY `id` LIMIT 1), `salary`=735.91, `basic_pay`=16190.00, `ot_rate`=119.59 WHERE `id`=61; -- YAP, YSIDORA SIAO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=62; -- ABAD, QUERWIN ELLARINA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=63; -- ABDON, KYRULL YUR ALCAYDE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=64; -- ABRINA, RAIZA AMOR CABANA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=65; -- ACUESTA, REBECCA VICKY CORTEL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=66; -- ACUÑA, RAMGIE SABOBO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=732.29, `basic_pay`=16110.42, `ot_rate`=119.00 WHERE `id`=238; -- ADANZA, ARNOLD SERRAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1081.00, `basic_pay`=23782.00, `ot_rate`=175.66 WHERE `id`=67; -- ADLAON, RISHA FE DELA PEÑA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=70; -- ALOZO, LUZLALAINE TOMAS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1359.00, `basic_pay`=29898.00, `ot_rate`=220.84 WHERE `id`=72; -- ANDALOC, MARIE JANE EGBUS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT') ORDER BY `id` LIMIT 1), `salary`=535.00, `basic_pay`=11770.00, `ot_rate`=86.94 WHERE `id`=75; -- ARAULA, LOVE JOY DANGATAO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=76; -- ARDIENTE, VERONICO JR. ARCAYENA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=77; -- AREVALO, JOAN SANDRA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=78; -- ATIENZA, SHEANA MAE TORAYNO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=79; -- ATIENZA, SHIELLA MARIE TORAYNO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=636.55, `basic_pay`=14004.10, `ot_rate`=103.44 WHERE `id`=80; -- AVELINO, IYLYN GEVERO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=82; -- BABUYO, RENIER JAMES ARUTA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('GENERAL SUPERVISOR') ORDER BY `id` LIMIT 1), `salary`=1195.00, `basic_pay`=26290.00, `ot_rate`=194.19 WHERE `id`=239; -- BACONGUIS, MARK BRYAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=83; -- BALABAG, SHIELA LIENE KINONTAO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=84; -- BANDIVAS, JOANA I.
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=86; -- BATOBALONOS, ANNIELYN LABOR
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1260.00, `basic_pay`=27720.00, `ot_rate`=204.75 WHERE `id`=87; -- BELACHO, VERNON CLYDE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1270.00, `basic_pay`=27940.00, `ot_rate`=206.38 WHERE `id`=91; -- BONSAYON, RUBY TUN-ANAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT') ORDER BY `id` LIMIT 1), `salary`=535.00, `basic_pay`=11770.00, `ot_rate`=86.94 WHERE `id`=92; -- CABALLES, MARIENNE VAYLE VALENZUELA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=96; -- CANEOS, KHARINE MAGBANUA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=533.00, `basic_pay`=11726.00, `ot_rate`=86.61 WHERE `id`=98; -- CARITAN, HEINTJEE TORREPALMA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=99; -- CARLOS IV, JOSE ANGELO MONDIGO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=100; -- CARO, MYRAFLOR SAGARAL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=512.00, `basic_pay`=11264.00, `ot_rate`=83.20 WHERE `id`=101; -- CASTRO, VINCENT LAUREANO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('INFECTION CONTROL NURSE') ORDER BY `id` LIMIT 1), `salary`=1145.00, `basic_pay`=25190.00, `ot_rate`=186.06 WHERE `id`=102; -- CENTILLAS, THERESA ARSENIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1280.00, `basic_pay`=28160.00, `ot_rate`=208.00 WHERE `id`=221; -- CUCHARO, VIERJOHN CAGADAS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=732.29, `basic_pay`=16110.42, `ot_rate`=119.00 WHERE `id`=103; -- DACALOS, ROGELIO PANGANIBAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=112; -- DEL PUERTO, CHERY LOU MOLINA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=113; -- DESOYO, JESSIE GIN DAYA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('ASST. CHIEF NURSE') ORDER BY `id` LIMIT 1), `salary`=1445.00, `basic_pay`=31790.00, `ot_rate`=234.81 WHERE `id`=114; -- DILLA , MA. KRISTINA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=115; -- DOBLE, JOANNA MAY ENCIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('SUPERVISOR') ORDER BY `id` LIMIT 1), `salary`=1451.27, `basic_pay`=31927.94, `ot_rate`=235.83 WHERE `id`=116; -- DOMINGO, MA.TERESA AGUILA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT') ORDER BY `id` LIMIT 1), `salary`=572.00, `basic_pay`=12584.00, `ot_rate`=92.95 WHERE `id`=117; -- DRAGON, JUVY MOZO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=119; -- ENGLATERA, DANICA JEAN ESPERO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('SUPERVISOR') ORDER BY `id` LIMIT 1), `salary`=1451.27, `basic_pay`=31927.94, `ot_rate`=235.83 WHERE `id`=121; -- ERESO, JANETH TADENA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT') ORDER BY `id` LIMIT 1), `salary`=535.00, `basic_pay`=11770.00, `ot_rate`=86.94 WHERE `id`=122; -- ESTOQUE, VANESSA MAE GAMBA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT') ORDER BY `id` LIMIT 1), `salary`=545.00, `basic_pay`=11990.00, `ot_rate`=88.56 WHERE `id`=242; -- ESTRELLA, RESTYGIE PACANA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=124; -- FLORES, JEE ANN CAINGIN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=127; -- GALARIDO, CHRISTINE JEANZELLE LABIS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=732.29, `basic_pay`=16110.38, `ot_rate`=119.00 WHERE `id`=128; -- GAMO, ZOSIMO LLEGUE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=129; -- GARIO, RECHELL  CASALTA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=130; -- GEVEROLA, GINA MAE SAPLINA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=131; -- GIL, CHARLES JONATHAN CUSTODIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=134; -- GONTIÑAS, DEANNE JASCHA PAILAGAO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=137; -- HONCULADA, CORD COVIE NILUAG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=138; -- IDULSA, ALFRED BARAGONA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('DIRECTOR OF NURSING') ORDER BY `id` LIMIT 1), `salary`=1604.91, `basic_pay`=35308.00, `ot_rate`=260.80 WHERE `id`=140; -- ILOGON, FE ANTIPAS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=143; -- JAMOROL, REJEY BLAYA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=146; -- LABADAN, IVY GRACE MAGNETICO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=148; -- LACAPAG, GRACELYN RAGANAS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT') ORDER BY `id` LIMIT 1), `salary`=510.00, `basic_pay`=11220.00, `ot_rate`=82.88 WHERE `id`=149; -- LADESMA, ZOJAILA JADRAQUE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=150; -- LAGBAS, JUSTICE OLIVEROS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=151; -- LAGURA, MECHELL HANDUGAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT') ORDER BY `id` LIMIT 1), `salary`=545.00, `basic_pay`=11990.00, `ot_rate`=88.56 WHERE `id`=243; -- LAUREANO, REYNA  MANIQUE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=561.00, `basic_pay`=12342.00, `ot_rate`=91.16 WHERE `id`=152; -- LEDESMA, VIRGELIO LEYROS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=154; -- LIBASTE, AILENE DAPOC
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=155; -- LIGAS, MARY JOY BUHAWI
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1335.00, `basic_pay`=29370.00, `ot_rate`=216.94 WHERE `id`=156; -- LINA-AC, PRINCESS DHANA DAPANAS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=159; -- LOPEZ, ERIC INCISO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT') ORDER BY `id` LIMIT 1), `salary`=543.00, `basic_pay`=11946.00, `ot_rate`=88.24 WHERE `id`=163; -- MALACAYA, MARICEL SABEJON
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=165; -- MEDEL, ELYNE FAVE TANTE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1195.00, `basic_pay`=26290.00, `ot_rate`=194.19 WHERE `id`=169; -- MORADO, BARBARA LOU PISLA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=170; -- MORDEN, ANGELICA DIANE DADANG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=171; -- MURING, JUSTINE MERLL SUMATRA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=623.00, `basic_pay`=13706.00, `ot_rate`=101.24 WHERE `id`=215; -- MUSA, JACQUILINE TABASAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=173; -- NAGUITA, TRIXIA MIKAELA BERGONIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=177; -- ORINTAR, JAMIL COMAGUL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=178; -- PACQUIAO, KENRICH MYLES BACULIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=180; -- PANGARUNGAN, AYNA SALIC
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=181; -- POQUIZ, SHALCITA MARIE MANGANTE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=182; -- POTTER, FRANCESCA MARIE MUTYA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=183; -- RADEN, ALFONSO LICAYAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1280.00, `basic_pay`=28160.00, `ot_rate`=208.00 WHERE `id`=245; -- RATILLA, KATHLEEN BLESS VARQUEZ
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=184; -- REMOLLO, IRENE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=185; -- SABELLA, JAYCEL AMOR
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=627.00, `basic_pay`=13794.00, `ot_rate`=101.89 WHERE `id`=218; -- SACALA, NIMPHA CHIONG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=187; -- SALAMERO, MAIZA HORMEGUERA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=188; -- SALVANE, LANCE ASEQUIA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=563.00, `basic_pay`=12386.00, `ot_rate`=91.49 WHERE `id`=190; -- SARITA, ANTONIO DELA TORRE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=191; -- SENINA, RENABER SUMAGANG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1260.00, `basic_pay`=27720.00, `ot_rate`=204.75 WHERE `id`=192; -- SULOG, JOHAIRIA D.
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=194; -- TACANDONG, JOHN RHEY ALQUIZA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=196; -- TORILLO, MARJORIE ANN  ABIAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=197; -- TORION, ZENN PAULINE MAHINAY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=198; -- TROCIO, JENINE SAHAY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=199; -- TUBEO, MEL CARLO  BELISARIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=201; -- VELEZ, IKE JOHN FERRAREN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=202; -- VILLAMOR, ERICHA MAGALLANES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=203; -- VILLARUZ, BLANCHE NICOLE CAMELLO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=562.00, `basic_pay`=12364.00, `ot_rate`=91.33 WHERE `id`=228; -- VITOR, MARK OLIVER RAGMAC
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=732.33, `basic_pay`=16111.25, `ot_rate`=119.00 WHERE `id`=229; -- YASAY, HERNANDO TORILLO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('NURSING ATTENDANT') ORDER BY `id` LIMIT 1), `salary`=555.00, `basic_pay`=12210.00, `ot_rate`=90.19 WHERE `id`=247; -- YEKE, ROBERT ALABADO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=206; -- YNGOC, AIMEE CRISTINA MARAAT
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('ECHO TECH') ORDER BY `id` LIMIT 1), `salary`=695.00, `basic_pay`=15290.00, `ot_rate`=112.94 WHERE `id`=210; -- DALOGDOG, TONI BLAISE ZILMAR
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD') ORDER BY `id` LIMIT 1), `salary`=1324.51, `basic_pay`=29139.21, `ot_rate`=215.23 WHERE `id`=211; -- SANTIAS, FE KISTERIA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=638.61, `basic_pay`=14049.42, `ot_rate`=103.77 WHERE `id`=212; -- GAYRAMARA, LELIA CARANZO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=105; -- DAGOHOY, DIANA JANE ONAGAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=108; -- DANIEL, DOVY JANE VELASQUEZ
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1260.00, `basic_pay`=27720.00, `ot_rate`=204.75 WHERE `id`=256; -- FACTURA, HANNAH MARIE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=636.55, `basic_pay`=14004.10, `ot_rate`=103.44 WHERE `id`=214; -- LAMPIOS, CONSOLACION CAGAS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=594.00, `basic_pay`=13068.00, `ot_rate`=96.53 WHERE `id`=216; -- PAGAL, IVY LAMBIGUIT
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=641.55, `basic_pay`=14114.10, `ot_rate`=104.25 WHERE `id`=217; -- REGIS, IMELDA SO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=809.34, `basic_pay`=17805.38, `ot_rate`=131.52 WHERE `id`=195; -- TAMPARONG, VIVIRA GALES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=623.00, `basic_pay`=13706.00, `ot_rate`=101.24 WHERE `id`=219; -- BARTON, ROBERT BUTALID
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=71; -- ANCIANO, VIA MAE LABIS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=95; -- CALO, NICA MONETTE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1220.00, `basic_pay`=26840.00, `ot_rate`=198.25 WHERE `id`=220; -- CANOY, NIEZTSCHE BAGABOYBOY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=107; -- DAL, JEUEL ARVI MANGAYAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=123; -- FABORADA, MARKLY CATIIL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=125; -- GADOT, MARY ALVY TABUD
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=126; -- GALACIO, TRISHA ANNE CAPITO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=132; -- GO, ANNA MAYE SABULBERO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=577.00, `basic_pay`=12694.00, `ot_rate`=93.76 WHERE `id`=222; -- KHO, RAPHY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=147; -- LABESORES, JAN ELMER LABIDE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=153; -- LEONES, MYZZAH GAYLE LAGO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=157; -- LIPAY, JOAN RUBY BENLOG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=162; -- MADROÑAL, JOSIAH RAQUIE ELAGO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=166; -- MERCADO, LOUISSE TRISHA EDUARTE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=167; -- MIGALBIO, JEAN DAGONDON
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1641.68, `basic_pay`=36117.00, `ot_rate`=266.77 WHERE `id`=224; -- NAVALES, REY BAJUYO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1210.00, `basic_pay`=26620.00, `ot_rate`=196.63 WHERE `id`=244; -- NERI, LORRAINE RANOLO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=225; -- RAGANDANG, DOREN RAGMAC
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1185.00, `basic_pay`=26070.00, `ot_rate`=192.56 WHERE `id`=226; -- TANGCALAGAN, JADE MARCIAL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AMBULANCE DRIVER') ORDER BY `id` LIMIT 1), `salary`=570.00, `basic_pay`=12540.00, `ot_rate`=92.63 WHERE `id`=227; -- TUBO, ALBEN CALUMBA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=207; -- ZACARIAS, REIGN MAE  BASILIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=209; -- ZULIETA, KIMBERLY ILLANA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1290.00, `basic_pay`=28380.00, `ot_rate`=209.63 WHERE `id`=230; -- ARTAJO, AGUSTO CESAR CENIZA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=81; -- BABIA, JEWEL ANGEL EMANO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=97; -- CANOY, ANÑA ISABELA LILI BAGABOYBOY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=120; -- ENGLATERA, JHON LYNDON ESPERO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=158; -- LLARENAS, CHRISTAL JEAN BACOR
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HDU TECHNICIAN') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=231; -- LONCION, LOIJE PABORES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('SUPERVISOR') ORDER BY `id` LIMIT 1), `salary`=1445.00, `basic_pay`=31790.00, `ot_rate`=234.81 WHERE `id`=232; -- MANALO, FRENCH JANE PASTOLERO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=164; -- MARATA, ANNA MARIE REMEDIOS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=172; -- NACUA, ANNA NIÑA ERICKA BALACUIT
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1230.00, `basic_pay`=27060.00, `ot_rate`=199.88 WHERE `id`=233; -- NACUA, REEVIN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=176; -- ORCULLO, GEREL ANGELA SANTOS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1210.00, `basic_pay`=26620.00, `ot_rate`=196.63 WHERE `id`=234; -- PABAYO, ELVIE BAHADE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1220.00, `basic_pay`=26840.00, `ot_rate`=198.25 WHERE `id`=235; -- PELIGRINO, ARNOLFO GABAY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HDU TECHNICIAN') ORDER BY `id` LIMIT 1), `salary`=689.00, `basic_pay`=15158.00, `ot_rate`=111.96 WHERE `id`=236; -- PEÑALOSA, NORBERTO RAGANDANG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=189; -- SANSON, JEDDAHLYNN KULOB
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HDU TECHNICIAN') ORDER BY `id` LIMIT 1), `salary`=562.00, `basic_pay`=12364.00, `ot_rate`=91.33 WHERE `id`=205; -- YAMOWAY, EDELITO MEJARES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1410.27, `basic_pay`=31025.94, `ot_rate`=229.17 WHERE `id`=237; -- ABANALES, KAREN MARTINEZ
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=74; -- APOSTOL, SWEETIE MAE HARNAIZ
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1220.00, `basic_pay`=26840.00, `ot_rate`=198.25 WHERE `id`=240; -- BALBIN, JANICA CES DACU
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1220.00, `basic_pay`=26840.00, `ot_rate`=198.25 WHERE `id`=241; -- BALOG, IREZ PANGANIBAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=89; -- BERNALDEZ, ALYSSA RYANNE ARRIOLA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=93; -- CADIZ, CHRISTINE LOU UBALDE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=567.00, `basic_pay`=12474.00, `ot_rate`=92.14 WHERE `id`=106; -- DAHILAN, CHARLES QUIDER
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=110; -- DELFIN, NENOCHE GRACE TABUAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1220.00, `basic_pay`=26840.00, `ot_rate`=198.25 WHERE `id`=118; -- ENDAB, MELROSE RICAFORTE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=136; -- HAMMOND, FRANCHESSKA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=139; -- IGAR, ANGEL BAHIAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=144; -- JANOLINO, HANS EXCEL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=145; -- KHALIL, AISHA REHAM MENOR
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=160; -- LUCAGBO, LIZA ORTIZ
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=174; -- NAMBATAC, JACKIELYN  TOYOR
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=565.00, `basic_pay`=12430.00, `ot_rate`=91.81 WHERE `id`=223; -- NAMOCATCAT, REYNALDO CAILING
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=175; -- NIEVA, ERIKA MAE CAGAS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=179; -- PADRINO, DYNAH LOU MOLLENO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=186; -- SABUERO, JESSA MAE TECSON
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=193; -- SUMICAD, APRIL SOPHIA AGOS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=246; -- TUBO, EUSEBIO MARIBAO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=564.00, `basic_pay`=12408.00, `ot_rate`=91.65 WHERE `id`=204; -- WABE, RENE QUIDLAT
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF RADTECH') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=248; -- EBOÑA, JUDY PAZ ENDAB
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('LIAISON') ORDER BY `id` LIMIT 1), `salary`=733.77, `basic_pay`=16142.85, `ot_rate`=119.24 WHERE `id`=249; -- ELLARINA, HENRY MALIG-ON
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('RADTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=250; -- HONTIVEROS, MARIA YIOVANNA ARNIVAL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('RADTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=251; -- RULIDA, JAY RYAN MURILLO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1325.00, `basic_pay`=29150.00, `ot_rate`=215.31 WHERE `id`=252; -- ABRIAM, AIZA MARIE VALMORES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=255; -- AMPER, SUGAY JY TUQUIB
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=733.68, `basic_pay`=16140.85, `ot_rate`=119.22 WHERE `id`=109; -- DELFIN, DANIEL ROBLES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=253; -- PARAGAS, JHERRA CELESTINE B.
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1210.00, `basic_pay`=26620.00, `ot_rate`=196.63 WHERE `id`=254; -- ROSENDO, IMY DADANG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD NURSE') ORDER BY `id` LIMIT 1), `salary`=1395.00, `basic_pay`=30690.00, `ot_rate`=226.69 WHERE `id`=213; -- JANAO, CHRISTINE ANGELI ANGGAM
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=69; -- ALINSUB, ERIKA SAMILLANO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=73; -- ANDOY, MARYLIT BUTRON
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=85; -- BARLISAN, CHRISTOPHER JORGE ETRONE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1210.00, `basic_pay`=26620.00, `ot_rate`=196.63 WHERE `id`=88; -- BELTIS, ERIKA SAGARIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=512.00, `basic_pay`=11264.00, `ot_rate`=83.20 WHERE `id`=90; -- BONGLAY, DEE JAY MARK OBALANG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=94; -- CAINGIN, MARIA COLLEN GALARRITA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=545.00, `basic_pay`=11990.00, `ot_rate`=88.56 WHERE `id`=104; -- DAGASDAS, GLENN IDIANG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=111; -- DELICANA, NORLIE BERING
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=133; -- GOMEZ, ROLA SHEEN  CABALLES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=135; -- GUINTO, URIAH KISHA ANGELA  BACALA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=142; -- IWANAGA, KIHO CADIZ
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=257; -- JIMENEZ, ALAN JED MANTA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=161; -- MACAPUNDAG, QUEEN CZARINA BACARAT
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=562.00, `basic_pay`=12364.00, `ot_rate`=91.33 WHERE `id`=258; -- MALILONG, JOSEPH TABALON
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF NURSE') ORDER BY `id` LIMIT 1), `salary`=1095.00, `basic_pay`=24090.00, `ot_rate`=177.94 WHERE `id`=168; -- MOLEJON, AYESSA MONIQUE PALMES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('ENDOSCOPY AIDE') ORDER BY `id` LIMIT 1), `salary`=567.00, `basic_pay`=12474.00, `ot_rate`=92.14 WHERE `id`=259; -- PAHUNANG, REYMOND BONGOLTO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('WARDMAN') ORDER BY `id` LIMIT 1), `salary`=555.00, `basic_pay`=12210.00, `ot_rate`=90.19 WHERE `id`=260; -- PARREÑO, KLIFF ARAZO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('SUPERVISOR') ORDER BY `id` LIMIT 1), `salary`=1610.81, `basic_pay`=35437.82, `ot_rate`=261.76 WHERE `id`=208; -- ZACARIAS, ROWENA BASILIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=745.38, `basic_pay`=16398.35, `ot_rate`=121.12 WHERE `id`=261; -- BANAAG, RACQUEL ANDOY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=748.88, `basic_pay`=16475.41, `ot_rate`=121.69 WHERE `id`=262; -- CALIAO, MARLOU ESPARAGO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=263; -- CAMPION, JOBERT CORPUZ
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=264; -- COSTINAR, CHARISH VAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('PHARMACIST I') ORDER BY `id` LIMIT 1), `salary`=1175.36, `basic_pay`=25858.00, `ot_rate`=191.00 WHERE `id`=265; -- DAMASING, MIKEE LOU FLORES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=266; -- DUBLADO, EDU CALOPE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE/STOCKMAN') ORDER BY `id` LIMIT 1), `salary`=567.00, `basic_pay`=12474.00, `ot_rate`=92.14 WHERE `id`=267; -- OCAT, ROEL BANGGO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('PHARMACIST II') ORDER BY `id` LIMIT 1), `salary`=1059.45, `basic_pay`=23308.00, `ot_rate`=172.16 WHERE `id`=268; -- SANCHEZ, JAYD
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=269; -- VILLANUEVA, EUNICE PEREA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=737.47, `basic_pay`=16224.24, `ot_rate`=119.84 WHERE `id`=270; -- YECYEC, JUDITH IDULSA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=567.00, `basic_pay`=12474.00, `ot_rate`=92.14 WHERE `id`=271; -- ZAPANTA, MARILOU GUILLANO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD/PCO') ORDER BY `id` LIMIT 1), `salary`=940.18, `basic_pay`=20684.00, `ot_rate`=152.78 WHERE `id`=272; -- ABALES, ESTER BARANDA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=747.41, `basic_pay`=16442.95, `ot_rate`=121.45 WHERE `id`=273; -- BABUYO, RENERIO CABUGSA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=562.00, `basic_pay`=12364.00, `ot_rate`=91.33 WHERE `id`=274; -- BENGAR, VINGIE BANGALAO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=275; -- DAMAS JR, MARIO BAGUIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF ROD') ORDER BY `id` LIMIT 1), `salary`=3067.20, `basic_pay`=67478.50, `ot_rate`=498.42 WHERE `id`=276; -- CASINO, MARIA CECILIA GONZALES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('YAKAP CLINIC DOCTOR') ORDER BY `id` LIMIT 1), `salary`=2923.76, `basic_pay`=64322.66, `ot_rate`=475.11 WHERE `id`=277; -- DAJAY, DARRELL JAY SUIZO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('ROD') ORDER BY `id` LIMIT 1), `salary`=3176.22, `basic_pay`=69876.74, `ot_rate`=516.14 WHERE `id`=278; -- DELA RIARTE, MA. LYRA CERNA DELA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('ROD') ORDER BY `id` LIMIT 1), `salary`=2766.27, `basic_pay`=60858.00, `ot_rate`=449.52 WHERE `id`=279; -- EJERA, SENECA GADRINAB
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('ROD') ORDER BY `id` LIMIT 1), `salary`=2843.00, `basic_pay`=62546.00, `ot_rate`=461.99 WHERE `id`=280; -- LABIS, CECILLE MAE TORRES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('ROD') ORDER BY `id` LIMIT 1), `salary`=2937.13, `basic_pay`=64616.82, `ot_rate`=477.28 WHERE `id`=281; -- MADRIDONDO, LEOMER CAMANCHE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('ROD') ORDER BY `id` LIMIT 1), `salary`=2789.27, `basic_pay`=61364.00, `ot_rate`=453.26 WHERE `id`=282; -- SARITA, ANTONETTE FAITH BUENAVENTURA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=283; -- ABLAY, LOUIE ESTANO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('ASST. DIETITIAN') ORDER BY `id` LIMIT 1), `salary`=814.00, `basic_pay`=17908.00, `ot_rate`=132.28 WHERE `id`=284; -- ALFORQUE, KIZZA YVONNE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('COOK') ORDER BY `id` LIMIT 1), `salary`=614.00, `basic_pay`=13508.00, `ot_rate`=99.78 WHERE `id`=285; -- BACAYAN, HENRICKSON LAURETA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=286; -- BOLTRON, JOHN LOUIE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('COOK') ORDER BY `id` LIMIT 1), `salary`=544.00, `basic_pay`=11968.00, `ot_rate`=88.40 WHERE `id`=287; -- BOYLES, DAISY UMBAL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=288; -- CASTRO, MARVIN LAUREANO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF DIETITIAN') ORDER BY `id` LIMIT 1), `salary`=814.00, `basic_pay`=17908.00, `ot_rate`=132.28 WHERE `id`=289; -- DUPITAS, NIEZELLE ESTANDARTE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=565.09, `basic_pay`=12431.98, `ot_rate`=91.83 WHERE `id`=290; -- GELLO-ANO, ANALIZA PACLIBAR
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=564.00, `basic_pay`=12408.00, `ot_rate`=91.65 WHERE `id`=291; -- JALAGAT, ALLAN OMELDA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=292; -- LOPEZ, RENRICK JAE INCISO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=522.00, `basic_pay`=11484.00, `ot_rate`=84.83 WHERE `id`=293; -- LUNA , MARIA LIECEL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=294; -- MARTINEZ, DARLITA LEBRIA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('COOK') ORDER BY `id` LIMIT 1), `salary`=724.09, `basic_pay`=15930.01, `ot_rate`=117.66 WHERE `id`=295; -- NAMOC, ROLANDO UGAYAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=296; -- PANTALLANO, ROGER PAHAY JR
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('AIDE/PURCHASER') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=297; -- SUSON, RODERICK OBSIOMA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MIDWIFE') ORDER BY `id` LIMIT 1), `salary`=545.00, `basic_pay`=11990.00, `ot_rate`=88.56 WHERE `id`=298; -- TURNO, MARY MIE SACALA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=535.00, `basic_pay`=11770.00, `ot_rate`=86.94 WHERE `id`=299; -- ABALDE, MARIA LYRA SALISE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=300; -- ACHACOSO, CATHERINE RAMIREZ
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=732.65, `basic_pay`=16118.38, `ot_rate`=119.06 WHERE `id`=346; -- AMOGUIS, ALEJANDRO BONSACAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=724.23, `basic_pay`=15933.00, `ot_rate`=117.69 WHERE `id`=302; -- BENIGA, EMMANUEL LLANTO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1) WHERE `id`=368; -- CARVAJAL, RAFFY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=522.00, `basic_pay`=11484.00, `ot_rate`=84.83 WHERE `id`=303; -- FABRO , JIED
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=304; -- GONZALES, JAMES GUMADA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1) WHERE `id`=364; -- GUIZONA, ALJUN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD') ORDER BY `id` LIMIT 1), `salary`=640.45, `basic_pay`=14090.00, `ot_rate`=104.07 WHERE `id`=306; -- MAGDALE, CRISNON OMPOC
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=308; -- MANAHAN, JOEMAR ZULITA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=732.29, `basic_pay`=16110.42, `ot_rate`=119.00 WHERE `id`=309; -- MANATAD, TEODIE JARDIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=311; -- MATIAS, RUBILYN SABANA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=313; -- PANIO, MC GIE BULLECER
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=315; -- RODRIGUEZ, ARNEL MIYAKE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=316; -- ROSALINA, ERNESTO QUEZON
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=317; -- SALCEDO, ALVIN OLIVERIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=318; -- SATUR, GABRIEL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1) WHERE `id`=366; -- TAÑAN, RAFFY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=875.00, `basic_pay`=19250.00, `ot_rate`=142.19 WHERE `id`=320; -- ALTERNADO, MAUREEN MOSQUEDA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=321; -- AMBAYEC, RESELLIE BALLESTEROS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=875.00, `basic_pay`=19250.00, `ot_rate`=142.19 WHERE `id`=301; -- ARANGGO, ARRIEL CARLJEN NOEL ARROGANTE
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('PHLEBOTOMIST') ORDER BY `id` LIMIT 1), `salary`=795.00, `basic_pay`=17490.00, `ot_rate`=129.19 WHERE `id`=322; -- BERENIO, VIVIAN CAGOYONG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=324; -- BESCO, AESCHYLLUS JOHN VALLECER
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=325; -- BULAHAN, GLAZE AMBER FLORES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=326; -- CALIT, JAN ELTSEN NARONA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HISTOTECH') ORDER BY `id` LIMIT 1), `salary`=815.72, `basic_pay`=17945.89, `ot_rate`=132.55 WHERE `id`=328; -- CAÑETE, BALTAZAR MENDOZA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=329; -- CAÑETE, THADDEUS ELTON CANIOS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=330; -- DALUPANG, ABDUL NAFFY SULTAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=332; -- ENCABO, JIEZL LOVE NOBIERES
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=333; -- HASSAN, FARHAN BANDRANG
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=334; -- LUCMAN, NORHANNAH MUSLIM
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('PHLEBOTOMIST') ORDER BY `id` LIMIT 1), `salary`=1048.21, `basic_pay`=23060.70, `ot_rate`=170.33 WHERE `id`=335; -- MACASIMBAR, HANIFAH DEROGONGAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HISTOTECH') ORDER BY `id` LIMIT 1), `salary`=708.00, `basic_pay`=15576.00, `ot_rate`=115.05 WHERE `id`=336; -- MAGTUBA, ESTRELLA CARREON
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=337; -- MANZON, DEN AUDREY ED BACABIS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('LAB. RECEPTIONIST') ORDER BY `id` LIMIT 1) WHERE `id`=365; -- NABO, RONALYN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=338; -- NAMO, LADY JOY DEL ROSARIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=875.00, `basic_pay`=19250.00, `ot_rate`=142.19 WHERE `id`=339; -- OMANDAM, GLEN ANGELA ZOSA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=340; -- OMANDAM, KARYZZA PEROCHO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=341; -- PAESTE, ABIGAIL ALO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=342; -- PANCHO, SHEILA DAYMIEL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=343; -- SORIANO, MV DEHN ANDRELLE N
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('MEDTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=344; -- UBALDE, CLARISSE FIDELYN SALVACION
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF MEDTECH') ORDER BY `id` LIMIT 1), `salary`=1345.66, `basic_pay`=29604.58, `ot_rate`=218.67 WHERE `id`=345; -- VILLANUEVA, MARY IRENE GEVERO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=732.34, `basic_pay`=16111.42, `ot_rate`=119.01 WHERE `id`=347; -- ARELLANO  JR., ISAIAS SEMILA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=348; -- BELLO, ARIES JUSHUA OCCENA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=565.00, `basic_pay`=12430.00, `ot_rate`=91.81 WHERE `id`=349; -- DOVERTE, NOEL PLAZA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=522.00, `basic_pay`=11484.00, `ot_rate`=84.83 WHERE `id`=350; -- ECUASION, DENNIS LINTOCAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=562.00, `basic_pay`=12364.00, `ot_rate`=91.33 WHERE `id`=351; -- ENDING , FIEL ENOJARDO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=563.00, `basic_pay`=12386.00, `ot_rate`=91.49 WHERE `id`=352; -- GALARRITA, RICHARD TACBAS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=353; -- SENDREJAS, ALFREDO ENRIQUEZ
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD') ORDER BY `id` LIMIT 1), `salary`=875.73, `basic_pay`=19266.06, `ot_rate`=142.31 WHERE `id`=354; -- TIBUDAN, JEREMY SANTOS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('RADTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=323; -- BERNALDEZ, ANGELA AALIYAH RICA ARRIOLA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('RADTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=355; -- CABANG, MARK KENNETH LUIGI G.
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('RADTECH') ORDER BY `id` LIMIT 1), `salary`=955.00, `basic_pay`=21010.00, `ot_rate`=155.19 WHERE `id`=327; -- CALLANTA, SETH OLIVER AGCOPRA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('RADTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=331; -- DIMAAMPAO, TARHATA GUIMBA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('RADTECH') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=356; -- LAGRA, ERIC JOHN NACARIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('RADTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=357; -- LITERATUS, ELAIZZA CANEOS
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('CHIEF RADTECH') ORDER BY `id` LIMIT 1), `salary`=995.00, `basic_pay`=21890.00, `ot_rate`=161.69 WHERE `id`=358; -- MAYUGA, DODIE KARL ALIVIO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('RADTECH') ORDER BY `id` LIMIT 1), `salary`=915.00, `basic_pay`=20130.00, `ot_rate`=148.69 WHERE `id`=359; -- MAONGCO, SHALIMAR M.
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('XRAY TECHNICIAN') ORDER BY `id` LIMIT 1), `salary`=1109.49, `basic_pay`=24408.77, `ot_rate`=180.29 WHERE `id`=360; -- PACON, ANECITA BONGHANOY
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('XRAY TECHNICIAN') ORDER BY `id` LIMIT 1), `salary`=1135.63, `basic_pay`=24983.77, `ot_rate`=184.54 WHERE `id`=361; -- PACON, NELMAR ESCAMILLAN
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('RESPIRATORY THERAPIST') ORDER BY `id` LIMIT 1), `salary`=1039.00, `basic_pay`=22858.00, `ot_rate`=168.84 WHERE `id`=68; -- ALFORQUE, VANNIE MAREEN ORNOPIA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD') ORDER BY `id` LIMIT 1), `salary`=539.00, `basic_pay`=11858.00, `ot_rate`=87.59 WHERE `id`=305; -- LINAAC, BERNARD SABUERRO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=310; -- MATIAS, RAYMOND SABANA
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=314; -- REGALADO, RONALD CERO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('HEAD') ORDER BY `id` LIMIT 1), `salary`=759.17, `basic_pay`=16701.66, `ot_rate`=123.37 WHERE `id`=307; -- MAHILOM, ZENITH MAGDADARO
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=560.00, `basic_pay`=12320.00, `ot_rate`=91.00 WHERE `id`=312; -- OSMEÑA, WILVIE FERENAL
UPDATE `employee` SET `position_id`=(SELECT `id` FROM `position` WHERE LOWER(`name`)=LOWER('STAFF') ORDER BY `id` LIMIT 1), `salary`=500.00, `basic_pay`=11000.00, `ot_rate`=81.25 WHERE `id`=319; -- VILLAVER, JUDITH

-- 5) VERIFY (dapat 0 ka row ang mo-gawas sa duha) --------------------
SELECT id, salary, basic_pay, ROUND(basic_pay/salary,4) ratio FROM employee
  WHERE salary>0 AND ABS(basic_pay/salary - 22) > 0.01;
SELECT id, salary, ot_rate FROM employee
  WHERE salary>0 AND ABS(ot_rate - salary/8*1.30) > 0.01;

-- COMMIT;   -- <== i-review ang verify queries sa taas usa mo-COMMIT
-- ROLLBACK; -- kung naa'y sayop
