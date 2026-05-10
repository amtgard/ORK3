-- Event banner image (separate from event logo / heraldry).
-- has_banner mirrors has_heraldry's pattern. The two display toggles are
-- read on the event detail page to drive the banner rendering.

ALTER TABLE ork_event
	ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1) NOT NULL DEFAULT 0 AFTER has_heraldry,
	ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1) NOT NULL DEFAULT 1 AFTER has_banner,
	ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1) NOT NULL DEFAULT 1 AFTER banner_show_logo;
