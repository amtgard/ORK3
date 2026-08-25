-- db-migrations/2026-06-27-cms-theme.sql
-- CMS Theme Engine: per-scope design-token sets for the public front door.
CREATE TABLE IF NOT EXISTS ork_cms_theme (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope_type   ENUM('global','kingdom','park') NOT NULL DEFAULT 'global',
  scope_id     INT NOT NULL DEFAULT 0,
  name         VARCHAR(120) NOT NULL DEFAULT 'Default',
  is_active    TINYINT(1) NOT NULL DEFAULT 0,
  tokens_json  JSON NULL,
  updated_by   INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_scope_name (scope_type, scope_id, name),
  KEY idx_scope_active (scope_type, scope_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
