# Scrollery — "Customize Design" card: heraldry source + custom knot border

Date: 2026-10-27 · Branch: `scrollery` · Status: owner answers folded in after builder check; awaiting owner approval of this document

## Intent

Officers printing a single scroll in the Scrollery builder (`Scrollery/create/<park|kingdom>/<id>`)
want two personal touches without leaving the builder:

1. **Pick whose heraldry crowns the scroll.** Today the top-centre arms are always the issuing
   body's: the kingdom's on a kingdom scroll, the park's on a park scroll. Officers want to switch
   to kingdom, park, or the recipient's own (persona) arms.
2. **Swap any award's border for a colour-it-yourself knot.** The two-band Celtic knot from the
   Jovious plate is simple and unadorned. Recoloured, it can frame any award in kingdom colours
   (e.g. Crystal Groves blue and gold) or anything else the officer likes.

## What the officer sees

A new collapsible card, **Customize Design**, in the builder's left column:

- **Position:** between the **Award** card and the **Wording** card.
- **Collapsed by default:** same `rp-filter-card` + `data-sc-collapse` pattern as "Scroll design"
  and "Heraldry adjustments".

### Heraldry

A three-button segmented bar: **Kingdom | Park | Persona**.

- **Default.** The issuing scope: Kingdom on a kingdom-scoped builder, Park on a park-scoped one.
- **Persona = the recipient's own heraldry.** The recipient is the player chosen in the
  **Wording** card's recipient search, which sits *below* this card. Persona stays disabled, with
  a `data-tip` saying "Choose a recipient below first", until a recipient is picked, and updates
  live when one is.
- **Disabled buttons.** A button is disabled, with a `data-tip` saying why, when that heraldry
  doesn't exist:
  - Persona: no recipient chosen yet, or the recipient has no heraldry.
  - Park, on a kingdom-scoped builder: it uses the **recipient's home park** (owner decision,
    2026-10-27). It is disabled with "Choose a recipient below first" until a recipient is picked,
    then enabled when that park has heraldry, or disabled with "This recipient's park has no
    heraldry on file". It backfills live when the recipient is chosen, exactly like Persona.
  - Any entity with no heraldry uploaded.
- **Effect.** Choosing a button changes the top-centre heraldry in the live preview and on the
  print.
- **Adjustments still apply.** The existing "Heraldry adjustments" sliders keep working on
  whichever arms are shown.
- **Fallback.** If the default source has no heraldry, behaviour is unchanged from today: no arms
  drawn. The bar does not auto-jump to another source.

### Custom Border

- **The checkbox.** A checkbox labelled **Custom Border**. When ticked:
  - the selected award's own border is removed from the certificate, and the two-band Jovious
    knot is drawn in its place;
  - the award's symbol, rank art, ground/background and text stay as they are;
  - two colour controls appear, **Color 1** and **Color 2**.
- **Each colour control** = a native colour picker + a hex text field, kept in sync. Either one
  edits the colour.
  - The hex field accepts `#RRGGBB` (with or without `#`, any case).
  - Invalid input is flagged inline and does not apply.
- **Which colour goes where.**
  - Color 1 = the knot band that is blue on the Jovious plate (the "Primary" band group).
  - Color 2 = the band that is pink (the "Secondary" band group).
- **Defaults.**
  - The Jovious originals: Color 1 `#6AC1FF`, Color 2 `#E84C92`.
  - *(My call:)* the builder remembers the officer's last pair in `localStorage` as a convenience.
    Wrap every access in try/catch; the page must work without it.
- **"Match scroll style?"** A second checkbox, shown under the colour controls while Custom Border
  is on. (Owner decision, 2026-10-27.)
  - **Unchecked (exact colours):** the knot prints in exactly the picked colours.
    - It is drawn as a normal-blend, opaque layer **above** the order's ground and any
      whole-page effects (e.g. battle's soft-light wash, mask's glow).
    - It stays **below** the heraldry, symbol/rank art and text.
  - **Checked (match style):** the knot takes the replaced border's place in the layer stack and
    that border's blending, so it sits in the art like the original. Examples:
    - multiply into the parchment on crown/rose/smith/hunter/hydra/lion and the `_border.tpl`
      orders;
    - invert on owl;
    - screen on mask;
    - under battle's wash.
  - **Expect the colours to shift.** Picked colours shift with the paper and effects; that is the
    point of the option.
  - Each compatible order declares the slot and blend its border uses, server-side, next to its
    compatibility rule.
  - *(My call:)* unchecked by default. The officer is choosing colours, so the default shows
    exactly those colours.
