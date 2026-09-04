# Scrollery — deferred work and known gaps

Everything the Scrollery port deliberately did **not** do, plus everything found along
the way that someone will want back. Started 2026-09-03 during the initial build; add to
it rather than letting these scatter across commit messages.

Related: `docs/superpowers/specs/2026-09-03-scrollery-design.md` (the spec),
`docs/superpowers/plans/2026-09-03-scrollery.md` (the build plan).

---

## 0. Triage — decided 2026-09-04

Every open item was put to the maintainer with options and a recommendation. Outcomes:

| # | Item | Decision |
|---|---|---|
| 3.13 | Navigation | **BUILD** — player link from the Awards tab, officer links from the park/kingdom Admin panel |
| 3.1 | Own-profile print | **BUILD** — a link above the Awards table, not a widened actions cell |
| 3.11 | Grant + print | **BUILD** — offer Print from the award-grant success path, so it prints the real row |
| 3.12 | Bulk event picker | **BUILD** — reuse the ORK's existing event autocomplete |
| 1.1 | Archery | **BUILD** — name-keyed mapping from `ork_kingdomaward.name` to the hunter order |
| 1.2 | hunter never eyeballed | **BUILD** — render against real data and check against lion/hydra (Arcanyst cannot show it) |
| 2.3 | Generic certificate | **BUILD** — one generic design from the existing shared layers, covering the ~136 uncovered awards |
| 3.6 | Pronouns | **BUILD** — interpret from the profile with sensible defaults, plus an override picker in the create tool |
| 3.9 | WnAjax malformed JSON | **BUILD** — add the missing `exit;` calls (not our bug, but 3 lines) |
| 3.7 | `UIR` in a model | **BUILD** — move `PrintUrl` construction to the template |
| 3.8 | woff2 MIME | **BUILD** — add the nginx `types` entry |
| 2.1 | Zodiac month, ranks 11-12 | **DEFERRED PERMANENTLY** — a dedicated branch will turn Zodiac into a real month feature; do not solve it here |
| 2.5 | Server-side PDF | **PARKED** — the bare render route is already the seam; revisit on a concrete need |
| 3.5 | Unreferenced art | **KEEP** — 2.6 MB of original artwork, possibly alternates; the note stops re-litigation |
| 2.2 | Per-kingdom name variants | **CLOSED** — `ork_kingdomaward.name` is already per-kingdom and already drives the title |
| 2.4 | Per-kingdom theming | **CLOSED** — 0 of 36 `ork_kingdom_design` rows carry a colour, and a brand palette would fight artwork designed around per-order colours |

Items marked CLOSED are kept below for the reasoning, not as work.

---

## 1. Blocked on data — needs a decision or a lookup nobody has yet

### 1.1 Archery has no base award to key art to

> **DECIDED 2026-09-04 — BUILD.** Name-keyed mapping: match `ork_kingdomaward.name`
> against a curated archery name list and resolve those rows to the hunter order. The
> printed title still comes from the kingdom's own name, so all four spellings render
> correctly. A kingdom inventing a new spelling gets no art — the same safe failure as
> today.

`hunter` keeps Arcanyst's fabricated `ork_id` **6969** in
`system/lib/ork3/data/scroll-orders.php`. It is not an ORK award id, so
`Scroll::OrderForAwardId()` never matches it and **no archery award can be printed** —
the safe failure, not the desired one.

The spec assumed this was a lookup waiting to happen. It is not. Archery is not one base
ladder award; queried against real data, archery grants hang off **three** different base
ids under a dozen kingdomaward names:

| base `award_id` | base name | kingdomaward names seen |
|---|---|---|
| `0` | *(none)* | Order of the Hunter, Order of the Archer, Order of the Marksman, Master Hunter, Hunter |
| `94` | Custom Award | Order of the Hunter, Order of the Marksman, Order of the Sharpshooter, Order of the Archer |
| `131` | *(none)* | Master Archer |

