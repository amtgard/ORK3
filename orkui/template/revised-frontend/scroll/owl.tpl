<?php
/**
 * Order of the Owl. Transliterated from
 * arc-scroll/src/award/templates/ash/owl.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * Owl is the one order whose LAYER COUNT varies by variant. Stacking an identical
 * multiply/screen layer deepens it, and the two grounds that are not the shared
 * parchment do not read without the extra passes. Per the source:
 *
 *     layer            parchment   cyanotype   transparency
 *     cyanotype.jpg        —           1x           —
 *     shared parchment    1x           —            —
 *     heraldry            1x           2x           4x
 *     owl/border.jpg      2x           1x           3x
 *     owl/rank_N.png      1x           1x           2x
 *
 * Never collapse a repeat into a single element.
 *
 * The source view's <style> blocks live in scrollery.css, keyed on
 * [data-order="owl"] and [data-order="owl"][data-variant="..."]. Two of those rules
 * targeted the <award-heraldry> CUSTOM ELEMENT — the host, which is a separate box
 * from the .centre-top div inside it. This port has no custom element, so each
 * heraldry pass is wrapped in a .owl-heraldry div that stands in for that host. The
 * wrapper is load-bearing, not decoration: the host carries the blend (which is
 * `filter:invert()` on two of the three variants) while the inner div carries
 * `filter: blur(1px) url(#OWL_edge-detect)`. Flatten them onto one element and the
 * inline filter wins, silently dropping the edge-detect on parchment and
 * transparency.
 *
 * #OWL_edge-detect is an in-document SVG filter, defined once per page by
 * Scrollery_render.tpl.
 */
$variant = (string) $s['variant'];

/*
 * The source spelled this same ternary out inline, once per layer, as an Aurelia
 * css= binding. Computing it once is not a deduplication — every layer below still
 * emits, and still carries it.
 */
$blend = $variant === 'cyanotype'
    ? 'mix-blend-mode: screen;'
    : 'filter:invert();mix-blend-mode:multiply;';

$heraldry = ['s' => $s, 'width' => 370, 'height' => 460, 'top' => 5];
?>
<div class="award-template" data-order="owl" data-rank="<?= (int) $s['rank'] ?>" data-variant="<?= scroll_e($s['variant']) ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php if ($variant === 'cyanotype'): ?>
        <img class="bleed" <?= scroll_art_attrs('owl/cyanotype.jpg') ?> alt="">
    <?php endif; ?>
    <?php if ($variant === 'parchment'): ?>
        <?php scroll_partial('parchment'); ?>
    <?php endif; ?>

    <?php /* Heraldry pass 1 of 1/2/4 — always drawn. */ ?>
<?php /* The exact-colour knot sits BELOW the heraldry (spec), so arms an officer scales
         or moves into the frame stay on top of it. Nothing else lies between. */ ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>
    <div class="owl-heraldry" style="<?= $blend ?>"><?php scroll_partial('heraldry', $heraldry); ?></div>

    <?php /* Pass 2, cyanotype only. */ ?>
    <?php if ($variant === 'cyanotype'): ?>
        <div class="owl-heraldry" style="<?= $blend ?>"><?php scroll_partial('heraldry', $heraldry); ?></div>
    <?php endif; ?>

    <?php /* Passes 2, 3 and 4, transparency only. Three separate elements in the
             source; three separate elements here. */ ?>
    <?php if ($variant === 'transparency'): ?>
        <div class="owl-heraldry" style="<?= $blend ?>"><?php scroll_partial('heraldry', $heraldry); ?></div>
        <div class="owl-heraldry" style="<?= $blend ?>"><?php scroll_partial('heraldry', $heraldry); ?></div>
        <div class="owl-heraldry" style="<?= $blend ?>"><?php scroll_partial('heraldry', $heraldry); ?></div>
    <?php endif; ?>

<?php /* Custom Border: the knot replaces all 2/1/3 border passes. Its rule's passes
         are keyed by variant, so match mode draws the same 2/1/3 knots with $blend;
         exact mode draws one plain knot, just below the heraldry. At column 0 so a
         certificate without one prints the bytes it always did. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <?php /* Border pass 1 of 2/1/3 — always drawn. */ ?>
    <img <?= scroll_art_attrs('owl/border.jpg') ?> style="<?= $blend ?>" alt="">
    <?php if ($variant === 'transparency' || $variant === 'parchment'): ?>
        <img <?= scroll_art_attrs('owl/border.jpg') ?> style="<?= $blend ?>" alt="">
    <?php endif; ?>
    <?php if ($variant === 'transparency'): ?>
        <img <?= scroll_art_attrs('owl/border.jpg') ?> style="<?= $blend ?>" alt="">
    <?php endif; ?>
<?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>

    <?php /* owl ships its own rank_0.png, so the rank layer is UNGUARDED — an
             unranked owl certificate draws rank_0, it does not skip the layer.
             The source <img> carried both style= and css=; Aurelia merged them, so
             they are one attribute here. */ ?>
    <div class="centre-bottom">
        <img <?= scroll_art_attrs('owl/rank_' . (int) $s['rank'] . '.png') ?> style="margin-bottom:75px;<?= $blend ?>" alt="">
    </div>
    <?php if ($variant === 'transparency'): ?>
        <div class="centre-bottom">
            <img <?= scroll_art_attrs('owl/rank_' . (int) $s['rank'] . '.png') ?> style="margin-bottom:75px;<?= $blend ?>" alt="">
        </div>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
