<?php
/**
 * Order of the Crown. Transliterated from
 * arc-scroll/src/award/templates/ash/crown.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * The source view's <style> block (the SignAndDate min-height/flex-basis/width/
 * margin override plus the SignAndDate>div width reset) lives in scrollery.css,
 * keyed on [data-order="crown"].
 *
 * The source interpolated ${award.order} into the border and phoenix paths; it
 * resolves to "crown", written literally here.
 */
?>
<div class="award-template" data-order="crown" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php scroll_partial('parchment'); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* Two passes of the same border: a multiply, then a half-opacity plain
             pass over it. DELIBERATE — never "deduplicate" this. */ ?>
<?php /* Custom Border: the knot replaces both passes — one knot per pass, with its
         blend, in match mode; one plain knot in exact mode, still below the symbol.
         At column 0 so a certificate without one prints the bytes it always did. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <img <?= scroll_art_attrs('crown/border.png') ?> style="mix-blend-mode:multiply;" alt="">
    <img <?= scroll_art_attrs('crown/border.png') ?> style="opacity:0.5;" alt="">
<?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>

    <?php /* Same two-pass treatment for the phoenix. It sits in .centre — vertically
             centred — not .centre-bottom like the rank symbol below. */ ?>
    <div class="centre"><img <?= scroll_art_attrs('crown/phoenix.png') ?> style="mix-blend-mode:multiply;" alt=""></div>
    <div class="centre"><img <?= scroll_art_attrs('crown/phoenix.png') ?> style="opacity:0.5;" alt=""></div>

    <?php /* crown ships symbol_0.png, so the path is unguarded — as in the source. */ ?>
    <div class="centre-bottom"><img <?= scroll_art_attrs('crown/symbol_' . (int) $s['rank'] . '.png') ?> alt=""></div>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
