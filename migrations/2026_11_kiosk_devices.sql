-- Kiosk Devices page: columns the heartbeat alone cannot supply.
--
-- Installing a kiosk needs NO approval — a tablet signs in with a timekeeper
-- account and works immediately. `status` therefore defaults to 'active'; it
-- exists so a LOST or RETIRED tablet can be shut out afterwards, which is the
-- only case where a device should ever be refused.
--
-- Additive and safe to re-run. kiosk_devices itself is created by
-- 2026_11_kiosk_api.sql, which must run first.

ALTER TABLE kiosk_devices
    ADD COLUMN IF NOT EXISTS status VARCHAR(10) NOT NULL DEFAULT 'active'
        COMMENT 'active | blocked — blocked refuses every call but health',
    ADD COLUMN IF NOT EXISTS operator_user_id INT(11) NULL DEFAULT NULL
        COMMENT 'users.id of the timekeeper whose sign-in this tablet is using',
    ADD COLUMN IF NOT EXISTS blocked_by INT(11) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS blocked_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS note VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'why it was blocked, or where the tablet lives';

ALTER TABLE kiosk_devices
    ADD INDEX IF NOT EXISTS idx_kiosk_devices_status (status);
