# CMS Expert-Review Enhancements — Status

Tracks the **19 enhancement recommendations** from the CMS Expert Review artifact
("ORK CMS — Expert Review": 31 challenges + 19 enhancements). The 31 *challenges*
were remediated earlier (`9b547ba0` + this branch's polish rounds). This doc covers
the enhancement backlog only. Numbering = the artifact's display order (01–19).

Nothing below is committed yet — all work sits in the working tree on `feature/front-door`.

## ✅ Done this round (10)

| # | Enhancement | What shipped |
|---|---|---|
| 05 | Media-library management | `CmsMedia::Update` (alt/title/rename) + `ReferenceUsage` where-used; `Cms_media.tpl` per-card edit/delete/where-used + bulk-select-delete; CmsAjax `mediaupdate`/`mediadelete`/`mediabulkdelete`/`mediausage` (CSRF + IDOR + media.manage). **Crop deferred.** |
| 06 | Per-site RSS + cache-bust | Shared `CmsPost::RssFeedXml` (scope-parameterized); `Site::rss($slug)` (preview-refused); `Blog::rss` thinned to call it; per-scope `rss_xml` ghettocache busted on publish/unpublish/delete/restore; empty-excerpt→body-snippet fallback. |
| 09 | Usage analytics | New `ork_cms_view` (per-day counter) + `class.CmsView.php` + `model.CmsView.php`; best-effort increment on public render (excludes preview/non-GET/bots); dashboard 30-day rollup + "Most viewed" card. |
| 11 | Draft→publish guidance | Inline `.cms-note` in `Cms_edit`/`Cms_editpost` when `$canEdit && !$canPublish`. |
| 12 | Slug-change 301 redirects | **Verified already fully built** (`RecordRedirect` + `ork_cms_redirect` + Site 404-path 301 + open-redirect guard). No gaps. |
| 13 | Immutable media caching + theme/nav cache | nginx `immutable` on `/assets/cms-media/` only (content-addressed); nav-tree GhettoCache via content-signature; theme CSS cache verified pre-existing. **No full-page `/k/` cache** (keeps #09 counting live). |
| 14 | Editor ergonomics | Pointer/touch drag reorder; collapse-all/expand-all; media-picker pagination (`medialist` offset + `has_more`, SQL-level paging). |
| 16 | Visual columns editor | 2/3-column splitter reusing the card UI per column, replacing raw-JSON for `columns`; legacy-JSON graceful fallback; nesting bounded; round-trip live-verified. |
| 17 | Author a11y prompts | Video-title field (+ render use); table header-row default-on + note; media-picker inline alt editor + "decorative" checkbox. |
| 18 | DRY / taxonomy cleanup | `CmsBase::_normalizeSlug` hoisted; CmsPage/CmsPost/CmsSite routed through it; `page.type` documented as inert metadata; `raw_html` relabeled "Custom HTML (limited)" + preview strip-note. |

### QA verification (this round)
Full changeset lint-clean (`php -l` on all 44 touched + 5 new files; 3 `_block_editor.tpl` `<script>` blocks parse). Both migrations applied idempotently to local MariaDB. Adversarial review of the security-critical seams:
- **Columns child-block stored-XSS → NOT vulnerable**: `CmsPage::_sanitizeBlockFields` recurses into `columns[].fields` at every depth (C3 choke point covers nested children).
- Media endpoints (CSRF/IDOR/capability/where-used guard), CmsView SQL (bound params, scope-pinned JOIN, best-effort), nav cache (scope-only key, signature busts on any mutation), RSS (published+scope gate, matching bust key, XML-escaped), slug DRY (lookups still match stored slugs) — all clean.
- **Fixed:** `medialist` pagination silently capped at 1000 rows/scope (over-fetch collided with `_clampLimit`) — reworked to SQL-level `LIMIT offset,count` (+ model wrapper updated to forward `offset`).

### Deploy steps
1. `db-migrations/2026-07-08-cms-slug-live-and-integrity.sql` (from the prior polish round)
2. `db-migrations/2026-07-08-cms-view-analytics.sql`
3. nginx: reload with the `/assets/cms-media/` immutable block + `/k|/p/{slug}/rss` routes.

### Follow-ups logged (small, deferred)
- Media **crop** (feeding `_makeThumb`) — deliberately deferred from #05.
- Nav cache doesn't bust on a linked page/post **slug rename** (stale href ≤1800s; #12's 301 catches the click). Bust belongs in the page/post write path.
- Nav-manager **drag + in-place update** lives in `Cms_nav.tpl` (out of #14's scope).
- Media-library **page** could reuse the picker's pagination.
- **Global** Blog index can advertise its RSS by setting `$rss_feed_url` (per-org sites already do).
- CMS image blocks emit the original, not a `_thumb` rendition — optimization once the masters/rendition pipeline (`feature/high-res-images`) merges.

## ⏳ Remaining enhancements (9)

| # | Enhancement | Value / effort |
|---|---|---|
| 01 | Form / Contact block (+ submissions table + officer email) | high / large |
| 02 | Automated tests for RBAC / sanitizer / IDOR | high / med |
| 03 | Per-page SEO: canonical/OG + XML sitemap + robots | high / med |
| 04 | Content-Security-Policy backstop on public render | med / med |
| 07 | Global synced / reusable blocks + shared content library | med / large |
| 08 | Public per-site search | med / med |
| 10 | Moderation / takedown / report + super-admin site kill-switch | med / med |
| 15 | In-app help / first-run guidance | low / med |
| 19 | Custom domain mapping | low / large |
