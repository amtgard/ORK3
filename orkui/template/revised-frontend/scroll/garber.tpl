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
    <img class="bleed" src="<?= $art ?>/garber/linen.jpg" alt="">

    <?php /* Heraldry is smaller and sits higher than the 420/420/50 default. */ ?>
    <?php scroll_partial('heraldry', ['s' => $s, 'width' => 380, 'height' => 380, 'top' => 35]); ?>

    <img src="<?= $art ?>/garber/overlay.png" alt="">

    <?php /* garber ships rank_0.png, so the path is unguarded — as in the source. */ ?>
    <div class="centre-bottom"><img src="<?= $art ?>/garber/rank_<?= (int) $s['rank'] ?>.png" alt=""></div>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
