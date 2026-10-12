-- Composite index for the ironman streak scan in GetStandings:
--   SELECT ... FROM ork_match WHERE bracket_id = ? AND result IS NOT NULL ORDER BY `order` ASC
-- Without (bracket_id, `order`) this range-scans by bracket_id then filesorts by `order`
-- on every standings load for an ironman bracket. The composite lets it read in index order.
-- Idempotent via INFORMATION_SCHEMA guard.
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_match'
    AND index_name = 'idx_match_bracket_order'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_match_bracket_order ON ork_match (bracket_id, `order`)',
  'SELECT "idx_match_bracket_order already exists" AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
