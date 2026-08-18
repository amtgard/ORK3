-- Schema-only excerpt from 2026-04-21-danger-audit-schema-and-backfill.sql.
-- Base CREATE is not in db-migrations/; final shape matches mirror after this migration.

CREATE TABLE IF NOT EXISTS `ork_danger_audit` (
  `danger_audit_id` int(11) NOT NULL AUTO_INCREMENT,
  `method_call` varchar(100) NOT NULL,
  `parameters` text NOT NULL,
  `prior_state` text NOT NULL,
  `post_state` text NOT NULL,
  `by_whom_id` int(11) NOT NULL,
  `entity` varchar(30) NOT NULL,
  `entity_id` int(11) NOT NULL DEFAULT 0,
  `modified_at` datetime NOT NULL,
  PRIMARY KEY (`danger_audit_id`),
  KEY `method_call` (`method_call`),
  KEY `by_whom_id` (`by_whom_id`),
  KEY `modified_at` (`modified_at`),
  KEY `idx_entity_id` (`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
