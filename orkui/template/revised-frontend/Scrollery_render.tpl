<?php
/**
 * The bare print surface. No ORK chrome — nothing on this page but certificates.
 *
 * Arcanyst suppressed its host page at print time with
 * `@media print { body > * { display:none } }`, which fights the page it lives in.
 * A dedicated route has nothing to hide, so scrollery.css's print block is just
 * @page plus page-break-before between siblings, and bulk printing is a foreach.
 *
 * This is also the seam a future headless-Chrome PDF service attaches to: it
 * fetches this one URL.
 *
 * Rendered directly by Controller_Scrollery::render_bare(), which includes this
 * file with its data extracted into local scope and then exits. It does NOT go
 * through View: View wraps every template in the controller template and then the
 * site theme — nav, GTM, footer — which is exactly what must not appear here.
 * Hence $Scrolls rather than $this->__data['Scrolls'].
 *
 * The <svg>/<foreignObject> frame is kept from Arcanyst: it is what lets the same
 * 2550x3300 markup scale to both a thumbnail preview and a printed page.
 */
$scrollery_css = DIR_TEMPLATE . 'revised-frontend/style/scrollery.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= scroll_e($PageTitle ?? 'Scroll') ?></title>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery.css?v=<?= filemtime($scrollery_css) ?>">
</head>
<body>
<?php
/**
 * In-document SVG filters the certificates reference by id. Defined ONCE per page,
 * not per certificate: duplicate filter ids resolve to the first in the document,
 * so extra copies are dead weight on a bulk print.
 *
 * Only the filters actually used are copied over from
 * arc-scroll/src/resources/filters.html — Arcanyst's animated `arcanyst` glow
 * filters are chrome for its own UI and carry two indefinite SMIL animations each.
 */
?>
<svg version="1.1" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
     style="visibility:hidden;position:fixed;top:0;left:0;width:0;height:0;">
    <defs>
        <filter id="OWL_edge-detect">
            <feFlood x="-10%" y="-10%" width="120%" height="120%" flood-color="white" flood-opacity="1" result="flood"></feFlood>
            <feGaussianBlur stdDeviation="1" in="SourceGraphic" />
            <feComposite x="-10%" y="-10%" width="120%" height="120%" in2="flood" mode="over"></feComposite>
            <feConvolveMatrix x="-10%" y="-10%" width="120%" height="120%" order="3 3" preserveAlpha="true" edgeMode="none" kernelMatrix="0 -2 0 -2 8 -2 0 -2 0"/>
            <feColorMatrix
                type="matrix"
                values="0 0 0 0 1
                        0 0 0 0 1
                        0 0 0 0 1
                        1 1 1 0 0" />
        </filter>
    </defs>
</svg>
<?php if (!empty($Dropped)): ?>
<p style="max-width:60em;margin:16px auto;padding:12px 16px;border:1px solid #b58900;background:#fff8e1;
          color:#5b4600;font:14px/1.5 system-ui,sans-serif;" role="status">
    Only the first <?= (int) ($MaxRun ?? 0) ?> certificates of this run are shown.
    <?= (int) $Dropped ?> more were left out — print them in a second run.
</p>
<?php endif; ?>
<?php foreach ($Scrolls as $s): ?>
<div class="scroll-page">
    <svg viewBox="0 0 2550 3300"
         style="image-resolution:300dpi;aspect-ratio:8.5/11;width:100%;height:100%;position:relative;overflow:hidden;--heraldry-filter:brightness(1) contrast(1);--heraldry-transform:translateX(0%) translateY(0%) scale(1);"
         data-scroll-frame>
        <foreignObject x="0" y="0" width="2550" height="3300" style="overflow:visible;">
            <?= Scroll::Render($s) ?>
        </foreignObject>
    </svg>
</div>
<?php endforeach; ?>
<?php if (!empty($AutoPrint)): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
