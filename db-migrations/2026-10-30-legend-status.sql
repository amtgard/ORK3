-- ORK3 — Legend status: a deceased player's In Memoriam record
-- =============================================================================
-- A Legend is a player who has passed away. Their record stays, their history
-- stays, and their page becomes a memorial. Marking someone a Legend is a
-- kingdom-officer (or ORK admin) call, reversible, and audited.
--
--   ork_mundane.is_legend    1 = this player is a Legend (indexed: lists filter on it)
--   ork_mundane.legend_year  the year they passed (0 = unset); the banner reads it
--   ork_legend_log           one snapshot per convert / undo / year change, holding
--                            what the conversion cleared so an undo can restore it
--   ork_legend_memory        the memory wall: short notes left by friends and family;
--                            soft-removed (removed_by_id <> 0 means removed)
--
-- New columns are NOT NULL with defaults because Yapo drops null on save.
--
-- Idempotent: ADD COLUMN / INDEX IF NOT EXISTS, CREATE TABLE IF NOT EXISTS;
-- safe to re-run.
--
-- DEPLOY: apply this to production BEFORE running greenblue.sh. That order is safe
-- because the new columns are NOT NULL with defaults and the old code ignores them.
-- greenblue.sh neither migrates nor restarts, so if this is ever applied AFTER the
-- code goes live, restart the live colour's container (ork3-php8-app-blue or -green)
-- or delete the yapo_schema_ork_mundane APCu key. Yapo caches a table's schema for
-- 24 hours, and until it refreshes every login guard reads is_legend as false.
-- Local dev: docker restart ork3-php8-app.

ALTER TABLE ork_mundane
  ADD COLUMN IF NOT EXISTS is_legend   TINYINT(1)        NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS legend_year SMALLINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE ork_mundane ADD INDEX IF NOT EXISTS idx_mundane_is_legend (is_legend);

CREATE TABLE IF NOT EXISTS ork_legend_log (
  legend_log_id INT NOT NULL AUTO_INCREMENT,
  mundane_id    INT NOT NULL,
  action        ENUM('convert','undo','year') NOT NULL,
  legend_year   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  prior_active  TINYINT NOT NULL DEFAULT 0,            -- ork_mundane.active before the conversion
  cleared_json  MEDIUMTEXT NOT NULL,                   -- what the conversion cleared, for undo
  warnings_json MEDIUMTEXT NOT NULL,                   -- what it left needing attention (vacant seats, units with no manager)
  actor_id      INT NOT NULL,
  created       DATETIME NOT NULL,
  PRIMARY KEY (legend_log_id),
  KEY idx_legend_log_mundane (mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ork_legend_memory (
  legend_memory_id INT NOT NULL AUTO_INCREMENT,
  mundane_id       INT NOT NULL,                       -- the Legend the memory is about
  author_id        INT NOT NULL,
  author_persona   VARCHAR(255) NOT NULL DEFAULT '',   -- the byline as it stood when posted
  body             TEXT NOT NULL,
  created          DATETIME NOT NULL,
  removed_by_id    INT NOT NULL DEFAULT 0,             -- 0 = not removed
  removed_at       DATETIME NULL DEFAULT NULL,
  removed_reason   VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (legend_memory_id),
  KEY idx_legend_memory_mundane (mundane_id),
  KEY idx_legend_memory_author (mundane_id, author_id, created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
