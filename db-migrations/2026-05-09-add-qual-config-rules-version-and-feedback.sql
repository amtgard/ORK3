-- Add rules_version (Reeve only, displayed as test footer) and
-- show_correct_on_incorrect (toggle: highlight correct answer when player picks wrong)
ALTER TABLE `ork_qual_config`
  ADD COLUMN `rules_version` VARCHAR(100) DEFAULT NULL AFTER `instructions`,
  ADD COLUMN `show_correct_on_incorrect` TINYINT(1) NOT NULL DEFAULT 0 AFTER `rules_version`;
