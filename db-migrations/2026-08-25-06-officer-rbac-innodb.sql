-- Migration: convert the officer-admin / RBAC tables to InnoDB and index rbac_role_id
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/officer-rbac-innodb.sql
--
-- Depends on: officer-position.sql, officer_history.sql, rbac-tables.sql
-- Idempotent: ENGINE=InnoDB on an InnoDB table is a no-op rebuild; the index uses IF NOT EXISTS.
--
-- Why:
--   The CREATE TABLE statements in those three migrations originally said ENGINE=MyISAM,
--   copying the 2005-era default the rest of this schema still carries. MyISAM has no
--   transactions -- it does not error on BEGIN/COMMIT/ROLLBACK, it silently ignores them.
--   OfficerPosition::ReconcileRoleBinding() wraps its revoke/grant pair in
--   BeginTrans/CommitTrans/RollbackTrans precisely so a failure between the DELETE and the
--   INSERT cannot strand occupants with neither the old nor the new RBAC role. On MyISAM
--   that protection is inert: the DELETE commits the instant it runs.
--
--   The same reasoning, and the same fix, is recorded in
--   db-migrations/2026-08-21-innodb-merge-tables.sql for the park/unit merge paths.
--
--   The CREATEs are also corrected in place, so a fresh install gets InnoDB directly; this
--   file exists for databases that already ran them.
--
-- Note: these tables are small (a kingdom has tens of positions, a few hundred role rows),
-- so the rebuild is fast. No FK constraints are added -- this schema enforces referential
-- integrity in PHP by project convention.

ALTER TABLE `ork_officer_position`       ENGINE=InnoDB;
ALTER TABLE `ork_officer_position_alias` ENGINE=InnoDB;
ALTER TABLE `ork_officer_history`        ENGINE=InnoDB;
ALTER TABLE `ork_role`                   ENGINE=InnoDB;
ALTER TABLE `ork_permission`             ENGINE=InnoDB;
ALTER TABLE `ork_role_permission`        ENGINE=InnoDB;
ALTER TABLE `ork_user_role`              ENGINE=InnoDB;
ALTER TABLE `ork_rbac_audit`             ENGINE=InnoDB;

-- RBACService::RoleIsOfficerBound() filters ork_officer_position on rbac_role_id and had
-- no index for it, so every call was a full scan of the registry.
ALTER TABLE `ork_officer_position`
  ADD INDEX IF NOT EXISTS `idx_rbac_role` (`rbac_role_id`);

-- Verify (informational):
-- SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND TABLE_NAME IN ('ork_officer_position','ork_officer_position_alias','ork_officer_history',
--                       'ork_role','ork_permission','ork_role_permission','ork_user_role','ork_rbac_audit');
