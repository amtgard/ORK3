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
<?php /* Custom Border: both border passes go, the glow included. Match mode draws two
         knots here — the plain pass and the blurred, dodged one, adjacent as they
         were; exact mode draws one plain knot just below the heraldry. At column 0
         so a certificate without one prints the bytes it always did. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <?php scroll_partial('border', ['primary' => $s['primary'], 'secondary' => $s['secondary']]); ?>
    <?php /* Glow pass. Same geometry, black fill, blurred and dodged. */ ?>
    <?php scroll_partial('border', [
        'primary'   => 'black',
        'secondary' => $s['secondary'],
        'style'     => 'filter:blur(13px);mix-blend-mode:color-dodge;',
    ]); ?>
<?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>
<?php /* The exact-colour knot sits BELOW the heraldry (spec), so arms an officer scales
         or moves into the frame stay on top of it. Nothing else lies between. */ ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* Bare <img>, not wrapped in .centre-bottom — a full-bleed multiply layer. */ ?>
<?php /* dragons.svg is the pair of dragon heads in the frame's side diamonds: it sits ON
         the frame line, so it goes with the border under a Custom Border (Task 6). */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <img <?= scroll_art_attrs('dragon/dragons.svg') ?> style="mix-blend-mode:multiply;" alt="">
<?php endif; ?>

    <?php /* under_0.png ships, so this path is unguarded across the whole rank range. */ ?>
    <div class="centre-bottom"><img <?= scroll_art_attrs('dragon/under_' . (int) $s['rank'] . '.png') ?> style="mix-blend-mode:multiply;" alt=""></div>

    <?php /* over_ art only exists from rank 6 up; the guard is what stops a broken image. */ ?>
    <?php if ((int) $s['rank'] >= 6): ?>
        <div class="centre-bottom"><img <?= scroll_art_attrs('dragon/over_' . (int) $s['rank'] . '.png') ?> alt=""></div>
    <?php endif; ?>

    <?php if ((int) $s['rank'] > 0): ?>
        <div class="centre-bottom" style="bottom:50px;">
            <img <?= scroll_art_attrs('common/rank_' . (int) $s['rank'] . '.png') ?> alt="">
        </div>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
