-- Migration: Add timezone support to Kingdom, Park, and Event tables
-- Timezone values are IANA timezone identifiers (e.g., 'America/New_York')
-- NULL means "inherit from parent" (Event→Park→Kingdom→UTC)

ALTER TABLE `ork_kingdom`
  ADD COLUMN `timezone` VARCHAR(64) DEFAULT NULL AFTER `url`;

ALTER TABLE `ork_park`
  ADD COLUMN `timezone` VARCHAR(64) DEFAULT NULL AFTER `directions`;

ALTER TABLE `ork_event`
  ADD COLUMN `timezone` VARCHAR(64) DEFAULT NULL AFTER `name`;
