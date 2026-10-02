-- Digital Waivers Amendment 1 — real-world waiver coverage.
-- Additive. Safe to run on a DB that has the 2026-04-17 base migration.
-- 2026-04-18

ALTER TABLE `ork_waiver_template`
  ADD COLUMN `requires_dob`               TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_address`           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_phone`             TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_email`             TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_preferred_name`    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_gender`            TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_emergency_contact` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_witness`           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `max_minors`                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN `custom_fields_json`         MEDIUMTEXT NOT NULL;

ALTER TABLE `ork_waiver_signature`
  ADD COLUMN `preferred_name_snapshot`         VARCHAR(64)  NOT NULL DEFAULT '',
  ADD COLUMN `dob_snapshot`                    DATE NULL DEFAULT NULL,
  ADD COLUMN `gender_snapshot`                 VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `address_snapshot`                VARCHAR(255) NOT NULL DEFAULT '',
  ADD COLUMN `phone_snapshot`                  VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `email_snapshot`                  VARCHAR(128) NOT NULL DEFAULT '',
  ADD COLUMN `emergency_contact_name`          VARCHAR(128) NOT NULL DEFAULT '',
  ADD COLUMN `emergency_contact_phone`         VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `emergency_contact_relationship`  VARCHAR(64)  NOT NULL DEFAULT '',
  ADD COLUMN `witness_printed_name`            VARCHAR(128) NOT NULL DEFAULT '',
  ADD COLUMN `witness_signature_type`          ENUM('drawn','typed') NULL DEFAULT NULL,
  ADD COLUMN `witness_signature_data`          MEDIUMTEXT NULL DEFAULT NULL,
  ADD COLUMN `custom_responses_json`           MEDIUMTEXT NOT NULL,
  ADD COLUMN `verifier_id_type`                VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `verifier_id_number_last4`        VARCHAR(8)   NOT NULL DEFAULT '',
  ADD COLUMN `verifier_age_bracket`            ENUM('', '18+', '14+', 'under14') NOT NULL DEFAULT '',
  ADD COLUMN `verifier_scanned_paper`          TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `ork_waiver_signature_minor` (
  `waiver_signature_minor_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waiver_signature_id`       INT UNSIGNED NOT NULL,
  `seq`                       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `legal_first`               VARCHAR(64)  NOT NULL DEFAULT '',
  `legal_last`                VARCHAR(64)  NOT NULL DEFAULT '',
  `preferred_name`            VARCHAR(64)  NOT NULL DEFAULT '',
  `persona_name`              VARCHAR(128) NOT NULL DEFAULT '',
  `dob`                       DATE NULL DEFAULT NULL,
  PRIMARY KEY (`waiver_signature_minor_id`),
  KEY `idx_signature` (`waiver_signature_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
