-- Per-leave-type year-end policy: reset vs carry-over (with optional cap).
ALTER TABLE leave_types
    ADD COLUMN carryover     TINYINT      NOT NULL DEFAULT 0
        COMMENT '0=reset to days_allowed each year, 1=carry unused credits into next year',
    ADD COLUMN carryover_cap DECIMAL(6,1) NULL
        COMMENT 'max days carried over when carryover=1; NULL = no cap';

-- Track leave credits per calendar year so each year is separate + auditable.
ALTER TABLE employee_leave_credits
    ADD COLUMN year INT NOT NULL DEFAULT 0 AFTER leave_type_id;

-- Assign existing balances to the current leave year, then re-key uniqueness by year.
UPDATE employee_leave_credits SET year = YEAR(CURDATE()) WHERE year = 0;
ALTER TABLE employee_leave_credits DROP INDEX uq_emp_type;
ALTER TABLE employee_leave_credits ADD UNIQUE KEY uq_emp_type_year (employee_id, leave_type_id, year);
