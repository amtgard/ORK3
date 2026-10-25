<?php

declare(strict_types=1);

/*
 * DRAFT, PENDING OWNER SIGN-OFF. This is the one judgement the artwork requires (spec 7.3,
 * docs/scrollery-ink-collision-results.md section 4): which plates each design means the
 * type to sit ON. The owner signs the contact sheet before any free-space profile built
 * from this file is committed. Regenerate the sheet with
 *
 *     npx playwright test --project=scroll-underlays      # writes tools/scroll-sweep/out/underlays/index.html
 *
 * WHAT THE FILE IS. order id => list of fnmatch() patterns, relative to assets/scrolls/,
 * naming the plates that design is DESIGNED to have type printed over: full-page washes,
 * scenes and textures, composited soft-light or multiply or at opacity below 1, that the
 * type band overlaps on purpose. Everything else a design draws is an OBSTACLE the type
 * keeps clear of: border corner pieces, figures, badges, numerals, anything with a hard
 * edge. An empty list is a real answer ("nothing here is meant to be written over") and
 * every order has an entry, `custom` included.
 *
 * HOW A PLATE IS MATCHED, so a pattern is written once and covers everything it should.
 *
 *   - The pattern is tested against the plate's path below assets/scrolls/ as the PRINT
 *     route serves it: the master or its WebP print rendition (Scroll::ArtUrl()), which is
 *     `wash.jpg` where WebP lost to the JPEG and `glow.webp` where it won. So the pattern
 *     names the stem and leaves the extension open: `battle/arcane/wash.*`. The display
 *     rendition (`wash_display.webp`) is deliberately not matched, because the free-space
 *     profile is built from the print route and never from the composer's preview.
 *     The `?v=<sha>` cache-buster is not part of the path.
 *   - Plain fnmatch(), no flags, so `*` also crosses `/`. Only `*` and `?` are used here.
 *
 * THE GRAIN IS THE FILE, NOT THE LAYER. A plate is hidden by its src, so a file drawn as
 * both an underlay and an obstacle cannot be split (see `mask`). That is a limit of the
 * data, not a judgement about the art, and it always resolves to obstacle. For the same
 * reason one file gets one answer everywhere: it is never an underlay in one order and an
 * obstacle in another (`owl/border.*` is listed under both owl and custom).
 *
 * NEVER LIST A PLATE THAT markGround() ALREADY TAKES FOR THE GROUND IN THAT ORDER.
 * markGround() (tools/scroll-sweep/lib/isolate.ts) takes an opaque, full-frame, normally
 * blended <img> for the ground, and the ground stays visible in layer G. A pattern naming
 * it would hide it from layer A only, and the difference of the two would be the whole
 * ground reported as artwork. The rule is about the plate in that order, not about the
 * file: `warrior/bg_10.*` is listed under warrior, where rank 10 draws it soft-light and
 * so it is not the ground, and is NOT listed under custom, where the `sunburst` ground
 * draws the same file normally and so it is. `custom` lists the washes laid over the
 * parchment and none of the grounds (Scroll::GROUND_WASHES says which grounds are which).
 * ScrollLayoutUnderlaysTest holds all of this to the vocabulary in scroll-recipes.php.
 *
 * THE KNOTWORK BORDER IS NOT A FILE. _border.tpl (drawn by battle, dragon, griffon and
 * custom) is inline SVG with no src, so no pattern can name it and the builder treats it
 * as an obstacle. The sheet notes it in words and shows no thumbnail.
 *
 * WHEN UNSURE, OBSTACLE, and say why in a CALL note beside the entry. The sheet prints
 * every CALL note at its head so the owner sees the judgement calls behind the draft. A
 * note begins at a comment line reading ` * CALL <order> (<where it landed>): ...` and
 * continues over the following comment lines indented three spaces past the asterisk.
 */

