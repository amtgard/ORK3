# Amtgard Front Door — Design Spec

**Date:** 2026-06-22
**Branch:** `feature/front-door`
**Status:** Approved design → ready for implementation plan

## Goal

Replace the ORK's utilitarian landing page with a rich, themed, photo-forward
**"front door" for Amtgard** — a cinematic, newcomer-first marketing landing that
also serves logged-in members. The existing landing (a Kingdoms Directory) is
preserved verbatim and relocated to its own route.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Audience model | **Adaptive single page.** One page for everyone; logged-in members get a slim personalized strip; the bulk of the page is newcomer/marketing content. |
| Primary job | **Recruit-leaning, serves both.** Lead with the epic recruiting hook; surface live/public content below; member tooling trimmed to a slim bar. |
| Visual direction | **Epic Cinematic.** Deep navy (`#0b1120`), gold accent (`#f0b429`), `MedievalSharp` display type over crisp system-sans body. Full-bleed photography, dramatic gradients. |
| Photography | **Photo-rich.** Auto-rotating hero carousel, large section imagery, a photo mosaic. Uses the 8 user-supplied event photos + the Amtgard logo. |
| Marketing nav | **Front-door-only.** A placeholder nav mirroring amtgard.com renders only on the landing page; all other pages keep the standard ORK header untouched. |
| Education content | **Placeholder shells now.** Sections + links exist with placeholder copy; real education pages/copy are a later feature. |
| Kingdoms Directory | **Moves to a new `/Directory` controller** (`Directory/index`), content carried over unchanged. |

## Architecture

### Routing & templates

ORK resolves the home route (empty `Route`) to the base `Controller::index()`,
which renders `template/default/default.tpl` (the current fallback). Today that
fallback file *is* the landing page. We split this cleanly:

1. **Front door** → new `template/default/Controller_index.tpl`.
   The View resolves `Controller_index.tpl` (controller `Controller`, request
   `index`) *before* falling through to `default.tpl`, so the home route renders
   the new front door without touching the shared fallback.
