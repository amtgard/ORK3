# Scrollery — review panel decisions

**Date:** 2026-09-05
**Source:** a nine-specialist review panel run against the `scrollery` branch on 2026-09-04/05
(front-end/UX, graphic & document design, database & API, medieval scroll design, Amtgard
awards domain, ORK platform, accessibility & inclusion, print pipeline & performance,
product & adoption), synthesised into a 46-item menu.

Full panel reports and the PM synthesis:
`/private/tmp/claude-501/-Users-averykrouse-GitHub-ORK-tobias-ORK3-tobias/e554e6d0-9e0e-42e9-8753-5090675d9b6e/scratchpad/scrollery-panel/`
(`SYNTHESIS.md` plus `01-`…`09-` per-lens reports). Copy them into `docs/` if they need to
outlive the scratchpad.

Related: `docs/scrollery-deferred.md` (the older deferred list — items here supersede it
where they overlap), `docs/superpowers/specs/2026-09-03-scrollery-design.md`,
`docs/superpowers/specs/2026-09-04-scrollery-custom-scroll-design.md`.

---

## Batch 1 status — COMPLETE, 2026-09-05

All 26 items resolved. Unit suite **623 tests / 31,552 assertions / 0 failures** (baseline
at session start: 572). Nothing committed — everything is in the working tree on top of the
maintainer's own in-flight work. `class.Authorization.php` untouched and unstaged.

| Landed | Deferred to batch 2 | No change needed |
|---|---|---|
| 1 · 2 · 3 · 4 · 5 · 6 · 7 · 8 · 9 · 10 · 11 · 12 · 13 · 14 · 15 · 16 · 18/19/20 · 26 | 17 · 21 · 23 · 24 · 25 | 22 |

**Four defects found that were on nobody's list**, each surfaced by doing an assigned item:

1. **A pre-existing SQL-injection vector in `Player::AwardsForPlayer()`** — a non-numeric
   `MundaneId` failed `valid_id()` and reached the statement through the no-op
   `mysql_real_escape_string()` shim. No caller passes one; nothing stopped it. Now
   `(int)`-cast. Found by a *new test*, not by looking.
2. **Admin grant rejections were reported to officers as successes** — the endpoint answers
   with a rendered admin page and `knDoSave`/`pkDoSave` never read the body, so every
   rejection (including three pre-dating this work) surfaced as "award granted".
3. **Every rank pill that was already a `<button>` renders as an ellipse** — nothing in the
   codebase sets `box-sizing: border-box`, so the UA's button padding sat outside the 36×36
   content box. Live on five CRM surfaces, in the UA button font.
4. **The render page's `Dropped` banner had no print rule** — three certificates paginated
   to four sheets, sheet one blank but for the notice.

Plus two fixture errors: the "ladder award 245" in two tests is **Knight of Battle**, a
knighthood (the ladder is 243); and a fixture labelled "Warlord = award 40" is **Paragon
Barbarian** (Warlord is 12).

**Corrections to the panel's numbers**, now that the code is real: bulk `Total` at event
12250 is **488, not 495** (7 rows are excluded-class and counting them would be dishonest);
the single-award query was **124.4 ms, not the panel's 36.2 ms**, now 0.25 ms; and the
"2,230 nulled `mundane_id`" figure is **2,797**.

## How to read this

Items are numbered as in the synthesis menu, so "do 4, 9 and 17" stays meaningful.
Batch 1 covers items **1–26**. Items **27–46** have not been ruled on yet and are listed at
the bottom, unchanged, for a later pass.

`Decision` is the maintainer's own words. `Reading` is how it was interpreted into work —
if a reading is wrong, that is the line to correct.

---

## Batch 1 — items 1–26

### Tier 0 — Correctness bugs

