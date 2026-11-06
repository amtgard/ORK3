-- Migration: open an ork_officer_history term for every seated officer.
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-08-29-officer-history-backfill.sql
--
-- ork_officer_history held ONE row against 2,507 seated officers, so the rolls
-- were empty and no sitting officer had an open term. Both date columns are
-- already DEFAULT NULL, so this migration alters nothing -- it is pure data.
--
-- Idempotent ONLY once InsertOfficerRow's future-end-date rejection
-- (class.OfficerPosition.php, setOfficerByPosition's non-crown branch) is
-- deployed -- with that guard in place, no NEW future-dated row can be
-- created, so step 1's reopen matches nothing on a second run (end_date is
-- already NULL) and step 2's INSERT is guarded by a NOT EXISTS on the open
-- term. Re-running this migration against a database built from an OLDER
-- release (one still missing that rejection, so still able to accept a
-- caller-supplied future Term End) can find NEW legitimately-future-dated
-- rows -- someone recording a projected term end -- and step 1 will erase
-- them exactly like the one known-bad production row this migration was
-- written to repair: end_date set to NULL with no audit row and no
-- recoverable copy of the value it overwrote.
--
-- Order matters. Step 1 (reopen) MUST run before step 2 (insert): an officer
-- whose only history row carries a future end_date has no OPEN term, so if
-- the INSERT ran first its NOT EXISTS guard would see no open term and add a
-- SECOND row, leaving that officer with two. Running the reopen first turns
-- the future-dated row into the open term, and the INSERT's guard then
-- correctly skips them.
--
-- start_date is deliberately written as NULL for every row, never derived
-- from ork_officer.modified. Commit 52f729f7 (this branch) already reached
-- this conclusion in words: "Any history backfill has to treat pre-existing
-- rows as start-date-unknown rather than reading this column." Checking the
-- data confirms it: 42 rows share modified = 2022-11-17 across 42 DISTINCT
-- parks, and 17 rows share modified = 2024-09-10 across 17 distinct parks --
-- nobody takes office in dozens of different parks on the same day, so these
-- are bulk row-creation artifacts, not real appointment dates. Writing any of
-- them as start_date would be a fabricated date presented as recorded fact,
-- exactly what the design's "never present an inferred date as recorded
-- fact" rule forbids. NULL means "we do not know."
--
-- Both statements below also mirror OfficerPosition's legacy open-term match
-- (class.OfficerPosition.php ~line 1980): a history row can carry
-- position_id = 0 with the position identified by role/canonical key alone,
-- for rows that predate the position registry. Matching position_id strictly
-- would let such a legacy open row slip past the guard and pick up a SECOND
-- open term -- the exact defect this migration exists to remove.

-- 1. Reopen any term closed with a FUTURE end date whose holder is still seated.
--    Someone used Term End as a projected end date, which made a sitting officer
--    read as departed -- end_date IS NULL is what defines "current" everywhere.
--    Written generally rather than against the one known row.
UPDATE `ork_officer_history` h
  JOIN `ork_officer` o
    ON o.kingdom_id  = h.kingdom_id
   AND o.park_id     = h.park_id
   AND o.mundane_id  = h.mundane_id
   AND (h.position_id = o.position_id OR (h.position_id = 0 AND h.role = o.role))
   SET h.end_date = NULL
 WHERE h.end_date > CURDATE();

-- 2. Open a term for every seated officer that has none.
--    start_date is always NULL -- see the note above. display_label snapshots
--    the position's DisplayTitle using OfficerPosition::display_title_sql's
--    own tiering: a shared (kingdom_id = 0) position resolves through the
--    per-kingdom alias table, a kingdom-owned position resolves through its
--    own title_alias column -- never both, and never a plain COALESCE of the
--    two. The outer COALESCE(..., o.role) is only a defensive fallback for a
--    position_id that no longer resolves to a row at all.
INSERT INTO `ork_officer_history`
    (kingdom_id, park_id, mundane_id, role, position_id, display_label,
     start_date, end_date, changed_by, notes, created_at)
SELECT
    o.kingdom_id,
    o.park_id,
    o.mundane_id,
    o.role,
    o.position_id,
    COALESCE(
        IF(p.kingdom_id = 0,
            IF(a.title_alias IS NOT NULL AND a.title_alias <> '', a.title_alias, p.title),
            IF(p.title_alias IS NOT NULL AND p.title_alias <> '', p.title_alias, p.title)
        ),
        o.role
    ),
    NULL,
    NULL,
    NULL,
    NULL,
    NOW()
FROM `ork_officer` o
LEFT JOIN `ork_officer_position` p
       ON p.position_id = o.position_id
LEFT JOIN `ork_officer_position_alias` a
       ON a.kingdom_id = o.kingdom_id AND a.canonical_key = p.canonical_key
WHERE o.mundane_id > 0
  AND NOT EXISTS (
      SELECT 1 FROM `ork_officer_history` h
       WHERE h.kingdom_id  = o.kingdom_id
         AND h.park_id     = o.park_id
         AND h.mundane_id  = o.mundane_id
         AND (h.position_id = o.position_id OR (h.position_id = 0 AND h.role = o.role))
         AND h.end_date IS NULL
  );
