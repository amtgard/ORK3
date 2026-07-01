# CMS Multi-Site — Kingdom Public Websites — Design Spec

**Date:** 2026-07-01
**Branch:** `feature/cms-multi-site` (off `feature/front-door`)
**Status:** Approved by owner to implement, workflow-orchestrated, subagent-driven.

Builds on the [Amtgard CMS (v2) design](2026-06-23-amtgard-cms-design.md) and the
[CMS Theme Engine design](2026-06-27-cms-theme-engine-design.md). Those shipped a
block-based CMS whose every table already carries `scope_type ENUM('global','kingdom','park')`
+ `scope_id`, an RBAC layer (`CmsCan`) that bridges to `HasAuthority`, and a scope-ready
theme engine — all currently exercised only in `global` scope. This spec cashes in that
substrate to give **each kingdom its own public-facing website**, authored with the existing
free-form block builder.

## Goal

Let a kingdom's officers build and publish a standalone public website — their own pages,
navigation, theme, and media — addressed at `/k/{slug}`, using the block editor that already
powers the global front door. The starter template is an optional, fully-editable seed. The
data model is built park-ready so `/p/{slug}` park sites drop in later with no migration.

## Locked decisions (from brainstorming)

- **Authoring shape:** Free-form CMS builder scoped per org — unlimited pages, custom nav,
  blocks in any order, own theme. The starter template is an optional seed the org can gut.
- **Addressing:** Path-based on the ORK domain — `/k/{kingdom-slug}` (parks `/p/{slug}` later).
  No DNS/TLS/subdomain work; scope resolved from the path. Pretty-URL rewriting beyond this
  routing is deferred.
- **Separation from CRM:** The public site is **wholly separate** from the internal
  `/Kingdom/profile/{id}` CRM home tab. The site neither replaces, embeds, nor redirects the
  profile. ORK data appears on the *public* site only via its own dynamic blocks.
- **Scope of v1:** Kingdoms only. Data model is park-ready; park sites are deferred.
- **Ownership & lifecycle:** Officers self-serve edit (via the existing `HasAuthority`
  `AUTH_EDIT` bridge — no manual grant). Publishing requires an `AUTH_ADMIN` officer
  (monarch/regent). A site is unpublished until first publish.
- **Dynamic ORK-data blocks (v1):** `kingdom_officers`, `kingdom_parks`, `kingdom_events`.
  Board of Directors / non-ORK roles use the existing authored `staff_roster` block. (No
  stats ticker — cut as unused on a public marketing site.)
- **Chrome:** Fully standalone per org — own header (logo + site name), own scoped nav, own
  theme, own footer. Only a subtle footer "Part of the Amtgard ORK ↗" tie-back. No global
  ORK top bar on org sites.
- **Implementation approach (A):** Thread scope through the existing CMS + add a public site
  router. Reuse `CmsPage`/`CmsPost`/`CmsMedia`/`CmsNav`/`CmsTheme`/`CmsAuth` (all already
  scope-parameterized) rather than build a parallel admin.

## Codebase conventions (must follow)

- PHP8 MVC. DB logic in `system/lib/ork3/class.*.php`; `orkui/model/model.*.php` thin
  pass-throughs (`__call` forwards to lib). Controllers thin.
- `.tpl`/`.theme` are PLAIN PHP via `extract()`+`include` — **never Smarty** (`<?php ?>`/`<?= ?>`).
- Always `$DB->Clear()` before raw `DataSet`/`Execute`. `ork_configuration` values JSON
  round-tripped. Dark-mode selector `html[data-theme="dark"]`.
- Migrations idempotent (`CREATE TABLE IF NOT EXISTS`), in `db-migrations/`. No destructive ops.
- Escape/sanitize all authored output (`htmlspecialchars`; rich text via HTML Purifier at save).
- PSR-12 normalize-first for edited PHP. Keep `class.Authorization.php` unstaged if the
  login-bypass hack is present; stage files explicitly (never `git add -A`).
