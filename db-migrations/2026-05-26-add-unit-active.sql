-- Soft-delete / retire support for units (companies & households).
-- Mirrors the convention already used by ork_park and ork_kingdom
-- (enum('Active','Retired') NOT NULL DEFAULT 'Active'), NOT the tinyint
-- pattern on ork_mundane. Retired units are hidden from unit lists and
-- player search; a kingdom officer (monarchy) restores them.
ALTER TABLE ork_unit
  ADD COLUMN `active` enum('Active','Retired') NOT NULL DEFAULT 'Active' AFTER `modified`;
