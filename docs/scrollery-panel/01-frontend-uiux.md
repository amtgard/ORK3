# Panel report — Front-end Design & UI/UX

## Summary
The module is unusually well-built for a first pass — it wears the shared `.rp-*` shell honestly, the preview loop is correctly split into "what needs the server" vs "what is a DOM write", and the copy is written for a human. The problems are almost all FLOW problems rather than craft problems: the landing page can send a real officer to a denial page, the user-facing copy still describes phase 1 while the custom scroll has quietly made almost every award printable, and the composer presents 7,680 combinations as four collapsed dropdowns with no way to browse, reset or be surprised. The bulk door is honest about its 200-row cap but gives the officer no control that would actually get past it. Nobody on any door ever sees the certificate before the OS print dialog fires.

## Findings

- **[BUG] The landing page sends kingdom-only officers to a denial page**
  - Now: `Scrollery_index.tpl:14` builds `$officerScope = $parkId>0 ? 'park/'.$parkId : (kingdom…)` from the viewer's HOME PARK in session. `viewer_is_officer()` (`controller.Scrollery.php:195`) returns true if EITHER park or kingdom authority holds, but the door only ever links to the park. A Kingdom Regent/PM/Monarch who is not also a park officer sees both doors, clicks one, and `may_issue_for(…,'park',…)` at `:57`/`:90` fails -> `Scrollery_denied.tpl`.
  - Proposal: compute door targets from the same authority check that gated them; build the list of scopes the viewer actually holds and render a scope chooser when there is more than one. Add the same scope chip as a SWITCHER in the `rp-header-scope` of create and bulk.
  - Why: the most senior officers in a kingdom — exactly the people who print scrolls before Coronation — hit a permission wall on the first click.
  - Effort: S — Risk: none; the authority calls already exist in the controller.

- **The module's copy still describes phase 1, and `Scrollery/mine` has silently become an unbounded list**
  - Now: `Scroll::OrderForAwardRow()` (`class.Scroll.php:372-395`) falls through to `custom` for any non-officer award, so `HasArt()` is true for nearly everything. But `Scrollery_index.tpl:56` still says "Artwork exists for the ladder orders and the Order of the Flame", and `Scrollery_mine.tpl:65-66` repeats it plus "Awards without artwork are not listed here". `printable_awards_for_player()` returns every one into an unfiltered, unsorted, unpaginated table (`Scrollery_mine.tpl:76-110`). Measured: max 278 non-officer awards on one player; **4,523 players over 25 and 1,045 over 60**.
  - Proposal: rewrite the two lede paragraphs. Give `mine` a text filter, sort by date/order, checkboxes with a single **Print selected** (render already takes `awards=1,2,3`), and a Design column saying "Order artwork" vs "Generic". Also: `mine` never sets `&variant=`, so an Owl or Battle reprint can only ever print the first of its 3/2 variants even though `render()` reads the parameter.
  - Why: the copy is now actively wrong, and it fronts the player-facing door — widest audience, least tolerance for a wall of rows.
  - Effort: M — Risk: none.

- **The composer is a form over a 7,680-cell space with no way in**
  - Now: `Scrollery_create.tpl:349-402`. The card is `display:none` until the award resolves to `custom` (`scrollery.js:249`), then opens COLLAPSED (`aria-expanded="false"`). Every option is a text label; the only visual is a 30x10px two-leg swatch (`:379`). Per-slider reset for heraldry (`:431`) but **no reset for the design**, no presets, no randomiser.
  - Proposal: (1) **Presets** — six to eight named recipes ("Ivory & knot", "Midnight", "Forge") as clickable thumbnails; a `presets` key in `scroll-recipes.php` plus a chip row. (2) **Surprise me** — randomise a legal combination; the fastest way to teach an officer what the space contains. (3) **Reset design** restoring `Scroll::DEFAULT_RECIPE`. If budget allows, replace ground/border selects with a thumbnail grid (each ground is already a shipped 2550x3300 JPEG).
  - Why: a novice officer sees a collapsed grey bar labelled "Scroll design" and never opens it; the entire phase-2 investment goes unused.
  - Effort: M — Risk: thumbnails want derived images, not full-size JPEGs.

