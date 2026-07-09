-- db-migrations/2026-07-08-cms-view-analytics.sql
-- CMS built-in usage analytics (#09): lightweight per-day view tallies.
--
-- One row per (scope, entity, calendar day). A public page/post render fires a
-- single upsert:
--   INSERT ... VALUES (..., CURDATE(), 1)
--   ON DUPLICATE KEY UPDATE views = views + 1
-- so the hot path is one cheap indexed upsert. Per-day granularity lets the
-- dashboard show both an all-time total and a rolling "last 30 days" without a
-- separate events table. No PII, no third-party analytics.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. Safe to re-run.
CREATE TABLE IF NOT EXISTS ork_cms_view (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope_type   ENUM('global','kingdom','park') NOT NULL DEFAULT 'global',
  scope_id     INT NOT NULL DEFAULT 0,
  entity_type  ENUM('page','post') NOT NULL,
  entity_id    INT UNSIGNED NOT NULL,
  `day`        DATE NOT NULL,
  views        INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  -- The upsert target: one counter row per entity per day.
  UNIQUE KEY uq_view_day (scope_type, scope_id, entity_type, entity_id, `day`),
  -- Scope rollups ("X views this month") scan by scope + day.
  KEY idx_scope_day (scope_type, scope_id, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
