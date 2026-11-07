<?php
/**
 * Order of Battle. Transliterated from
 * arc-scroll/src/award/templates/ash/battle.html.
 *
 * Document order is z-order — every child is an absolutely-positioned full-bleed
 * layer. Do not reorder these siblings.
 *
 * Two variants, `arcane` and `martial`, and they differ by more than a symbol
 * path: arcane adds a soft-light wash and a glow plate and draws its symbol with
 * no blend at all, while martial multiplies its symbol and stacks an `over_`
 * plate from rank 6 up. Hence data-variant on the wrapper.
 *
 * battle has no <style> block of its own — no SignAndDate override, nothing to
 * move into scrollery.css.
 *
 * wash.jpg and glow.png are the two files that arrived as webpack content hashes
 * (ad39b48…jpg and 01b3c71…png) and were given real names under
 * assets/scrolls/battle/arcane/.
 */
$rank    = (int) $s['rank'];
$variant = (string) $s['variant'];
?>
<div class="award-template" data-order="battle" data-rank="<?= $rank ?>" data-variant="<?= scroll_e($variant) ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php scroll_partial('parchment'); ?>
<?php /* Custom Border: the knot partial is battle's one border pass, so the knot takes
         its place — here in match mode, under the heraldry where the knotwork was; in
         exact mode below the heraldry (martial) or over the wash (arcane, below). The
         band (border_symbols.png) goes too: its sword and arrow sit ON the frame line
         at both sides, and over the knot they read as a faint misprint (owner, Task 6).
         At column 0 so a certificate without one prints the bytes it always did. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <?php scroll_partial('border', ['primary' => $s['primary'], 'secondary' => $s['secondary']]); ?>
<?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>
<?php if ($variant !== 'arcane') { scroll_custom_border_slot($s, 'exact'); } ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php if ($variant === 'arcane'): ?>
        <img class="bleed" <?= scroll_art_attrs('battle/arcane/wash.jpg') ?> style="mix-blend-mode: soft-light; opacity:0.8" alt="">
    <?php endif; ?>

<?php /* Arcane's soft-light wash lies over the heraldry, so there the exact knot stays
         above the wash (exact colours win) and so above the heraldry; martial has no
         wash, and its exact knot sits below the heraldry like every other order's. */ ?>
<?php if ($variant === 'arcane') { scroll_custom_border_slot($s, 'exact'); } ?>
    <?php /* width="2291" is the source's own value; the file is one pixel wider
             (2292). Kept as written — changing it would resize the layer. */ ?>
<?php if (!scroll_custom_border_active($s)): ?>
    <div class="centre" style="margin-bottom:90px;">
        <img width="2291" height="292" src="<?= scroll_art('battle/border_symbols.png') ?>" style="mix-blend-mode: multiply;" alt="">
    </div>
<?php endif; ?>

    <?php if ($variant === 'arcane'): ?>
        <div class="centre-bottom"><img <?= scroll_art_attrs('battle/arcane/glow.png') ?> style="mix-blend-mode: multiply;" alt=""></div>
    <?php endif; ?>

    <?php /* Both variants ship symbol_0.png, so this path is unguarded. Aurelia's
             css= binding gave the symbol a blend for martial and an empty string
             for arcane, so arcane gets no style attribute at all. */ ?>
    <div class="centre-bottom">
        <img <?= scroll_art_attrs('battle/' . $variant . '/symbol_' . $rank . '.png') ?><?= $variant === 'arcane' ? '' : ' style="mix-blend-mode:multiply"' ?> alt="">
    </div>

    <?php /* martial/over_*.png exists only for ranks 6-10. */ ?>
    <?php if ($variant === 'martial' && $rank >= 6): ?>
        <div class="centre-bottom"><img <?= scroll_art_attrs('battle/martial/over_' . $rank . '.png') ?> style="mix-blend-mode:multiply" alt=""></div>
    <?php endif; ?>

    <?php if ($rank > 0): ?>
        <div class="centre-bottom" style="bottom:<?= $rank >= 9 ? '124px' : '134px' ?>;">
            <img <?= scroll_art_attrs('common/rank_' . $rank . '.png') ?> alt="">
        </div>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
