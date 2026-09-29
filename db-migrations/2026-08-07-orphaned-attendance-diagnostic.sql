-- ============================================================================
-- F007 — the 4,978 attendance rows already orphaned by the dead delete-guard.
--
-- DIAGNOSTIC ONLY. Nothing in this file changes data. Read it, run the SELECTs,
-- then decide.
--
-- Attendance::HasAttendance() never returned a usable value (it read a field off
-- a result set whose cursor had not been advanced), so the guard in
-- Event::DeleteEventDetail could not fire and deleting an occurrence silently
-- orphaned its attendance. The guard works now, so the set below is closed — it
-- cannot grow.
--
-- Measured on the production snapshot at time of writing:
--
--     4,978 attendance rows
--       143 distinct event_calendardetail_ids that no longer exist
--     3,540 distinct players
--     dates through 2025-12-04
--
-- These rows are NOT worthless. attendance carries mundane_id, date, park_id,
-- kingdom_id and credit information; only the link to the occurrence is broken.
-- They still count toward the player's attendance history, which is why the
-- right answer is almost certainly NOT "delete them".
--
-- The three options, in the order I would consider them:
--
--   1. LEAVE THEM (recommended default). They are historically accurate
--      attendance. Nothing reads event_calendardetail_id in a way that breaks
--      on a dangling id — reports join and simply get no occurrence detail.
--      Cost: any future FK constraint on the column has to exclude or repair
--      them first.
--
--   2. NEUTRALISE THE DANGLING LINK: set event_calendardetail_id = 0, which is
--      the same shape as attendance recorded without an occurrence. Keeps every
--      row and every credit, drops the broken reference. This is what you want
--      before adding a foreign key. See the statement at the bottom (commented).
--
--   3. DELETE THEM. Destroys real attendance history for 3,540 players. Do this
--      only if these are confirmed to be test/garbage rows — verify with the
--      per-occurrence breakdown below first.
--
-- Whichever is chosen, take a backup first.
-- ============================================================================

-- --- How many, and over what period? ---------------------------------------
SELECT COUNT(*)                                  AS rows_orphaned,
       COUNT(DISTINCT a.event_calendardetail_id) AS missing_occurrences,
       COUNT(DISTINCT a.mundane_id)              AS players_affected,
       MIN(a.date)                               AS earliest,
       MAX(a.date)                               AS latest
FROM ork_attendance a
LEFT JOIN ork_event_calendardetail d
       ON d.event_calendardetail_id = a.event_calendardetail_id
WHERE a.event_calendardetail_id > 0
  AND d.event_calendardetail_id IS NULL;

-- --- Which occurrences, and how much rides on each? ------------------------
-- Use this to judge whether the set looks like real park days or like test
-- data. Real park days cluster by park with plausible dates.
SELECT a.event_calendardetail_id,
       COUNT(*)                     AS attendance_rows,
       COUNT(DISTINCT a.mundane_id) AS players,
       MIN(a.date)                  AS earliest,
       MAX(a.date)                  AS latest,
       a.park_id,
       a.kingdom_id
FROM ork_attendance a
LEFT JOIN ork_event_calendardetail d
       ON d.event_calendardetail_id = a.event_calendardetail_id
WHERE a.event_calendardetail_id > 0
  AND d.event_calendardetail_id IS NULL
GROUP BY a.event_calendardetail_id, a.park_id, a.kingdom_id
ORDER BY attendance_rows DESC;

-- ---------------------------------------------------------------------------
-- OPTION 2 — neutralise the dangling link, keeping every row. Uncomment to run.
-- ---------------------------------------------------------------------------
-- UPDATE ork_attendance a
--   LEFT JOIN ork_event_calendardetail d
--          ON d.event_calendardetail_id = a.event_calendardetail_id
-- SET a.event_calendardetail_id = 0
-- WHERE a.event_calendardetail_id > 0
--   AND d.event_calendardetail_id IS NULL;
--
-- The MyISAM mirror carries the same column and should be kept in step:
-- UPDATE ork_attendance_myisam a
--   LEFT JOIN ork_event_calendardetail d
--          ON d.event_calendardetail_id = a.event_calendardetail_id
-- SET a.event_calendardetail_id = 0
-- WHERE a.event_calendardetail_id > 0
--   AND d.event_calendardetail_id IS NULL;
