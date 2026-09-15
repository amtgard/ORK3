-- Digital Waivers: per-kingdom versioned templates + per-player signatures
-- 2026-04-17

CREATE TABLE IF NOT EXISTS `ork_waiver_template` (
  `waiver_template_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kingdom_id`            INT UNSIGNED NOT NULL,
  `scope`                 ENUM('kingdom','park') NOT NULL,
  `version`               INT UNSIGNED NOT NULL DEFAULT 1,
  `is_active`             TINYINT(1) NOT NULL DEFAULT 0,
  `is_enabled`            TINYINT(1) NOT NULL DEFAULT 0,
  `header_markdown`       TEXT NOT NULL,
  `body_markdown`         MEDIUMTEXT NOT NULL,
  `footer_markdown`       TEXT NOT NULL,
  `minor_markdown`        TEXT NOT NULL,
  `created_by_mundane_id` INT UNSIGNED NOT NULL,
  `created_at`            DATETIME NOT NULL,
  PRIMARY KEY (`waiver_template_id`),
  KEY `idx_kingdom_scope_active` (`kingdom_id`, `scope`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ork_waiver_signature` (
  `waiver_signature_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waiver_template_id`       INT UNSIGNED NOT NULL,
  `mundane_id`               INT UNSIGNED NOT NULL,

  `mundane_first_snapshot`   VARCHAR(64)  NOT NULL DEFAULT '',
  `mundane_last_snapshot`    VARCHAR(64)  NOT NULL DEFAULT '',
  `persona_name_snapshot`    VARCHAR(128) NOT NULL DEFAULT '',
  `park_id_snapshot`         INT UNSIGNED NOT NULL DEFAULT 0,
  `kingdom_id_snapshot`      INT UNSIGNED NOT NULL DEFAULT 0,

  `signature_type`           ENUM('drawn','typed') NOT NULL,
  `signature_data`           MEDIUMTEXT NOT NULL,
  `signed_at`                DATETIME NOT NULL,

  `is_minor`                 TINYINT(1)   NOT NULL DEFAULT 0,
  `minor_rep_first`          VARCHAR(64)  NOT NULL DEFAULT '',
  `minor_rep_last`           VARCHAR(64)  NOT NULL DEFAULT '',
  `minor_rep_relationship`   VARCHAR(64)  NOT NULL DEFAULT '',

  `verification_status`      ENUM('pending','verified','rejected','superseded') NOT NULL DEFAULT 'pending',
  `verified_by_mundane_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `verified_at`              DATETIME NULL DEFAULT NULL,
  `verifier_printed_name`    VARCHAR(128) NOT NULL DEFAULT '',
  `verifier_persona_name`    VARCHAR(128) NOT NULL DEFAULT '',
  `verifier_office_title`    VARCHAR(128) NOT NULL DEFAULT '',
  `verifier_signature_type`  ENUM('drawn','typed') NULL DEFAULT NULL,
  `verifier_signature_data`  MEDIUMTEXT NULL DEFAULT NULL,
  `verifier_notes`           TEXT NOT NULL,

  PRIMARY KEY (`waiver_signature_id`),
  KEY `idx_mundane` (`mundane_id`),
  KEY `idx_template_status` (`waiver_template_id`, `verification_status`),
  KEY `idx_kingdom_status` (`kingdom_id_snapshot`, `verification_status`),
  KEY `idx_park_status` (`park_id_snapshot`, `verification_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
