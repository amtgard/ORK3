<?php
/**
 * Order of the Hunter. Transliterated from
 * arc-scroll/src/award/templates/ash/hunter.html.
 *
 * This one partial serves all four of Arcanyst's archery order ids (hunter,
 * bowyer, marksman, sharpshooter). The art path is hardcoded to hunter/ exactly
 * as the source hardcodes it; the kingdom's own name for the award reaches the
 * certificate through $s['title'] (ork_kingdomaward.name), which is why the other
 * three are not orders in scroll-orders.php.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * The source view's <style> block (SignAndDate min-height:650px) lives in
 * scrollery.css, keyed on [data-order="hunter"].
 *
 * The source <require>s award-border but never places an <award-border> element,
 * so no border partial is drawn here — hunter's frame is its own border.png.
 */
?>
<div class="award-template" data-order="hunter" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php scroll_partial('parchment'); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* Border drawn twice: a multiply pass, then an un-blended pass at 0.7.
             DELIBERATE — the second pass restores the linework the multiply ate.
             mix-blend-mode:normal is written out because the source writes it. */ ?>
    <img src="<?= $art ?>/hunter/border.png" style="mix-blend-mode:multiply;" alt="">
    <img src="<?= $art ?>/hunter/border.png" style="mix-blend-mode:normal;opacity:0.7;" alt="">

    <?php /* hunter ships symbol_0.png, so the symbol path is UNGUARDED. Same two-pass
             treatment as the border — multiply, then a normal pass at 0.7. */ ?>
    <div class="centre-bottom"><img src="<?= $art ?>/hunter/symbol_<?= (int) $s['rank'] ?>.png" style="mix-blend-mode:multiply;" alt=""></div>
    <div class="centre-bottom"><img src="<?= $art ?>/hunter/symbol_<?= (int) $s['rank'] ?>.png" style="mix-blend-mode:normal;opacity:0.7;" alt=""></div>

    <?php /* No common/rank_N.png numeral — hunter's symbol art carries the rank. */ ?>
    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
