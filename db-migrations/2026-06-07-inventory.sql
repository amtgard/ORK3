-- Inventory module: per-org durable-goods register (Kingdom/Park)
-- 2026-06-07

CREATE TABLE `ork_inventory_item` (
  `id`                int(11)       NOT NULL AUTO_INCREMENT,
  `owner_type`        enum('kingdom','park') NOT NULL,
  `owner_id`          int(11)       NOT NULL,
  `name`              varchar(255)  NOT NULL,
  `category`          varchar(64)   NOT NULL,
  `quantity`          int(11)       NOT NULL DEFAULT 1,
  `condition`         enum('new','good','fair','poor','needs_repair') NOT NULL DEFAULT 'good',
  `unit_value`        decimal(12,2) NOT NULL DEFAULT 0.00,
  `location`          varchar(255)  NOT NULL DEFAULT '',
  `held_by`           varchar(255)  NOT NULL DEFAULT '',
  `held_by_player_id` int(11)       NOT NULL DEFAULT 0,
  `acquired_date`     date          DEFAULT NULL,
  `notes`             varchar(500)  NOT NULL DEFAULT '',
  `removed_at`        datetime      DEFAULT NULL,
  `removal_reason`    varchar(32)   NOT NULL DEFAULT '',
  `removal_note`      varchar(500)  NOT NULL DEFAULT '',
  `disposal_date`     date          DEFAULT NULL,
  `disposal_value`    decimal(12,2) NOT NULL DEFAULT 0.00,
  `disposed_to`       varchar(255)  NOT NULL DEFAULT '',
  `treasury_entry_id` int(11)       NOT NULL DEFAULT 0,
  `last_verified_at`  datetime      DEFAULT NULL,
  `last_verified_by`  int(11)       NOT NULL DEFAULT 0,
  `deleted_at`        datetime      DEFAULT NULL,
  `created_by`        int(11)       NOT NULL,
  `created_at`        datetime      NOT NULL,
  `updated_at`        datetime      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_owner_cat` (`owner_type`,`owner_id`,`category`),
  KEY `ix_deleted` (`deleted_at`),
  KEY `ix_removed` (`removed_at`),
  CONSTRAINT `ck_inventory_quantity` CHECK (`quantity` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ork_inventory_audit` (
  `id`          int(11)  NOT NULL AUTO_INCREMENT,
  `item_id`     int(11)  NOT NULL,
  `action`      enum('create','edit','remove','restore','delete','undelete','split','verify') NOT NULL,
  `changed_by`  int(11)  NOT NULL,
  `changed_at`  datetime NOT NULL,
  `before_json` text     DEFAULT NULL,
  `after_json`  text     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
