-- Digital Waivers: true version metadata.
-- Each SaveTemplate already creates a new versioned row (prior row -> is_active=0).
-- This adds a human-facing name and a change summary to every version, powering
-- the builder "Publish New Version" prompt and the Waiver Change History report.
--   version_name  : e.g. "2026-05-30 V1" (default), editable at save time.
--   change_reason : the author's summary of what changed in this version.

ALTER TABLE ork_waiver_template
  ADD COLUMN version_name  VARCHAR(120) NOT NULL DEFAULT '' AFTER version,
  ADD COLUMN change_reason TEXT         NULL                AFTER version_name;

-- Backfill existing rows so the history report reads cleanly:
--  - derive a name from the save date + version number
--  - mark the first version of each (kingdom, scope) chain as the initial publication
UPDATE ork_waiver_template
   SET version_name = CONCAT(DATE_FORMAT(created_at, '%Y-%m-%d'), ' V', version)
 WHERE version_name = '';

UPDATE ork_waiver_template
   SET change_reason = 'Initial publication of digital waiver.'
 WHERE version = 1 AND (change_reason IS NULL OR change_reason = '');
