-- Loans had a single `loan_date` doing double duty: the date the loan was
-- granted AND the gate that decides whether a payroll period deducts it
-- (admin_class.php:3062 and :3325 skip a loan when loan_date > date_to).
-- A loan granted mid-July therefore silently deducts nothing from a June
-- payroll even when the amortization was meant to start earlier.
--
-- Split the two concerns, mirroring employee_deductions.effective_date
-- ("First deduction date"). NULL means "same as loan_date", so every existing
-- loan keeps its current behavior and the field is optional on the form.

ALTER TABLE `loans`
    ADD COLUMN `effective_date` DATE NULL DEFAULT NULL AFTER `loan_date`;