| # | Finding | Decision | Reading |
|---|---|---|---|
| **1** | Revoked awards are printable; 2,287 appear pre-checked in bulk, 2,228 print with a blank recipient, 59 reprintable by the player | *"hide the button on revoked"* | Hide the Print control wherever a revoked award is listed **and** add the `revoked = 0` predicate server-side (bulk query + `viewer_may_print()`). Hiding the button alone leaves the URL reachable, and the URL is the thing that prints. |
| **2** | Long and multibyte names are silently clipped off the page — `strlen()`-guessed sizing, `overflow:hidden` trims rather than shows | *"Dynamically resize"* | Real measurement-based autofit, not a better guess: shrink until the text fits its box, applied on the render surface **and** in the composer preview so the two agree. `mb_strlen` for the server-side first guess. |
| **3** | Auto-print races the webfonts — prints in Times, or blank | *"fix"* | Gate `window.print()` on `document.fonts.ready` **and** `load`, with a timeout failsafe. Preload the two faces. |
| **4** | `award_row()` materialises 3,076 rows to find one — and now runs on every award grant in the ORK | *"fix"* | Single-row branch in `AwardsForPlayer()`; pin with a test. This is a platform-wide regression, not just a Scrollery cost. |
| **5** | The bulk 200-cap truncates alphabetically, before the art filter — a 495-award court silently becomes the first 200 | *"fix"* | Merged with **26**. Return a true total; page the query so nothing is unreachable. |
| **6** | The landing page sends kingdom-only officers to a denial page | *"fix"* | Door targets built from the authority check that gated them, plus a scope chooser. **The panel's diagnosis was wrong and the real bug was far worse — see below.** |
| **7** | Two composer controls lie — border colours inert for 8 of 10 borders but still swatch and still reload; `rosewash` prints blank white paper | *"fix"* | Both halves. The knot/frame predicate comes from `Scroll::BORDER_STYLES` as a stamped attribute, never restated in JS. |
| **8** | `.Multiline` is defined nowhere — 230 hand-formatted citations print as a run-on | *"fix, but also be prepared to be dynamic"* | Define the class **and** bring the reason field into the item-2 autofit pass, since a multi-line citation is exactly what will overflow. |
| **9** | `PrintAwardsId` is dead code; `$ordinals` fails silently to `''` past rank 10 | *"fix"* | Delete the dead key; make the ordinals fallback explicit. Deliberately **not** coupled to the Zodiac branch. |

### Tier 1 — Trust & safety of the print action

