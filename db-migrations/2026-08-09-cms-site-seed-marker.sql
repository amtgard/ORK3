-- Amtgard CMS — explicit starter-template seed marker on ork_cms_site
-- =============================================================================
-- CmsSite::EnsureSite runs on every OGRE dashboard load and every Publish
-- (controller.Cms.php:85 / :228 via _loadSiteContext, controller.CmsAjax.php:
-- 1211). Its #116 "partially seeded" repair used to INFER "never seeded" from
-- live content state — an empty 'marketing' nav menu and/or a NULL
-- home_page_id. That inference cannot tell "never seeded" apart from "the
-- kingdom deliberately deleted the seeded content", so an org that removed the
-- five seeded nav links got them silently re-inserted (CmsNav::CreateItem is
-- not UNIQUE-guarded), had home_page_id force-written back to the seeded home,
-- and — because 2026-07-08-cms-slug-live-and-integrity.sql moved uniqueness
-- onto slug_live (NULL for a trashed row, so the collision guard no longer
-- fires) — got brand-new PUBLISHED pages full of seed placeholder copy for
-- starter pages it had trashed. Public visitors then saw content the kingdom
-- had deliberately removed.
--
-- This column is the explicit state that replaces the inference:
-- template_seeded_at is stamped exactly once, at the END of a successful
-- _seedStarterTemplate() run, and EnsureSite gates the repair on it being NULL.
-- A seeded-then-emptied site therefore is never re-seeded.
--
-- Applied at deploy (MariaDB client, not mysql). Additive only — no data is
-- dropped or rewritten; the foundation/site migrations stay faithful records of
-- the original schema and this ALTER layers the new column on top.
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS is a no-op on a second run, and the
-- backfill only touches rows whose marker is still NULL.

-- ---------------------------------------------------------------------------
-- ork_cms_site — the seed marker. NULL means "starter template never seeded".
-- ---------------------------------------------------------------------------
ALTER TABLE `ork_cms_site`
  ADD COLUMN IF NOT EXISTS `template_seeded_at` DATETIME NULL DEFAULT NULL
    AFTER `home_page_id`;

-- ---------------------------------------------------------------------------
-- CRITICAL BACKFILL — must run in the SAME migration as the ADD COLUMN.
-- ---------------------------------------------------------------------------
-- Existing ork_cms_site rows were seeded by the create-branch call to
-- _seedStarterTemplate() when they were minted. Leaving them NULL would make the
-- new gate read them as "never seeded", so deploying this migration would
-- re-seed live sites — exactly the destructive behavior it exists to prevent.
--
-- The backfill is NARROWED to rows with evidence of a COMPLETED seed:
-- home_page_id IS NOT NULL is written by _setSeededHomePage() at the very end of
-- a successful seed, so a non-NULL value means the seed ran to completion.
--
-- TRADEOFF (deliberate): a blanket backfill would also stamp the handful of rows
-- whose seed genuinely died mid-run, stranding them forever with dead nav or a
-- "being built" home — there is no officer- or admin-triggered "re-seed starter
-- template" action anywhere to recover them. Leaving those rows NULL lets them
-- self-repair on the next OGRE dashboard load. That repair leaves deliberately-
-- trashed starter pages trashed and never re-points an org's chosen home page,
-- but it is NOT a no-op: it re-inserts the five 'marketing' nav rows when that
-- menu is empty (the intent for a genuinely-unseeded row), and a starter page
-- that was trashed and then aged out by CmsPage::PurgeTrashed() (30-day default,
-- hard DELETE) is indistinguishable from one that never existed, so it will be
-- re-created as a published page carrying seed placeholder copy. The cost is one
-- repair pass over a very small set of already-broken sites; the alternative is
-- permanent breakage with no remediation path. A one-time inference here is far
-- safer than the per-request inference this change removes.
--
-- created_at is TIMESTAMP NOT NULL DEFAULT current_timestamp(), so it is used
-- directly — no COALESCE fallback is reachable.
UPDATE `ork_cms_site`
   SET `template_seeded_at` = `created_at`
 WHERE `template_seeded_at` IS NULL
   AND `home_page_id` IS NOT NULL;
