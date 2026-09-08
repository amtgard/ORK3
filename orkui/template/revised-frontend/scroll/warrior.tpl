<?php
/**
 * Order of the Warrior. Transliterated from
 * arc-scroll/src/award/templates/ash/warrior.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * Warrior is the only order whose rank range is 1-10 rather than 0-10 (see
 * data/scroll-orders.php), and rank 10 is a different certificate: it swaps the
 * shared parchment for its own soft-light ground and has no border art at all,
 * because the tenth-level flame distortion is painted into that ground.
 *
 * The source carried EIGHT per-rank <style> blocks (SignAndDate min-heights for
 * ranks 2, 3, 4, 5, 6, 8 and 9, plus the rank-9 column layout). They live in
 * scrollery.css keyed on [data-order="warrior"][data-rank="N"]. Ranks 1, 7 and 10
 * genuinely have no override — that is the source, not an omission.
 */
$rank = (int) $s['rank'];
?>
<div class="award-template" data-order="warrior" data-rank="<?= $rank ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php if ($rank !== 10): ?>
        <?php scroll_partial('parchment'); ?>
    <?php endif; ?>

    <?php /* Tenth level has the flame distortion to worry about, so it brings its
             own ground instead of the shared parchment. No .bleed class — the
             source's <img> had none, and .award-template > * already full-bleeds
             it. */ ?>
    <?php if ($rank === 10): ?>
        <img <?= scroll_art_attrs('warrior/bg_10.jpg') ?> style="mix-blend-mode:soft-light" alt="">
    <?php endif; ?>

    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* All but tenth level have a separate border; there is no border_10.jpg. */ ?>
    <?php if ($rank !== 10): ?>
        <img <?= scroll_art_attrs('warrior/border_' . $rank . '.jpg') ?> style="mix-blend-mode:multiply;" alt="">
    <?php endif; ?>

    <?php /* Ninth level Big Scary Dragon. The source interpolated the rank into the
             filename inside a rank == 9 guard, so this only ever resolves to
             symbol_9.png — the only symbol warrior ships. */ ?>
    <?php if ($rank === 9): ?>
        <div class="centre-bottom">
            <img <?= scroll_art_attrs('warrior/symbol_' . $rank . '.png') ?> alt="">
        </div>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
