-- Recommendation resolution: how a closed (soft-deleted) recommendation was closed.
-- Granting a recommendation (Court Planner commit, Recommendations Manager grant,
-- "Give from rec" on the Kingdom/Park pages) and dismissing one both soft-delete
-- the row (deleted_at / deleted_by), so until now a grant read as a dismissal in
-- every "Show dismissed" view and could be Restored, re-opening it for a second
-- grant. The app writes this on every close and clears it on Restore:
--   NULL      = live (or a pre-migration close not yet backfilled)
--   granted   = closed because the award was given
--   dismissed = closed without a grant
-- Historic rows are classified by 2026-11-22-recommendation-resolution-backfill.sql
-- (production only; run after this file).
-- ork_recommendations is MyISAM: one ALTER, one table copy. Idempotent.
ALTER TABLE ork_recommendations
  ADD COLUMN IF NOT EXISTS resolution ENUM('granted','dismissed') NULL DEFAULT NULL AFTER deleted_by;
