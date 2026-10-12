-- Amtgard CMS — add the 'store' page type
-- =============================================================================
-- CmsBlockRegistry::PageTypeDefs() gains a 'store' preset ("Store catalog":
-- heading + the new catalog block). ork_cms_page.type is an ENUM, and with
-- strict SQL mode OFF (the local/dev default) saving an unlisted value silently
-- stores '' instead — the same trap 2026-07-23-cms-page-type-about.sql fixed
-- for 'about'. This widens the ENUM to include 'store'.
--
-- 'type' is AUTHOR-FACING editor metadata (which preset a page was made from —
-- see Controller_Cms::_pageTypes), NOT a render input, so widening the ENUM has
-- no effect on the public render path.
--
-- Additive + idempotent: MODIFY COLUMN restates the full ENUM, so re-running is
-- a no-op. No backfill — no page can have been saved as 'store' before this.
-- MariaDB client, not mysql. No destructive ops.

ALTER TABLE `ork_cms_page`
  MODIFY COLUMN `type`
    enum('composed','article','media','blog_index','resource','dynamic','about','store')
    NOT NULL DEFAULT 'composed';
