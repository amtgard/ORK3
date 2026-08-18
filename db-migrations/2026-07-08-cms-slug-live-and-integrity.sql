-- Amtgard CMS — Live-slug uniqueness migration
-- =============================================================================
-- Fixes the soft-delete slug-reservation regression: the original
-- UNIQUE(scope_type, scope_id, slug) keys on ork_cms_page / ork_cms_post ignore
-- deleted_at, so trashing a page/post PERMANENTLY reserves its slug — a new
-- page/post can never reuse the slug of a trashed one, and restoring is the only
-- way to free it. AUTHOR ONLY — do NOT run against the shared local DB; applied
-- at deploy (MariaDB client, not mysql).
--
-- Runs AFTER 2026-07-07-cms-integrity-safety-net.sql (which adds deleted_at) and
-- 2026-07-07b-cms-routing-hierarchy.sql.
--
-- Approach (per product decision): a STORED generated column `slug_live` that
-- equals `slug` for a LIVE row (deleted_at IS NULL) and NULL for a trashed row.
-- The uniqueness constraint moves onto `slug_live`. Because MySQL/MariaDB unique
-- keys allow unlimited NULL duplicates, many trashed rows sharing a slug coexist
-- freely while at most one LIVE row per (scope_type, scope_id, slug) is enforced.
-- This is NOT slug-mangling — the stored `slug` is left untouched on trash, so a
-- restore keeps the original slug (subject to the app's live-collision guard).
--
-- Idempotent where MariaDB allows it (ADD COLUMN IF NOT EXISTS, DROP INDEX IF
-- EXISTS, ADD UNIQUE KEY IF NOT EXISTS). MariaDB-specific: generated-column and
-- IF [NOT] EXISTS index syntax verified against MariaDB 10.2+. InnoDB / utf8mb4
-- throughout. No destructive ops (the old slug data is preserved; only the index
-- definition changes).

-- ---------------------------------------------------------------------------
-- ork_cms_page — live-slug generated column + re-keyed uniqueness.
-- ---------------------------------------------------------------------------
-- slug_live mirrors slug while the row is live, NULL once it is trashed. varchar
-- width + collation match `slug` (inherits the table default utf8mb4_unicode_ci)
-- so the unique key compares identically to the old one for live rows.
ALTER TABLE `ork_cms_page`
  ADD COLUMN IF NOT EXISTS `slug_live` varchar(160)
    GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `slug`, NULL)) STORED
    AFTER `slug`;

-- Swap the uniqueness from (scope,slug) to (scope,slug_live): drop the old
-- deleted_at-blind key, add the live-only key. No FK depends on the old key
-- (all CMS FKs reference primary keys), so dropping it is safe.
ALTER TABLE `ork_cms_page`
  DROP INDEX IF EXISTS `uq_page_scope_slug`;
ALTER TABLE `ork_cms_page`
  ADD UNIQUE KEY IF NOT EXISTS `uq_page_scope_slug_live` (`scope_type`,`scope_id`,`slug_live`);

-- ---------------------------------------------------------------------------
-- ork_cms_post — same live-slug generated column + re-keyed uniqueness.
-- ---------------------------------------------------------------------------
ALTER TABLE `ork_cms_post`
  ADD COLUMN IF NOT EXISTS `slug_live` varchar(160)
    GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `slug`, NULL)) STORED
    AFTER `slug`;

ALTER TABLE `ork_cms_post`
  DROP INDEX IF EXISTS `uq_post_scope_slug`;
ALTER TABLE `ork_cms_post`
  ADD UNIQUE KEY IF NOT EXISTS `uq_post_scope_slug_live` (`scope_type`,`scope_id`,`slug_live`);
