-- Amtgard CMS — add the 'about' page type
-- =============================================================================
-- The Staff Roster exemplar seed (2026-06-27-cms-seed-staff-roster.php) creates
-- pages with type='about' (Board of Directors / Team Leads), but the foundation
-- migration's ork_cms_page.type ENUM
-- (2026-06-23-cms-foundation.sql:20) only declared:
--     enum('composed','article','media','blog_index','resource','dynamic')
-- With strict SQL mode OFF (the local/dev default), inserting the unlisted
-- 'about' value silently stored '' (empty) instead — so this migration widens
-- the ENUM to include 'about' and then backfills any staff-roster pages that
-- were seeded before it ran.
--
-- Additive only: the CREATE TABLE in the foundation migration is intentionally
-- left unchanged (that file stays a faithful record of the original schema);
-- this ALTER layers the new value on top.
--
-- 'type' is AUTHOR-FACING editor metadata (which editor preset a page was made
-- from — see Controller_Cms::_pageTypes), NOT a render input, so widening the
-- ENUM has no effect on the public render path.
--
-- Idempotent: MODIFY COLUMN restates the full ENUM (re-running is a no-op), and
-- the backfill only touches rows whose type is still '' for a known about-slug.
-- MariaDB client, not mysql. No destructive ops.

ALTER TABLE `ork_cms_page`
  MODIFY COLUMN `type`
    enum('composed','article','media','blog_index','resource','dynamic','about')
    NOT NULL DEFAULT 'composed';

-- Backfill: repair staff-roster pages seeded before the ENUM knew 'about'. Only
-- rows whose type landed as '' (the strict-mode-off truncation of the rejected
-- 'about' value) for the two known about-slugs are corrected; any page an editor
-- deliberately left as 'composed'/'article'/etc. is untouched.
UPDATE `ork_cms_page`
   SET `type` = 'about'
 WHERE `type` = ''
   AND `slug` IN ('board-of-directors', 'team-leads');
