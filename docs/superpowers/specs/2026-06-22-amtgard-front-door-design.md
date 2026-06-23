# Amtgard Front Door — Design Spec

**Date:** 2026-06-22
**Branch:** `feature/front-door`
**Status:** Approved design → ready for implementation plan

## Goal

Replace the ORK's utilitarian landing page with a rich, themed, photo-forward
**"front door" for Amtgard** — a cinematic, newcomer-first marketing landing that
also serves logged-in members. The existing landing (a Kingdoms Directory) is
preserved verbatim and relocated to its own route.

**Forward-looking constraint:** the page must be built so that a future **CMS**
(admin controls, image pickers, rich-text content areas, block reordering, a blog)
can manage it **without re-architecting the front end.** This first cut ships
placeholder content, but renders from a structured content model — not hardcoded
markup. See "Content model & CMS-readiness."

## Decisions (locked)

| Topic | Decision |
|---|---|
| Audience model | **Adaptive single page.** One page for everyone; logged-in members get a slim personalized strip; the bulk is newcomer/marketing content. |
| Primary job | **Recruit-leaning, serves both.** Lead with the epic recruiting hook; surface live/public content below; member tooling trimmed to a slim bar. |
| Visual direction | **Epic Cinematic.** Deep navy (`#0b1120`), gold accent (`#f0b429`), `MedievalSharp` display type over crisp system-sans body. Full-bleed photography, dramatic gradients. |
| Photography | **Photo-rich.** Auto-rotating hero carousel, large section imagery, a photo mosaic. Uses the 8 user-supplied event photos + the Amtgard logo. |
| Marketing nav | **Front-door-only.** A placeholder nav mirroring amtgard.com renders only on the landing page; all other pages keep the standard ORK header untouched. |
| Education content | **Placeholder shells now.** Sections + links exist with placeholder copy; real education pages/copy are a later feature. |
| Kingdoms Directory | **Moves to a new `/Directory` controller** (`Directory/index`), content carried over unchanged. |
| **Content sourcing** | **Content-model driven from day one.** A provider returns a structured, typed block list; v1 provider returns hardcoded defaults. A future CMS replaces the provider's data source only. |

## Content model & CMS-readiness

The front door is modeled as an **ordered list of typed content blocks**, not a
fixed template. The template is a generic block renderer; each block type has a
small partial. This is the single most important architectural decision for CMS
readiness: **content (what/where/which image/which copy) is data; presentation
(how a block type looks) is the partial.**

### Block contract

Every block is an associative array:

```php
[
  'id'      => 'hero',            // stable slug (CMS row key later)
  'type'    => 'hero_carousel',   // selects the render partial
  'enabled' => true,              // CMS visibility toggle
  'order'   => 20,                // CMS drag-reorder
  'source'  => 'authored',        // 'authored' (CMS-editable) | 'dynamic' (live ORK data)
  'fields'  => [ /* type-specific, see below */ ],
]
```

The template iterates blocks ordered by `order`, skips `enabled=false`, and
`include`s `frontdoor/blocks/{type}.tpl`, passing `$block['fields']`. Unknown
types are skipped safely. Adding a future block type = add a partial + emit the
block from the provider; no renderer change.

### Block types (v1)

| `type` | `source` | Key fields (CMS-editable later) |
|---|---|---|
| `marketing_nav` | authored | `logo` (media ref), `items[]` {label, href, children[]}, `cta` {label, href} |
| `member_bar` | dynamic | (renders from `LoggedIn` + viewer identity; hidden when anonymous) |
| `hero_carousel` | authored | `slides[]` {image (media ref), kicker, headline, subcopy, ctas[]}, `autoplay_ms`; overlaid `stat_ticker` is dynamic |
| `richtext` | authored | `kicker`, `heading`, `body` (rich text), `cta` {label, href}, `align` |
| `card_grid` | authored | `kicker`, `heading`, `cards[]` {image (media ref), icon, title, blurb, href} |
| `steps` | authored | `kicker`, `heading`, `steps[]` {n, title, body}, `band` (light/dark), `cta` |
| `events_feed` | dynamic | `heading`, `limit`, `more_href` (data from `EventSummary`) |
| `tournaments_feed` | dynamic | `heading`, `limit` (data from `Tournaments`) |
| `recap_highlight` | dynamic | `heading`, `more_href` (data from `week_recap`) |
| `photo_mosaic` | authored | `images[]` (media refs), `caption` |
| `kingdoms_teaser` | dynamic | `heading`, `limit`, `more_href` → `Directory/index` (data from `ActiveKingdomSummary`) |
| `cta_band` | authored | `logo` (media ref), `heading`, `subcopy`, `ctas[]`, `links[]` |
| `blog_feed` | dynamic | **(future)** `heading`, `limit` — reserved; not emitted in v1 |

