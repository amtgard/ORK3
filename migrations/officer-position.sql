-- Migration: Officer Admin Expansion — position registry, ENUM->VARCHAR, position_id columns
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/officer-position.sql
-- Idempotent where practical.

-- 1. Widen ork_officer.role ENUM -> VARCHAR (fixes GMR coercion bug; admits custom keys)
ALTER TABLE `ork_officer` MODIFY `role` VARCHAR(80) NOT NULL;

-- 2. Widen ork_officer_history.role ENUM -> VARCHAR (admits custom keys; NOT a coercion fix)
ALTER TABLE `ork_officer_history` MODIFY `role` VARCHAR(80) NOT NULL;

-- 3a. New table: position registry
CREATE TABLE IF NOT EXISTS `ork_officer_position` (
  `position_id`   int(11)      NOT NULL AUTO_INCREMENT,
  `kingdom_id`    int(11)      NOT NULL,
  `canonical_key` varchar(60)  NOT NULL,
  `title`         varchar(80)  NOT NULL,
  `title_alias`   varchar(80)  NOT NULL DEFAULT '',
  `classification` enum('crown','supporting') NOT NULL,
  `is_pinned`     tinyint(1)   NOT NULL DEFAULT 0,
  `is_system`     tinyint(1)   NOT NULL DEFAULT 0,
  `rbac_role_id`  int(11)      NOT NULL,
  `has_auth_role` tinyint(1)   NOT NULL DEFAULT 0,
  `sort_order`    int(11)      NOT NULL DEFAULT 100,
  `parent_position_id` int(11) NULL DEFAULT NULL,
  `hide_when_vacant`   tinyint(1) NOT NULL DEFAULT 0,
  `retired_at`    datetime     NULL DEFAULT NULL,
  `created_by`    int(11)      NOT NULL DEFAULT 0,
  `created_at`    datetime     NOT NULL,
  PRIMARY KEY (`position_id`),
  UNIQUE KEY `uq_kingdom_key` (`kingdom_id`, `canonical_key`),
  KEY `idx_grouped_read` (`kingdom_id`, `classification`, `retired_at`, `sort_order`),
  KEY `idx_parent_position` (`parent_position_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3b. New table: per-kingdom alias of shared system (Core-Five) rows
CREATE TABLE IF NOT EXISTS `ork_officer_position_alias` (
  `alias_id`      int(11)      NOT NULL AUTO_INCREMENT,
  `kingdom_id`    int(11)      NOT NULL,
  `canonical_key` varchar(60)  NOT NULL,
  `title_alias`   varchar(80)  NOT NULL DEFAULT '',
  PRIMARY KEY (`alias_id`),
  UNIQUE KEY `uq_kingdom_canonical` (`kingdom_id`, `canonical_key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Seed the 5 system positions (kingdom_id=0, is_system=1, is_pinned=1, classification='crown')
INSERT IGNORE INTO `ork_officer_position`
  (`kingdom_id`,`canonical_key`,`title`,`title_alias`,`classification`,`is_pinned`,`is_system`,`rbac_role_id`,`has_auth_role`,`sort_order`,`retired_at`,`created_by`,`created_at`)
SELECT 0,'monarch','Monarch','','crown',1,1,r.role_id,1,10,NULL,0,NOW()
  FROM `ork_role` r WHERE r.name='monarch' AND r.kingdom_id=0 AND r.is_system=1;
INSERT IGNORE INTO `ork_officer_position`
  (`kingdom_id`,`canonical_key`,`title`,`title_alias`,`classification`,`is_pinned`,`is_system`,`rbac_role_id`,`has_auth_role`,`sort_order`,`retired_at`,`created_by`,`created_at`)
SELECT 0,'regent','Regent','','crown',1,1,r.role_id,1,20,NULL,0,NOW()
  FROM `ork_role` r WHERE r.name='regent' AND r.kingdom_id=0 AND r.is_system=1;
INSERT IGNORE INTO `ork_officer_position`
  (`kingdom_id`,`canonical_key`,`title`,`title_alias`,`classification`,`is_pinned`,`is_system`,`rbac_role_id`,`has_auth_role`,`sort_order`,`retired_at`,`created_by`,`created_at`)
SELECT 0,'prime_minister','Prime Minister','','crown',1,1,r.role_id,1,30,NULL,0,NOW()
  FROM `ork_role` r WHERE r.name='prime_minister' AND r.kingdom_id=0 AND r.is_system=1;
INSERT IGNORE INTO `ork_officer_position`
  (`kingdom_id`,`canonical_key`,`title`,`title_alias`,`classification`,`is_pinned`,`is_system`,`rbac_role_id`,`has_auth_role`,`sort_order`,`retired_at`,`created_by`,`created_at`)
SELECT 0,'champion','Champion','','crown',1,1,r.role_id,0,40,NULL,0,NOW()
  FROM `ork_role` r WHERE r.name='champion' AND r.kingdom_id=0 AND r.is_system=1;
INSERT IGNORE INTO `ork_officer_position`
  (`kingdom_id`,`canonical_key`,`title`,`title_alias`,`classification`,`is_pinned`,`is_system`,`rbac_role_id`,`has_auth_role`,`sort_order`,`retired_at`,`created_by`,`created_at`)
SELECT 0,'gmr','Guildmaster of Reeves','','crown',1,1,r.role_id,0,50,NULL,0,NOW()
  FROM `ork_role` r WHERE r.name='gmr' AND r.kingdom_id=0 AND r.is_system=1;

-- 5. Add position_id to ork_officer + backfill (normalize display strings -> canonical keys)
ALTER TABLE `ork_officer` ADD COLUMN `position_id` INT NOT NULL DEFAULT 0;
-- 5a. Normalize coerced/empty GMR rows back to the canonical key first (risk-3 reconciliation)
UPDATE `ork_officer` SET `role`='gmr'
  WHERE (`role`='' OR `role`='GMR') AND `authorization_id`=0;
-- 5b. Normalize remaining display strings to canonical keys
UPDATE `ork_officer` SET `role`='monarch'        WHERE `role`='Monarch';
UPDATE `ork_officer` SET `role`='regent'         WHERE `role`='Regent';
UPDATE `ork_officer` SET `role`='prime_minister' WHERE `role`='Prime Minister';
UPDATE `ork_officer` SET `role`='champion'       WHERE `role`='Champion';
UPDATE `ork_officer` SET `role`='gmr'            WHERE `role`='GMR';
-- 5c. Backfill position_id from the system seed by canonical key
UPDATE `ork_officer` o JOIN `ork_officer_position` p
  ON p.kingdom_id=0 AND p.canonical_key=o.role
  SET o.position_id=p.position_id;

-- 6. Add position_id + display_label to ork_officer_history + backfill
ALTER TABLE `ork_officer_history`
  ADD COLUMN `position_id` INT NOT NULL DEFAULT 0,
  ADD COLUMN `display_label` VARCHAR(80) NOT NULL DEFAULT '';
UPDATE `ork_officer_history` SET `display_label`=`role` WHERE `display_label`='';
UPDATE `ork_officer_history` SET `role`='monarch'        WHERE `role`='Monarch';
UPDATE `ork_officer_history` SET `role`='regent'         WHERE `role`='Regent';
UPDATE `ork_officer_history` SET `role`='prime_minister' WHERE `role`='Prime Minister';
UPDATE `ork_officer_history` SET `role`='champion'       WHERE `role`='Champion';
UPDATE `ork_officer_history` SET `role`='gmr'            WHERE `role`='GMR';
UPDATE `ork_officer_history` h JOIN `ork_officer_position` p
  ON p.kingdom_id=0 AND p.canonical_key=h.role
  SET h.position_id=p.position_id;

-- 7. Drop blanket unique; add non-unique scoped index
ALTER TABLE `ork_officer` DROP INDEX `kingdom_id`;
ALTER TABLE `ork_officer` ADD INDEX `idx_kingdom_park_position` (`kingdom_id`,`park_id`,`position_id`);
