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
$scrollery_fit  = DIR_TEMPLATE . 'revised-frontend/script/scrollery-autofit.js';
$scrollery_tm   = DIR_TEMPLATE . 'revised-frontend/script/scrollery-textmodel.js';
$scrollery_ts   = DIR_TEMPLATE . 'revised-frontend/script/scrollery-typeset.js';
$scrollery_hand = DIR_TEMPLATE . 'revised-frontend/script/scrollery-hand.js';
$fontBase      = HTTP_TEMPLATE . 'revised-frontend/style/fonts/';

/*
 * The hand — &face=.
 *
 * A typeface is a per-PRINT choice rather than part of the composed artwork: the
 * same certificate is legitimately printed in a different hand for a different
 * court, and it changes nothing about what the award IS. So it travels as a page
 * parameter next to &print= and &up=, not as a recipe key, and a shipped reprint
 * URL that omits it keeps drawing exactly what it drew before.
 *
 * The vocabulary moved to scroll-recipes.php ('hands') and Scroll::Hands(), which
 * is where the note that used to sit here said it belonged. It had to: this page
 * held the only copy of the list and that is exactly why it held the only picker.
 * Four surfaces read it now — this one, the composer, `mine` and `bulk`.
 *
 * NormaliseHand() is the allowlist. It is an allowlist rather than a sanitiser
 * because the value lands in an HTML attribute and selects a font: an unknown key
 * falls back to the default hand rather than reaching either.
 */
$scrollery_hands = Scroll::Hands();
$scrollery_face  = Scroll::NormaliseHand((string) ($_GET['face'] ?? ''));

/*
 * The signature hand — &sig=.
 *
 * How the given-by line is lettered: the same kind of per-print choice as the hand, so
 * it travels the same way (a page parameter, stamped on <body>, never stored on the
 * award). NormaliseSignatureHand() is its allowlist. Its face is preloaded alongside
 * the hand's so the print gate, which waits on document.fonts.ready, has it in hand
 * before the first fit measures the signature.
 */
$scrollery_sigs = Scroll::SignatureHands();
$scrollery_sig  = Scroll::NormaliseSignatureHand(isset($_GET['sig']) ? (string) $_GET['sig'] : null);

$scrollery_preload_fonts = array_merge(Scroll::HandFonts($scrollery_face), Scroll::SignatureHandFonts($scrollery_sig));

/*
 * The citation's ruled lines — &lines=0.
 *
 * A per-print presentation choice like the hand, and applied the same way: an
 * attribute the stylesheet reads, never a change to what the certificate emits. The
 * six rules are in the shared text layer that all seventeen orders render, so this
 * works on any award and not only on a composed one.
 */
$scrollery_lines = Scroll::NormaliseShowLines($_GET['lines'] ?? null);

/*
 * How the top of the certificate is composed — &heading=.
 *
 * Another per-print choice that is not part of the award: `auto` lets the typeset
 * engine pick Classic or Centered for each certificate, and `classic` or `centered`
 * force one. It is stamped on <body> as data-sc-heading, where the engine reads it
 * from any ancestor of a certificate. NormaliseHeading() is the allowlist, so a value
 * that is not one of the three prints `auto` rather than reaching the attribute.
 */
$scrollery_heading = Scroll::NormaliseHeading(isset($_GET['heading']) ? (string) $_GET['heading'] : null);

/*
 * Sheet geometry — &trim=1 and &up=2|4.
 *
 * Both are print-shop and bulk affordances that a normal print must never see, so
 * both are opt-in per URL and both default off. The CSS is in scrollery.css
 * sections 5c and 5d; all this does is decide whether to say so on <body>.
 *
 * &up is read as an int against a fixed set rather than clamped, because 3-up and
 * 8-up are not "nearly 2" — they are layouts that do not exist, and rounding an
 * officer's typo into a layout they did not ask for prints the wrong thing.
 */
