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
$scrollery_fit = DIR_TEMPLATE . 'revised-frontend/script/scrollery-autofit.js';
$fontBase      = HTTP_TEMPLATE . 'revised-frontend/style/fonts/';

/*
 * The faces this page actually paints, for <link rel=preload>.
 *
 * All five in scrollery.css are font-display:block, which means the browser paints
 * NOTHING where the text goes until the face lands — and the print used to fire on
 * `load`, which waits on ~4 MB of certificate art but not on a webfont at all. The
 * font could and did arrive after the print dialog, so the certificate printed in
 * Times, or blank.
 *
 * Averia's non-italic Bold is deliberately absent: .Award .Body is italic, so every
 * bold thing on the certificate ("On this day...", the rank phrase) resolves to
 * BoldItalic, and preloading a face nothing asks for just spends bandwidth on the
 * critical path.
 */
$scrollery_preload_fonts = [
    'BlackChancery.woff2',
    'AveriaSerifLibre-Regular.woff2',
    'AveriaSerifLibre-Italic.woff2',
    'AveriaSerifLibre-BoldItalic.woff2',
];

/*
 * Where "Back" goes.
 *
 * This page is now reached without auto-print on the module's main paths — the bulk
 * run, the View link on `mine`, and the composer all land here as a preview — so
 * without a way off it a preview is a dead end. The referrer is the right answer
 * when there is one, because it is the list the officer was working through.
 *
 * It is a $_SERVER value, so it is checked rather than trusted: it must start with
 * this installation's own UI root (an open redirect on a page an officer is about to
 * click is exactly the wrong place to be casual), and it must not be another render
 * or compose URL, which would only bounce between previews.
 */
