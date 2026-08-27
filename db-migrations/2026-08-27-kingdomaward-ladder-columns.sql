-- ork_kingdomaward.is_ladder / max_level exist in production but were never
-- added by a tracked migration -- they arrived through direct database access,
-- the same route that produced the 24 rows currently flagged is_ladder = 1.
-- A fresh build from ork-db therefore lacks both columns entirely, and every
-- kingdom-ladder surface reads or writes them.
--
-- Idempotent: safe to re-run on dev and prod, where the columns already exist.
-- No backfill -- the 24 live rows already carry their values, and a fresh build
-- correctly starts with none.

ALTER TABLE `ork_kingdomaward`
    ADD COLUMN IF NOT EXISTS `is_ladder` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `max_level` INT(11) NOT NULL DEFAULT 0;