- FontAwesome 5.8.2 only. `data-tip` tooltips (no native `title`). `tnConfirm()` (no native dialogs).

## Data model

One new table introduces the "site" concept — an addressable, publishable grouping of the
already-scoped `ork_cms_*` content. **No changes to existing CMS tables.**

**`ork_cms_site`** — an org's public website.

| column | type | notes |
|---|---|---|
| `site_id` | INT PK auto-inc | |
| `scope_type` | ENUM('kingdom','park') | `global` reserved to the existing front door |
| `scope_id` | INT | `kingdom_id` / `park_id` |
| `slug` | VARCHAR(160) | the `/k/{slug}` segment |
| `site_name` | VARCHAR(160) | display name in the header |
| `logo_media_id` | INT NULL | FK to `ork_cms_media` |
| `status` | ENUM('unbuilt','draft','published') DEFAULT 'unbuilt' | |
| `published_at` | DATETIME NULL | |
| `home_page_id` | INT NULL | which `ork_cms_page` is the landing page |
| `created_by`,`created_at`,`updated_by`,`updated_at` | | audit |

- UNIQUE(`scope_type`,`scope_id`) — one site per org.
- UNIQUE(`slug`) — globally unique across all org sites.
- INDEX(`status`).

All pages, blocks, posts, tags, media, nav items, grants, and themes remain in the existing
`ork_cms_*` tables, keyed by `(scope_type, scope_id)`. The site row adds addressability
(slug), identity (name/logo), a home-page pointer, and a publish lifecycle.

## Components

### 1. `class.CmsSite.php` (+ `model.CmsSite.php`) — the site concept

Extends `CmsBase`. Owns the `ork_cms_site` lifecycle and slug logic. Methods:

- `GetSiteBySlug($slug) : ?row` — public resolver; returns the site row or null.
- `GetSiteForScope($scopeType, $scopeId) : ?row` — admin lookup.
- `EnsureSite($scopeType, $scopeId, $uid) : row` — lazily create (`status='unbuilt'`) + seed
  the starter template on first "Manage Public Site" open. Idempotent.
- `SetPublished($siteId, $uid)` / `SetDraft($siteId, $uid)` — lifecycle transitions;
  `SetPublished` stamps `published_at`.
- `UpdateSite($siteId, $fields, $uid)` — name, logo, slug (slug editable by `AUTH_ADMIN`).
- `DeriveSlug($name) : string` — kingdom name → `[a-z0-9-]` slug.
- `ValidateSlug($slug, $exceptSiteId=0) : true|error` — charset, reserved-word list
  (`k`,`p`,`Cms`,`CmsAjax`,`Blog`,`Page`,`Directory`,`admin`,`Login`,`Kingdom`,`Park`,…),
  and uniqueness (pre-check for a friendly error; DB unique key is the hard guard).
- `$DB->Clear()` before every raw query; YapoSave null-skip rule (assign `''` not null).

### 2. Scope resolution & public routing — `Controller_Site` (new)

`orkui/controller/controller.Site.php`. Routes:

- `Site/view/{slug}` (pretty: `/k/{slug}`) → site home = `home_page_id` page, rendered as blocks.
- `Site/page/{slug}/{pageSlug}` (`/k/{slug}/{pageSlug}`) → a published scoped page.
- `Site/blog/{slug}` and `Site/post/{slug}/{postSlug}` → scoped blog index + entry (reuses
  `CmsPost` list/entry logic with the resolved scope).

Flow: resolve `{slug}` → `ork_cms_site` → `(scope_type, scope_id)` → existing
`CmsPage::GetPageBySlug($pageSlug, $scopeType, $scopeId, publishedOnly=true)` → existing
`frontdoor/render_blocks.tpl`. Draft/unbuilt/unknown handled per Error Handling below.

