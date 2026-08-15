-- 2026_08_users_employee_link.sql
-- Ang self-skip rule (walay makaaprub sa kaugalingon niyang leave) kinahanglan
-- mahibalo kung ang user nga mag-approve mao ra ang empleyado nga nag-file.
-- Walay link ang `users` ug `employee` kaniadto — mao ni siya.
-- NULL = ang account dili empleyado (pananglitan ang consultant nga doktor).

START TRANSACTION;
ALTER TABLE `users` ADD COLUMN `employee_id` INT(11) NULL DEFAULT NULL AFTER `department_id`;
ALTER TABLE `users` ADD KEY `idx_employee` (`employee_id`);
ALTER TABLE `users` ADD CONSTRAINT `users_emp_fk` FOREIGN KEY (`employee_id`) REFERENCES `employee`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

UPDATE `users` SET `employee_id`=230 WHERE `username`='agusto.artajo'; -- ARTAJO, AGUSTO CESAR CENIZA
UPDATE `users` SET `employee_id`=252 WHERE `username`='aiza.abriam'; -- ABRIAM, AIZA MARIE VALMORES
UPDATE `users` SET `employee_id`=169 WHERE `username`='barbara.morado'; -- MORADO, BARBARA LOU P.
UPDATE `users` SET `employee_id`=305 WHERE `username`='bernard.linaac'; -- BERNARD LINAAC II
UPDATE `users` SET `employee_id`=213 WHERE `username`='christine.janao'; -- JANAO, CHRISTINE ANGELI A.
UPDATE `users` SET `employee_id`=306 WHERE `username`='crisnon.magdale'; -- CRISNON MAGDALE
UPDATE `users` SET `employee_id`=9 WHERE `username`='darryl.apolinario'; -- DARRYL JOHN APOLINARIO
UPDATE `users` SET `employee_id`=358 WHERE `username`='dodie.mayuga'; -- MAYUGA, DODIE KARL A.
UPDATE `users` SET `employee_id`=27 WHERE `username`='edwin.delossantosjr'; -- DR. DELOS SANTOS, EDWIN
UPDATE `users` SET `employee_id`=272 WHERE `username`='ester.abales'; -- ESTER ABALES
UPDATE `users` SET `employee_id`=140 WHERE `username`='fe.ilogon'; -- ILOGON, FE A.
UPDATE `users` SET `employee_id`=211 WHERE `username`='fe.santias'; -- SANTIAS, FE
UPDATE `users` SET `employee_id`=232 WHERE `username`='french.manalo'; -- MANALO, FRENCH JANE
UPDATE `users` SET `employee_id`=256 WHERE `username`='hannah.factura'; -- FACTURA, HANNAH MARIE M.
UPDATE `users` SET `employee_id`=28 WHERE `username`='hr-head'; -- MARIA SCICHOLLONE B. DOPLON
UPDATE `users` SET `employee_id`=121 WHERE `username`='janeth.ereso'; -- ERESO, JANETH T.
UPDATE `users` SET `employee_id`=192 WHERE `username`='johairia.sulog'; -- SULOG, JOHAIRA D.
UPDATE `users` SET `employee_id`=248 WHERE `username`='judy.ebona'; -- EBOÑA, JUDY PAZ E.
UPDATE `users` SET `employee_id`=237 WHERE `username`='karen.abanales'; -- ABANALES, KAREN MARTINEZ
UPDATE `users` SET `employee_id`=245 WHERE `username`='kathleen.ratilla'; -- RATILLA, KATHLEEN BLESS V.
UPDATE `users` SET `employee_id`=116 WHERE `username`='ma.domingo'; -- DOMINGO, MA TERESA
UPDATE `users` SET `employee_id`=276 WHERE `username`='maria.casino'; -- CASIÑO, MARIA CECILIA GONZALES
UPDATE `users` SET `employee_id`=58 WHERE `username`='maria.serina'; -- DR. MARIA MERCEDITAS A. SERIÑA
UPDATE `users` SET `employee_id`=72 WHERE `username`='marie.andaloc'; -- ANDALOC, MARIE JANE E.
UPDATE `users` SET `employee_id`=239 WHERE `username`='mark.baconguis'; -- BACONGUIS, MARK BRYAN
UPDATE `users` SET `employee_id`=345 WHERE `username`='mary.villanueva'; -- VILLANUEVA, MARY IRENE
UPDATE `users` SET `employee_id`=265 WHERE `username`='mikee.damasing'; -- DAMASING, MIKEE LOU
UPDATE `users` SET `employee_id`=289 WHERE `username`='niezelle.dupitas'; -- NIEZELLE DUPITAS
UPDATE `users` SET `employee_id`=46 WHERE `username`='oliva.monteros'; -- MONTEROS, OLIVA
UPDATE `users` SET `employee_id`=18 WHERE `username`='perlita.blanco'; -- BLANCO, PERLITA
UPDATE `users` SET `employee_id`=156 WHERE `username`='princess.linaac'; -- LINA-AC, PRINCESS DHANA D.
UPDATE `users` SET `employee_id`=222 WHERE `username`='raphy.kho'; -- DR. ANNE CHRISTINE CO-KHO
UPDATE `users` SET `employee_id`=224 WHERE `username`='rey.navales'; -- NAVALES, REY BAJUYO
UPDATE `users` SET `employee_id`=208 WHERE `username`='rowena.zacarias'; -- ZACARIAS, ROWENA B.
UPDATE `users` SET `employee_id`=91 WHERE `username`='ruby.bonsayon'; -- BONSAYON, RUBY T.
UPDATE `users` SET `employee_id`=20 WHERE `username`='rufa.broces'; -- BROCES, RUFA
UPDATE `users` SET `employee_id`=37 WHERE `username`='stephanie.jacutin'; -- DR. STEPHANIE JACUTIN
UPDATE `users` SET `employee_id`=24 WHERE `username`='trile.carpio'; -- TRILE M. CARPIO
UPDATE `users` SET `employee_id`=5 WHERE `username`='vanisa.actub'; -- VANISA C. ACTUB
UPDATE `users` SET `employee_id`=87 WHERE `username`='vernon.belacho'; -- BELACHO, VERNON CLYDE N.
UPDATE `users` SET `employee_id`=221 WHERE `username`='vierjohn.cucharo'; -- CUCHARO, VIERJOHN C.
UPDATE `users` SET `employee_id`=57 WHERE `username`='wilma.rugay'; -- RUGAY, WILMA P.
UPDATE `users` SET `employee_id`=307 WHERE `username`='zenith.mahilom'; -- ZENITH MAHILOM

SELECT COUNT(*) naay_link, SUM(employee_id IS NULL) walay_link FROM users;
-- COMMIT;
