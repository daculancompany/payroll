-- system_settings — the table Site Settings has always read and written, and
-- which was never actually created on this database.
--
-- The page (site_settings.php) and Action::save_settings() both assume it
-- exists. Without it the page opened with a PHP notice and an empty form, and
-- pressing Save threw, so the screen has simply never worked here.
--
-- Columns are exactly the ones the existing save handler binds — name, email,
-- contact, about_content, cover_img — so no PHP had to change to match a new
-- shape.
--
-- Additive and re-runnable (MariaDB 10.4 local / 11.8 live).

CREATE TABLE IF NOT EXISTS system_settings (
    id            INT(11)      NOT NULL AUTO_INCREMENT,
    name          VARCHAR(150) NOT NULL DEFAULT ''   COMMENT 'system name shown in the browser tab and on printouts',
    email         VARCHAR(150) NOT NULL DEFAULT ''   COMMENT 'contact address shown to employees',
    contact       VARCHAR(80)  NOT NULL DEFAULT '',
    about_content TEXT         NULL DEFAULT NULL,
    cover_img     VARCHAR(255) NULL DEFAULT NULL     COMMENT 'file name under assets/img/',
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row, and only ever one: save_settings() UPDATEs when a row exists and
-- INSERTs when it does not, so seeding here keeps it on the update path.
-- Seeded with the product brand rather than blanks, so the page opens with
-- something sensible instead of an empty form on a fresh install.
INSERT INTO system_settings (id, name, email, contact, about_content)
SELECT 1, 'Kawani HR', '', '', NULL
WHERE NOT EXISTS (SELECT 1 FROM system_settings);
