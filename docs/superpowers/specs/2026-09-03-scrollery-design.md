# Scrollery — award certificate generation inside the ORK

**Date:** 2026-09-03
**Surface:** New `Scrollery` controller + `revised-frontend` templates + `assets/scrolls/`
**Type:** New module (port of the Arcanyst scroll generator)
**Source material:** `arcanyst-clone/arc-scroll` (Arcanyst 2.1.3, by Valory Fox)

## Problem

Amtgard award certificates ("scrolls") are produced outside the ORK. Arcanyst is the
best current tool for it, but it is a standalone Aurelia SPA whose recipient data must
be typed by hand or pasted from a Google Sheet, and whose three Google Cloud Functions
(`/ork`, `/heraldry`, `/sheets`) are dead — ORK search and Sheets import no longer work
at all. Meanwhile the ORK already holds every fact a scroll needs: recipient, rank,
date, giver, park, kingdom, award name, heraldry, pronouns.

We have license from the original creator to continue the work. The natural home for it
is the ORK itself.

## Goal

A Scrollery module inside the ORK with three doors:

1. **Reprint my scroll** — a player prints a certificate for any award on their own record.
2. **Print a new scroll** — an officer prints one scroll while granting an award.
3. **Print bulk scrolls** — an officer prints an event's or a date range's award list.

Reusing Arcanyst's artwork, layer recipes and typography; wearing the ORK's own design
language; driven by ORK data rather than manual entry.

## Constraints

- **The Aurelia application does not come along.** Its TypeScript sources are lost and
  its 14 webpack bundles cannot be rebuilt. Viewmodel behaviour is rewritten as PHP +
  vanilla JS in ORK's idiom. Nothing from `tools/` (`sync-views.mjs`,
  `patch-bundles.mjs`, `stamp-cache.mjs`) is ported — they exist only to work around
  unbuildable bundles.
- **Output is `window.print()`**, as in Arcanyst. Certificates are composed from stacked
  `<img>` layers with CSS `mix-blend-mode`; there is no canvas and no server-side
  compositing. PHP PDF libraries have no blend modes, so a server-rendered PDF would
  mean redrawing every order. Structure the render surface so a headless-Chrome PDF
  service can be attached later without rework.
- Follow ORK conventions: `controller.X.php` → `$this->template`, `.tpl` templates,
  `revised.css`/`revised.js` design language, `filemtime()` cache busting.
- Art is committed to the fork (`baltinerdist/ork3-tobias`) under `assets/scrolls/`.
  Upstreaming to `amtgard/ork3` is a later decision, made once the module has run a
  real event.
- `ork.sql` is **not** the schema of record — it is schema-only, has zero `INSERT`s, and
  is already stale (it lacks `awards.alias_award_id` and `awards.by_whom_id`, both of
  which live code selects). `db-migrations/` is authoritative.

## Reference: how Arcanyst renders a certificate

`arc-scroll/src/award/award-render.html`:

- An `<svg viewBox="0 0 2550 3300">` (8.5x11in @ 300 DPI) holding one full-bleed
  `<foreignObject>`. All certificate content is HTML inside it; the SVG is only a
  fixed-aspect frame. **Kept as-is** — it is what makes the same markup scale to a
  thumbnail preview and to a printed page.
- `--heraldry-filter` / `--heraldry-transform` custom properties publish the user's
  heraldry brightness/contrast/scale/translate controls; only `award-heraldry` consumes
  them.
- A flat list of `<compose if.bind="award.order == '...'">`, one line per order.

Each order view is a short stack of absolutely-positioned siblings, so **document order
is z-order**. `arc-scroll/src/award/templates/ash/griffon.html` is representative: a
parchment, a border, a heraldry layer, two *identical* symbol layers, a rank numeral
positioned from a per-rank offset table, and the text layer.

**Deliberate duplication is not copy-paste damage.** `owl.html` renders `<award-heraldry>`
once for `parchment`, twice for `cyanotype`, four times for `transparency`, with
byte-identical attributes. Stacking identical `multiply`/`screen` layers deepens them,
and the transparent-ground variants do not read without the extra passes. The same holds
for griffon's doubled `soft-light` symbol. Preserve every repetition exactly.

Shared pieces: `common/award-parchment.html`, `common/award-border.html` (inline SVG
`<symbol>` pieces recoloured via `--primary-color`/`--secondary-color`, with a ~47 KB
`<g id="Griffons">` block gated behind a `griffons` bindable),
`common/award-heraldry.html`, `common/text/text.html` (the typography layer).

Two defaults live in the bundle's `Award` constructor, not in
`reference/award-definitions.json`, and both have caused shipped bugs:

- `ranks` defaults to `{from: 0, to: 10}`. Only `warrior` overrides it (`{from: 1}`).
  **Rank 0 — the generic, unranked certificate — is reachable for 18 of 19 orders**, so
  any path interpolating a rank without a guard needs a rank-0 file.
