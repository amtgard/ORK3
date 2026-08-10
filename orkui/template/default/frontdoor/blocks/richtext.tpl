<?php
/**
 * Partial: richtext.tpl
 * Receives: $blockFields (kicker, heading, body, align, cta?), UIR
 * body is rich-text passthrough — emitted raw.
 */
$kicker  = $blockFields['kicker']  ?? '';
$heading = $blockFields['heading'] ?? '';
$body    = $blockFields['body']    ?? '';
$align   = $blockFields['align']   ?? 'left';
$cta     = $blockFields['cta']     ?? [];

$textAlign  = ($align === 'center') ? 'text-align:center;' : '';
// Constrained single-column body is ALWAYS centered on the page (margin:0 auto);
// text-align (above) stays independent so copy can still be left- or centered.
$marginAuto = 'margin:0 auto;';

// Every field empty → there is nothing to show. Without this the wrapper below
// still painted a full-width banded section, so an untouched starter block read
// as an empty grey stripe on the page with no way to tell what it was. Follow the
// hero_carousel / photo_mosaic pattern: nothing at all for visitors, a discoverable
// hint for the author in the CMS editor/preview.
// A body is "empty" only when it has neither visible text NOR a void element that
// IS the content on its own — strip_tags() alone would treat an image-only or
// video-only body as blank and hide a block the author deliberately filled.
$bodyHasText  = trim(strip_tags((string) $body)) !== '';
$bodyHasMedia = (bool) preg_match('/<(img|iframe|video|audio|hr|embed|object)\b/i', (string) $body);

if (
    trim((string) $kicker) === ''
    && trim((string) $heading) === ''
    && !$bodyHasText
    && !$bodyHasMedia
    && empty($cta['label'])
) {
    if (!empty($SitePreview) || !empty($PreviewPage)) {
        echo '<div class="fd-pad" style="text-align:center;color:#8a97ad;font-style:italic;">'
            . 'This rich text block is empty.</div>';
    }
    return;
}
?>
<div class="fd-pad fd-section-light" style="<?= $textAlign ?>">
    <?php if (!empty($kicker)): ?>
        <div class="fd-kicker fd-kicker-d" style="margin-bottom:10px;">
            <?= htmlspecialchars($kicker, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($heading)): ?>
        <h2 class="fd-sec-title" style="font-size:34px;margin-bottom:14px;">
            <?= htmlspecialchars($heading, ENT_QUOTES) ?>
        </h2>
    <?php endif; ?>

    <?php if (!empty($body)): ?>
        <div class="fd-body-text" style="max-width:680px;<?= $marginAuto ?>font-size:18px;line-height:1.6;">
            <?php /* rich-text passthrough */ ?>
            <?= $body ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($cta['label'])): ?>
        <div style="margin-top:18px;">
            <a class="fd-link" href="<?= htmlspecialchars(CmsSanitizer::SafeHrefOrHash($cta['href'] ?? ''), ENT_QUOTES) ?>">
                <?= htmlspecialchars($cta['label'], ENT_QUOTES) ?>
            </a>
        </div>
    <?php endif; ?>
</div>
