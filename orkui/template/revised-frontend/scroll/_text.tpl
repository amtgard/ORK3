<?php
/**
 * The typography layer. Transliterated from
 * arc-scroll/src/award/templates/ash/common/text/text.html.
 *
 * The source view's <style> block is NOT here — it lives in style/scrollery.css.
 * Aurelia scoped it to a shadow root; inlined here it would be global, and on a
 * bulk page of mixed orders the last certificate's rules would win for all of them.
 *
 * Five class names came across from Arcanyst with NO rule behind them anywhere —
 * `UnderlinedBlank`, `KeepUnderline`, `FormFill`, `Keep` and `Cull`. They were
 * hooks for Aurelia bindings this port does not have, and repo-wide there is not a
 * single `.UnderlinedBlank`/`.KeepUnderline`/`.FormFill`/`.Keep`/`.Cull` selector in
 * any stylesheet or script. They are removed rather than left to read as live
 * styling hooks. `Multiline` was the sixth and is the one that had to STAY, because
 * it turns out to be load-bearing: 230 `ork_awards.note` rows contain newlines, and
 * without `white-space: pre-line` a hand-formatted citation prints as one run-on.
 * It is defined in scrollery.css as `.Award .Multiline`. What used to be here was an
 * inline `white-space: pre-line` on the flavour block only — the reason block, which
 * is where the real newlines live, never got it.
 *
 * `Underline` is NOT dead; it has rules. The overflow that "culls" the surplus ruled
 * lines is `.Award .Body.Award_Reason { overflow: hidden }`, not a `.Cull` rule.
 *
 * Two font sizes scale with string length, as in the source. The lengths are cast
 * to int before they reach the calc() so nothing in a persona or a reason can break
 * out of the style attribute.
 *
 * Those formulas are a FIRST GUESS, not the fit. They are what a JS-less render
 * gets, and they are deliberately kept so such a render still looks roughly right;
 * the actual fitting is measured in the browser, by script/scrollery-typeset.js for
 * the top half (the issuing lines, the name and the order line) and by
 * script/scrollery-autofit.js for the rest. Do not "simplify" them away — remove them
 * and a no-script print falls back to 1.7em for a 100-character persona.
 *
 * The top half's guesses are tied together so that a render with no script keeps the
 * hierarchy the engine enforces (spec 6.4.2): the order line is guessed at 0.85 of
 * the name's guess and never above 1.5em, and the place at the old length formula
 * under a 1.3em ceiling. The name's guess is computed here as a number for that, and
 * is the same formula the inline calc() below states for the browser.
 *
 * The role elements (`data-sc-role`) are the elements whose size and lines the engine
 * writes, and the markup around them is the contract it measures against: two <p> as
 * the first and second paragraphs of the issuing section, the three flourish ids, and
 * `data-sc-ink` on each flourish. See scrollery.css section 3 for the CSS half.
 *
 * The lengths are mb_strlen(), not strlen(). 155 personas in the ORK are multibyte
 * and strlen() counts BYTES, so `Nokren ᚨᛒᛟᛟᚹᚺᛟᚷᛟᛞ` (17 characters, 37 bytes)
 * guessed a size for a 37-character name and shrank to the floor.
 */
$res         = $s['resources'];
$ordinals    =['', 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth', 'eleventh', 'twelfth'];

/*
 * `ordinalRank`, not `rank`. They are the same number for every grant up to the
 * tenth and differ above it: `rank` is clamped to the art the order ships (there is
 * no eleventh numeral to draw), while `ordinalRank` is what the row actually says.
 * Writing the word from the art's number is what made 79 live grants — rank 11 to
 * rank 22 — all letter "tenth". See Scroll::OrdinalRank().
 *
 * ?? for a scroll array built before that key existed (Render() is reachable with
 * any such array, and a reprint route or a test may hand in an old one).
 */
$rank        = (int) ($s['ordinalRank'] ?? $s['rank']);

/*
 * Past the last named ordinal, print the NUMERAL rather than nothing. The old
 * `$ordinals[$rank] ?? ''` printed "with their " and stopped. This branch is live
 * now rather than defensive: ranks 13 and up exist in the data and reach here.
 * Rank 0 keeps its empty string on purpose: the ordinal is a hand-written blank
 * there, drawn as the writing-line image below.
 */
$ordinalWord = $ordinals[$rank] ?? '';
if ($ordinalWord === '' && $rank > 0) {
    $mod100      = $rank % 100;
    $ordinalWord = $rank . (($mod100 >= 11 && $mod100 <= 13)
        ? 'th'
        : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$rank % 10] ?? 'th'));
}

