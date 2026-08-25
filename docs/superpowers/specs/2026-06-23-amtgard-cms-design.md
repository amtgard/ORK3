# Amtgard CMS (v2) — Design Spec & Shared Contract

**Date:** 2026-06-23
**Branch:** `feature/front-door` (continues the front-door work)
**Status:** Approved by owner to implement directly, orchestrated via sequenced build workflows.

This is the **north-star design** for the CMS that manages the front door and a family
of content pages, plus the **shared contract** (data model, block schema, render path,
auth) that every build sub-project must conform to.

## Goal

Let non-developers create, edit, and publish content pages and blog posts that render
through the block system the front door already uses — with a friendly admin UI,
rich-text editing, a media library, and a role-based permission model. v2 is **global
scope** (org-wide pages edited by ORK staff/granted editors); the data model is built so
**kingdom- and park-scoped** pages drop in later with no migration.

## Locked decisions (from brainstorming)

- **Page types (all in scope):** Composed/Landing, Article/Text, Media/Gallery, Blog
  (index + entries), Resource/Document, Dynamic-data.
- **Rendering is universal:** every page type renders as an ordered list of **blocks**
  (the front-door model). "Page type" is an *editor preset + hint* (which blocks/defaults
  the editor offers), not a separate renderer. Blog posts render as blocks + post chrome.
- **Blog = dedicated post model** (own table, author/date/tags/slug, index, entry, RSS).
- **Scope:** v2 = `global` only; carry `scope_type`/`scope_id` everywhere for kingdom/park later.
- **Auth = Hybrid RBAC + scope bridge:** named CMS roles/capabilities, but `CmsCan()`
  defers to existing `HasAuthority` for kingdom/park scopes so officers auto-gain rights later.
- **Rich text = TinyMCE**, stored HTML **sanitized server-side with HTML Purifier**.
- **Workflow:** draft → published + preview + autosave; version history deferred.
- **Media:** new `ork_cms_media` library + picker modal; reuse the existing
  base64→`imagecreatefromstring`→GD pipeline + `audit_media_upload`; add thumbnailing.
- **Front door becomes the first CMS-managed page** (a `home` system page), with the
  hardcoded `Model_FrontDoor` defaults as the fallback when no row exists.

## Codebase conventions (must follow)

- PHP8 MVC. DB logic in `system/lib/ork3/class.*.php`; `orkui/model/model.*.php` are thin
  pass-throughs (`Model_X extends Model`, `__call` forwards to lib). Controllers thin.
- `.tpl`/`.theme` are PLAIN PHP via `extract()`+`include` — **never Smarty**.
- Always `$DB->Clear()` before raw `DataSet`/`Execute`. `ork_configuration` values are
  JSON round-tripped. Dark mode selector `html[data-theme="dark"]`.
- `vendor/` is gitignored → committed vendored libs need `git add -f`.
- Front-door block partials live in `orkui/template/default/frontdoor/blocks/{type}.tpl`
  and consume `$blockFields` + media refs `{key,src,alt,focal}`. This is the shared block
  library the CMS extends.

## Data model (authoritative — all sub-projects conform)

All tables prefixed `ork_cms_`. Every content table carries `scope_type
ENUM('global','kingdom','park') DEFAULT 'global'` and `scope_id INT DEFAULT 0`.

**`ork_cms_page`** — a content page.
- `page_id` PK · `slug` VARCHAR(160) · `type` ENUM('composed','article','media','blog_index','resource','dynamic')
- `title` · `status` ENUM('draft','published') DEFAULT 'draft' · `published_at` DATETIME NULL
- `hero_media_id` INT NULL · `meta_description` VARCHAR(255) NULL
- `is_system` TINYINT DEFAULT 0 (protects e.g. the home page from deletion)
- `scope_type`,`scope_id` · `created_by`,`created_at`,`updated_by`,`updated_at`
- UNIQUE(`scope_type`,`scope_id`,`slug`).

