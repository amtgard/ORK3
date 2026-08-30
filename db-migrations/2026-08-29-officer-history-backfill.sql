-- Migration: open an ork_officer_history term for every seated officer.
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-08-29-officer-history-backfill.sql
--
-- ork_officer_history held ONE row against 2,507 seated officers, so the rolls
-- were empty and no sitting officer had an open term. Both date columns are
-- already DEFAULT NULL, so this migration alters nothing -- it is pure data.
--
-- Idempotent: the reopen matches nothing on a second run (end_date is already
-- NULL), and the INSERT is guarded by a NOT EXISTS on the open term.
--
-- Order matters. Step 1 (reopen) MUST run before step 2 (insert): an officer
-- whose only history row carries a future end_date has no OPEN term, so if
-- the INSERT ran first its NOT EXISTS guard would see no open term and add a
-- SECOND row, leaving that officer with two. Running the reopen first turns
-- the future-dated row into the open term, and the INSERT's guard then
-- correctly skips them.

-- 1. Reopen any term closed with a FUTURE end date whose holder is still seated.
--    Someone used Term End as a projected end date, which made a sitting officer
--    read as departed -- end_date IS NULL is what defines "current" everywhere.
--    Written generally rather than against the one known row.
UPDATE `ork_officer_history` h
  JOIN `ork_officer` o
    ON o.kingdom_id  = h.kingdom_id
   AND o.park_id     = h.park_id
   AND o.position_id = h.position_id
   AND o.mundane_id  = h.mundane_id
   SET h.end_date = NULL
 WHERE h.end_date > CURDATE();

-- 2. Open a term for every seated officer that has none.
--    start_date takes ork_officer.modified where it is usable, and NULL otherwise.
--    NULL, never a derived date: 97% of rows have a zero modified, and presenting an
--    inferred date as a recorded fact is its own bug.
INSERT INTO `ork_officer_history`
    (kingdom_id, park_id, mundane_id, role, position_id, display_label,
     start_date, end_date, changed_by, notes, created_at)
SELECT
    o.kingdom_id,
    o.park_id,
    o.mundane_id,
    o.role,
    o.position_id,
    IF(a.title_alias IS NOT NULL AND a.title_alias <> '', a.title_alias, COALESCE(p.title, o.role)),
    CASE
        WHEN o.modified IS NULL THEN NULL
        WHEN DATE(o.modified) <= '1970-01-01' THEN NULL
        WHEN DATE(o.modified) > CURDATE() THEN NULL
        ELSE DATE(o.modified)
    END,
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
         AND h.position_id = o.position_id
         AND h.mundane_id  = o.mundane_id
         AND h.end_date IS NULL
  );
