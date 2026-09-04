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
<?php if (($s['heraldry'] ?? '') !== ''): ?>
    <img src="<?= scroll_e($s['heraldry']) ?>" width="<?= $hWidth ?>" height="<?= $hHeight ?>"
         style="mix-blend-mode: multiply;object-fit:contain;filter: var(--heraldry-filter);transform: var(--heraldry-transform);" alt="">
<?php endif; ?>
</div>
