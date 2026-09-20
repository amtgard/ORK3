-- Amtgard CMS Multi-Site — ork_cms_site_alias migration
-- Remembers a site's PREVIOUS slugs so renaming one does not 404 every link
-- that was ever shared to it. CmsSite::UpdateSite() records the old slug here
-- whenever the slug actually changes, and CmsSite::GetSiteByAliasSlug()
-- resolves an alias back to the site's CURRENT row (the controller turns that
-- into a 301 to the current /k/{slug}).
--
-- CmsPage::RecordRedirect cannot cover this: it keys on the path AFTER the site
-- slug, and is consulted only once the site row has already been resolved by
-- its CURRENT slug.
--
-- alias_slug is the PRIMARY KEY, so an alias can only ever point at one site,
-- and UpdateSite's INSERT IGNORE quietly keeps the first claimant — a slug that
-- has been handed back and re-taken by another org must not silently redirect
-- that org's visitors away.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. InnoDB / utf8mb4. No destructive ops.
-- 190 chars is the utf8mb4 index-width limit and comfortably exceeds
-- ork_cms_site.slug's own 160.

CREATE TABLE IF NOT EXISTS ork_cms_site_alias (
  alias_slug  VARCHAR(190) NOT NULL,
  site_id     INT NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (alias_slug),
  KEY idx_site (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