The `/k/{slug}` ↔ `Site/view/{slug}` mapping is registered in the app router alongside the
existing routes; the underlying controller action is scope-driven and identical for pretty
and raw URLs.

### 3. Standalone public chrome — site shell template

New `orkui/template/default/site/site_shell.theme` (or parameterized reuse of `default.theme`)
renders: org header (logo + `site_name`), scoped nav via existing `CmsNav::GetMenu($scope)`,
block content via `render_blocks.tpl`, and a footer with the subtle tie-back link. The
per-org theme is injected exactly as today — `CmsTheme->GetActiveCss($scope)` into `<head>`
— so each kingdom themes independently with **no new theme-engine code**. Dark mode
(`html[data-theme="dark"]`) and responsive layout intact.

### 4. Scope-aware admin — extend existing `Controller_Cms` / `Controller_CmsAjax`

Make scope an explicit **context** instead of the hardcoded `global`:

- **Entry point:** a "Manage Public Site" action from the kingdom management area. It verifies
  the officer's authority (`HasAuthority(AUTH_KINGDOM, kingdom_id, AUTH_EDIT)`), calls
  `CmsSite::EnsureSite(...)`, then enters the CMS admin with a scope context.
- **Scope context:** carried in the route (e.g. `?scope=k:{kingdom_id}`) and **re-validated
  server-side on every request** — never trusted from the client alone. Resolved scope must
  be one the user has authority over.
- Every `Cms*`/`CmsAjax` action reads the scope, passes it to the already-scope-aware libs,
  and gates via `CmsCan($uid, $cap, $scope)`. Publish caps require `AUTH_ADMIN`; edit requires
  `AUTH_EDIT`.
- **UI unchanged in structure** — page list, block editor, media, nav, theme all operate
  within the active scope, plus a persistent "Editing: {Kingdom}'s site" context banner so an
  officer never confuses it with the global front door.
- **Isolation:** all reads scope-filtered; every mutation carries an **IDOR guard** — the
  target row's `(scope_type,scope_id)` must equal the request's resolved scope.

### 5. Org-scoped dynamic blocks (the new content build)

Three new `source='dynamic'` block partials under `frontdoor/blocks/`, each reading data for
the block's owning scope (scope passed at render — no cross-scope leakage), plus their
resolvers in the appropriate libs (`class.Kingdom.php` / `class.Report.php` /
`class.Officer.php` as fits existing patterns):

- **`kingdom_officers`** — current officers from ORK data (persona/name, office, custom title,
  photo). Pairs with the authored `staff_roster` block for Board of Directors / non-ORK roles.
- **`kingdom_parks`** — active parks for the kingdom (name, location, link; each park optionally
  deep-links to its future park site).
- **`kingdom_events`** — scoped version of the existing global `events_feed`.

Each: dark-mode + responsive from the start, escapes all ORK-data output, graceful empty state
when no officers/parks/events exist.

### 6. Starter template & provisioning

`CmsSite::EnsureSite` seeds (all editable/deletable — a seed, not a cage):

- A `home` page (`is_system` within the scope) — hero + intro + a curated starter block set.
- **About Us / History** page — `rich_text`.
- **Our Parks** page — `kingdom_parks` block.
- **Officers** page — `kingdom_officers` + `staff_roster` (Board of Directors).
- **Documents & Resources** page — `file_download` library.
- A scoped nav menu (`ork_cms_nav_item`, `menu='site'`, scope) linking the pages.
- `home_page_id` set to the seeded home page.

### 7. Discovery

The kingdom Directory / listing surfaces a "Visit site" link when the org's site is
`published`; otherwise no public link. (Minimal; deeper cross-site discovery deferred.)

## Auth & security

- Reuse `CmsAuth::CmsCan($uid, $cap, $scope)`. Edit caps via `HasAuthority(AUTH_KINGDOM,
  scope_id, AUTH_EDIT)`; publish / site-publish requires `AUTH_ADMIN`. Super-admin short-circuit stays.
