<?php
/**
 * The Custom Border's knot geometry: the two <symbol>s every .sc-knot layer draws.
 *
 * Emitted ONCE PER PAGE from _filters.tpl, never per certificate — exactly the rule
 * _border_defs.tpl states, for exactly its reason. Scroll::Render() caches a composed
 * certificate for an hour keyed on the scroll array and the partials' mtime, so a
 * certificate that decided for itself whether the knot was already on the page would
 * bake that answer into its cache entry and draw no knot on the next page. The page
 * emits it, unconditionally, and every cached fragment is then identical.
 *
 * Unconditionally even on a page that may never show a Custom Border: the composer
 * renders certificates over Ajax and the officer can tick the box at any moment, and a
 * knot that is not on the page silently draws nothing.
 *
 * INLINED rather than referenced. The fills read var(--knot-c1) / var(--knot-c2), and a
 * <use href="knot.svg#…"> into another document does not inherit custom properties
 * from the referencing page — the knot would print white. So the symbols are read out
 * of the asset itself (one source of truth, the file Task 1 exported and verified) and
 * written into this page's sprite: the file's comment and outer <svg> are dropped and
 * the indentation between tags is collapsed, nothing else.
 *
 * Memoised in $GLOBALS the same way _border_defs.tpl is, so a page that reaches the
 * seam twice pays once.
 */
if (!empty($GLOBALS['scroll_knot_defs'])) {
    return;
}
$GLOBALS['scroll_knot_defs'] = true;

$knotSvg     = (string) @file_get_contents(DIR_ASSETS . 'scrolls/' . Scroll::KNOT_SPRITE);
$knotSvg     = (string) preg_replace('/<!--.*?-->/s', '', $knotSvg);
$knotFirst   = strpos($knotSvg, '<symbol');
$knotLast    = strrpos($knotSvg, '</symbol>');
$knotSymbols = ($knotFirst === false || $knotLast === false)
    ? ''
    : (string) preg_replace('/>\s+</', '><', substr($knotSvg, $knotFirst, $knotLast + 9 - $knotFirst));
?>
<svg version="1.1" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"
     style="position:absolute;left:0;top:0;right:auto;bottom:auto;width:0;height:0;overflow:hidden;">
    <defs><?= $knotSymbols ?></defs>
</svg>
