-- =====================================================================
-- Northern Lights "Custom Award" duplicate cleanup
-- Date: 2026-06-03
--
-- PROBLEM
--   The Kingdom of Northern Lights (kingdom_id 20) has TWO ork_kingdomaward
--   rows named 'Custom Award':
--     * 3463  award_id 94   -> resolves to ork_award 94 'Custom Award'  (CORRECT)
--     * 3556  award_id 187  -> 187 does NOT exist in ork_award          (ORPHAN:
--             a holdover from an old master-list renumbering)
--   346 awardings across 181 members were filed under the orphaned 3556 (all with
--   ork_awards.award_id = 187). The awards display joins ork_award via
--   ork_awards.award_id, so the dead 187 link means the "(Custom Award)" descriptor
--   can't be resolved and these awards can't be reconciled to the Titles tab.
--   No awards are lost or mis-assigned -- this is purely a broken-link repair.
--
-- FIX
--   Repoint the 346 orphaned awardings onto the correct entry (kingdomaward 3463 /
--   award_id 94), then delete the orphaned 3556 entry so it can't be used again.
--
-- BEFORE RUNNING
--   1. Take a backup with mariadb-dump --no-create-info (see PR runbook for the
--      exact commands). DO NOT omit --no-create-info or the backup will DROP+CREATE
--      the table on restore and wipe everything not in the --where= slice.
--   2. Keep the rollback script handy: 2026-06-03-northern-lights-custom-award-dedup-rollback.sql
--
-- SAFETY
--   * Scoped WHERE clauses only touch the 346 orphaned rows.
--   * The DELETE only fires once NOTHING references 3556 (NOT EXISTS guard).
--   * Re-runnable: once applied, the UPDATE and DELETE both match 0 rows.
--   * If the correct 'Custom Award' entry can't be found, @good is NULL and the
--     whole script safely no-ops (UPDATE guarded by "@good IS NOT NULL").
-- =====================================================================

-- Resolve the correct (master-linked) Custom Award entry for Northern Lights.
SET @good := (SELECT kingdomaward_id FROM ork_kingdomaward
              WHERE kingdom_id = 20 AND name = 'Custom Award' AND award_id = 94
              LIMIT 1);

-- ---- BEFORE (review) ----
-- The ID anchor (3463) is the load-bearing check; absolute counts drift upward
-- each week as Northern Lights keeps filing awards on the orphan. As of
-- 2026-06-10 prod shows 361/188 (was 346/181 when this migration was authored).
SELECT @good AS good_kingdomaward_id;                      -- expect 3463
SELECT COUNT(*)              AS orphaned_awardings_before, -- expect a positive count (361 on 2026-06-10)
       COUNT(DISTINCT mundane_id) AS members              -- (188 on 2026-06-10)
  FROM ork_awards WHERE kingdomaward_id = 3556 AND award_id = 187;

START TRANSACTION;

-- 1) Repoint the orphaned awardings onto the correct Custom Award entry.
UPDATE ork_awards
   SET kingdomaward_id = @good,
       award_id        = 94
 WHERE @good IS NOT NULL
   AND kingdomaward_id = 3556
   AND award_id        = 187;

-- 2) Remove the orphaned duplicate entry (only if nothing references it now).
DELETE FROM ork_kingdomaward
 WHERE kingdomaward_id = 3556
   AND kingdom_id      = 20
   AND name            = 'Custom Award'
   AND award_id        = 187
   AND NOT EXISTS (SELECT 1 FROM ork_awards aw WHERE aw.kingdomaward_id = 3556);

-- ---- AFTER (review before committing) ----
SELECT COUNT(*) AS rows_still_on_3556        FROM ork_awards        WHERE kingdomaward_id = 3556;        -- expect 0
SELECT COUNT(*) AS bad_entry_remaining       FROM ork_kingdomaward  WHERE kingdomaward_id = 3556;        -- expect 0
SELECT COUNT(*) AS award187_left_on_good     FROM ork_awards        WHERE kingdomaward_id = @good AND award_id = 187; -- expect 0
SELECT COUNT(*) AS total_on_good_entry       FROM ork_awards        WHERE kingdomaward_id = @good;       -- expect prior_good + 346

-- If the AFTER numbers look right, keep COMMIT. Otherwise replace with: ROLLBACK;
COMMIT;
