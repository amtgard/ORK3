-- Survey options: exclusive choices (follow-up to 2026-09-09-survey-module.sql).
-- Purpose:
--   * ork_survey_option.is_exclusive — on a checkbox (multi) question, an option
--     that cannot be combined with any other ("Prefer not to answer", "None of
--     the above"). Picking it clears the other boxes; picking any other box
--     clears it; SurveyTypes::validateAnswer refuses a submission that has both.
--     Ignored by every other question type.
-- Idempotent: ADD COLUMN IF NOT EXISTS; safe to re-run.
-- After applying, run `docker restart ork3-php8-app` to clear the APCu schema
-- cache, and refresh the PHPUnit sandbox with
-- `bin/ork-db deploy-sandbox --force-refresh --yes`.

ALTER TABLE ork_survey_option
  ADD COLUMN IF NOT EXISTS is_exclusive TINYINT(1) NOT NULL DEFAULT 0 AFTER is_other;
