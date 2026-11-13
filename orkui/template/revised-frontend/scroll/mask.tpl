<?php
/**
 * Order of the Mask. Transliterated from
 * arc-scroll/src/award/templates/ash/mask.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * The source view's <style> block (SignAndDate min-height:550px) lives in
 * scrollery.css, keyed on [data-order="mask"].
 *
 * The glow is a filter on the WRAPPING div, not on the <img> — the blur has to
 * spread past the image box for the bloom to read at all.
 */
$glow = 'filter:sepia(1) contrast(0.5) hue-rotate(200deg) saturate(5) blur(200px);mix-blend-mode:screen;';
?>
<div class="award-template" data-order="mask" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php scroll_partial('parchment'); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* Two passes of the same border: screen at .6 lifts it, multiply then
             sets the ink back down. DELIBERATE. */ ?>
<?php /* Custom Border: the knot replaces both passes — one knot per pass, with its
         blend, in match mode, here; one plain knot in exact mode, AFTER the last glow
         pass below (see there). At column 0 so a certificate without one prints the
         bytes it always did. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <img <?= scroll_art_attrs('mask/border.png') ?> style="mix-blend-mode:screen;opacity:0.6;" alt="">
    <img <?= scroll_art_attrs('mask/border.png') ?> style="mix-blend-mode:multiply;opacity:1;" alt="">
<?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>

    <?php /* mask's symbol is UNRANKED — one mask/symbol.png, drawn THREE times:
             glow, plain, then glow AGAIN after the rank numeral. That last pass is
             what puts the bloom over the numeral. Never deduplicate or reorder. */ ?>
    <div class="centre-bottom" style="<?= $glow ?>"><img <?= scroll_art_attrs('mask/symbol.png') ?> alt=""></div>
    <div class="centre-bottom"><img <?= scroll_art_attrs('mask/symbol.png') ?> alt=""></div>

    <?php if ((int) $s['rank'] > 0): ?>
        <div class="centre-bottom" style="bottom:60px;opacity:1;">
            <img <?= scroll_art_attrs('common/rank_' . (int) $s['rank'] . '.png') ?> alt="">
        </div>
    <?php endif; ?>

    <div class="centre-bottom" style="<?= $glow ?>"><img <?= scroll_art_attrs('mask/symbol.png') ?> alt=""></div>
<?php /* The exact-colour knot goes over BOTH glow passes. Each is a 200px screen bloom
         that reaches the knot's bottom ends either side of the gap: under the first
         pass the outlines printed grey-blue, and between the passes (below the
         numeral) still visibly lighter. Over the last pass it prints as picked. It
         still cannot cover the symbol or the numeral: the symbol's box is x 1005-1545,
         y 2591-3275 and the knot has no ink there (its bottom gap is x 977-1573). */ ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
