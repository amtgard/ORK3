<?php
/**
 * Order of the Jovious. Transliterated from
 * arc-scroll/src/award/templates/ash/jovious.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * jovious does NOT draw the shared parchment: bg.jpg replaces it as the ground.
 * The source view has no <style> block, so this order owns no scrollery.css rules.
 *
 * The source nests two identical <div class="award-template"> wrappers around the
 * same layers — a copy-paste defect, not a stacking effect (an empty wrapper adds
 * nothing to a blend). One wrapper is emitted here.
 *
 * jovious's three text flourishes are the ribbon-*.png files rather than the shared
 * squigglies; that override lives in the order's resources array, so _text.tpl picks
 * it up with no work here.
 */
?>
<div class="award-template" data-order="jovious" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php /* Replaces the parchment partial. */ ?>
    <img src="<?= scroll_art('jovious/bg.jpg') ?>" alt="">

    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* jovious ships symbol_0.png, so the path is unguarded — as in the source. */ ?>
    <div class="centre-bottom"><img src="<?= scroll_art('jovious/symbol_' . (int) $s['rank'] . '.png') ?>" alt=""></div>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
