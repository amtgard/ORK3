-- Amtgard CMS Multi-Site — ork_cms_site migration
-- Introduces the "site" concept: an addressable, publishable grouping of the
-- already-scoped ork_cms_* content (one public website per org). No changes to
-- existing CMS tables — the site row only adds addressability (slug), identity
-- (name/logo), a home-page pointer, and a publish lifecycle.
-- Idempotent: CREATE TABLE IF NOT EXISTS. InnoDB / utf8mb4. No destructive ops.

-- ---------------------------------------------------------------------------
-- ork_cms_site — an org's public website (scope kingdom|park; global reserved
-- to the existing front door).
CREATE TABLE IF NOT EXISTS ork_cms_site (
  site_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope_type    ENUM('kingdom','park') NOT NULL DEFAULT 'kingdom',
  scope_id      INT NOT NULL DEFAULT 0,
  slug          VARCHAR(160) NOT NULL DEFAULT '',
  site_name     VARCHAR(160) NOT NULL DEFAULT '',
  logo_media_id INT NULL,
  status        ENUM('unbuilt','draft','published') NOT NULL DEFAULT 'unbuilt',
  published_at  DATETIME NULL,
  home_page_id  INT NULL,
  created_by    INT NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by    INT NOT NULL DEFAULT 0,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (site_id),
  UNIQUE KEY uq_scope (scope_type, scope_id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