- `resources` defaults to the `squiggly-*.svg` / `writing-line.svg` flourishes; only
  `jovious` and `rose` override it, and only *partially* — the rest is inherited.

## Design

### 1. Module layout

```
orkui/controller/controller.Scrollery.php       index, mine, create, bulk, render
orkui/controller/controller.ScrolleryAjax.php   preview fetch, recipient lookup
orkui/model/model.Scrollery.php                 award rows -> scroll data
system/lib/ork3/class.Scroll.php                order resolution, title precedence, rank guards
system/lib/ork3/data/scroll-orders.php          the 19 orders (ex-award-definitions.json)

orkui/template/revised-frontend/
    Scrollery_index.tpl        landing / chooser
    Scrollery_mine.tpl         reprint my scroll
    Scrollery_create.tpl       print a new scroll
    Scrollery_bulk.tpl         print bulk scrolls
    Scrollery_render.tpl       bare print surface: N certificates, nothing else
    scroll/<order>.tpl         19 order partials
    scroll/_parchment.tpl _border.tpl _heraldry.tpl _text.tpl
    style/scrollery.css
    script/scrollery.js
    style/fonts/               BlackChancery, Averia Serif Libre

assets/scrolls/<order>/...     the ~62 MB of art (ex-resources/ash/)
```

Routes (`orkui/index.php` dispatches `Route=Controller/call/action`):

| Route | Door |
|---|---|
| `Scrollery/mine` | player's own award history, Print per row |
| `Scrollery/create/<kingdom\|park>/<id>` | officer, single scroll |
| `Scrollery/bulk/<kingdom\|park>/<id>` | officer, event or date-range list |
| `Scrollery/render` + query params | the print surface; no ORK chrome |

Three consequences of following ORK conventions:

- **No cache-stamp tooling.** ORK templates self-include assets as
  `style/revised.css?v=<?= filemtime(...) ?>` (e.g. `Eventnew_index.tpl:134`).
  `stamp-cache.mjs` existed only because webpack content hashes lie after in-place
  edits; `filemtime()` cannot lie.
- **No view-sync tooling.** A `.tpl` is the running code.
- **`Scrollery_render.tpl` is its own route, not a modal.** Arcanyst suppresses host
  chrome with `@media print { body > * { display:none } }` (`styles/page.css`), which
  fights the page it lives in. A dedicated bare route renders only certificates, so its
  print CSS is `@page { size: letter; margin: 0.125in }` plus `page-break-before:
  always` between siblings — and bulk printing is a `foreach`. It is also the seam a
  future PDF service attaches to: it fetches that one URL.

**Render parameters travel in the query string, and `render` re-runs the permission
check against them.** The render route is a view, never the authority. This keeps the
URL shareable, keeps "open in a new tab and print" working, and leaves the future PDF
service able to fetch a URL.

### 2. Order partials

PHP `include` shares scope, which would let `_border.tpl` silently inherit a stray
`$primary`. One helper gives partials real parameters:

```php
function scroll_partial($name, array $vars = []) {
    extract($vars);   // isolated function scope
    include DIR_TEMPLATE . "revised-frontend/scroll/_{$name}.tpl";
}
```

`scroll/griffon.tpl` then transliterates `griffon.html` almost line for line:

```php
<div class="award-template">
  <?php scroll_partial('parchment'); ?>
  <?php scroll_partial('border', ['primary' => $s['primary'], 'secondary' => $s['secondary'], 'griffons' => true]); ?>
  <?php scroll_partial('heraldry', ['instance' => $s]); ?>
  <div class="centre-bottom"><img src="<?= $art ?>/griffon/symbol_<?= $s['rank'] ?: 5 ?>.png" style="mix-blend-mode:soft-light"></div>
  <div class="centre-bottom"><img src="<?= $art ?>/griffon/symbol_<?= $s['rank'] ?: 5 ?>.png" style="mix-blend-mode:soft-light"></div>
  <?php if ($s['rank'] > 0): $offsets = [85,85,85,85,85,440,440,440,235,185]; ?>
    <div class="centre-bottom" style="bottom:<?= $offsets[$s['rank']-1] ?>px"><img src="<?= $art ?>/common/rank_<?= $s['rank'] ?>.png"></div>
  <?php endif; ?>
  <?php scroll_partial('text', ['s' => $s]); ?>
</div>
```

`warrior.tpl` keeps its per-rank `<style>` and its rank-10 `bg_10.jpg` parchment swap.
`owl.tpl` keeps its 1x/2x/4x heraldry stacking and 1x/2x/3x border repeats per variant.
`griffon.tpl` keeps the `[href="#Border-Deco-Special-Griffon"]` CSS override.