So the fix is a **kingdomaward-name-keyed mapping**, not an id lookup — and it needs a
decision about which of those names should print an archery certificate at all.
`ScrollResolutionTest::testRealArcheryAwardsResolveToNoOrder` pins the current state and
fails the day one of those ids is given art.

**Blocks:** archery certificates entirely. `hunter.tpl` exists, renders, and is
asset-tested; it just cannot be reached.

### 1.2 hunter is the one order never visually compared

> **DECIDED 2026-09-04 — BUILD.** Render hunter across its rank range against a real
> archery award and check it against lion and hydra, which share its exact layer shape
> and both matched. Arcanyst genuinely cannot display it, so no side-by-side is possible.

Every other order was compared side by side against Arcanyst 2.1.3. `hunter` could not
be: Arcanyst gates the archery orders behind a kingdom-id list, and its kingdom-loading
Cloud Function is dead, so the order never appears in its sidebar. It shares its layer
shape with `lion` and `hydra`, both of which matched. Worth an eyeball if the archery
mapping above ever lands.

---

## 2. Deliberately out of scope for phase 1 (recorded in the spec)

### 2.1 Zodiac's month, and ranks 11–12

> **DECIDED 2026-09-04 — DEFERRED PERMANENTLY.** A future branch will turn Zodiac into
> a real month feature. Do NOT solve it here, and specifically do not derive the month
> from the award date as a stopgap — that branch owns the whole question, ranks 11-12
> included. `ExpandFlavour()` already accepts `$context['month']` when it lands.

