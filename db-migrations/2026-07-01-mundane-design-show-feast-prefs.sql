-- Optional public display of feast preferences on the About tab.
-- Default OFF (0): feast prefs carry allergen data, so we treat public
-- surfacing as opt-in even though "Show My Beltline" defaults to on.
ALTER TABLE `ork_mundane_design`
    ADD COLUMN IF NOT EXISTS `show_feast_prefs` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `show_beltline`;
