# CMS Polish — Punch List (Round 2)

Round 1 fixed ~37 findings. Round 2 (this pass) sharded the 44 deferred findings across four
build agents + a QA/review pass. Of those, ~40 are now **fixed** and verified (see below); the
3 large structural refactors are **deferred** by decision; and the review surfaced 1 new
lower-severity follow-up.

Nothing in this round is committed yet — changes sit in the working tree on `feature/front-door`.

---

## ✅ Resolved design decision
- **Soft-delete vs slug-uniqueness** → shipped the **generated live-slug column** approach.
  New migration `db-migrations/2026-07-08-cms-slug-live-and-integrity.sql` adds a STORED
  `slug_live = IF(deleted_at IS NULL, slug, NULL)` to `ork_cms_page`/`ork_cms_post`, drops the
  old `uq_*_scope_slug` keys, and adds `UNIQUE(scope_type, scope_id, slug_live)`. Trashed rows
  (`slug_live` = NULL) no longer reserve a slug; live rows stay unique. Applied + functionally
  verified against local MariaDB. **Deploy step: run this migration.**

---

## ✅ Fixed this round (verified)

**CmsPage / CmsPost / CmsBase (system/lib/ork3/)**
- Slug reuse after trash — generated live-slug column + live-only dup guards (`CmsBase::_insertWithDupGuard`). `CreatePage/Post` filter `deleted_at IS NULL`; delete keeps the slug intact.
- `UpdatePage`/`UpdatePost` IDOR scope-guard params (opt-in, mirrors Delete/Restore); belt-and-suspenders behind the controller's own `_requireOwnerEditable` gate.
- `UpdatePost` dup-slug pre-check + write-verify; **`UpdatePage` dup pre-check `deleted_at IS NULL` filter** (fixed in QA — page lane had diverged from post lane).
- `RecordRedirect` open-redirect — `CmsSanitizer::IsSafeUrl` validation before persist.
- `PagePath`/`GetPageAncestors` per-request memoization (`$_ancestorMemo`, invalidated on parent/child change).
- Create/Delete/Restore/SetStatus deduped into shared `CmsBase` helpers (`_softDelete`/`_restore`/`_setStatus`/`_insertWithDupGuard`), with restore-collision guard (returns `false`, no throw).
- `ReplaceBlocks` split into `_normalizeBlocks`/`_upsertKnownBlocks`/`_deleteRemovedBlocks`/`_insertNewBlocks`/`_verifyBlockCount`; transaction + count/fields_json verify preserved.
- `CmsBase::_tableExists()` single memoized probe replacing 3 copies; docblock corrected.
- New aggregates `CountPages()`/`CountPosts()` (status→count + total).

**CmsNav / CmsMedia / CmsSite**
- `CmsNav::CreateItem` write-verify read-back (no more blind `GetLastInsertId`).
- `CmsNav::UpdateItem` is now authoritative about NULLing the unused link columns per `link_type`.
- `CmsMedia::_referenceCount` short-circuits cheap FK checks before the unbounded `cms_block` REGEXP (deliberately NOT scope-narrowed — fail-safe; `cms_block` has no scope columns).
- `CmsSite` heading-seed closure dedup; **nav-seed TOCTOU** wrapped in a `FOR UPDATE`-guarded transaction.

**Controllers + trait**
- `Blog::index` / `Site::blog` pagination OFFSET clamp (`$pages` floored to 1 — no underflow).
- Dashboard counts via `CountPages/CountPosts` aggregates (no full-row fetch); Recent list bounded to 6.
- `_pageLiveHref` nested-page path via `PagePath()`.
- `savepage`/`savepost` auth deduped through `_requireOwnerEditable` + `_guardConcurrency`.
- `savepost` checks `set_tags()` return (fails loud on partial tag apply).
- `savenavitem` routes link-column clearing through the lib (no controller-side null hack).
- `$BLOCK_TYPES` synced to `_blockCatalog()` — removed 3 phantom types (`stat_ticker`, `tournaments_feed`, `recap_highlight`; no partials, not in catalog).
- `_scopeOrDenyWithCap()` helper replacing the 10× copy-pasted scope preamble.
- `trait.CmsScope` raw `$DB` removed → new `Kingdom::GetName()` / existing `Park::GetParkShortInfo()` via model pass-through.
- `fa-photo-video` → `fa-images` (FA 5.8.2).