| # | Finding | Decision | Reading |
|---|---|---|---|
| **10** | Every entry point prints sight-unseen | *"fix"* | Drop `print=1`; `render` becomes the preview surface it already is, with a no-print toolbar (Print / Back). |
| **11** | Bulk pre-checks all 200 rows; one click sends them to the printer | *"fix, ensure page breaks are working as expected"* | Select-all defaults **off**, counted button, confirm above a threshold (CRM helper, never native `confirm()`). **Plus** verify multi-certificate page breaks actually break where expected — one certificate per sheet, no blanks, no orphans. |
| **12** | No AUTH_ADMIN branch; 120,284 rows (30.74%) printable by nobody but the recipient; three silent redirects; denied page has the `orkui.css` h1 pill bug | *"fix"* | Admin branch, real denial messages on all three redirects, denied page rebuilt on the `.rp-*` shell with its heading reset. |
| **13** | Decide the fallback scope for unattributed awards — root cause is the grant form defaulting "where given" to 0 | *"default to yourself if you are a park or kingdom officer, otherwise blank and required"* | **Root-cause fix on the grant form** — see the note below, the real cause was not what the panel diagnosed. ⚠️ Does **not** retroactively reach the existing 120,284 rows; with **12**'s admin branch they are admin-reachable, everything else stays recipient-only. |
| **14** | Nothing records that a scroll was printed | *"fix"* | Insert-only print-log table + migration (classified in ork-db's `migration-classification.json5`, or `drift-check --strict` blocks the test run). Unblocks 27, 30, 32, 45 later. |
| **15** | The composer has no non-visual channel — no live region, no `aria-pressed`, `<label for>` points at a `<div>` | *"fix"* | Full a11y pass on the composer. Note `tnRankPaint()` is shared, so the pill work lands on Add Award and Recommend too — that is intended, and needs their regression pass. |
| **16** | Nothing warns that browser headers/footers are on by default, that Firefox flattens blend modes, that Safari's `foreignObject` is unreliable | *"fix as much as you can silently, panel the rest"* | Fix in code whatever can be fixed in code (dead `image-resolution`, margins, anything CSS can assert). Only what genuinely cannot be controlled from the page becomes a dismissible advisory panel. Advisory never gates. |

### Tier 2 — The certificate itself

| # | Finding | Decision | Reading |
|---|---|---|---|
| **17** | Curated design presets over 7,680 raw combinations | **Defer to phase 2** | Not in this batch. |
| **18** | `custom`'s flavour array is eleven empty strings; five orders repeat one sentence across all ranks | **Superseded — see below** | |
| **19** | Masterhoods should print their ladder's artwork (3,195 grants) | **Superseded — see below** | |
| **20** | Fixed recipes for Knighthoods (2,017 grants) | **Superseded — see below** | |
| **21** | Provenance: Amtgard Year and event name on the date line | **Defer** | Not in this batch. |
| **22** | Somewhere to actually sign — the blank is pre-filled and there is only one | *"double check this, if you don't enter a name it leaves the bottom right blank"* | Investigate before implementing: is the empty-signatory case a deliberate blank line to sign in ink, or a layout defect (collapsed flex column, orphaned underline, asymmetric block)? Fix if defect; report if intended. |
| **23** | Type craft — notificatio clause, rubrication, versal, ligatures, tracking | **Defer** | Not in this batch. |
| **24** | A real font stack — BlackChancery is Latin-1 only, 42 of 155 non-ASCII personas unrenderable, no fallback declared | **Defer** | Not in this batch. |
| **25** | Ink-friendly / bare-paper / draft mode | **Defer** | Not in this batch. |

### ⭐ 18 / 19 / 20 — superseded by a scope decision

> *"remove noble titles above Lord, all associate titles, paragon, knighthood, and
> masterhood from scrollery altogether, those are all too important to use this tool for"*

This replaces items 18, 19 and 20 entirely. Rather than improving how Scrollery renders
these awards, they leave the module. The reasoning is that a knighting scroll, a
masterhood, a paragon and a peerage-adjacent title are documents that deserve a real
scribe, and a generated certificate is the wrong artefact for them.

**Excluded classes** (mapping to `Award::OptionGroupLabelFor()` buckets):

- `Knighthoods` — all — ids 17, 18, 19, 20, 245
- `Masterhoods` — all — ids 1–12, 240, 244
- `Paragons` — all — ids 37–51, 241, 242
- `Associate Titles` — all — ids 13, 14, 15, 16, 203
- `Noble Titles` **above Lord** — ids 54–69, plus orphan ids 147–162
- `Defender` — id 35 (decided 2026-09-05, see below)

**The cut line is `title_class > 30`.** `title_class` is a clean 10-step ordinal matching
the Amtgard ladder exactly (Lord 30 → Baronet 40 → Baron 50 → Viscount 60 → Count 70 →
Marquis 80 → Duke 90 → Archduke 100 → Grand Duke 110). Lord (52) and Lady (53) both sit at
30 and both survive — every rung is a masculine/feminine pair at the same class, so the rule
can never be `award_id > 52`.

**Blast radius:** 55 catalogue ids, 2,187 `ork_kingdomaward` rows, **15,293 grants (3.91% of
391,296), 5,127 since 2023** — counted two ways and agreeing.

**Implemented as an explicit id set, not a field expression.** Two verified reasons: the
bulk row shape (`model.Scrollery.php:445-459`) carries no `Peerage` / `IsTitle` /
`TitleClass` at all, so a field-derived rule would **fail open on every bulk run**; and
`Kingdom::GetAwardList()` reads `ka.title_class`, a per-kingdom override that **already
drifts** (award 52 "Lord" has `ka.title_class = 0` on one row). The predicate also tests
`AliasAwardId` — 11 award rows carry an alias, 7 of them to a now-excluded id.

**Orphan ids 147–162:** no `ork_award` row exists for ids 95–187, yet 49 `ork_kingdomaward`
rows in kingdoms 3, 28 and 32 point at 147–162 with the exact names Baronet / Baron /
Viscount / Count / Marquis / Duke / Archduke / Grand Duke and feminine forms. They dangle,
so `officer_role` is NULL, so they are custom-eligible and **print today**. A field-keyed
rule cannot see them; the id set includes them.

**Three gap entries, decided 2026-09-05:**

| Entry | tc | Grants | Ruling |
|---|---|---|---|
| `Defender` (35) | 10 | 236 | **Excluded** — despite sitting *below* the cut line |
| `Master` (226) | 0 | 67 | **Kept printable** |
| `Esquire` (209) | 0 | 58 | **Kept printable** (not selected for exclusion) |

On `Master`, in the maintainer's words — recorded because a name-matching rule would sweep
it up and someone will eventually try to "correct" this:

> "the bare Master is a weird non-noble title that most kingdoms have, it ranks below Lord,
> despite having the same conceptual nomenclature as Master Owl or Master Rose. Master by
> itself is low level."

Surviving `Noble Titles` bucket: Lord, Lady, Master, Esquire — 4 entries.

**Consequences to honour:**

- Excluding Masterhoods must **not** exclude the base ladder award — several ladders have
  a separate Master-tier catalogue entry (`Award::GetLadderMasterMap()`). The 14 ladder
  orders with artwork survive intact.
- The exclusion belongs at **one** choke point: a new
  `Scroll::IsExcludedFromScrollery()` beside `Scroll::IsOfficerPosition()`
  (`class.Scroll.php:~341`), gated inside `OrderForAwardRow()` at `class.Scroll.php:379`.
  That single gate is inherited by `mine`, `bulk`, `create`, `compose`, `render`, the
  profile print icon and the post-grant link, because `OrderForAwardRow()` is what both
  `HasArt()` and `BuildScroll()` call. It must **not** go in `IsCustomEligible()`, which
  only answers "may an art-less award take the custom scroll" — an excluded award that has
  its own art would slip past.
- `Scrollery_bulk.tpl:178-187` currently says excluded rows "have no certificate artwork".
  After this change most are excluded by **policy**, not missing art. Reword it.
- This shrinks the custom scroll's audience substantially. Item **18**'s "custom prints no
  flavour at all" therefore matters much less than it did — most of what it affected is
  now gone. Worth re-reading after the exclusion lands rather than assuming it still holds.
- Officer positions remain excluded as they already are.

### ⚠️ Item 6 — nobody could reach the officer doors at all

The panel reported this as "the landing page prefers park scope, so a *kingdom-only* officer
is sent to the denial page". The real fault is bigger, and the panel's version barely
happens.

`Scrollery_index.tpl` derived the officer scope from `$this->session->park_id` /
`kingdom_id`. **Those are `Controller_Admin`'s scratch values** — "which park am I currently
editing" — set at `controller.Admin.php:435` and unset at `:28`/`:31`. `Controller_Login`
writes only `user_id`, `user_name`, `token` and `timeout`.

> So on every normal login both were unset, `viewer_is_officer()` returned false, and
> **no officer ever saw the officer doors at all.** Not just kingdom officers — nobody.
> Verified: before the fix a kingdom monarch got only `Scrollery/mine`.

Two corrections to the panel's model that follow from this:

- `HasAuthority(AUTH_PARK)` **falls up to the park's kingdom**
  (`class.Authorization.php:911`), so the "kingdom officer denied at the park door" case
  only bites when the officer's home park sits outside their authority chain. Rare.
- Scope is now resolved from real authority rather than session scratch. One residual gap
  remains until `Model_Scrollery::issuable_scopes_for()` lands: resolution still starts from
  the viewer's **home** park/kingdom, so an office held outside one's home is invisible —
  e.g. `ritari` (54527) holds kingdom-4 authority but lives in park 32 in kingdom 3, and
  gets no doors. Strictly better than before (where nobody did), but not yet complete.

### ⚠️ Item 13 — the root cause was not what the panel diagnosed

Recorded because it changes what "fixed" means, and because the panel's framing is wrong in
`docs/scrollery-panel/03-database-api.md` and `09-product-adoption.md`.

The panel blamed a **missing default** — "the form leaves *where given* blank and
`add_award()` writes 0". That is not mainly it. The kingdom and park grant modals already
defaulted to their own scope, and the profile modal already defaulted to the recipient's
park. The actual leak:

> **Typing free text into "Given At" without picking a suggestion silently zeroes the
> location ids, and the form submits anyway.**

That is the hole, and it is present on all four grant surfaces. Defaults alone would never
have closed it. Both halves are now closed client- and server-side, and no path can write a
`0 / 0` pair again.

**Two further defects found in the same code, neither previously known:**

1. **Rejections on `Admin/player/<id>/addaward` were reported to the officer as
   successes.** That endpoint answers with a whole rendered admin page, and
   `knDoSave` / `pkDoSave` never read the response body — so **every** rejection, including
   three that pre-date this work, surfaced in the UI as "award granted". It now also emits an
   `X-Award-Error` header, which the two save handlers read and surface.
2. **The "Given At" change handler was clearing the recipient.** A copy-paste bug: typing a
   *location* wiped `#GivenBy` / `#MundaneId` — the person receiving the award — instead of
   the location ids it meant to clear.

**Global admins — ruled 2026-09-05.** In the maintainer's words:

> "For ORK Admins, the Given At can default to the Kingdom of that admin's persona residency
> if they are accessing the Scrollery outside the click pattern of inside a kingdom or park."

So the resolution order is: (1) the scope the page is for, when the user holds authority over
it; (2) **new** — for a global ORK admin with no park/kingdom route context, the kingdom of
their own persona's residency, read from their `ork_mundane` record (**not** the session
scratch values, which are `Controller_Admin`'s state); (3) blank and required, now only for a
non-admin with no authority over the page's scope. Kingdom deliberately, not their home park —
an admin acting outside a click-path is acting kingdom-wide. It remains a default, editable,
and the server-side rejection of a submitted blank is unchanged.

**Original reasoning, retained:**
`HasAuthority()` short-circuits `true` for a true global admin on every scope, so the only
way to single them out is an explicit `AUTH_ADMIN` probe — and blanking them would force an
ORK admin standing on a park's own award page to re-pick that park by hand, which is worse.
The rule implemented is: **default to the scope the page is for, when the granting user
holds authority over it** — "yourself" in the only sense the page can know — and blank
otherwise.

### ⭐ Scope tightening — ladders only, decided 2026-09-05

This **supersedes** the 55-id exclusion above. In the maintainer's words:

> "remove all awards from the drop-down other than Order of X awards (the ladders). No
> titles, no masters, etc. Leave Custom Award and Custom Title but specifically give a space
> for that to be typed in to replace the 'Custom Title' or 'Custom Award' wording."

**The denylist becomes an allowlist**, which fails closed rather than open:

> An award is printable **iff** it resolves to a real art-backed order, **or** it is
> Custom Award (94) or Custom Title (246).

**Applies everywhere** — the composer dropdown, `mine`, `bulk`, and the profile Awards and
Titles print buttons. One predicate at one choke point; an award you cannot compose cannot be
reprinted either.

**Award 31, "Order of the Walker in the Middle", is dropped** despite being a genuine
`is_ladder` award with 379 grants, because Scrollery has no artwork for it. This is what
removes Crystal Groves' "Master Reeve" — a kingdomaward on base 31 — with no name-matching
heuristic anywhere. Verified: award 31 is the **only** ladder in the catalogue with
off-pattern kingdom names, and there are exactly three (`Master Reeve` 4 grants,
`Walker in the Middle` 16, `Walker of the Middle` 13). Every other ladder is named
"Order of …" in every kingdom.

**This reverses the previous round's keeps.** Lord, Lady, Master and Esquire were explicitly
retained on 2026-09-05 morning; they are now excluded. 8 catalogue entries and **3,991
grants** lose printability: Lord 1,862 · Lady 975 · Walker 379 · Weaponmaster 341 ·
Dragonmaster 289 · Master 67 · Esquire 58 · Warmaster 20. **361,381 grants remain**
(343,098 ladder + 18,283 custom).

**Custom entries print their real name.** Title precedence stays as decided for every other
award, but for 94/246 only it becomes `CustomAwardName → KingdomAwardName (when not the
literal "Custom Award"/"Custom Title") → today's chain`. Live data: award 94 has 18,077
grants of which **12,213 already carry a real `custom_name`**, and 246 has 203 of 206 — all
of which print the literal words "Custom Award" today. Some kingdoms also created genuinely
named `ork_kingdomaward` rows on base 94 (Dreamkeeper, Order of the Hellrider, Grand Marquis,
Order of the Hardcore, Mistress), which the second tier picks up.

The composer gains a **required text field** for that name, live-previewed, reaching
`BuildScroll()` as `$context['customName']` on both the preview and compose paths. Sanitising
and length-capping live in the domain, not the controllers. An empty one is **refused
server-side** with a real message rather than printing the placeholder.

### Certificate wording and alignment — 2026-09-05

**Never justify certificate text.** `.Award` sets `text-align: center`, but that only
*inherits*, and `orkui.css:788` carries a global `p { text-align: justify }` — a rule matching
an element directly always beats an inherited value. `.AwardeeName` is a `<p>`, so a persona
long enough to wrap rendered as `Highmarshal        Haliax` with words at opposite margins.
Fixed in `scrollery.css` with `.Award p, .Award div, .Award span, .Award section
{ text-align: center }` (0,1,1 beats the global 0,0,1; the two deliberately left/right-aligned
lines in the issuing block are 0,1,3 and keep their alignment). **Preview-only in effect** —
`Scrollery_render.tpl` is a bare route loading only `scrollery.css`, so the print never saw
`orkui.css`. Same class as `1e19545d`, the dark-ground preview lie. `SignIn_index.tpl` fights
the same global rule the same way.

**Both custom entries take an interstitial line, and it is editable per print.**
`Scroll::TitlePhrase()` supplies a default and accepts an override; `_text.tpl` renders it in
the existing `.Body.AwardDetails` slot as an `elseif` to the ordinal phrase, so the two can
never both appear.

| Entry | Default | Reads |
|---|---|---|
| Custom Title (246) | `with the title of` | *Haliax Duskstrider → with the title of → Fantastic Beast* |
| Custom Award (94) | `with the` | *Haliax Duskstrider → with the → Order of the Raider* |

Different defaults because the two entries are **different parts of speech**: a title names
what someone *is*; an award names a thing granted, and award `custom_name` values are
frequently already order-shaped in the real data ("Order of the Raider", "Order of the
Facade"), where `with the` reads as a sentence and the title wording would not.

The composer offers a **"With phrase"** field, shown only for the two custom entries and
prefilled with the per-entry default. Both defaults are stamped into the input's `data-`
attributes from `Scroll::DEFAULT_AWARD_PHRASE` / `DEFAULT_TITLE_PHRASE`, so the wording lives
in one place and the JS never restates it.

Three behaviours worth keeping:
- **Clearing the box prints no line at all** — a real choice, and distinct from not supplying
  one. `BuildScroll()` reads the key with `array_key_exists`, not `??`, and the JS **omits**
  the parameter rather than sending `''`, so *absent* means "use the default" and *present but
  empty* means "print nothing". A `PHRASE_UNSET` sentinel carries that distinction.
- **An override cannot give a ranked award a second phrase.** `TitlePhrase()` returns `''` for
  anything that is not a custom entry, before it looks at the override at all.
- **The officer's wording survives an award switch.** Changing between Custom Award and Custom
  Title re-defaults the box only until they type — the `rankTouched` idiom already in the file.

### Tier 3 — Officer workflow

| # | Finding | Decision | Reading |
|---|---|---|---|
| **26** | Page the bulk run; make the browser page ~25, not 200 | *"fix"* | Merged with **5**. Server keeps 200 as a hard guard; the query pages so nothing is unreachable; the browser run chunks to a size the print rasteriser survives. |

---

## Not yet ruled on — items 27–46

Carried forward unchanged for a later batch.

**Tier 3 — officer workflow:** 27 bulk filters (park / order / min rank / ceremonial /
not-yet-printed) · 28 wire the print offer into surfaces that already compute it (titles
cell, officer's own profile, two grant paths discarding `AwardsId`) · 29 `mine` is an
unbounded unfiltered list and never sets `&variant=` · 30 Court Planner as a bulk source ·
31 bulk cannot reach a kingdom's parks or its principality · 32 free-title mode ·
33 event admin tab should link to bulk · 34 mobile tables lose every column header.

**Tier 4 — performance & pipeline:** 35 hoist `_border.tpl`'s 48 KB defs to the page ·
36 WebP renditions + a display size for the preview · 37 immutable asset caching, and
`heraldry_url()` passing no `Size` · 38 make the render run cheap (batch lookups, cache
composed markup, two composite indexes) · 39 palette changes shouldn't round-trip ·
40 bleed, trim marks, multi-up · 41 the render route is a page, not a seam (signed token +
manifest) · 42 the module's raw queries sit in `orkui/model/`.

**Found along the way, not from the panel:** four native `title` attributes on the profile's
attendance and notes tables still need converting to `data-tip` (the right-anchor CSS rule
already covers them, so it is four attributes and no CSS work) · **nobody — not even a global
ORK admin — can grant an award to a parkless player from the CRM profile.**
`canManageAwards` is `has_authority($uid, AUTH_PARK, $playerParkId, AUTH_CREATE)`, and
`HasAuthority()`'s opening guard rejects `id = 0` for a non-`AUTH_ADMIN` type *before*
reaching the global-admin short-circuit, so the Add Award modal is not rendered at all. Only
two such players exist today (mundane 1 `admin`, 12 `orkadmin`, both service accounts), which
is why it has never been reported. Owned by `controller.Player.php`.

**Tier 5 — reach & adoption:** 43 the copy still describes phase 1 · 44 the module ships
invisible (no nav link, no What's New, no docs, stale breadcrumb) · 45 guards (ground
contrast test, CI asset-path check, written upstreaming gate) · 46 honesty fixes on the
recipient's own data (dead pronoun datalist, profile/override asymmetry, suspended chips).

---

## Declined outright

From the panel, not revisited: A4 sizing (zero non-North-American parks) · per-kingdom
theming and per-kingdom certificate name variants (already CLOSED with data) · deriving
the Zodiac month or touching ranks 11–12 (the owning branch keeps it; only the *column
read* is unblocked) · client-side PDF (`html2canvas` has never supported `mix-blend-mode`)
· a headless-Chrome PDF sidecar for now (build 41 first) · more composer vocabulary ·
auto-filling the reigning monarch (11 usable `start_date`s out of 2,514) · the recipient's
own device as a second heraldic slot · officer-position scrolls behind a config flag ·
trimming the CRM bundle for the composer page.
