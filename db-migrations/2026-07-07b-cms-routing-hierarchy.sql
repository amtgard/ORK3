-- Amtgard CMS — Routing & Hierarchy migration
-- =============================================================================
-- Schema behind the L5 routing/architecture changes. AUTHOR ONLY — do NOT run
-- against the shared local DB; applied at deploy (MariaDB client, not mysql).
-- Runs AFTER 2026-07-07-cms-integrity-safety-net.sql (deploy order matters —
-- that migration converts the CMS tables it references to InnoDB FKs first).
--
-- Covers:
--   C13 Page hierarchy — nullable self-referential parent_id on ork_cms_page,
--       nested slug paths + breadcrumbs. ON DELETE SET NULL so trashing a
--       parent flattens its children rather than orphaning/cascading them.
--   C17 Reserved slugs + 301 redirects — ork_cms_redirect maps an old path to a
--       page (or external URL) so a renamed page's inbound links keep working.
--
-- Idempotent where MariaDB allows it (ADD COLUMN IF NOT EXISTS, CREATE TABLE IF
-- NOT EXISTS). The FK add is NOT guarded (older MariaDB lacks IF NOT EXISTS for
-- FKs); re-running it on an already-migrated DB warns harmlessly. InnoDB /
-- utf8mb4 throughout. No destructive ops.

-- ---------------------------------------------------------------------------
-- C13 — Page hierarchy. A page may nest under one parent page (same scope).
-- parent_id NULL => a flat/top-level page (today's behavior, unchanged). The
-- self-FK is ON DELETE SET NULL: a hard-deleted parent flattens its children
-- (the app's soft-delete path in CmsPage::DeletePage NULLs children explicitly,
-- since a soft-delete does not fire the FK).
-- ---------------------------------------------------------------------------
ALTER TABLE `ork_cms_page`
  ADD COLUMN IF NOT EXISTS `parent_id` int(11) DEFAULT NULL AFTER `scope_id`,
  ADD KEY IF NOT EXISTS `ix_page_parent` (`parent_id`);

-- FK NOT guarded (no IF NOT EXISTS for FKs on older MariaDB). ork_cms_page is
-- InnoDB (converted in the safety-net migration), so a self-FK is valid.
ALTER TABLE `ork_cms_page`
  ADD CONSTRAINT `fk_cms_page_parent`
    FOREIGN KEY (`parent_id`) REFERENCES `ork_cms_page` (`page_id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- ---------------------------------------------------------------------------
-- C17 — 301 redirects. When a page's slug changes, the old path is recorded
-- here so inbound links / bookmarks 301 to the current URL instead of hitting
-- the branded 404. Also usable for hand-authored vanity redirects.
--
--   scope_type/scope_id — the org (or 'global') the redirect belongs to; the
--     public router matches within the resolved site scope only.
--   from_path           — the path AFTER the site slug, no leading slash
--                          (e.g. 'about', 'about/history', 'old-blog').
--   to_page_id          — target page (preferred; survives later slug renames),
--   to_url              — OR an absolute/relative URL (external or off-scope).
--   code                — HTTP status (301 permanent by default; 302 allowed).
--
-- UNIQUE(scope_type, scope_id, from_path) so the newest rename wins (the app
-- upserts). At most one of to_page_id / to_url is set per row.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_redirect` (
  `redirect_id` int(11)                        NOT NULL AUTO_INCREMENT,
  `scope_type`  enum('global','kingdom','park') NOT NULL DEFAULT 'global',
  `scope_id`    int(11)                        NOT NULL DEFAULT 0,
  `from_path`   varchar(400)                   NOT NULL,
  `to_page_id`  int(11)                        DEFAULT NULL,
  `to_url`      varchar(600)                   DEFAULT NULL,
  `code`        smallint(6)                    NOT NULL DEFAULT 301,
  `created_by`  int(11)                        DEFAULT NULL,
  `created_at`  datetime                       DEFAULT NULL,
  PRIMARY KEY (`redirect_id`),
  UNIQUE KEY `uq_redirect_scope_path` (`scope_type`,`scope_id`,`from_path`),
  KEY `ix_redirect_target` (`to_page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Target-page FK (InnoDB→InnoDB). ON DELETE SET NULL so a hard-deleted target
-- leaves the row as a dead redirect (the app skips rows with neither target).
ALTER TABLE `ork_cms_redirect`
  ADD CONSTRAINT `fk_cms_redirect_page`
    FOREIGN KEY (`to_page_id`) REFERENCES `ork_cms_page` (`page_id`)
    ON DELETE SET NULL ON UPDATE CASCADE;
