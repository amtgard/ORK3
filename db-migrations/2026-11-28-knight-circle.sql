-- ORK3 — Circle of Knights Manager (kingdom-level)
-- =============================================================================
-- A kingdom's Circle of Knights wants one place to see who its knights are,
-- which of them are eligible to take part in the Circle (sign-ins, dues,
-- waiver, residence ... all configurable per kingdom), and to run a candidate
-- pipeline: Watchlist > Masterhood > Under Discussion > Voting > Knight-Elect >
-- Knighted, with a Holding side column and a Dismissed stage.
--
-- FIVE TABLES.
--   ork_knight_circle_config   one row per kingdom: the eligibility rules and
--                              view toggles. No row means the code DEFAULTS
--                              (KnightCircle::DEFAULTS).
--   ork_knight_circle_admin    the kingdom's Guardian of the Circle (role
--                              'gmok', one per kingdom, enforced in code) and
--                              any deputies. Appointed by kingdom CREATE or an
--                              ORK admin.
--   ork_knight_candidate       one row per (kingdom, player, order). `award_id`
--                              is the knighthood the player is a candidate for
--                              (17 Flame, 18 Crown, 19 Serpent, 20 Sword,
--                              245 Battle). The suggested watchlist is computed,
--                              never stored. Dismissal is a stage, so a
--                              reconcile never re-adds a dismissed player.
--                              Candidates are never hard-deleted.
--   ork_knight_candidate_history  every stage change, with the vote tally when a
--                              candidate leaves Voting. stage_changed_by 0 /
--                              actor_id 0 = the system (automatic reconcile).
--   ork_knight_candidate_note  private notes on a candidate; soft-deleted.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS; safe to re-run. utf8mb4 InnoDB.
-- After applying: restart the app container (APCu schema cache), e.g.
--   docker restart ork3-php8-app

CREATE TABLE IF NOT EXISTS ork_knight_circle_config (
  kingdom_id               INT NOT NULL,
  min_signins              SMALLINT NOT NULL DEFAULT 6,
  window_months            TINYINT NOT NULL DEFAULT 6,
  signin_mode              ENUM('count','days','weeks') NOT NULL DEFAULT 'count',
  signin_scope             ENUM('kingdom','anywhere') NOT NULL DEFAULT 'kingdom',
  online_mode              ENUM('kingdom_setting','include','exclude') NOT NULL DEFAULT 'kingdom_setting',
  require_dues             TINYINT(1) NOT NULL DEFAULT 1,
  dues_scope               ENUM('kingdom','anywhere') NOT NULL DEFAULT 'kingdom',
  require_waiver           TINYINT(1) NOT NULL DEFAULT 0,
  require_residence        TINYINT(1) NOT NULL DEFAULT 1,
  include_principalities   TINYINT(1) NOT NULL DEFAULT 1,
  exclude_suspended        TINYINT(1) NOT NULL DEFAULT 1,
  min_knight_months        SMALLINT NOT NULL DEFAULT 0,
  use_kingdom_voting_rules TINYINT(1) NOT NULL DEFAULT 0,
  watchlist_min_rank       TINYINT NOT NULL DEFAULT 9,
  officers_see_pipeline    TINYINT(1) NOT NULL DEFAULT 1,
  caveat                   VARCHAR(1000) NOT NULL DEFAULT '',
  updated_by               INT NOT NULL DEFAULT 0,
  updated_at               DATETIME NULL,
  PRIMARY KEY (kingdom_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ork_knight_circle_admin (
  circle_admin_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kingdom_id      INT NOT NULL,
  mundane_id      INT NOT NULL,
  role            ENUM('gmok','deputy') NOT NULL DEFAULT 'deputy',
  added_by        INT NOT NULL DEFAULT 0,
  added_at        DATETIME NOT NULL,
  PRIMARY KEY (circle_admin_id),
  UNIQUE KEY uq_kc_admin (kingdom_id, mundane_id),
  KEY idx_kc_admin_mundane (mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ork_knight_candidate (
  candidate_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kingdom_id         INT NOT NULL,
  mundane_id         INT NOT NULL,
  award_id           INT NOT NULL,                  -- the order: 17, 18, 19, 20 or 245
  stage              ENUM('watchlist','masterhood','discussion','voting','holding','elect','knighted','dismissed') NOT NULL DEFAULT 'watchlist',
  source             ENUM('manual','suggested','master_auto') NOT NULL DEFAULT 'manual',
  sort_order         INT NOT NULL DEFAULT 0,
  stage_changed_at   DATETIME NOT NULL,
  stage_changed_by   INT NOT NULL DEFAULT 0,        -- 0 = the system
  knighted_awards_id INT NULL,                      -- the ork_awards row that made them a knight
  created_by         INT NOT NULL DEFAULT 0,
  created_at         DATETIME NOT NULL,
  PRIMARY KEY (candidate_id),
  UNIQUE KEY uq_kc_candidate (kingdom_id, mundane_id, award_id),
  KEY idx_kc_candidate_mundane (mundane_id),
  KEY idx_kc_candidate_stage (kingdom_id, stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ork_knight_candidate_history (
  history_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidate_id  INT UNSIGNED NOT NULL,
  from_stage    VARCHAR(20) NULL,
  to_stage      VARCHAR(20) NOT NULL,
  actor_id      INT NOT NULL DEFAULT 0,             -- 0 = the system
  comment       VARCHAR(500) NOT NULL DEFAULT '',
  votes_for     SMALLINT NULL,
  votes_against SMALLINT NULL,
  votes_abstain SMALLINT NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (history_id),
  KEY idx_kc_history_candidate (candidate_id),
  CONSTRAINT fk_kc_history_candidate FOREIGN KEY (candidate_id)
    REFERENCES ork_knight_candidate (candidate_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ork_knight_candidate_note (
  note_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidate_id INT UNSIGNED NOT NULL,
  author_id    INT NOT NULL,
  body         TEXT NOT NULL,                       -- capped at 4000 characters in code
  created_at   DATETIME NOT NULL,
  deleted_at   DATETIME NULL,
  deleted_by   INT NULL,
  PRIMARY KEY (note_id),
  KEY idx_kc_note_candidate (candidate_id),
  CONSTRAINT fk_kc_note_candidate FOREIGN KEY (candidate_id)
    REFERENCES ork_knight_candidate (candidate_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