**Media reference** is itself a small struct so an image picker can later swap a
source without touching markup:

```php
[ 'key' => 'hero-1', 'src' => '<url>', 'alt' => '...', 'focal' => '50% 40%' ]
```

In v1 `src` points at committed asset files; later it points at uploaded media.

### Provider seam

- **Lib:** `system/lib/ork3/class.FrontDoor.php` — `GetContent(array $ctx): array`
  returns the ordered block list. **v1 implementation returns hardcoded defaults**
  (from a `defaults()` method / a `frontdoor-defaults.php` array). The method
  signature and return shape are the contract a CMS will satisfy later (reading
  rows + media + ordering from a store instead of the defaults array).
- **Model:** `orkui/model/model.FrontDoor.php` — thin pass-through per the
  architecture-layers rule.
- **Controller:** base `Controller::index()` calls the model, then merges
  `dynamic` blocks with the live data it already loads (events/recap/tournaments/
  kingdoms) and exposes a single `$FrontDoor` payload to the template.

Authored vs dynamic split matters for the CMS: **authored** blocks are what the
CMS edits (copy, images, order, on/off); **dynamic** blocks are wired to live ORK
data and the CMS only configures their framing (heading, limit, visibility).

### What the CMS will later add (designed-for, NOT built now)

The v1 architecture must leave clean seams for, but does **not** implement:

- **Content store** — DB tables (e.g. `ork_frontdoor_block` + `ork_frontdoor_media`,
  or an EAV/JSON document) the provider reads instead of `defaults()`. The block
  contract above is the schema target.
- **Admin editing UI** — block list with enable/disable, drag-reorder, add/remove;
  per-field editors.
- **Image picker / media library** — uploads resolving to media refs; the
  `media ref` struct is the integration point.
- **Rich-text content areas** — `richtext`/`steps`/`card` body fields authored via
  an editor; v1 stores plain strings/HTML in the same fields.
- **Blog space** — a `blog_feed` block (reserved type) + a blog
  controller/store/post pages. Out of scope now; the block model + routing
  accommodate it.
- **Per-kingdom / scoped variants** — provider `$ctx` already carries viewer +
  kingdom context so a future CMS could serve kingdom-specific front doors.

## Architecture

### Routing & templates

ORK resolves the home route (empty `Route`) to the base `Controller::index()`,
which renders `template/default/default.tpl` (the current fallback). Today that
fallback file *is* the landing page. We split this cleanly:

1. **Front door** → new `template/default/Controller_index.tpl` (generic block
   renderer). Resolved before the `default.tpl` fallback for the home route.
2. **Block partials** → `template/default/frontdoor/blocks/{type}.tpl` (one per
   block type) + shared `frontdoor/css/frontdoor.css`, `frontdoor/js/frontdoor.js`.
3. **Kingdoms Directory** → new `controller.Directory.php` (`Controller_Directory`)
   + `template/default/Directory_index.tpl`. Directory markup currently in
   `default.tpl` moves here verbatim (welcome title relabeled; "Your Kingdom"
   pinning logic comes along).
4. **`default.tpl`** reduced to a minimal neutral generic fallback (no longer
   doubles as home), removing the smell where template-less routes render the
   directory.

A link to `Directory/index` is added wherever "Kingdoms" is surfaced (front-door
teaser "Browse the full Kingdoms Directory →", ORK menu/search as appropriate).

### Data (all already loaded by `Controller::index()`)

`dynamic` blocks reuse data the base controller already provides — **no new
DB/library work for v1**, honoring the data-usefulness rule:

- **Stat ticker / kingdoms teaser** ← `ActiveKingdomSummary`.
- **Upcoming Events** ← `EventSummary` (includes RSVP counts).
- **Week in Review** ← `week_recap`.
- **Recent Tournaments** ← `Tournaments` (`Report::TournamentReport`).
- **Member strip / tools gating** ← `LoggedIn`, `UserKingdomId`/`UserParentKingdomId`, viewer identity.

