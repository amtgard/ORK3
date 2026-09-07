<?php
/**
 * Order of the Hydra. Transliterated from
 * arc-scroll/src/award/templates/ash/hydra.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * The source view's <style> block (SignAndDate min-height:650px) lives in
 * scrollery.css, keyed on [data-order="hydra"].
 *
 * The source <require>s award-border but never places an <award-border> element,
 * so no border partial is drawn here — hydra's frame is its own border.png.
 */
?>
<div class="award-template" data-order="hydra" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php scroll_partial('parchment'); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* Border drawn twice: a multiply pass, then a soft-light wash over it.
             DELIBERATE — the second pass lifts the first. Never merge these. */ ?>
    <img src="<?= scroll_art('hydra/border.png') ?>" style="mix-blend-mode:multiply;" alt="">
    <img src="<?= scroll_art('hydra/border.png') ?>" style="mix-blend-mode:soft-light;opacity:0.5;" alt="">

    <?php /* hydra ships symbol_0.png, so the symbol path is UNGUARDED. Same two-pass
             treatment as the border — multiply, then a soft-light wash. */ ?>
    <div class="centre-bottom"><img src="<?= scroll_art('hydra/symbol_' . (int) $s['rank'] . '.png') ?>" style="mix-blend-mode:multiply;" alt=""></div>
    <div class="centre-bottom"><img src="<?= scroll_art('hydra/symbol_' . (int) $s['rank'] . '.png') ?>" style="mix-blend-mode:soft-light;opacity:0.5;" alt=""></div>

    <?php /* No common/rank_N.png numeral — hydra's symbol art carries the rank. */ ?>
    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