`Scroll::ExpandFlavour()` understands `<<prefix|MONTH|suffix|else>>` and currently always
takes the **else** branch, which is what those branches were written for ("for whole
months", "You're a", "the"). The month side is implemented and unused: pass
`$context['month']` and it renders `prefix . month . suffix`. What is missing is any
notion of *which* month an award belongs to, and a UI for it.

Separately, `Award::GetLadderMasterMap()` gives award 30 a `MaxRank` of **12**, but zodiac
art and flavour stop at 10. `Scroll::ClampRank()` clamps to 10.
`ScrollResolutionTest::testZodiacClampsAtTenDespiteTheCatalogueAllowingTwelve` pins it.

### 2.2 Per-kingdom name variants on the printed title

> **DECIDED 2026-09-04 — CLOSED.** Already solved: the certificate prints
> `ork_kingdomaward.name`, which is per-kingdom, and that is exactly why all four archery
> spellings work. A separate certificate-only name would be a second title to keep in
> sync, and nobody has asked for one.

`Scroll::ResolveTitle()` prints `ork_kingdomaward.name`, which is "Order of the X"
throughout. A kingdom that wants its own wording on the certificate has no way to say so.
Decided 2026-09-03: every award is designed as "Order of the X" and prints that way in
phase 1.

### 2.3 A generic certificate for everything with no art

> **DECIDED 2026-09-04 — BUILD.** One generic certificate composed from the existing
> shared layers — parchment, border, heraldry, text — with no order symbol and no rank
> numeral. Every piece already exists and is tested; title, recipient, date and issuer
> come from the record exactly as they do now. Turns ~136 unprintable awards printable.

Ladder coverage is complete (14 ladders + Flame). **Nothing else in the catalogue has
art**: every Master (1–12, 240, 244), every title, every non-ladder and every
kingdom-specific award. Phase 1 shows a Print control only where art exists and nothing
elsewhere — no dead buttons. A generic certificate covering the rest is a later phase.

### 2.4 Per-kingdom theming

> **DECIDED 2026-09-04 — CLOSED.** `ork_kingdom_design` has `color_primary`,
> `color_secondary` and `color_accent`, but 0 of 36 rows populate any of them — there is
> nothing to theme from. The per-order colours are also deliberate artistic choices; a
> kingdom palette would override artwork that was not designed for it.

`--primary-color` / `--secondary-color` are per ORDER (from `scroll-orders.php`), not per
kingdom. Driving them from kingdom colours is a later phase.

### 2.5 Server-side PDF

> **DECIDED 2026-09-04 — PARKED.** `Scrollery_render.tpl` is already the seam. Revisit
> on a concrete need (emailing, archiving, or a device with a poor print dialog).

Output is `window.print()`. `Scrollery_render.tpl` is deliberately a bare route with no
chrome so a headless-Chrome PDF service can attach to it later by fetching one URL. PHP
PDF libraries have no blend modes, so a server-rendered PDF would mean redrawing every
order.

---

## 3. Smaller gaps found during the build

### 3.1 The Awards-tab Print button is officer-only

> **DECIDED 2026-09-04 — BUILD, but not by widening the cell.** Add a link above the
> Awards table pointing at `Scrollery/mine`, leaving `pn-award-actions-cell` officer-only.
> Avoids restructuring the column header and colspan of a 7,750-line shared template.

In `Playernew_index.tpl` the Print button sits inside the `pn-award-actions-cell`, which
only renders under `$canManageAwards`. A player viewing their own profile therefore does
not get an inline Print; they use `Scrollery/mine`. Widening the cell to profile owners
means restructuring the table header and colspan, which was beyond Task 15's scope.

Note also that only the **awards** table takes the button. The titles table never has art,
and the third location the plan named (~7428) is the attendance table.

### 3.2 `alias_award_id` is effectively dead weight in title resolution

`ResolveTitle()` keeps `AliasAwardName` in its chain, but there are **11 alias rows in
391,295 awards**, and every one is on kingdomaward base 246 ("Custom Title"), which has no
art. It can never influence a printed certificate today. Harmless where it sits; do not
mistake it for load-bearing.

### 3.3 Dragon emits duplicate SVG element ids

`dragon.tpl` draws `_border.tpl` twice (the second as a blurred colour-dodge glow), so a
dragon certificate contains **two copies of all 32 `id="Border-*"` symbols and defs**.
`<use href="#Border-A">` still resolves because the geometry is byte-identical, and
Arcanyst had the same duplication. It becomes a hazard only if something starts querying
those ids, or if a bulk page composes dragon beside another bordered order and someone
depends on id uniqueness.

### 3.4 Two wrapper divs are guarded where the source guards only the image

In `battle.tpl`, the arcane glow layer and the martial `over_` layer guard the whole
`<div class="centre-bottom">`, where the source leaves the wrapper unconditional and
guards only the inner `<img>`. Verified inert — `.centre-bottom` paints nothing on its
own, and no CSS in `scrollery.css` uses `nth-child`, adjacent or general-sibling
selectors on `.award-template` children — but it does mean the rendered child count
differs from the source's at those ranks.

### 3.5 Unreferenced art shipped

> **DECIDED 2026-09-04 — KEEP.** 2.6 MB against 62 MB of certificate art is noise, and
> these are original works that may be alternate treatments. This note is what stops the
> question being re-litigated.

`assets/scrolls/rose/burn.jpg` and `assets/scrolls/smith/bg.jpg` are committed but no
layer references them — rose uses `burn.svg`, and smith keeps the shared parchment. They
may be leftovers from an earlier Arcanyst revision. Left in place rather than deleted;
confirm before removing.

### 3.6 Pronouns will need revisiting when free-text lands

> **DECIDED 2026-09-04 — BUILD NOW, do not wait for the other branch.** Interpret the
> possessive from whatever the profile offers today, apply sensible defaults, and add an
> override picker to the Scrollery create tool so an officer can correct what was
> inferred. The free-text trap still stands: `he/him` must resolve to **his**, never
> `him` — match the leading token against `ork_pronoun.subject`, longest-first.

`Model_Scrollery::possessive_pronoun()` reads `pronoun_id` (→ `ork_pronoun.possessive`),
then falls back to the custom-pronoun JSON's `"p"` set, then to `"their"`.

`ork_mundane.pronoun_freetext` **exists in the shared dev database but no PHP on this
branch reads it** — that is another branch's work arriving through the shared DB. When it
merges, this needs revisiting, and *not* naively: free text is `subject/object`
("he/him", "she/her", "they/them"), and the certificate needs the **possessive**. Parsing
"he/him" for a possessive yields "him" — the certificate would read "with him third".
Matching the leading token against `ork_pronoun.subject` (longest first, so "e/ey" and
"ze/zie" work) is the safe route.

### 3.7 `UIR` is a front-controller constant used from a model

> **DECIDED 2026-09-04 — BUILD.** Move `PrintUrl` construction out of the model and into
> the template; the model returns the awards id. A URL is presentation, and it keeps CLI
> harnesses from needing a `UIR` shim.

`Model_Scrollery::printable_awards_for_player()` builds `PrintUrl` with `UIR`, which
`orkui/index.php` defines — not config. It is fine in the web path and there is precedent
(`model.AmtgardIdp.php`), but any CLI harness touching this model has to define `UIR`
itself first. Arguably the URL belongs in the template.

### 3.8 woff2 is served as `application/octet-stream`

> **DECIDED 2026-09-04 — BUILD.** Add the nginx `types` entry so woff2 serves as
> `font/woff2`, and cache/compression rules can be reasoned about for the five font files
> every certificate loads.

nginx has no woff2 MIME mapping in this stack. Browsers accept it, but a `types` entry
would be tidier and lets compression/caching headers be set sensibly.

### 3.9 `Controller_WnAjax` echoes JSON and returns instead of exiting

> **DECIDED 2026-09-04 — BUILD.** Add the missing `exit;` after each of its three
> `echo json_encode` calls, matching the convention every other Ajax controller follows.

Not Scrollery's, but found while building `ScrolleryAjax`: the ORK's Ajax controllers
`exit` after `echo json_encode(...)` (PlayerAjax 73 times, KingdomAjax 60) because
returning lets `index.php` go on to call `$C->view()`, which appends the entire themed
page after the JSON. `Controller_WnAjax` returns. Its callers only read `Status`, so a
lenient parser has been hiding it, but the response is malformed JSON.

### 3.10 `HasAuthority()` returns NULL on denial, not false

`Ork3::$Lib->authorization->HasAuthority()` is untyped legacy and returns **null** when
it denies. Passing it straight through a `: bool` return type is a fatal TypeError — cast
it. Cost one 500 during Task 16.

### 3.11 The create flow prints a scroll but does not grant the award

> **DECIDED 2026-09-04 — BUILD.** Offer a Print link from the award-grant success path
> in `Controller_Award`, so the certificate prints the row that was actually written.
> `Scrollery/create` does NOT gain the ability to grant — that would fork the grant
> validation, duplicate detection and ladder-rank rules.

The plan describes Task 16 as "print one scroll while granting an award", but
`Scrollery/create` only prints. Granting lives in `Controller_Award`, and wiring the two
together means touching the award-creation path, which was beyond the task. An officer
today grants the award as usual and then prints — either from the create flow by hand, or
from the Awards-tab button once the row exists.

### 3.12 Bulk selects by event id typed in by hand

> **DECIDED 2026-09-04 — BUILD.** Reuse the ORK's existing event autocomplete, scoped to
> the park/kingdom. Server-side scoping is already enforced by the bulk query's scope
> predicate.

`Scrollery/bulk` takes an event **id** in a number field. There is no event picker, so an
officer has to know the id (or come from a link that carries it). The date-range path
needs no such knowledge and is the usable one today.

### 3.12b Bulk scoping leaves three small populations unreachable

Closing the cross-tenant leak (see §4) moved the bulk event filter onto the AWARD row's
own `at_park_id` / `at_kingdom_id` rather than the event's. That is correct and
fail-closed, but it leaves three groups nobody can list through bulk:

- **336 award rows** have `at_park_id > 0` but no `at_kingdom_id`, so a *kingdom* officer
  no longer sees them on the event path. The park officer still does. This matches how
  the pre-existing non-event kingdom branch already behaved.
- **135 event-attached rows** carry neither `at_park_id` nor `at_kingdom_id` and are now
  unreachable through bulk by anyone. That is unattributable data, not a scoping bug.
- **One event-day exceeds the 200 cap on its own**: event 12250, kingdom 4, 2020-03-07 =
  391 awards. Since the date filter's finest grain is a whole day, rows 201-391 there
  cannot be reached by narrowing. The banner now says so rather than promising a remedy
  that does not work, but paging or a park filter would actually fix it.

### 3.13 Scrollery is not linked from any navigation — TOP OF THE LIST

> **DECIDED 2026-09-04 — BUILD, contextual placement.** Player link to `Scrollery/mine`
> from the Awards tab; officer links to `create`/`bulk` from the park/kingdom Admin
> panel, which already holds the scope id and already computes AUTH_EDIT. No global
> top-nav entry.

**This is the one gap that makes everything else moot: nothing in the ORK links to the
module.** `Scrollery`, `Scrollery/mine`, `Scrollery/create/...` and `Scrollery/bulk/...`
are reachable only by typing a URL. The single in-app entry point is the per-row Print
button on a player's Awards tab, which is officer-only (see 3.1), so a player has no way
to reach their own scrolls at all.

What it needs:

- A player-facing entry to `Scrollery/mine` — the Awards tab header or the player profile
  is the natural home, since that is where someone is when they think "I want that
  printed".
- An officer-facing entry to `Scrollery/create/<scope>` and `Scrollery/bulk/<scope>` —
  the Admin panel for a park or kingdom, which already has the scope in hand.
- The nav is built in `system/lib/system/class.Controller.php` (`$this->data['menu']`,
  which already computes park/kingdom admin entries against AUTH_EDIT), so the officer
  gating is a check that already exists rather than a new one.

---

## 4. Fixed along the way — recorded so they are not "rediscovered"

### Cross-tenant disclosure in bulk print (found by the polish panel, 2026-09-03)

`Scrollery/bulk` authorised the viewer against the URL's own park/kingdom, then — when
`?event=N` was present — ran `awards.at_event_id = N` with no scope predicate at all. Any
officer could enumerate any event's full award list system-wide: recipient personas,
award names, ranks, dates, given-by. The render route still re-checked per award, so
nothing could be *printed*, but the listing itself leaked. Three review lenses flagged it
independently, and the page's own copy ("searches this park only") proved it unintended.

The first fix gated on the EVENT's `kingdom_id`/`park_id` and was rejected by the critic
on real data: **974 awards sit at an event belonging to a different kingdom**, and event
8462 alone spans **3 kingdoms / 747 awards**. That gate was simultaneously too strict
(blocking a body's own awards granted at someone else's event) and still leaky (returning
other bodies' rows at a shared event). The award row carries its own scope; the event does
not. The predicate now filters on `awards.at_park_id` / `awards.at_kingdom_id`.

Verified after the fix: a kingdom-4 officer querying event 8462 gets 741 rows across
exactly **1** distinct `at_kingdom_id`; kingdom 1's 5 rows and kingdom 31's 1 row are
gone. `ScrolleryBulkQueryTest` now carries the regression test.

- **Title precedence.** `custom_name` is not a title field: on ladder awards it is
  overwhelmingly a bare tag ("Rose" against a kingdomaward of "Order of the Rose"),
  89,849 of 343,091. Precedence is `KingdomAwardName → Name → AliasAwardName →
  CustomAwardName`.
- **Kingdom issuer wording.** Kingdom names already carry their own article and rank word
  ("The Kingdom of the Wetlands"), so `useThe`/`useOf`/`issuerTitle` are derived from
  whether the issuer is a park. 47% of ladder awards are kingdom-issued.
- **Park rank word.** `ork_park.parktitle_id → ork_parktitle.title` is real data; "Barony"
  fits fewer than a sixth of parks (349 Freeholds, 274 Grand Duchies).
- **owl's default variant** reordered to `parchment`; Arcanyst's data led with
  `cyanotype`, which is a dark blue plate with white text.
- **`GetHeraldryUrl()` never returns `''`** — it falls through to a `.jpg` URL whether or
  not the file exists, so `has_heraldry` is what actually answers the question.
- **`Player::AwardsForPlayer()`** left `$player_award` undefined when called without an
  `AwardsId`, warning on every whole-player fetch. Initialised.
