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
-- ork_officer_history is InnoDB; every statement is idempotent on re-run.
--
-- DEPLOY: apply this file BEFORE the code deploy, then run it once more right
-- after it. The new code reads and writes the columns added here. Against the
-- old schema its history writes fail (ExecuteChecked, logged) and a seat change
-- records no history. Code from before the deploy, still serving in between,
-- writes rows without start_ordinal / end_ordinal, without is_current, and
-- closes terms by end_date alone (record_officer_history on every seat
-- change). The second run stamps, marks and clears those (the ordinal steps
-- and is_current steps 0 and 1) and changes nothing else. Every later run is
-- a no-op on a consistent table.
ALTER TABLE ork_officer_history
  ADD COLUMN IF NOT EXISTS holder_name VARCHAR(120) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS start_season ENUM('spring','summer','fall','winter') NULL,
  ADD COLUMN IF NOT EXISTS start_year SMALLINT NULL,
  ADD COLUMN IF NOT EXISTS end_season ENUM('spring','summer','fall','winter') NULL,
  ADD COLUMN IF NOT EXISTS end_year SMALLINT NULL,
  ADD COLUMN IF NOT EXISTS is_current TINYINT(1) NOT NULL DEFAULT 0,
  ADD INDEX IF NOT EXISTS idx_oh_current (kingdom_id, park_id, is_current);

-- Stored season ordinals: a term's meaning, fixed when each side is saved.
--
-- An ordinal is year*4 + season (spring 1, summer 2, fall 3, winter 0), and a
-- winter is the year of its January-February: Dec YY and Jan-Feb YY+1 are one
-- winter, (YY+1)*4 (OfficerTermPeriod). A typed "Winter YY" means Dec YY or
-- Jan-Feb YY depending on the other end, so re-resolving the season columns
-- after an end is added moved a stored start. Every write path now stamps
-- start_ordinal / end_ordinal with the side it sets, and readers use them as
-- they are; the season columns stay the text as entered, for editing.
-- GetOfficerHistory orders on these in SQL; a per-org list is small, so the
-- sort needs no index of its own. idx_oh_rolls (an earlier draft of this file,
-- which could not serve that ORDER BY) is dropped where it was applied.
ALTER TABLE ork_officer_history
  ADD COLUMN IF NOT EXISTS start_ordinal INT NULL,
  ADD COLUMN IF NOT EXISTS end_ordinal INT NULL,
  DROP INDEX IF EXISTS idx_oh_rolls;

-- Ordinals 1. Backfill unstamped sides only (a re-run changes nothing). A date
--    wins and maps by month: December is the next year's winter, Jan-Feb this
--    year's, then Mar-May spring, Jun-Aug summer, Sep-Nov fall.
UPDATE ork_officer_history
   SET start_ordinal = CASE WHEN MONTH(start_date) = 12 THEN (YEAR(start_date) + 1) * 4
                            WHEN MONTH(start_date) <= 2 THEN YEAR(start_date) * 4
                            ELSE YEAR(start_date) * 4 + (MONTH(start_date) - 3) DIV 3 + 1 END
 WHERE start_ordinal IS NULL AND start_date IS NOT NULL;

UPDATE ork_officer_history
   SET end_ordinal = CASE WHEN MONTH(end_date) = 12 THEN (YEAR(end_date) + 1) * 4
                          WHEN MONTH(end_date) <= 2 THEN YEAR(end_date) * 4
                          ELSE YEAR(end_date) * 4 + (MONTH(end_date) - 3) DIV 3 + 1 END
 WHERE end_ordinal IS NULL AND end_date IS NOT NULL;

-- Ordinals 2. A season start with no date. Spring/Summer/Fall are fixed. A Winter start
--    is the December winter (Dec YY, (YY+1)*4) when the end entered with it
--    (an end season, or the exact end date beside it) lies at or after that
--    winter, else the January-February one (YY*4). An end date with no end
--    season is a later close of a current term, which entered its start alone,
--    so it does not turn the start (OfficerTermPeriod::parse for a lone winter).
--    Nor does a single typed winter (start and end the same Winter YY), even
--    with an exact end date beside it: it is the lone winter, YY*4.
UPDATE ork_officer_history
   SET start_ordinal = IF(start_season <> 'winter',
         start_year * 4 + FIELD(start_season, 'winter', 'spring', 'summer', 'fall') - 1,
         IF((start_year + 1) * 4 <= (CASE
               WHEN end_season IS NULL THEN NULL
               WHEN end_season = 'winter' AND end_year = start_year THEN NULL
               WHEN end_date IS NOT NULL THEN end_ordinal
               WHEN end_season = 'winter' THEN NULL
               ELSE end_year * 4 + FIELD(end_season, 'winter', 'spring', 'summer', 'fall') - 1 END),
            (start_year + 1) * 4, start_year * 4))
 WHERE start_ordinal IS NULL AND start_date IS NULL
   AND start_season IS NOT NULL AND start_year IS NOT NULL;

-- Ordinals 3. A season end with no date, against the start just stamped. A Winter end
--    is the January-February winter (YY*4) when that is at or after the start,
--    else the December one; after a Winter start it must follow the start, and
--    the same winter on both ends is one season.
UPDATE ork_officer_history
   SET end_ordinal = IF(end_season <> 'winter',
         end_year * 4 + FIELD(end_season, 'winter', 'spring', 'summer', 'fall') - 1,
         IF(start_season = 'winter' AND start_date IS NULL,
            IF(start_year = end_year, start_ordinal,
               IF(start_ordinal IS NULL OR end_year * 4 >= start_ordinal + 1, end_year * 4, (end_year + 1) * 4)),
            IF(start_ordinal IS NULL OR end_year * 4 >= start_ordinal, end_year * 4, (end_year + 1) * 4)))
 WHERE end_ordinal IS NULL AND end_date IS NULL
   AND end_season IS NOT NULL AND end_year IS NOT NULL;