2. **Kingdoms Directory** → new `controller.Directory.php` (`Controller_Directory`)
   + `template/default/Directory_index.tpl`. The directory markup currently in
   `default.tpl` moves here verbatim (welcome title relabeled to "Kingdoms
   Directory"; the "Your Kingdom" pinning logic comes along).
3. **`default.tpl`** is reduced to a minimal, neutral generic fallback (no longer
   doubles as the home page). This removes the existing smell where any
   template-less route would render the full Kingdoms Directory.

`index.php` already redirects legacy index routes; no router change is required.
A nav/link to `Directory/index` is added wherever "Kingdoms" is surfaced
(front-door "Browse the full Kingdoms Directory →", and the ORK menu/search as
appropriate).

### Data (all already loaded by `Controller::index()`)

The front door needs only data the base controller already provides — **no new
DB/library work**, honoring the data-usefulness rule:

- **Stat ticker** — kingdom count, park total, players/week from `ActiveKingdomSummary`; events-this-month from `EventSummary`/search.
- **Upcoming Events** — `EventSummary` (already includes RSVP counts).
- **Week in Review** — `week_recap` (Recap model).
- **Recent Tournaments** — `Tournaments` (`Report::TournamentReport`).
- **Kingdoms teaser** — a small slice of `ActiveKingdomSummary` (heraldry + name), linking to `/Directory`.
- **Member strip / tools gating** — `LoggedIn`, `UserKingdomId`/`UserParentKingdomId`, viewer identity.

The `Directory` controller's `index()` replicates the data loads the directory
view needs (`ActiveKingdomSummary`, home-kingdom pinning, etc.).

### Marketing nav placement

The marketing nav is rendered as the first element of the front-door content
(`Controller_index.tpl`). Because it is part of the front-door template only, it
never appears on other routes. A `fd-home` flag/body class is set on the home
route so the master `default.theme` can adjust stacking if needed (e.g. offset
or restyle the standard `#newmenu` on home only). Implementation confirms the
exact stacking against `#newmenu`'s positioning; default behavior keeps the
standard ORK header present beneath the marketing nav.

Nav structure (placeholder; links to `#`/TBD, external items to amtgard.com):
`Home · About`(Mission, Staff, Volunteers) `· Join`(Learn the Basics, Find a
Chapter, Start a Chapter) `· AI Programs`(Food Fight, Olympiad) `· Media`
(Galleries, Writing) `· Official Resources`(Documents) `· Merch`(Redbubble).
ORK-specific right side: a "Record Keeper" link + "Find a Chapter" CTA.

### Assets

- Copy the 8 event photos and the logo into a committed, optimized location:
  `orkui/template/default/img/frontdoor/` (e.g. `hero-1.jpg … hero-8.jpg`,
  `amtgard-logo.avif` + `.png` fallback). Resize/compress photos for web
  (target ≤ ~250 KB each, ~1600px wide). Source files: `~/Downloads/amt-img-*.jpg`,
  `~/Downloads/amtgardlogo.avif`.
- **Assumption:** user-supplied imagery is cleared for use on amtgard.com/ORK.

### Front-door CSS/JS

- All styles scoped with an `fd-` prefix to avoid collisions; lives in the
  template or a dedicated `template/default/css/frontdoor.css` + `js/frontdoor.js`.
- Carousel: vanilla JS, auto-advance (~4.5s), clickable dots, pause-on-interaction;
  no new library dependency.

## Page structure (top → bottom)

0. **Marketing nav** (front-door-only; logo + amtgard.com menu placeholder).
1. **Slim member bar** — *logged-in only.* "Welcome back, {name}" + Your Park /
   Live Attendance / Member Tools. Hidden for anonymous.
2. **Hero carousel** — full-bleed rotating photos, gold `MedievalSharp` headline
   per slide, "Find Amtgard Near You" + "Watch & Learn" CTAs, logo top-left,
   live **stat ticker** pinned to the base.
3. **What is Amtgard?** — official one-paragraph description, centered, prominent.
4. **Find Your Path** — "catch your interest" grid of photo cards (Warrior,
   Archer, Caster, Artisan, Monster, Leader). *Placeholder* — each links to a
   TBD education page; final class set/names TBD.
5. **Your First Day** — dark band, 3 friendly getting-started steps + CTA.
   *Placeholder copy.*
6. **Upcoming Events** — event cards from `EventSummary`.
7. **The Look of Amtgard** — large photo mosaic.
8. **Kingdoms** — teaser strip (heraldry + names) → "Browse the full Kingdoms
   Directory →" (`Directory/index`).
9. **Get Involved** — closing CTA band with logo + links out to amtgard.com /
   play.amtgard.com / Record Keeper.

Anonymous vs logged-in differences: member bar hidden when anonymous; member
tools/links shown only when logged-in; all marketing/education/public content
identical.

## Cross-cutting requirements

- **Dark mode (required).** Front door is navy-dominant, but light sections
  (`#fff`, `#f7f8fb`, event/kingdom cards, "What is Amtgard") must have explicit
  `html[data-theme="dark"]` treatments. Walk every surface in dark mode before
  done (per project dark-mode checklist).
- **Responsive/mobile.** Carousel, multi-column grids (paths, events, mosaic,
  kingdoms) collapse gracefully; nav becomes a mobile menu; member bar wraps.
- **Heading reset.** Any heading inside hero/cards/bands must reset the global
  `orkui.css` h1–h6 gray-box styling (`background:transparent;border:none;...`).
- **No native tooltips / dialogs.** Use existing in-product patterns if any
  tooltips/confirms are needed.
- **PSR-12 / normalize-first** for any edited PHP; `.tpl` files are plain PHP
  (`<?php ?>`/`<?= ?>`), not Smarty.
- **`.tpl` template engine** is `extract()`+`include` PHP — no Smarty syntax.

## Out of scope (future features)

- Real education content + dedicated path/basics sub-pages.
- Functional amtgard.com nav destinations (placeholder links for now).
- Any new reporting/DB aggregation (front door reuses existing data only).
- Adopting the marketing nav globally across the app.

## Risks / open implementation details

- **Fallback split:** confirm no route depends on `default.tpl` rendering the
  directory; the move to `Controller_index.tpl` + minimal `default.tpl` should be
  verified by exercising a few template-less routes.
- **Nav stacking vs `#newmenu`:** confirm positioning so the marketing nav and
  ORK header don't overlap on home.
- **Image weight:** ensure optimized assets; lazy-load below-the-fold photos.
