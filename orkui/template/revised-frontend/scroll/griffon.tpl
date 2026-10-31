<?php
/**
 * Order of the Griffin. Transliterated from
 * arc-scroll/src/award/templates/ash/griffon.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * The source view's <style> block (SignAndDate min-height, and the swap of
 * Border-Deco-1 for Border-Deco-Special-Griffon) lives in scrollery.css, keyed on
 * [data-order="griffon"].
 */
?>
<div class="award-template" data-order="griffon" data-rank="<?= (int) $s['rank'] ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php scroll_partial('parchment'); ?>
<?php /* Custom Border: the knot takes the border pass's place, griffon plate and all —
         the plate is drawn inside that pass. Match mode draws it here, under the
         heraldry; exact mode just below the heraldry too. At column 0 so a certificate
         without one prints the bytes it always did. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <?php scroll_partial('border', ['primary' => $s['primary'], 'secondary' => $s['secondary'], 'griffons' => true]); ?>
<?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>
<?php /* The exact-colour knot sits BELOW the heraldry (spec), so arms an officer scales
         or moves into the frame stay on top of it. Nothing else lies between. */ ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php
    // griffon ships no symbol_0.png; the unranked certificate borrows symbol_5,
    // exactly as the source does.
    $sym = (int) $s['rank'] === 0 ? 5 : (int) $s['rank'];
    ?>
    <?php /* Two identical soft-light layers. DELIBERATE — stacking them deepens the
             blend. Never "deduplicate" this. */ ?>
    <div class="centre-bottom"><img <?= scroll_art_attrs('griffon/symbol_' . $sym . '.png') ?> style="mix-blend-mode:soft-light;" alt=""></div>
    <div class="centre-bottom"><img <?= scroll_art_attrs('griffon/symbol_' . $sym . '.png') ?> style="mix-blend-mode:soft-light;" alt=""></div>

    <?php if ((int) $s['rank'] > 0): $offsets = [85, 85, 85, 85, 85, 440, 440, 440, 235, 185]; ?>
        <div class="centre-bottom" style="bottom:<?= $offsets[(int) $s['rank'] - 1] ?>px;">
            <img <?= scroll_art_attrs('common/rank_' . (int) $s['rank'] . '.png') ?> alt="">
        </div>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
