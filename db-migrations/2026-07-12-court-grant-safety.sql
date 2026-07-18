-- Court Planner: Grant Safety (optimistic concurrency token)
-- Spec: docs/superpowers/specs/2026-07-12-court-planner-grant-safety-design.md §S5
--
-- Adds a reliable per-row optimistic-concurrency token to court_award. `modified`
-- is second-granular and collision-prone, so mutating endpoints instead guard on
-- `row_version` (UPDATE ... AND row_version = ?); every successful mutating write
-- increments it. 0 rows affected => the row changed under the client => the
-- controller emits a non-destructive 409 "this row changed — reload".

ALTER TABLE ork_court_award
    ADD COLUMN row_version INT NOT NULL DEFAULT 0 AFTER modified;
