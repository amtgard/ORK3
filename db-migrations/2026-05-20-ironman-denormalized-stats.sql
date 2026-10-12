-- Ironman v2: denormalized per-fighter global stats on ork_participant, so recording
-- a win is O(1) and GetStandings reads columns instead of replaying every match.
--   im_wins           - total wins across all rings
--   im_current_streak - current consecutive-win run (resets to 0 when dethroned)
--   im_max_streak     - high-water mark, used for standings ranking
-- Plus a (bracket_id, ring_number, `order`) index so "previous king of ring R" is a
-- single indexed lookup on the hot path. All guarded for idempotency.

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'ork_participant' AND column_name = 'im_wins');
SET @sql := IF(@c = 0, 'ALTER TABLE ork_participant ADD COLUMN im_wins INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'ork_participant' AND column_name = 'im_current_streak');
SET @sql := IF(@c = 0, 'ALTER TABLE ork_participant ADD COLUMN im_current_streak INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'ork_participant' AND column_name = 'im_max_streak');
SET @sql := IF(@c = 0, 'ALTER TABLE ork_participant ADD COLUMN im_max_streak INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @i := (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND table_name = 'ork_match' AND index_name = 'idx_match_bracket_ring_order');
SET @sql := IF(@i = 0, 'CREATE INDEX idx_match_bracket_ring_order ON ork_match (bracket_id, ring_number, `order`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
