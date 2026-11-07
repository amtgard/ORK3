<?php
/**
 * Transliterated from arc-scroll/src/award/templates/ash/common/award-heraldry.html.
 *
 * The source guarded each dimension with `x === undefined || x === null || x === ''`
 * rather than `x || default`, because 0 is a legitimate value for `top`. The isset()
 * checks below keep that: a bare `?:` would quietly turn top="0" into top="50".
 */
$hTop    = (isset($top)    && $top    !== '' && $top    !== null) ? (int) $top    : 50;
$hWidth  = (isset($width)  && $width  !== '' && $width  !== null) ? (int) $width  : 420;
$hHeight = (isset($height) && $height !== '' && $height !== null) ? (int) $height : 420;
?>
<div class="centre-top" style="margin-top:<?= $hTop ?>px;padding:1px;">
<?php if (in_array($s['heraldryDrawIn'] ?? '', Scroll::DRAW_IN_SHAPES, true)): ?>
    <?php /* EXPERIMENT (scrollery-heraldry): "Draw In Heraldry", a blank for the arms to
             be drawn in by hand. Plain white with a black outline, NOT multiplied: the
             point is a clean field to draw on, not one that takes the parchment's tone.
             Heater: the slot's full height, 0.84 as wide. Circle: 0.9 of the slot. The
             stroke sits inside the box, so the outline is never clipped. */
    $sw  = Scroll::DRAW_IN_STROKE;
    $in  = $sw / 2;
    $isH = $s['heraldryDrawIn'] === 'heater';
    $dh  = $isH ? $hHeight : (int) round(min($hWidth, $hHeight) * 0.9);
    $dw  = $isH ? (int) round($hHeight * 0.84) : $dh;
    $f   = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); ?>
    <svg class="ScDrawIn" width="<?= $dw ?>" height="<?= $dh ?>" viewBox="0 0 <?= $dw ?> <?= $dh ?>" aria-hidden="true">
    <?php if ($isH): ?>
        <path d="M<?= $f($in) ?> <?= $f($in) ?>H<?= $f($dw - $in) ?>V<?= $f($dh * 0.5) ?>C<?= $f($dw - $in) ?> <?= $f($dh * 0.78) ?> <?= $f($dw * 0.75) ?> <?= $f($dh * 0.9) ?> <?= $f($dw / 2) ?> <?= $f($dh - $in) ?>C<?= $f($dw * 0.25) ?> <?= $f($dh * 0.9) ?> <?= $f($in) ?> <?= $f($dh * 0.78) ?> <?= $f($in) ?> <?= $f($dh * 0.5) ?>Z"
              fill="#fff" stroke="#000" stroke-width="<?= $sw ?>" stroke-linejoin="miter"/>
    <?php else: ?>
        <circle cx="<?= $f($dw / 2) ?>" cy="<?= $f($dh / 2) ?>" r="<?= $f($dw / 2 - $in) ?>" fill="#fff" stroke="#000" stroke-width="<?= $sw ?>"/>
    <?php endif; ?>
    </svg>
<?php elseif (($s['heraldryLayout'] ?? 'single') === 'overlap'): ?>
    <?php /* EXPERIMENT (scrollery-heraldry): kingdom behind, park in front, offset down
             and right, the pair centred in the box the order gives its one device. Spans,
             not divs: owl's and cyanotype's edge-detect rules hang off every div in here.
             The pair is isolated and multiplied as one, so inside it the park covers the
             kingdom; scrollery-arms.js clears the park's white surround so only the arms
             themselves do. */
    $arms   = $s['heraldryArms'] ?? [];
    $shield = (int) round(min($hWidth, $hHeight) * 0.76);
    $dx     = (int) round($shield * 0.6);
    $dy     = (int) round($shield * 0.32); ?>
    <span class="ScArmsPair" style="width:<?= $shield + $dx ?>px;height:<?= $shield + $dy ?>px;">
        <img class="ScArmsPair-back" src="<?= scroll_e((string) ($arms['kingdom'] ?? '')) ?>" width="<?= $shield ?>" height="<?= $shield ?>" alt="">
        <img class="ScArmsPair-front" data-sc-knockout src="<?= scroll_e((string) ($arms['park'] ?? '')) ?>" width="<?= $shield ?>" height="<?= $shield ?>"
             style="left:<?= $dx ?>px;top:<?= $dy ?>px;" alt="">
    </span>
<?php elseif (($s['heraldry'] ?? '') !== ''): ?>
    <img src="<?= scroll_e($s['heraldry']) ?>" width="<?= $hWidth ?>" height="<?= $hHeight ?>"
         style="mix-blend-mode: multiply;object-fit:contain;" alt="">
<?php endif; ?>
</div>
