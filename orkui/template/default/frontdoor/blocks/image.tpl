<?php
/**
 * Partial: image.tpl  (MEDIA block) — self-contained (own scoped <style>)
 * Receives: $blockFields, shared $data, UIR
 *
 * Fields:
 *   image     media ref {key,media_id,src,thumb,alt,focal}
 *   caption?  string  — optional figcaption (escaped)
 *   href?     string  — optional link wrapping the image (escaped; http(s)/relative only)
 *   align?    'left'|'center'|'right'  (default 'center')
 *   max_width? int|string — caps the figure width (px); blank = natural
 *
 * "Dumb" partial: renders $blockFields only, fetches nothing. Escapes every
 * authored string with htmlspecialchars(..., ENT_QUOTES).
 */
$fdbImg     = $blockFields['image'] ?? [];
$fdbSrc     = is_array($fdbImg) ? ($fdbImg['src'] ?? '') : '';
$fdbAlt     = is_array($fdbImg) ? ($fdbImg['alt'] ?? '') : '';
$fdbFocal   = is_array($fdbImg) ? ($fdbImg['focal'] ?? '') : '';
$fdbCaption = $blockFields['caption'] ?? '';
$fdbHref    = $blockFields['href'] ?? '';
$fdbAlign   = $blockFields['align'] ?? 'center';
$fdbMaxW    = $blockFields['max_width'] ?? '';

// Only render when there's an image to show.
if ($fdbSrc === '') {
    return;
}

// Whitelist alignment.
$fdbAlign = in_array($fdbAlign, ['left', 'center', 'right'], true) ? $fdbAlign : 'center';
$fdbFigAlign = ($fdbAlign === 'center') ? 'margin-left:auto;margin-right:auto;'
    : (($fdbAlign === 'right') ? 'margin-left:auto;' : 'margin-right:auto;');

// max_width: numeric → px cap; otherwise no cap.
$fdbMaxWCss = '';
if (is_numeric($fdbMaxW) && (int) $fdbMaxW > 0) {
    $fdbMaxWCss = 'max-width:' . (int) $fdbMaxW . 'px;';
}

// Respect focal point via object-position when provided.
$fdbObjPos = '';
if (is_string($fdbFocal) && $fdbFocal !== '') {
    // focal is "x% y%" style — keep only safe chars before emitting.
    $fdbFocalSafe = preg_replace('/[^0-9%.\s a-z-]/i', '', $fdbFocal);
    if ($fdbFocalSafe !== '') {
        $fdbObjPos = 'object-position:' . $fdbFocalSafe . ';';
    }
}

// Link safety: defer to the authoritative URL checker (rejects javascript:,
// data:, vbscript:, protocol-relative //host, etc.).
$fdbHrefSafe = '';
if (is_string($fdbHref) && $fdbHref !== '' && CmsSanitizer::IsSafeUrl($fdbHref)) {
    $fdbHrefSafe = $fdbHref;
}
?>
<style>
/* scoped: fdb-img */
.fdb-img-figure {
    margin: 0;
    width: 100%;
}
.fdb-img-frame {
    display: block;
    width: 100%;
    border-radius: 10px;
    overflow: hidden;
    background: #f1f3f8;
}
.fdb-img-frame img {
    display: block;
    width: 100%;
    height: auto;
    object-fit: cover;
}
.fdb-img-caption {
    margin-top: 9px;
    font-size: 13px;
    line-height: 1.5;
    color: #667;
    text-align: center;
}
a.fdb-img-frame:hover {
    opacity: .94;
}
html[data-theme="dark"] .fdb-img-frame {
    background: #1b2236;
}
html[data-theme="dark"] .fdb-img-caption {
    color: #9aa6c0;
}
</style>
<div class="fd-pad">
    <figure class="fdb-img-figure" style="<?= $fdbMaxWCss ?><?= $fdbFigAlign ?>">
        <?php if ($fdbHrefSafe !== ''): ?>
            <a class="fdb-img-frame" href="<?= htmlspecialchars($fdbHrefSafe, ENT_QUOTES) ?>">
                <img src="<?= htmlspecialchars($fdbSrc, ENT_QUOTES) ?>"
                     alt="<?= htmlspecialchars($fdbAlt, ENT_QUOTES) ?>"
                     style="<?= $fdbObjPos ?>">
            </a>
        <?php else: ?>
            <span class="fdb-img-frame">
                <img src="<?= htmlspecialchars($fdbSrc, ENT_QUOTES) ?>"
                     alt="<?= htmlspecialchars($fdbAlt, ENT_QUOTES) ?>"
                     style="<?= $fdbObjPos ?>">
            </span>
        <?php endif; ?>

        <?php if ($fdbCaption !== ''): ?>
            <figcaption class="fdb-img-caption">
                <?= htmlspecialchars($fdbCaption, ENT_QUOTES) ?>
            </figcaption>
        <?php endif; ?>
    </figure>
</div>
