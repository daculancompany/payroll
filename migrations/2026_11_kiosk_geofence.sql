-- Site attendance area (geofence) — kiosk Phase C.
--
-- A site gains a centre point, a radius and a mode. A punch made with
-- coordinates is measured against its site's circle: how far out it was, and
-- whether that counts as outside.
--
--   off     no area set — distance is not measured, nothing is flagged
--   flag    the punch is recorded and marked "outside the area" for review
--   strict  a punch from outside the area is REFUSED
--
-- 'flag' is the one to start with: it tells you what your real radius should
-- be before it starts turning people away. 'strict' only after the flagged
-- punches look right, because a wrong radius in strict mode stops a whole
-- site from clocking in.
--
-- The kiosk app already understands this: it reads {latitude, longitude,
-- radius_m, mode, branch_name} from the heartbeat and enforces strict mode on
-- the device too. The server still re-checks every punch — the device's copy
-- is for the message it shows, never the decision.
--
-- Additive and re-runnable (MariaDB 10.4 local / 11.8 live).

-- ── The area itself, on the site ──────────────────────────────────────────
ALTER TABLE sites
    ADD COLUMN IF NOT EXISTS latitude          DECIMAL(10,7) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS longitude         DECIMAL(10,7) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS geofence_radius_m INT(11)       NULL DEFAULT NULL COMMENT 'metres from the centre that count as on site',
    ADD COLUMN IF NOT EXISTS geofence_mode     VARCHAR(10)   NOT NULL DEFAULT 'off' COMMENT 'off | flag | strict',
    ADD COLUMN IF NOT EXISTS geofence_note     VARCHAR(255)  NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS geofence_set_by   INT(11)       NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS geofence_set_at   DATETIME      NULL DEFAULT NULL;

-- ── What the measurement found, kept with the punch ───────────────────────
-- distance_m is stored even when the punch is inside: it is the only way to
-- tell afterwards whether a radius is too tight or far too generous.
ALTER TABLE kiosk_punches
    ADD COLUMN IF NOT EXISTS distance_m       INT(11)     NULL DEFAULT NULL COMMENT 'metres from the site centre at punch time',
    ADD COLUMN IF NOT EXISTS outside_geofence TINYINT(1)  NULL DEFAULT NULL COMMENT '1 = outside the area, NULL = not measured',
    ADD COLUMN IF NOT EXISTS source           VARCHAR(10) NOT NULL DEFAULT 'kiosk' COMMENT 'kiosk | mobile (employee punching on their own phone)';

ALTER TABLE kiosk_punches
    ADD INDEX IF NOT EXISTS idx_kiosk_punches_outside (outside_geofence);
