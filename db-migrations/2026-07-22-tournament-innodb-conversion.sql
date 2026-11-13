-- Convert the shared tournament tables from MyISAM to InnoDB, then create the foreign keys
-- that 2026-07-14-tournament-foreign-keys.sql intentionally SKIPPED because their parents
-- were still MyISAM (a cross-engine FK is rejected by InnoDB).
--
-- !! MAINTENANCE WINDOW REQUIRED !!
-- ALTER TABLE ... ENGINE=InnoDB rebuilds each table and holds a metadata/table lock for the
-- duration. ork_match / ork_participant can be large. Run this during a maintenance window
-- with tournament writes quiesced. It is NOT an online, lock-free operation.
--
-- Engine conversion PRESERVES the PERSISTENT generated columns (reg_number_uk,
-- entrant_number_uk) and the UNIQUE indexes (uq_participant_reg_number,
-- uq_participant_bracket_number) on ork_participant — an ENGINE change copies the full
-- table definition. This is verified post-migration via SHOW CREATE TABLE ork_participant.
--
-- Idempotent: each ENGINE change is a no-op if the table is already InnoDB (or absent);
-- orphan cleanup no-ops when clean; each FK add is guarded by constraint name.

-- =====================================================================================
-- 1. MyISAM -> InnoDB (guarded on current ENGINE; no-op if already InnoDB or table absent)
-- =====================================================================================
SET @eng := (SELECT ENGINE FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'ork_tournament');
SET @sql := IF(@eng = 'MyISAM', 'ALTER TABLE ork_tournament ENGINE=InnoDB',
               'SELECT ''ork_tournament already InnoDB or absent'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @eng := (SELECT ENGINE FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'ork_bracket');
SET @sql := IF(@eng = 'MyISAM', 'ALTER TABLE ork_bracket ENGINE=InnoDB',
               'SELECT ''ork_bracket already InnoDB or absent'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @eng := (SELECT ENGINE FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'ork_participant');
SET @sql := IF(@eng = 'MyISAM', 'ALTER TABLE ork_participant ENGINE=InnoDB',
               'SELECT ''ork_participant already InnoDB or absent'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @eng := (SELECT ENGINE FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'ork_match');
SET @sql := IF(@eng = 'MyISAM', 'ALTER TABLE ork_match ENGINE=InnoDB',
               'SELECT ''ork_match already InnoDB or absent'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @eng := (SELECT ENGINE FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'ork_seed');
SET @sql := IF(@eng = 'MyISAM', 'ALTER TABLE ork_seed ENGINE=InnoDB',
               'SELECT ''ork_seed already InnoDB or absent'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================================================
-- 2. Foreign keys previously skipped (MyISAM parents, now InnoDB). Match the FK style of
--    2026-07-14-tournament-foreign-keys.sql: remove genuinely-parentless child rows first
--    (they are meaningless without a parent and would be CASCADE-removed anyway), then add
--    each FK guarded by constraint name. ON DELETE CASCADE on all four: a score cell, a
--    seed, or an event/seq row has no meaning once its parent bracket/tournament is gone.
-- =====================================================================================

-- ork_point_score.bracket_id -> ork_bracket(bracket_id)
DELETE ps FROM ork_point_score ps
  LEFT JOIN ork_bracket b ON b.bracket_id = ps.bracket_id
  WHERE b.bracket_id IS NULL;
SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE table_schema = DATABASE() AND table_name = 'ork_point_score'
              AND constraint_name = 'fk_ps_bracket' AND constraint_type = 'FOREIGN KEY');
SET @sql := IF(@fk = 0,
  'ALTER TABLE ork_point_score
     ADD CONSTRAINT fk_ps_bracket FOREIGN KEY (bracket_id)
     REFERENCES ork_bracket (bracket_id) ON DELETE CASCADE',
  'SELECT "fk_ps_bracket already exists" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ork_point_score.participant_id -> ork_participant(participant_id)
DELETE ps FROM ork_point_score ps
  LEFT JOIN ork_participant p ON p.participant_id = ps.participant_id
  WHERE p.participant_id IS NULL;
SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE table_schema = DATABASE() AND table_name = 'ork_point_score'
              AND constraint_name = 'fk_ps_participant' AND constraint_type = 'FOREIGN KEY');
SET @sql := IF(@fk = 0,
  'ALTER TABLE ork_point_score
     ADD CONSTRAINT fk_ps_participant FOREIGN KEY (participant_id)
     REFERENCES ork_participant (participant_id) ON DELETE CASCADE',
  'SELECT "fk_ps_participant already exists" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ork_tournament_event.tournament_id -> ork_tournament(tournament_id)
DELETE te FROM ork_tournament_event te
  LEFT JOIN ork_tournament t ON t.tournament_id = te.tournament_id
  WHERE t.tournament_id IS NULL;
SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE table_schema = DATABASE() AND table_name = 'ork_tournament_event'
              AND constraint_name = 'fk_te_tournament' AND constraint_type = 'FOREIGN KEY');
SET @sql := IF(@fk = 0,
  'ALTER TABLE ork_tournament_event
     ADD CONSTRAINT fk_te_tournament FOREIGN KEY (tournament_id)
     REFERENCES ork_tournament (tournament_id) ON DELETE CASCADE',
  'SELECT "fk_te_tournament already exists" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ork_tournament_seq.tournament_id -> ork_tournament(tournament_id)
DELETE ts FROM ork_tournament_seq ts
  LEFT JOIN ork_tournament t ON t.tournament_id = ts.tournament_id
  WHERE t.tournament_id IS NULL;
SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE table_schema = DATABASE() AND table_name = 'ork_tournament_seq'
              AND constraint_name = 'fk_tseq_tournament' AND constraint_type = 'FOREIGN KEY');
SET @sql := IF(@fk = 0,
  'ALTER TABLE ork_tournament_seq
     ADD CONSTRAINT fk_tseq_tournament FOREIGN KEY (tournament_id)
     REFERENCES ork_tournament (tournament_id) ON DELETE CASCADE',
  'SELECT "fk_tseq_tournament already exists" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
