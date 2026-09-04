<?php
/**
 * The custom scroll — for awards with no design of their own.
 *
 * Composed from the vocabulary in system/lib/ork3/data/scroll-recipes.php rather
 * than fixed art: a full-page ground, a border (the parametric knotwork or a raster
 * overlay) and a rank numeral. Order symbols are deliberately absent; they are order
 * identity, and a griffon on a Knighthood reads as a mistake rather than a choice.
 *
 * Document order is z-order, exactly as in every other order partial.
 *
 * Unlike every other partial this one draws art it did not grow up with, so it
 * cannot assume the pieces compose. Four of them do not, and each is handled below
 * against the shipped files rather than against the vocabulary's key names:
 *
 *   - owl/border.jpg is an OPAQUE black plate, not a transparent frame;
 *   - battle/border_symbols.png is a 2292x292 band, not a 2550x3300 frame;
 *   - the `forge` and `arcane` grounds are soft-light washes meant to sit OVER the
 *     parchment, not base plates that replace it;
 *   - the zodiac/garber/flame numerals are whole-page plates, not the 292x171 glyph
 *     that common/rank_N.png is.
 *
 * Those are properties of the artwork, so the notes stay here with the markup that
 * accounts for them. Longer term they belong in the vocabulary itself — see the
 * Task 3 report.
 */
$v = Scroll::Recipes();

/*
 * Normalised again here, not merely defaulted. Render() is reachable with any scroll
 * array — the reprint route builds one, a test hands one in — and NormaliseRecipe is
 * the single allowlist keeping an unknown key out of an <img src> and an arbitrary
 * colour out of a style attribute. It is idempotent, so re-running it over the
 * recipe BuildScroll already normalised costs nothing and every lookup below is then
 * guaranteed to hit.
 */
$recipe = Scroll::NormaliseRecipe(is_array($s['recipe'] ?? null) ? $s['recipe'] : []);
$rank   = (int) $s['rank'];

/*
 * 'battle' is the one entry in the border vocabulary that is not a frame:
 * battle/border_symbols.png is a 2292x292 decorative band, where the other eight
 * rasters are full 2550x3300 frames. battle.tpl draws the knotwork border and then
 * centres that band over it, which is what happens here — stretched full-bleed a
 * 292px strip becomes vertical smear down the page, and a border choice that draws
 * no frame at all reads as a broken selection.
 */
$band  = $recipe['border'] === 'battle';
$knot  = $recipe['border'] === 'knot' || $band;
$frame = !$knot;

/*
 * Each order draws its own border with a blend pair its artist tuned for that art
 * (multiply, then a second lifting pass at some opacity). The custom scroll draws
 * one pass — the multiply all nine of them start from — because the second pass is
 * per-art tuning, not a general rule.
 *
 * owl is the exception and cannot take the default: owl/border.jpg is white line art
 * on an OPAQUE black plate, where the other eight are transparent PNGs. Multiplied
 * unfiltered it turns the whole certificate black. This is owl.tpl's own treatment
 * for its parchment variant, copied rather than reinvented.
 */
$borderStyle = $recipe['border'] === 'owl'
    ? 'filter:invert();mix-blend-mode:multiply;'
    : 'mix-blend-mode:multiply;';

/*
 * Two entries in the ground vocabulary are not base plates either. smith/burn.jpg
 * and battle/arcane/wash.jpg are soft-light WASHES: their own orders lay them over
 * the parchment rather than instead of it (smith.tpl, battle.tpl). Drawn alone each
 * is a flat mid-grey the certificate's type sinks into — measured mean luminance
 * 118 and 120 across the text box, against ~200 for every real ground. Composited
 * the way their own orders composite them, they are the ground those certificates
 * actually print on. Blend and opacity are copied from those two partials.
 */
$washes = [
    'forge'  => 'mix-blend-mode:soft-light;',
    'arcane' => 'mix-blend-mode:soft-light;opacity:0.8',
];
$wash = $washes[$recipe['ground']] ?? '';
?>
<div class="award-template" data-order="custom" data-rank="<?= $rank ?>" data-ground="<?= scroll_e($recipe['ground']) ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <?php /* Every ground is exactly 2550x3300, the foreignObject's own size, which is
             what makes any one of them a drop-in swap for any other. */ ?>
    <?php if ($wash !== ''): ?>
        <?php scroll_partial('parchment'); ?>
        <img class="bleed" src="<?= $art ?>/<?= scroll_e($v['grounds'][$recipe['ground']]) ?>" style="<?= $wash ?>" alt="">
    <?php else: ?>
        <img class="bleed" src="<?= $art ?>/<?= scroll_e($v['grounds'][$recipe['ground']]) ?>" width="2550" height="3300" alt="">
    <?php endif; ?>

    <?php if ($knot): ?>
        <?php scroll_partial('border', ['primary' => $recipe['primary'], 'secondary' => $recipe['secondary']]); ?>
    <?php endif; ?>

    <?php if ($frame): ?>
        <img class="bleed" src="<?= $art ?>/<?= scroll_e($v['borders'][$recipe['border']]) ?>" style="<?= $borderStyle ?>" alt="">
    <?php endif; ?>

    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php if ($band): ?>
        <?php /* width="2291" is battle.tpl's own value; the file is one pixel wider
                 (2292). Kept as written there — changing it would resize the layer. */ ?>
        <div class="centre" style="margin-bottom:90px;">
            <img width="2291" src="<?= $art ?>/<?= scroll_e($v['borders']['battle']) ?>" style="mix-blend-mode:multiply;" alt="">
        </div>
    <?php endif; ?>

    <?php /* Guarded on rank > 0 for EVERY set: common, zodiac and flame ship rank_1..10
             only. garber also ships a rank_0, which is deliberately never used here so
             the rule stays one rule. */ ?>
    <?php if ($rank > 0): ?>
        <?php $rankSrc = $art . '/' . scroll_e($v['rankSets'][$recipe['rankSet']]) . '/rank_' . $rank . '.png'; ?>
        <?php if ($recipe['rankSet'] === 'common'): ?>
            <?php /* common/rank_N.png is a 292x171 glyph, so it needs a box and an
                     offset. 85px is griffon's own baseline for its ranks 1-5. */ ?>
            <div class="centre-bottom" style="bottom:85px;">
                <img src="<?= $rankSrc ?>" alt="">
            </div>
        <?php elseif ($recipe['rankSet'] === 'zodiac'): ?>
            <?php /* zodiac's numerals are 2625x3375 — a whole transparent plate, 75px
                     oversized on both axes. zodiac.tpl's margin:-37.5px re-centres it. */ ?>
            <div class="centre-bottom">
                <img src="<?= $rankSrc ?>" style="margin:-37.5px" alt="">
            </div>
        <?php else: ?>
            <?php /* garber and flame ship full 2550x3300 transparent plates that place
                     their own numeral, so they are their own full-bleed layer — as in
                     garber.tpl and flame.tpl. A .centre-bottom box would shift them. */ ?>
            <img class="bleed" src="<?= $rankSrc ?>" alt="">
        <?php endif; ?>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
