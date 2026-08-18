-- Widen hero_overlay so the new 'vignette' mode (radial-blur option on the
-- profile hero) fits alongside the existing 'low' / 'med' / 'high' values.
-- Original column was varchar(4); 'vignette' is 8 chars.

ALTER TABLE `ork_mundane_design`
  MODIFY `hero_overlay` varchar(16)
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
  NOT NULL DEFAULT 'med';
