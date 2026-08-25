<?php
/**
 * Partial: rich_text.tpl — the CMS's canonical rich-text block.
 * Receives: $blockFields (kicker, heading, body, align, cta?), UIR
 *
 * 'richtext' is a legacy DB alias for this same block type (see
 * script/cms-block-editor.js); richtext.tpl is a one-line include of this file so
 * both stored type names render from one implementation.
 *
 * body is rich-text passthrough — emitted raw. It is sanitized AUTHORITATIVELY at
 * the storage choke point every writer passes through, CmsPage::ReplaceBlocks (via
 * CmsSanitizer::Clean), NOT at render time, so there is deliberately no
 * re-sanitize here.
 */
$rtKicker  = $blockFields['kicker']  ?? '';
$rtHeading = $blockFields['heading'] ?? '';
$rtBody    = $blockFields['body']    ?? '';
$rtAlign   = $blockFields['align']   ?? 'left';
$rtCta     = $blockFields['cta']     ?? [];

$rtTextAlign  = ($rtAlign === 'center') ? 'text-align:center;' : '';
// Constrained single-column body is ALWAYS centered on the page (margin:0 auto);
// text-align (above) stays independent so copy can still be left- or centered.
$rtMarginAuto = 'margin:0 auto;';

// Every field empty → there is nothing to show, and the wrapper below would still
// paint a full-width banded section, so an untouched starter block would read as an
// empty grey stripe with no way to tell what it was. Follow the hero_carousel /
// photo_mosaic pattern: nothing at all for visitors, a discoverable hint for the
// author in the CMS editor/preview.
// A body is "empty" only when it has neither visible text NOR a void element that
// IS the content on its own — strip_tags() alone would treat an image-only or
// video-only body as blank and hide a block the author deliberately filled.
$rtBodyHasText  = trim(strip_tags((string) $rtBody)) !== '';
$rtBodyHasMedia = (bool) preg_match('/<(img|iframe|video|audio|hr|embed|object)\b/i', (string) $rtBody);

if (
    trim((string) $rtKicker) === ''
    && trim((string) $rtHeading) === ''
    && !$rtBodyHasText
    && !$rtBodyHasMedia
    && empty($rtCta['label'])
) {
    if ($fdIsPreview) {
        fdEmptyBlockNotice('This rich text block is empty.');
    }
    return;
}
?>
<div class="fd-pad fd-section-light" style="<?= $rtTextAlign ?>">
    <?php if (!empty($rtKicker)): ?>
        <div class="fd-kicker fd-kicker-d" style="margin-bottom:10px;">
            <?= htmlspecialchars($rtKicker, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($rtHeading)): ?>
        <h2 class="fd-sec-title" style="font-size:34px;margin-bottom:14px;">
            <?= htmlspecialchars($rtHeading, ENT_QUOTES) ?>
        </h2>
    <?php endif; ?>

    <?php if (!empty($rtBody)): ?>
        <div class="fd-body-text" style="max-width:680px;<?= $rtMarginAuto ?>font-size:18px;line-height:1.6;">
            <?php /* rich-text passthrough */ ?>
            <?= $rtBody ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($rtCta['label'])): ?>
        <div style="margin-top:18px;">
            <a class="fd-link" href="<?= htmlspecialchars(CmsSanitizer::SafeHrefOrHash($rtCta['href'] ?? ''), ENT_QUOTES) ?>">
                <?= htmlspecialchars($rtCta['label'], ENT_QUOTES) ?>
            </a>
        </div>
    <?php endif; ?>
</div>
