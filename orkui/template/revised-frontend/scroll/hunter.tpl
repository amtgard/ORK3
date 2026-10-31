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
<?php /* The exact-colour knot sits BELOW the heraldry (spec), so arms an officer scales
         or moves into the frame stay on top of it. Nothing else lies between. */ ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* Border drawn twice: a multiply pass, then an un-blended pass at 0.7.
             DELIBERATE — the second pass restores the linework the multiply ate.
             mix-blend-mode:normal is written out because the source writes it. */ ?>
<?php /* Custom Border: the knot replaces both passes — one knot per pass, with its
         blend, in match mode; one plain knot in exact mode, just below the heraldry.
         At column 0 so a certificate without one prints the bytes it always did. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <img <?= scroll_art_attrs('hunter/border.png') ?> style="mix-blend-mode:multiply;" alt="">
    <img <?= scroll_art_attrs('hunter/border.png') ?> style="mix-blend-mode:normal;opacity:0.7;" alt="">
<?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>

    <?php /* hunter ships symbol_0.png, so the symbol path is UNGUARDED. Same two-pass
             treatment as the border — multiply, then a normal pass at 0.7. */ ?>
    <div class="centre-bottom"><img <?= scroll_art_attrs('hunter/symbol_' . (int) $s['rank'] . '.png') ?> style="mix-blend-mode:multiply;" alt=""></div>
    <div class="centre-bottom"><img <?= scroll_art_attrs('hunter/symbol_' . (int) $s['rank'] . '.png') ?> style="mix-blend-mode:normal;opacity:0.7;" alt=""></div>

    <?php /* No common/rank_N.png numeral — hunter's symbol art carries the rank. */ ?>
    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
