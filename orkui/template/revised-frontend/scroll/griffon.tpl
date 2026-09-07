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
    <?php scroll_partial('border', ['primary' => $s['primary'], 'secondary' => $s['secondary'], 'griffons' => true]); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php
    // griffon ships no symbol_0.png; the unranked certificate borrows symbol_5,
    // exactly as the source does.
    $sym = (int) $s['rank'] === 0 ? 5 : (int) $s['rank'];
    ?>
    <?php /* Two identical soft-light layers. DELIBERATE — stacking them deepens the
             blend. Never "deduplicate" this. */ ?>
    <div class="centre-bottom"><img src="<?= scroll_art('griffon/symbol_' . $sym . '.png') ?>" style="mix-blend-mode:soft-light;" alt=""></div>
    <div class="centre-bottom"><img src="<?= scroll_art('griffon/symbol_' . $sym . '.png') ?>" style="mix-blend-mode:soft-light;" alt=""></div>

    <?php if ((int) $s['rank'] > 0): $offsets = [85, 85, 85, 85, 85, 440, 440, 440, 235, 185]; ?>
        <div class="centre-bottom" style="bottom:<?= $offsets[(int) $s['rank'] - 1] ?>px;">
            <img src="<?= scroll_art('common/rank_' . (int) $s['rank'] . '.png') ?>" alt="">
        </div>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
