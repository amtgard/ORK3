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
-- Current is per SEAT (office + holder): a supporting office may have several
-- holders at once, each with their own current row. Every statement below is
-- idempotent, so re-running the file repairs state rather than changing it.
--
-- 1. Mark a legacy open row (no seasons, no plain-text holder) current when its
--    holder sits in that seat today and the seat has no current row yet.
--    Legacy rows with position_id = 0 identify the office by role alone. A row
--    written through the ledger is never touched.
UPDATE ork_officer_history h
  JOIN ork_officer o
    ON o.kingdom_id = h.kingdom_id
   AND o.park_id = h.park_id
   AND o.mundane_id = h.mundane_id
   AND o.mundane_id > 0
   AND (h.position_id = o.position_id OR (h.position_id = 0 AND h.role = o.role))
  LEFT JOIN ork_officer_history c
    ON c.kingdom_id = h.kingdom_id
   AND c.park_id = h.park_id
   AND c.mundane_id = h.mundane_id
   AND c.is_current = 1
   AND c.officer_history_id <> h.officer_history_id
   AND (c.position_id = o.position_id OR (c.position_id = 0 AND c.role = o.role))
   SET h.is_current = 1
 WHERE h.end_date IS NULL
   AND h.is_current = 0
   AND h.start_season IS NULL
   AND h.end_season IS NULL
   AND h.holder_name = ''
   AND c.officer_history_id IS NULL;

-- 2. At most one current row per seat: where one holder has two current rows
--    for the same office, the latest start_date keeps the mark (an unknown
--    start counts as earliest, and a tie goes to the highest officer_history_id).
UPDATE ork_officer_history h
  JOIN ork_officer_history newer
    ON newer.kingdom_id = h.kingdom_id
   AND newer.park_id = h.park_id
   AND newer.mundane_id = h.mundane_id
   AND newer.is_current = 1
   AND newer.officer_history_id <> h.officer_history_id
   AND ((h.position_id > 0 AND newer.position_id = h.position_id)
        OR ((h.position_id = 0 OR newer.position_id = 0) AND newer.role = h.role))
   AND (COALESCE(newer.start_date, '1000-01-01') > COALESCE(h.start_date, '1000-01-01')
        OR (COALESCE(newer.start_date, '1000-01-01') = COALESCE(h.start_date, '1000-01-01')
            AND newer.officer_history_id > h.officer_history_id))
   SET h.is_current = 0
 WHERE h.is_current = 1;

-- 3. A seated officer whose seat still has no current row gets one: start and
--    end unknown, office and label written as record_officer_history writes
--    them (role = canonical key, display_label = the kingdom's display title).
INSERT INTO ork_officer_history
       (kingdom_id, park_id, mundane_id, role, position_id, display_label,
        start_date, end_date, is_current, changed_by, notes, created_at)
SELECT o.kingdom_id, o.park_id, o.mundane_id,
       COALESCE(p.canonical_key, o.role), o.position_id,
       LEFT(COALESCE(IF(p.kingdom_id = 0,
                        IF(a.title_alias IS NOT NULL AND a.title_alias != '', a.title_alias, p.title),
                        IF(p.title_alias != '', p.title_alias, p.title)),
                     o.role), 80),
       NULL, NULL, 1, NULL, NULL, NOW()
  FROM ork_officer o
  LEFT JOIN ork_officer_position p ON p.position_id = o.position_id AND o.position_id > 0
  LEFT JOIN ork_officer_position_alias a ON a.kingdom_id = o.kingdom_id AND a.canonical_key = p.canonical_key
 WHERE o.mundane_id > 0
   AND NOT EXISTS (
       SELECT 1 FROM ork_officer_history c
        WHERE c.kingdom_id = o.kingdom_id
          AND c.park_id = o.park_id
          AND c.mundane_id = o.mundane_id
          AND c.is_current = 1
          AND (c.position_id = o.position_id OR (c.position_id = 0 AND c.role = o.role)));
