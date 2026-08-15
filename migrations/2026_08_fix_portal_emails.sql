-- 2026_08_fix_portal_emails.sql
-- Giayo ang employee.portal_username, nga sirang kopya sa tinuod nga login
-- email sa `employee_portal_accounts.username` (mao'y authoritative — mao'y
-- gigamit sa portal login ug gibasa sa admin_class.php:2938).
--
-- 1) 36 ka row nga wala mag-sync -> kuhaon gikan sa employee_portal_accounts.
--    Naayo niini: 5 ka peke nga email, ang nagbaylo nga ATIENZA magsuon,
--    ang 'jiedfabro@gmail .com' nga naay espasyo, 5 ka @hospital.local
--    placeholder, ug 8 ka NULL.
-- 2) 5 ka row nga peke sa DUHA ka table -> i-NULL; walay tinuod bisan asa,
--    kinahanglan mangayo ang HR sa tinuod nilang email.
--
-- WALA gihilabtan ang employee_portal_accounts — dili maapektuhan ang login.

START TRANSACTION;

-- ROLLBACK SNAPSHOT ---------------------------------------------------
DROP TABLE IF EXISTS `employee_email_backup_2026_08`;
CREATE TABLE `employee_email_backup_2026_08` AS
  SELECT id, portal_username FROM employee;

-- 1) SYNC gikan sa employee_portal_accounts ---------------------------
--    Duha ka pass: i-NULL usa, dayon i-set. Kay UNIQUE ang portal_username,
--    ug naay nagbayloay nga values (BUENAVIDES<->BULAHAN, ATIENZA magsuon),
--    mag-collide kini kung usa ra ka UPDATE ang gamiton.
UPDATE `employee` SET `portal_username`=NULL WHERE `id` IN (8,21,45,78,79,89,90,94,107,117,118,119,177,212,218,219,227,283,293,294,298,303,317,325,339,344,347,352,363,364,365,366,367,368,369,370);

UPDATE `employee` e
  JOIN `employee_portal_accounts` a ON a.`employee_id` = e.`id`
  SET e.`portal_username` = a.`username`
  WHERE e.`id` IN (8,21,45,78,79,89,90,94,107,117,118,119,177,212,218,219,227,283,293,294,298,303,317,325,339,344,347,352,363,364,365,366,367,368,369,370);

-- 2) I-CLEAR ang peke nga walay tinuod nga kapuli ----------------------
UPDATE `employee` SET `portal_username`=NULL WHERE `id`=9; -- APOLINARIO, DARRYL — 'cristinealmonia1952@' kay email ni ALMONIA + '2'
UPDATE `employee` SET `portal_username`=NULL WHERE `id`=111; -- DELICANA, NORLIE — 'dovyjanedaniel2@' kay email ni DANIEL + '2'
UPDATE `employee` SET `portal_username`=NULL WHERE `id`=225; -- RAGANDANG, DOREN — 'carolragandang072@' kay email ni RAGANDANG, CAROL + '2'
UPDATE `employee` SET `portal_username`=NULL WHERE `id`=310; -- MATIAS, RAYMOND — 'yamsonmerlie8542@' kay email ni MARTINEZ + '2'
UPDATE `employee` SET `portal_username`=NULL WHERE `id`=323; -- BERNALDEZ, ANGELA — 'angelarica20012@' kay email ni BERNALDEZ, ALYSSA + '2'

-- VERIFY --------------------------------------------------------------
-- (a) dapat 0: walay nahibiling wala mag-sync gawas sa 5 nga gi-clear
SELECT e.id, e.portal_username, a.username FROM employee e
  JOIN employee_portal_accounts a ON a.employee_id=e.id
  WHERE LOWER(IFNULL(a.username,'')) <> LOWER(IFNULL(e.portal_username,''))
    AND e.id NOT IN (9,111,225,310,323);

-- (b) dapat 5 ra: ang gi-clear, nga hulaton ang tinuod nga email gikan sa HR
SELECT id, CONCAT(lastname,', ',firstname) nm, portal_username
  FROM employee WHERE portal_username IS NULL;

-- (c) dapat 0: walay email nga nagsugod sa email sa lain + digit
SELECT a.id, a.employee_id, a.username FROM employee_portal_accounts a
  JOIN employee_portal_accounts b ON b.id<>a.id
   AND a.username = CONCAT(SUBSTRING_INDEX(b.username,'@',1), RIGHT(SUBSTRING_INDEX(a.username,'@',1),1), '@', SUBSTRING_INDEX(b.username,'@',-1))
  ORDER BY a.employee_id;

-- COMMIT;   -- <== i-review ang verify sa taas usa mo-COMMIT
-- ROLLBACK;
