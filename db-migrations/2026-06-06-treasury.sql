-- Treasury module: per-org financial ledger (Kingdom/Park)
-- 2026-06-06

CREATE TABLE `ork_treasury_entry` (
  `id`             int(11)       NOT NULL AUTO_INCREMENT,
  `owner_type`     enum('kingdom','park') NOT NULL,
  `owner_id`       int(11)       NOT NULL,
  `entry_date`     date          NOT NULL,
  `direction`      enum('credit','debit') NOT NULL,
  `amount`         decimal(12,2) NOT NULL,
  `category`       varchar(64)   NOT NULL,
  `payment_method` enum('cash','check','digital') NOT NULL,
  `description`    varchar(255)  NOT NULL DEFAULT '',
  `counterparty`   varchar(255)  DEFAULT NULL,
  `reference_no`   varchar(64)   DEFAULT NULL,
  `deleted_at`     datetime      DEFAULT NULL,
  `created_by`     int(11)       NOT NULL,
  `created_at`     datetime      NOT NULL,
  `updated_at`     datetime      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_owner_date` (`owner_type`,`owner_id`,`entry_date`),
  KEY `ix_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ork_treasury_reconciliation` (
  `id`               int(11)       NOT NULL AUTO_INCREMENT,
  `owner_type`       enum('kingdom','park') NOT NULL,
  `owner_id`         int(11)       NOT NULL,
  `as_of_date`       date          NOT NULL,
  `actual_balance`   decimal(12,2) NOT NULL,
  `computed_balance` decimal(12,2) NOT NULL,
  `variance`         decimal(12,2) NOT NULL,
  `explanation`      varchar(500)  DEFAULT NULL,
  `is_opening`       tinyint(1)    NOT NULL DEFAULT 0,
  `deleted_at`       datetime      DEFAULT NULL,
  `created_by`       int(11)       NOT NULL,
  `created_at`       datetime      NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_owner_asof` (`owner_type`,`owner_id`,`as_of_date`),
  KEY `ix_owner_opening` (`owner_type`,`owner_id`,`is_opening`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ork_treasury_audit` (
  `id`          int(11)  NOT NULL AUTO_INCREMENT,
  `entry_id`    int(11)  NOT NULL,
  `action`      enum('create','edit','delete') NOT NULL,
  `changed_by`  int(11)  NOT NULL,
  `changed_at`  datetime NOT NULL,
  `before_json` text     DEFAULT NULL,
  `after_json`  text     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_entry` (`entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