**`ork_cms_block`** — an ordered block belonging to a page OR a post (polymorphic).
- `block_id` PK · `owner_type` ENUM('page','post') · `owner_id` INT
- `type` VARCHAR(40) (block key) · `ordering` INT · `enabled` TINYINT DEFAULT 1
- `source` ENUM('authored','dynamic') DEFAULT 'authored' · `fields_json` JSON
- INDEX(`owner_type`,`owner_id`,`ordering`).

**`ork_cms_post`** — a blog post (body = blocks via `ork_cms_block` owner_type='post').
- `post_id` PK · `slug` VARCHAR(160) · `title` · `excerpt` TEXT NULL
- `hero_media_id` INT NULL · `author_id` INT (mundane_id)
- `status` ENUM('draft','published') · `published_at` DATETIME NULL
- `scope_type`,`scope_id` · `created_by`,`created_at`,`updated_by`,`updated_at`
- UNIQUE(`scope_type`,`scope_id`,`slug`).

**`ork_cms_tag`** (`tag_id`,`name`,`slug`) + **`ork_cms_post_tag`** (`post_id`,`tag_id`).

**`ork_cms_media`** — media library.
- `media_id` PK · `filename` · `path` (relative) · `mime` · `width`,`height`,`bytes`
- `alt` VARCHAR(255) · `title` VARCHAR(160) NULL · `focal` VARCHAR(16) DEFAULT '50% 50%'
- `thumb_path` NULL · `scope_type`,`scope_id` · `uploaded_by`,`created_at`.

**`ork_cms_nav_item`** — editable navigation.
- `nav_id` PK · `menu` VARCHAR(40) (e.g. 'marketing') · `label`
- `link_type` ENUM('page','post','url','dynamic') · `page_id`/`post_id`/`url`
- `parent_id` INT NULL (dropdowns) · `ordering` · `enabled` · `scope_type`,`scope_id`.

**`ork_cms_grant`** — RBAC.
- `grant_id` PK · `mundane_id` · `role` ENUM('contributor','author','editor','publisher','admin')
- `scope_type` ENUM('global','kingdom','park') · `scope_id` INT
- `granted_by`,`created_at` · UNIQUE(`mundane_id`,`role`,`scope_type`,`scope_id`).

## Block library (content types)

Shared partials in `frontdoor/blocks/`. Each block = `{id,type,enabled,order,source,fields}`.
Existing (shipped): `marketing_nav, member_bar, hero_carousel, richtext, card_grid, steps,
events_feed, photo_mosaic, kingdoms_teaser, cta_band`.
New for CMS: `rich_text` (TinyMCE HTML), `heading, divider, spacer, accordion, quote,
table, image, gallery, video_embed, file_download, columns, raw_html`, plus dynamic
`stat_ticker, tournaments_feed, recap_highlight, blog_feed`.
**Page types are editor presets** mapping to a starting block set (e.g. Article =
`hero?`+`rich_text`; Media = `gallery`; Resource = `file_download[]`).

## Render path (universal)

A single reusable block renderer (generalized from `_index.tpl`):
`orkui/template/default/frontdoor/render_blocks.tpl` (or a `CmsRenderer` helper) takes an
ordered block list and `include`s `frontdoor/blocks/{type}.tpl` per block (sanitized
`type`, `file_exists` guard, dark-mode/responsive intact). Used by:
- Home (`Controller::index()`) — blocks from the `home` page row, else `Model_FrontDoor` defaults.
- `Controller_Page::view($slug)` — any published page.
- `Controller_Blog::index()` / `::post($slug)` — post feed + entry (post chrome + blocks).

Authored string fields escape via `htmlspecialchars`; `rich_text.body`/`raw_html` are
sanitized through **HTML Purifier** at save (and defense-in-depth at render).

## Auth model (Hybrid RBAC + scope bridge)

