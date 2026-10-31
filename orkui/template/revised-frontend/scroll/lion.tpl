<?php
/**
 * Order of the Lion. Transliterated from
 * arc-scroll/src/award/templates/ash/lion.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * The source view's <style> block (SignAndDate min-height:550px) lives in
 * scrollery.css, keyed on [data-order="lion"].
 *
 * The source <require>s award-border but never places an <award-border> element,
 * so no border partial is drawn here — lion's frame is its own border.png.
 */
?>
<div class="award-template" data-order="lion" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php scroll_partial('parchment'); ?>
<?php /* The exact-colour knot sits BELOW the heraldry (spec), so arms an officer scales
         or moves into the frame stay on top of it. Nothing else lies between. */ ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* Border drawn twice: a multiply pass, then a soft-light wash over it.
             DELIBERATE — the second pass lifts the first. Never merge these. */ ?>
<?php /* Custom Border: the knot replaces both passes — one knot per pass, with its
         blend, in match mode; one plain knot in exact mode, just below the heraldry.
         At column 0 so a certificate without one prints the bytes it always did. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <img <?= scroll_art_attrs('lion/border.png') ?> style="mix-blend-mode:multiply;" alt="">
    <img <?= scroll_art_attrs('lion/border.png') ?> style="mix-blend-mode:soft-light;opacity:0.3;" alt="">
<?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>

    <?php /* lion ships symbol_0.png, so the symbol path is UNGUARDED. Same two-pass
             treatment as the border — multiply, then a screen wash. */ ?>
    <div class="centre-bottom"><img <?= scroll_art_attrs('lion/symbol_' . (int) $s['rank'] . '.png') ?> style="mix-blend-mode:multiply;" alt=""></div>
    <div class="centre-bottom"><img <?= scroll_art_attrs('lion/symbol_' . (int) $s['rank'] . '.png') ?> style="mix-blend-mode:screen;opacity:0.3;" alt=""></div>

    <?php /* No common/rank_N.png numeral — lion's symbol art carries the rank. */ ?>
    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
