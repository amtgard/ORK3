-- Covering indexes for the hottest tournament lookups: kingdom/park tournament lists by
-- date, bracket-by-tournament, participant-by-bracket, and match-by-tournament scans.
-- Column names verified against ork.sql / live schema:
--   ork_tournament(kingdom_id, park_id, date_time)  ork_bracket(tournament_id)
--   ork_participant(bracket_id)                      ork_match(tournament_id)
-- Each add is guarded via INFORMATION_SCHEMA.STATISTICS for idempotency (matches the
-- existing index-migration pattern in this repo, e.g. 2026-05-19-match-bracket-side-round).

-- ork_tournament — kingdom tournament list ordered by date
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_tournament'
    AND index_name = 'idx_tournament_kingdom_date'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_tournament_kingdom_date ON ork_tournament (kingdom_id, date_time)',
  'SELECT "idx_tournament_kingdom_date already exists" AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ork_tournament — park tournament list ordered by date
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_tournament'
    AND index_name = 'idx_tournament_park_date'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_tournament_park_date ON ork_tournament (park_id, date_time)',
  'SELECT "idx_tournament_park_date already exists" AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ork_bracket — brackets by tournament
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_bracket'
    AND index_name = 'idx_bracket_tournament'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_bracket_tournament ON ork_bracket (tournament_id)',
  'SELECT "idx_bracket_tournament already exists" AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ork_participant — participants by bracket (distinct from the existing composite
-- idx_participant_tourn_bracket (tournament_id, bracket_id); a bracket_id-only lookup
-- cannot use that composite as a leading prefix).
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_participant'
    AND index_name = 'idx_participant_bracket'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_participant_bracket ON ork_participant (bracket_id)',
  'SELECT "idx_participant_bracket already exists" AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ork_match — matches by tournament
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_match'
    AND index_name = 'idx_match_tournament'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_match_tournament ON ork_match (tournament_id)',
  'SELECT "idx_match_tournament already exists" AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
