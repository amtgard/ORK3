-- Add optional fixed expiry date to qual test config.
-- When valid_until is set it takes precedence over valid_days.
ALTER TABLE `ork_qual_config`
    ADD COLUMN `valid_until` DATE NULL DEFAULT NULL AFTER `valid_days`;
