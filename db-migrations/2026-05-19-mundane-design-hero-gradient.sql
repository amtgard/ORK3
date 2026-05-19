-- Amtpride Nameplate: stores a preset key (e.g. 'pride6', 'progress', 'trans')
-- that the player template renders into a horizontal multi-stop gradient on
-- the hero. When set, takes precedence over color_primary/color_secondary.
-- Key validation lives in class.Player.php::UpdatePlayer against the keys
-- defined in system/lib/ork3/pride_gradients.php.

ALTER TABLE `ork_mundane_design`
  ADD COLUMN IF NOT EXISTS `hero_gradient` varchar(32)
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
  DEFAULT NULL
  AFTER `color_secondary`;
