-- Court Planner distributed-review fixes (2026-09-09).
--
-- STATEMENT ORDER MATTERS. Applied the usual way (`mariadb ... < thisfile`) the
-- client stops at the first error, so every ADD COLUMN the shipped code hard-
-- requires runs FIRST and the one statement that can legitimately fail on a
-- populated database -- the unique index, which a pre-existing duplicate
-- (court_id, recommendations_id) rejects -- runs LAST. A schema that is missing
-- only the index still serves the code; a schema missing the columns does not.
-- Every statement is IF [NOT] EXISTS, so the file is safe to re-run.

-- 1. ork_court_award.last_finalize_error
--    finalize_court's only record of a failed commit was the JSON in a one-time
--    toast. Persist the per-row failure on the line itself so the cause survives
--    the response and is queryable without reproducing the failure. Cleared on a
--    successful commit.
ALTER TABLE ork_court_award
  ADD COLUMN IF NOT EXISTS last_finalize_error TEXT NULL DEFAULT NULL AFTER award_id;

-- 2. ork_court_award.public_comment_cleared
--    The award editor offers a "(Clear)" action that empties the citation box and
--    tells the officer nothing of the recommendation's wording will be published.
--    An empty public_comment alone cannot say that: commitStagedAward falls back
--    to the originating recommendation's reason when public_comment is blank, and
--    a never-touched line must keep doing so. Record the officer's EXPLICIT
--    intent, so "blank because cleared" (publish nothing) is distinguishable from
--    "blank because untouched" (inherit the rec reason). Confidential, often
--    anonymous recommender wording otherwise reaches ork_awards.note, the public
--    profile and the login-free Court Report -- permanently.
ALTER TABLE ork_court_award
  ADD COLUMN IF NOT EXISTS public_comment_cleared TINYINT NOT NULL DEFAULT 0 AFTER public_comment;

-- 3. ork_court.giver_snapshot
--    ork_officer is replaced in place and carries no history, so a court recorded
--    after Coronation credited the incoming monarch on every line. Snapshot the
--    court's giver roster (JSON: default + pills, as getCourtGiverOptions returns
--    it) when the court is published or first printed, and serve that snapshot for
--    a past-dated court instead of today's seated officers.
ALTER TABLE ork_court
  ADD COLUMN IF NOT EXISTS giver_snapshot TEXT NULL DEFAULT NULL AFTER recorder_mundane_id;

-- 4. ork_kingdomaward.is_ladder in the test schema
--    The column exists in dev and prod but was missing from ork_test, which is why
--    Court::ledgerAlreadyHasHonor carried a TODO refusing to reference it (a
--    missing column fails the whole probe open). Bring the test schema in line so
--    the per-kingdom ladder override can be read honestly everywhere. No-op where
--    the column already exists.
ALTER TABLE ork_kingdomaward
  ADD COLUMN IF NOT EXISTS is_ladder TINYINT(1) NOT NULL DEFAULT 0 AFTER award_id;

-- 5. ork_court_award unique (court_id, recommendations_id) -- LAST, see header.
--    Court::addAward de-duplicates a recommendation-backed line with a
--    check-then-insert, which two reeves working the same pending-recs list can
--    both pass. One recommendation is one honor, so a court carries it at most
--    once -- let the database say so. NULL != NULL in a MariaDB unique index, so
--    walk-on lines (recommendations_id NULL) are unaffected and stay repeatable.
--    addAward now reads the row back by this unique pair after its INSERT rather
--    than trusting LAST_INSERT_ID(), so the loser of the race is handed the
--    winning row instead of a silent failure.
--
--    If this ALTER fails with a duplicate-entry error, the database already holds
--    two lines for one recommendation on one court. Resolve them by hand (keep the
--    live line, cancel or delete the redundant one) and re-run this file; the four
--    statements above will no-op.
ALTER TABLE ork_court_award
  ADD UNIQUE KEY IF NOT EXISTS uniq_court_rec (court_id, recommendations_id);
