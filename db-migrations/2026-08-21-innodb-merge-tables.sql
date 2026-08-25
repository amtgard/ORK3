-- ============================================================================
-- Convert the merge-path tables to InnoDB so the transaction work in this
-- branch actually protects them.
--
-- Park::MergeParks and Unit::MergeUnits now run their destructive statements
-- inside BeginTrans/CommitTrans/RollbackTrans (implemented on YapoMysql in
-- this branch). But six of the tables they write are still MyISAM — the
-- 2005-era default engine, never converted — and MyISAM silently ignores
-- transactions: begin/commit/rollback all report success while the writes
-- commit immediately. Proven empirically on the rendered sandbox: an INSERT
-- into ork_officer survives RollbackTrans; the identical probe on an InnoDB
-- table rolls back cleanly. So a mid-merge failure could permanently destroy
-- officer/authorization rows while reporting "rolled back".
--
-- Sizes at time of writing (production): the six tables total ~7 MB / ~69k
-- rows, so each ALTER is seconds of table lock. No FULLTEXT or other
-- special indexes exist on any of them (verified via information_schema).
--
-- Precedent: ork_mundane, ork_attendance, ork_awards, ork_event and friends
-- were converted to InnoDB years ago (their *_myisam twin tables on prod are
-- that era's backups). This finishes the job for the merge-path tables.
--
-- Deliberately NOT touched: every *_myisam-suffixed table (deliberate
-- backups/mirrors — ork_attendance_myisam feeds the nightly dump), and the
-- remaining historic MyISAM tables that no transactional code path writes.
--
-- Co-benefits: row-level locking on ork_authorization (read on every
-- permission check; MyISAM locked the whole table on any write) and InnoDB
-- crash recovery instead of REPAIR TABLE.
--
-- Idempotent: ALTER ... ENGINE=InnoDB on an already-InnoDB table is a no-op
-- rebuild. Safe to re-run.
--
-- Apply BEFORE or WITH the code deploy:
--
--     docker exec -i ork3-php8-db mariadb -u root -proot ork \
--         < db-migrations/2026-08-21-innodb-merge-tables.sql
-- ============================================================================

ALTER TABLE `ork_park` ENGINE=InnoDB;
ALTER TABLE `ork_officer` ENGINE=InnoDB;
ALTER TABLE `ork_authorization` ENGINE=InnoDB;
ALTER TABLE `ork_unit` ENGINE=InnoDB;
ALTER TABLE `ork_unit_mundane` ENGINE=InnoDB;
ALTER TABLE `ork_dues` ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Verification — must return no rows.
-- ---------------------------------------------------------------------------
-- SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
--   WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'MyISAM'
--   AND TABLE_NAME IN ('ork_park','ork_officer','ork_authorization',
--                      'ork_unit','ork_unit_mundane','ork_dues');
