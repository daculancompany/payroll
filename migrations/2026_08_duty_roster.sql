-- Per-day duty roster — the cutoff schedule sheet for rotating staff (nurses).
--
-- WHY A NEW TABLE INSTEAD OF MORE employee_schedules ROWS
-- employee_schedules answers "which shift is this employee on, from when to
-- when", with rest days as a CSV of WEEKDAY numbers. That model is exactly
-- right for fixed staff (8-5, off Sat+Sun) and stays the default. It cannot
-- express a rotation:
--   1. A rotating nurse's day off MOVES — it is day 5 of a 6-day cycle, not
--      "every Saturday". A weekday CSV has no way to say that.
--   2. A shift that changes every 2 days would need ~15 period rows per nurse
--      per cutoff, each one splitting the covering period. The roster would be
--      mostly churn, and the stale-schedule detector would fire constantly.
--
-- So the rotation gets its own grain: ONE ROW PER EMPLOYEE PER DAY, painted on
-- a cutoff grid. Employees with no rows here are untouched — the resolver falls
-- straight back to employee_schedules, so nothing about fixed staff changes.
--
-- schedule_id NULL = a rest day the planner painted without naming a shift.
-- The resolver still needs shift BOUNDARIES for such a day (someone who punches
-- on their day off earns overtime measured against a shift end), so it falls
-- back to the employee_schedules shift for the times and only forces the
-- rest-day flag. is_rest_day is therefore the authoritative flag, not a
-- derived convenience.
--
-- status: 0 = draft, 1 = published. DRAFT ROWS ARE INVISIBLE TO EVERYTHING —
-- resolve_employee_schedule and the mismatch detector both filter status=1.
-- This is what lets a head nurse build next cutoff's sheet over several days
-- without half-finished rotations leaking into DTR or the employee portal.
--
-- planned_* keeps the value as FIRST published. Hospitals swap duties
-- constantly and later need to answer "who changed this, and from what" —
-- comparing planned_schedule_id against schedule_id shows the swap without a
-- separate history table.

CREATE TABLE IF NOT EXISTS employee_day_schedule (
    id                  INT(11)      NOT NULL AUTO_INCREMENT,
    employee_id         INT(11)      NOT NULL,
    work_date           DATE         NOT NULL,
    schedule_id         INT(11)      NULL,
    is_rest_day         TINYINT(1)   NOT NULL DEFAULT 0,
    planned_schedule_id INT(11)      NULL,
    planned_is_rest_day TINYINT(1)   NULL,
    status              TINYINT(1)   NOT NULL DEFAULT 0,
    note                VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
    created_by          INT(11)      NULL,
    changed_by          INT(11)      NULL,
    published_at        DATETIME     NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
    changed_at          TIMESTAMP    NULL DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (id),
    -- One answer per employee per day. The grid upserts against this key, so a
    -- double-click or a re-published cutoff can never stack duplicate days —
    -- which would make resolve_employee_schedule's answer depend on row order.
    UNIQUE KEY uniq_emp_day (employee_id, work_date),
    -- The resolver's hot path is (employee_id, work_date, status); the grid and
    -- the coverage counts read a whole date range at once.
    KEY idx_date_status (work_date, status),
    KEY idx_schedule (schedule_id),
    KEY idx_changed_by (changed_by),
    CONSTRAINT eds_employee_fk FOREIGN KEY (employee_id) REFERENCES employee (id)        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT eds_schedule_fk FOREIGN KEY (schedule_id) REFERENCES work_schedules (id)  ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT eds_planned_fk  FOREIGN KEY (planned_schedule_id) REFERENCES work_schedules (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT eds_changedby_fk FOREIGN KEY (changed_by) REFERENCES users (id)           ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
