-- Move the early-punch grace window out of the DTR_EARLY_GRACE_HOURS constant
-- and into pay_settings, so an administrator can tune it without a code edit.
--
-- The grace decides which tap becomes the day's time-in: a punch earlier than
-- (shift start - grace) is discarded as noise so it cannot steal the IN slot
-- from the real punch. It is NOT a pay rule — clocking in early already earns
-- nothing, because dtr_compute_day clamps eff_in to the shift start.
--
-- Read by dtr_early_grace_hours() (db_connect.php), which falls back to the
-- DTR_EARLY_GRACE_HOURS constant when this row is missing, so deploying the
-- PHP before this migration is safe.
--
-- WARNING: both ingestion (Action::save_biometric_attendance) and recompute
-- (dtr_compute_day) read this. Changing it takes effect immediately for NEW
-- scans, but existing rows keep their frozen figures until a Recompute is run
-- — and that recompute re-pairs them against the new value, which can restate
-- rows that were already correct. Lowering the value discards MORE punches.
INSERT INTO pay_settings (setting_key, setting_value, description)
VALUES ('dtr_early_grace_hours', 4,
        'Hours before shift start that a punch still counts as an early arrival; earlier taps are discarded as noise (does not affect pay — early time is never paid)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
