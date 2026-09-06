# Scrollery — panel synthesis

Nine specialist reviews, deduplicated into one menu. Severity claims spot-checked against the
live DB (`ork3-php8-db`); the in-flight working tree was read before anything was proposed.

---

## 1. The read

Scrollery is a good module with a trust problem. The architecture is honest — the `.rp-*` shell
is worn properly, the preview loop correctly splits "needs the server" from "is a DOM write",
`AbortController` is used right, there are 17 unit tests, and the pronoun and title-precedence
handling already beats every hand-run Photoshop workflow in Amtgard. What the panel converges on
is not craft, it is **consequence**: the module spends paper before anyone has seen the page,
prints a name that has been silently cut in half, prints an award that was taken away, remembers
nothing about having printed, and denies 30.7% of the corpus to everyone but the recipient with a
blank redirect. Underneath that sit two performance faults with real blast radius — a per-award
lookup that materialises 3,076 rows (and now taxes every award grant in the ORK, not just
Scrollery) and a bulk cap that discards 60% of a coronation court alphabetically. Phase 2 lit up
almost every award in the catalogue and then left the copy, the flavour text, and the officer's
route to it all describing phase 1. Nothing here is architectural. Almost all of it is small.

---

## 2. Already in flight — do not re-decide these

The uncommitted working tree (`git diff --stat`: 11 files, +1056/-448) already lands:

- **The composer's award picker is sectioned and searchable.** `Award::OptionGroupLabelFor()` /
  `OptionGroupOrder()` extracted in `class.Award.php`; `Model_Scrollery::choicesForAwardListForTest()`
  stamps `Group` on each choice and sorts section-then-name; `Scrollery_create.tpl` emits real
  `<optgroup>`s with Custom as standalone options, and `awInitPicker()` is wired. The panel's
  "flat 250-entry select" complaint is answered.
- **Rank is a pill grid, not a dropdown** — shared `.sc-rank-pill` painted by `tnRankPaint()`,
  annotated with the recipient's held ranks from `PlayerAjax/.../awardranks`, opening on the rank
  they hold. The a11y lens's *control* complaint is largely answered; its `aria-pressed` /
  accessible-name half is **not** (item 17).
- **All four doors migrated onto the shared `.rp-*` shell**, with `reports.css` linked and two
  genuinely shared additions (`.rp-sidebar-wide`, the collapsible `button.rp-filter-card-header`)
  put in the shared sheet rather than a local block. Most of the ~90 lines of duplicated inline CSS
  the frontend lens flagged is gone. Residue: `.sc-table` is still restated in `mine` and `bulk`,
  and the `@media (max-width:1000px)` `.rp-body` stack is still restated in `create` and `bulk`
  even though `reports.css` now owns it.
- **Composer sidebar reordered** to `_text.tpl` reading order; design and heraldry cards collapsed
  by default; `.adm-input/.adm-select/.adm-textarea` primitives; `aria-label` on the sliders;
  dark-mode tokens throughout; Print/Back moved into `.rp-header`.
- **The dark-ground preview lie is already fixed** (commit `1e19545d`) — see §4.5.

Explicitly **not** fixed by the in-flight work, despite touching the same files: the stale phase-1
copy (`Scrollery_index.tpl:56` survives verbatim), the officer-scope door bug
(`Scrollery_index.tpl:14` unchanged), bulk's pre-checked select-all (`Scrollery_bulk.tpl:210,222`
still `checked`), auto-print (`&print=1` still on all four call sites), and `Scrollery_denied.tpl`
(untouched, still links `revised.css` only).

⚠️ The working tree also carries the `true ||` auth bypass in `class.Authorization.php`. Never
stage that file.

---

## 3. The menu

### Tier 0 — Correctness bugs

**[1] Revoked awards are printable** · Effort: S · Raised by: Amtgard, Platform
What: No `revoked` predicate anywhere — `bulkSqlForTest()` (`model.Scrollery.php:367-383`),
`viewer_may_print()` (`:694`), and `Player::AwardsForPlayer()` all ignore it. Verified live: **2,856
revoked rows; 2,287 carry a park/kingdom scope so they appear pre-checked in bulk; 59 still carry a
live `mundane_id`** so the player can reprint their own stripped award from `mine`. The other 2,228
print with a blank recipient because revocation nulls `mundane_id`.
Why: an award stripped for cause is the one document that must never reprint, and blank-name pages
waste a scribe's night.
Watch: filter inside Scrollery, not in the shared `AwardsForPlayer()`.

**[2] Long and multibyte names are silently clipped off the printed page** · Effort: M · Raised by: Graphic, A11y
What: `_text.tpl:47` guesses font size from `strlen()` and floors at 136px for names ≥32 chars;
689 personas exceed 32 chars, 291 exceed 40, 71 are >24 chars with no space. The wrapping
`<svg style="overflow:hidden">` (`Scrollery_render.tpl:44`) **trims the overflow rather than showing
it**. `strlen()` counts bytes, so 155 multibyte personas shrink as if half again as long. And
`applyText()` (`scrollery.js:379-409`) never touches font-size, so the composer preview shows a
size the print will not use. Same class of defect for the reason field, where `<textarea id="sc-reason">`
has no `maxlength` against a `varchar(400)` column.
Why: a certificate with the recipient's name cut in half is worse than no certificate, and neither
the officer nor the preview can tell before it is on paper.
Watch: an autofit pass must be idempotent across a 200-page bulk document and gated on
`document.fonts.ready`; keep the PHP formula as the JS-less first guess.

**[3] Auto-print races the webfonts** · Effort: S · Raised by: Perf
What: `Scrollery_render.tpl:54` fires `window.print()` on `load`. All five faces are
`font-display:block` (`scrollery.css:24-62`), so `load` (which waits on ~4 MB of art) can fire
before the 276 KB of fonts resolve.
Why: the certificate silently prints in Times, or with blank text where `block` is still hiding
glyphs. Nobody notices until it is on paper.
Watch: none — `Promise.all([document.fonts.ready, load])` with a `setTimeout` failsafe so a font
404 still prints. Add `<link rel="preload">` for BlackChancery and Averia.

**[4] `award_row()` materialises 3,076 rows to find one — on every award grant in the ORK** · Effort: S · Raised by: DB, Perf
What: `model.Scrollery.php:723` calls `AwardsForPlayer(['MundaneId'=>0,'AwardsId'=>$id])`, which
`class.Player.php:1133` renders as an **unparenthesised** `where awards.mundane_id='0' or
awards.awards_id='N'`. 3,075 rows have `mundane_id=0` (verified), so every call runs a 9-table
`SELECT DISTINCT` and hydrates 3,076 PHP arrays to keep one. Profiled at 36.2 ms vs 0.18 ms.
`Controller_Award::handle_success()` (`controller.Award.php:126`) now runs it on **every grant**.
Why: a 200-scroll run burns ~7.2s of DB time and 615,200 discarded arrays before any markup — and
Scrollery made the ORK's hottest officer workflow measurably slower.
Watch: `AwardsForPlayer()` is shared; add a single-row branch and pin it with a test.

