<?php
/**
 * Order of the Rose. Transliterated from
 * arc-scroll/src/award/templates/ash/rose.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * The source view's <style> block (SignAndDate min-height:250px) lives in
 * scrollery.css, keyed on [data-order="rose"].
 *
 * rose also swaps the three text flourishes for its own squiggly-*-rose.svg.
 * That is a resources override on $s['resources'], not a layer here — the text
 * partial picks it up on its own.
 */
?>
<div class="award-template" data-order="rose" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php scroll_partial('parchment'); ?>

    <?php /* The burn sits BELOW the heraldry — unlike every other order that has
             one. Keep it above parchment and under the device. */ ?>
    <img <?= scroll_art_attrs('rose/burn.svg') ?> style="mix-blend-mode:multiply;opacity:0.2;filter:hue-rotate(80deg);" alt="">

    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* Two passes of the same border: a multiply to bite into the parchment,
             then a near-opaque normal pass to bring the ink back. DELIBERATE. */ ?>
<?php /* Custom Border: the knot replaces both passes — one knot per pass, with its
         blend, in match mode; one plain knot in exact mode, still below the symbol.
         At column 0 so a certificate without one prints the bytes it always did. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <img <?= scroll_art_attrs('rose/border.png') ?> style="mix-blend-mode:multiply;" alt="">
    <img <?= scroll_art_attrs('rose/border.png') ?> style="opacity:0.85;" alt="">
<?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>

    <?php /* rose ships symbol_0.png, so the path is unguarded — rank 0 draws the
             unranked device, exactly as the source does. No blend mode here. */ ?>
    <div class="centre-bottom"><img <?= scroll_art_attrs('rose/symbol_' . (int) $s['rank'] . '.png') ?> alt=""></div>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