`scroll-orders.php` records the two previously-implicit defaults explicitly, and merges
partial `resources` overrides over the default set rather than replacing it.

### 3. Scroll data contract

One associative array built by `model.Scrollery` and read by every partial. It is
Arcanyst's `award` + `Instance` merged, with most fields now sourced from the record:

| Field | Source |
|---|---|
| `title` | resolved award name (see §4) |
| `order`, `primary`, `secondary`, `resources`, `flavour` | `scroll-orders.php`, keyed by base `award_id` |
| `player` | recipient persona (`Player::GetPlayer`) |
| `rank`, `reason`, `date` | `ork_awards` row (`rank`, `note`, `date`) |
| `pronoun` | `class.Pronoun.php` |
| `givenBy` | `awards.given_by_id` -> persona |
| `issuerTitle` / `issuerName` | park or kingdom on the award row |
| `heraldry` | `Heraldry::resolve_heraldry_url()` (`class.Heraldry.php:40`) |

`pronoun` is an outright improvement over Arcanyst, where it was typed per recipient:
the text layer's *"with their third"* becomes *"with her third"* automatically.

Heraldry is a **local file** here. That deletes three Arcanyst problems at once: no
cross-origin fetch, no `.png`-then-`.jpg` retry (`ork-heraldry-fallback.js`), and no
possibility of the latent `HeraldryOverrides` bug where id 17 is both Kingdom Crystal
Groves and Park Drakenwood.

### 4. Award and title resolution

**The reprint query already exists.** `Player::AwardsForPlayer()`
(`system/lib/ork3/class.Player.php:448`) joins `awards -> kingdomaward -> award -> alias
award -> park -> kingdom -> event -> mundane` and accepts an `AwardsId` for a single
row. It returns `Rank`, `Date`, `Note`, `GivenBy`, `ParkName`, `KingdomName`,
`KingdomAwardName`, `CustomAwardName`, `AliasAwardName` — nearly the whole contract, and
the same call the Awards tab already makes. `model.Scrollery` **reuses it** rather than
writing a parallel query that can drift.

Title precedence, in order:

```
KingdomAwardName -> Name -> AliasAwardName -> CustomAwardName
```

*Revised 2026-09-03, during Task 3, against real award data.* The original order led
with `CustomAwardName`, on the reasoning that a hand-typed name is the most specific.
The data says otherwise. `awards.custom_name` is not a title field: on ladder awards it
is overwhelmingly a short tag typed beside the real name — 19,342 rows reading `Rose`
whose kingdomaward is `Order of the Rose`, 13,477 `Warrior`, 6,084 `Gryphon`, 89,849 of
343,091 ladder awards in all. The certificate prints the title on its own line between
the recipient's rank and the reason, so leading with `custom_name` would set a quarter
of every ladder scroll to read *"with her third Rose for"*. The kingdom's own name for
the award is the field that actually holds a whole order name, so it leads; custom and
alias names remain last so a row missing both real names still prints something.

**Per-kingdom name variants are a later phase.** Every award is designed as "Order of
the X" and prints that way in phase 1.

Arcanyst had no such concept: it computed `"Order of the " + capitalize(orderId)`, which
is precisely why one archery award had to become four order ids (`hunter`, `bowyer`,
`marksman`, `sharpshooter`) sharing a fabricated `ork_id 6969` and gated on kingdom-id
lists. **In the ORK the word after "Order of the" is data.** All four collapse into one
`hunter.tpl` whose title comes from `ork_kingdomaward.name`.

`ork_kingdomaward`'s unique key is `(kingdom_id, award_id, name)`, so a kingdom may hold
more than one name for a base award. This needs no special control:

- **Reprint** carries `kingdomaward_id` on the award row — unambiguous.
- **Create / bulk** go through the award picker the ORK already has, which lists
  kingdomaward rows by name.

*Fallback, if real kingdom data proves messier:* an archery-specific name picker on the
create flow. Not built in phase 1.

Art is keyed by **base `award_id`**, matching Arcanyst's `ork_id`. The 19 orders resolve
to 16 distinct ids, which are exactly the 14 ladders in `Award::GetLadderMasterMap()`
(`class.Award.php:20`) plus Flame (34) plus the archery award.

### 5. Permissions

Reusing the existing checks; no new concepts.

| Route | Check |
|---|---|
| `mine` | `session->user_id === awards.mundane_id` |
| `create`, `bulk` | `HasAuthority($uid, AUTH_PARK\|AUTH_KINGDOM, $id, AUTH_EDIT)` — the same gate `Controller_Award` uses to grant awards |
| `render` | re-runs the above against the URL parameters |

### 6. Live preview

