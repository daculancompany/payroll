-- 1) Per-employee leave eligibility override.
--    NULL = auto (follow classification via LEAVE_ELIGIBLE_CLASSIFICATIONS),
--    1    = force allow (can file leave regardless of classification),
--    0    = force block (cannot file leave even if classification would allow).
ALTER TABLE employee
    ADD COLUMN leave_override TINYINT NULL DEFAULT NULL
    COMMENT 'NULL=auto(classification), 1=force allow, 0=force block';

-- 2) Manual add/deduct credit adjustments: record the action and the reason
--    alongside the existing old->new snapshot.
ALTER TABLE leave_credit_history
    ADD COLUMN change_type VARCHAR(10)  NOT NULL DEFAULT 'set' AFTER new_credits,  -- set | add | deduct
    ADD COLUMN reason      VARCHAR(255) NULL              AFTER change_type;
