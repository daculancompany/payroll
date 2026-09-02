-- Loan types: clean the two seed labels and widen the column.
--
-- The seed rows carried literal CR/LF inside the name ("SSS\r\nS-LOAN",
-- "HDMF\r\nMPL-LOA\r\nN" — the second truncated by varchar(30)), which showed up
-- broken in the Add Loan dropdown, the Active Loans table and payslips.
-- Loan types are now managed from Payroll → Active Loans → Loan Types.
--
-- Safe to re-run. Scoped by primary key.

ALTER TABLE contribution_loan_types MODIFY loan_type VARCHAR(100) NOT NULL;

UPDATE contribution_loan_types SET loan_type = 'SSS S-LOAN'    WHERE clt_id = 1;
UPDATE contribution_loan_types SET loan_type = 'HDMF MPL-LOAN' WHERE clt_id = 2;
