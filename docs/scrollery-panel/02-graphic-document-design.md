# Panel report — Graphic & Document Design

## Summary
The type layer is a faithful transliteration of Arcanyst's, and that is both its strength and its central weakness: it inherits a *guessed* text-fitting model (two `calc()` formulas keyed on string length, one floor, no measurement) tuned against one designer's test data, not 183,425 real personas and 391,296 real notes. Against real data that model fails, and it fails *silently* — the certificate's `<svg>` carries `overflow:hidden` (`Scrollery_render.tpl:44`), so an over-long recipient name is not visibly spilled, it is trimmed off the printed page. Print production is a single hardcoded profile (`size: letter; margin: 0.125in`, `print-color-adjust: exact`) with no ink-, paper- or print-shop-aware variants. The composer's aesthetic surface is the opposite problem — 7,680 reachable combinations, most never looked at. Nearly every fix lands in `_text.tpl`, `scrollery.css`, `scroll-recipes.php`; no new artwork required.

## Findings

- **[BUG] Text fitting is length-guessed, never measured — long names are clipped off the page, silently**
  - Now: `_text.tpl:47` sizes recipient as `max(1.36em, calc(2.55em + -0.374em * (L/8)))`. With `.Award{font-size:75pt}` (=100px) it floors at **136px** for any name >= ~32 chars. Content column is 1683px. Real data: `MAX(CHAR_LENGTH(persona))=100`; **689 personas >32 chars, 291 >40**; 71 are >24 chars with NO space (`*Michael_Anthony_Wilder_III`). `.Award{overflow:visible}` but wrapping `<svg style="overflow:hidden">` clips — overflow is LOST not seen. Separately `.Award .Order{font-size:1.5em}` has **no length scaling at all**, and 195 of 4,870 `ork_kingdomaward.name` rows exceed 24 chars, 71 exceed 28 (`Guildmaster of Reeves, Principality`=35). That population becomes printable when the generic certificate ships.
  - Proposal: ~30-line autofit in `Scrollery_render.tpl` + composer `applyText()`, after `document.fonts.ready`, before `window.print()`: binary-search font-size down (8 steps, floor ~0.5em) until `scrollWidth<=clientWidth && scrollHeight<=allotted`. Keep PHP formula as first guess for JS-less render. `text-wrap: balance` on `.AwardeeName`/`.Order`; `overflow-wrap:anywhere` on `.AwardeeName` only.
  - Why: a certificate with the name cut in half is worse than none, and the preview clips identically so the officer cannot tell.
  - Effort: M — Risk: must be idempotent on the 200-page bulk route; gate on `document.fonts.ready`.

- **[BUG] `strlen()` where the data is UTF-8**
  - Now: `_text.tpl:21-26` uses `strlen()` for `$issuerLen`, `$playerLen`, `$reasonLen`. **155 personas are multibyte.**
  - Proposal: `mb_strlen(..., 'UTF-8')` in all three. Also **9 personas contain literal numeric entities** (`&#1042;&#1083;…`) which `scroll_e()` double-escapes into visible gibberish — `html_entity_decode()` before escaping in `Scroll::BuildScroll()`.
  - Why: "Ælfríc Þórsdóttir" prints ~13% smaller than an ASCII name of the same visual length.
  - Effort: S — Risk: entity-decode half needs a test so `&` still escapes.

- **[BUG] Line breaks in a reason collapse into a run-on — `.Multiline` styles nothing**
  - Now: flavour has inline `white-space:pre-line`; reason does not. Both carry `class="… Multiline"` and **`.Multiline` is defined nowhere** in `scrollery.css` — as are `.KeepUnderline`, `.FormFill`, `.Keep`. **230 `ork_awards.note` rows contain `\n`.**
  - Proposal: define `.Award .Multiline{white-space:pre-line}`, drop the inline duplicate, delete/annotate the dead classes. Mirror in `applyText()` (`scrollery.js:390`) which `esc()`s into `innerHTML` and loses newlines in preview.
  - Why: a hand-formatted citation prints as mush and no longer lines up with the ruled underlines.
  - Effort: S — Risk: check a 3-line reason still culls the 6-underline loop correctly.

- **An ink-friendly / bare-paper print mode — highest-value print-production addition**
  - Now: every certificate lays a full-bleed raster ground under everything; `scrollery.css:~600` forces `print-color-adjust:exact` on `.award-template *`. `cyanotype` is a full-page dark navy plate at 8.25x10.68in.
  - Proposal: `?ink=1` on the render route (`controller.Scrollery.php:215`) that suppresses the `.bleed` ground, neutralises `mix-blend-mode:multiply`->`normal` where the ground is gone, exposed as a checkbox on mine/create/bulk. Add `'none'=>''` to `scroll-recipes.php` grounds as a first-class ground.
  - Why: certificates are printed on parchment cardstock. Printing a parchment IMAGE onto parchment PAPER double-textures it and, because of the 0.125in margin, leaves a printed rectangle inset from the sheet edge whose beige never matches the stock. Costs a cartridge per dozen. This is what commit `1e19545d` was circling.
  - Effort: M — Risk: raster borders and heraldry are tuned to multiply INTO parchment; on bare white they look thin. Needs `BORDER_STYLES` (`class.Scroll.php:~110`) to grow a third `paper` tone.

