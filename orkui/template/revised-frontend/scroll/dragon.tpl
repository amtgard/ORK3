<?php
/**
 * Order of the Dragon. Transliterated from
 * arc-scroll/src/award/templates/ash/dragon.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * The source view's <style> block (SignAndDate min-height) lives in scrollery.css,
 * keyed on [data-order="dragon"].
 *
 * Dragon is the only order that draws the border partial twice. The second pass is
 * a glow, not a duplicate: black primary, blurred 13px, composited color-dodge.
 * That is what the optional $style parameter on _border.tpl exists for.
 */
?>
<div class="award-template" data-order="dragon" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php scroll_partial('parchment'); ?>
    <?php scroll_partial('border', ['primary' => $s['primary'], 'secondary' => $s['secondary']]); ?>
    <?php /* Glow pass. Same geometry, black fill, blurred and dodged. */ ?>
    <?php scroll_partial('border', [
        'primary'   => 'black',
        'secondary' => $s['secondary'],
        'style'     => 'filter:blur(13px);mix-blend-mode:color-dodge;',
    ]); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* Bare <img>, not wrapped in .centre-bottom — a full-bleed multiply layer. */ ?>
    <img src="<?= $art ?>/dragon/dragons.svg" style="mix-blend-mode:multiply;" alt="">

    <?php /* under_0.png ships, so this path is unguarded across the whole rank range. */ ?>
    <div class="centre-bottom"><img src="<?= $art ?>/dragon/under_<?= (int) $s['rank'] ?>.png" style="mix-blend-mode:multiply;" alt=""></div>

    <?php /* over_ art only exists from rank 6 up; the guard is what stops a broken image. */ ?>
    <?php if ((int) $s['rank'] >= 6): ?>
        <div class="centre-bottom"><img src="<?= $art ?>/dragon/over_<?= (int) $s['rank'] ?>.png" alt=""></div>
    <?php endif; ?>

    <?php if ((int) $s['rank'] > 0): ?>
        <div class="centre-bottom" style="bottom:50px;">
            <img src="<?= $art ?>/common/rank_<?= (int) $s['rank'] ?>.png" alt="">
        </div>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