*(2026-10-27: the heraldry sliders and their `--heraldry-*` properties were removed; heraldry is sized dynamically.)*
Heraldry sliders and the issuer / date / given-by fields write CSS custom properties and
text content directly in `scrollery.js`, exactly as `--heraldry-filter` and
`--heraldry-transform` already work — no round trip on a slider drag. Only order, rank or
variant changes re-fetch, via `ScrolleryAjax`. With no Aurelia there is no shadow root,
so debugging is a plain `document.querySelector` again.

### 7. Fonts

BlackChancery already ships as `.woff2` and is vendored as-is. Averia Serif Libre
currently arrives through a Google Fonts `@import` in `arc-scroll/styles/theme.css`; it
is **self-hosted** here. A print path that silently falls back to a system serif when
Google is slow or blocked is a bad failure mode, and ORK today ships no webfonts at all.
Source Serif Pro and Megrim are Arcanyst chrome only and are dropped — ORK's own type
stack replaces them.

## Coverage

Ladder coverage is complete: all 14 ladder awards have art. **Everything else in the
ORK's catalogue does not** — every Master award (1-12, 240, 244), every title, every
non-ladder award, and every kingdom-specific award.

Phase 1 shows a Print control **only where art exists**, and shows nothing elsewhere. No
dead buttons. A generic certificate covering the rest is a later phase.

**Zodiac is clamped to rank 10.** `GetLadderMasterMap()` gives award 30 a `MaxRank` of
12, but Arcanyst's zodiac has `flavour` keyed `"0"`-`"10"` and no art above 10. Zodiac
also uses a `<<MONTH>>` placeholder that wants rethinking. Both are deferred to a
separate branch; phase 1 suppresses the control above rank 10.

## Testing

**The test that matters is the asset-path expansion.** `arc-scroll/test/asset-paths.test.mjs`
walks every `src` in every view across each order's real rank range and variant list,
honouring the `if.bind` guards on the element and every ancestor, and asserts the file
exists — ~870 concrete paths across 19 orders. It is the only thing standing between a
release and a certificate that renders a broken image at exactly one order and rank,
which is invisible until someone prints it. `flame` and `zodiac` both shipped 2.1.3 with
a missing rank-0 file.

The PHP equivalent walks `scroll-orders.php`, renders each order partial across its
rank/variant matrix, extracts every `src`, and stats the file. Plus two small unit tests:
title precedence, and rank guards (rank 0 reachable for 18 of 19; warrior starts at 1;
zodiac clamps at 10).

**No test catches fidelity.** A dropped duplicate layer or a wrong blend mode renders
happily and looks subtly flat. Every order is compared by eye against Arcanyst's output
before it is considered done.

## Phasing

| Phase | Content |
|---|---|
| 0 | Import: art to `assets/scrolls/`, fonts vendored, `NOTICE` entry. Verified by file count. |
| 1 | One order end to end — controller, model, `scroll_partial()`, four shared partials, `griffon.tpl`, render route, print CSS. Printed and compared. |
| 2 | The other 18 orders + the asset-path test. `warrior` and `owl` last. |
| 3 | The three doors in ORK chrome: `mine`, `create`, `bulk`. |
| 4 | Live overrides in `scrollery.js`. |
| later | Per-kingdom theming; generic certificate for uncovered awards; zodiac month redesign; headless-Chrome PDF service. |

Phase 1 is deliberately one order, because griffon alone exercises the border override,
the rank-offset table and the intentional duplicate layer — it retires most of the port's
risk before eighteen more files exist to fix.

## Attribution

The artwork, layer designs and flavour text are Valory Fox's original work, with flavour
text credited in-app to Kisa (Shelly Alexandra). Phase 0 adds a `NOTICE` entry recording
this, and the module carries a visible credit. This is a prerequisite for any later
upstreaming to `amtgard/ork3`.

## Open items

1. **The archery award's real base `award_id`.** `6969` is Arcanyst's invention. Resolving
   it needs the redacted database; `ork.sql` carries no data. Blocks `hunter.tpl` only.
2. **The test database must be running even for unit tests.** `tests/bootstrap.php`
   loads the full ORK runtime through `startup.php`, which constructs a DB connection
   unconditionally, so `vendor/bin/phpunit --testsuite unit` fails with
   `PDOException ... Connection refused` unless `docker-compose -f docker-compose.php8.yml up -d`
   has brought up `ork3testdb` on port 19307.
3. **`assets/scrolls/` and `.gitignore`.** The ignore rules name `heraldry`, `players`,
   `tmp`, `waivers` and `backups` specifically, so `assets/scrolls/` is tracked by
   default. Confirm at import time.

## Out of scope

- Rebuilding or replacing Arcanyst's dead Cloud Functions (`/ork`, `/heraldry`, `/sheets`).
- Google Sheets import — the ORK is the data source.
- Server-side PDF generation.
- Zodiac's month handling and ranks 11-12.
- Per-kingdom theming and the generic certificate (later phases, recorded above).