The new `FrontDoor` lib introduces no DB queries in v1 (defaults only). The
`Directory` controller `index()` replicates the data loads the directory view needs.

### Marketing nav placement

Rendered as the first block of the front-door content (`marketing_nav`), so it
never appears on other routes. A `fd-home` body flag is set on the home route so
the master `default.theme` can adjust stacking if needed. Default behavior keeps
the standard ORK header present beneath the marketing nav; implementation confirms
stacking against `#newmenu`.

Nav structure (placeholder; internal links `#`/TBD, external → amtgard.com):
`Home · About`(Mission, Staff, Volunteers) `· Join`(Learn the Basics, Find a
Chapter, Start a Chapter) `· AI Programs`(Food Fight, Olympiad) `· Media`
(Galleries, Writing) `· Official Resources`(Documents) `· Merch`(Redbubble).
ORK-specific right side: "Record Keeper" link + "Find a Chapter" CTA. **These nav
items live in the `marketing_nav` block's `items[]` — i.e. CMS-editable later.**

### Assets

- Copy the 8 event photos + logo into a committed, optimized location:
  `orkui/template/default/img/frontdoor/` (`hero-1.jpg … hero-8.jpg`,
  `amtgard-logo.avif` + `.png` fallback). Resize/compress (≤ ~250 KB, ~1600px).
  Sources: `~/Downloads/amt-img-*.jpg`, `~/Downloads/amtgardlogo.avif`. These are
  the default media refs; a future media library supersedes them.
- **Assumption:** user-supplied imagery is cleared for use.

### Front-door CSS/JS

- Styles scoped with `fd-` prefix; `frontdoor.css` + `frontdoor.js`.
- Carousel: vanilla JS, auto-advance (~4.5s), clickable dots, pause-on-interaction;
  no new library dependency.

## Page structure (block order, top → bottom)

`marketing_nav` → `member_bar` (logged-in only) → `hero_carousel` (+ stat ticker)
→ `richtext` (What is Amtgard) → `card_grid` (Find Your Path — placeholder) →
`steps` (Your First Day — placeholder) → `events_feed` → `photo_mosaic` →
`kingdoms_teaser` (→ `Directory`) → `cta_band` (Get Involved).

Anonymous vs logged-in: `member_bar` hidden when anonymous; member tools/links
gated on `LoggedIn`; all marketing/education/public content identical.

## Cross-cutting requirements

- **Dark mode (required).** Navy-dominant, but light blocks (`#fff`, `#f7f8fb`,
  cards, "What is Amtgard") need explicit `html[data-theme="dark"]` treatments.
  Walk every surface in dark mode before done.
- **Responsive/mobile.** Carousel, grids (paths, events, mosaic, kingdoms)
  collapse gracefully; nav becomes a mobile menu; member bar wraps.
- **Heading reset.** Headings inside hero/cards/bands must reset the global
  `orkui.css` h1–h6 gray-box styling.
- **No native tooltips / dialogs.** Use in-product patterns.
- **PSR-12 / normalize-first** for edited PHP; `.tpl` is plain PHP
  (`<?php ?>`/`<?= ?>`, `extract()`+`include`), **not Smarty**.
- **Escaping.** Authored fields render through `htmlspecialchars` except fields
  explicitly typed as rich-text/HTML (documented per field) — important now and
  doubly so once a CMS feeds user input.

## Out of scope (future features / phases)

- **CMS (Phase 2):** content store, admin editing UI, image picker/media library,
  rich-text editing, block add/reorder, scoped variants. (Designed-for above.)
- **Blog (Phase 2+):** `blog_feed` block + blog controller/posts.
- Real education content + dedicated path/basics sub-pages.
- Functional amtgard.com nav destinations (placeholder links now).
- New reporting/DB aggregation (front door reuses existing data only).
- Adopting the marketing nav globally across the app.

## Risks / open implementation details

- **Fallback split:** confirm no route depends on `default.tpl` rendering the
  directory; verify by exercising template-less routes.
- **Nav stacking vs `#newmenu`:** confirm positioning so nav + ORK header don't
  overlap on home.
- **Block-renderer discipline:** keep partials dumb (render `fields`, no data
  fetching) so the provider stays the single content seam.
- **Image weight:** optimized assets; lazy-load below-the-fold photos.
