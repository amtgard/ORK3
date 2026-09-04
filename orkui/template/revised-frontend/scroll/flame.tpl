<?php
/**
 * Order of the Flame. Transliterated from
 * arc-scroll/src/award/templates/ash/flame.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * flame does NOT draw the shared parchment: bg.jpg replaces it as the ground. It is
 * also the only order in this group that requires no border partial at all.
 *
 * The source view's <style> block (SignAndDate min-height:650px) lives in
 * scrollery.css, keyed on [data-order="flame"].
 */
?>
<div class="award-template" data-order="flame" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php /* Replaces the parchment partial. */ ?>
    <img src="<?= $art ?>/flame/bg.jpg" alt="">

    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* flame ships no rank_0.png. The source's own `if.bind="award.rank > 0"` is
             what keeps an unranked certificate from requesting a missing file — this
             guard is load-bearing, not defensive. The numeral is a bare full-bleed
             layer here, not a .centre-bottom box. */ ?>
    <?php if ((int) $s['rank'] > 0): ?>
        <img src="<?= $art ?>/flame/rank_<?= (int) $s['rank'] ?>.png" alt="">
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
