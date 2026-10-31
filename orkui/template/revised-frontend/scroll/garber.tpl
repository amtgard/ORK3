<?php
/**
 * Order of the Garber. Transliterated from
 * arc-scroll/src/award/templates/ash/garber.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * garber does NOT draw the shared parchment: linen.jpg replaces it as the ground.
 * The source view has no <style> block, so this order owns no scrollery.css rules —
 * it keeps the default SignAndDate height.
 */
?>
<div class="award-template" data-order="garber" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php /* Replaces the parchment partial. The source carries no width/height here. */ ?>
    <img class="bleed" <?= scroll_art_attrs('garber/linen.jpg') ?> alt="">

    <?php /* Heraldry is smaller and sits higher than the 420/420/50 default. */ ?>
    <?php scroll_partial('heraldry', ['s' => $s, 'width' => 380, 'height' => 380, 'top' => 35]); ?>

    <img <?= scroll_art_attrs('garber/overlay.png') ?> alt="">

    <?php /* garber ships rank_0.png, so the path is unguarded — as in the source.

             A FULL-BLEED layer, not a .centre-bottom box. Every garber rank plate is a
             whole 2550x3300 transparent page — the tape measure, the scissors, the
             thread and the numeral, composed against linen.jpg's own border — so the
             only correct placement is 0,0. A .centre-bottom box is bottom-anchored and
             holds its plate in an INLINE <img>, which puts a 25px descender strip
             underneath it: the plate drew at y=-25, its bottom border sat 25px above
             the top border that comes from linen.jpg, and a strip of bare linen showed
             at the page foot. custom.tpl already says this in as many words for the
             garber and flame rank sets ("A .centre-bottom box would shift them") and
             flame.tpl already draws its plate bare; garber.tpl was the one that did
             not. The badge plates the other fifteen orders draw are small and float
             clear of the page edge, so the same 25px is invisible there and they are
             deliberately left alone. */ ?>
    <img class="bleed" <?= scroll_art_attrs('garber/rank_' . (int) $s['rank'] . '.png') ?> alt="">

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
