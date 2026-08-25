<?php
// photo_mosaic.tpl — Plain PHP partial (extract()+include). No Smarty.
// Receives: $blockFields, UIR (constant)

$images  = $blockFields['images']  ?? [];
$caption = htmlspecialchars($blockFields['caption'] ?? '', ENT_QUOTES, 'UTF-8');

// Author-configurable CTA tile (optional). Render a real link only when
// BOTH a label and a usable href are supplied; otherwise the tile shows just the
// caption, or is omitted entirely when there's nothing to show.
$mosaicCtaLabel = trim((string)($blockFields['cta_label'] ?? ''));
$mosaicCtaHref  = CmsSanitizer::SafeHrefOrHash($blockFields['cta_href'] ?? '');
$mosaicHasCta   = ($mosaicCtaLabel !== '' && $mosaicCtaHref !== '' && $mosaicCtaHref !== '#');

// Mosaic layout: first image spans 2 rows, then up to 3 more, then caption tile.
// Grid: 3 cols (2fr 1fr 1fr), 2 rows (190px 190px) with 4px gap.
// Prefer the mid-size "display" rendition; fall back to the original src.
$mosaicSrc = static function ($img) {
    return is_array($img) ? (string)($img['display'] ?? $img['src'] ?? '') : '';
};
$img0 = $images[0] ?? null;
$img1 = $images[1] ?? null;
$img2 = $images[2] ?? null;
$img3 = $images[3] ?? null;

if (empty($images)) {
    // Author hint: surface the empty state in the CMS editor/preview
    // (SitePreview) only, so it's discoverable instead of a silently missing block.
    if ($fdIsPreview) {
        fdEmptyBlockNotice('This photo mosaic has no images yet.');
    }
    return;
}
?>
<div class="fd-mosaic" style="display:grid;grid-template-columns:2fr 1fr 1fr;grid-template-rows:190px 190px;gap:4px">

    <?php if ($img0): ?>
    <img
        src="<?= htmlspecialchars($mosaicSrc($img0), ENT_QUOTES, 'UTF-8') ?>"
        alt="<?= htmlspecialchars($img0['alt'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        style="grid-row:span 2;width:100%;height:100%;object-fit:cover"
    >
    <?php endif; ?>

    <?php if ($img1): ?>
    <img
        src="<?= htmlspecialchars($mosaicSrc($img1), ENT_QUOTES, 'UTF-8') ?>"
        alt="<?= htmlspecialchars($img1['alt'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        style="width:100%;height:100%;object-fit:cover"
    >
    <?php endif; ?>

    <?php if ($img2): ?>
    <img
        src="<?= htmlspecialchars($mosaicSrc($img2), ENT_QUOTES, 'UTF-8') ?>"
        alt="<?= htmlspecialchars($img2['alt'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        style="width:100%;height:100%;object-fit:cover"
    >
    <?php endif; ?>

    <?php if ($img3): ?>
    <img
        src="<?= htmlspecialchars($mosaicSrc($img3), ENT_QUOTES, 'UTF-8') ?>"
        alt="<?= htmlspecialchars($img3['alt'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        style="width:100%;height:100%;object-fit:cover"
    >
    <?php endif; ?>

    <?php // Caption / CTA tile — omitted entirely when there's neither a caption
          // nor a configured link. A real <a> is emitted only when the author
          // supplied a label + usable href; otherwise it's a plain caption tile. ?>
    <?php if ($caption !== '' || $mosaicHasCta): ?>
        <?php $mosaicTileTag = $mosaicHasCta ? 'a' : 'div'; ?>
        <<?= $mosaicTileTag ?> class="fd-mosaic-cta"<?php if ($mosaicHasCta): ?> href="<?= htmlspecialchars($mosaicCtaHref, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>
            style="background:var(--navy);color:var(--fd-primary-contrast);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:14px;text-decoration:none">
            <?php if ($caption !== ''): ?>
            <div class="fd-serif" style="font-size:22px;color:var(--gold)"><?= $caption ?></div>
            <?php endif; ?>
            <?php if ($mosaicHasCta): ?>
            <div style="font-size:11px;opacity:.7;margin-top:4px"><?= htmlspecialchars($mosaicCtaLabel, ENT_QUOTES, 'UTF-8') ?> &rarr;</div>
            <?php endif; ?>
        </<?= $mosaicTileTag ?>>
    <?php endif; ?>

</div>
