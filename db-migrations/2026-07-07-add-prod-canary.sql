-- Dev-only prod canary for mirror (ork @ 19306). Never applied to ork_test by the test tool.
CREATE TABLE IF NOT EXISTS `_ork_canary_prod` (
  `id` INT PRIMARY KEY,
  `marker` VARCHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL
);

INSERT INTO `_ork_canary_prod` (`id`, `marker`, `created_at`)
VALUES (1, 'ORK3_PROD_CANARY_v1', NOW())
ON DUPLICATE KEY UPDATE `marker` = VALUES(`marker`);