**[5] The bulk 200-cap truncates alphabetically, before the art filter** · Effort: M · Raised by: DB, Frontend
What: `bulkSqlForTest()` ends `order by m.persona, a.name, awards.rank limit 201`
(`model.Scrollery.php:382`); the printable/excluded split happens in PHP afterwards
(`:479`). Event 12250 / kingdom 4 is 495 rows, all printable — the officer gets the
alphabetically-first 200 and 295 certificates are unreachable, because the date filter's finest
grain is one day and that *is* one day. 24 event/kingdom pairs exceed the cap; worst is 741.
Why: bulk print for a coronation court is the flagship use case, and it fails silently in favour of
recipients whose personas start with A.
Watch: fix jointly with item 31 — return a true `Total` so the banner can be honest even before
paging lands.

**[6] The landing page sends kingdom-only officers to a denial page** · Effort: S · Raised by: Frontend
What: `Scrollery_index.tpl:14` builds `$officerScope` from the viewer's **home park** in session,
but `viewer_is_officer()` (`controller.Scrollery.php:195`) returns true if *either* park or kingdom
authority holds. A Kingdom Regent/PM/Monarch who is not also a park officer sees both doors, clicks
one, and `may_issue_for(…,'park',…)` fails.
Why: the most senior officers in a kingdom — exactly the people printing before Coronation — hit a
permission wall on the first click.
Watch: none. Build the door targets from the same authority check that gated them; render a scope
chooser when more than one scope holds.

**[7] Two composer controls lie about what they do** · Effort: S · Raised by: Frontend, A11y
What: (a) "Border colours" only reaches `_border.tpl`'s knotwork (`custom.tpl:44-46`); for
`crown,hunter,hydra,lion,mask,owl,rose,smith` the raster frame is drawn and the palette is inert —
yet the swatch paints and `scrollery.js:513-516` fires a full server `reload()` anyway.
(b) `rosewash` → `rose/burn.jpg` (`scroll-recipes.php:32`) is 86% pure white with mean L 0.993, drawn
as an opaque base plate; `rose.tpl:22` uses the `.svg` at `multiply; opacity:0.2`. One of twelve
grounds prints blank paper.
Why: an officer changes the colour pair, waits for a reload, sees no difference — that reads as
broken, not as a constraint.
Watch: the knot/frame predicate must come from `Scroll::BORDER_STYLES` stamped as a `data-` attribute,
not restated in JS. Pairs with item 39 (make the palette a DOM write, not a round trip).

**[8] `.Multiline` is defined nowhere, so hand-formatted citations print as a run-on** · Effort: S · Raised by: Graphic
What: `_text.tpl:74,83` both carry `class="… Multiline"`; `scrollery.css` defines it nowhere
(verified), as with `.KeepUnderline`, `.FormFill`, `.Keep`. The flavour block gets `white-space:pre-line`
inline; the reason does not. **230 `ork_awards.note` rows contain `\n`.** `applyText()` `esc()`s into
`innerHTML` and loses newlines in the preview too.
Why: a citation someone formatted by hand prints as mush and stops lining up with the ruled underlines.
Watch: confirm a 3-line reason still culls the 6-underline loop correctly.

**[9] Two values are computed and discarded** · Effort: S · Raised by: Platform, Manuscript
What: `controller.Award.php:126` sets `$this->data['PrintAwardsId']` — the only occurrence of that key
in the repo (verified); `Award_addawards.tpl` renders `$Message` alone. Separately `$ordinals`
(`_text.tpl:16`) stops at `tenth` and falls back with `?? ''`, so if `ClampRank()` ever passes 10 the
certificate prints "with their " and nobody will notice.
Why: one is dead code on the pre-3.5 surface; the other is a silent-empty-string trap sitting directly
in the path of the Zodiac branch.
Watch: don't couple the ordinals fix to the Zodiac work — extend the array or fall back to the numeral.

---

### Tier 1 — Trust & safety of the print action

**[10] Preview before print** · Effort: S · Raised by: Adoption, Frontend
What: `Scrollery_mine.tpl:102`, `Scrollery_bulk.tpl:290`, `Playernew_index.tpl:2230`, `revised.js:2311`
and `scrollery.js` all append `&print=1`, and `Scrollery_render.tpl:53-55` fires the dialog on load.
Without the flag `render` is already a perfectly good preview surface — it is simply never linked that
way, and dismissing the dialog leaves a dead page with no on-page Print button.
Why: this is the entire reversibility story — paper and ink are spent before anyone can see that the
heraldry is a placeholder, the pronoun read "their", or the reason line is blank.
Watch: see §4.9 — bulk and create should route through a preview; single-row `mine`/profile prints keep
one-click *and* gain a View link. Verify the new toolbar is genuinely absent under `@media print`.

**[11] Bulk pre-checks all 200 rows and one click sends them to the printer** · Effort: S · Raised by: Adoption
What: `Scrollery_bulk.tpl:210` and `:222` both still emit `checked` (verified in the current working
tree); the only feedback is "N selected". 48 event-days since 2023 produced 51-100 awards, 27 produced
100+.
Why: the default action on a legitimate date-range search is "print all of them".
Watch: no native `confirm()` — use the CRM helper. Button should read `Print 37 certificates`.

**[12] AUTH_ADMIN branch and an honest denial page** · Effort: S · Raised by: DB, Amtgard, Adoption, Frontend, A11y
What: `viewer_may_print()` (`model.Scrollery.php:694`) grants on recipient identity or AUTH_EDIT on
`at_park_id`/`at_kingdom_id`. **Verified: 120,284 rows (30.74% of 391,296) carry neither — 6,831 of
them since 2023.** Failure is `header('Location: '.UIR.'Scrollery')` with an empty body
(`controller.Scrollery.php:234`), plus two more silent redirects at `:262` and `:285`.
`Scrollery_denied.tpl` is 17 lines, is the only page not on the `.rp-*` shell, links only
`revised.css` — so `orkui.css:775` gives its `<h1>` the grey pill box in both themes.
Why: an officer who hits an unexplained blank redirect three times concludes the tool is broken. The
deferred doc treats this as four 1990s rows; it is one award in sixteen.
Watch: add the admin branch only — the broader scope widening is item 13, and it is a separate decision.