// ${award.player.length}
$playerLen   = mb_strlen((string) $s['player'], 'UTF-8');
// ${award.reason.length ? award.reason.length : 200} — an empty reason must not
// compute a size from 0, which would be far larger than the box.
$reasonLen   = mb_strlen((string) $s['reason'], 'UTF-8') ?: 200;

/*
 * The citation's formula, as data for scrollery-autofit.js: floor, intercept, slope,
 * divisor, and the length to substitute when the text is empty (0 = use the real
 * length). Emitted rather than restated in the JS so there is ONE place these
 * numbers live — change the calc() below and the client's ceiling changes with it.
 *
 * The client needs it because it fits text the server never saw. The composer's live
 * preview renders once with an empty reason and then types into the DOM, so the
 * server's guess there was computed for the 200-character stand-in: without
 * recomputing, the same note would preview at one size and print at another.
 * Re-deriving the ceiling from the text actually in the box is what makes the two
 * surfaces agree, which is the whole point of fitting the preview at all. The name's
 * and the place's sizes are the typeset engine's, which ignores any such data.
 */
$reasonFormula = '0.55,0.8,-0.15,150,200';

/*
 * The issuing line in the three pieces the certificate sets in separate sizes: the
 * connective ("and the Kingdom of"), the article ("the") and the place. BuildScroll()
 * splits them with ScrollText::IssuerLines(), the same rules the composer's preview
 * runs. A scroll array built before those keys existed (Render() is reachable with any
 * such array) is split here from the stored form, so it prints what it always printed.
 */
if (isset($s['issuerLead'], $s['issuerArticle'], $s['issuerPlace'])) {
    $issuerLead    = (string) $s['issuerLead'];
    $issuerArticle = (string) $s['issuerArticle'];
    $issuerPlace   = (string) $s['issuerPlace'];
} else {
    $lines         = ScrollText::IssuerLines(
        (string) ($s['issuerTitle'] ?? ''),
        (string) $s['issuerName'],
        (bool) ($s['useThe'] ?? false),
        (bool) ($s['useOf'] ?? false)
    );
    $issuerLead    = $lines['lead'];
    $issuerArticle = $lines['article'];
    $issuerPlace   = $lines['place'];
}

/*
 * First-guess sizes for the top half, as numbers, because two of them are derived from
 * each other.
 *
 * Name: the formula the inline calc() on .AwardeeName states, evaluated for this
 * persona. Award: 0.85 of that, never above the 1.5em it always was. Place: the old
 * length formula over the whole phrase as printed, under a 1.3em ceiling. Article: the
 * connective's size relative to the place's, so "the" is set at 1.04em (the lead's
 * ceiling) whatever the place was guessed at; --sc-art-size is what .sc-art reads.
 *
 * Printed with four decimals so the markup is stable and a test can read it.
 */
$nameGuess  = max(1.36, 2.55 - 0.374 * ($playerLen / 8));
$awardGuess = min(1.5, 0.85 * $nameGuess);
$placeLen   = max(10, mb_strlen(trim($issuerArticle . ' ' . $issuerPlace), 'UTF-8'));
$placeGuess = min(1.3, max(1.0, 1 + 0.5 * (14 / $placeLen)));
$fmtEm      = static fn (float $v): string => number_format($v, 4, '.', '');

