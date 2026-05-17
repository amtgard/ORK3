-- Hero banner image for Park, Kingdom, Player (via ork_mundane), and Unit.
-- Mirrors the ork_event banner pattern (2026-05-10-add-event-banner.sql +
-- 2026-05-11-add-banner-offset.sql), applied to four entities at once.
--
-- Five columns per table:
--   has_banner       0/1 — is a banner image saved?
--   banner_show_logo 0/1 — render the entity logo over the banner? (default on)
--   banner_vignette  0/1 — darken+blur the left/bottom edges? (default on)
--   banner_offset_x  0..100 — CSS background-position-x percent (default 50 = center)
--   banner_offset_y  0..100 — CSS background-position-y percent (default 50 = center)

ALTER TABLE ork_park
    ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)       NOT NULL DEFAULT 0 AFTER has_heraldry,
    ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)       NOT NULL DEFAULT 1 AFTER has_banner,
    ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)       NOT NULL DEFAULT 1 AFTER banner_show_logo,
    ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
    ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;

ALTER TABLE ork_kingdom
    ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)       NOT NULL DEFAULT 0 AFTER has_heraldry,
    ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)       NOT NULL DEFAULT 1 AFTER has_banner,
    ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)       NOT NULL DEFAULT 1 AFTER banner_show_logo,
    ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
    ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;

ALTER TABLE ork_mundane
    ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)       NOT NULL DEFAULT 0 AFTER has_heraldry,
    ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)       NOT NULL DEFAULT 1 AFTER has_banner,
    ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)       NOT NULL DEFAULT 1 AFTER banner_show_logo,
    ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
    ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;

ALTER TABLE ork_unit
    ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)       NOT NULL DEFAULT 0 AFTER has_heraldry,
    ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)       NOT NULL DEFAULT 1 AFTER has_banner,
    ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)       NOT NULL DEFAULT 1 AFTER banner_show_logo,
    ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
    ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;
