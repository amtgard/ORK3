-- Officer Rolls: season-span history and an explicit current-term marker.
--
-- Backfilled history is recorded as season spans ("Spring 1995 to Fall 1996"),
-- and a past term may have a blank end meaning "end unknown". A null end_date
-- therefore no longer identifies the current officer, so "current" becomes the
-- explicit is_current column. Invariant: a row is current iff is_current = 1;
-- a current row has end_date IS NULL and no end season.
--
-- holder_name carries a name for a historical holder with no ORK account
-- (mundane_id = 0). The season/year pairs are the span as entered; start_date/
-- end_date stay the resolved calendar dates the rest of the app reads.
--
-- ork_officer_history is InnoDB; one ALTER, idempotent on re-run.
ALTER TABLE ork_officer_history
  ADD COLUMN IF NOT EXISTS holder_name VARCHAR(120) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS start_season ENUM('spring','summer','fall','winter') NULL,
  ADD COLUMN IF NOT EXISTS start_year SMALLINT NULL,
  ADD COLUMN IF NOT EXISTS end_season ENUM('spring','summer','fall','winter') NULL,
  ADD COLUMN IF NOT EXISTS end_year SMALLINT NULL,
  ADD COLUMN IF NOT EXISTS is_current TINYINT(1) NOT NULL DEFAULT 0,
  ADD INDEX IF NOT EXISTS idx_oh_current (kingdom_id, park_id, is_current);

-- is_current backfill
-- An open (null-end) row is current only when its holder sits in that seat
-- today. Legacy rows with position_id = 0 identify the office by role alone.
UPDATE ork_officer_history h
  JOIN ork_officer o
    ON o.kingdom_id = h.kingdom_id
   AND o.park_id = h.park_id
   AND o.mundane_id = h.mundane_id
   AND o.mundane_id > 0
   AND (h.position_id = o.position_id OR (h.position_id = 0 AND h.role = o.role))
   SET h.is_current = 1
 WHERE h.end_date IS NULL;

-- At most one current row per office: where two rows for the same office are
-- marked current, the highest officer_history_id keeps the mark.
UPDATE ork_officer_history h
  JOIN ork_officer_history newer
    ON newer.kingdom_id = h.kingdom_id
   AND newer.park_id = h.park_id
   AND newer.is_current = 1
   AND newer.officer_history_id > h.officer_history_id
   AND ((h.position_id > 0 AND newer.position_id = h.position_id)
        OR ((h.position_id = 0 OR newer.position_id = 0) AND newer.role = h.role))
   SET h.is_current = 0
 WHERE h.is_current = 1;
