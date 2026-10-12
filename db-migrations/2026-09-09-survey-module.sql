-- Survey module schema.
-- Purpose: adds the ten ork_survey_* tables backing the in-ORK survey builder,
-- mobile-first runner (with consent data gate), Highcharts reporting, and
-- image uploads. See docs/superpowers/specs/2026-09-09-survey-module-design.md §3.
-- Idempotent: every statement is CREATE TABLE IF NOT EXISTS; safe to re-run.
-- After applying, run `docker restart ork3-php8-app` to clear the APCu schema
-- cache, and refresh the PHPUnit sandbox with
-- `bin/ork-db deploy-sandbox --force-refresh --yes`.

CREATE TABLE IF NOT EXISTS ork_survey (
  survey_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope_type           ENUM('ork','kingdom','park') NOT NULL,
  scope_id             INT NOT NULL DEFAULT 0,
  title                VARCHAR(200) NOT NULL,
  slug                 VARCHAR(64) NOT NULL,
  description          TEXT NULL,                 -- short blurb (plain text) for lists/widget/banner
  welcome_md           TEXT NULL,                 -- markdown; NULL = skip welcome screen
  welcome_image_id     INT UNSIGNED NULL,
  thanks_md            TEXT NULL,                 -- markdown; NULL = default thank-you
  thanks_image_id      INT UNSIGNED NULL,
  status               ENUM('draft','open','closed','archived') NOT NULL DEFAULT 'draft',
  open_at              DATETIME NULL,
  close_at             DATETIME NULL,
  audience_kingdom_ids TEXT NULL,                 -- JSON int array; ork scope only; NULL = all kingdoms
  audience_active_only TINYINT(1) NOT NULL DEFAULT 1,
  audience_min_tenure_months SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  data_gate_enabled    TINYINT(1) NOT NULL DEFAULT 1,
  show_banner          TINYINT(1) NOT NULL DEFAULT 0,
  show_progress        TINYINT(1) NOT NULL DEFAULT 1,
  allow_resume         TINYINT(1) NOT NULL DEFAULT 1,
  accent_color         VARCHAR(7) NULL,           -- '#rrggbb' or NULL = default
  response_count       INT UNSIGNED NOT NULL DEFAULT 0,  -- non-test responses, maintained in submit txn
  created_by           INT NOT NULL,
  created_at           DATETIME NOT NULL,
  updated_at           DATETIME NOT NULL,
  opened_at            DATETIME NULL,             -- first open; non-NULL => structure locked
  closed_at            DATETIME NULL,
  PRIMARY KEY (survey_id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_scope (scope_type, scope_id, status),
  KEY idx_status_banner (status, show_banner)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_page (
  page_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id            INT UNSIGNED NOT NULL,
  sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  title                VARCHAR(200) NULL,
  description_md       TEXT NULL,
  show_if_question_id  INT UNSIGNED NULL,
  show_if_option_id    INT UNSIGNED NULL,
  PRIMARY KEY (page_id),
  KEY idx_survey (survey_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_question (
  question_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id            INT UNSIGNED NOT NULL,
  page_id              INT UNSIGNED NOT NULL,
  sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  type                 ENUM('single','multi','dropdown','yesno','rating','nps','matrix','ranking',
                            'short_text','paragraph','number','date','section','image') NOT NULL,
  prompt               TEXT NOT NULL,              -- question text, or heading for 'section'
  help_md              TEXT NULL,                  -- markdown under the prompt, or body for 'section'
  image_id             INT UNSIGNED NULL,          -- optional illustration; required for type 'image'
  required             TINYINT(1) NOT NULL DEFAULT 0,
  settings             TEXT NULL,                  -- JSON object, keys per type (see §4)
  show_if_question_id  INT UNSIGNED NULL,
  show_if_option_id    INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL,
  updated_at           DATETIME NOT NULL,
  PRIMARY KEY (question_id),
  KEY idx_page (page_id, sort_order),
  KEY idx_survey (survey_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_option (
  option_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id          INT UNSIGNED NOT NULL,
  role                 ENUM('choice','row','column') NOT NULL DEFAULT 'choice',
  sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  label                VARCHAR(255) NOT NULL,
  value_num            DECIMAL(10,2) NULL,         -- matrix column weight (Likert mean); NULL otherwise
  is_other             TINYINT(1) NOT NULL DEFAULT 0,  -- "Other (please specify)" write-in
  PRIMARY KEY (option_id),
  KEY idx_question (question_id, role, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_response (
  response_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id            INT UNSIGNED NOT NULL,
  consent              ENUM('full','partial','anonymous') NOT NULL,
  mundane_id           INT NULL,
  kingdom_id           INT NULL,
  tenure_months        SMALLINT UNSIGNED NULL,
  is_test              TINYINT(1) NOT NULL DEFAULT 0,
  started_at           DATETIME NULL,
  submitted_at         DATETIME NOT NULL,
  duration_seconds     INT UNSIGNED NULL,
  PRIMARY KEY (response_id),
  KEY idx_survey (survey_id, is_test, submitted_at),
  KEY idx_survey_kingdom (survey_id, kingdom_id),
  KEY idx_mundane (mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_answer (
  answer_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  response_id          INT UNSIGNED NOT NULL,
  question_id          INT UNSIGNED NOT NULL,
  option_id            INT UNSIGNED NULL,          -- chosen option / matrix column / ranked option
  row_option_id        INT UNSIGNED NULL,          -- matrix row
  value_text           TEXT NULL,                  -- text answers, "other" write-in, ISO date
  value_num            DECIMAL(12,3) NULL,         -- rating, nps, number, rank position
  PRIMARY KEY (answer_id),
  KEY idx_response (response_id),
  KEY idx_question_option (question_id, option_id),
  KEY idx_question_row (question_id, row_option_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Records THAT a player completed a survey. Deliberately no timestamp and no response_id.
CREATE TABLE IF NOT EXISTS ork_survey_participation (
  survey_id            INT UNSIGNED NOT NULL,
  mundane_id           INT NOT NULL,
  PRIMARY KEY (survey_id, mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_draft (
  survey_id            INT UNSIGNED NOT NULL,
  mundane_id           INT NOT NULL,
  answers_json         LONGTEXT NOT NULL,
  page_index           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  started_at           DATETIME NOT NULL,
  updated_at           DATETIME NOT NULL,
  PRIMARY KEY (survey_id, mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_dismissal (
  survey_id            INT UNSIGNED NOT NULL,
  mundane_id           INT NOT NULL,
  dismissed_at         DATETIME NOT NULL,
  PRIMARY KEY (survey_id, mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_image (
  image_id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id            INT UNSIGNED NOT NULL,
  ext                  VARCHAR(4) NOT NULL,        -- 'jpg' | 'png'
  width                SMALLINT UNSIGNED NOT NULL,
  height               SMALLINT UNSIGNED NOT NULL,
  created_by           INT NOT NULL,
  created_at           DATETIME NOT NULL,
  PRIMARY KEY (image_id),
  KEY idx_survey (survey_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
