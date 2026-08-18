-- ── Duty Roster change history ─────────────────────────────────────────────
--
-- employee_day_schedule already carries created_by / changed_by / published_at,
-- but those only describe the row as it stands NOW. They cannot answer the
-- questions a scheduling office actually asks:
--
--   "Who took this nurse off Nights on the 12th?"
--   "This day is blank — was it never rostered, or did someone delete it?"
--   "Who published this cutoff, and when?"
--
-- A deleted row takes its own audit columns with it, so the only way to keep
-- that answer is to write the change to a separate append-only table.
--
-- One row per change. Cell-level actions carry employee_id + work_date; the
-- period-wide ones (publish, copy, discard, recompute, lock) leave those NULL
-- and describe their scope in `detail` instead.

CREATE TABLE IF NOT EXISTS duty_roster_log (
    id           INT(11)     NOT NULL AUTO_INCREMENT,
    period       VARCHAR(12) NOT NULL DEFAULT '',        -- "2026-08-2" cutoff half
    employee_id  INT(11)         NULL,                   -- NULL = period-wide action
    work_date    DATE            NULL,                   -- NULL = period-wide action
    action       VARCHAR(20) NOT NULL,                   -- create|update|delete|publish|copy|discard|recompute|import|lock|unlock
    -- Before/after of the two fields that decide what a day means. Kept as plain
    -- ints rather than a JSON blob so "every change to Nights in August" stays a
    -- normal indexed query.
    old_schedule_id   INT(11)     NULL,
    old_is_rest_day   TINYINT(1)  NULL,
    new_schedule_id   INT(11)     NULL,
    new_is_rest_day   TINYINT(1)  NULL,
    was_published     TINYINT(1) NOT NULL DEFAULT 0,     -- was the day already handed out when this happened?
    note         VARCHAR(255)     NULL,                  -- the cell note, when one was set
    detail       VARCHAR(255) NOT NULL DEFAULT '',        -- human sentence for the timeline
    user_id      INT(11)          NULL,                  -- users.id who did it
    created_at   TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    -- The drawer's two queries: one cell's history, and one cutoff's history.
    KEY idx_cell   (employee_id, work_date),
    KEY idx_period (period, created_at),
    KEY idx_user   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
