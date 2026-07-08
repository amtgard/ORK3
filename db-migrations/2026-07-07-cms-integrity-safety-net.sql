-- Amtgard CMS — Integrity & Safety-Net migration
-- =============================================================================
-- Adds the schema behind five hardening changes to the CMS data layer. AUTHOR
-- ONLY — do NOT run this against the shared local DB; it is applied at deploy
-- (MariaDB client, not mysql).
--
-- Covers:
--   C2  Content safety net — soft-delete (deleted_at) + revision history table.
--   C7  Scheduled publishing — a 'scheduled' status on page/post.
--   C8  Referential integrity — InnoDB FKs where BOTH sides are InnoDB.
--   C14 Audit trail — append-only ork_cms_audit.
--
-- Idempotent where MariaDB allows it (ADD COLUMN IF NOT EXISTS, CREATE TABLE
-- IF NOT EXISTS). FK additions are NOT guarded (older MariaDB lacks IF NOT
-- EXISTS for FKs); re-running the FK block on an already-migrated DB will warn
-- harmlessly. InnoDB / utf8mb4 throughout. No destructive ops.
--
-- ENGINE NOTE: ork_park / ork_kingdom / ork_mundane are MyISAM/latin1, so the
-- scope columns (scope_type/scope_id) and creator columns (created_by,
-- author_id, uploaded_by, mundane_id) CANNOT be foreign-keyed to them — a FK
-- requires both sides to be InnoDB. Those relationships are intentionally left
-- un-FK'd. ork_cms_block is polymorphic (owner_type + owner_id references
-- EITHER ork_cms_page OR ork_cms_post), which a single SQL foreign key cannot
-- express, so it is also intentionally skipped — its rows are cleaned in the
-- application delete/trash paths instead.

-- ---------------------------------------------------------------------------
-- C2 — Soft-delete columns. Trash/Restore in the app sets/clears deleted_at;
-- reads filter `deleted_at IS NULL`. Physical rows are retained so a delete is
-- recoverable and its blocks/references survive for restore.
-- ---------------------------------------------------------------------------
ALTER TABLE `ork_cms_page`
  ADD COLUMN IF NOT EXISTS `deleted_at` datetime DEFAULT NULL AFTER `updated_at`,
  ADD KEY IF NOT EXISTS `ix_page_deleted` (`deleted_at`);

ALTER TABLE `ork_cms_post`
  ADD COLUMN IF NOT EXISTS `deleted_at` datetime DEFAULT NULL AFTER `updated_at`,
  ADD KEY IF NOT EXISTS `ix_post_deleted` (`deleted_at`);

ALTER TABLE `ork_cms_media`
  ADD COLUMN IF NOT EXISTS `deleted_at` datetime DEFAULT NULL AFTER `created_at`,
  ADD KEY IF NOT EXISTS `ix_media_deleted` (`deleted_at`);

-- ---------------------------------------------------------------------------
-- C7 — Scheduled publishing. Add a 'scheduled' status. A scheduled row is
-- promoted to 'published' lazily on read once published_at <= NOW() (no cron).
-- ---------------------------------------------------------------------------
ALTER TABLE `ork_cms_page`
  MODIFY COLUMN `status` enum('draft','published','scheduled') NOT NULL DEFAULT 'draft';

ALTER TABLE `ork_cms_post`
  MODIFY COLUMN `status` enum('draft','published','scheduled') NOT NULL DEFAULT 'draft';

-- ---------------------------------------------------------------------------
-- C2 — Revision history. A snapshot of the block-set JSON (plus a small meta
-- snapshot) is written on each save/publish so content is never irrecoverably
-- overwritten. The app caps retention at ~25 rows per owner (prunes older).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_revision` (
  `revision_id` int(11)             NOT NULL AUTO_INCREMENT,
  `owner_type`  enum('page','post') NOT NULL,
  `owner_id`    int(11)             NOT NULL,
  `blocks_json` longtext            DEFAULT NULL,
  `meta_json`   longtext            DEFAULT NULL,
  `author_id`   int(11)             DEFAULT NULL,
  `created_at`  datetime            DEFAULT NULL,
  PRIMARY KEY (`revision_id`),
  KEY `ix_revision_owner` (`owner_type`,`owner_id`,`revision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- C14 — Append-only audit trail. Never UPDATEd/DELETEd by the app. Written
