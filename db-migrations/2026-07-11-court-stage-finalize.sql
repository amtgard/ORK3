-- Court Planner: Stage/Finalize + Run-vs-Plan intent
-- Spec: docs/superpowers/specs/2026-07-11-court-planner-stage-finalize-design.md §4
--
-- Splits "grant at court" (stage) from "commit to the permanent player record"
-- (finalize), and stores the run-vs-plan intent on the court row.

-- Run vs Plan intent + finalize audit trail.
ALTER TABLE ork_court
    ADD COLUMN mode ENUM('run','plan') NOT NULL DEFAULT 'run' AFTER status,
    ADD COLUMN finalized_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN finalized_by INT NULL DEFAULT NULL;

-- Captured grant metadata (who granted) + the committed-award linkage set at finalize.
ALTER TABLE ork_court_award
    ADD COLUMN given_by_mundane_id INT NULL DEFAULT NULL AFTER mundane_id,
    ADD COLUMN award_id INT NULL DEFAULT NULL AFTER recommendations_id;

-- ork_court_award.status is an ENUM on this database (probed via SHOW COLUMNS),
-- so widen it to add the new 'staged' value while keeping every existing value.
-- (If a target DB has this column as VARCHAR instead, skip this ALTER and rely on
-- the app-level validation lists, which now include 'staged'.)
ALTER TABLE ork_court_award
    MODIFY COLUMN status ENUM('planned','announced','staged','given','cancelled') NOT NULL DEFAULT 'planned';
