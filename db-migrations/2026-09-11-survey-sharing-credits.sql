-- Survey module: results sharing (rolldown) and attendance credits.
-- Spec: docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md §2–§4.
-- Idempotent: ADD ... IF NOT EXISTS, CREATE TABLE IF NOT EXISTS, a guarded
-- backfill, and an append-only ENUM MODIFY that re-runs as a no-op.
-- After applying: docker restart ork3-php8-app (APCu schema cache).

ALTER TABLE ork_survey
  ADD COLUMN IF NOT EXISTS results_share ENUM('none','scoped','all') NOT NULL DEFAULT 'none' AFTER data_gate_enabled,
  -- When shared viewers get results: live, or 24h after the survey stops taking responses.
  ADD COLUMN IF NOT EXISTS results_share_timing ENUM('ongoing','after_close') NOT NULL DEFAULT 'after_close' AFTER results_share;

ALTER TABLE ork_survey_response
  ADD COLUMN IF NOT EXISTS park_id INT NULL AFTER kingdom_id,
  ADD INDEX IF NOT EXISTS idx_survey_park (survey_id, park_id);

-- 1 when the data gate showed this Any ORK Data respondent an attendance-credit
-- line before they chose (spec D1). Only such responses are ever credited, by
-- the live grant or a backfill. No backfill: existing rows were never told.
ALTER TABLE ork_survey_response
  ADD COLUMN IF NOT EXISTS credit_notice TINYINT(1) NOT NULL DEFAULT 0 AFTER park_id;

-- Park snapshot for existing Any ORK Data rows only; never overwrites a snapshot.
UPDATE ork_survey_response r
  JOIN ork_mundane m ON m.mundane_id = r.mundane_id
   SET r.park_id = NULLIF(m.park_id, 0)
 WHERE r.consent = 'full' AND r.mundane_id IS NOT NULL AND r.park_id IS NULL;

CREATE TABLE IF NOT EXISTS ork_survey_credit (
  credit_id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id               INT UNSIGNED NOT NULL,
  grantor_type            ENUM('kingdom','park') NOT NULL,
  grantor_id              INT NOT NULL,
  mode                    ENUM('home_park','event') NOT NULL,
  event_id                INT NULL,
  event_calendardetail_id INT NULL,
  enabled_by              INT NOT NULL,
  enabled_at              DATETIME NOT NULL,
  PRIMARY KEY (credit_id),
  UNIQUE KEY uq_survey_grantor (survey_id, grantor_type, grantor_id),
  KEY idx_survey_enabled (survey_id, enabled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- One credit per player per survey; written in the same transaction as the attendance row.
CREATE TABLE IF NOT EXISTS ork_survey_credit_grant (
  survey_id      INT UNSIGNED NOT NULL,
  mundane_id     INT NOT NULL,
  credit_id      INT UNSIGNED NOT NULL,
  attendance_id  INT NOT NULL,
  PRIMARY KEY (survey_id, mundane_id),
  KEY idx_credit (credit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Append-only (see 2026-05-31-attendance-entry-method.sql): never reorder or remove.
ALTER TABLE ork_attendance
  MODIFY COLUMN entry_method ENUM('manual','signin_link','self_reg','bulk_import','survey') NOT NULL DEFAULT 'manual';
