-- Half-day leave on a specific day of a multi-day request.
-- Before this, a half-day request was always a single date, so date_from WAS
-- the half day. Now an employee can file e.g. Mon–Wed with the half falling on
-- the first or last day; half_date records exactly which day is the half.
-- duration is stored as (number of days − 0.5) for half-day requests.
ALTER TABLE leave_requests
    ADD COLUMN half_date DATE NULL AFTER half_period;

-- Backfill legacy single-date half-day requests: their half day is date_from.
UPDATE leave_requests SET half_date = date_from WHERE is_half_day = 1 AND half_date IS NULL;
