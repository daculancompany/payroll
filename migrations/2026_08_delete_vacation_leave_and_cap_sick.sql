-- 1) Hard-delete the retired "Vacation Leave" type (id 2).
--    It was deactivated by 2026_08_retire_vacation_leave.sql; this removes the
--    row entirely now that it is confirmed unreferenced:
--      leave_requests         = 0
--      employee_leave_credits = 0
--      leave_credit_history   = 0
--    Verify again before re-running elsewhere — delete_leave_type() refuses to
--    drop a type once any request points at it, and this bypasses that guard.
--
-- 2) Turn OFF `no_limit` on Sick Leave — it is no longer freely fileable
--    without credits. Sick Leave now behaves like Annual Leave: blocked once
--    the balance reaches 0.
--
--    IMPACT: 329 eligible employees currently have no Sick Leave credit row for
--    2026, so they are blocked from filing sick leave until credits are set.
--    Use "Initialize Missing Credits" on the Leave Balances page to seed them
--    all to the type's 15-day default in one pass.
--
--    Reverting: UPDATE leave_types SET no_limit = 1 WHERE id = 1;

DELETE FROM `leave_types` WHERE `id` = 2;

UPDATE `leave_types` SET `no_limit` = 0 WHERE `id` = 1;