- **Curated presets instead of 7,680 raw slots**
  - Now: `Scrollery_create.tpl:357-397` renders four independent selects: 12 grounds x 10 borders x 16 palettes x 4 rank sets = **7,680 combinations**. Only guardrails are advisory `data-note` strings (`Scrollery_create.tpl:52-61`). Bad pairs reachable and unlabelled: `owl` border (opaque black plate) over `starfield`; `battle` band over `linen`; `Zodiac night` knotwork on `cyanotype` — dark frame on dark ground.
  - Proposal: a `'presets'` block in `scroll-recipes.php` — ~10 named art-directed recipes ("Court Parchment", "Midnight Charter", "Field Linen", "Ember"). Composer leads with preset thumbnails; the four raw slots move into a collapsed "Customise" card. `NormaliseRecipe()` needs no change — a preset IS a recipe.
  - Why: an officer printing three scrolls before court wants four good options, not a colour-theory exercise. Converts an unbounded QA surface into ten reviewable ones.
  - Effort: M — Risk: someone must eyeball and choose the ten. Keep raw slots reachable so shipped URLs don't break.

- **Bleed, trim, and the printer-margin lie**
  - Now: `@page{size:letter;margin:0.125in}` (`scrollery.css:589`); `.scroll-page` keeps `aspect-ratio:8.5/11` in print, so design renders at 8.25x10.68in. Most consumer inkjets have a hard unprintable margin of 0.16-0.25in and respond with shrink-to-fit — a SECOND reduction plus asymmetric margins the design never anticipated.
  - Proposal: (1) relax default to `margin:0.25in` — art is already inset, nothing lost, double-scale stops. (2) `?trim=1` sets `@page{size:9in 11.5in;margin:0}`, scales to 8.75x11.25 (0.125in bleed each side), draws four L crop marks. Print shops need it; consumer printers must never see it.
  - Why: kingdoms with a budget send Crown-level scrolls to a real printer; today the shop trims into the artwork.
  - Effort: M — Risk: crop-mark layer must sit outside `.scroll-page`'s `page-break-inside:avoid`.

- **Multi-up printing for bulk runs**
  - Now: one `.scroll-page` per certificate; `.scroll-page + .scroll-page{page-break-before:always}`. `MAX_PRINT_RUN=200` (`class.Scroll.php:24`) => up to 200 sheets.
  - Proposal: `?up=2|4`. In print wrap pages in a `display:grid` of 2/4 cells, drop the unconditional break to `:nth-child(2n+1)`/`:nth-child(4n+1)`, add hairline trim guides. The `2550x3300` viewBox scales without touching a single layer — exactly what the frame buys.
  - Why: a court hands out dozens of first-level ladder awards; half-sheets are normal for those. 50-75% paper/ink saving.
  - Effort: S-M — Risk: at 4-up type is 4.25x5.5in and formulas were tuned full-size; pair with autofit, bump the floor.

- **No browser guard on a path depending on `foreignObject` + `mix-blend-mode`**
  - Now: every certificate is HTML inside `<foreignObject>` composited with `mix-blend-mode` (`_border.tpl:~24`, `_heraldry.tpl:16`, `custom.tpl`). Nothing anywhere mentions a browser requirement.
  - Proposal: one-line non-blocking banner on mine/create/bulk when UA is not Chromium: "Certificates print correctly in Chrome or Edge; other browsers may drop the layered artwork."
  - Why: Firefox's print path handles `foreignObject` poorly; Safari's blend-mode rasterisation inside it is inconsistent. A player prints a blank/flat-grey certificate and reports "Scrollery is broken."
  - Effort: S — Risk: none. Advisory only, do not gate.

- **Typographic detail the port never got to**
  - Now: no `font-feature-settings`, `font-variant-ligatures`, `text-rendering`, `letter-spacing` or `hanging-punctuation` anywhere. `.Award .Body.Award_Reason` uses `line-height:2em` — an em line-height that compounds badly as the font-size formula shrinks (and does not inherit as a ratio). `.Award .Order` at `1.5em` with default tracking; display-size calligraphic caps want NEGATIVE tracking.
  - Proposal: `line-height:2` unitless; `letter-spacing:-0.01em` on `.Order`/`.AwardeeName`; `font-variant-ligatures:common-ligatures` + `text-rendering:optimizeLegibility` on `.Award`; `font-variant-numeric:oldstyle-nums` on the date if Averia carries it.
  - Why: the difference between "a web page that printed" and "a document".
  - Effort: S — Risk: the `2em`->`2` change alters leading wherever reason font-size differs from `.Award`'s; verify one long and one short reason.

- **Do NOT build A4 — recorded so it is not re-proposed**
  - Every active park in `ork_park` is US or Canada (Texas 40, California 25, … BC 9, Ontario 6, Alberta 5, Manitoba/Sask/Québec 1 each). Both use Letter. Zero active parks outside North America. An A4 variant would double the `@page` matrix, multi-up grid and trim mode for nobody. If it ever becomes real it is a `@page size` swap plus a scale factor — cheap later, wasted now.

- **The composer's Reason textarea is uncapped where the record is `varchar(400)`**
  - Now: `Scrollery_create.tpl:322` is `<textarea id="sc-reason" rows="2">` with no `maxlength`. `ork_awards.note` is `varchar(400)`; longest real note is exactly 400; **3,475 notes >150 chars, 485 >300**. Compose does not persist so nothing truncates.
  - Proposal: `maxlength="400"` with a live counter. At 400 chars the reason formula clamps to its `0.55em` floor and needs ~6 ruled lines — exactly the 6 the underline loop draws in `_text.tpl`. Make that design budget visible rather than implicit.
  - Why: officer pastes a citation, preview looks fine, print silently loses the tail below the last ruled line.
  - Effort: S — Risk: none; pairs with autofit, which is what actually guarantees fit.
