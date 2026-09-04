# Scrollery — deferred work and known gaps

Everything the Scrollery port deliberately did **not** do, plus everything found along
the way that someone will want back. Started 2026-09-03 during the initial build; add to
it rather than letting these scatter across commit messages.

Related: `docs/superpowers/specs/2026-09-03-scrollery-design.md` (the spec),
`docs/superpowers/plans/2026-09-03-scrollery.md` (the build plan).

---

## 1. Blocked on data — needs a decision or a lookup nobody has yet

### 1.1 Archery has no base award to key art to

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

Every other order was compared side by side against Arcanyst 2.1.3. `hunter` could not
be: Arcanyst gates the archery orders behind a kingdom-id list, and its kingdom-loading
Cloud Function is dead, so the order never appears in its sidebar. It shares its layer
shape with `lion` and `hydra`, both of which matched. Worth an eyeball if the archery
mapping above ever lands.

---

## 2. Deliberately out of scope for phase 1 (recorded in the spec)

### 2.1 Zodiac's month, and ranks 11–12

`Scroll::ExpandFlavour()` understands `<<prefix|MONTH|suffix|else>>` and currently always
takes the **else** branch, which is what those branches were written for ("for whole
months", "You're a", "the"). The month side is implemented and unused: pass
`$context['month']` and it renders `prefix . month . suffix`. What is missing is any
notion of *which* month an award belongs to, and a UI for it.

Separately, `Award::GetLadderMasterMap()` gives award 30 a `MaxRank` of **12**, but zodiac
art and flavour stop at 10. `Scroll::ClampRank()` clamps to 10.
`ScrollResolutionTest::testZodiacClampsAtTenDespiteTheCatalogueAllowingTwelve` pins it.

### 2.2 Per-kingdom name variants on the printed title

`Scroll::ResolveTitle()` prints `ork_kingdomaward.name`, which is "Order of the X"
throughout. A kingdom that wants its own wording on the certificate has no way to say so.
Decided 2026-09-03: every award is designed as "Order of the X" and prints that way in
phase 1.

### 2.3 A generic certificate for everything with no art

Ladder coverage is complete (14 ladders + Flame). **Nothing else in the catalogue has
art**: every Master (1–12, 240, 244), every title, every non-ladder and every
kingdom-specific award. Phase 1 shows a Print control only where art exists and nothing
elsewhere — no dead buttons. A generic certificate covering the rest is a later phase.

### 2.4 Per-kingdom theming

`--primary-color` / `--secondary-color` are per ORDER (from `scroll-orders.php`), not per
kingdom. Driving them from kingdom colours is a later phase.

### 2.5 Server-side PDF

Output is `window.print()`. `Scrollery_render.tpl` is deliberately a bare route with no
chrome so a headless-Chrome PDF service can attach to it later by fetching one URL. PHP
PDF libraries have no blend modes, so a server-rendered PDF would mean redrawing every
order.

---

## 3. Smaller gaps found during the build

### 3.1 The Awards-tab Print button is officer-only

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

`assets/scrolls/rose/burn.jpg` and `assets/scrolls/smith/bg.jpg` are committed but no
layer references them — rose uses `burn.svg`, and smith keeps the shared parchment. They
may be leftovers from an earlier Arcanyst revision. Left in place rather than deleted;
confirm before removing.

### 3.6 Pronouns will need revisiting when free-text lands

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

`Model_Scrollery::printable_awards_for_player()` builds `PrintUrl` with `UIR`, which
`orkui/index.php` defines — not config. It is fine in the web path and there is precedent
(`model.AmtgardIdp.php`), but any CLI harness touching this model has to define `UIR`
itself first. Arguably the URL belongs in the template.

### 3.8 woff2 is served as `application/octet-stream`

nginx has no woff2 MIME mapping in this stack. Browsers accept it, but a `types` entry
would be tidier and lets compression/caching headers be set sensibly.

### 3.9 `Controller_WnAjax` echoes JSON and returns instead of exiting

Not Scrollery's, but found while building `ScrolleryAjax`: the ORK's Ajax controllers
`exit` after `echo json_encode(...)` (PlayerAjax 73 times, KingdomAjax 60) because
returning lets `index.php` go on to call `$C->view()`, which appends the entire themed
page after the JSON. `Controller_WnAjax` returns. Its callers only read `Status`, so a
lenient parser has been hiding it, but the response is malformed JSON.

### 3.10 `HasAuthority()` returns NULL on denial, not false

`Ork3::$Lib->authorization->HasAuthority()` is untyped legacy and returns **null** when
it denies. Passing it straight through a `: bool` return type is a fatal TypeError — cast
it. Cost one 500 during Task 16.

### 3.11 Scrollery is not linked from any navigation

`Scrollery` and `Scrollery/mine` are reachable only by URL, plus the per-row Print button
on the Awards tab. No menu entry exists yet.

---

## 4. Fixed along the way — recorded so they are not "rediscovered"

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