- **[BUG] "Border colours" does nothing for 8 of the 10 borders, but still shows a swatch and still costs a round trip**
  - Now: `custom.tpl:44-46` — `$knot = border==='knot' || $band`, and `_border.tpl` (the only consumer of `--primary-color`/`--secondary-color`, `_border.tpl:28`) renders only when `$knot`. For `crown,hunter,hydra,lion,mask,owl,rose,smith` the raster frame is drawn and the palette is inert. `syncSwatch()` (`scrollery.js:283`) paints the swatch unconditionally and `scrollery.js:513-516` fires a full `reload()` on palette change regardless.
  - Proposal: the page already has the right pattern — `rankSetFd.classList.toggle('is-inert', rank<1)` at `scrollery.js:276` plus a line in `#sc-recipe-note`. Do that for the palette when the border is a raster frame, driven off a `data-knot` attribute stamped from `Scroll::BORDER_STYLES` rather than a second opinion in JS.
  - Why: an officer changes the colour pair, waits for a reload, sees no difference. That reads as broken, not as a constraint.
  - Effort: S — Risk: the knot/frame predicate must come from `Scroll`, not be restated in the template.

- **Palette changes should not cost a network round trip**
  - Now: `scrollery.js:513-516` groups `paletteEl` with ground/border/rankSet and calls `reload()` — a POST that re-runs `BuildScroll()` and re-serialises the ~98KB border SVG. But `_border.tpl:28` writes the two custom properties as an inline style on the border `<svg>`; nothing else consumes them.
  - Proposal: on palette change, query that element and set the two properties directly — the pattern `applyHeraldry()` already uses (`scrollery.js:418`). Fall back to `reload()` if absent.
  - Why: the colour pair is the control an officer exercises most and is currently the slowest. Instant feedback is what makes a design space explorable.
  - Effort: S — Risk: value already travels as one `primary|secondary` pair so it cannot desync from `NormaliseRecipe()`.

- **Bulk print's advice is not actionable from the bulk page**
  - Now: `Scrollery_bulk.tpl:169-176` says "narrow by park instead"; the sidebar (`:106-141`) offers event, from, to, and an event-id fallback. No park, order or recipient filter, and a hard `limit 200+1` (`model.Scrollery.php:383`) with no offset. **24 kingdom-event pairs over 200** in live data.
  - Proposal: add a **Park** select to the kingdom-scoped sidebar (the officer already has kingdom authority over every park in it) and an **Order** filter. Add `&page=N` offset paging so the banner says "showing 1-200 of 391 — next 191". In the table add Select none, shift-click range selection, and a Design column.
  - Why: an event with 391 awards is the exact case bulk exists for, and it is the one case bulk cannot serve.
  - Effort: M — Risk: the scope predicate must stay on `awards.at_park_id`/`at_kingdom_id` — a park filter narrows WITHIN it, never replaces it.

- **[BUG] Two style regressions: the denial page loses the heading reset, and dark-mode error alerts lose their red**
  - Now: (a) `Scrollery_denied.tpl:8` links ONLY `revised.css`. The `.adm-page-header h1` pill-box reset lives in `scrollery.css:75-87`, which the denial page never loads; `revised.css:6488` carries only `margin-bottom`. `orkui.css:775` therefore gives the "Scrollery" heading its grey pill box in BOTH themes. (b) `Scrollery_bulk.tpl:53` sets `.sc-alert-error{border-left-color:#c53030}`, then `:60` sets `html[data-theme="dark"] .sc-alert{border-color:#4a5568}` — higher specificity, later, and a SHORTHAND that resets all four sides. In dark mode an error alert is visually identical to a status alert.
  - Proposal: link `scrollery.css` from the denial page (or move the heading reset into `revised.css` where `.adm-page-header` lives), and add an explicit `html[data-theme="dark"] .sc-alert-error{border-left-color:#fc8181}`. Verify by computed style.
  - Why: both are exactly the failure mode the project rule warns about — a local class rule losing to a global element rule, and a shorthand quietly eating a longhand.
  - Effort: S — Risk: none.

- **Every dead end in the module is a silent redirect**
  - Now: `controller.Scrollery.php:233-236` (render with nothing printable -> `Location: Scrollery`, empty body), `:262` (compose unauthorised), `:285` (compose, no matching choice). `Scrollery_denied.tpl` is 17 lines, the only page in the module not on the `.rp-*` shell, one link back. Deferred §3.12c names the real case: awards_id 13/119/120/3575 carry both scope ids 0, so `viewer_may_print()` denies everyone but the recipient — including a global admin — and the player gets a blank redirect from a link on their own profile.
  - Proposal: route all three to `Scrollery_denied.tpl` with a specific `$Error`: "This award is not attributed to a park or kingdom, so no issuer can be printed on the certificate." Rebuild `denied.tpl` on `rp-root`/`rp-header`/`rp-context` like its siblings and give it a next action. Add the AUTH_ADMIN branch §3.12c recommends.
  - Why: a redirect with an empty body is the single most confusing thing a web app can do — the user assumes they clicked wrong.
  - Effort: S — Risk: changes only the message, not the gate (plus the deliberate admin widening).