- **Live preview.** Colour changes update the preview in place, with no server round trip, like
  the existing palette and heraldry sliders. Toggling the checkbox may round-trip.
- **Incompatible award styles.** When the selected award's style cannot take a custom border, the
  checkbox is **hidden**. In its place, a muted "Custom Border" label carries
  `data-tip="This award style is not compatible with custom borders."`.
  - Tooltips in this project are `data-tip`, never `title`.
  - *(My call: a muted label + info icon carrying the tip, so the tip has something to sit on.)*
- **The `custom` order.** It already has its own border picker and palette in "Scroll design".
  When Custom Border is on, the custom order's own border selection is superseded: disable those
  two controls with a one-line note.

### Custom Ribbon (added 2026-10-27, owner-approved)

- **The control.** A **Custom Ribbon** checkbox under Custom Border, with ONE **Ribbon Color**
  control: a native colour picker + `#RRGGBB` hex field, kept in sync, same validation as Color 1/2.
- **What it replaces.** The text-section flourishes (`flourish_top`, `flourish_right`,
  `flourish_bottom`, including the two centred-layout side rules `ScRule--l` / `ScRule--r`,
  which reuse `flourish_bottom`) are replaced by the three Jovious ribbons
  (`jovious/ribbon-{top,right,bottom}.png` shapes), tinted the chosen colour.
  - It applies to **every** award: every order draws these flourishes.
  - On a Jovious scroll it recolours the ribbons it already has.
- **Exact colour always** (my call). Flourishes are plain overlay images, not blended into the
  plate, so "Match scroll style?" stays border-only.
- **Default colour.** The Jovious ribbon blue, measured from the source/shipped art and
  recorded with the artwork.
- **The text layout must not move.** The tinted ribbons keep the exact outline/alpha of the
  shipped Jovious ribbons, so their ink grids (`data-sc-ink`) and the typeset engine's fit
  are unchanged.
- **Live preview.** Colour changes repaint in place, with no round trip; toggling the
  checkbox may round-trip.
