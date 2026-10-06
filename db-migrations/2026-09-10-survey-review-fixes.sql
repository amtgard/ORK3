-- Survey module review fixes (follow-up to 2026-09-09-survey-module.sql).
-- Purpose:
--   * ork_survey_activity  — per-survey audit log (create/update/structure/status/
--                            clone/delete/rows_view/export) with actor + JSON detail.
--   * ork_survey_start     — who opened a survey's runner (completion rate). Deliberately
--                            NO timestamp so a start cannot be timed against a submission.
--   * ork_survey           — updated_by (last editor), audience_recent_months (recent
--                            attendance rule, 0 = off), audience_event_calendardetail_id
--                            (event-attendee audience, NULL = off).
--   * ork_survey_image     — token (random filename component; '' = legacy %06d.ext name).
--   * ork_survey_response  — BACKFILL: partial-consent rows stored before banding are
--                            folded onto the years-played band floor, and lose their
--                            duration and start time, as the new consent copy promises.
-- Idempotent: CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS, and the backfill
-- maps a band floor to itself; safe to re-run.
-- After applying, run `docker restart ork3-php8-app` to clear the APCu schema
-- cache, and refresh the PHPUnit sandbox with
-- `bin/ork-db deploy-sandbox --force-refresh --yes`.
-- Then give images stored before the token column an unguessable file name (SQL
-- cannot rename files; this renames each file, sets its token and rewrites any
-- markdown that names it; re-running is a no-op):
--   docker exec -e ENVIRONMENT=DEV ork3-php8-app php -r '$_SERVER["HTTP_HOST"]="localhost:19080";
--     require "/var/www/ork.amtgard.com/startup.php";
--     echo json_encode((new Survey())->upgradeLegacyImageNames()), PHP_EOL;'

CREATE TABLE IF NOT EXISTS ork_survey_activity (
  activity_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id            INT UNSIGNED NOT NULL,
  mundane_id           INT NOT NULL,
  action               VARCHAR(32) NOT NULL,
  detail               TEXT NULL,                 -- JSON object; NULL = no detail
  created_at           DATETIME NOT NULL,
  PRIMARY KEY (activity_id),
  KEY idx_survey_created (survey_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_start (
  survey_id            INT UNSIGNED NOT NULL,
  mundane_id           INT NOT NULL,
  PRIMARY KEY (survey_id, mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

ALTER TABLE ork_survey
  ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by,
  ADD COLUMN IF NOT EXISTS audience_recent_months SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER audience_min_tenure_months,
  ADD COLUMN IF NOT EXISTS audience_event_calendardetail_id INT NULL AFTER audience_recent_months;

ALTER TABLE ork_survey_image
  ADD COLUMN IF NOT EXISTS token CHAR(16) NOT NULL DEFAULT '' AFTER ext;

-- Partial consent keeps a years-played RANGE, never exact months, and no
-- duration (SurveyResponse::scrubForConsent). Band floors mirror
-- SurveyResponse::TENURE_BANDS: 0 / 12 / 36 / 72 / 132 months.
UPDATE ork_survey_response
   SET tenure_months = CASE
         WHEN tenure_months IS NULL THEN NULL
         WHEN tenure_months < 12    THEN 0
         WHEN tenure_months < 36    THEN 12
         WHEN tenure_months < 72    THEN 36
         WHEN tenure_months < 132   THEN 72
         ELSE 132
       END,
       duration_seconds = NULL,
       started_at = NULL
 WHERE consent = 'partial'
   AND ((tenure_months IS NOT NULL AND tenure_months NOT IN (0, 12, 36, 72, 132))
        OR duration_seconds IS NOT NULL
        OR started_at IS NOT NULL);