- **Nobody ever sees a certificate before the OS print dialog — and on a phone the preview sits below the entire form**
  - Now: every print path appends `&print=1` and `Scrollery_render.tpl:53-55` fires `window.print()` on load (`Scrollery_mine.tpl:102`, `Scrollery_bulk.tpl:290`, `Playernew_index.tpl:2230`, `scrollery.js:573`). No non-auto-print entry to render/compose anywhere in the UI, and the bare page has no on-page Print button, so dismissing the dialog leaves a dead page. On the composer `.sc-preview` drops `position:sticky` below 1000px (`Scrollery_create.tpl:185-190`) and `.rp-table-area` follows `.rp-sidebar` in DOM order (`:222`,`:442`) — on a phone the preview is five collapsed cards and ~15 fields down, so live preview is effectively off.
  - Proposal: (a) a **View** link beside every Print — same URL without `print=1`. (b) a small no-print toolbar on `Scrollery_render.tpl` (Print / Back), hidden under `@media print`. (c) below 1000px move the preview ABOVE the sidebar (flex `order`) or collapse to a sticky 90px strip that expands on tap.
  - Why: "preview then print" is the mental model; the module currently only offers "print and hope".
  - Effort: S (a,b) / M (c) — Risk: verify the toolbar is genuinely absent from print output.

- **A 200-scroll bulk run ships ~20 MB of markup and 6,400 duplicate SVG ids**
  - Now: `_border.tpl` is 97,882 bytes of inline SVG with 32 `<symbol>` defs and its own `<style>` block, emitted ONCE PER CERTIFICATE that uses the knot border — every `custom` scroll, plus griffon, battle, and dragon twice (deferred §3.3). At the 200 cap: ~20MB of HTML in one document, 6,400 duplicate ids, 200 copies of a global `<style>`. The render page shows plain white until `load`, then a print dialog.
  - Proposal: hoist the defs/symbol block out of `_border.tpl` into `_filters.tpl` (already emitted exactly once per page, `Scrollery_render.tpl:34`), leaving each border to emit only its `<use>` elements and its own colour pair. Move the inline `<style>` there too. Collapses ~19.5MB to ~100KB + per-certificate `<use>` markup and makes id uniqueness TRUE rather than "benign because the geometry matches". Add a "Preparing N certificates…" state that clears on `load`.
  - Why: the difference between bulk print being usable at an event and being a tab that hangs.
  - Effort: M — Risk: the griffon `Deco-Special` override (`scrollery.css:362-368`) keys on `[href="#Border-Deco-Special-Griffon"]` on the `<use>` and survives the move, but re-run the asset/render tests and confirm `<use>` fill classes still resolve `var()` from the referencing tree.

- **~90 of the 175 lines of inline CSS across the four doors are duplicated, and some belongs to the shared shell**
  - Now: inline `<style>` blocks of 24/26/77/48 lines in `Scrollery_{index,mine,create,bulk}.tpl`. `.sc-wrap` restated four times; `.sc-lede`/`.sc-credit` twice; the whole `.sc-table` th/td/dark/stacked-card group twice (`mine` 26-40, `bulk` 36-73) with a hardcoded `#4a5568` in both; `.sc-hint` twice; and `@media (max-width:1000px){.rp-body{flex-direction:column} .rp-sidebar,.rp-sidebar-wide{width:100%}}` twice — that last is the `.rp-*` shell's OWN responsive behaviour and has no business in a module template.
  - Proposal: move the two-column stack and a `.rp-table-stacked` phone mode into `reports.css`. Move the remaining `.sc-*` chrome into a new `style/scrollery-ui.css` linked by all four doors, keeping `scrollery.css` as the certificate sheet it says it is — that split is real, since index/mine/bulk deliberately do not load the certificate CSS. Replace `#4a5568` with existing `--rp-*`/`--ork-*` tokens.
  - Why: four copies of a table's dark-mode border is exactly how the module's fifth page gets one of them wrong.
  - Effort: S — Risk: `reports.css` additions must be additive and scoped to `.rp-body` or an opt-in class.

- **One unguarded `getElementById` can take the whole composer down** — `scrollery.js:550` calls `$('sc-print').addEventListener(...)` with no null check, unlike every other element in the file. It sits ABOVE `awInitPicker()`, `syncRankAndVariant()` and the initial `reload()` (`:606-613`), so if the header button is ever absent the award picker never initialises and the preview never loads. Effort: S, one guard matching the file's own convention at `:54`, `:121`, `:190`.
