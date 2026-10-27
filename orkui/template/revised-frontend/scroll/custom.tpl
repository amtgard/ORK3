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
 * cannot assume the pieces compose. Five of them do not, and each is handled against
 * the shipped files rather than against the vocabulary's key names:
 *
 *   - owl/border.jpg is an OPAQUE black plate, not a transparent frame;
 *   - battle/border_symbols.png is a 2292x292 band, not a 2550x3300 frame;
 *   - the `forge` and `arcane` grounds are soft-light washes meant to sit OVER the
 *     parchment, not base plates that replace it;
 *   - the zodiac/garber/flame numerals are whole-page plates, not the 292x171 glyph
 *     that common/rank_N.png is;
 *   - the `cyanotype` ground is dark, so every layer over it has to be lifted rather
 *     than multiplied down.
 *
 * Which names those are lives on Scroll (GROUND_WASHES, DARK_GROUNDS, BORDER_STYLES,
 * BORDER_BAND, RANK_SET_GLYPH, RANK_SET_OVERSIZED), guarded by
 * ScrollRecipeTest::testEveryRecipeExceptionStillNamesAnEntryInTheVocabulary — a
 * rename in scroll-recipes.php would otherwise leave every test green while the
 * certificate silently started printing wrong. The reasoning is on the constants.
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

$band  = $recipe['border'] === Scroll::BORDER_BAND;
// Scroll::BorderDrawsKnot(), not a restatement of it: the composer needs the same
// answer to stamp its swatches, and two copies of the predicate would drift the
// moment the 'knot' vocabulary key were renamed in scroll-recipes.php.
//
// 'none' is a third state, not the absence of the other two: NormaliseRecipe() forces
// it whenever the GROUND already carries a frame (flame, linen, jovious, sunburst,
// map), because those plates have the border painted in and anything drawn over one
// is a second border sitting on the first. Without this, $frame = !$knot would send
// an empty src to <img>.
$none  = $recipe['border'] === Scroll::BORDER_NONE;
$knot  = !$none && Scroll::BorderDrawsKnot((string) $recipe['border']);
$frame = !$none && !$knot;
$dark  = in_array($recipe['ground'], Scroll::DARK_GROUNDS, true);

$wash = Scroll::GROUND_WASHES[$recipe['ground']] ?? '';

/*
 * Each order draws its own border with a blend pair its artist tuned for that art
 * (multiply, then a second lifting pass at some opacity). The custom scroll draws
 * one pass — the multiply all nine of them start from — because the second pass is
 * per-art tuning, not a general rule.
 *
 * The tone is the ground's, not the border's: a frame that multiplies correctly into
 * parchment lands DARKER than a dark ground and reads as a hole rather than a frame.
 * BORDER_STYLES carries the per-tone defaults and owl's two exceptions.
 */
$tone        = $dark ? 'dark' : 'light';
$borderStyle = Scroll::BORDER_STYLES[$recipe['border']][$tone] ?? Scroll::BORDER_STYLE_DEFAULT[$tone];

/*
 * On a dark ground the knot has to be screened rather than multiplied. Multiplied,
 * the default tan primary (L~150) lands at L~34 against a ground of 57 and the frame
 * reads as a dim silhouette — which also makes the curated colour pair, the entire
 * point of the border-colour slot, invisible. _border.tpl appends $style after its
 * own declarations, so the later mix-blend-mode wins; dragon.tpl overrides the same
 * way for its glow pass.
 */
$knotStyle = Scroll::CustomKnotStyle((string) $recipe['ground']);
?>
<?php /* data-rankset is here for the same reason data-ground is: the numeral's
         legibility is a property of the PAIR, and only the pair is known here.
         scrollery.css reads both. */ ?>
<?php /* --sc-sign-lift-want: how far to raise the date and signature so they are
         not printed on the border's corner ornaments or on a full-page rank plate's
         own furniture. Measured per (border, rank set, rank) — see
         Scroll::SignLiftFor(). It is a ceiling; scrollery-autofit.js clamps it to
         whatever room the citation left. */ ?>
<?php /* --sc-safe-inset: where THIS border's art stops. The custom scroll is the one
         place any border meets any ground with nobody having composed the pair by
         hand, and four of the nine frames intrude past the flat padding .Award used
         to rely on — hunter by 159px. Scroll::SafeInsetFor() never returns less than
         that padding, so this can only ever move text further from the frame. */ ?>
<div class="award-template" data-order="custom" data-rank="<?= $rank ?>" data-ground="<?= scroll_e($recipe['ground']) ?>" data-rankset="<?= scroll_e($recipe['rankSet']) ?>"<?= in_array($recipe['rankSet'], Scroll::PALE_RANK_SETS, true) ? ' data-rank-ink="pale"' : '' ?> style="position:absolute;top:0;left:0;right:0;bottom:0;--sc-safe-inset:<?= Scroll::SafeInsetFor((string) $recipe['border']) ?>px;--sc-sign-lift-want:<?= Scroll::SignLiftFor((string) $recipe['border'], (string) $recipe['rankSet'], $rank) ?>px;">
    <?php /* Every ground is exactly 2550x3300, the foreignObject's own size, which is
             what makes any one of them a drop-in swap for any other. */ ?>
    <?php if ($wash !== ''): ?>
        <?php /* Blend and opacity are smith.tpl's and battle.tpl's. The PLACEMENT is
                 not theirs: both draw their wash over the heraldry, and battle over
                 the border too, whereas this draws it under everything. That is
                 deliberate — here the wash is filling the ground slot, so it has to
                 finish being the ground before any layer that reads against it. */ ?>
        <?php scroll_partial('parchment'); ?>
        <img class="bleed" src="<?= scroll_e(scroll_art($v['grounds'][$recipe['ground']])) ?>" width="2550" height="3300" style="<?= $wash ?>" alt="">
    <?php else: ?>
