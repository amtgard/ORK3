# CMS Polish — Deferred Findings (Punch List)

Generated from the 58-reviewer polish pass. 44 findings intentionally deferred (out of the 'highs-only' fix scope, or requiring a design decision). The remaining ~37 findings were fixed in the CMS-polish commit.

Severity = impact · Risk = cost/danger of the fix.

## ⚠️ Priority — design decision required
- **Soft-delete vs slug-uniqueness**: trashing a page/post permanently blocks reusing its slug (the `deleted_at` soft-delete unique key isn't scoped to live rows). Fix options: a generated unique column folding `deleted_at`, or mangle the trashed row's slug on delete. Spans `db-migrations` + `CmsPage::DeletePage`/`CmsPost::DeletePost`. _(regression from remediation)_

## All deferred findings

### 🟠 HIGH
- **[db-migrations/2026-07-07-cms-integrity-safety-net.sql:32]** Soft-delete (deleted_at) added without adjusting the slug UNIQUE constraint — trashing a page/post permanently blocks reuse of its slug — _risky fix_
    - Change the uniqueness so it's scoped to live rows only — e.g. drop the plain UNIQUE key and instead enforce uniqueness with a generated/stored column that folds deleted_at into the key (common MySQL/MariaDB pattern: `slu

### 🟡 MEDIUM
- **[…/controller.Blog.php:47]** Unauthenticated blog pagination has no upper bound on the OFFSET — _trivial fix_
    - Clamp `$page` to `[1, $pages]` after `$pages` is computed (or reject/redirect out-of-range page numbers before calling list_posts), so the OFFSET sent to the DB can never exceed the actual result set size.
- **[…/controller.Cms.php:82]** CMS dashboard fetches entire page/post row sets just to compute counts — _medium fix_
    - Add lightweight aggregate methods to the CmsPage/CmsPost libs (e.g. CountPages/CountPosts with a status breakdown via GROUP BY, or SELECT COUNT(*) ... GROUP BY status) instead of fetching full rows for counting. For the 
- **[…/controller.Cms.php:963]** Page-list 'View live' link is wrong for nested (child) pages — _medium fix_
    - In _pageLiveHref() (or its caller in index()), resolve the full slug path via $this->CmsPage->PagePath($pageId) instead of the bare $pageSlug when building the non-home Site/page/... URL.
- **[…/controller.CmsAjax.php:88]** savepage/savepost re-implement the same edit-authorization logic that `_requireOwnerEditable()` already encapsulates — _medium fix_
    - Refactor `savepage`/`savepost` to call `$existing = $this->_requireOwnerEditable($uid, 'page'|'post', $pageId|$postId, $scope);` followed by `$this->_guardConcurrency($existing);`, removing the duplicated inline block in
- **[…/controller.CmsAjax.php:357]** set_tags() return value discarded — post save can report success with a partially-applied tag set — _medium fix_
    - Check set_tags's return value and _fail()/log on failure; consider wrapping the meta+tags+blocks sequence in a single DB transaction so any failure rolls back the whole save instead of leaving a partially-applied state.
- **[…/controller.CmsAjax.php:764]** yapo null-drop defeats the link-type-switch column clear in savenavitem — _medium fix_
    - Do not rely on null to clear a column through the yapo layer. Either assign '' / 0 sentinels the lib translates to a real UPDATE, or have the CmsNav/CmsPage lib explicitly set these columns to NULL via a raw bound UPDATE
- **[…/controller.Site.php:220]** Unauthenticated per-org blog pagination has no upper bound on the OFFSET — _trivial fix_
    - Clamp `$pageNo` to `[1, $pages]` (computed from `$total`/`$perPage`) before building `$offset`/calling list_posts, mirroring the fix needed in Controller_Blog::index().
- **[…/Cms_edit.tpl:201]** Cms_edit.tpl and Cms_editpost.tpl duplicate the entire save/publish/delete/preview-pane flow (~300 lines) — _risky fix_
    - Factor the shared save/publish/delete/preview-pane logic into the existing window.CmsBlockEditor engine (or a new shared helper module), parameterized by entity kind ('page'|'post'), id, and CmsAjax endpoint names, the s
- **[…/Cms_index.tpl:245]** Cms_index.tpl and Cms_posts.tpl duplicate ~350 lines of list-page JS almost verbatim — _medium fix_
    - Extract the shared toast/modal/POST-helper/publish-unpublish/delete-undo/overflow-menu/bulk-action logic into a single reusable module (e.g. a small JS include parameterized on the row id-attribute and CmsAjax endpoint p
- **[…/cms/_block_editor.tpl:164]** ~2,000-line static JS engine is inlined into every page/post-editor response instead of a cacheable external asset — _medium fix_
    - Extract the static portion (already marked by the file's own 'C27 extraction seam' comment) into template/default/script/cms-block-editor.js, loaded with a normal <script src> tag (cache-busted via a version query param)
- **[…/cms/_block_editor.tpl:779]** Staff Roster "People" repeater leaks a persona-search dropdown node on every add/remove/reorder — _medium fix_
    - Track the dropdown element(s) created for a repeater instance (e.g. keep a list on the repeater closure, or store a reference on `person` and remove the prior node before creating a new one) and explicitly remove them fr
- **[…/frontdoor/blocks/kingdom_parks.tpl:56]** kingdom_parks.tpl re-queries Kingdom::GetParks on every anonymous hit, plus per-row file_exists() heraldry probe, with zero caching — _medium fix_
    - Mirror the kingdom_officers.tpl C5 fix: resolve the full row set (park list + heraldry URLs) once, cache it in Ork3::$Lib->ghettocache keyed by kingdom_id (+relevant fields like limit/sort), with a short TTL (e.g. 300s),
- **[…/frontdoor/blocks/kingdom_parks_map.tpl:51]** kingdom_parks_map.tpl re-queries Map::GetParkLocations + per-row file_exists() heraldry probe on every anonymous hit, no caching — _medium fix_
    - Cache the resolved $kpmParks array (post markdown-render, post heraldry-URL-resolve) in GhettoCache keyed by kingdom_id with a short TTL, same pattern as kingdom_officers.tpl, so repeat anonymous hits skip both the DB ca
- **[…/class.CmsMedia.php:642]** _referenceCount()'s cms_block check is an unscoped REGEXP full-table scan run on every media delete/purge — _medium fix_
    - Bound the REGEXP query to the media row's own scope_type/scope_id (already known at the DeleteMedia/PurgeMedia call sites) to cut scan size, or replace the regex scan with a maintained media-usage table updated on block 
- **[…/class.CmsNav.php:205]** [stability] CreateItem() has zero verification that the INSERT succeeded before returning a nav_id — _medium fix_
    - Mirror the read-back pattern already used elsewhere in this same file (UpdateItem/DeleteItem/Reorder all verify writes by reading rows back): after Execute(), SELECT nav_id FROM cms_nav_item WHERE menu=:menu AND scope_ty
- **[…/class.CmsPage.php:246]** Soft-deleted (trashed) pages permanently block slug reuse for new pages — _risky fix_ _(regression from remediation)_
    - Either exclude trashed rows from the uniqueness scope (requires a schema change, e.g. incorporating a purge/hard-delete path, or a generated column that folds deleted_at into the unique key), or have DeletePage() rewrite
- **[…/class.CmsPage.php:416]** UpdatePage() can silently reassign a page's scope_type/scope_id with no ownership/IDOR guard — _medium fix_
    - Add the same optional ($scopeType = null, $scopeId = null) IDOR-guard parameters used by DeletePage()/RestorePage() to UpdatePage(), and reject the write (or at least the scope_type/scope_id fields) when the caller's int
- **[…/class.CmsPage.php:526]** PagePath()/GetPageAncestors() re-walk the same ancestor chain twice per page render with no memoization — _medium fix_
    - Add a per-request memo cache keyed by page_id around the ancestor-chain result (this file already uses that exact pattern for self::$_redirectTableExists / self::$_revisionTableExists), so PagePath() and GetPageAncestors
- **[…/class.CmsPage.php:648]** RecordRedirect() persists an admin-supplied target URL with no scheme validation (open-redirect risk) — _trivial fix_ _(regression from remediation)_
    - Validate $toUrl with CmsSanitizer::IsSafeUrl($toUrl) before storing (mirroring _sanitizeBlockFields' URL_FIELDS handling) and reject/null out unsafe values instead of persisting them as-is.
- **[…/class.CmsPage.php:834]** Create/Delete/Restore/SetStatus logic duplicated wholesale between CmsPage and CmsPost — _medium fix_ _(regression from remediation)_
    - Parameterize the common skeleton (table, PK column, scope columns) into shared protected helpers on CmsBase (e.g. _softDelete, _restore, _setStatus, _insertWithDupGuard); have CmsPage/CmsPost methods become thin wrappers
- **[…/class.CmsPage.php:996]** ReplaceBlocks is an oversized, multi-responsibility method — _medium fix_ _(regression from remediation)_
    - Split into small private methods (_normalizeBlocks, _upsertKnownBlocks, _deleteRemovedBlocks, _insertNewBlocks, _verifyBlockCount) called in sequence by ReplaceBlocks, which keeps only the transaction boundary and orches
- **[…/class.CmsPost.php:350]** Soft-deleted (trashed) posts permanently block slug reuse for new posts — _risky fix_ _(regression from remediation)_
    - Same remedy as the CmsPage finding: exclude trashed rows from the uniqueness scope or mangle the trashed row's slug on delete so it's freed for reuse.
- **[…/class.CmsPost.php:452]** UpdatePost() renames a slug with no duplicate check or write verification — _medium fix_ _(regression from remediation)_
    - Add a duplicate-slug pre-check (scope_type, scope_id, slug, excluding this post_id) before staging the SET clause, and verify the UPDATE actually applied (ROW_COUNT()/read-back) before returning true.
- **[…/class.CmsPost.php:485]** UpdatePost() can silently reassign a post's scope_type/scope_id with no ownership/IDOR guard — _medium fix_
    - Add the same optional scope-guard parameters used by DeletePost()/RestorePost() to UpdatePost() and reject cross-scope writes to scope_type/scope_id when the caller's intended scope doesn't match the current row.
- **[…/class.CmsPost.php:763]** SetTags() lacks the post-write verification/rollback guard used by every other atomic write in this file — _trivial fix_ _(regression from remediation)_
    - After the INSERT, SELECT COUNT(*) FROM ork_cms_post_tag WHERE post_id = :post_id and compare to count($tagIds); ROLLBACK and return false on mismatch, matching the pattern used in ReplaceBlocks().

### ⚪ LOW
- **[/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias/…/Cms_dashboard.tpl:60]** Page-specific CSS shipped inline instead of the shared, cacheable cms-admin.css — _trivial fix_
    - Move these page-specific rules into style/cms-admin.css (scoped with the existing .cms-dash-*/.cms-sitecard-* class prefixes already used) so they're fetched once and cached across all CMS admin page views instead of bei
- **[…/controller.Cms.php:60]** Identical 3-line scope-resolve/deny/apply preamble copy-pasted across ~10 action methods — _medium fix_
    - Extract a shared private helper, e.g. `_scopeOrDenyWithCap($uid, $capability)` (or a variant accepting a capability-check callback for the _hasAnyCmsCapability cases), that resolves scope, performs the capability gate, c
- **[…/controller.Cms.php:1099]** Block-catalog 'gallery' uses fa-photo-video, not in pinned FontAwesome 5.8.2 — _trivial fix_
    - Swap 'fa-photo-video' for a 5.8.2-valid media/gallery icon, e.g. 'fa-images' (already used for hero_carousel) or 'fa-th-large'.
- **[…/controller.CmsAjax.php:52]** `$BLOCK_TYPES` docblock claims lockstep with `_blockCatalog()` but has entries the catalog doesn't recognize — _medium fix_
    - Either add `stat_ticker`, `tournaments_feed`, and `recap_highlight` to `Controller_Cms::_blockCatalog()`'s `$known` map to complete the feature, or remove them from `$BLOCK_TYPES` if they were abandoned, and correct the 
- **[…/trait.CmsScope.php:166]** Raw $DB DataSet in a controller trait violates the architecture-layer rule — _medium fix_
    - Move the single-column org-name read into the appropriate lib in system/lib/ork3/ (e.g. a Kingdom/Park lib helper) and call it from the trait via the model pass-through, keeping the controller/trait layer free of raw $DB
- **[…/Blog_index.tpl:71]** conventions: blog-card h2 title missing orkui heading gray-pill reset — _trivial fix_
    - Add the same reset to the `.blog-card-title` rule at line 71: `background:transparent;border:none;padding:0;border-radius:0;` (matching the `.blog-title` treatment).
- **[…/Blog_index.tpl:137]** strtotime()+date() formatting idiom copy-pasted across four templates — _trivial fix_
    - Factor the strtotime/date guard into one small helper (e.g. a fdFormatDate($raw, $fmt) function defined once in render_blocks.tpl or a shared includes file) and call it from all four sites instead of re-implementing the 
- **[…/Blog_post.tpl:37]** Inline CSS for tag pills/meta/dark-mode duplicated from Blog_index.tpl rather than shared — _medium fix_
    - Extract the shared tag-pill / meta / dark-mode rules into frontdoor.css (or a small frontdoor/css/blog.css included by both templates) using a shared class name, and delete the duplicated declarations from the inline <st
- **[…/cms/_block_editor.tpl:2131]** `canEdit` is threaded all the way from the host template into the shared engine but is never read — a dead flag documented as part of the public API — _medium fix_
    - Either use `canEdit` to actually disable/hide the Add-block button, drag handles, and per-card delete/duplicate/enable controls when false, or remove it from the documented API/option surface if the host-level Save gate 
- **[…/cms/_shell_top.tpl:142]** Scope-context banner CSS is inlined in the shell rather than the stylesheet, duplicated on every scoped admin page render — _trivial fix_
    - Move the `.cms-scope-banner` rules into the CMS admin's shared stylesheet and drop the inline <style> block here, keeping only the markup.
- **[…/frontdoor/blocks/card_grid.tpl:12]** Duplicated, fragile inline background color coupled to an unrelated attribute-selector hack — _medium fix_
    - Move the light-mode background into a real `.fd-section-muted { background: #f7f8fb; }` base rule in frontdoor.css (next to the existing `.fd-section-light` rule, which already follows this pattern), drop the inline `sty
- **[…/frontdoor/blocks/card_grid.tpl:27]** Subheading hardcodes color:#667 with no dark-mode adaptation — _trivial fix_
    - Drop the inline color and use an existing muted helper (e.g. class="fd-body-text") that already carries a dark override, or add an html[data-theme="dark"] rule that lightens this subheading (e.g. #9aa6c0) to match the ot
- **[…/frontdoor/blocks/gallery.tpl:49]** gallery.tpl emits its full <style> block unconditionally on every instance, unlike the request-scoped dedupe used by sibling blocks — _trivial fix_
    - Wrap the <style> block in the same `if (empty($fdStyleOnce['gallery'])) : $fdStyleOnce['gallery'] = true;` pattern already used by kingdom_parks.tpl/kingdom_events.tpl so the generic CSS rules are emitted at most once pe
- **[…/frontdoor/blocks/richtext.tpl:38]** CTA href-safety ternary copy-pasted verbatim across four block templates — _trivial fix_
    - Extract a shared helper (e.g. a static method on CmsSanitizer such as `CmsSanitizer::SafeHrefOrHash($href)`) and use it in richtext.tpl, steps.tpl, cta_band.tpl, and card_grid.tpl instead of re-deriving the same ternary 
- **[…/class.CmsBase.php:7]** Stale docblock undercounts CmsBase's actual helper surface — _trivial fix_
    - Update the docblock count/description to match the current helper set, or drop the specific number and describe the helper categories generically.
- **[…/class.CmsPage.php:735]** "Table exists" per-request probe pattern implemented three separate times — _trivial fix_
    - Add one protected _tableExists($tableName) helper to CmsBase backed by a single static memo array keyed by table name; replace all three call sites.
- **[…/class.CmsSite.php:312]** Identical 'heading' seed-block literal copy-pasted four times — _trivial fix_
    - Add a small closure, e.g. `$heading = function ($text) { return array('type' => 'heading', 'source' => 'authored', 'enabled' => 1, 'order' => 10, 'fields' => array('text' => $text, 'level' => 2, 'align' => 'center')); };
- **[…/class.CmsSite.php:435]** Nav-item seed guard is a TOCTOU check, not a real concurrency guard — first-load double-submit can duplicate nav rows — _medium fix_ _(regression from remediation)_
    - Either wrap the nav-item inserts (and ideally the whole seed) in a single transaction guarded by the site row's UNIQUE(scope) key (e.g. `SELECT ... FOR UPDATE` on the site row, or a short advisory lock keyed by scope), o
