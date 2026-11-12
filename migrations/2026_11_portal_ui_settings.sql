-- Per-employee appearance settings for the employee portal (theme, colour
-- theme, accent, text & display, accessibility). Written by
-- emp-portal-ajax.php?action=save_ui_settings; rules in includes/ui_settings.php.
-- LONGTEXT + JSON_VALID check: works on MariaDB 10.4 (local) and 11.8 (live).
-- Safe to re-run.
ALTER TABLE employee_portal_accounts
    ADD COLUMN IF NOT EXISTS settings LONGTEXT NULL
        CHECK (settings IS NULL OR JSON_VALID(settings));
