-- ============================================================================
-- F019 — repair note rows whose date was stored as the Unix epoch.
--
-- Player::AddNote did date('Y-m-d', strtotime($request['DateComplete'])) with no
-- guard. strtotime('') is false, and date('Y-m-d', false) is 1969-12-31, so
-- every note saved with a blank completion date got the epoch — and it is
-- user-visible in the notes table. EditNote already guarded this correctly with
-- a ternary; AddNote now matches, so no new rows can acquire it.
--
-- At time of writing production held 16,549 affected rows.
--
-- The correct value for a blank date is "no date". ork_mundane_note.date and
-- .date_complete are both DATE NOT NULL, so the sentinel is the zero date --
-- which is exactly what the fixed AddNote/EditNote now write (they assign '',
-- and yapo passes it straight through to MySQL, which stores '0000-00-00';
-- Yapo::__set has its ValidateField call commented out, so no strtotime()
-- conversion happens). '0000-00-00' is the established "unset date" sentinel
-- elsewhere in the app and renders blank.
--
-- Only 1969-12-31 is touched — a genuine 1969-12-31 note date is not a
-- plausible Amtgard record (the game was founded in 1983).
--
-- Idempotent: safe to re-run.
--
-- NOT RUN AUTOMATICALLY. Take a backup, then:
--
--     docker exec -i ork3-php8-db mariadb -u root -proot ork \
--         < db-migrations/2026-08-07-repair-epoch-note-dates.sql
-- ============================================================================

UPDATE `ork_mundane_note`
SET `date_complete` = '0000-00-00'
WHERE `date_complete` = '1969-12-31';

UPDATE `ork_mundane_note`
SET `date` = '0000-00-00'
WHERE `date` = '1969-12-31';

-- ---------------------------------------------------------------------------
-- Verification — must return 0.
-- ---------------------------------------------------------------------------
-- SELECT COUNT(*) AS epoch_rows_remaining FROM ork_mundane_note
--   WHERE date = '1969-12-31' OR date_complete = '1969-12-31';
