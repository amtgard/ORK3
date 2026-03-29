-- Add instructions field to qualification test config
ALTER TABLE `ork_qual_config`
  ADD COLUMN `instructions` TEXT DEFAULT NULL AFTER `share_questions`;