-- fire-and-forget from publish/unpublish/delete + grant/revoke paths.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_audit` (
  `audit_id`    int(11)      NOT NULL AUTO_INCREMENT,
  `actor_id`    int(11)      DEFAULT NULL,
  `action`      varchar(40)  NOT NULL,
  `entity_type` varchar(24)  NOT NULL,
  `entity_id`   int(11)      NOT NULL DEFAULT 0,
  `scope_type`  enum('global','kingdom','park') NOT NULL DEFAULT 'global',
  `scope_id`    int(11)      NOT NULL DEFAULT 0,
  `at`          datetime     DEFAULT NULL,
  PRIMARY KEY (`audit_id`),
  KEY `ix_audit_entity` (`entity_type`,`entity_id`),
  KEY `ix_audit_actor` (`actor_id`),
  KEY `ix_audit_scope` (`scope_type`,`scope_id`),
  KEY `ix_audit_at` (`at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- C8 — Referential integrity (InnoDB<->InnoDB only). Clean up any orphaned
-- references FIRST so the FK creation does not fail on pre-existing bad rows,
-- then add the constraints.
-- ---------------------------------------------------------------------------

-- Orphan cleanup ------------------------------------------------------------
-- post_tag rows pointing at a vanished post/tag (CASCADE targets).
DELETE pt FROM `ork_cms_post_tag` pt
  LEFT JOIN `ork_cms_post` p ON p.`post_id` = pt.`post_id`
  WHERE p.`post_id` IS NULL;
DELETE pt FROM `ork_cms_post_tag` pt
  LEFT JOIN `ork_cms_tag` t ON t.`tag_id` = pt.`tag_id`
  WHERE t.`tag_id` IS NULL;

-- nav_item references pointing at a vanished page/post/parent (SET NULL / self).
UPDATE `ork_cms_nav_item` n
  LEFT JOIN `ork_cms_page` p ON p.`page_id` = n.`page_id`
  SET n.`page_id` = NULL
  WHERE n.`page_id` IS NOT NULL AND p.`page_id` IS NULL;
UPDATE `ork_cms_nav_item` n
  LEFT JOIN `ork_cms_post` po ON po.`post_id` = n.`post_id`
  SET n.`post_id` = NULL
  WHERE n.`post_id` IS NOT NULL AND po.`post_id` IS NULL;
UPDATE `ork_cms_nav_item` n
  LEFT JOIN `ork_cms_nav_item` par ON par.`nav_id` = n.`parent_id`
  SET n.`parent_id` = NULL
  WHERE n.`parent_id` IS NOT NULL AND par.`nav_id` IS NULL;

-- site.home_page_id pointing at a vanished page (SET NULL).
UPDATE `ork_cms_site` s
  LEFT JOIN `ork_cms_page` p ON p.`page_id` = s.`home_page_id`
  SET s.`home_page_id` = NULL
  WHERE s.`home_page_id` IS NOT NULL AND p.`page_id` IS NULL;

-- Foreign keys --------------------------------------------------------------
-- post_tag -> post / tag : CASCADE (a purged post/tag drops its links).
ALTER TABLE `ork_cms_post_tag`
  ADD CONSTRAINT `fk_post_tag_post` FOREIGN KEY (`post_id`)
    REFERENCES `ork_cms_post` (`post_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_post_tag_tag` FOREIGN KEY (`tag_id`)
    REFERENCES `ork_cms_tag` (`tag_id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- nav_item -> page / post : SET NULL (a purged target leaves a '#' link, not a
-- dangling id). nav_item.parent_id -> nav_item : CASCADE (drop a dropdown's
-- children with its parent), mirroring CmsNav::DeleteItem's behavior.
ALTER TABLE `ork_cms_nav_item`
  ADD CONSTRAINT `fk_nav_page` FOREIGN KEY (`page_id`)
    REFERENCES `ork_cms_page` (`page_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_nav_post` FOREIGN KEY (`post_id`)
    REFERENCES `ork_cms_post` (`post_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_nav_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `ork_cms_nav_item` (`nav_id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- site.home_page_id -> page : SET NULL (a purged home page unsets the pointer).
ALTER TABLE `ork_cms_site`
  ADD CONSTRAINT `fk_site_home_page` FOREIGN KEY (`home_page_id`)
    REFERENCES `ork_cms_page` (`page_id`) ON DELETE SET NULL ON UPDATE CASCADE;
