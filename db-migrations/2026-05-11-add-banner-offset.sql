-- Banner image framing offsets (percent 0-100). With the source image saved
-- uncropped, the display uses CSS background-position: <x>% <y>% to crop on
-- the fly, which lets hosts re-frame any time without re-uploading.
-- Existing banners (saved cropped to 1800×240) display unchanged at the 50/50
-- default since the image already fits the frame.

ALTER TABLE ork_event
	ADD COLUMN IF NOT EXISTS banner_offset_x TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
	ADD COLUMN IF NOT EXISTS banner_offset_y TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;
