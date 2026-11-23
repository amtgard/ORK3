<?php
/**
 * Order of the Smith. Transliterated from
 * arc-scroll/src/award/templates/ash/smith.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * The source view's <style> block (SignAndDate min-height:250px) lives in
 * scrollery.css, keyed on [data-order="smith"].
 */
?>
<div class="award-template" data-order="smith" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php scroll_partial('parchment'); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <img <?= scroll_art_attrs('smith/burn.jpg') ?> style="mix-blend-mode:soft-light;" alt="">

    <?php /* Two passes of the same border: multiply, then a normal pass at .6.
             DELIBERATE — stacking them deepens the blend. */ ?>
<?php /* Custom Border: the knot replaces both passes — one knot per pass, with its
         blend, in match mode; one plain knot in exact mode, still below the symbol.
         At column 0 so a certificate without one prints the bytes it always did. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <img <?= scroll_art_attrs('smith/border.png') ?> style="mix-blend-mode:multiply;" alt="">
    <img <?= scroll_art_attrs('smith/border.png') ?> style="opacity:0.6;" alt="">
<?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>

    <?php /* smith's symbol is UNRANKED — there is no symbol_${rank}.png, only the
             one smith/symbol.png, drawn twice. Never deduplicate these. */ ?>
    <div class="centre-bottom"><img <?= scroll_art_attrs('smith/symbol.png') ?> style="mix-blend-mode:multiply;" alt=""></div>
    <div class="centre-bottom"><img <?= scroll_art_attrs('smith/symbol.png') ?> style="opacity:0.3;" alt=""></div>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
