-- Magic-link tokens for the password-fallback claim path
CREATE TABLE IF NOT EXISTS `ork_idp_claim_token` (
    `token` CHAR(64) NOT NULL,
    `idp_user_id` VARCHAR(255) NOT NULL,
    `idp_email` VARCHAR(255) NOT NULL,
    `mundane_id` INT(11) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `consumed_at` DATETIME NULL,
    PRIMARY KEY (`token`),
    KEY `idx_mundane` (`mundane_id`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IDP mirror retry tracking on existing IDP auth table
ALTER TABLE `ork_idp_auth`
    ADD COLUMN `idp_mirror_status` ENUM('pending','synced','failed') NOT NULL DEFAULT 'pending',
    ADD COLUMN `idp_mirror_last_attempt` DATETIME NULL;