return [
    /*
     * battle (arcane and martial share every entry; martial has no underlay at all).
     *   UNDERLAY  arcane/wash.jpg      full page, soft-light at 0.8: a ring of runes
     *                                  across the left and middle that the text crosses.
     *   obstacle  border_symbols       a sword and an arrow in the side margins, multiply.
     *   obstacle  arcane/glow          NOT a glow: see CALL.
     *   obstacle  symbol_N and martial/over_N (both variants), common/rank_N: emblems, numerals.
     *
     * CALL battle (obstacle): arcane/glow.* is an obstacle although spec 7.3's example lists it.
     *   The file is the brown hand-and-knot emblem (it is byte-identical to arcane/hand.png),
     *   a hard-edged figure at bottom centre, not a soft glow.
     */
    'battle' => ['battle/arcane/wash.*'],

    /*
     * crown: border (twice) and phoenix (twice: two birds, one in each side margin) and the
     * laurel symbol_N. Every one is a hard-edged figure. No wash.
     */
    'crown' => [],

    /*
     * dragon: dragons.svg (two small dragons in the side margins), under_N (the dragon
     * emblem, bottom centre), over_N (gold ornaments from rank 6), the rank numeral. All
     * figures, though dragons.svg is drawn full-bleed at multiply.
     */
    'dragon' => [],

    /*
     * flame: bg.jpg is the ground (frame and flames painted in). The one plate over it is
     * rank_N.png, a whole-page transparent plate carrying the frame ornaments and the
     * numeral. Obstacle.
     */
    'flame' => [],

    /*
     * garber: linen.jpg is the ground. overlay.png (the frame's corner furniture, nails,
     * bobbin) and rank_N.png (scissors, thread, numeral) are whole-page transparent plates
     * of figures. Obstacles.
     */
    'garber' => [],

    /*
     * griffon: symbol_N twice at soft-light (sepia figures at the foot of the page), and
     * the rank numeral. Soft-light does not make it a wash: it is a figure.
     */
    'griffon' => [],

    /*
     * jovious: bg.jpg is the ground; the only plate over it is symbol_N, the jester.
     */
    'jovious' => [],

    /*
     * lion: border twice (multiply, then soft-light at 0.3) and symbol_N twice (multiply,
     * then screen at 0.3). The second passes only lift the first; the art is a frame and a
     * shield.
     */
    'lion' => [],

    /*
     * hunter: border twice (multiply, then normal at 0.7: the second pass restores the
     * linework the first ate) and symbol_N twice likewise. Frame and archers.
     */
    'hunter' => [],

    /*
     * hydra: border twice (multiply, then soft-light at 0.5) and symbol_N twice. Frame and
     * figures.
     */
    'hydra' => [],

    /*
     * mask: border twice, and mask/symbol.png THREE times: two blurred screen passes (the
     * glow) and the plain emblem between them.
     *
     * CALL mask (obstacle): the glow is a faint diffuse bloom around the emblem that the type
     *   could plausibly sit on, but it is drawn from the SAME FILE as the emblem (the blur is
     *   a filter on the wrapper). A pattern for mask/symbol.* would hide the emblem too, so
     *   the whole file is an obstacle. Cost: the soft halo around the emblem at the foot of
     *   the page narrows the room the profile reports there.
     */
    'mask' => [],

    /*
     * owl (parchment, cyanotype and transparency alike): the ground is parchment.jpg,
     * cyanotype.jpg or, for transparency, the page itself. owl/border.jpg is drawn two,
     * one or three times and rank_N once or twice, all with the invert/screen blends.
     *   UNDERLAY  owl/border.*   the blueprint grid across the whole page (and, in the same
     *                            file, the frame and the corner owls: see CALL).
     *   obstacle  rank_N         the numeral.
     *
     * CALL owl (underlay, as ruled): owl/border.jpg is one file holding the page-wide
     *   graph-paper grid, the frame and the corner owls. The grid is the texture the type is
     *   designed to sit on. The frame and the owls are what it is not, but they sit outside
     *   the .Award column: the owl border's art stops at 433px a side (Scroll::BORDER_INSETS)
     *   and .Award's padding is never less than that, so type does not reach them and listing
     *   the file costs nothing there. Custom draws the same file through its owl border, so
     *   custom lists it too.
     */
    'owl' => ['owl/border.*'],

    /*
     * rose: burn.svg is drawn under the heraldry at multiply, opacity 0.2, hue-rotated
     * green: pale vines along both side margins, a texture by any definition.
     *   UNDERLAY  rose/burn.*
     *   obstacle  border (twice), symbol_N.
     * `burn.*` also matches burn.jpg, which is the `rosewash` ground drawn through custom.
     */
    'rose' => ['rose/burn.*'],

    /*
     * smith: burn.jpg at soft-light is the map and townscape that spreads over the whole
     * page and that the text is written across. It is the "smith" underlay.
     *   UNDERLAY  smith/burn.*
     *   obstacle  border (twice), symbol (twice: the anvil).
     *
     * CALL smith (burn.*, not the brief's bg.*): the brief and spec 7.3's example name
     *   `smith/bg.*`. smith never draws it. bg.jpg is the opaque `map` ground used only by
     *   custom, and it carries its own frame, tools and anvil, so it is a ground and not an
     *   underlay. The townscape smith draws is burn.jpg, which is what is listed.
     *   ScrollLayoutUnderlaysTest::testNoPatternHidesAGround fails if bg.* is listed.
     */
    'smith' => ['smith/burn.*'],

    /*
     * warrior: ranks 1-9 draw the parchment ground and border_N.jpg (the frame and that
     * rank's figure, a hawk on a post at rank 5; multiply) and, at rank 9, also symbol_9
     * (the purple dragon). Rank 10 draws bg_10.jpg at soft-light and nothing else.
     *   UNDERLAY  warrior/bg_10.*   rank 10 only.
     *   obstacle  border_N (ranks 1-9), symbol_9.
     *
     * CALL warrior (underlay, as ruled): rank 10's warrior/bg_10.jpg is that rank's own page
     *   ground, with the frame, a flame wash, a phoenix and an ash band painted into it. It is
     *   drawn soft-light, so markGround() does not take it for the ground, and it is listed
     *   here instead. It is NOT listed under custom, where the `sunburst` ground draws the
     *   same file normally and so it is the ground there.
     *   For the builder: at warrior rank 10 the frame and the ash band live inside this
     *   underlay, so the builder will see no obstacle ink there. The .Award padding
     *   (17% of 2550, 433px a side; scrollery.css) is what keeps type off that frame.
     */
    'warrior' => ['warrior/bg_10.*'],

    /*
     * zodiac: bg_generic.jpg and bg_rank.jpg are opaque grounds with their own frame and
     * parchment cut-out. The one plate over them is rank_N.png, the numeral. Obstacle.
     */
    'zodiac' => [],

    /*
     * custom: composed from parts, so the entry is per PLATE, not per recipe. Of the twelve
     * grounds, nine are opaque and normally blended (parchment, linen, jovious, flame,
     * starfield, starfield2, cyanotype, sunburst, map) and are the ground, so none of them
     * is listed. Three are washes that custom.tpl lays over the parchment, with the blend
     * each one's own order uses (Scroll::GROUND_WASHES): forge (smith/burn), rosewash
     * (rose/burn) and arcane (battle/arcane/wash). Those are underlays, and they are the
     * same three files as above, so the two entries cannot drift apart.
     *
     * The fourth entry is the owl border, which custom draws through its border slot: the
     * same file as owl, so the same answer (see owl). The other borders (eight rasters
     * and the knotwork) and the rank sets are obstacles: numerals and the zodiac, garber and
     * flame whole-page numeral plates are hard-edged figures.
     */
    'custom' => ['smith/burn.*', 'rose/burn.*', 'battle/arcane/wash.*', 'owl/border.*'],
];
