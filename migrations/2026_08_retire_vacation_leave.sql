-- Retire the unpaid "Vacation Leave" leave type (id 2).
--
-- It was payroll-identical to "Leave Without Pay" (both is_paid = 0, both
-- excluded from the paid leaveMap in calculate_payroll, both filed under the
-- same "File LWOP" button), so it added a duplicate picker entry with no
-- distinct behavior — while colliding with the PAID "Annual Leave" by name and
-- inviting employees to file unpaid time thinking it was their paid vacation.
--
-- Deactivated (status = 0) rather than DELETEd so the row survives for audit
-- and the change is reversible; this also matches delete_leave_type(), which
-- refuses to remove a type once any request references it.
--
-- Resulting structure — three types, each with distinct payroll behavior:
--   Annual Leave      paid,  credited, blocked at 0  → planned time off
--   Sick Leave        paid,  no_limit                 → unplanned illness
--   Leave Without Pay unpaid                          → everything else
--
-- Safe at time of writing: 0 leave_requests and 0 leave_credit_history rows
-- reference type 2. Rollback: see scratchpad rollback_vacation_leave.sql, or
--   UPDATE leave_types SET status = 1 WHERE id = 2;

UPDATE `leave_types` SET `status` = 0 WHERE `id` = 2;

-- Orphan credit row: credits on an unpaid type are never consumed or shown, so
-- this 5.0 would only resurface as noise in a future year-end rollover.
DELETE FROM `employee_leave_credits` WHERE `leave_type_id` = 2;
