-- ork_recommendations columns that exist in production but were never added by a
-- tracked migration -- the same class of gap as 2026-08-27-kingdomaward-ladder-columns.sql,
-- found by the schema-drift audit in docs/schema-drift-audit-2026-08-28.md.
--
-- snoozed_by_id / passed_to_local: read by Kingdom::GetAdminDashboard()
-- (class.Kingdom.php:123-124) to exclude snoozed and passed-to-local rows from the
-- open-recommendations count. Introduced by 9a2b8a5c on this branch.
--
-- Why this one is deploy-breaking rather than merely missing: the reference sits in a
-- raw DataSet() read, not a yapo write. Yapo drops unmappable columns and fails quietly;
-- a raw read against a missing column errors, DataSet() returns false, and the guard at
-- class.Kingdom.php:132 skips the entire assignment block -- taking OpenRecommendations,
-- UnwaiveredActive AND WaiveredMembers with it. WaiveredMembers is the pre-count for
-- Reset Waivers (controller.KingdomAjax.php:555), so on a fresh deploy that operation
-- would clear every waiver in the kingdom and report that it cleared 0 players.
--
-- Types mirror production exactly: snoozed_by_id is a nullable player id (NULL = not
-- snoozed); passed_to_local is a NOT NULL flag defaulting to 0. Both readers wrap the
-- column in COALESCE(..., 0), so either shape reads correctly, but matching prod keeps
-- fresh builds honest.
--
-- Idempotent: safe to re-run on dev and prod, where both columns already exist.
-- No backfill -- live rows already carry their values, and a fresh build correctly
-- starts with none.

ALTER TABLE `ork_recommendations`
    ADD COLUMN IF NOT EXISTS `snoozed_by_id`   INT(11)     NULL     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `passed_to_local` TINYINT(4)  NOT NULL DEFAULT 0;
