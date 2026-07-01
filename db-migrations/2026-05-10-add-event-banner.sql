-- Event banner image + framing controls (separate from event logo / heraldry).
-- Consolidated migration (Rose-cycle, fresh-deploy edition):
--   * has_banner / banner_show_logo / banner_vignette toggles
--   * banner_offset_x / banner_offset_y framing offsets (percent 0-100)
--
-- has_banner mirrors has_heraldry's pattern. The display toggles are read
-- on the event detail page. The framing offsets feed into CSS
-- background-position: <x>% <y>% so hosts can re-frame any saved banner
-- without re-uploading.

ALTER TABLE ork_event
	ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)       NOT NULL DEFAULT 0  AFTER has_heraldry,
	ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)       NOT NULL DEFAULT 1  AFTER has_banner,
	ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)       NOT NULL DEFAULT 1  AFTER banner_show_logo,
	ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
	ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;
