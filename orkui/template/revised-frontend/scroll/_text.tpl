<?php
/**
 * The typography layer. Transliterated from
 * arc-scroll/src/award/templates/ash/common/text/text.html.
 *
 * The source view's <style> block is NOT here — it lives in style/scrollery.css.
 * Aurelia scoped it to a shadow root; inlined here it would be global, and on a
 * bulk page of mixed orders the last certificate's rules would win for all of them.
 *
 * Two font sizes scale with string length, as in the source. The lengths are cast
 * to int before they reach the calc() so nothing in a persona or a reason can break
 * out of the style attribute.
 */
$res         = $s['resources'];
$assetsRoot  = rtrim(HTTP_ASSETS, '/') . '/';
$ordinals    = ['', 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth'];
$rank        = (int) $s['rank'];

// ${Instance.IssuerName.length < 10 ? 10 : Instance.IssuerName.length}
$issuerLen   = max(10, strlen((string) $s['issuerName']));
// ${award.player.length}
$playerLen   = strlen((string) $s['player']);
// ${award.reason.length ? award.reason.length : 200} — an empty reason must not
// compute a size from 0, which would be far larger than the box.
$reasonLen   = strlen((string) $s['reason']) ?: 200;
?>
<div class="Award">

    <section class="IssuingTextSection">
        <p>
            <span style="font-size:1.15em;margin-right:-10px;position:relative;">Amtgard
                <img id="TopTextFlourish" src="<?= $assetsRoot . $res['flourish_top'] ?>" class="Flourish" alt="">
                <img id="BottomTextFlourish" src="<?= $assetsRoot . $res['flourish_bottom'] ?>" class="Flourish" alt="">
            </span>
            <span id="IssuerTitle" data-scroll-text="issuerTitle">and<?= $s['useThe'] ? ' the' : '' ?> <?= scroll_e($s['issuerTitle']) ?><?= $s['useOf'] ? ' of' : '' ?>
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
        <p class="AwardeeName UnderlinedBlank KeepUnderline" style="font-size:max(1.36em, calc(2.55em + -0.374em * ((<?= $playerLen ?> / 8))));">
            <span class="FormFill" data-scroll-text="player" data-blank="<?= $assetsRoot . $res['writing_line'] ?>" style="margin-top:0.3em;bottom:0;position:relative;">
                <?php if ((string) $s['player'] === ''): ?><img class="Underline" src="<?= $assetsRoot . $res['writing_line'] ?>" alt=""><?php endif; ?><?= scroll_e($s['player']) ?>
            </span>
        </p>
        <div class="Body AwardDetails">
            <span style="display:inline;" data-scroll-text="rankPhrase">
                with <?= scroll_e($s['pronoun'] ?: 'their') ?> <?= $ordinals[$rank] ?? '' ?>
                <?php if ($rank === 0): ?><img class="Underline Keep" style="width:4em;" src="<?= $assetsRoot . $res['writing_line'] ?>" alt=""><?php endif; ?>
            </span>
        </div>
    </section>

    <section class="Order" style="flex-grow:0;"><?= scroll_e($s['title']) ?></section>
    <section class="Body" style="display:inline;flex-grow:0;margin-top:0.3em;margin-block-end:0em;font-size:0.6em">for</section>
    <?php /* The source carried a css= binding AND a style= here; Aurelia merged them, plain HTML would drop the second. */ ?>
    <section class="Body Award_Reason Multiline" style="font-size:max(0.55em, calc(0.8em - 0.15em * (<?= $reasonLen ?> / 150)));margin-left:0.5em;margin-right:0.5em;margin-bottom:0.5em;flex-grow:0;">
        <div style="padding-top:0.42em;position:absolute;left:0;right:0;top:0;bottom:0;pointer-events:none;">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <img class="Underline Cull" src="<?= $assetsRoot . $res['writing_line'] ?>" alt="">
            <?php endfor; ?>
        </div>
        <p data-scroll-text="reason" style="display:inline-block;"><?= scroll_e($s['reason']) ?><?php if ((string) $s['reason'] === ''): ?><span><br/><br/></span><?php endif; ?></p>
    </section>
    <div style="flex-grow:0.5;"></div>
    <section class="Body Flavour Multiline" style="margin-block-start:1em;margin-left:-0.3em;margin-right:-0.3em;font-size:0.65em;">
        <span style="white-space: pre-line"><?= scroll_e($s['flavour']) ?></span>
    </section>
    <div style="flex-grow:0.7;"></div>
    <section class="SignAndDate">
        <div class="AwardDate UnderlinedBlank">
            <p data-scroll-text="date" style="min-height:1.2em;font-style:normal;font-size:0.7em;" class="Body"><?= scroll_e($s['date']) ?></p>
            <p style="font-size:0em">
                <img class="Underline" src="<?= $assetsRoot . $res['writing_line'] ?>" alt="">
            </p>
            <p class="Body" style="display:inline-block;margin-block-start:0.4em;"><b><span>On this day...</span></b></p>
        </div>
        <div class="AwardSignatory UnderlinedBlank">
            <p data-scroll-text="givenBy" style="min-height:1.2em;font-style:normal;font-size:0.7em;" class="Body"><?= scroll_e($s['givenBy']) ?></p>
            <p style="font-size:0em">
                <img class="Underline" src="<?= $assetsRoot . $res['writing_line'] ?>" alt="">
            </p>
            <p class="Body" style="display:inline-block;margin-block-start:0.4em;"><b><span>By my hand...</span></b></p>
        </div>
    </section>
</div>
