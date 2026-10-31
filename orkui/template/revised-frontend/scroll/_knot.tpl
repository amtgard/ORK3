<?php
/**
 * The Custom Border: the Jovious two-band knot in the officer's two colours, drawn in
 * place of a compatible order's own border. One full-page layer per call; an order
 * template never calls this directly, it calls scroll_custom_border_slot(), which
 * decides how many to draw and with which blend.
 *
 * Like _border.tpl, this is the per-certificate half only: the colour pair, the blend,
 * and two <use> references. The geometry — ~360 KB of paths — is in _knot_defs.tpl,
 * which the PAGE emits once from _filters.tpl. The same warning applies, for the same
 * reason: this file must never decide whether the defs are there, because
 * Scroll::Render() caches each composed certificate keyed on nothing about the page.
 *
 * The colours ride on the layer as --knot-c1 / --knot-c2, and the symbols' fills read
 * them through the <use> shadow trees, the way --primary-color reaches the knotwork
 * border. That is also the composer's repaint contract: its script sets those two
 * properties on every .sc-knot and the knot recolours without a round trip.
 *
 * $color1, $color2 — the two bands. Normalised HERE, where they reach markup, not
 *                    merely trusted from BuildScroll(): Render() is reachable with any
 *                    scroll array.
 * $style           — the blend: a replaced pass's own style in match mode, or
 *                    mix-blend-mode:normal in exact mode.
 */
$knotStyle = '--knot-c1:' . Scroll::NormaliseHexColour((string) ($color1 ?? ''), Scroll::DEFAULT_DESIGN['color1'])
    . ';--knot-c2:' . Scroll::NormaliseHexColour((string) ($color2 ?? ''), Scroll::DEFAULT_DESIGN['color2'])
    . ';' . (isset($style) ? (string) $style : '');
?>
    <div class="sc-knot" style="<?= scroll_e($knotStyle) ?>">
        <svg viewBox="0 0 2550 3300" width="2550" height="3300" aria-hidden="true" focusable="false">
            <?php /* Band 1 then band 2: the second passes over the first at every
                     crossing the artist drew it on top. */ ?>
            <use href="#Knot-Band-1"/>
            <use href="#Knot-Band-2"/>
        </svg>
    </div>