Role → capability map (in code): `contributor` {page.create, page.edit_own},
`author` {+page.edit}, `editor` {+media.manage}, `publisher` {+page.publish},
`admin` {+page.delete, nav.manage, roles.manage}. Capabilities cover pages, posts, media, nav, roles.

`CmsCan($uid, $capability, $scope=['type'=>'global','id'=>0]) : bool`:
1. ORK super-admin → true.
2. Collect the user's `ork_cms_grant` rows whose scope matches (exact scope, or a `global`
   grant which applies to global content). Union their capabilities; if `$capability` present → true.
3. **Bridge:** for `kingdom`/`park` scopes, also true if `HasAuthority($uid, AUTH_KINGDOM|AUTH_PARK, scope_id, AUTH_EDIT)` (officers implicitly edit; `page.publish` requires AUTH_ADMIN). v2 only exercises global, but the bridge is built in.

## Tooling to bring in

- **TinyMCE** — vendored under `orkui/template/default/script/vendor/tinymce/` (or CDN-pinned like FontAwesome). Loaded only on editor + front-door-editor surfaces.
- **HTML Purifier** (PHP) — vendored under `system/lib/vendor/htmlpurifier/` (committed with `git add -f`); wrapped by a `CmsSanitizer` helper.
- **GD** (already present) for upload processing + thumbnails.

## Routing

New controllers: `Controller_Page` (`Page/view/{slug}`), `Controller_Blog`
(`Blog/index`, `Blog/post/{slug}`, `Blog/rss`), `Controller_Cms` (admin:
`Cms/index` list, `Cms/edit/{id}`, `Cms/media`, `Cms/nav`, `Cms/roles`).
Home stays the empty route, now CMS-backed. Pretty URLs deferred; v2 uses
`index.php?Route=...`. Nav items resolve page/post slugs or external URLs.

## Sub-project decomposition (each its own build workflow, in order)

1. **Foundation** — migrations (all `ork_cms_*` tables); `class.CmsPage.php` + model
   (page+block read/CRUD); generalized block renderer + `Controller_Page` + routing;
   migrate the front door to render from the `home` page row with `Model_FrontDoor`
   fallback + a seed that imports the defaults into the store. *No admin UI yet.*
   **Exit check:** migrations apply locally; home renders identically (from store);
   a seeded test page renders at `Page/view/{slug}`; no regressions.
2. **Auth/RBAC** — `ork_cms_grant`, role→capability map, `CmsCan()` + `HasAuthority` bridge, super-admin detection, grant CRUD lib.
3. **Admin editor + TinyMCE + media library** — `Controller_Cms` authoring UI (page list,
   block editor w/ drag-reorder + per-field forms, TinyMCE on rich_text, media picker
   modal, draft/autosave/publish/preview), `class.CmsMedia.php` upload+thumbnail+audit, HTML Purifier wired.
4. **Page-type renderers/editors** — Article, Media/Gallery, Resource presets + new block
   partials (`rich_text, gallery, video_embed, file_download, accordion, quote, heading,
   divider, columns, image, table`), all dark-mode + responsive.
5. **Blog** — `class.CmsPost.php`, `Controller_Blog` (index/entry/RSS), tags, blog editor, `blog_feed` block.
6. **Nav management** — `ork_cms_nav_item` CRUD UI; `marketing_nav` block reads from it.

## Cross-cutting requirements

Dark mode + responsive on every new surface. PSR-12 normalize-first for edited PHP.
Escape/sanitize all authored output. `$DB->Clear()` before raw queries. Migrations are
idempotent (`CREATE TABLE IF NOT EXISTS`) and live in `db-migrations/`. No destructive
operations. Keep `class.Authorization.php` unstaged if the login-bypass hack is present.

## Out of scope (later)

Version history/rollback; kingdom/park-scoped authoring UI (data model ready); pretty
URLs; comments on blog; scheduled publishing; multi-language.