- **IDOR guard** on every mutation (target scope == resolved authorized scope).
- **CSRF**: all new `CmsAjax` mutations flow through existing `_begin()` (`X-CSRF-Token`); no exemptions.
- **Output safety**: authored strings `htmlspecialchars`; rich text via HTML Purifier at save;
  dynamic blocks escape ORK-data fields. Slug `[a-z0-9-]`, reserved-word blocked, unique (DB key + pre-check).
- **Scope isolation**: media/nav/pages/theme queries always scope-filtered; no org sees or
  touches another's content or a global-front-door row.

## Error handling

- Unknown / `unbuilt` / unpublished slug → clean "coming soon" or 404; never a stack trace or
  a leak of draft content to the public. Draft pages visible only to authorized officers via preview.
- Publish without `AUTH_ADMIN` → clear "a monarch or regent must publish" message, not a hard failure.
- Slug collision → inline validation error before save.
- Empty dynamic-block data → graceful empty state, not a broken block.
- `$DB->Clear()` before every raw query; migrations idempotent.

## Testing

- **Unit:** `CmsSite` slug derivation/validation/reserved-words/uniqueness; scope resolver
  (`/k/{slug}` → scope; bad slug → null); `CmsCan` edit-vs-publish across officer / non-officer
  / super-admin; the three dynamic-block resolvers return correctly-scoped rows + empty states;
  `EnsureSite` idempotency.
- **Integration/manual:** provision a kingdom site → seed appears → officer edits a page →
  blocked from publishing → monarch publishes → `/k/{slug}` renders live with the org's theme,
  nav, and the three dynamic blocks; a second kingdom's editor cannot see the first's content;
  dark mode verified on the public site and every new admin surface.
- **Regression:** the existing global front door stays `scope='global'`, unchanged path.

## Build sequence (workflow phases)

1. **Foundation** — `ork_cms_site` migration; `class.CmsSite.php` + model (lifecycle, slug
   derive/validate, `EnsureSite`); scope resolver. Unit coverage of slug + resolver.
   *Exit:* migration applies; a hand-seeded site row resolves by slug.
2. **Public routing + chrome** — `Controller_Site`, `/k/{slug}` route registration,
   `site/site_shell.theme` standalone chrome, per-org theme injection, "coming soon"/404 states.
   *Exit:* a published hand-seeded site renders at `/k/{slug}` with its theme + nav.
3. **Scope-aware admin** — scope context in `Controller_Cms`/`CmsAjax`, "Manage Public Site"
   entry from the kingdom area, context banner, IDOR guards, publish gate.
   *Exit:* an officer edits within scope, is blocked from publishing, monarch publishes.
4. **Dynamic blocks** — `kingdom_officers`, `kingdom_parks`, `kingdom_events` partials +
   resolvers; dark-mode + responsive + empty states.
   *Exit:* each block renders correctly-scoped live data on a site page.
5. **Starter template + discovery** — `EnsureSite` seeding of the 5 pages + nav + home pointer;
   Directory "Visit site" link. *Exit:* first "Manage Public Site" open yields a complete editable seed.

## Out of scope (v1)

Park sites (`/p/{slug}` — data model ready); subdomain / custom domains; pretty-URL rewriting
beyond `/k/{slug}`; cross-site search; version history; scheduled publishing; per-page SEO
beyond `meta_description`; migrating the internal `/Kingdom/profile` CRM page (deliberately separate).

## Cross-cutting requirements

Dark mode + responsive on every new surface (public and admin). PSR-12 normalize-first for
edited PHP. Escape/sanitize all authored + dynamic output. `$DB->Clear()` before raw queries.
Idempotent, non-destructive migrations. Keep `class.Authorization.php` unstaged if the
login-bypass hack is present; stage files explicitly.
