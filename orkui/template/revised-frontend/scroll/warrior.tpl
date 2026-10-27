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
             it.

             With a Custom Border it draws bg_10-noborder.jpg instead: the same ground
             with its painted frame taken out, so the knot is the only frame. */ ?>
    <?php if ($rank === 10): ?>
        <img <?= scroll_art_attrs(scroll_custom_ground($s, 'warrior/bg_10.jpg')) ?> style="mix-blend-mode:soft-light" alt="">
    <?php endif; ?>

<?php /* The exact-colour knot sits BELOW the heraldry (spec), so arms an officer scales
         or moves into the frame stay on top of it. Nothing else lies between. */ ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* All but tenth level have a separate border; there is no border_10.jpg. */ ?>
    <?php if ($rank !== 10 && !scroll_custom_border_active($s)): ?>
        <img <?= scroll_art_attrs('warrior/border_' . $rank . '.jpg') ?> style="mix-blend-mode:multiply;" alt="">
    <?php endif; ?>
<?php /* Custom Border: the knot draws in the border's slot at every rank — in place of
         border_N on 1-9, and on the tenth where the frame came out of the ground.
         At column 0 so a certificate without one prints the bytes it always did. */ ?>
<?php scroll_custom_border_slot($s, 'match'); ?>
<?php /* The rank animal of levels 1-8 is painted INTO border_N.jpg, in the frame's
         bottom gap: warrior ships no other rank art below the ninth. So with a Custom
         Border the same file comes back, above the knot and clipped to the clear
         paper the animal sits on: the column between the frame's sides (ink ends at
         x 238 / 2312) above the bottom band (ink starts at y 3057), plus the band's
         own gap (x 985-1564). Measured on the shipped art: no frame ink in that
         region on any rank and no knot ink either, and on ranks 2-7 every animal
         pixel is inside it. The snake (1) and the panther's tail (8) cross the band
         and are cut at the gap's edges, where they run under the knot; the tail's
         tip, which lies on the band's top edge right of the gap (x 1630-1760,
         y 3007-3056), is notched out on 8 so it does not float free of the tail. */ ?>
<?php if ($rank >= 1 && $rank <= 8 && scroll_custom_border_active($s)): ?>
<?php $keepRight = $rank === 8 ? '90.1961% 90.9091%,62.7451% 90.9091%,62.7451% 92.4242%' : '90.1961% 92.4242%'; ?>
    <img <?= scroll_art_attrs('warrior/border_' . $rank . '.jpg') ?> class="sc-rank-keep" style="mix-blend-mode:multiply;clip-path:polygon(9.8039% 65.1515%,90.1961% 65.1515%,<?= $keepRight ?>,61.3725% 92.4242%,61.3725% 100%,38.6275% 100%,38.6275% 92.4242%,9.8039% 92.4242%);" alt="">
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
