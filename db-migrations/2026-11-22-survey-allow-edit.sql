-- Survey: Update My Answers (design docs/superpowers/specs/2026-11-22-survey-update-answers-design.md).
-- Purpose:
--   * ork_survey.allow_edit — per-survey toggle: a respondent may change their
--     answers while the survey is open (status open, close_at not passed).
--     Default off.
--   * ork_survey_response.edit_key — HMAC-SHA256(secret, "survey-edit:{survey_id}:{mundane_id}"),
--     written at submit only when allow_edit is on (never for test rows). It is
--     the bounded exception to the response privacy model: never selected by
--     results, export, rows or any manager-facing read, and set NULL when the
--     survey closes (status change or date sweep) or allow_edit is switched off.
--   * ork_survey_response.edit_uuid — opaque id for the respondent's reopen link.
--   * ork_survey_response.edit_answers_json — the respondent's submitted answer
--     map, used only to prefill the runner on edit; wiped with edit_key.
-- DDL only, no data. Existing responses get NULLs (they cannot be edited).
-- Idempotent: ADD COLUMN / ADD UNIQUE KEY IF NOT EXISTS; safe to re-run.
-- After applying, run `docker restart ork3-php8-app` to clear the APCu schema
-- cache.

ALTER TABLE ork_survey ADD COLUMN IF NOT EXISTS allow_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_resume;

ALTER TABLE ork_survey_response
  ADD COLUMN IF NOT EXISTS edit_key CHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS edit_uuid CHAR(36) NULL,
  ADD COLUMN IF NOT EXISTS edit_answers_json MEDIUMTEXT NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_edit_key (edit_key);
