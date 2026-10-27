# Scrollery 150-combination sweep: root-cause findings

Source run: `tools/scroll-sweep/out/combo150/` (seed 20261027). Summary report: `tools/scroll-sweep/out/combo150/REPORT.md`. These are the three read-only root-cause investigations, kept verbatim as the evidence the resolution plan argues from (`docs/superpowers/plans/2026-10-27-scrollery-sweep-resolution.md`).

Owner direction (2026-10-27): resolve the detected issues; verify by regenerating only the samples that prove each fix plus controls, not the full 150.


---

## Root-cause findings, combo150 sweep (A1-A5). Read-only investigation.
Repo: /Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias (branch scrollery). Images examined at 1:1 crops (scratch crops alongside this file).

## A1. Custom Ribbon top ribbon collides with heraldry (centered): c076, c034, c107, c029
Root cause (confirmed by reading code, geometry measured from assets):
- _text.tpl:157-190 swaps only the *src* (Scroll::RIBBON_RESOURCES, class.Scroll.php:173) and adds `sc-ribbon` + filter. Size/position stay the squiggle's: scrollery.css:634 `#TopTextFlourish{width:3.8em;left:-.4em;bottom:.9em}`. There is NO order-specific flourish CSS anywhere (grep: [data-order] rules at css:1210-1336 are unrelated), so the Jovious order places its ribbon with the identical rule. Jovious "looks fine" (c045, c063) only because its heraldry crest is short; a tall shield would collide there too (latent bug, not ribbon-specific).
- Geometry (ink-extents.json): squiggly-top-rose box 556x158 (3.52:1), ink y 0-0.996, but the grid is SPARSE at the bottom (one thin stem). ribbon-top.png box 1242x432 (2.875:1) -> at 3.8em wide it is 1.32em tall (squiggle 1.08em), ink y 0.117-0.914, SOLID body across the full width. Ink top sits 2.065em above the versal span bottom (squiggle 1.98em) and its SOLID bottom edge sits at 1.014em (the squiggle's only sparse stem reaches 0.904em).
- The engine never measures the top ornament against the heraldry or the versal: scrollery-typeset.js:2121-2127 `out.top = inPlace(A.top, true)` (skipArt=true: "one piece with the versal ... judged only against the text"), and textBoxes() (js:1498-1512) explicitly omits the versal. So in Centered, where "Amtgard" sits on its own line directly beneath the heraldry box (centre-top, 50px margin + 420px box; _heraldry.tpl), nothing resolves ribbon-vs-shield. The assumption "art leaves room for the squiggle" does not hold for a 22% taller solid ribbon under a 420px opaque-JPEG shield box (B6 makes it worse: shield sits on a black square).
Proposed fix (two parts):
1. scrollery.css, new rule after #TopTextFlourish: `.Award #TopTextFlourish.sc-ribbon{width:~3.0em;bottom:~1.1em;left:~-.15em}`. Derive: ink h = 0.277*w, ink top = bottom + 1.148*(w/3.3)*0.883. Target ink top <= ~2.0em (the squiggle's envelope that heraldry gap already tolerates) while ink bottom stays above the A cap. Tune numerically with the sweep (A1/A2 share the knob); do not guess final numbers.
2. scrollery-typeset.js placeOrnaments (~line 2121): `out.top = inPlace(A.top, !A.top.classList.contains('sc-ribbon'))` so the ribbon IS judged against artwork (heraldry), taking the existing 0.85/0.70 scale steps. Verify hitsArtwork() includes the .centre-top heraldry img first (not confirmed). Hidden fallback is undesirable for a feature the officer asked for: add a final "nudge down/shrink" step, or accept hide only after 0.70.
Risk: only elements with `.sc-ribbon` affected (class is '' without a ribbon, _text.tpl:163), so every non-ribbon order is byte-identical. Ribbon-with-Classic heading cases shift slightly (c005, c016, c038, c064, c077, c084 etc.): re-diff those. Jovious's own ribbons are NOT `.sc-ribbon` (no custom ribbon) so they are unaffected unless you intentionally want the latent fix (then drop the class condition).
Regression test: Playwright tools/scroll-sweep/tests/ribbon-clearance.spec.ts: load compose for the failing params, read .centre-top img rect and #TopTextFlourish.getBoundingClientRect() plus ink rows (data-sc-ink grid), assert ink-rect/heraldry-rect gap >= 0 and ink-bottom above first-glyph rect of .ScVersal (Range on first text char) top. PHPUnit alternative for static piece: tests/Unit/ScrollRibbonMarkupTest.php asserting the css rule exists and the class is emitted only with customRibbon.
Prove fixed: c076-warrior-r10-park-bmrb (centered), c034-lion-r5-park-bmrb, c107-zodiac-r3-park-bxrb, c029-battle-arcane-r4-park-bxrb.
Controls (must not change): c045-jovious-r5-park-plain, c063 / c046-crown-r0 (centered, no ribbon; c046 has no customRibbon) and c001-jovious-r0-park-bm (no ribbon). Also eyeball c006-griffon-r5-kdom-rb and c122 (centered ribbon, currently clear) for no regress.

## A2. Ribbon touches the drop-cap "A": c099 (centered), c011 (auto)
Root cause: same rule as A1. The ribbon box bottom is `bottom:.9em` of the versal span with a SOLID lower edge at ~1.014em above span bottom (see geometry above); several hands' A rises past that. The squiggle never touched because its bottom is a sparse stem. Versal is omitted from textBoxes() (js:1498) so engine never checks. Fix: same CSS rule as A1 (raise `bottom`, reduce `width` so ink top stays within envelope); engine option: for `.sc-ribbon` add the versal's first-glyph rect (Range of first char, plus ORN_GAP) as an extra box in textBoxes() for the TOP flourish only (the bottom flourish must still be allowed around "mtgard"). Risk and test as A1 (assert ink bottom <= A glyph top - gap across the ten hands in axes.json; parametrize over hands via the existing hand param). Prove: c099-rose-r2-park-bmrb, c011-battle-martial-r0-park-rb. Controls: c142-smith-r2-kdom-rb, c148-flame-r10-park-rb (ribbon, fine), c136-jovious-r10 or c135 (ribbon+auto, check unchanged where clear).

## A3. Custom + Garber rank set: plate vs knot gap (c014; c020 is fine)
Observed (1:1 crops c014_capL/capR): the clip IS applied (custom.tpl:202-205 reuses the owl/garber polygon: x 8.1176/91.8431%, y 6.1818/93.9091%, notch x 38.3922-61.6078% = 979-1571px, y>3099). The knot caps are intact; what the sweep saw as "knot chopped" is the plate's thread (x~910-1000) and the ruler (x~1550-1630) being hard-cut by that rectangular notch right against the knot terminals, leaving a visible rectangle of plate shadow/halo missing around each cap. Cause is geometry not a missing clip: the garber rank 10 (and 9, 8, 7) plates ship art that straddles the knot gap edges. Measured alpha of assets/scrolls/garber/rank_N.png below y=3103 outside the gap: rank 0-6 = 0 px; rank 7: R 10441; rank 8: R 10244; rank 9,10: L 6206 + R 10244 px; flame 1-10 and zodiac 1-10 = 0 px. (Also note garber rank_2.png is 5100x6600, twice the others; scroll_art_attrs gives it a box, harmless here.) Matrix exposure: only c014 (rank 10); c020 (rank 5) is clean.
Fix options, in order: (a) make custom+garber at rank >= 7 incompatible with Custom Border in Scroll::CustomBorderRule (class.Scroll.php:838 block: `if ($recipe['rankSet']==='garber' && $rank >= 7) return null;`), mirrored in the builder (scrollery.js ~853-880 customBorderCompatible reads rule data stamped from CustomBorderRules; add a rankSets rule key in scroll-custom-border.php 'custom' entry, e.g. 'rankSetRanks'=>['garber'=>[7,8,9,10]]) so the checkbox hides with the existing "not compatible" tip; (b) keep compatible but build a per-rank clip that removes the straddling elements entirely (not just trims them), which requires edited art. (a) is cheap and consistent with the existing warrior rank 1/9 precedent. Same rule should be applied to the fixed 'garber' order? garber.tpl is already incompatible with Custom Border (report: garber requests draw no knot) so no.
Risk: only custom order + garber set + knot + rank>=7; those officers lose the Custom Border box for that combo.
Test: tests/Unit/ScrollCustomEligibilityTest.php add dataProvider rows: (custom, garber, rank 6) compatible, (custom, garber, ranks 7-10) not; plus a data-driven guard (GD) that for every (rankSet, rank) the alpha below knot-gap-edges outside the notch is 0 for compatible combos, so a new plate cannot regress. Prove fixed: c014 (becomes incompatible: no knot, expect.compatible=false; update the sweep expectation). Controls: c020-custom-r5-framed-sunburst-park-bm (garber rank 5, keeps knot and clean), c091-custom-r0-forge+smith, c123 (common set).

## A4. Match mode with black/white on dark ground breaks knot: c134
Root cause confirmed: owl cyanotype match pass is `mix-blend-mode:screen` (class.Scroll.php:277-280 'dark'; custom grounds: CustomKnotStyle, class.Scroll.php:348-351). screen(black, ground)=ground, so the black band AND black outlines vanish; only the white band survives, interrupted wherever band 1 crossed over it -> dashes (see c134_tl.png). Other screen-blend match passes: mask pass 1 (screen, opacity .6) exposure: c115, c133 (mask match black/white), minor. Matrix: only c134 is dark+match+black.
Weigh: (1) silent fallback to exact on DARK_GROUNDS: simple, but 'match' on dark is the intended look for ordinary colours (c054, c120-style), and exact black-on-navy is also weak (outline vanishes into the dark ground) though structurally intact. (2) builder hint only: honest, but leaves the officer to fix. RECOMMEND narrow server rule + hint: in Scroll::BuildScroll (class.Scroll.php:2092 area) when a customBorder pass list contains 'mix-blend-mode:screen' and either colour's relative luminance < ~0.08 (colour that screen erases), set effective matchStyle=false and return `customBorder.matchFallback=true` (also in the returned design echoed to the builder as `design.matchStyle` already reflects what was DRAWN, line 2092), then scrollery.js shows a sc-hint under #sc-match-style: "Match style hides very dark colours on this dark background, so the exact colours are drawn." Derive 'screen' from the passes (not a JS list) to follow the repo rule that Scroll decides. White on dark in match is fine, so only the dark colour triggers it.
Risk: affects only dark/screen passes with near-black colours; light grounds untouched (multiply keeps black).
Test: PHPUnit ScrollDesignTest/ScrollCustomBorderRenderTest: owl cyanotype + matchStyle + #000 => matchStyle false + hint flag; owl cyanotype + #1E4FA3 stays true; owl parchment + #000 stays true; Playwright sweep expectation exact-mode knot count 1 and `.sc-knot` style mix-blend-mode normal. Prove: c134-owl-cyanotype-r8-kdom-bm. Controls: c054-owl-cyanotype-r5-park-bmrb (default colours, match kept), c066-owl-parchment-r8, c104-hydra-r6-park-bm (black/white match on light ground, unchanged), c120 (exact on dark, unchanged).

## A5. Near-parchment / white knot and ribbon colours on parchment
Root cause: no validation by design (NormaliseHexColour only checks shape, class.Scroll.php:718); only the outline reads. Cases: c088 c148 c013 c133 c142 c034 (ribbon), c066 c071 (knot pair near-parchment), c011 (white ribbon).
Where ground tone is known: server-side only today. Scrollery_create.tpl:53-60 builds notes from Scroll::DARK_GROUNDS, ground options carry data-note/data-own-border (tpl:725-732), and tests/fixtures/scroll-ground-luminance.json already stores per-ground mean WCAG luminance of the text box (parchment 0.5798, linen 0.6366, jovious 0.5158, ...; only the 12 CUSTOM grounds). The fixed orders' papers are not in it (parchment-family; owl cyanotype variant is dark).
Fix: (1) Stamp `data-ground-lum` on each ground <option> in Scrollery_create.tpl from the fixture (read once via the same loader ScrollGroundContrastTest uses, or promote it to a Scroll::GroundLuminance() constant map), and on each order/variant <option> (extend fixture with per-order paper entries, one-off regen in ScrollGroundContrastTest). (2) scrollery.js: in paintKnot/paintRibbon (js:~740-760) compute WCAG contrast of colors.c1/c2/ribbon vs the selected ground's luminance (custom: ground option; fixed: order/variant option via selected()), threshold 1.5:1 (the knot/ribbon is a graphic, WCAG 3:1 is too strict for decorative art; parchment vs white is ~1.6). (3) Show an existing .sc-hint element with data-tip/hint text under each colour control, e.g. "This colour is very close to the paper (1.2:1); it will read only through its outline." Add `<p class="sc-hint" id="sc-ribbon-contrast" hidden>` next to the hex-err in the $colorControl closure (Scrollery_create.tpl:455-470). Also recompute on order/ground/variant change (syncDesign js:~895).
Match mode caveat: with matchStyle the effective colour is multiplied by the paper, so compute contrast of colour*ground (per-channel multiply) vs ground when match is on, direct otherwise.
Risk: UI only; no server rendering change. Dark-mode: hint uses var(--rp-text-muted) as existing hints do; test both themes.
Test: Playwright tools/scroll-sweep/tests/composer.spec.ts: set #sc-ribbon-color-hex=#F2E6C8 on a parchment order and expect #sc-ribbon-contrast visible; #1E4FA3 hidden; switch variant to owl cyanotype and expect white to be fine and #101820 flagged. PHPUnit: ground-option luminance attribute equals fixture value (Scrollery_create render test) so it cannot drift. Prove: c088, c148, c013, c133, c142, c034, c066, c071, c011 produce the hint (drive the builder with the case params). Controls: c134 and c054 (owl cyanotype: colours fine), c014 (#1E4FA3 ribbon), c029 (blue ribbon): no hint.

---

## Scrollery combo150 root causes: B1, B2, B3, B8 (read-only; nothing in the repo was modified)

Paths are relative to /Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias.
TS = orkui/template/revised-frontend/script/scrollery-typeset.js
AF = orkui/template/revised-frontend/script/scrollery-autofit.js

---------------------------------------------------------------------------------------------------
## B1. Forced Classic with an extreme issuer runs off the right edge

### Root cause: by design, and the design is under-specified
- Spec section 6.5.3 (docs/superpowers/specs/2026-10-25-scrollery-typeset-engine-design.md:515-519) says: "A forced Classic that is infeasible still renders Classic at the floors, with `data-sc-overflow`."
- TS `layoutIssuer` (TS:2053-2066) does exactly that. When forced and `issuerClassic(..., relaxed=false)` is infeasible, it re-solves with `relaxed=true`. `pmin` is then `caps.placeMin`, which is `placeClassic.min * floorScale` = 0.62em (TS:95, 1834-1835, 2037).
- The place is hard-wired to ONE line in Classic (`maxLines: 1`, TS:95 and 1889). The solver cannot wrap it, and it is never shrunk below 0.62em.
- When the line still does not fit, `setRole` flags `data-sc-overflow` and leaves the words at the minimum (TS:1353-1377).
- The only consumer of that flag is the infeasibility test and a CSS-less attribute. Nothing in the stylesheet or the control reacts to it. grep for `sc-overflow` shows only writers. So the overflow is silent.
- Measured in results.json, forced Classic, place at the 62px floor:

| case | place line width | allowed | needed size |
|---|---|---|---|
| c064 | 2366px | 1313px | about 34px (0.34em) |
| c104 | 2358px | 1337px | about 0.35em |
| c106 | 2366px | 1266px | about 0.33em |
| c132 | 1897px | 1313px | about 0.43em |
| c131 | 1668px | 1337px | about 0.50em |

  c131 stays inside the page because the safe margin is left over. Only c064, c104, c106 and c132 land over the frame (`outside` is non-empty).
- Shrinking to a lower floor cannot rescue c064, c104 or c106. 0.34em is about 8pt, illegible, and it breaks the hierarchy rule that the place is at least 0.75 of the name.
- Auto already handles these inputs. c022 has the same issuer and Centered sets it on 2 lines at 104px, cleanly.

### A second bug on the same path: the overflow flag is not trustworthy
- TS:2063 sets `data-sc-overflow` and `res.place.overflow=true` whenever `feasible` is false.
- `feasible` can be false for reasons other than the place's width:
  - the lead does not fit beside the versal (c075, lead 921px vs 797px);
  - the artwork profile is not clear across the column (`clear`, TS:1921-1928).
- Evidence: forced-Classic c050 ("Satyr's Crossing"), c098 ("Ash") and c049/c052 report `place.overflow=true` with the place on one line and no `lineOver`. Those certificates are fine.
- The new fallback must therefore key on the place's measured overflow from the relaxed solve, before line 2063 stamps the flag.

### Options considered
1. Shrink to a lower floor. Only rescues c131 and c091, and is illegible for the rest. Rejected as the whole answer.
2. Wrap the place across 2 lines inside Classic. The Classic place is the right-aligned second row, built around `--sc-place-gutter` and `--sc-place-reserve`. The right flourish stem is reserved in `xR`, and the lead row is `white-space:nowrap`. Two lines would need new geometry, new ornament rules and a new height budget. High risk, and it yields something that is neither Classic nor Centered.
3. Fall back to Centered and say so. Centered is already proven on exactly this input (c022, auto). Honouring "forced" literally buys nothing, because the result is illegible or off the page.

### Recommendation
Hybrid of 1 and 3, a "forced Classic, with a hard floor then honest fallback" rule:
1. Keep today's two attempts (normal, then relaxed to 0.62em).
2. Add a third relaxed attempt with `pmin = 0.50 * floorScale`. That is 12pt, still legible, and rescues c131 and c091-like near misses. Make it a CONST, e.g. `CONST.FORCED_CLASSIC_PLACE_MIN = 0.5`.
3. If the place still overflows at that floor (c064, c104, c106, c132), render Centered via `issuerCentered(ctx, A, caps)` instead.
   - Set `res.fellBack = 'classic'`.
   - Write `data-sc-fallback="classic"` on the `.Award` in `layoutTop`, next to `data-sc-comp` at TS:2340.
   - Keep `data-sc-comp="centered"` truthful: `report.comp = res.comp`.
   - Do NOT fall back just because `feasible` is false (profile or lead reasons). Keep the current render-at-floors behaviour there, but stop mis-flagging the place as overflowing: set `data-sc-overflow` only when `res.place.overflow` was already true.
4. Tell the user in the heading control. Where "Automatic (" labels live:
   - orkui/template/revised-frontend/script/scrollery-hand.js:599-651, `ScrolleryHeading` (`headingLabel`, `bindHeading`).
   - It is wired from orkui/template/revised-frontend/Scrollery_render.tpl:266-282.
   - Options come from `Scroll::Headings()` at system/lib/ork3/class.Scroll.php:625-628.
   - Today `sync()` bails when `select.value !== 'auto'` (hand.js:~633), so a forced value never gets a label.
   - Change: when the value is 'classic', look at `.Award[data-sc-fallback]`. If any is present, set the Classic option's text to `Classic (didn't fit, used Centered)`, or `Classic (didn't fit on N of M)` for bulk pages. Otherwise restore `Classic`. Add `data-sc-fallback` to the observer's `attributeFilter`.
   - Keep the label function pure and exported (like `headingLabel`) so it is unit-testable.
   - The tooltip at Scrollery_render.tpl:~287 could add one sentence: "If Classic can't fit a long name it switches to centred."
5. Update spec 6.5.3 to say so.

### Risk to normal certificates
Very low. The new code runs only when the heading is forced to Classic AND the relaxed solve overflows. Auto never reaches it. A forced-Classic certificate that fits today (c002, c003, c019, c036 and about 15 more) never takes the branch. The only changed render is a certificate that today overflows off the page.

### Regression tests
- Playwright, in tools/scroll-sweep/tests/typeset-dom-synthetic-issuer.spec.ts (which already builds synthetic issuers): force Classic with the 66-character Freehold issuer. Assert all four of these:
  - the place line is inside the column (no `lineOver`, no `outside`);
  - `data-sc-comp="centered"`;
  - `data-sc-fallback="classic"`;
  - a short issuer forced Classic keeps `data-sc-comp="classic"` and has no `data-sc-fallback`.
- A near-miss synthetic (about the c131 length) asserts it stays Classic at 0.5em to 0.62em with no overflow.
- A synthetic forced Classic where only `clear` or the lead is infeasible (c050-like) asserts there is no `data-sc-overflow` on the place and no fallback.
- Pure unit test for the label function (is there a spec for ScrolleryHeading? tests/Unit/ScrollHeadingTest.php covers the PHP allowlist only, so add one in typeset-bulk.spec.ts or composer.spec.ts): `label(['centered'], 'classic', {fallback})`.

### Sweep cases
- Must change (no overflow, Centered, labelled): c064, c104, c106, c132.
- Should stay Classic with no overflow (shrunk to the new floor): c131 (about 0.497em, borderline; Centered is also acceptable, but it must have no `lineOver`) and c091 (a 3% overshoot at 0.62em).
- Controls that must be unchanged (forced Classic that fits): c002, c003, c019, c036. Also c050 and c098 (forced Classic, infeasible only by profile or lead, place fits) must stay classic.
- c075 is expected unchanged: its lead overflows (921 vs 797) but the place is fine. Do not trigger the fallback on lead overflow alone, or document the change if you do.

---------------------------------------------------------------------------------------------------
## B2. c022: reason clipped by about 102px

### Where the reason is fitted
- NOT the top-half engine. The reason is pass 2 of scrollery-autofit.js, `fitReason` (AF:404-457). It binary-searches font-size from base down to `POLICY.reason.minEm` = 0.34em (AF:134-141, 415).
- The reason box is `.Body.Award_Reason`: `min-height:2em`, `overflow:hidden` (scrollery.css:835-838, 858-885). Its height is whatever the flex column leaves it.
- The room is decided earlier by the top-half engine's budget (TS:2151-2315): `topRoom()` is `max(H_TOP_MIN(0.40) * content, content - below)`. `below` is `bottomNeed()`, with the reason counted at its natural height capped at 6 lines (TS:2189-2205).

### Root cause: the budget has only one lever, and an empty name removes it
- The loop at TS:2306 is `for (...; stack > room && fit.name; ...)`. It only ever lowers the NAME's ceiling by 8% per round.
- c022 has `player.tier = 'empty'`, so there is no name text. `setRole` returns null (TS:1304-1305) and `fit.name` is null. The loop never runs. Result: `budget:'floor'` (TS:2315), with nothing tried.
- The empty name is not free space. `_text.tpl:214-218` renders a handwriting blank (writing-line `<img>`) in `.AwardeeName`, whose inline `font-size` is `max(1.36em, 2.55em - 0.374em*(0/8))` = 2.55em. That is why results.json shows the name role at 255px for a name with no text. This blank is the largest rigid element and nothing shrinks it.
- On top of that, the extreme issuer falls to Centered with a 2-line place (about 104px lines) and a lead. That costs about 110-200px more than a Classic one-liner (compare c077, c035, c040 and c114: empty name + long reason all fit, with `clipped = []`).
- Result: the reason box is 164px high against 422px of content. `fitReason` runs out of range at the 0.34em floor (px 34 = 8.16pt), and `markClipped` fires (the clip notice is there, but the officer gets a clipped citation).

### Recommended behaviour
Keep the floor. 8pt is already the smallest type on the certificate, and lower is unreadable. Never-clip by overflow:visible is worse: the citation would print over the flavour line, the signature and the shield, and the outer `<svg overflow:hidden>` would clip other things. Instead "let the reason take space from elsewhere":
1. Give the budget a second lever for the empty name's blank. In `layoutTop`, when `nameEl` exists but has no text (`fit.name` null) and `stack > room`, step the blank's font-size down in 8% rounds, from the template's 2.55em toward `max(1.36em, nameFloor)` (it is an inline style on `.AwardeeName`, which `fitStack` and `layoutIssuer` re-read through the idempotent base).
   - Rough saving: 2.55em to 1.36em is about 119px of font-size times the 1.12 line height, about 130px or more. That is more than the 102px missing, and it also lets `fitReason` grow the citation above the floor.
   - It must restore the base each run: idempotence is a stated invariant (AF:46-53).
2. Optionally, when the budget still ends at `floor` and the award line is not at its minimum, let the same rounds also lower the award's ceiling. That is a bigger change, so treat it as a second step only if the blank lever is not enough in testing. The number needs a measured run; I estimated it, I did not execute it.
3. Keep `markClipped` and the notice as the last resort (already there).

### Risk
Only certificates with an EMPTY name AND `stack > room` change (the loop is entered only then). Blank-name certificates with a short or medium reason have `stack <= room` and are untouched, so there is no change to the blank's size for them. That is the intended behaviour, not a regression. A hand-filled blank stays long enough to write on at 1.36em (that is the `max(1.36em, ...)` floor the template already uses for long names).

### Test
- Playwright in typeset-dom-synthetic.spec.ts: empty player, 416-character reason, extreme Centered issuer. Assert `budget:'ok'` (or reason `data-scroll-clipped` absent) and the reason size above 0.34em. Add the converse: the same with a short reason leaves the blank at 2.55em.
- Unit: idempotence (fit twice, same sizes).

### Sweep cases
- Fixed: c022 (`clipped` must become `[]`, `budget` not `floor`).
- Controls that must be unchanged: c077, c035, c040, c114 (empty name + long reason, already `ok`, reason px 43-53). Also c058 and c131 (long reason, extreme or long names, no clip today).

---------------------------------------------------------------------------------------------------
## B3. Rank word printed twice ("and the Principality of / the Principality of the Crimson Sands")

### Root cause
- IssuerLines only splits a stored phrase when `title === '' && !useThe && !useOf`:
  - PHP: system/lib/ork3/class.ScrollText.php:50-117, split at :75-90.
  - JS twin: orkui/template/revised-frontend/script/scrollery-textmodel.js:53-96, split at :61-71.
- The sweep's cases c047, c058, c065 (plus c108 and c110) pass `issuerTitle='Principality'` and `useThe=useOf=true` WITH `issuerName='The Principality of the Crimson Sands'`. The code takes that to be the officer's deliberate form ("anything an officer has typed or ticked is left exactly as given"), builds the lead "and the Principality of" from the title, and the name still starts with "The Principality of". The article lift then makes `place = "Principality of the Crimson Sands"` with a small "the". Hence the double.
- That input is a harness artifact (the real composer prefills from `IssuerLines()`'s echo, which gives title "Principality" and name "the Crimson Sands"). But it is reachable by hand: an officer who types a title into the Title field while the Name field still holds the whole stored phrase, or via the `&issuerTitle=` URL.

### Can real data trigger it? (read-only SELECTs against `ork` in ork3-php8-db)
- No exact match today. Joining ork_park to ork_parktitle and testing "name starts with [the] <own title> of": 0 rows.
- One near miss: park 218 "Shire of the West" is stored with title "Grand Duchy" (status Retired). It prints "and the Grand Duchy of / Shire of the West". That reads oddly, but it is a data problem, not the duplicate, and a same-title dedupe leaves it alone. I would not add a rule for different rank words.
- All 29 kingdom names are whole phrases with no separate title, so they take the split path and are fine.
- Park titles in ork_parktitle: Barony, Burg, County, Duchy, Freehold, Grand Duchy, Kingdom, Outpost, Shire (plus an "Inactive " that is already handled).
- So the real-world trigger is a future park named e.g. "Barony of Iron Keep" with title Barony. Cheap to guard.

### Proposed rule (identical in PHP and JS)
After the "Inactive"/Barony-floor step and the kingdom split, and before the lead is built:

```
if title !== '' :
    prefix = optional leading "the "/"Þe ", then title (case-insensitive), then " of "
    if name matches ^(?:(?:the|Þe)\s+)?<title>\s+of\s+(.+)$ :   // remainder must be non-empty
        name = remainder          // "The Principality of the Crimson Sands" -> "the Crimson Sands"
```

- Do it whenever a title is present (regardless of flags). With `useOf` off, "and the Principality / Principality of X" would be just as wrong.
- Anchored at the start and requires " of ", so "Grand Duchy of X" with title "Duchy", or "Kingdom Come" with title "Kingdom", are untouched.
- Then the existing article lift gives article "the", place "Crimson Sands", lead "and the Principality of". That is the same as the stored-kingdom path gives.
- The echoed `name` becomes the stripped form, which is fine (`composer` prefill is idempotent: feeding it back is a no-op).
- PHP: `preg_match('/^(?:(?:The|Þe)\s+)?' . preg_quote($title, '/') . '\s+of\s+(.+)$/iu', $name, $m)`.
- JS: `new RegExp('^(?:(?:The|Þe)\\s+)?' + title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s+of\\s+(.+)$', 'i')`.
- Caveat: PHP `/iu` and JS `/i` fold case slightly differently for exotic letters. Titles in real data are ASCII, so fine. Pin Þ in a fixture case.
- The `phrase` output (PHP only) then reads "the Principality of the Crimson Sands" and stays correct.

### Risk
Very low. It changes only inputs where a title is given and the name already starts with that exact title plus " of". Park names without a rank word and all stored-kingdom names are untouched.

### Tests
Add rows to tests/fixtures/scroll-text-model.json (shared by tests/Unit/ScrollTextModelTest.php and tools/scroll-sweep/tests/textmodel.spec.ts):
- title Principality + name "The Principality of the Crimson Sands", flags true: want lead "and the Principality of", article "the", place "Crimson Sands".
- title Barony + name "Barony of Iron Keep", scope park.
- title Duchy + name "Grand Duchy of Wolven Fang": unchanged (place "Grand Duchy of Wolven Fang").
- title Kingdom + name "Kingdom Come": unchanged.
- title Principality + name "Principality of": unchanged (no remainder).

### Sweep cases
- Fixed: c047, c058, c065, c108, c110. Print should read "and the Principality of / Crimson Sands" with a small "the".
- Controls that must be unchanged: c015 ("The Kingdom of the Wetlands", title empty), c018 or c075 ("The Grand Duchy of Wolven Fang", kingdom, empty title), c013 ("The Celestial Kingdom of the Northern Lights...", no split), and a park case with title and a bare name, e.g. c001 (Duchy, "The Marsh") and c010 (Barony, "Främling").

---------------------------------------------------------------------------------------------------
## B8. Unbreakable names and signatories break mid-word

### Name (engine): a deliberate last resort
- TS:855-878 and spec section 6.3.4 (docs/superpowers/specs/2026-10-25-scrollery-typeset-engine-design.md:~363): when nothing fits as written, cut tokens gently first. The passes run in this order: protected span, then separators `_ - / .` (TS:538-543), then camelCase seams (TS:546-553), then `midpointPieces`, which halves at the exact midpoint (TS:555-559). The mid-word cut applies only to a token that cannot sit on any line at the role's minimum size (`tooLong`, TS:861).
- c021 / c079 / c104 "Wilhelminafeatherstonehaughdelamancha" is 37 lowercase letters with no seam, so it reaches pass 4. The result is "Wilhelminafeatherst / onehaughdelamancha", the midpoint, and not even at a syllable.
- Real data barely hits this. Real long unbroken personas usually carry separators ("*Michael_Anthony_Wilder_III"), which pass 2 handles.

### Name recommendation (low-risk)
- Keep the behaviour. Mid-word is the documented last resort and only runs when the alternative is clipping or overflow.
- Two cheap improvements, both confined to pass 4, which only extreme input reaches:
  1. Choose the cut near the midpoint at a vowel-to-consonant boundary (search about +/-15% of the midpoint, prefer after a vowel and before a consonant, avoid leaving a 1-2 letter stub). That avoids "Wilhelminafeatherst|" and gives something like "Wilhelminafeathe|rstonehaugh...". Deterministic, pure, covered by the existing solver tests.
  2. Optionally add a trailing hyphen on the left piece for pass 4 only. A hyphen is typographically correct, but it changes the text the solver measures and the verify step compares (`data-sc-text`, TS:1255) and the text a screen reader or copy reads. It needs measure and verify changes in two places. Not recommended as a first step.
- Do not shrink below `name.min`: shrinking the name to keep one word whole would break the hierarchy (name over award over place) and makes the name smaller than the award.

### Signatory (c139, c122): different mechanism
- The signature `<p data-scroll-text="givenBy">` is NOT set by the engine. It is a fixed 7em box (scrollery.css:1225-1227) with `overflow-wrap:anywhere` (scrollery.css:1249), fitted by AF `fitInline` with the signer ladder (AF:122-133: 1 line at 0.55em, 2 lines at 0.40em, 3 lines at 0.34em).
- "Smiles/Hobi/Can'tCallHimSmileyCanWe?" has no spaces, so the browser breaks it wherever the box edge falls ("Can'tCallHimS / mileyCanWe?" in c139).
- Cheapest fix: give the browser legal break opportunities. In `fitInline` (or once at the top of the signer pass), rebuild the signer's text with `<wbr>` after `/ _ - .` and between lowercase and capital (the same seams the engine uses). `textContent` is unchanged (the ladder's line counting and the print log keep working), `anywhere` stays as the net, and one JS site covers both print and composer preview, so no PHP/JS twin is needed. Idempotent if it regenerates from the stored text.
- Result: "Smiles/Hobi/ / Can't Call Him / Smiley Can We?" style breaks at seams, with a mid-word cut only for a seamless word.
- Low risk: extreme signatories only. Normal signatures contain spaces, so adding `<wbr>` changes nothing for them (they break at spaces first).

### Tests
- Unit (typeset-core.spec.ts): `solveBlock` on a 37-letter lowercase token chooses a vowel-boundary cut, never leaves a 1-2 letter piece, and still falls back to the midpoint when there is no vowel/consonant seam.
- DOM test: the signer p with "A/B/CamelCaseWord" has `<wbr>` after "/" and before capitals; textContent is unchanged; line count is stable across two fits.

### Sweep cases
- Name: c021, c079, c104 (cut at a better boundary; they remain a mid-word cut because the token has no seam).
- Signatory: c139, c122 (and c104, which also has the 36-character signer): the break lands at "/" or a camel seam.
- Controls: a name with a seam (the camel and separator cases are in typeset-core spec) and any case with a spaced signer, e.g. c001 and c065, must be unchanged.

---

## RC3 findings: B4 / B5 / B6 / B7 (read-only investigation, branch scrollery)

Common mechanism (applies to B4 and B7): the free-space profile (assets/scrolls/free-space.json: y0=400, step=25, bands=80, so it covers y 400..2400 only)
is consumed ONLY by the top-half typeset engine (scrollery-typeset.js:938-979 reads data-sc-free). The bottom half (reason, flavour/motto, date, signer)
is plain flex flow in _text.tpl: `.Award` is a column (justify-content:center, scrollery.css:546-584), flavour sits between two spacers (flex-grow .5 / .7),
and `section.SignAndDate` has a per-order min-height (scrollery.css:1404-1480). Nothing in the bottom half knows where plate ink is, except
`liftSignature()` (scrollery-autofit.js:795-820), which lifts ONLY the date/signer block, and only by a per-order constant (`--sc-sign-lift-want`).
The motto is therefore not at a fixed y: measured on the sweep renders it lands anywhere in y~2340..2600 depending on how many reason lines / blank-reason
the certificate has, while the plates are fixed at frame coordinates. Whether they meet is data-dependent AND plate(rank)-dependent.
Docs already say this class is OPEN (docs/scrollery-ink-collision-results.md section 4 "C-over-A ... NOT fixed", and typeset-results 5.3: "bottomNeed does not see art").

## B4 - order art over bottom-half text (five sub-cases)

### B4a battle-arcane glow ring through motto (c110 r9)
- Verdict: inherited from the Arcanyst geometry, but a real (low-medium) defect. battle.tpl has no SignAndDate override (source has none), so default basis 350 is used.
  `battle/arcane/glow.png` is drawn for EVERY rank (battle.tpl:55-57), 2000x1200 bottom-anchored at scale 1, so the ring sits at y~2500-2640 regardless of rank.
  Motto lands on it whenever the motto's y is ~2540-2620 (long reason: c110; c009/c096 clear it, c029/c052/c065 sit above it).
  The black type is still legible (text is the top layer), so it is cosmetic.
- Rank-dependence: glow ring = all ranks 0-10; the hand/symbol varies (symbol top y 2100+226..305, bottom to 2100+891). So ALL arcane ranks collide when the motto falls in that band.
- Root cause: no bottom-half awareness (_text.tpl:226-228 flavour block + spacers; scrollery.css:1214-1222 SignAndDate basis 350).
- Fix (cheap, same mechanism flame/dragon/griffon/hunter already use): add to scrollery.css next to line 1404 
  `.award-template[data-order="battle"] section.SignAndDate { min-height: 600px !important; }` (try 550-650) so the reserve under the motto grows by ~250px and the
  motto rides above the plate top (martial r8-10 top = y2373, glow ring top ~2500). Date/signer stay put (block is align-items:end).
  Alternative (proper, bigger): a `data-sc-bottom` obstacle profile (y 2400..3300) generated by tools/scroll-sweep/profile.spec.ts and a `liftFlavour()` beside liftSignature().
- Risk: the column gets 250px less room, so long reasons shrink via fitReason()/fitColumn() (reason floor 0.55em) or clip; battle has no existing override so every battle cert shifts up.
- Regression check: sweep:scrolls C-over-A and reasonClipped counters for battle; combo150 battle cases; reason clipping banner (autofit reportClipping).
- Proof cases: c110 (arcane r9, long reason), c096 (arcane r10 ring + swirl, short reason). Controls: c029, c052, c065 (arcane, motto already clear: must not get worse / reason not clipped), c009.

### B4b battle-martial spear tip through motto (c094 r5)
- Verdict: same defect class; data-dependent. c094 has an EMPTY reason (short column), so motto drops to y~2525 while martial r5's spear tip is at y2100+344..=2444 (symbol_5 top 344).
  Martial plate tops by rank (frame y = 2100 + top): r8/9/10 2373-2375, r5 2444, r2 2523, r0/4/7 2528, r6 2608, r3 2610, r1 2671. Motto y spans 2340-2550, so r5, r8-10 collide in the upper range (c092 r10 spear tip kisses "Tales of your valorous battles..."), r0/2/4/7 in the lower range.
- Root cause and fix: as B4a (same battle min-height bump covers both variants). Because martial ranks 8-10 reach 2373, 600px reserve moves the motto to ~2100-2300: clear.
- Proof: c094, c092. Controls: c117 (r1, plate far below), c075 (r1), c011 (r0), c021 (r3), c122 (r2).

### B4c crown laurel over "By my hand..." caption (c030, c046; near-miss c060)
- Verdict: DEFECT, but caused by data (signature width), crown-specific. scrollery.css:1444-1447 `.award-template[data-order="crown"] section.SignAndDate > div { width:auto !important; margin:0 1em }`.
  With a short signer ("Bo", c127; or no signer c003, c129) the box is narrow and flush right, caption sits far right. With a wide signature (c030 long printed title; c046 and c060 script/unbreakable)
  the signatory div grows to max-content, its centred caption "By my hand..." slides toward the page centre (x~1400-1500) onto the laurel's right leaf tips (crown plate has the same laurel at every rank; ranks differ only inside the wreath).
  So rank-independent, signer-length dependent. liftSignature is clamped (crown want 340px) and cannot fix horizontal collision.
- Fix: cap the signature/date boxes in crown so the caption stays on the flank: 
  `.award-template[data-order="crown"] section.SignAndDate > div { max-width: 600px; }` (laurel occupies ~x950-1600; flank to the right is ~640px). fitInline() (overflow-wrap:anywhere makes line counts honest) then shrinks/wraps the signer into the narrower box.
  Optional belt-and-braces: `.award-template[data-order="crown"] .AwardSignatory { margin-left:auto }`.
- Risk: long signers wrap to 3-4 lines (block grows upward; lift clamp copes); crown only.
- Check: crown rows of sweep:scrolls (CA sign-and-date family; crown 1421 ink px baseline), N_crown-signdate fixture.
- Proof: c030, c046 (and c060). Controls: c127, c129, c003, c010 (short/no signer, must be unchanged).

### B4d thick reason rules touching descenders (c150 garber, c024 rose)
- Verdict: inherited design weakness (ruled-paper look transliterated from the source) rendered worse at small sizes; cosmetic/low. Mechanism: the six rules are inline `<img class="Underline">` in an absolutely positioned div with `padding-top:.42em` (_text.tpl:~196) and line-height 2 (scrollery.css:858-870), so rule PITCH scales with the fitted font size, but the rule itself has fixed PX metrics: `.Underline{height:13px; margin-top:7px; margin-bottom:-20px}` (scrollery.css:621-626).
  When the citation is long, fitReason shrinks the font toward 0.55em: the em-based position drifts relative to the baseline while thickness (13px, heavy centre) does not, so the centre of the rule lands inside the descender zone of g/p/q/y (visible in c150 and c024; not in short-reason cases).
- Fix: make the rule offset/thickness em-based so it scales with the fitted size, e.g. in scrollery.css `.Award_Reason .Underline{ height:.16em; margin-top:.09em; margin-bottom:-.26em }` (values to be tuned from a baseline-to-rule measurement) or simply push the container padding-top from .42em to ~.50em. 
- Risk: last rule can clip against `overflow:hidden` of .Award_Reason (scrollery.css:870); rule-vs-text alignment changes on all 17 orders (shared layer), so run full sweep.
- Proof: c150, c024. Controls: c110 (long reason, clean), c009, c089, c031.

### B4e flame charred ground swallows numeral (c086 r7)
- Verdict: INTENDED art, not a defect. `assets/scrolls/flame/rank_7.png` is a transparent plate drawn over `flame/bg.jpg` whose charred foot is baked into the ground; the plate's own last "I" of VII is burnt at an angle (yellow ember edge baked into the PNG at x~1410-1450,y~2985, visible when composited over magenta), registered exactly to the char contour (rank_N alpha top y=2947 for r5-r8). flame.tpl draws ground, heraldry, plate bare full-bleed in the right order (flame.tpl:19-29). It reads "VII" with a burned-off last stroke by design.
- No fix recommended. Optional: none. (Other numerals ending in I may look similar; same art.)
- Proof/controls if someone wants to double-check: c086 vs c093, c050, c148 (flame r5/r10).

## B5 - Garber pale thread strokes crossing the heading (c020, c053, c047, c150, c114, c014)
- Verdict: INTENDED original art; not misregistered and correctly layered. `garber/rank_N.png` is a 2550x3300 transparent plate: for r0-8 two thread strokes (alpha<=186) at y467-512 and y800-845, r9/10 at y528-575 and 1123-1170, plus the foot cluster (garber.tpl:25-34 comment). It sits above linen/overlay and BELOW the text layer (z-order: linen, heraldry, overlay.png, rank plate, _text), so type prints over a faint thread. docs/scrollery-typeset-results.md section 6 documents it as an "underlay window" and the engine deliberately ignores it. custom.tpl:198-204 explicitly says "garber's plates run a thread to both page edges" and already clips the plate inside the knot when a custom border is active (clip-path), which is why c014/c150/c020 do not show it crossing the knot.
- On fixed garber (c053) the thread passes translucently over linen.jpg's baked border at x=0-210 (as in the original composition; the plate was "composed against linen's own border").
- Fix: none needed. If a softer look is desired on the heading: lower opacity of the rank plate layer is NOT recommended (also fades the numeral cluster); instead a CSS mask on the plate over y 420-1220 could drop thread alpha (~0.5) without touching the numerals. Optional polish only.
- Check if ever changed: c053, c047 (fixed garber), c014/c150/c020/c114 (custom with garber rank set), typeset Q-tests (the window policy).

## B6 - heraldry with opaque black backgrounds
- Verdict: DATA problem amplified by render path (low). `_heraldry.tpl:15-16` draws the image with `mix-blend-mode:multiply`, which makes WHITE vanish but black stay black. Uploads are stored as-is by Heraldry::store_heraldry (system/lib/ork3/class.Heraldry.php:110-145); a PNG with alpha that falls through to JPEG (its own comment at 117-119) or a user-cropped shield on a black canvas becomes an opaque black rectangle. The 420x420 box (and centre-top margin) is therefore visible, and the TopTextFlourish/Amtgard swash overlaps it.
- Players 2433 (002433.png RGBA, fully opaque, corner rgb(22,22,22)), park 797 (00797.jpg, corner (0,0,0), 29% of the image is black bg connected to the corners).
- Scan (scratch scripts): images whose four corners are near-black and uniform (lum<40, spread<30), counts / total:
  player 724/4284 (also 1901 white-corner = fine under multiply, 1656 coloured, 3 with alpha), park 75/585, kingdom 2/41, unit 265/1143. (Looser lum<45 flood-connected test: 810 players, 79 parks, 3 kingdoms, 286 units.) Many of the 724 are genuinely dark art (black shields, dark photos), so a blanket removal is unsafe.
  Lists of the strict set: scratchpad rc3/dark_player.txt, dark_park.txt, dark_kingdom.txt (keyable_*.txt = flood-fill fraction per file).
- Options: (a) data cleanup - not scalable (800+ files, uploaded by users). (b) CSS-only - cannot distinguish background black from art black (screen/lighten/luminosity-key fails on white-on-black art). (c) RECOMMENDED: derive a scroll rendition at render/upload time: flood-fill from the 4 corners over near-black pixels (with a ~tolerance), set alpha=0 for the connected region ONLY when (i) all 4 corners are near-black and (ii) the connected region is 5-60% of the area and bordered by non-black ink (a ring) - otherwise leave untouched (dark-art guard; excludes the ~421 players/37 parks that are mostly dark). Interior blacks (e.g. inside the Star of David) are untouched because they are not corner-connected.
  Where: the pipeline has the hook - model.Scrollery::heraldry_sources() (orkui/model/model.Scrollery.php:1363-1392) asks `Ork3::$Lib->heraldry->GetHeraldryUrl([... 'Size'=>'master'])`; add a Size 'scroll' (or generate `<id>_scroll.png` beside the master in Heraldry::store_heraldry at upload + a backfill script like the rendition backfill) with GD (imagefill-style BFS). Keeps the profile/other pages untouched since they use the master. Also fixes the swash touching the box (the black box disappears).
- Risk: mis-keying dark-edged art (mitigated by the guards); JPEG fringes (use tolerance + 1px alpha feather); another file type to cache (GhettoCache/ memcache flush when regenerating).
- Check: render the 724/75 strict list with scroll rendition and diff; ensure white-corner arts byte-identical.
- Proof cases: c088, c019, c021, c020, c068 (player 2433 persona), c093, c071, c127, c067, c094 (parks/kingdom with black field). Controls: c053 and c047 (kingdom K10 arms, shield-shaped, unaffected), c030 (park 797 heraldry source), any case with no heraldry (c024, c053 mode none/with kingdom).

## B7 - starfield2 + mask: date runs into the notch (c035)
- Verdict: DEFECT in the composition (custom scroll), not intended. `starfield2` = `zodiac/bg_rank.jpg` (scroll-recipes.php:44), a ground with a baked dark notch at the foot (measured on the JPG: dark run x=984-1576 at y2700, 1001-1571 at y2800, 1005-1569 at y2900) where the zodiac order's numeral sits.
  The zodiac ORDER compensates in CSS: `.award-template[data-order="zodiac"] .Award{padding:21% 21% 12% 21%}` and ranked `SignAndDate{width:130%; flex-basis:500px; min-height:500px}` + first-child margin (scrollery.css:1455-1481), spreading the date and signer out around the notch (boxes end ~x930 and start ~x1670).
  The custom scroll keeps the generic rule `.award-template[data-order="custom"] section.SignAndDate{width:100%}` (scrollery.css:1210-1212) and the mask border's 433px inset, so the two 7em boxes span x433-1000 and x1550-2117. That puts the date box's right edge and the "27th September 2026" ink (to ~x1016) and its rule at the notch edge (1001-1005) and the signer rule starts at ~1540 inside the notch's right edge (1570). The free-space profile does not help (ground/starfield2 entry is only 400-2400; bottom half does not consume it).
- Fix (one rule, scoped to the ground so fixed zodiac and starfield-without-notch are untouched), in scrollery.css after line 1212:
  `.award-template[data-order="custom"][data-ground="starfield2"] section.SignAndDate > div { width: 6.2em; }`  (~500px: date box x433-935, signer box x1617-2117, 40-65px clear of the notch both sides).
  fitInline() then fits the date/signer to the narrower box. Do not widen the block instead (width>100% runs into the knot, inset 433).
- Risk: the date drops ~8-10% in size (or wraps on very long dates/hands) only for this ground; signer wraps sooner (longer lift).
- Check: any custom + starfield2 sweep case (tier C starfield2+crown / +owl combos in combo150), plus fixed zodiac ranks 1-10 byte-identical.
- Proof: c035. Controls: c044 (starfield with bg_generic, no notch: unchanged), the C_custom starfield2+crown+common and starfield2+owl+common sweeps (crown/owl also have inset quirks: must not regress), fixed zodiac r1/r5/r10.

## Priority suggestion
1. B7 (one-line scoped CSS, clear win) 2. B4c crown max-width 3. B4a/b battle min-height 4. B6 server-side scroll rendition (biggest visible win, biggest work) 5. B4d rules em-scaling (shared layer, needs full sweep). B4e and B5 intended: no change.