-- is_current backfill
-- Current is per SEAT (office + holder): a supporting office may have several
-- holders at once, each with their own current row. A seat's office is its
-- position_id, or its role for a legacy row with position_id = 0. Every
-- statement below is idempotent, so re-running the file repairs state rather
-- than changing it: after a run, a row is current exactly when its holder sits
-- in that seat today, in an org that is not retired, and it has no end.
--
-- A. File every park row under its park's kingdom. TransferPark moves history
--    now. Before it did, a transferred park's rows stayed under the old
--    kingdom, where the park's rolls and the close paths no longer looked.
UPDATE ork_officer_history h
  JOIN ork_park p ON p.park_id = h.park_id
   SET h.kingdom_id = p.kingdom_id
 WHERE h.park_id > 0
   AND h.kingdom_id <> p.kingdom_id;

-- 0. Clear marks that cannot be current: a row with any end set, or one whose
--    holder no longer sits in that seat (vacated by a merge, a transfer, or
--    code that closed by end_date alone). The end is left as it is, so the
--    next seat change never stamps today over a real or unknown end.
UPDATE ork_officer_history h
   SET h.is_current = 0
 WHERE h.is_current = 1
   AND (h.end_date IS NOT NULL OR h.end_season IS NOT NULL
        OR h.end_year IS NOT NULL OR h.end_ordinal IS NOT NULL
        OR NOT EXISTS (
            SELECT 1 FROM ork_officer o
             WHERE o.kingdom_id = h.kingdom_id
               AND o.park_id = h.park_id
               AND o.mundane_id = h.mundane_id
               AND o.mundane_id > 0
               AND ((h.position_id > 0 AND o.position_id = h.position_id)
                    OR (h.position_id = 0 AND o.role = h.role))));

-- R. A retired park or kingdom has no current officers: clear its marks,
--    leaving each end unknown (RetirePark and RetireKingdom do the same).
--    Steps 1 and 3 skip retired orgs, so nothing re-marks them.
UPDATE ork_officer_history h
   SET h.is_current = 0
 WHERE h.is_current = 1
   AND (EXISTS (SELECT 1 FROM ork_park rp
                 WHERE rp.park_id = h.park_id AND h.park_id > 0 AND rp.active = 'Retired')
        OR EXISTS (SELECT 1 FROM ork_kingdom rk
                    WHERE rk.kingdom_id = h.kingdom_id AND rk.active = 'Retired'));

-- 1. Mark a legacy open row (no seasons, no plain-text holder) current when its
--    holder sits in that seat today, the org is not retired, and the seat has
--    no current row yet. A row written through the ledger is never touched.
UPDATE ork_officer_history h
  JOIN ork_officer o
    ON o.kingdom_id = h.kingdom_id
   AND o.park_id = h.park_id
   AND o.mundane_id = h.mundane_id
   AND o.mundane_id > 0
   AND ((h.position_id > 0 AND o.position_id = h.position_id)
        OR (h.position_id = 0 AND o.role = h.role))
  LEFT JOIN ork_officer_history c
    ON c.kingdom_id = h.kingdom_id
   AND c.park_id = h.park_id
   AND c.mundane_id = h.mundane_id
   AND c.is_current = 1
   AND c.officer_history_id <> h.officer_history_id
   AND ((o.position_id > 0 AND c.position_id = o.position_id)
        OR (c.position_id = 0 AND c.role = o.role))
   SET h.is_current = 1
 WHERE h.end_date IS NULL
   AND h.end_season IS NULL
   AND h.end_year IS NULL
   AND h.end_ordinal IS NULL
   AND h.is_current = 0
   AND h.start_season IS NULL
   AND h.holder_name = ''
   AND c.officer_history_id IS NULL
   AND NOT EXISTS (SELECT 1 FROM ork_park rp
                    WHERE rp.park_id = h.park_id AND h.park_id > 0 AND rp.active = 'Retired')
   AND NOT EXISTS (SELECT 1 FROM ork_kingdom rk
                    WHERE rk.kingdom_id = h.kingdom_id AND rk.active = 'Retired');

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

-- 3. A seated officer of an org that is not retired, whose seat still has no
--    current row, gets one: start and end unknown, office and label written as
--    record_officer_history writes them (role = canonical key, display_label =
--    the kingdom's display title).
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
   AND NOT EXISTS (SELECT 1 FROM ork_park rp
                    WHERE rp.park_id = o.park_id AND o.park_id > 0 AND rp.active = 'Retired')
   AND NOT EXISTS (SELECT 1 FROM ork_kingdom rk
                    WHERE rk.kingdom_id = o.kingdom_id AND rk.active = 'Retired')
   AND NOT EXISTS (
       SELECT 1 FROM ork_officer_history c
        WHERE c.kingdom_id = o.kingdom_id
          AND c.park_id = o.park_id
          AND c.mundane_id = o.mundane_id
          AND c.is_current = 1
          AND ((o.position_id > 0 AND c.position_id = o.position_id)
               OR (c.position_id = 0 AND c.role = o.role)));
