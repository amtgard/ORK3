-- ork_kingdomaward columns that exist in production but were never added by a
-- tracked migration -- they arrived through direct database access or untracked
-- writes, and a fresh build from ork-db therefore lacks them entirely.
--
-- is_ladder / max_level: produced the 24 rows currently flagged is_ladder = 1.
-- Every kingdom-ladder surface reads or writes these columns. Added via direct DB
-- access in production.
--
-- disabled: backs award soft-delete, which already writes it on this branch
-- (class.Kingdom.php:384/509/544). Without this column, soft-delete silently fails
-- on a fresh deploy.
--
-- Idempotent: safe to re-run on dev and prod, where all three columns already exist.
-- No backfill -- live rows already carry their values, and a fresh build correctly
-- starts with none.

ALTER TABLE `ork_kingdomaward`
    ADD COLUMN IF NOT EXISTS `is_ladder` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `max_level` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `disabled`  TINYINT(1) NOT NULL DEFAULT 0;
