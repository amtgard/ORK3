<?php
/**
 * Order of the Zodiac. Transliterated from
 * arc-scroll/src/award/templates/ash/zodiac.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * Zodiac is the one order that draws neither the shared parchment nor the border
 * partial: its two backgrounds are finished 2550x3300 plates that already carry
 * their own frame. The source view still `require`d award-parchment.html without
 * ever composing it; that require is dead and is not carried over.
 *
 * The source view's three <style> blocks — the .Award padding / Body / Flavour
 * rules, plus the rank-0 and ranked SignAndDate boxes — live in scrollery.css,
 * keyed on [data-order="zodiac"] and [data-rank].
 *
 * The flavour text still carries Arcanyst's <<MONTH>> placeholder verbatim; month
 * handling is a separate branch, and ranks stay clamped at 10.
 */
?>
<div class="award-template" data-order="zodiac" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php /* One whole-page plate or the other. Both are 2550x3300 — exactly the
             foreignObject's size — so, as in the source, they carry no class and
             no dimensions and still fill the certificate at their intrinsic size. */ ?>
    <?php if ((int) $s['rank'] === 0): ?>
        <img src="<?= scroll_art('zodiac/bg_generic.jpg') ?>" alt="">
    <?php else: ?>
        <img src="<?= scroll_art('zodiac/bg_rank.jpg') ?>" alt="">
    <?php endif; ?>

    <?php scroll_partial('heraldry', ['s' => $s, 'top' => 100, 'width' => 360, 'height' => 360]); ?>

    <?php /* zodiac ships no rank_0.png; the unranked certificate simply carries no
             numeral, which is what the source's if.bind does. */ ?>
    <?php if ((int) $s['rank'] > 0): ?>
        <div class="centre-bottom">
            <img src="<?= scroll_art('zodiac/rank_' . (int) $s['rank'] . '.png') ?>" style="margin:-37.5px" alt="">
        </div>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
