-- Composite index for PostMatchResult / ResetMatch aggregate queries.
-- These queries filter by bracket_id + bracket_side + round on every live-ops action.
-- Use IF NOT EXISTS guards via INFORMATION_SCHEMA for idempotency.
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_match'
    AND index_name = 'idx_match_bracket_side_round'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_match_bracket_side_round ON ork_match (bracket_id, bracket_side, round)',
  'SELECT "idx_match_bracket_side_round already exists" AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