$freeSpace = Scroll::FreeSpaceAttr($s);
/** The flourish's ink grid as an attribute, or nothing when the file has none. */
$inkAttr   = static function (string $resource): string {
    $grid = scroll_ink_grid($resource);

    return $grid === '' ? '' : ' data-sc-ink="' . scroll_e($grid) . '"';
};
?>
<div class="Award"<?= $freeSpace !== '' ? ' data-sc-free="' . scroll_e($freeSpace) . '"' : '' ?>>

    <section class="IssuingTextSection" data-sc-comp="classic">
        <p class="ScLead">
            <span class="ScVersal">Amtgard
                <img id="TopTextFlourish" src="<?= scroll_resource($res['flourish_top']) ?>" class="Flourish"<?= $inkAttr($res['flourish_top']) ?> alt="">
                <img id="BottomTextFlourish" src="<?= scroll_resource($res['flourish_bottom']) ?>" class="Flourish"<?= $inkAttr($res['flourish_bottom']) ?> alt="">
            </span>
            <span id="IssuerTitle" data-scroll-text="issuerTitle" data-sc-role="lead"><?= scroll_e($issuerLead) ?></span>
        </p>
        <p class="ScPlace">
            <?php /* The inline size is that formula under a 1.3em ceiling, the place's
                     maximum (spec 6.4.1). It is the size a render with no script prints.
                     --sc-art-size is the article's size relative to this span, so "the"
                     comes out at the lead's 1.04em whatever the place was guessed at. */ ?>
            <span data-scroll-text="issuerName" data-sc-role="place" style="font-size:min(1.3em, max(1em, calc(1em + 0.5em * (14 / <?= $placeLen ?>))));--sc-art-size:calc(1.04em / <?= $fmtEm($placeGuess) ?>)"><?php if ($issuerArticle !== ''): ?><span class="sc-art"><?= scroll_e($issuerArticle) ?></span> <?php endif; ?><?= scroll_e($issuerPlace) ?></span>
            <img id="RightTextFlourish" src="<?= scroll_resource($res['flourish_right']) ?>" class="Flourish"<?= $inkAttr($res['flourish_right']) ?> alt="">
        </p>
        <?php /* Centered's rule pair: two copies of the order's bottom swash, mirrored,
                 that close the issuing block under its last line. Hidden until the engine
                 chooses Centered (scrollery.css). They are children of the section and not
                 of a paragraph so they cannot become the section's first or second <p>. */ ?>
        <img class="Flourish ScRule ScRule--l" src="<?= scroll_resource($res['flourish_bottom']) ?>"<?= $inkAttr($res['flourish_bottom']) ?> alt="">
        <img class="Flourish ScRule ScRule--r" src="<?= scroll_resource($res['flourish_bottom']) ?>"<?= $inkAttr($res['flourish_bottom']) ?> alt="">
        <p class="IssuingVerb Body">
            <span>presents</span>
        </p>
    </section>

    <section class="ScName">
        <p class="AwardeeName" style="font-size:max(1.36em, calc(2.55em + -0.374em * ((<?= $playerLen ?> / 8))));">
            <span data-scroll-text="player" data-sc-role="name" data-blank="<?= scroll_resource($res['writing_line']) ?>" style="margin-top:0.3em;bottom:0;position:relative;">
                <?php if ((string) $s['player'] === ''): ?><img class="Underline" src="<?= scroll_resource($res['writing_line']) ?>" alt=""><?php endif; ?><?= scroll_e($s['player']) ?>
            </span>
        </p>
        <?php /* The div stays even when the phrase does not: it carries
                 .Award .Body.AwardDetails { margin-block-start: 0.2em }, and dropping it
                 would shift the block spacing on every unranked certificate.

                 $s['ranked'] is false only for an award with no ordinal at all — a
                 Knighthood, a Master, a title. A LADDER at rank 0 stays ranked and keeps
                 its blank, which is the whole point of the blank: somebody writes the
                 level in by hand. See Scroll::BuildScroll(). */ ?>
        <div class="Body AwardDetails">
            <?php if (!empty($s['ranked'])): ?>
            <span style="display:inline;" data-scroll-text="rankPhrase">
                with <?= scroll_e($s['pronoun'] ?: 'their') ?> <?= scroll_e($ordinalWord) ?>
                <?php if ($rank === 0): ?><img class="Underline" style="width:4em;" src="<?= scroll_resource($res['writing_line']) ?>" alt=""><?php endif; ?>
            </span>
            <?php /* A Custom Title has no ordinal, so the branch above renders nothing and
                     the recipient's name used to run straight into a bare phrase — two
                     unrelated lines rather than a sentence. Scroll::TitlePhrase() supplies
                     the introducing words; it returns '' for every other award, so this and
                     the rank phrase can never both appear. The decision of WHICH awards get
                     a phrase is the domain's, not this template's. */ ?>
            <?php elseif (($s['titlePhrase'] ?? '') !== ''): ?>
            <span style="display:inline;" data-scroll-text="titlePhrase"><?= scroll_e($s['titlePhrase']) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="Order" data-sc-role="award" style="font-size:<?= $fmtEm($awardGuess) ?>em"><?= scroll_e($s['title']) ?></section>
    <?php /* The one elastic gap in the top half. It takes what the rigid stack above
             leaves, between 0.3em and 1.2em, so a short certificate breathes between the
             order line and "for" instead of between every line above it. Anything past
             its cap flows to the spacers below. Empty and decorative. */ ?>
    <div class="ScSpacer" aria-hidden="true"></div>
    <section class="Body" style="display:inline;flex-grow:0;margin-top:0.3em;margin-block-end:0em;font-size:0.6em">for</section>
    <?php /* The source carried a css= binding AND a style= here; Aurelia merged them, plain HTML would drop the second. */ ?>
    <section class="Body Award_Reason Multiline" data-af-len="<?= $reasonFormula ?>" style="font-size:max(0.55em, calc(0.8em - 0.15em * (<?= $reasonLen ?> / 150)));margin-left:0.5em;margin-right:0.5em;margin-bottom:0.5em;flex-grow:0;">
        <div style="padding-top:0.42em;position:absolute;left:0;right:0;top:0;bottom:0;pointer-events:none;">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <img class="Underline" src="<?= scroll_resource($res['writing_line']) ?>" alt="">
            <?php endfor; ?>
        </div>
        <p data-scroll-text="reason" style="display:inline-block;"><?= scroll_e($s['reason']) ?><?php if ((string) $s['reason'] === ''): ?><span><br/><br/></span><?php endif; ?></p>
    </section>
    <div style="flex-grow:0.5;"></div>
    <section class="Body Flavour Multiline" style="margin-block-start:1em;margin-left:-0.3em;margin-right:-0.3em;font-size:0.65em;">
        <span><?= scroll_e($s['flavour']) ?></span>
    </section>
    <div style="flex-grow:0.7;"></div>
    <section class="SignAndDate">
        <div class="AwardDate">
            <p data-scroll-text="date" style="min-height:1.2em;font-style:normal;font-size:0.7em;" class="Body"><?= scroll_e($s['date']) ?></p>
            <p style="font-size:0em">
                <img class="Underline" src="<?= scroll_resource($res['writing_line']) ?>" alt="">
            </p>
            <p class="Body" style="display:inline-block;margin-block-start:0.4em;"><b><span>On this day...</span></b></p>
        </div>
        <div class="AwardSignatory">
            <p data-scroll-text="givenBy" style="min-height:1.2em;font-style:normal;font-size:0.7em;" class="Body"><?= scroll_e($s['givenBy']) ?></p>
            <p style="font-size:0em">
                <img class="Underline" src="<?= scroll_resource($res['writing_line']) ?>" alt="">
            </p>
            <p class="Body" style="display:inline-block;margin-block-start:0.4em;"><b><span>By my hand...</span></b></p>
        </div>
    </section>
</div>