$scrollery_back = UIR . 'Scrollery';
$scrollery_ref  = (string) ($_SERVER['HTTP_REFERER'] ?? '');
if ($scrollery_ref !== ''
    && strpos($scrollery_ref, HTTP_UI) === 0
    && strpos($scrollery_ref, 'Scrollery/render') === false
    && strpos($scrollery_ref, 'Scrollery/compose') === false) {
    $scrollery_back = $scrollery_ref;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= scroll_e($PageTitle ?? 'Scroll') ?></title>
<?php foreach ($scrollery_preload_fonts as $f): ?>
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= $fontBase . $f ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery.css?v=<?= filemtime($scrollery_css) ?>">
</head>
<body class="scroll-surface">
<?php scroll_partial('filters'); ?>
<?php /*
    Print / Back. Chrome, not content: it sits OUTSIDE .scroll-page so it cannot
    reach a certificate's page-break-inside, and scrollery.css hides it under
    @media print alongside the advisory, so it never reaches the paper.

    The Print button calls window.print() directly. It is the same call the
    ?print=1 gate makes, minus the gate — by the time somebody has looked at the
    preview and clicked, the fonts have long since resolved and the autofit pass
    has run.
*/ ?>
<div class="sc-toolbar" role="toolbar" aria-label="Certificate actions">
    <span class="sc-toolbar-title"><?= scroll_e($PageTitle ?? 'Scroll') ?></span>
    <a class="sc-btn" href="<?= scroll_e($scrollery_back) ?>">&larr; Back</a>
    <button type="button" class="sc-btn sc-btn-primary" onclick="window.print();">Print</button>
</div>
<?php /*
    The advisory. Three facts about printing this page that CSS cannot assert, said
    once, quietly. It is dismissible, it never gates the print, and scrollery.css
    hides it under @media print so it cannot reach the paper. It is NOT gated on
    user-agent on purpose — a sniff would hide the warning from the browsers whose
    behaviour it is describing, and browsers change.
*/ ?>
<aside class="sc-advisory" id="sc-advisory" role="note" aria-label="Before you print">
    <button type="button" class="sc-advisory-dismiss" aria-label="Dismiss this notice"
            onclick="var a=document.getElementById('sc-advisory');if(a){a.remove();}try{sessionStorage.setItem('sc-advisory-hidden','1');}catch(e){}">&times;</button>
    <b>Before you print</b>
    <ul>
        <li>Chrome and Edge print <b>headers and footers</b> on by default — they will
            stamp the page URL and today's date into the margin. Turn them off under
            <em>More settings</em> in the print dialog.</li>
        <li>Print from <b>Chrome or Edge</b> if you can. Firefox has a long history of
            flattening or dropping the blend modes and SVG filters these certificates
            are built from, and Safari does not reliably rasterise them at print scale.</li>
        <li>Leave <b>Background graphics</b> on and scale at <b>100%</b>: the parchment,
            the border and the heraldry are all backgrounds, and any "fit to page"
            shrink is applied on top of the layout this page already did.</li>
    </ul>
</aside>
<script>
    /* Honour a dismissal for the rest of the session. Inline and immediate so the
       notice never flashes on a page where it was already dismissed. */
    try {
        if (sessionStorage.getItem('sc-advisory-hidden') === '1') {
            var _a = document.getElementById('sc-advisory');
            if (_a) { _a.remove(); }
        }
    } catch (e) { /* private mode: the notice simply shows again */ }
</script>
<?php if (!empty($Dropped)): ?>
<p class="sc-overflow-notice" role="status">
    Only the first <?= (int) ($MaxRun ?? 0) ?> certificates of this run are shown.
    <?= (int) $Dropped ?> more were left out — print them in a second run.
</p>
<?php endif; ?>
<?php foreach ($Scrolls as $s): ?>
<div class="scroll-page">
    <?php /* `image-resolution: 300dpi` used to lead this style attribute. No shipping
             browser implements the property — it is removed rather than left to read
             as a print-quality control that is doing something. The certificate is
             2550x3300 CSS px drawn at 8.5x11in, which IS 300dpi; nothing had to
             declare it. */ ?>
    <svg viewBox="0 0 2550 3300"
         style="aspect-ratio:8.5/11;width:100%;height:100%;position:relative;overflow:hidden;--heraldry-filter:brightness(1) contrast(1);--heraldry-transform:translateX(0%) translateY(0%) scale(1);"
         data-scroll-frame>
        <foreignObject x="0" y="0" width="2550" height="3300" style="overflow:visible;">
            <?= Scroll::Render($s) ?>
        </foreignObject>
    </svg>
</div>
<?php endforeach; ?>
<?php /*
    The measured autofit. It self-runs once on document.fonts.ready and exposes
    window.ScrolleryAutofit for anything that needs to re-fit after the DOM changes
    (the composer's live preview calls ScrolleryAutofit.fitSoon(mount)).

    Loaded WITHOUT defer/async on purpose: it must have defined window.ScrolleryAutofit
    before the print gate below runs, and it does no DOM work at parse time — its own
    first pass is inside a promise on document.fonts.ready.
*/ ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-autofit.js?v=<?= filemtime($scrollery_fit) ?>"></script>
<?php if (!empty($AutoPrint)): ?>
<script>
/*
 * Auto-print used to be `window.addEventListener('load', print)`. `load` waits on
 * roughly 4 MB of certificate art and does not wait on a webfont at all, so the
 * print dialog could — and did — open before BlackChancery and Averia arrived. All
 * five faces are font-display:block, so what got printed was Times, or nothing.
 *
 * The gate is now all three things that have to be true: the page loaded, the fonts
 * resolved, and the autofit pass finished (it is itself gated on the fonts, because
 * measuring the fallback serif measures the wrong thing).
 *
 * The failsafe is not optional. A font 404 settles document.fonts.ready, but a
 * hanging image request does not settle `load`, and an old browser may have no
 * FontFaceSet at all — either way this must end at the print dialog rather than at a
 * page that never prints. Whatever has finished by then is what prints.
 */
(function () {
    var FAILSAFE_MS = 10000;
    var fired = false;

    function go() {
        if (fired) { return; }
        fired = true;
        window.print();
    }

    function settled(p) {
        return (p && typeof p.then === 'function')
            ? p.then(function () {}, function () {})
            : Promise.resolve();
    }

    var loaded = document.readyState === 'complete'
        ? Promise.resolve()
        : new Promise(function (resolve) {
            window.addEventListener('load', resolve, { once: true });
        });

    var fitted = window.ScrolleryAutofit
        ? settled(window.ScrolleryAutofit.ready)
        : settled(document.fonts && document.fonts.ready);

    Promise.all([loaded, fitted]).then(go, go);
    window.setTimeout(go, FAILSAFE_MS);
}());
</script>
<?php endif; ?>
</body>
</html>