**[13] Decide the fallback scope for unattributed awards** · Effort: M · Raised by: DB, Amtgard
What: When `at_park_id` and `at_kingdom_id` are both 0, two fallbacks are available on the row already:
`ork_awards.park_id`/`kingdom_id` (the recipient's home at grant time — DB lens) or
`awards.kingdomaward_id → ork_kingdomaward.kingdom_id` (the catalogue's kingdom — Amtgard lens).
Neither needs a new join. Root cause is upstream: `Controller_Award::add_award()`
(`controller.Award.php:117`) defaults both to 0 when the form leaves "where given" blank, so ~6.5% of
current grants are still being created this way.
Why: "print my grandfather's Lion from 2009" is the reprint case, and today the park that gave it cannot help.
Watch: this is a genuine authorisation widening — decide it explicitly, don't infer it. Use it to
authorise a **single reprint**, never to widen a bulk listing. Nudge the grant form in the same pass.

**[14] A print log** · Effort: M · Raised by: DB, Amtgard, Platform, Adoption
What: Nothing anywhere records that a scroll was printed. Proposal:
`ork_scroll_print (scroll_print_id, awards_id NULL, mundane_id, printed_by_id, scope_type, scope_id,
order_id, rank, variant, recipe_json, printed_at)`, insert-only, written from `render()`/`compose()`
in `system/lib/ork3/`.
Why: one table answers four questions nothing else can — *has this player already had a scroll?* (the
recurring court question), *reprint exactly as it printed in 2019* (variant+recipe are per-print and
currently unrecoverable), *did anyone adopt this?* (feeds `Report::ReleaseFeatureUtilization()`, which
today has nothing to count), and *who printed 741 certificates at 3am*. It also gates the scribe
workflow (item 32), the Court handshake (item 37) and the upstreaming gate (item 45).
Watch: `render` is a GET — dedupe on `(awards_id, printed_by_id, <60s)` or log from a `sendBeacon` on
`afterprint`. The migration must be classified in ork-db's `migration-classification.json5` or
`drift-check --strict` blocks the whole unit-test run.

**[15] The composer has no non-visual channel** · Effort: S · Raised by: A11y
What: `#sc-stage` is an unnamed `<svg>` whose busy state is `opacity:.45`; `mount.innerHTML = d.Html`
replaces it with no announcement; `#sc-err` has no `role="alert"`; `#sc-recipe-note` — the only place
the dark-ground and band explanations live — is set with `textContent` and never announced. The
in-flight rank pills carry `aria-label 'Rank N'` and nothing else: no `aria-pressed`, no ", already
earned", and `<label for="sc-rank-pills">` points at a `<div>`.
Why: 7,680 combinations behind a control whose entire state is colour. One visually-hidden live region
carrying "Linen ground, knotwork border in Crown gold, common numerals, rank 3" is the whole non-visual
channel, and it costs ~15 lines.
Watch: `tnRankPaint()` is shared with Add Award and Recommend — the aria work lands on three surfaces
at once, which is the point, but needs their regression pass.

**[16] A "before you print" panel** · Effort: S · Raised by: Perf, Graphic
What: Nothing tells the user that browser headers/footers are **on by default** in Chrome/Edge and will
stamp the URL across a 0.125in margin, that scale must be 100%, or that Firefox routes print through a
platform backend with a long history of flattening `mix-blend-mode` (and would likely drop the owl's
`filter:url(#OWL_edge-detect)` outright). Safari rasterises HTML inside `<foreignObject>` unreliably.
Why: a player prints a blank or flat-grey certificate in Firefox and reports "Scrollery is broken."
Watch: advisory only — never gate on UA. Also delete the dead `image-resolution: 300dpi`
(`Scrollery_render.tpl:45`), which no shipping browser implements.

---

### Tier 2 — The certificate itself

**[17] Curated design presets** · Effort: M · Raised by: Graphic, Manuscript, A11y, Frontend
What: `Scrollery_create.tpl` offers 12 grounds × 10 borders × 16 palettes × 4 rank sets = **7,680
combinations** as four bare selects with `data-note` guidance on 4 of 22 entries. Bad pairs are
reachable and unlabelled (owl's opaque black plate over `starfield`; Zodiac night knotwork on
`cyanotype`). Add a `'presets'` block to `scroll-recipes.php`: 6-10 art-directed recipes, each with a
name and one descriptive sentence, as the first control, with the four raw slots underneath as
"Customise". Also add **Reset design** (to `Scroll::DEFAULT_RECIPE`) and, cheaply, **Surprise me**.
Why: an officer at 11pm before court wants four good options, not a colour-theory exercise. It turns an
unbounded QA surface into ten reviewable ones and is the only route by which a low-vision officer can
make a *considered* choice.
Watch: someone with taste must pick and eyeball the ten (at rank 1 and rank 10); a preset must validate
through `NormaliseRecipe()`; keep the raw slots reachable so shipped URLs don't break. Naming: see §4.6.

**[18] The flavour vocabulary is thin, and `custom` has none at all** · Effort: M · Raised by: Manuscript
What: `scroll-orders.php:522-535` — the `custom` order's flavour array is **eleven empty strings**, so
every Masterhood, Knighthood, Paragon, Defender and kingdom-invented award prints a blank sentiment
band. Separately `flame` and `hunter` repeat one identical sentence across all eleven ranks; `hydra` and
`smith` run in identical pairs; `dragon` reuses one line at rank 0 and 5. Key a small flavour set to the
buckets `Award::OptionGroupLabelFor()` already returns, and fill the ~30 missing sentences. Optionally
add a per-print flavour override (a select of the order's own lines plus a textarea) — nothing persists,
it rides the existing URL seam.
Why: phase 2 made ~136 awards printable and then printed them mute. A Knighthood scroll with an empty
sentiment block is the one certificate a person frames.
Watch: the accepted constraint is on **artwork**, not words — but ask Kisa first, credit additions in
the file header, and never silently rewrite her existing lines. An override is free text on a printed
page: escape through `scroll_e()` and cap length (the flavour block has no length-scaled font size).

**[19] Masterhoods should print their own ladder's artwork** · Effort: S · Raised by: Amtgard
What: All **3,195** Master grants fall through `Scroll::OrderForAwardRow()` (`class.Scroll.php:372`) to
the generic composer. `Award::GetLadderMasterMap()` (`class.Award.php:18`) is already the canonical
Master→ladder map — Master Rose → `rose`, Warlord → `warrior`, Battlemaster → `battle`, all 14. Render at
rank 0 with `ranked=>false` so no numeral and no write-in blank appear.
Why: a Master Rose *is* the apex of the Order of the Rose. 3,195 grants move from beige generic to
correct, with zero new art.
Watch: this reverses a phase-2 decision — see §4.4. `warrior` starts at rank 1 and needs an explicit
rank-0 path or a fallback to its rank-1 plate.

**[20] Fixed recipes for the awards that deserve one** · Effort: S · Raised by: Amtgard
What: All five Knighthoods (17,18,19,20,245 — **2,017 grants**) resolve to `Scroll::DEFAULT_RECIPE`
from `mine` and `bulk`: parchment, knot border, griffon tan/gold — identical to a Paragon Bard's. Add a
small `AWARD_RECIPES` map to `scroll-recipes.php`, consulted before `DEFAULT_RECIPE`, giving specific
awards a fixed recipe from the existing vocabulary. Same for Squire / Man-at-Arms / Page.
Why: a knighting scroll is the most important document most Amtgarders ever receive, and because it
resolves deterministically, fidelity across years is free.
Watch: values must stay valid vocabulary names — the existing `ScrollRecipeTest` guard extends to this
table for free. See §4.1 for why this is code, not storage.

**[21] Provenance on the date line: the Amtgard Year and the event** · Effort: S · Raised by: Manuscript, Platform
What: `Scroll::FormatDate()` (`class.Scroll.php:417-432`) prints `13th May 2024` and nothing else.
Append the game's own era — `…, in the XLII year of Amtgard` — as `floor()` arithmetic against one epoch
constant (25 Feb 1983), Roman to rhyme with the rank numeral art. Separately, `AwardsForPlayer()`
already returns `EventName` (`class.Player.php:1194`) and `BuildScroll()` never reads it: render it
under the date blank as "at Midreign". **20,930 awards carry a named event**, 253 distinct.
Why: the two cheapest things that turn the page from a form letter into a document of record, both real
Amtgard vocabulary, neither needing a query. It also gives bulk's event filter a visible payoff.
Watch: `SignAndDate` is already two tight columns and five orders override its positioning
(`scrollery.css:341-350`). The epoch is a community convention — pick it once and record the reasoning
on the constant. Make the year opt-out so 1998 reprints stay honest.

**[22] Somewhere to actually sign** · Effort: M · Raised by: Amtgard, Manuscript
What: `_text.tpl:96` pre-fills the "By my hand…" blank with `givenBy`, and there is only one signatory
line. Add (a) a "leave signature blank" toggle plus `&sign=blank` honoured by render/bulk — the
underline image is already drawn, so this is suppression, not layout; (b) an optional second ruled blank
captioned "For the Crown", always blank.
Why: a typed name where the signature goes is the most visible tell that this came out of a database, and
a monarch signing a scroll in court is the entire point of printing it.
Watch: **do not auto-fill a reign.** `ork_officer_history` has 2,514 rows and 11 populated `start_date`s,
all a backfill artefact sharing one date. Auto-filling from current officers would put the wrong monarch
on every historical reprint.

**[23] Type craft: the notificatio, rubrication, and the details the port never got to** · Effort: M · Raised by: Manuscript, Graphic
What: `_text.tpl:29-45` goes straight from the issuer block to "presents" — no notification clause, which
is the one thing every charter has ("Know all who read these words that"). `.Award{color:#000000}` means
every glyph on every certificate is black; piping the order's primary onto `.Award` would let exactly two
things be rubricated (the notification line and `.Order`), plus a `::first-letter` versal, no image.
Separately: no `font-feature-settings`, no ligatures, no `text-rendering`; `.Award .Order` at 1.5em with
default tracking wants negative; `line-height:2em` should be unitless `2` so it doesn't compound as the
size formula shrinks.
Why: the difference between "a web page that printed" and "a document" — and rubrication finally makes the
per-order colour pair visible on orders whose border is raster rather than knotwork.
Watch: `.Award` is a flex column with hand-tuned per-order `SignAndDate` overrides — prototype the extra
line at rank 10 warrior and rank 0 zodiac, the two tightest. Seven orders have `primary => null`; a rubric
must not apply on `Scroll::DARK_GROUNDS`.

**[24] A real font stack, and a warning when the typeface can't render the name** · Effort: S/M · Raised by: A11y
What: `scrollery.css:163` is `font-family:"BlackChancery";` with **no fallback family** (same at `:190`).
BlackChancery carries 214 glyphs, Latin-1 only — no `ð þ Þ Ð ł Ł š ž č ř ē ș ǧ`, no Greek, no Hangul, no
Elder Futhark. **42 of the 155 real non-ASCII personas contain at least one unrenderable character.**
Declare a stack; then check the persona against a shipped codepoint list and show one honest line.
Why: a Knighthood scroll with the name half in blackletter and half in Times is a keepsake with a visible
defect, landing disproportionately on non-Anglophone personas.
Watch: ship the codepoint list as a data file with a test, and regenerate if the woff2 is re-subset.

**[25] An ink-friendly / bare-paper / draft mode** · Effort: M · Raised by: Graphic, Adoption
What: Every certificate lays a full-bleed raster ground under everything and forces
`print-color-adjust:exact` on `.award-template *`. Add `?ink=1` (suppresses the `.bleed` ground and
neutralises `mix-blend-mode:multiply`→`normal` where it is gone; add `'none'=>''` as a first-class ground)
and `&draft=1` (also drops the border, keeping text, heraldry outline and numeral). Expose both as a
checkbox / a "Proof (low ink)" button.
Why: certificates get printed on parchment cardstock — printing a parchment *image* onto parchment *paper*
double-textures it and leaves an inset rectangle whose beige never matches the stock. It also costs roughly
a colour cartridge per dozen sheets, which is what makes a park unwilling to try the module at all.
Watch: raster borders and heraldry are tuned to multiply *into* parchment; on bare white they look thin —
`BORDER_STYLES` needs a third `paper` tone. A draft must be visibly labelled so it is never mistaken for
the real thing.

---

### Tier 3 — Officer workflow at scale

**[26] Page the bulk run — and make the page 25, not 200** · Effort: M · Raised by: DB, Perf, Frontend
What: Two different limits conflated into one number. Fix both: (a) return a true `Total` so the banner
reads "200 of 495", and add keyset paging `&after=<persona,awards_id>` on the same ORDER BY with a "print
the next batch" control, so nothing is unreachable; (b) drop the *browser* run to ~25 and chunk, keeping
`MAX_PRINT_RUN=200` as the hard server guard. A 2550×3300 plate decodes to 33.7 MB of RGBA; a 50-cert
mixed run touches 500-840 MB of decoded bitmap, and iOS Safari tabs cap nearer 200-400 MB.
Why: today the failure is a hung tab or a half-blank job discovered after the paper is gone — or 295
certificates that simply cannot be reached.
Watch: keyset paging on non-unique `m.persona` needs `awards_id` as tiebreaker in both the ORDER BY and
the cursor. More dialogs per run — mitigate by confirming once and stepping through.

**[27] Bulk needs filters that match what an officer actually prints** · Effort: S/M · Raised by: Frontend, Adoption, Amtgard
What: `awards_for_bulk()` filters on scope, event and date only — the page's own advice ("narrow by park
instead") is not actionable from the page. Add: a **Park** select on the kingdom-scoped sidebar, an
**Order** filter, a **minimum rank**, a **ceremonial only** toggle (non-ladder + rank ≥6), and — once item
14 exists — **not yet printed**. Of 94,342 art-backed grants since 2023, 61,141 are ranks 1-3; the
ceremonial population is ~167 per kingdom per year.
Why: the real job is "print the six scrolls for tonight's court", not "print the 240 ladder bumps from last
quarter". Today that means unchecking 234 boxes.
Watch: a park filter narrows *within* the authorised scope predicate (`at_park_id`/`at_kingdom_id`) — it
must never replace it.

**[28] Wire the print offer into the surfaces that already compute it** · Effort: M · Raised by: Platform, Adoption
What: Three separate half-wirings. (a) `Model_Player` stamps `CanPrintScroll` on **every** award row
(`model.Player.php:77`) and the Awards actions cell renders the link (`Playernew_index.tpl:2228-2233`), but
the structurally identical **Titles** cell (`:2348`) does not — so the Knighthood and Masterhood phase 2
made printable have no button. (b) `Playernew_index.tpl:2101` puts "Print My Certificates" inside the
`elseif ($isOwnProfile)` branch, so an **officer viewing their own profile never sees it**. (c)
`Controller_Admin::…addaward` (`controller.Admin.php:1104`) discards the `AwardsId`, and
`knDoSave`/`pkDoSave` never parse the response — so the Kingdom and Park grant modals and both
"grant from recommendation" paths end with no certificate offer.
Why: (a) and (b) are one-line changes on the widest-audience surface in the ORK; (c) is the exact moment in
Court season when a scroll is wanted.
Watch: `add_player_award` returns a **flat** `$r['Status']` int, not `Errors::Message` — misreading it is
the known double-grant trap.

**[29] `mine` has become an unbounded, unfiltered list** · Effort: M · Raised by: Frontend, Platform
What: `Scroll::OrderForAwardRow()` now falls through to `custom` for any non-officer award, so `HasArt()`
is true for nearly everything — but `printable_awards_for_player()` returns all of it into an unfiltered,
unsorted, unpaginated table. Measured: max 278 non-officer awards on one player, **4,523 players over 25
and 1,045 over 60**. `mine` also never sets `&variant=`, so an Owl or Battle reprint can only ever get the
first of its 3/2 variants even though `render()` reads the parameter.
Why: this fronts the player-facing door — widest audience, least tolerance for a wall of rows.
Watch: none. Text filter, sort by date/order, checkboxes with one "Print selected" (render already takes
`awards=1,2,3`), and a Design column saying "Order artwork" vs "Generic".

**[30] Court Planner is Scrollery's real front door** · Effort: L · Raised by: Amtgard, DB, Platform, Adoption
What: `feature/court-planner` already ships `ork_court_award.scroll_maker_id` + `scroll_status`, a Prep
Sheet grouped by maker, and `ork_court.last_printed_at` — and `ork_awards.court_award_id` exists in the
shared DB **with zero PHP readers on this branch**. Add `Scrollery/bulk/court/<court_id>`: rows in
`sort_order` (court order, so the printed stack matches the herald's script), filterable by
`scroll_maker_id` so a scribe prints only their own, stamping `scroll_status=2`. Plus a "Print this court's
scrolls" button on Finalize, carrying the ids it just wrote.
Why: this is the actual timeline — PM plans court, awards go to scribes, scrolls are made days ahead. Today
Scrollery only serves the last step, and bulk's event path reaches just 9.2% of grants (only 9,868 of
107,846 awards since 2023 carry an `at_event_id` at all).
Watch: blocked on the merge. Keep the coupling one-directional — Scrollery reads Court, Court never
requires Scrollery. Court sizes exceed 200, so item 26 lands first. Design the bulk source as a strategy now
so it slots in.

**[31] Bulk cannot reach a kingdom's parks or its principality** · Effort: M · Raised by: Amtgard
What: The kingdom branch is `awards.at_kingdom_id = <id>` alone (`model.Scrollery.php:348`). 1,616 rows are
park-only and invisible to a kingdom officer; a principality is an `ork_kingdom` row (Crimson Sands holds
1,351 awards) that `HasAuthority()` already reaches via `parent_kingdom_id`, but no link goes there. Add a
scope selector — "this kingdom" / "+ its parks" / "+ its principalities" — with the last derived from
`Kingdom::GetStatsKingdomIds()` so it honours the existing Include-Principality-in-Statistics setting.
Why: kingdom-level scribes work the whole kingdom's backlog, not one park at a time.
Watch: this widens exactly the predicate the cross-tenant fix hardened. Derive the id set **server-side**
from the authorised scope; never accept it from the URL.

**[32] Free title mode** · Effort: M · Raised by: Adoption
What: `compose()` re-resolves the submitted award against the kingdom's catalogue and redirects on no match
(`controller.Scrollery.php:270`) — correct as a gate, but it means a tournament champion scroll, an
event-participation certificate or a household charter cannot be produced at all. Allow an officer with
AUTH_EDIT on the scope to type an arbitrary title and recipient, backed by the custom recipe only, never
order art, never an officer position. It prints, it is logged, it grants nothing.
Why: "it's integrated" does not move a herald who already has a Canva file. "It does the three certificates
Canva can't do from your data, and the tournament ones too" does. Obvious first customer: a "print champion
scrolls" action off `Tournament/export`.
Watch: this adds an abuse surface, which is exactly why the print log (item 14) should land first.

**[33] The event admin tab should link to bulk** · Effort: S · Raised by: Platform
What: The Event admin tab is a one-item list (`Eventnew_index.tpl:2080-2086`), while `Scrollery/bulk`
already accepts `?event=` (`controller.Scrollery.php:105-124`) and `events_with_printable_awards()` already
builds a scoped picker. One `<li>` — `Scrollery/bulk/park/<parkId>?event=<eventId>` — gated on the tab's
existing `$canManage`.
Why: the backend is finished; the link is the whole feature.
Watch: none. `ScrolleryBulkQueryTest` already pins the scoped predicate.

**[34] Mobile tables lose every column header, and the date fields ignore the project rule** · Effort: S · Raised by: A11y, Platform
What: `Scrollery_mine.tpl` and `Scrollery_bulk.tpl` both do `@media (max-width:640px){thead{display:none}
td{display:block}}` with no `data-label` and no `td::before` — on a phone a row reads "Order of the Lion /
3 / 13th May 2024 / Sir Bob" with nothing saying which is which, and `display:none` on `thead` removes the
headers from the accessibility tree too. Separately the date fields are raw `<input type="date">` against
the project's Flatpickr `altInput`/`altFormat` rule.
Why: the bulk table is where an officer confirms who is getting what before committing 200 sheets of card stock.
Watch: put the `data-label` pattern in `reports.css` beside `.rp-*`, not a third copy in a third template.

---

### Tier 4 — Performance & output pipeline

**[35] Hoist `_border.tpl`'s defs to the page** · Effort: M · Raised by: Perf, Frontend
What: `scroll/_border.tpl` is ~97 KB, of which a `<defs>` block of **47,938 bytes** (32 `<symbol>`, 68
`<path>`) plus its own `<style>` is emitted **once per certificate** that uses the knot border — which is
every `custom` scroll, i.e. the generic fallback, plus griffon, battle and dragon (twice). Event 12250 is
391 awards of which 391 are Custom Award: a 200-cert run there is ~10 MB of inline SVG and ~6,400 duplicate
element ids. `_filters.tpl` already documents the correct pattern and its header comment states the rule.
Why: ~50 KB → ~2 KB per certificate, and it makes id uniqueness *true* rather than "benign because the
geometry matches".
Watch: the defs must be emitted by the **page**, not the fragment, so they survive the composer's
`mount.innerHTML = d.Html`. The griffon `Deco-Special` override keys on `[href="#…"]` on the `<use>` and
survives the move — re-run the asset/render tests and confirm `<use>` fill classes still resolve `var()`
from the referencing tree.

**[36] WebP renditions, and a display size for the preview** · Effort: M · Raised by: Perf, Platform
What: All 253 art files are raw PNG/JPG; 50 are exactly 2550×3300. A lion rank-1 certificate pulls 3.83 MB,
warrior rank 9 pulls 4.60 MB. Measured with ImageMagick on this corpus: `warrior/symbol_9.png` 2.50 → 0.26 MB
(−90%), `rose/border.png` 2.84 → 0.59 (−79%), PNG corpus −67%. Give `Scroll::ArtBase()`
(`class.Scroll.php:403`) a size argument so the composer's ~600px preview stage stops downloading 300 dpi
plates while only `render`/`compose` take the masters.
Why: today every order change in the composer costs ~4 MB for a postcard-sized picture, and a scribe on venue
wifi sees a blank page.
Watch: blend modes are luminance-sensitive — validate WebP quality against `GROUND_WASHES`/`BORDER_STYLES`,
not by eye; consider lossless for the line-art border layer. The masters+WebP pipeline is on
`feature/high-res-images`, not merged here.

**[37] Asset delivery: immutable caching, and one latent time bomb** · Effort: S · Raised by: Perf
What: `nginx.ork3.config:65-67` serves art with `Cache-Control: public, max-age=300, must-revalidate`
(verified live), and the regex omits `jpeg`, `svg` and `webp` entirely — `squiggly-top.svg` comes back with
no `Cache-Control` at all. CSS/JS get `?v=filemtime()`; art gets nothing. Separately,
`Model_Scrollery::heraldry_url():750` calls `GetHeraldryUrl` with **no `Size`**, which will silently start
delivering a 256px thumbnail into a 300 dpi certificate the day the renditions branch merges — pass
`'Size'=>'master'` now.
Why: every print run more than five minutes stale issues ~25 blocking conditional requests before the first
pixel, and officers print in bursts on event day from park wifi.
Watch: stale art after a redeploy — hence a `?v=` from a build manifest as the escape hatch.

**[38] Make the render run cheap** · Effort: M · Raised by: Perf, DB
What: `render()` loops `scroll_for_awards_id()` per id; each call runs `AwardsForPlayer()` **and**
`GetPlayer()` — which itself runs two `GetDues()` passes, an attendance query and a heraldry lookup. At 200
ids that is ~400+ domain queries against a 391k-row table before a byte of HTML. Add an `AwardsForIds(array)`
batch plus one `GetPlayers()` by the resulting mundane-id set (the scroll needs a persona and a pronoun, not
a profile), and cache the composed markup keyed on `awards_id + variant + recipe-hash` — `Scroll::Render()`
is a pure function of the award row. Add composite `(at_kingdom_id,date)` and `(at_park_id,date)` indexes,
which turn bulk's `Using temporary; Using filesort` into a narrow range scan.
Why: this is item 4's fix scaled to the whole run.
Watch: **never cache `viewer_may_print()` or the bulk scope predicate** — that surface already leaked
cross-tenant once. Cache per *certificate*, never per page (a 200-cert page exceeds memcached's 1 MB item
limit and fails silently). Include recipe and pronoun override in the key or reprints diverge.

**[39] Palette changes should not cost a network round trip** · Effort: S · Raised by: Frontend
What: `scrollery.js:513-516` groups the palette with ground/border/rankSet and calls `reload()` — a POST that
re-runs `BuildScroll()` and re-serialises the ~98 KB border SVG. But `_border.tpl:28` writes the two custom
properties as an inline style on the border `<svg>` and nothing else consumes them: set them directly, the way
`applyHeraldry()` already does.
Why: the colour pair is the control an officer exercises most and is currently the slowest — instant feedback
is what makes a design space explorable.
Watch: pairs with item 7. Fall back to `reload()` if the element is absent.

**[40] Bleed, trim, and multi-up** · Effort: M · Raised by: Graphic
What: `@page{size:letter;margin:0.125in}` with `.scroll-page` keeping `aspect-ratio:8.5/11` renders the design
at 8.25×10.68in — and most consumer inkjets have a 0.16-0.25in hard unprintable margin, so they shrink-to-fit
a *second* time with asymmetric margins the design never anticipated. Relax the default to `0.25in` (the art
is already inset, nothing is lost). Add `?trim=1` for print shops (`@page{size:9in 11.5in;margin:0}`, 0.125in
bleed, four L crop marks) and `?up=2|4` for bulk, which the 2550×3300 viewBox scales into for free.
Why: kingdoms with a budget send Crown-level scrolls to a real printer, and today the shop trims into the
artwork. Multi-up is a 50-75% paper and ink saving on the first-level ladder awards a court hands out by the
dozen.
Watch: crop marks must sit outside `.scroll-page`'s `page-break-inside:avoid`; at 4-up the type is 4.25×5.5in,
so pair with item 2's autofit and raise the floor.

**[41] The render route is a page, not a seam** · Effort: M · Raised by: DB, Perf
What: `render()`/`compose()` re-check against `$this->session->user_id`, so "shareable" means "to someone
holding that officer's cookie" — a headless renderer has no way to authenticate. Mint a short-lived signed
token (`&t=hmac(awards|viewerId|exp)`) from the page that already passed the check, accepted **in place of**
the session; add a `Scrollery/manifest` GET returning `BuildScroll()` output minus markup; and set
`Cache-Control: private, no-store` on `render_bare()`, which currently sets only Content-Type.
Why: §2.5 parks server-side PDF on "the bare render route is already the seam". It isn't yet — the auth model
is the missing half, and it is the cheap half. See §4.7.
Watch: scope the HMAC to the exact id list so it cannot be widened; decide expiry and single-use semantics.

**[42] The module's two raw queries sit in `orkui/model/`** · Effort: M · Raised by: Platform
What: `model.Scrollery.php` is the only file under `orkui/model/` (1 of 33) that references `DB_PREFIX` or
reaches `Ork3::$Lib->player->db` — `bulkSqlForTest()`, `eventsWithPrintableAwardsSqlForTest()`, `db_query()`,
`event_rows()`, plus a hand-rolled SQLSTATE reader. `bin/check-layering.sh` would flag it, but it lives only on
`feature/front-door`, so the gate silently no-ops here — and the 17 Scroll tests run in no CI at all.
Why: the project's own architecture rule, and the enforcement that would have caught it fails open.
Watch: the `$DB->Clear()`-then-manual-`Next()` shape must survive the move verbatim; the `…ForTest` seams move
with the statements.

---

### Tier 5 — Reach & adoption

**[43] The copy still describes phase 1** · Effort: S · Raised by: Adoption, Frontend
What: `Scrollery_index.tpl:56` still reads "Artwork exists for the ladder orders and the Order of the Flame"
— verbatim, in the file the in-flight work just rewrote — and `Scrollery_mine.tpl` adds "Awards without
artwork are not listed here". Both untrue since phase 2. While there, state the naming rule the module
correctly implements: `ork_awards` stores no persona and there is no persona-history table, so a reprint
necessarily prints the recipient's *current* name — that is the inclusive default and worth saying out loud.
Why: the stale line actively tells a player their Knighthood cannot be printed, and it sits on the page
carrying the Valory Fox credit — which is a licence obligation.
Watch: none.

**[44] The module ships invisible** · Effort: S · Raised by: Platform, Adoption
What: A repo-wide grep finds no link to bare `Scrollery`. No What's New entry (`whats_new_content.php` is
still on 3.5.5 Hydra). No `docs_modal.tpl` section. No `.kn-help-btn` in the Certificates groups
(`Kingdomnew_index.tpl:930`, `Parknew_index.tpl:1309`). No avatar-dropdown item, so a player has no path from
any page. And `controller.Scrollery.php` has no constructor, so every page shows a stale kingdom/park
breadcrumb.
Why: the ORK's only broadcast channel to 27 kingdoms is unused, and the landing page — the module's own
explanation of itself — is orphaned.
Watch: ship the announcement **with**, not before, items 10, 11 and 43 — an announcement whose audience
immediately hits the auto-print and select-all defaults is worse than none.

**[45] Guards, so none of this silently regresses** · Effort: S/M · Raised by: A11y, Adoption
What: Three cheap mechanical checks. (a) A unit test that loads each `grounds` entry, applies its
`GROUND_WASHES` blend and asserts the composited text-box luminance sits on the correct side of
`Scroll::DARK_GROUNDS` — the hand-maintained list is accurate **today** (see §4.5) and nothing keeps it right.
(b) A CI check that every `src` referenced by an order partial exists, so a rebase cannot silently orphan art.
(c) A written, falsifiable upstreaming gate — ≥3 kingdoms have printed, ≥1 run of ≥10 certificates, zero
unresolved rendering defects for 30 days — two of which the print log supplies.
Why: the real drift risk is not the PHP, it is that upstream refactors `class.Award.php` or
`AwardsForPlayer()` and 19 partials break in a fork nobody runs CI on.
Watch: (a) needs GD/Imagick in CI, or precompute luminances into a checked-in fixture.

**[46] Small honesty fixes on the recipient's own data** · Effort: S · Raised by: A11y
What: (a) `Scrollery_create.tpl` renders `list="sc-pronoun-list"` only `if ($Pronouns)`, and
`Controller_Scrollery::create()` **never sets `Pronouns`** (verified — the key appears nowhere in the
controller). The datalist is dead code; the field is a bare text box. One line fixes it, backed by the 12
`ork_pronoun.possessive` values. (b) There is an asymmetry underneath: `possessive_pronoun()`
(`model.Scrollery.php:863-874`) falls straight to `'their'` when `PossessiveFromFreetext` misses, while
`ResolvePronounOverride` has a post-slash fallback — so `ze/zir` on a *profile* becomes "their", but the same
string typed by an officer becomes "zir". Neither matches `she / her` or `He/Him.` (c) Carry
`m.suspended`/`m.active` through the bulk query and mark those rows with the chip the autocomplete already
renders, left selected by default.
Why: the pronoun override exists precisely so a player's pronoun is right on their keepsake, and the gendered
surface area of the entire certificate is exactly one word.
Watch: normalise whitespace and trailing punctuation before matching; cover in `ScrollPronounTest`.

---

## 4. Disagreements, adjudicated

**4.1 — Storage: recipes vs a print log vs per-kingdom defaults.**
Three lenses proposed three different things. My call, under one rule: **store events in the DB, store taste
in code.**
- *Print log* — **yes** (item 14). It records an event, not a preference, and nothing else can answer "has
  this player already had a scroll?"
- *Per-award fixed recipes for Knighthoods* — **yes, but as a code constant** in `scroll-recipes.php`
  (item 20), not a table. A recipe is five closed-vocabulary tokens that `NormaliseRecipe()` already validates;
  which recipe a Knighthood gets is a catalogue fact that should be versioned with the art and reviewed in a
  diff, not editable per-kingdom at 11pm.
- *Per-kingdom saved defaults* — **no, not now.** §2.4 is CLOSED with data behind it (0 of 36
  `ork_kingdom_design` rows carry a colour), and presets (item 17) serve the same need — "give me something
  good without deciding" — for one file and no migration. Revisit only if free title (item 32) ships and
  creates a real per-org identity need.

**4.2 — The Zodiac month.** The brief says DEFERRED PERMANENTLY; the Amtgard lens found the column exists.
**Verified: `ork_awards.zodiac_month tinyint(2) NOT NULL DEFAULT 0` is in the shared DB, with 6 rows > 0.**
My call: **allow the read.** The deferral is on *deriving* the month from the award date and on ranks 11-12 —
`Scroll::ExpandFlavour()` already accepts `$context['month']` and always takes the else branch, so passing a
populated column through is not deriving anything. Guard on `> 0` so it is a no-op either side of the owning
branch's merge, and claim the read only: ranks 11-12 and the entry UI stay with that branch. Fix item 9's
`$ordinals` silent-empty at the same time, since that branch is what will trip it.

**4.3 — Bulk cap: 200 vs 25.** Not a conflict — two different limits on two different things, and the panel
conflated them into one number. 200 is a *query-population* cap, and it is applied in the wrong place (after
an alphabetical sort, before the art filter) so it makes rows unreachable. 25 is a *raster-memory* cap on one
browser document. Both are right: keep 200 as the server-side hard guard, page the query so nothing is
unreachable, and chunk the browser run at ~25. One item (26) covers both.

**4.4 — Masterhoods printing their ladder's art.** Phase 2 decided against it; the Amtgard lens says the
premise was an Arcanyst limitation, not an Amtgard fact. **I side with the Amtgard lens** (item 19). The
spec's stated reason — "no ladder gives its Master tier the ladder's own artwork" — is an assertion about
Arcanyst's catalogue, not about how Amtgard understands a Master Rose. `Award::GetLadderMasterMap()` is
already the canonical mapping, so this is a fourth branch in one function, and it moves 3,195 grants from
beige generic to correct with zero new art. That is a large enough payoff to reverse a decision whose
justification does not survive inspection.

**4.5 — Ground contrast.** Resolved in the accessibility lens's favour, by measurement. It sampled the text
box of every ground and computed WCAG contrast: cyanotype 1.88:1 (correctly flipped to 11.15:1 white), forge
and arcane composite through their washes to 12.2:1 and 12.4:1 (correctly *not* dark), everything else
11.3-15.0:1. **`Scroll::DARK_GROUNDS` is accurate today** — commit `1e19545d` already fixed what the graphic
lens assumed was still broken. What is left is durability (item 45a) and `rosewash`, which is a separate defect
(item 7b).

**4.6 — Preset naming.** Four lenses independently proposed presets with four different name sets. My call:
take the *manuscript* lens's naming discipline (Insular, Gothic, White-vine, Architectural, Celestial, Forge —
organising ideas, honestly labelled as modern illustration rather than period facsimile) and the
*accessibility* lens's requirement that each carries one written descriptive sentence. One item, one file, one
review pass — not four competing chip rows.

**4.7 — The PDF path.** The DB lens wants a signed token and a manifest; the perf lens wants a puppeteer
sidecar. Compatible and *ordered*: the token and manifest (item 41) are cheap, useful on their own, and are the
half that is actually missing. The sidecar (a new runtime in the deploy) stays PARKED until the print log shows
someone running bulk for real. The perf lens also settled the client-side question definitively —
`html2canvas` has never supported `mix-blend-mode` and `satori` supports neither blends nor SVG filters, so
every client-side path would print a *different* certificate. That is now closed, not open.

**4.8 — Preview vs one-click.** The adoption lens says drop `print=1` from all four call sites; the frontend
lens says add a "View" link beside every Print. My call: **both, differentiated by stake.** Bulk and create go
through a preview surface, because the paper commitment is large and irreversible. Single-row prints from
`mine` and the profile keep one-click *and* gain a View link — one page of card stock does not justify an
extra click, and a player who wants to look first now can.

**4.9 — Scope of the unattributed fix.** The DB lens proposes falling back to `ork_awards.park_id/kingdom_id`
(the recipient's home at grant time); the Amtgard lens proposes `kingdomaward_id → ork_kingdomaward.kingdom_id`
(the catalogue's kingdom). I have deliberately **not** adjudicated this one — it is a real authorisation
widening over 120,284 verified rows and it is the maintainer's call, not a synthesis call. Item 12
(AUTH_ADMIN + honest denial) is separable, is not a widening, and should ship regardless. Item 13 carries the
decision.

---

## 5. If you only do three things

**1 — Ship the trust bundle: items 1, 6, 10, 11, 12, 43.**
Every one is S, they all live in the same four files, and they are the difference between a tool an officer
trusts with card stock and one they try once. Today the module can print an award that was revoked, print
before anyone has seen the page, pre-check 200 rows, deny a Kingdom Regent on their first click, redirect one
award in sixteen to a blank page, and tell a player their Knighthood cannot be printed. None of that is hard;
all of it is embarrassing. This is under a day.

**2 — Fix what the type does: items 2 and 3.**
The certificate *is* the product, and both of its failure modes are invisible until the paper is out of the
printer — a recipient's name silently trimmed by `overflow:hidden`, and a document that prints in Times
because `load` beat `document.fonts.ready`. 689 personas are long enough to hit the first; every certificate
is exposed to the second. Do these second because they need real measurement work (the autofit pass) that the
trust bundle does not, and because the trust bundle is what makes anyone see them.

**3 — Pay down items 4 and 5.**
Item 4 is not a Scrollery problem any more: `Controller_Award::handle_success()` runs that 3,076-row query on
**every award grant in the ORK**, so the module made the platform's hottest officer workflow measurably slower.
It is a one-`if` fix. Item 5 is the flagship use case failing at exactly the scale it exists for — a
495-award coronation court silently becomes the alphabetically-first 200. Fix the regression you introduced,
then fix the thing bulk was built for.

*Fourth, if there is room: item 14, the print log.* It is the prerequisite for the scribe workflow, the Court
Planner handshake, the utilization report and the upstreaming gate — four features waiting on one table. Land
it with item 44 so the announcement's audience is measurable.

---

## 6. Explicitly not recommended

- **A4 / non-Letter sizing.** Every active park in `ork_park` is US or Canada; both use Letter. It would double
  the `@page` matrix, the multi-up grid and trim mode for nobody, and is a cheap `@page size` swap plus a scale
  factor if it ever becomes real.
- **Per-kingdom certificate theming, and per-kingdom name variants.** §0 CLOSED with data behind both (0 of 36
  `ork_kingdom_design` rows carry a colour; `ork_kingdomaward.name` already is per-kingdom). Nothing in the
  panel is a new argument.
- **Deriving the Zodiac month from the award date, or touching ranks 11-12.** Stays deferred permanently and
  stays with the owning branch. Only the *column read* is unblocked — see §4.2.
- **Client-side PDF** (`html2canvas`, `satori`, `foreignObject`→canvas). Proven not to preserve
  `mix-blend-mode`; it would silently produce a different certificate. Closed, not deferred.
- **A server-side PDF sidecar, now.** Correctly PARKED. It is a new runtime in the deploy; wait until the print
  log shows a real bulk run. The token and manifest (item 41) are the part worth building first.
- **More composer vocabulary** — a 13th ground, an 11th border, a 17th palette. The four slots already reach
  7,680 combinations serving ~11% of volume; presets, not more slots. This is the gold-plating.
- **Per-kingdom saved recipe defaults.** See §4.1 — presets solve the same problem without a table, and free
  title is the use case that would justify revisiting it.
- **Auto-filling the reigning monarch on the signature line.** `ork_officer_history` has 11 populated
  `start_date`s out of 2,514, all a backfill artefact sharing one date. The ORK can name today's monarch and
  nothing about who reigned when a 2009 award was granted. Print the line blank (item 22).
- **The recipient's own device as a second heraldic slot.** Reaches roughly one recipient in ten (4,778 players
  with `has_heraldry=1`), and needs the same dark-ground `#OWL_edge-detect` treatment as the issuer device or it
  punches a white box through `cyanotype`. Nice idea, wrong order — revisit after presets.
- **Officer-position scrolls behind a per-kingdom config flag.** `Scroll::IsOfficerPosition()` is deliberately
  one choke point that fails closed; a flag to widen it adds a second predicate and a config surface for a use
  case nobody has asked for. Keep the exclusion.
- **Trimming the CRM bundle for the composer page.** The create page pulls `revised.css` (437 KB) and
  `revised.js` (950 KB) for one autocomplete and `awInitPicker` — real, but a repo-wide project. The perf lens's
  own verdict was "note it, don't do it here", and that is right.
