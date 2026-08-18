-- db-migrations/2026-07-23-cms-theme-active-unique.sql
-- CMS Theme Engine (#120): DB-enforce "at most one active theme per scope"
-- on ork_cms_theme. Idempotent + non-destructive (only flips redundant
-- is_active flags; never deletes rows). DB CLI is mariadb.
--
-- Real columns (see 2026-06-27-cms-theme.sql): id, scope_type, scope_id,
-- name, is_active, tokens_json, updated_by, created_at, updated_at.

-- --------------------------------------------------------------------------
-- Step 1: De-dupe existing active themes. For any (scope_type, scope_id)
-- with more than one is_active=1 row, keep the most-recently-updated (ties
-- broken by highest id) active and flip the rest to is_active=0.
-- Non-destructive: only clears redundant active flags.
-- --------------------------------------------------------------------------
UPDATE ork_cms_theme t
JOIN (
  SELECT id
  FROM (
    SELECT
      id,
      ROW_NUMBER() OVER (
        PARTITION BY scope_type, scope_id
        ORDER BY updated_at DESC, id DESC
      ) AS rn
    FROM ork_cms_theme
    WHERE is_active = 1
  ) ranked
  WHERE ranked.rn > 1
) losers ON losers.id = t.id
SET t.is_active = 0;

-- --------------------------------------------------------------------------
-- Step 2: Add a STORED generated column that is the scope key when active,
-- NULL otherwise. NULLs are permitted to repeat under a UNIQUE index, so
-- any number of inactive rows per scope remain legal.
-- MariaDB 10.0.2+ supports ADD COLUMN IF NOT EXISTS.
-- --------------------------------------------------------------------------
ALTER TABLE ork_cms_theme
  ADD COLUMN IF NOT EXISTS active_marker VARCHAR(64)
    AS (CASE WHEN is_active = 1
             THEN CONCAT(scope_type, ':', scope_id)
             ELSE NULL END) STORED;

-- --------------------------------------------------------------------------
-- Step 3: UNIQUE index on active_marker enforces one active theme per scope.
-- MariaDB 10.0.2+ supports ADD UNIQUE INDEX IF NOT EXISTS.
-- --------------------------------------------------------------------------
ALTER TABLE ork_cms_theme
  ADD UNIQUE INDEX IF NOT EXISTS uq_active_marker (active_marker);
