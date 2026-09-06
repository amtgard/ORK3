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
 * the actual fitting is measured in the browser by script/scrollery-autofit.js,
 * which restores these computed sizes as its own baseline before shrinking. Do not
 * "simplify" them away — remove them and a no-script print falls back to 1.7em for
 * a 100-character persona.
 *
 * The lengths are mb_strlen(), not strlen(). 155 personas in the ORK are multibyte
 * and strlen() counts BYTES, so `Nokren ᚨᛒᛟᛟᚹᚺᛟᚷᛟᛞ` (17 characters, 37 bytes)
 * guessed a size for a 37-character name and shrank to the floor.
 */
$res         = $s['resources'];
$assetsRoot  = rtrim(HTTP_ASSETS, '/') . '/';
$ordinals    = ['', 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth', 'eleventh', 'twelfth'];
$rank        = (int) $s['rank'];

/*
 * Past the last named ordinal, print the NUMERAL rather than nothing. The old
 * `$ordinals[$rank] ?? ''` printed "with their " and stopped — a silent, invisible
 * failure the moment anything lifts Scroll::ClampRank() past its current ceiling.
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

// ${Instance.IssuerName.length < 10 ? 10 : Instance.IssuerName.length}
$issuerLen   = max(10, mb_strlen((string) $s['issuerName'], 'UTF-8'));
// ${award.player.length}
$playerLen   = mb_strlen((string) $s['player'], 'UTF-8');
// ${award.reason.length ? award.reason.length : 200} — an empty reason must not
// compute a size from 0, which would be far larger than the box.
$reasonLen   = mb_strlen((string) $s['reason'], 'UTF-8') ?: 200;

/*
 * The same two formulas, as data for scrollery-autofit.js: floor, intercept, slope,
 * divisor, and the length to substitute when the text is empty (0 = use the real
 * length). Emitted rather than restated in the JS so there is ONE place these
 * numbers live — change the calc() below and the client's ceiling changes with it.
 *
 * The client needs them because it fits text the server never saw. The composer's
 * live preview renders once with an empty recipient and then types into the DOM, so
 * the server's guess there was computed for a zero-length name: without recomputing,
 * the same persona would preview at 2.55em and print at 1.36em. Re-deriving the
 * ceiling from the text actually in the box is what makes the two surfaces agree,
 * which is the whole point of fitting the preview at all.
 */
$nameFormula   = '1.36,2.55,-0.374,8,0';
$reasonFormula = '0.55,0.8,-0.15,150,200';
?>
<div class="Award">

    <section class="IssuingTextSection">
        <p>
            <span style="font-size:1.15em;margin-right:-10px;position:relative;">Amtgard
                <img id="TopTextFlourish" src="<?= $assetsRoot . $res['flourish_top'] ?>" class="Flourish" alt="">
                <img id="BottomTextFlourish" src="<?= $assetsRoot . $res['flourish_bottom'] ?>" class="Flourish" alt="">
            </span>
            <span id="IssuerTitle" data-scroll-text="issuerTitle">and<?= $s['useThe'] ? ' the' : '' ?> <?= scroll_e($s['issuerTitle']) ?><?= $s['useOf'] ? ' of' : '' ?></span>
            <br/>
        </p>
        <p>
            <span data-scroll-text="issuerName" style="font-size:max(1em, calc(1em + 0.5em * (14 / <?= $issuerLen ?>)))"><?= scroll_e($s['issuerName']) ?></span>
            <img id="RightTextFlourish" src="<?= $assetsRoot . $res['flourish_right'] ?>" class="Flourish" alt="">
        </p>
        <p class="IssuingVerb Body">
            <span>presents</span>
        </p>
    </section>

    <section style="flex-grow:0.4;">
        <p class="AwardeeName" data-af-len="<?= $nameFormula ?>" style="font-size:max(1.36em, calc(2.55em + -0.374em * ((<?= $playerLen ?> / 8))));">
            <span data-scroll-text="player" data-blank="<?= $assetsRoot . $res['writing_line'] ?>" style="margin-top:0.3em;bottom:0;position:relative;">
                <?php if ((string) $s['player'] === ''): ?><img class="Underline" src="<?= $assetsRoot . $res['writing_line'] ?>" alt=""><?php endif; ?><?= scroll_e($s['player']) ?>
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
                <?php if ($rank === 0): ?><img class="Underline" style="width:4em;" src="<?= $assetsRoot . $res['writing_line'] ?>" alt=""><?php endif; ?>
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

    <section class="Order" style="flex-grow:0;"><?= scroll_e($s['title']) ?></section>
    <section class="Body" style="display:inline;flex-grow:0;margin-top:0.3em;margin-block-end:0em;font-size:0.6em">for</section>
    <?php /* The source carried a css= binding AND a style= here; Aurelia merged them, plain HTML would drop the second. */ ?>
    <section class="Body Award_Reason Multiline" data-af-len="<?= $reasonFormula ?>" style="font-size:max(0.55em, calc(0.8em - 0.15em * (<?= $reasonLen ?> / 150)));margin-left:0.5em;margin-right:0.5em;margin-bottom:0.5em;flex-grow:0;">
        <div style="padding-top:0.42em;position:absolute;left:0;right:0;top:0;bottom:0;pointer-events:none;">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <img class="Underline" src="<?= $assetsRoot . $res['writing_line'] ?>" alt="">
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
                <img class="Underline" src="<?= $assetsRoot . $res['writing_line'] ?>" alt="">
            </p>
            <p class="Body" style="display:inline-block;margin-block-start:0.4em;"><b><span>On this day...</span></b></p>
        </div>
        <div class="AwardSignatory">
            <p data-scroll-text="givenBy" style="min-height:1.2em;font-style:normal;font-size:0.7em;" class="Body"><?= scroll_e($s['givenBy']) ?></p>
            <p style="font-size:0em">
                <img class="Underline" src="<?= $assetsRoot . $res['writing_line'] ?>" alt="">
            </p>
            <p class="Body" style="display:inline-block;margin-block-start:0.4em;"><b><span>By my hand...</span></b></p>
        </div>
    </section>
</div>
