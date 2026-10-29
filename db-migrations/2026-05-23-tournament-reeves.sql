-- Tournament Reeves: per-tournament staff roles (Feature 2)
-- 2026-05-23
--
-- One row per (tournament, mundane). Re-adding a person updates their role
-- via INSERT ... ON DUPLICATE KEY UPDATE on the unique key.
-- Roles:
--   organizer      — full manage rights for this tournament (passes check_auth)
--   bracket_runner — record match results / resets only (passes can_run_brackets)
-- Engine: InnoDB to match the sibling realtime tables (ork_tournament_event/_seq).
-- MyISAM's table-level write locking would block concurrent reeve-polling reads
-- during any write, so this table must be InnoDB. Charset utf8mb4.

CREATE TABLE IF NOT EXISTS `ork_tournament_reeve` (
  `tournament_reeve_id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `role` enum('organizer','bracket_runner') NOT NULL DEFAULT 'bracket_runner',
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tournament_reeve_id`),
  UNIQUE KEY `uq_tourn_mundane` (`tournament_id`,`mundane_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1;

-- Convert any table already created as MyISAM by an earlier run of this migration.
-- Idempotent: no-op once the table is InnoDB.
SET @eng := (SELECT engine FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'ork_tournament_reeve');
SET @sql := IF(@eng = 'MyISAM', 'ALTER TABLE `ork_tournament_reeve` ENGINE=InnoDB', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