- **Artwork.** The likely source is the Jovious master's hidden group `/3/5` (three pink
  "Curves" in the top text area) in `Award Templates Trim.af`. Extract it, and check it by eye
  against the shipped ribbons.
  - **Fallback**, if the source isn't the same art: derive a neutral version from the shipped
    PNGs (luminance relative to the ribbon's base colour), keeping the shading and stitching
    at today's resolution.
  - **Acceptance:** tinting the neutral art with the default colour reproduces the shipped
    ribbons (mean RGB error ≤ 0.03 on opaque pixels), and the alpha is identical.
- **Saved with the print** like the other design choices: `customRibbon` (bool) and
  `ribbonColor` (`#RRGGBB`).

## Which award styles are compatible

How each order (template in `orkui/template/revised-frontend/scroll/<order>.tpl`) draws its border
today decides what "replace" means:

| How the border is drawn | Orders | Custom Border |
|---|---|---|
| Separate border image layer | crown, hunter, hydra, lion, mask, owl (`border.jpg`, drawn more than once), rose, smith, warrior (`border_N`) | Compatible: suppress the layer(s), draw the knot |
| Shared vector knot partial `_border.tpl` | battle, custom, dragon (drawn twice: normal + glow pass), griffon (adds griffon decorations) | Compatible: draw the custom knot instead of the partial. Dragon's glow pass is part of its border and goes too; griffon's griffon decorations go too. |
| Border painted into the ground image | jovious (`bg.jpg`), flame (`bg.jpg`), garber (`linen.jpg` / `overlay.png`), zodiac (`bg_generic.jpg` / `bg_rank.jpg`), warrior rank 10 (`bg_10.jpg`), custom on a framed ground | Compatible only if a border-free ground can be produced, see below |

- **Jovious is compatible.** A border-free version of its ground can be exported from the Affinity
  source: the Jovious master minus its two band groups. Use it as the ground when Custom Border is on.
  - Its linen weave is softer than the shipped `jovious/bg.jpg`, so ticking Custom Border subtly
    changes the texture behind the text. **The owner accepted this (2026-10-27).**
  - Do not retouch the shipped file to remove the bands, and do not re-export the default Jovious
    ground.
- **Compatibility is per (order, rank, chosen ground), not per order** (my call, applying the
  owner's "hide it if we can't actually do it" rule).
  - **Warrior rank 10** draws no border layer: its knot frame is painted into
    `warrior/bg_10.jpg`. Treat it like the baked-in group. Export a border-free `bg_10` if the
    Affinity source allows it cleanly; otherwise hide Custom Border at rank 10.
  - **The `custom` order's framed grounds:** linen, jovious, flame, sunburst (= warrior `bg_10`)
    and map (`groundsWithOwnBorder` in `scroll-recipes.php`).
    - A ground with a border-free export is swapped for it while Custom Border is on: jovious, and
      sunburst if `bg_10` gets one.
    - On any other framed ground, Custom Border is hidden with the same tooltip.
  - **The checkbox reacts live** to the award menu, the rank pills and the ground menu.
  - **The server applies the same rule** and ignores a custom border it can't honour.
- **Flame, Garber, Zodiac: builder investigates in the Affinity sources.**
  - If a border-free ground can be exported cleanly (verified by eye, no remnants of the old
    border), make that order compatible.
  - Otherwise mark it incompatible (hidden checkbox + tooltip).
  - **Do not** draw the custom knot over a baked-in border. The owner chose "hide it if we can't
    actually do it".
- **Where compatibility is declared:** once, server-side, per order (e.g. a flag on the
  `Scroll::Orders()` entry). The builder page reads it from there; no parallel JS list.

## The knot artwork

- **Source.**
  - File: `~/Downloads/Arcanyst Artwork/Award Templates Trim.af`. The tool works on its APFS clone
    in `~/Desktop/Arcanyst Extract/src/`.
  - Master spread 1005 "Overlay (Jovious)".
  - Band groups: `/3/8` ("Primary") and `/3/9` ("Secondary").
  - Page background layers: `/3/0`, `/3/2`, `/3/3`, `/3/10`.
- **Tooling.** The local tool `tools/affinity-extract/` (git-ignored, never committed; see its
  README) already does this:
  - `recolor.py jovious` extracts the border alone, transparent, in register with the
    2550×3300 page, at 5100×6600.
  - It recolours through each band group's own Colour Overlay.
- **Structure of the art.**
  - Each band = white-filled, black-outlined curves.
  - Colour comes from ONE Colour Overlay (multiply) per band group, so colour × white = colour and
    black stays black.
  - There are no other active effects on the bands.
- **What to ship:** artwork that lets the browser recolour each band independently, instantly,
  and print crisply at 300 dpi or better on a 2550×3300 certificate.
  - **Preferred: vector.** Export the bands as SVG with the Colour Overlay effects stripped, so
    true vectors come out (effects rasterise in SVG export). Recolour the white fills via CSS
    custom properties, like `_border.tpl` does with `--primary-color` / `--secondary-color`.
  - **Acceptable fallback: per-band 2× rasters.** Each band exported with its overlay set to white
    (white fill + black outline, transparent elsewhere). The browser recolours by multiplying a
    solid colour, masked to the band's alpha, over it. That is exactly the Affinity mechanism.
  - **The builder picks after a short spike.** Criteria:
    - print sharpness;
    - certificate weight. Bulk pages repeat certificates N times, so prefer one shared
      definition referenced per certificate, like `_border_defs.tpl` does;
    - identical colour in preview and print.
- **Where it lives.** New assets go under `assets/scrolls/` (e.g. `assets/scrolls/common/knot/…`
  plus `jovious/bg-noborder.jpg`).
  - Follow the existing renditions/ink-extents conventions: `bin/build-scroll-renditions.sh`,
    `bin/build-scroll-ink-extents.php`, `assets/scrolls/hires-masters.txt` for 2× masters.
  - Their checks must still pass.

## Data flow and persistence

- **Wire format.** The builder's state already travels as parameters:
  - POST `ScrolleryAjax/preview` for the live preview;
  - GET `Scrollery/compose` for the print.
  - Both go through `Scroll::BuildScroll()`, which normalises the recipe via
    `Scroll::NormaliseRecipe()`.
- **New fields** ride the same path:
  - `heraldrySource` ∈ {`kingdom`, `park`, `persona`};
  - `customBorder` (bool);
  - `color1` / `color2` (`#RRGGBB`);
  - `matchStyle` (bool);
  - `customRibbon` (bool) / `ribbonColor` (`#RRGGBB`).
- **Validation.**
  - Normalise server-side in one place, with an allowlist / regex.
  - Unknown values fall back to the defaults, with no error.
  - A custom border requested on an incompatible order is ignored server-side too.
- **Applies to every order.** Today `BuildScroll()` ignores the recipe for every order but
  `custom`. These new fields apply to **all** orders; restructure accordingly.
- **Heraldry resolution stays server-side.**
  - The server resolves the URL for the chosen source using the existing
    `Ork3::$Lib->heraldry->GetHeraldryUrl([... 'Size' => 'master'])` path. See
    `model.Scrollery.php` `scope_context()` / `heraldry_url()`.
  - Persona uses the recipient player's heraldry.
  - The client never supplies an image URL.
- **Reprints.** The print log (`ork_scroll_print.recipe_json`) stores the normalised recipe, so
  reprints reproduce the exact certificate. Include the new fields there.
  - If the reprint path (`Scrollery/render`) does not currently read `recipe_json`, note it as a
    follow-up rather than building reprint-from-log here.
- **Layering.** SQL only in `system/lib/ork3/`; `orkui/model` is the only membrane. Templates are
  plain PHP (not Smarty).

## Goals

- The Customize Design card exists, collapsed by default, between Award and Wording.
- The heraldry source is switchable kingdom/park/persona, with the scope-based default.
- Custom Border works on every compatible order and is hidden (with the tooltip) on the rest.
- The colour pickers recolour the preview live; the print matches the preview.
- "Match scroll style?" switches between exact colours and the replaced border's own blending.
- Custom Ribbon replaces the text flourishes on every award with the Jovious ribbons in one chosen colour, without moving the text.
- The choices are recorded with the print.

## Non-goals

- Court Planner / bulk printing keep each award's normal look (my call). This is single-scroll
  builder only.
- Kingdom-level saved defaults, a list of preset palettes, or kingdom colour data.
- Other knot styles, recolouring anything other than the border, or editing symbols/grounds.
- New database tables.

## Anti-goals (would make it a failure even if it "works")

- Any remnant of the original border showing under or beside the custom knot.
- The print looking softer than today's art, or a preview/print colour mismatch.
- Text colliding with the new border: the typeset engine's free-space and ink data assume each
  order's own art. The knot hugs the page edge (≈ x 56–2494, y 52–3256 at 1×) with gaps at top
  and bottom centre. Verify text zones still clear it on every compatible order.
- Breaking bulk-page weight: a full-size knot embedded per certificate.
- A broken image when a heraldry source has no arms.

## Project rules that apply

- Dark mode (`html[data-theme="dark"]`) and phone widths for the new card: check computed
  styles in both themes.
- No native `confirm` / `alert` / `prompt`.
- `data-tip` tooltips.
- Existing Scroll PHPUnit suite (`php vendor/bin/phpunit --no-coverage --filter Scroll`, on the
  host) stays green.
- Add tests for:
  - recipe normalisation of the new fields;
  - the compatibility flag;
  - heraldry source resolution.

## Left to the builder

- Vector vs raster knot (after a spike, criteria above). With "Match scroll style?" checked, the
  knot must blend the way the replaced border did, so both outputs need to support blend modes.
- Exact control markup and styling within the card.
- Whether heraldry source switching re-fetches or swaps the image in place.
- The Flame/Garber/Zodiac verdicts, after investigating the sources.
- The park option on a kingdom-scoped builder.

## Follow-ups

- Reprint from the log: `Scrollery/render` (the reprint path) does not read `recipe_json`, so a
  reprint does not reproduce design choices.
- Trim `knot_defs` on bulk, court and reprint pages when no scroll in the run has `customBorder`
  (~357 KB raw per page today).
- A JS/PHP compatibility parity fixture test (the hide-the-checkbox rule vs
  `Scroll::CustomBorderRule()`).
- A committed golden-hash byte-identity test for design-less scrolls.
- Disabled-reason tooltips reachable by keyboard and screen readers (`aria-describedby`).
