# amtgard.com → CMS Content Replication — Design

**Date:** 2026-07-08
**Branch:** feature/front-door
**Author:** Avery Krouse (+ Claude)

## Goal

Programmatically replicate the **content** of www.amtgard.com into the new content-block CMS, at global scope (the org-wide front door). Aim for a 1:1 transfer of *content* (text, structure, images, documents) — **not** the visual style. The existing CMS front-door styling is intended to be the "next" look; the home page stays as-is (it is already better than amtgard.com's).

Success = every amtgard.com content page exists as a published CMS page under `scope_type='global', scope_id=0`, with equivalent content faithfully decomposed into CMS blocks, images re-hosted in the CMS media library, the navigation menu replicated (including external links), and every page verified to render locally.

## Scope

### Pages to replicate (15 content pages)

| # | amtgard.com path | CMS slug | Parent | Notes |
|---|---|---|---|---|
| 1 | /about | `about` | — | supersedes existing exemplar stub |
| 2 | /mission | `mission` | about | |
| 3 | /staff | `staff` | about | staff_roster / card_grid |
| 4 | /volunteers | `volunteers` | about | |
| 5 | /join | `join` | — | supersedes existing exemplar stub |
| 6 | /learn-the-basics | `learn-the-basics` | join | steps / rich_text |
| 7 | /start-a-chapter | `start-a-chapter` | join | steps |
| 8 | /programs | `programs` | — | ("AI Programs" label in nav) |
| 9 | /foodfight | `foodfight` | programs | |
| 10 | /olympiad | `olympiad` | programs | |
| 11 | /media | `media` | — | |
| 12 | /galleries | `galleries` | media | gallery blocks, photo-heavy |
| 13 | /writing | `writing` | media | |
| 14 | /resources | `resources` | — | ("Official Resources") |
| 15 | /documents | `documents` | resources | file_download blocks (PDFs) |

Agents confirm any deeper sub-pages during extraction and fold them in (extra pages or extra blocks as appropriate).

### Nav (global menu)

Replicate the 6 top-level dropdowns plus two **external** links, added to `ork_cms_nav_item` (global scope):
- Find a Chapter → `index.php?Route=Atlas` (existing ORK route)
- Amtgard Merch → `https://www.redbubble.com/people/amtgardmarket/shop`

Home stays untouched.

### Non-goals / explicit deferrals

- **Visual style** — not replicated. CMS front-door styling stands.
- **Home page** — untouched.
- **In-CMS document (PDF) storage** — the media library is raster-only (`CmsMedia::Upload()` rejects non-image mimes). Proper CMS file upload/storage is a **separate follow-up enhancement** (see Risks). Interim: PDFs are self-hosted under `assets/cms-docs/` and referenced by URL in `file_download` blocks, so the Documents page is complete today.
- Existing exemplar-seed pages not in amtgard's nav (`faq`, `media-gallery`) are left alone.

## Architecture — extract-then-seed

Chosen over UI-driven page building because the CMS uses **single-device sessions**: multiple Chrome agents sharing one CMS login would evict each other. Extract-then-seed also keeps the build deterministic, reviewable, and idempotently re-runnable.

The one true insert path is the lib layer (`CmsPage::CreatePage` + `CmsPage::ReplaceBlocks`), exercised from a CLI seed script with no HTTP/CSRF/auth — exactly as `db-migrations/2026-06-23-cms-seed-exemplars.php` already does.

### Phase 1 — Parallel extraction (one agent per page, ~15 agents)

Each agent owns one page end-to-end and produces **data only** (no DB writes):
1. Read the page (WebFetch for text; Chrome `get_page_text`/`read_page` for JS-rendered content e.g. galleries, staff cards).
2. Decompose content into an **ordered block list** using the documented block vocabulary (see Block Vocabulary).
3. Download every referenced image (curl) and PDF into `scratchpad/amtgard-clone/assets/<slug>/`, recording original URL + alt text.
4. Emit a page spec to `scratchpad/amtgard-clone/specs/<slug>.json`.

**Page-spec JSON contract:**
```json
{
  "slug": "learn-the-basics",
  "type": "composed",
  "parent_slug": "join",
  "title": "Learn the Basics",
  "meta_description": "…",
  "hero_asset": { "file": "assets/learn-the-basics/hero.jpg", "alt": "…" },
  "blocks": [
    { "type": "rich_text", "order": 1, "fields": { "heading": "…", "body": "<p>…</p>" } },
    { "type": "gallery", "order": 2, "fields": { "columns": 3 },
      "_assets": { "images": [ { "file": "assets/…/1.jpg", "alt": "…" } ] } }
  ]
}
```
- `fields` holds final block content **except** media, which stays as `_assets` placeholders (local staging filenames) — media_ids/src are assigned in Phase 2.
- Block field shapes follow `controller.Cms.php::_starter()` `$defaults` (the authoritative reference).
- HTML in `body` fields is clean semantic HTML (`<p>`, `<ul>`, `<a>`, `<strong>`…); it is re-sanitized by `CmsSanitizer` on save.

### Phase 2 — Asset import (orchestrator, one pass)

Walk all specs; for each `_assets` image call:
```php
$row = $media->Upload(base64_encode(file_get_contents($abs)), $name, $alt, $SYS_UID, ['type'=>'global','id'=>0]);
```
Rewrite placeholders into real refs: `image`→`fields.image=$ref`, `gallery`→`fields.images=[$ref,…]`, `hero_carousel`→`fields.slides[i].image=$ref`, page hero→`hero_media_id=$row['media_id']`. Build against **`HTTP_HOST=localhost:19080`** (bakes the media `src`). PDFs → copy into `assets/cms-docs/` and set `file_download` `fields.files[i].url` to `/assets/cms-docs/<name>`.

Output: resolved specs (media refs inlined).

### Phase 3 — Seed + publish (orchestrator)

`db-migrations/2026-07-08-cms-seed-amtgard.php`, modeled on the exemplar seed. CLI-guarded (`PHP_SAPI==='cli'`), force `HTTP_HOST=localhost:19080`, `require startup.php`, `new CmsPage()`. Two passes for hierarchy:
1. Create all pages first (so `parent_id` targets exist): per page `GetPageBySlug`→`DeletePage` (idempotent re-seed) → `CreatePage(global/0, type, title, meta, hero_media_id)` → `SetStatus(published)`.
2. Set `parent_id` for children (About/Join/Programs/Media/Resources trees), then `ReplaceBlocks('page', $pid, $blocks)` for each.

### Phase 4 — Nav (orchestrator)

`db-migrations/2026-07-08-cms-seed-amtgard-nav.php`, modeled on `2026-06-23-cms-nav-relink.php`. Insert global `ork_cms_nav_item` rows: 6 top-level items (link_type `page`, pointing at parent slugs) each with child items (link_type `page` for internal, `url` for the two external links). Idempotent (clear existing global menu rows for this menu, re-insert).

### Phase 5 — Verify (orchestrator, Chrome)

Docker up (`docker-compose.php8.yml`, localhost:19080). For each of the 15 slugs load `index.php?Route=Page/view/<slug>`: confirm blocks render, images/thumbs load, PDFs download, nav dropdowns resolve, dark mode holds (`html[data-theme="dark"]`). Fix regressions. Spot-check hierarchy/breadcrumbs.

## Block Vocabulary (allowed types)

Authored: `hero_carousel`, `rich_text`, `heading`, `card_grid`, `cta_band`, `steps`, `staff_roster`, `accordion`, `quote`, `table`, `gallery`, `photo_mosaic`, `image`, `video_embed`, `file_download`, `columns`, `divider`, `spacer`, `raw_html`. Field shapes per `controller.Cms.php::_starter()`. Prefer `rich_text` over legacy `richtext`. Avoid dynamic blocks (member_bar/events_feed/etc.) — this is static content replication.

## Idempotency & safety

- Everything targets `scope_type='global', scope_id=0`.
- Seeds are re-runnable: delete-by-slug (soft delete) before create; nav menu cleared before re-insert.
- No reserved slugs used (reserved: `blog`,`post`,`p`,`k`).
- Agents never write to the DB — zero write contention.
- Staging is under the session scratchpad; only the two seed scripts + `assets/cms-docs/` PDFs are committed.

## Risks / follow-ups

1. **No CMS file/document storage (deferred enhancement).** Media library is raster-only. Interim self-hosts PDFs in `assets/cms-docs/`. Recommend a follow-up: extend `CmsMedia` (or a new `CmsFile`) to accept documents with mime allowlist + safe storage, and a `file_download` picker.
2. **Host-baked media `src`.** Seeded image URLs embed `localhost:19080`. A prod deploy requires re-running the media import + seed against the prod host (or a follow-up to store relative paths / resolve at render).
3. **Content fidelity vs. JS-rendered pages.** Galleries/staff may be JS-driven; agents fall back to Chrome. Verify counts in Phase 5.
4. **amtgard.com structure drift.** Sitemap captured 2026-07-08; agents reconcile live during extraction.

## Deliverables

- `db-migrations/2026-07-08-cms-seed-amtgard.php` (pages + blocks)
- `db-migrations/2026-07-08-cms-seed-amtgard-nav.php` (nav)
- Re-hosted images in `ork_cms_media` (global); PDFs in `assets/cms-docs/`
- 15 published global CMS pages + replicated nav, verified locally