**Frontend templates + CSS + CmsSanitizer**
- Shared `fdFormatDate()` (frontdoor/_helpers.tpl) replacing the strtotime/date idiom across 6 templates.
- `CmsSanitizer::SafeHrefOrHash()` replacing the CTA href ternary across 6 blocks (link-suppression `?:''` sites left as-is by design).
- GhettoCache (TTL 300s) for `kingdom_parks` + `kingdom_parks_map` (public data only — no auth-scoped leak).
- CSS extraction: `Cms_dashboard` + `_shell_top` scope-banner → `cms-admin.css`; blog tag-pill + `.fd-section-muted` → `frontdoor.css`.
- `.blog-card-title` gray-pill reset; `card_grid` subheading → `fd-body-text` (dark-mode aware).
- `gallery.tpl` request-scoped `$fdStyleOnce` guard + `--fdb-cols` per-instance column count.
- Dead `canEdit` engine flag removed (host save-gate `STATE.canEdit` retained).

---

## 🚧 Deferred by decision (large structural refactors — separate tickets)
These are genuinely large and carry regression risk; do them deliberately with their own QA.
- **[cms/_block_editor.tpl:164]** Extract the ~2,000-line static JS engine (marked "C27 extraction seam") into `template/default/script/cms-block-editor.js`, loaded as a cache-busted `<script src>`.
- **[Cms_edit.tpl / Cms_editpost.tpl]** The two editors duplicate ~300 lines of save/publish/delete/preview-pane flow — fold into the shared `window.CmsBlockEditor` engine, parameterized by entity kind.
- **[Cms_index.tpl / Cms_posts.tpl]** ~350 lines of list-page JS (toast/modal/POST-helper/publish/delete-undo/overflow/bulk) duplicated verbatim — extract one reusable module.

---

## 🔎 New follow-up from the QA review
- **[class.CmsSite.php — page/block seed]** _(plausible, conf ~55)_ The nav-seed TOCTOU fix
  serializes NAV inserts via `FOR UPDATE`, but the starter **pages + blocks** are seeded *before*
  the lock. Two simultaneous first-loads of an unbuilt org site can interleave two
  `ReplaceBlocks` transactions and double-insert the home page's seed blocks (`ork_cms_block` has
  no unique key). Low frequency, content is editable. Fix: take the site-row `FOR UPDATE` lock
  (or `INSERT IGNORE` + "freshly minted" guard) **before** the page seed, not just around nav.

---

## ✅ Verification note (QA complete)
- **Data-lib adversarial review (completed):** found + fixed the `UpdatePage` missing-`deleted_at`
  dup-check divergence; all other lib claims verified.
- **Security-critical seams (inline):** IDOR scope-relocation → closed (save paths never write a
  POST-supplied scope); cache data-leak → none (public data only); slug-dup → fixed; pagination
  underflow → none (`$pages` floored to 1); cross-agent contracts + `php -l` on all touched files → clean.
- **Secondary frontend/controller review (completed):** 6 of 7 items clean (savenavitem create path,
  `_scopeOrDenyWithCap` capability parity across all 10 actions, fdFormatDate include chain, gallery
  multi-instance, dark-mode CSS-move parity, CSS-extraction integrity). 1 issue found + **fixed**:
  restore now returns a specific "a live page/post already uses this slug — rename it first" message
  instead of a misleading "not in Trash" (new `RestoreSlugConflict()` predicate on CmsPage/CmsPost
  backed by `CmsBase::_slugConflictForTrashed`).

Remaining open: only the plausible CmsSite page/block seed race above (a deliberate follow-up) and
the 3 deferred structural refactors.
