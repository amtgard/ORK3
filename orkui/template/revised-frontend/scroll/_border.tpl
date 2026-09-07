<?php
/**
 * Transliterated from arc-scroll/src/award/templates/ash/common/award-border.html.
 *
 * The SVG geometry is copied byte for byte; only three things changed.
 *
 * 1. The root <svg> carried an Aurelia css= binding AND a plain style=. Aurelia
 *    merged the two; two real style attributes would make the browser keep the
 *    first and silently drop the second, losing fill-rule:evenodd along with the
 *    clip and stroke rules — which fills in every hole in the border paths. They
 *    are merged into one attribute here.
 * 2. The <g id="Griffons"> block is gated in PHP rather than by if.bind.
 * 3. The 48 KB <symbol> library and the 47 KB griffon plate moved OUT, into
 *    _border_defs.tpl, which emits each of them once per page. What is left here is
 *    the per-certificate part and only that: the colour pair, the blend, and the
 *    eight <use> references. ~95 KB per certificate became ~2 KB.
 *
 * That is the whole point of the split, so keep it: every custom scroll composes
 * this partial and custom is the generic fallback, so a bulk run is N copies of
 * whatever this file emits. A <use href="#Border-A"> resolves against the DOCUMENT,
 * not against this <svg>, so the symbols living in another element of the same page
 * changes nothing about how they draw — while the ids stop being duplicated N times.
 *
 * ⚠️ THIS FILE MUST NOT DECIDE WHETHER THE DEFS ARE THERE. It emits <use>
 * references and nothing else, on every certificate, identically. Scroll::Render()
 * caches a composed certificate for an hour keyed on the scroll array and the
 * partials' mtime — so anything conditional on "has some earlier certificate on
 * this page already emitted the library?" gets baked into that cache entry and
 * served to pages where the answer was different. A self-including version of this
 * partial would cache a defs-less fragment from the print route and then draw a
 * borderless certificate in the composer. _filters.tpl emits the library, from the
 * page, once, unconditionally.
 *
 * The <style> that used to sit here has moved to scrollery.css, with every other
 * scroll rule, under "the knotwork border". A <style> inside an <svg> is
 * document-scoped just like one in <head>, so those rules were global already; all
 * that changed is that there is now one copy of them.
 *
 * The griffon gate stays a PHP conditional and stays HERE, on the reference rather
 * than on the geometry: ten orders compose this partial without drawing griffons,
 * and a CSS display:none would put the whole 47 KB plate in their DOM for nothing.
 */

/**
 * $style — optional extra declarations on the outer layer. dragon draws this
 * partial twice: once normally, then again as a glow (black primary, blurred,
 * color-dodge). Every other order leaves it unset.
 */
$borderStyle = 'mix-blend-mode:multiply;padding:1px;margin:-1px;'
    . (isset($style) ? (string) $style : '');
?>
    <div style="<?= scroll_e($borderStyle) ?>">
        <svg viewBox="0 0 2550 3300" width="2550" height="3300" style="--primary-color:<?= scroll_e($primary) ?>;--secondary-color:<?= scroll_e($secondary) ?>;fill-rule:evenodd;clip-rule:evenodd;stroke-linejoin:round;stroke-miterlimit:2;">
        
            <g> 
                <use href="#Border-TopCorner"               transform="matrix( 1,0,0,1,-5,0)"/>
                <use href="#Border-Deco-1"                  transform="matrix( 1,0,0,1,-5,0)"/>
                <use href="#Border-Deco-Special-Griffon"    transform="matrix( 1,0,0,1,-5,0)"/>
                <use href="#Border-BottomCorner"            transform="matrix( 1,0,0,1,-5,0)"/>
                <use href="#Border-TopCorner"               transform="matrix(-1,0,0,1,2555,0)"/>
                <use href="#Border-Deco-1"                  transform="matrix(-1,0,0,1,2555,0)"/>
                <use href="#Border-Deco-Special-Griffon"    transform="matrix(-1,0,0,1,2555,0)"/>
                <use href="#Border-BottomCorner"            transform="matrix(-1,0,0,1,2555,0)"/>
            </g>
<?php if (!empty($griffons)): ?>
            <?php /* The plate itself is in _border_defs.tpl. It keeps its own
                     transform, which a <use> clones along with the geometry, so this
                     draws exactly where the inline <g> did — after the eight corner
                     and deco references, which is what puts it on top of them. */ ?>
            <use href="#Border-Griffons"/>
<?php endif; ?>
        </svg>
    </div>
    