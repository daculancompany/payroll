-- Per-leave-type "open to all classifications" flag.
--
-- Leave filing is gated per EMPLOYEE (LEAVE_ELIGIBLE_CLASSIFICATIONS: Regular +
-- Executive), so a Probationary or Interm employee cannot file anything at all.
-- OFFICIAL BUSINESS is not a credit-consuming absence — it authorises being out
-- on company work — so it has to be fileable by everyone. This flag says so per
-- type, and HR/Admin toggle it on the Leave Types screen.
--
-- OB is also switched to no_limit: it has days_allowed = 0 and no credit rows
-- outside Regular, so the balance guard would otherwise reject every newly
-- allowed employee at 0 days. Both flags stay editable in the UI.
ALTER TABLE `leave_types`
    ADD COLUMN `open_to_all` TINYINT(1) NOT NULL DEFAULT 0 AFTER `no_limit`;

UPDATE `leave_types` SET `open_to_all` = 1, `no_limit` = 1 WHERE `name` = 'OFFICIAL BUSINESS';
