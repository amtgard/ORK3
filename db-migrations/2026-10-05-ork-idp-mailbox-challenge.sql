-- Physical name: ork_idp_mailbox_challenge (DB_PREFIX + idp_mailbox_challenge)
CREATE TABLE IF NOT EXISTS `ork_idp_mailbox_challenge` (
  `id` char(36) NOT NULL,
  `purpose` varchar(32) NOT NULL,
  `idp_user_id` varchar(64) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `sent_to_hash` char(64) NOT NULL,
  `send_count` int(11) NOT NULL DEFAULT 1,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ork_idp_mailbox_mundane` (`mundane_id`, `purpose`, `idp_user_id`),
  KEY `idx_ork_idp_mailbox_sent` (`sent_to_hash`, `purpose`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