<?php /* scroll_custom_ground(): with a Custom Border on a framed ground (jovious,
         sunburst) the frameless twin is drawn instead, so the knot is the only frame
         on the page. A wash is never a framed ground, so the branch above needs no
         such swap. At column 0 so a certificate without one prints its old bytes. */ ?>
        <img class="bleed" src="<?= scroll_e(scroll_art(scroll_custom_ground($s, $v['grounds'][$recipe['ground']]))) ?>" width="2550" height="3300" alt="">
    <?php endif; ?>

<?php /* Custom Border: the recipe's one border pass — knot, raster frame or band — is
         replaced, whichever it was (CustomBorderRule() derives the pass from the
         recipe). Match mode draws the knot here, where the border was; exact mode just
         below the heraldry. At column 0 so a certificate without one
         prints the bytes it always did. */ ?>
<?php $customBorder = scroll_custom_border_active($s); ?>
    <?php if ($knot && !$customBorder): ?>
        <?php scroll_partial('border', [
            'primary'   => $recipe['primary'],
            'secondary' => $recipe['secondary'],
            'style'     => $knotStyle,
        ]); ?>
    <?php endif; ?>

    <?php if ($frame && !$customBorder): ?>
        <img class="bleed" <?= scroll_art_attrs($v['borders'][$recipe['border']]) ?> style="<?= $borderStyle ?>" alt="">
    <?php endif; ?>
<?php scroll_custom_border_slot($s, 'match'); ?>

<?php /* The exact-colour knot sits BELOW the heraldry (spec), so arms an officer scales
         or moves into the frame stay on top of it. Nothing else lies between. */ ?>
<?php scroll_custom_border_slot($s, 'exact'); ?>
    <?php if ($dark): ?>
        <?php /* On a dark ground the device cannot simply be screened: 624 of the 626
                 files under assets/heraldry are opaque JPEGs and most have a white
                 field, and screen(255, ground) is pure white — a 420x420 white box,
                 worse than the multiply it replaces. owl.tpl solves this by running
                 the device through #OWL_edge-detect FIRST (defined once per page by
                 Scrollery_render.tpl) so that what gets screened is line art, not a
                 plate. The wrapper is what that filter and its overflow clip hang
                 off; see the [data-ground] rules in scrollery.css. */ ?>
        <div class="custom-heraldry" style="mix-blend-mode:screen;"><?php scroll_partial('heraldry', ['s' => $s]); ?></div>
    <?php else: ?>
        <?php scroll_partial('heraldry', ['s' => $s]); ?>
    <?php endif; ?>

    <?php if ($band && !$customBorder): ?>
        <?php /* width="2291" is battle.tpl's own value; the file is one pixel wider
                 (2292). Kept as written there — changing it would resize the layer.

                 The band is dark line art on transparency like the eight raster
                 frames, so it takes the same per-tone default they do. */ ?>
        <div class="centre" style="margin-bottom:90px;">
            <img width="2291" src="<?= scroll_e(scroll_art($v['borders'][Scroll::BORDER_BAND])) ?>" style="<?= Scroll::BORDER_STYLE_DEFAULT[$tone] ?>" alt="">
        </div>
    <?php endif; ?>

    <?php /* Guarded on rank > 0 for EVERY set: common, zodiac and flame ship rank_1..10
             only. garber also ships a rank_0, which is deliberately never used here so
             the rule stays one rule.

             .custom-rank is the hook the dark-ground lift keys on — the numeral sits
             outside .Award, so the type rules do not reach it. */ ?>
    <?php if ($rank > 0): ?>
        <?php /* scroll_art(), not $art . '/…': the URL resolves through
                 assets/scrolls/renditions.json, so this is the print rendition on the
                 print route and the display rendition on the composer's preview stage,
                 and the master wherever WebP lost to it. The escaping moved outward to
                 cover the whole URL rather than the vocabulary segment alone. */ ?>
        <?php /* The numeral's own path, and the BOX it has to be drawn in. The box is
                 the point: ArtUrl() may serve a display rendition a third the size of
                 the master this layout was built against, and an <img> with no
                 width/height then draws a third of the size — which is what put the
                 whole of Order of the Flame's ground in the corner of the page. */ ?>
        <?php $rankRel  = $v['rankSets'][$recipe['rankSet']] . '/rank_' . $rank . '.png'; ?>
        <?php $rankAttr = scroll_art_attrs($rankRel); ?>
        <?php if ($recipe['rankSet'] === Scroll::RANK_SET_GLYPH): ?>
            <?php /* common/rank_N.png is a 292x171 glyph, so it needs a box and an
                     offset. 85px is griffon's own baseline for its ranks 1-5. */ ?>
            <div class="centre-bottom custom-rank" style="bottom:85px;">
                <img <?= $rankAttr ?> alt="">
            </div>
        <?php elseif ($recipe['rankSet'] === Scroll::RANK_SET_OVERSIZED): ?>
            <?php /* zodiac's numerals are 2625x3375 — a whole transparent plate, 75px
                     oversized on both axes. zodiac.tpl's margin:-37.5px re-centres it. */ ?>
            <div class="centre-bottom custom-rank">
                <img <?= $rankAttr ?> style="margin:-37.5px" alt="">
            </div>
        <?php else: ?>
            <?php /* garber and flame ship full 2550x3300 transparent plates that place
                     their own numeral, so they are their own full-bleed layer — as in
                     garber.tpl and flame.tpl. A .centre-bottom box would shift them. */ ?>
            <img class="bleed custom-rank" <?= $rankAttr ?> alt="">
        <?php endif; ?>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
