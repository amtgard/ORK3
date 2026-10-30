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
-- After applying: docker restart ork3-php8-app (APCu schema cache).

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
  actor_id      INT NOT NULL,
  created       DATETIME NOT NULL,
  PRIMARY KEY (legend_log_id),
  KEY idx_legend_log_mundane (mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ork_legend_memory (
  legend_memory_id INT NOT NULL AUTO_INCREMENT,
  mundane_id       INT NOT NULL,                       -- the Legend the memory is about
  author_id        INT NOT NULL,
  body             TEXT NOT NULL,
  created          DATETIME NOT NULL,
  removed_by_id    INT NOT NULL DEFAULT 0,             -- 0 = not removed
  removed_at       DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (legend_memory_id),
  KEY idx_legend_memory_mundane (mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