$scrollery_trim = isset($_GET['trim']) && (string) $_GET['trim'] === '1';
$scrollery_up   = (int) ($_GET['up'] ?? 0);
if (!in_array($scrollery_up, [2, 4], true)) {
    $scrollery_up = 0;
}

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
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery-signature.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/scrollery-signature.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery-hand.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/scrollery-hand.css') ?>">
<style>
/* An instant data-tip tooltip for the toolbar (project convention: never title=). The
   bare print page loads none of the CRM's stylesheets, so the rule lives here. It drops
   BELOW the label because the toolbar is stuck to the top edge, wraps, and is
   right-anchored so a control near the right of a narrow window cannot push it off
   screen. Chrome only: the toolbar is display:none under @media print. */
/* The toolbar gained a third picker. It is a single nowrap flex row in scrollery.css, so
   on a narrow window it would push Print off the edge; let it wrap instead. */
.sc-toolbar { flex-wrap: wrap; }
.sc-tip { position: relative; }
.sc-tip[data-tip]:hover::after,
.sc-tip[data-tip]:focus-within::after {
    content: attr(data-tip);
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 70;
    width: max-content;
    max-width: 240px;
    padding: 6px 10px;
    border-radius: 4px;
    background: #2d3748;
    color: #fff;
    font-size: 12px;
    line-height: 1.35;
    text-align: left;
    white-space: normal;
    pointer-events: none;
    box-shadow: 0 6px 18px rgba(0, 0, 0, .3);
}
@media (prefers-color-scheme: dark) {
    .sc-tip[data-tip]:hover::after,
    .sc-tip[data-tip]:focus-within::after { background: #e2e8f0; color: #1a202c; }
}
</style>
</head>
<body class="scroll-surface"
      data-scroll-face="<?= scroll_e($scrollery_face) ?>"
      data-sc-heading="<?= scroll_e($scrollery_heading) ?>"
      data-scroll-sig="<?= scroll_e($scrollery_sig) ?>"
      <?php if (!$scrollery_lines): ?>data-scroll-lines="0"<?php endif; ?>
      <?php if ($scrollery_trim): ?>data-scroll-trim="1"<?php endif; ?>
      <?php if ($scrollery_up): ?>data-scroll-up="<?= $scrollery_up ?>"<?php endif; ?>>
<?php /*
    The page-level shared defs: the owl's edge-detect filter and the knotwork
    border's 32-symbol library, emitted once here and referenced by every certificate
    below. Both sit outside every .scroll-page, so neither is caught up in that
    element's page-break-inside: avoid.

    The border defs are asked for FIRST, and told whether this run needs the ~47 KB
    griffon plate. _filters.tpl asks for them too — that is the general seam, shared
    with the composer — but it cannot know what is coming, so it has to assume the
    plate is wanted; this page holds the whole run and can answer honestly. The call
    below is idempotent, so whichever runs first decides and the other costs nothing.

    This is a page-level decision about what is ON the page, never a per-certificate
    one: every certificate's markup is byte-identical either way, which is what keeps
    Scroll::Render()'s cache from baking one page's answer into another page.
*/ ?>
<?php
// Not $s: that is the name every partial binds the certificate to, and the
// certificate loop below uses it. A separate name here keeps the two apart.
$scrollery_needs_griffons = false;
foreach ($Scrolls as $scrollery_scan) {
    if (($scrollery_scan['order'] ?? '') === 'griffon') {
        $scrollery_needs_griffons = true;
        break;
    }
}
?>
<?php scroll_partial('border_defs', ['griffons' => $scrollery_needs_griffons]); ?>
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
    <?php /*
        The hand picker.

        One of four — the composer, `mine` and `bulk` carry the same control off the
        same list. This one is the last chance to change it, with the certificate
        already on screen, which is why it is the only one that re-letters in place
        rather than handing the choice to a page that has not loaded yet.

        Each option is drawn in the face it names, so the list is a specimen sheet
        rather than a list of adjectives. Chrome and Firefox honour font-family on an
        <option>; Safari and the mobile pickers do not, and there the label still
        reads as the font's name, which is the fallback being asked for.

        It changes the attribute rather than reloading. scrollery.css switches hands on
        `[data-scroll-face]` on ANY ancestor (section 1a-ii says so in as many words),
        so swapping it on <body> re-letters the page with no round trip, and the URL is
        rewritten underneath so a copied link still carries the choice.
    */ ?>
    <label class="sc-toolbar-field" for="sc-face">
        <span>Hand</span>
        <select id="sc-face" class="sc-select">
            <?php /* Not $scrollery_hand as the loop variable: that name is the PATH to
                     scrollery-hand.js, and filemtime() on it further down would get a
                     hand definition instead of a file. */ ?>
            <?php foreach ($scrollery_hands as $scrollery_key => $scrollery_def): ?>
            <option value="<?= scroll_e($scrollery_key) ?>" data-face-family="<?= scroll_e($scrollery_def['family']) ?>"<?= $scrollery_key === $scrollery_face ? ' selected' : '' ?>><?= scroll_e($scrollery_def['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php /*
        The heading picker, beside the hand because it is the same kind of choice: how
        this one print is set, not what the award is. The same label and select classes
        as the hand, so it takes the same light and dark styling. The options are
        Scroll::Headings() and the selection is the page's own normalised heading, so a
        stranger in &heading= shows as Automatic, which is what the page rendered.

        Like the hand it re-sets the page in place. The engine reads data-sc-heading off
        any ancestor of a certificate, so changing it on <body> and calling the fit again
        re-composes the top (Classic and Centered are different arrangements of the same
        words) with no round trip. Chrome, so it is inside .sc-toolbar, which
        scrollery.css hides whole under @media print; the choice reaches the paper
        through the attribute it leaves on <body>, not through this control.

        Unlike the hand it is not remembered: a forced heading is a rescue for a name that
        sets badly, and would force a name that sets fine on the next certificate.

        The Automatic option names what Automatic chose — "Automatic (Classic)", or
        "(mixed)" on a bulk page whose certificates disagree — because on most
        certificates Automatic and Classic look identical. See ScrolleryHeading in
        script/scrollery-hand.js.
    */ ?>
    <?php /* The signature hand, beside the hand it is a sibling of: how the given-by line is
             lettered, for a REPRINT where the signer would rather sign in script. Same
             listbox as the hand (each option drawn in its own face), re-lettered in place
             like it; "Match the lettering" is the default. */ ?>
    <label class="sc-toolbar-field" for="sc-sig">
        <span>Signature</span>
        <select id="sc-sig" class="sc-select">
            <?php foreach ($scrollery_sigs as $scrollery_skey => $scrollery_sdef): ?>
            <option value="<?= scroll_e($scrollery_skey) ?>"<?= $scrollery_sdef['family'] !== '' ? ' data-face-family="' . scroll_e($scrollery_sdef['family']) . '"' : '' ?><?= $scrollery_skey === $scrollery_sig ? ' selected' : '' ?>><?= scroll_e($scrollery_sdef['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="sc-toolbar-field sc-tip" for="sc-heading" data-tip="Automatic sets the classic heading, and switches to centred when a name or place won&rsquo;t fit. Applies to this print only.">
        <span>Heading</span>
        <select id="sc-heading" class="sc-select">
            <?php foreach (Scroll::Headings() as $scrollery_hkey => $scrollery_hlabel): ?>
            <option value="<?= scroll_e($scrollery_hkey) ?>"<?= $scrollery_hkey === $scrollery_heading ? ' selected' : '' ?>><?= scroll_e($scrollery_hlabel) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php /* The reason-lines switch. Beside the hand because it is the same kind of
             thing — a per-print presentation choice that changes nothing about the
             award — and on this page rather than only in the composer because a
             REPRINT of an existing award is exactly when somebody wants a clean sheet
             or a ruled one. */ ?>
    <label class="sc-toolbar-check">
        <input type="checkbox" id="sc-lines"<?= $scrollery_lines ? ' checked' : '' ?>>
        <span>Reason lines</span>
    </label>
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
<?php /*
    Court lines that could not be printed (Scrollery/court only): court-award id
    => ['Label' => "Persona — Award", 'Reason' => why], one item per line. The planner's preflight already removed unprintable lines before
    this tab opened, so these became unprintable in between. Same class as the
    overflow notice, so scrollery.css hides it at print time with the rest of the
    chrome — it never costs a sheet of paper.
*/ ?>
<?php if (!empty($Skipped) && is_array($Skipped)): ?>
<div class="sc-overflow-notice sc-skipped-notice" role="status">
    <?= count($Skipped) === 1 ? 'One scroll was' : (int) count($Skipped) . ' scrolls were' ?> left out of this run:
    <ul>
        <?php foreach ($Skipped as $skip): ?>
        <li><?= htmlspecialchars((string) ($skip['Label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string) ($skip['Reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php /*
    Citations that still do not fit.

    Emitted empty and hidden; scrollery-autofit.js fills it in after its measured
    pass, because whether a citation fits is not knowable server-side — it depends on
    the face, the sheet layout and the browser's own line breaking. Hidden with the
    `hidden` attribute rather than a class so that a page whose script never runs
    shows nothing rather than an empty box.

    It is chrome, so it sits outside .scroll-page and scrollery.css hides it at print
    time with the toolbar and the advisory. The officer needs it BEFORE the print
    dialog, not on the paper.
*/ ?>
<p class="sc-clip-notice" id="sc-clip-notice" role="status" hidden></p>
<?php if ($scrollery_trim): ?>
<?php /*
    Crop marks. Four corners, each drawing two hairlines with its own
    pseudo-elements — the geometry is entirely in scrollery.css section 5c, which
    is also where they are switched on; on screen and on a non-trim print they are
    display:none.

    Emitted at body level rather than inside a certificate on purpose: they are
    fixed-position furniture that Chromium repeats on every printed sheet, and a
    crop mark inside .scroll-page would be inside that element's
    page-break-inside: avoid, counting as part of the box it is marking.
*/ ?>
<div class="sc-crop" aria-hidden="true"><i class="tl"></i><i class="tr"></i><i class="bl"></i><i class="br"></i></div>
<?php endif; ?>
<?php /*
    The certificate flow.

    A wrapper rather than the certificates sitting straight on <body>, because
    multi-up turns exactly this element into a grid and the toolbar, the advisory
    and the overflow notice must not be cells in it. It also makes
    `.scroll-page:first-child` and `:last-child` mean what they say: on <body>
    those two selectors matched nothing at all, because the toolbar preceded the
    first certificate and the autofit <script> followed the last.
*/ ?>
<div class="scroll-flow">
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
</div>
<?php /*
    The scripts that set the type, in the order they depend on each other.

    The text model first: the typeset engine tokenises and breaks lines with the same
    rules the server split the issuer with. The typeset engine second: it composes the
    top half (the issuing lines, the name, the order line) and writes the sizes the
    autofit below then takes as given. Neither does any DOM work at parse time.
*/ ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-textmodel.js?v=<?= filemtime($scrollery_tm) ?>"></script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-typeset.js?v=<?= filemtime($scrollery_ts) ?>"></script>
<?php /*
    The measured autofit. It self-runs once on document.fonts.ready and exposes
    window.ScrolleryAutofit for anything that needs to re-fit after the DOM changes
    (the composer's live preview calls ScrolleryAutofit.fitSoon(mount)).

    Loaded WITHOUT defer/async on purpose: it must have defined window.ScrolleryAutofit
    before the print gate below runs, and it does no DOM work at parse time — its own
    first pass is inside a promise on document.fonts.ready.
*/ ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-autofit.js?v=<?= filemtime($scrollery_fit) ?>"></script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-hand.js?v=<?= filemtime($scrollery_hand) ?>"></script>
<script>
/*
 * The hand picker's behaviour, on the surface that OWNS the choice.
 *
 * Everything shared with the composer, `mine` and `bulk` is in scrollery-hand.js;
 * this is only the part that is true here — that the certificate is already on the
 * page, so switching re-letters it in place rather than handing the choice to a
 * page that has not loaded yet.
 */
(function () {
    var sel = document.getElementById('sc-face');
    if (!sel || !window.ScrolleryHand) { return; }

    var Hand = window.ScrolleryHand;
    Hand.init(<?= json_encode(array_keys($scrollery_hands)) ?>, <?= json_encode(Scroll::DEFAULT_HAND) ?>);

    /* An explicit &face= in the URL is somebody's stated intent for THIS print and
       always wins over the remembered default — a link shipped to a herald has to
       print what it says. It is not written back to the store either: following
       somebody else's link should not silently change your own preference. */
    var urlFace = (window.location.search.match(/[?&]face=([a-z]+)/i) || [])[1];

    function repaint(face) {
        Hand.apply(document.body, face, document);

        /* Keep the URL honest, so a link copied off this page prints the hand the
           officer is looking at. replaceState, not a navigation: nothing needs
           reloading and a history entry per fiddle with a <select> is noise. */
        try {
            var next = Hand.decorate(window.location.search || '?', face);
            window.history.replaceState(null, '', window.location.pathname + next + window.location.hash);
        } catch (e) { /* older browser: the attribute swap still worked */ }
    }

    if (urlFace && Hand.known(urlFace)) {
        sel.value = urlFace;
        Hand.paint(sel);
        Hand.armSpecimens(sel);
        sel.addEventListener('change', function () {
            Hand.paint(sel);
            repaint(Hand.set(sel.value));
        });
        return;
    }

    Hand.bind(sel, repaint);

    /* The remembered hand is applied in place rather than by redirecting on load: a
       page that bounces itself to a different URL before the officer has done
       anything is worse than one that re-letters. It costs the <link rel=preload>,
       which was emitted for the URL's hand — a real cost, paid once, and only by an
       officer who has expressed a preference. */
    var stored = Hand.get();
    if (stored !== document.body.getAttribute('data-scroll-face')) {
        Hand.apply(document.body, stored, document);
    }
}());
</script>
<script>
/*
 * The reason-lines switch. Its own block for the reason the signature's and heading's
 * are: the hand's block above returns early on an explicit &face=, and the composer
 * always sends one, so a binding inside it never ran on a print opened from there.
 */
(function () {
    var Lines = window.ScrolleryLines;
    if (!Lines) { return; }

    /* The reason lines. Re-letters nothing and re-fits nothing — the rules are out of
       flow, so this is a pure repaint — but the URL is kept honest for the same reason
       the hand's is: this page's link gets copied. */
    var box = document.getElementById('sc-lines');
    var urlLines = /[?&]lines=/.test(window.location.search);

    Lines.bind(box, function (on) {
        Lines.apply(document.body, on);
        try {
            var next = Lines.decorate(window.location.search || '?', on);
            /* decorate() only ever ADDS lines=0, so turning them back on has to
               clear the parameter rather than set it to 1 — otherwise the URL
               accumulates a value that means "the default" and reads as a choice. */
            if (on) { next = next.replace(/([?&])lines=[^&]*&?/, '$1').replace(/[?&]$/, ''); }
            window.history.replaceState(null, '', window.location.pathname + next + window.location.hash);
        } catch (e) { /* older browser: the attribute swap still worked */ }
    });

    /* An explicit &lines= in the URL is this print's stated intent and beats the
       remembered default, exactly as &face= does. */
    if (urlLines) {
        if (box) { box.checked = !document.body.hasAttribute('data-scroll-lines'); }
    } else {
        var remembered = Lines.get();
        if (box) { box.checked = remembered; }
        Lines.apply(document.body, remembered);
    }
}());
</script>
<script>
/*
 * The signature hand's behaviour. Its own block for the reason the heading's is: the hand's
 * block above returns early on an explicit &face=.
 *
 * An explicit &sig= in the URL is this print's stated intent and beats the remembered
 * default, and is not written back to the store (following somebody else's link must not
 * change your own preference), exactly as &face= is treated. Without one, the remembered
 * choice is applied in place. Either way the URL is kept honest on a change.
 */
(function () {
    var sel = document.getElementById('sc-sig');
    var Hand = window.ScrolleryHand;
    if (!sel || !Hand || !Hand.sig) { return; }

    var Sig = Hand.sig;
    Sig.init(<?= json_encode(array_keys($scrollery_sigs)) ?>, <?= json_encode(Scroll::DEFAULT_SIGNATURE_HAND) ?>);

    /* Lowercased as the server's NormaliseSignatureHand does, so &sig=GreatVibes counts
       as explicit rather than falling through to the remembered value. */
    var urlSig = ((window.location.search.match(/[?&]sig=([a-z]+)/i) || [])[1] || '').toLowerCase();

    function repaint(key) {
        Sig.apply(document.body, key, document, sel);
        try {
            var next = Sig.decorate(window.location.search || '?', key);
            window.history.replaceState(null, '', window.location.pathname + next + window.location.hash);
        } catch (e) { /* older browser: the attribute swap still worked */ }
    }

    if (urlSig && Sig.known(urlSig)) {
        sel.value = urlSig;
        Hand.paint(sel);
        Hand.armSpecimens(sel);
        Hand.enhance(sel);
        sel.addEventListener('change', function () {
            Hand.paint(sel);
            repaint(Sig.set(sel.value));
        });
        return;
    }

    Sig.bind(sel, repaint);

    /* Applied in place rather than by redirecting, as the hand's remembered face is. */
    var stored = Sig.get();
    if (stored !== document.body.getAttribute('data-scroll-sig')) {
        Sig.apply(document.body, stored, document, sel);
    }
}());
</script>
<script>
/*
 * The heading picker's behaviour. A separate block from the hand's because that one
 * returns early on an explicit &face=, and the heading has no such branch to skip.
 *
 * It owns the choice outright: the server already selected the page's normalised value,
 * so there is nothing to reconcile on load and nothing remembered. Changing it rewrites
 * data-sc-heading on <body> and fits again, in place.
 */
(function () {
    var sel = document.getElementById('sc-heading');
    if (!sel) { return; }

    /* The fit is idempotent (spec 6.2), so calling it on a page that has already been
       fitted re-composes from the words rather than compounding. It resolves
       ScrolleryAutofit at call time: this block runs before fonts.ready, and a change
       before the first pass has finished is simply covered by that pass. */
    function refit() {
        var A = window.ScrolleryAutofit;
        if (A && typeof A.fit === 'function') { A.fit(document); }
        /* Composition changes which lines clip; the notice has to follow, as it does on
           the hand and signature paths. */
        if (A && typeof A.reportClipping === 'function') { A.reportClipping(document); }
    }

    /* Say what Automatic chose, off the engine's own data-sc-comp report on every
       certificate on the page (a bulk print holds many, hence "mixed"). Watched, so the
       first fit after fonts.ready and every re-fit below update it. */
    if (window.ScrolleryHeading) {
        window.ScrolleryHeading.bind(sel, document.querySelector('.scroll-flow'));
    }

    sel.addEventListener('change', function () {
        document.body.setAttribute('data-sc-heading', sel.value);
        refit();

        /* Keep the URL honest, so a link copied off this page prints the heading the
           officer is looking at. Edited as text, as the hand's is: URLSearchParams
           would re-encode every other parameter. replaceState, not a navigation. */
        try {
            var search = window.location.search || '?';
            var re = /([?&]heading=)[^&]*/;
            var next = re.test(search)
                ? search.replace(re, '$1' + encodeURIComponent(sel.value))
                : search + (search === '?' ? '' : '&') + 'heading=' + encodeURIComponent(sel.value);
            window.history.replaceState(null, '', window.location.pathname + next + window.location.hash);
        } catch (e) { /* older browser: the attribute swap and the fit still worked */ }
    });
}());
</script>
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

    /* A remembered hand or signature face is applied in place and was not preloaded;
       their refits are the last things that have to finish. */
    var Hand = window.ScrolleryHand;
    var Sig = Hand && Hand.sig;
    var handed = settled(Hand && Hand.ready ? Hand.ready() : null);
    var signed = settled(Sig && Sig.ready ? Sig.ready() : null);

    Promise.all([loaded, fitted, handed, signed]).then(go, go);
    window.setTimeout(go, FAILSAFE_MS);
}());
</script>
<?php endif; ?>
</body>
</html>
