-- Per-user appearance settings for the v2 UI (theme, colour theme, accent,
-- text & display, accessibility) plus the classic/v2 shell choice.
-- Written by Action::save_ui_settings, read by includes/v2/bootstrap.php.
-- LONGTEXT + JSON_VALID check: works on MariaDB 10.4 (local) and 11.8 (live).
-- Safe to re-run.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS settings LONGTEXT NULL
        CHECK (settings IS NULL OR JSON_VALID(settings));
