-- Amtgard CMS (v2) — Foundation migration
-- Creates all `ork_cms_*` tables for the CMS data model defined in
-- docs/superpowers/specs/2026-06-23-amtgard-cms-design.md.
--
-- Every content table carries scope_type ENUM('global','kingdom','park')
-- DEFAULT 'global' and scope_id INT DEFAULT 0 so kingdom/park-scoped pages
-- drop in later with no migration (v2 = global only).
--
-- fields_json uses JSON (MariaDB 10.2+ aliases JSON to LONGTEXT + a
-- json_valid CHECK). If a target lacks JSON support, change JSON -> LONGTEXT.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. InnoDB / utf8mb4. No destructive ops.

-- ---------------------------------------------------------------------------
-- ork_cms_page — a content page.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_page` (
  `page_id`          int(11)        NOT NULL AUTO_INCREMENT,
  `slug`             varchar(160)   NOT NULL,
  `type`             enum('composed','article','media','blog_index','resource','dynamic') NOT NULL DEFAULT 'composed',
  `title`            varchar(255)   NOT NULL DEFAULT '',
  `status`           enum('draft','published') NOT NULL DEFAULT 'draft',
  `published_at`     datetime       DEFAULT NULL,
  `hero_media_id`    int(11)        DEFAULT NULL,
  `meta_description` varchar(255)   DEFAULT NULL,
  `is_system`        tinyint(1)     NOT NULL DEFAULT 0,
  `scope_type`       enum('global','kingdom','park') NOT NULL DEFAULT 'global',
  `scope_id`         int(11)        NOT NULL DEFAULT 0,
  `created_by`       int(11)        DEFAULT NULL,
  `created_at`       datetime       DEFAULT NULL,
  `updated_by`       int(11)        DEFAULT NULL,
  `updated_at`       datetime       DEFAULT NULL,
  PRIMARY KEY (`page_id`),
  UNIQUE KEY `uq_page_scope_slug` (`scope_type`,`scope_id`,`slug`),
  KEY `ix_page_status` (`status`,`published_at`),
  KEY `ix_page_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ork_cms_block — an ordered block belonging to a page OR a post (polymorphic).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_block` (
  `block_id`     int(11)        NOT NULL AUTO_INCREMENT,
  `owner_type`   enum('page','post') NOT NULL,
  `owner_id`     int(11)        NOT NULL,
  `type`         varchar(40)    NOT NULL,
  `ordering`     int(11)        NOT NULL DEFAULT 0,
  `enabled`      tinyint(1)     NOT NULL DEFAULT 1,
  `source`       enum('authored','dynamic') NOT NULL DEFAULT 'authored',
  `fields_json`  json           DEFAULT NULL,
  PRIMARY KEY (`block_id`),
  KEY `ix_block_owner_order` (`owner_type`,`owner_id`,`ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ork_cms_post — a blog post (body = blocks via ork_cms_block owner_type='post').
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_post` (
  `post_id`       int(11)       NOT NULL AUTO_INCREMENT,
  `slug`          varchar(160)  NOT NULL,
  `title`         varchar(255)  NOT NULL DEFAULT '',
  `excerpt`       text          DEFAULT NULL,
  `hero_media_id` int(11)       DEFAULT NULL,
  `author_id`     int(11)       DEFAULT NULL,
  `status`        enum('draft','published') NOT NULL DEFAULT 'draft',
  `published_at`  datetime      DEFAULT NULL,
  `scope_type`    enum('global','kingdom','park') NOT NULL DEFAULT 'global',
  `scope_id`      int(11)       NOT NULL DEFAULT 0,
  `created_by`    int(11)       DEFAULT NULL,
  `created_at`    datetime      DEFAULT NULL,
  `updated_by`    int(11)       DEFAULT NULL,
  `updated_at`    datetime      DEFAULT NULL,
  PRIMARY KEY (`post_id`),
  UNIQUE KEY `uq_post_scope_slug` (`scope_type`,`scope_id`,`slug`),
  KEY `ix_post_status` (`status`,`published_at`),
  KEY `ix_post_author` (`author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ork_cms_tag — blog tags.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_tag` (
  `tag_id` int(11)      NOT NULL AUTO_INCREMENT,
  `name`   varchar(80)  NOT NULL,
  `slug`   varchar(80)  NOT NULL,
  PRIMARY KEY (`tag_id`),
  UNIQUE KEY `uq_tag_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ork_cms_post_tag — post <-> tag join.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_post_tag` (
  `post_id` int(11) NOT NULL,
  `tag_id`  int(11) NOT NULL,
  PRIMARY KEY (`post_id`,`tag_id`),
  KEY `ix_post_tag_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ork_cms_media — media library.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_media` (
  `media_id`    int(11)       NOT NULL AUTO_INCREMENT,
  `filename`    varchar(255)  NOT NULL,
  `path`        varchar(255)  NOT NULL,
  `mime`        varchar(100)  DEFAULT NULL,
  `width`       int(11)       DEFAULT NULL,
  `height`      int(11)       DEFAULT NULL,
  `bytes`       int(11)       DEFAULT NULL,
  `alt`         varchar(255)  DEFAULT NULL,
  `title`       varchar(160)  DEFAULT NULL,
  `focal`       varchar(16)   NOT NULL DEFAULT '50% 50%',
  `thumb_path`  varchar(255)  DEFAULT NULL,
  `scope_type`  enum('global','kingdom','park') NOT NULL DEFAULT 'global',
  `scope_id`    int(11)       NOT NULL DEFAULT 0,
  `uploaded_by` int(11)       DEFAULT NULL,
  `created_at`  datetime      DEFAULT NULL,
  PRIMARY KEY (`media_id`),
  KEY `ix_media_scope` (`scope_type`,`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ork_cms_nav_item — editable navigation.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_nav_item` (
  `nav_id`     int(11)       NOT NULL AUTO_INCREMENT,
  `menu`       varchar(40)   NOT NULL DEFAULT 'marketing',
  `label`      varchar(160)  NOT NULL DEFAULT '',
  `link_type`  enum('page','post','url','dynamic') NOT NULL DEFAULT 'page',
  `page_id`    int(11)       DEFAULT NULL,
  `post_id`    int(11)       DEFAULT NULL,
  `url`        varchar(512)  DEFAULT NULL,
  `parent_id`  int(11)       DEFAULT NULL,
  `ordering`   int(11)       NOT NULL DEFAULT 0,
  `enabled`    tinyint(1)    NOT NULL DEFAULT 1,
  `scope_type` enum('global','kingdom','park') NOT NULL DEFAULT 'global',
  `scope_id`   int(11)       NOT NULL DEFAULT 0,
  PRIMARY KEY (`nav_id`),
  KEY `ix_nav_menu_order` (`menu`,`scope_type`,`scope_id`,`ordering`),
  KEY `ix_nav_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ork_cms_grant — RBAC.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ork_cms_grant` (
  `grant_id`    int(11) NOT NULL AUTO_INCREMENT,
  `mundane_id`  int(11) NOT NULL,
  `role`        enum('contributor','author','editor','publisher','admin') NOT NULL,
  `scope_type`  enum('global','kingdom','park') NOT NULL DEFAULT 'global',
  `scope_id`    int(11) NOT NULL DEFAULT 0,
  `granted_by`  int(11) DEFAULT NULL,
  `created_at`  datetime DEFAULT NULL,
  PRIMARY KEY (`grant_id`),
  UNIQUE KEY `uq_grant` (`mundane_id`,`role`,`scope_type`,`scope_id`),
  KEY `ix_grant_scope` (`scope_type`,`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
