<?php
// photo_mosaic.tpl — Plain PHP partial (extract()+include). No Smarty.
// Receives: $blockFields, UIR (constant)

$images  = $blockFields['images']  ?? [];
$caption = htmlspecialchars($blockFields['caption'] ?? '', ENT_QUOTES, 'UTF-8');

// Mosaic layout: first image spans 2 rows, then up to 3 more, then caption tile.
// Grid: 3 cols (2fr 1fr 1fr), 2 rows (190px 190px) with 4px gap.
$img0 = $images[0] ?? null;
$img1 = $images[1] ?? null;
$img2 = $images[2] ?? null;
$img3 = $images[3] ?? null;

if (empty($images)) {
    return;
}
?>
<div class="fd-mosaic" style="display:grid;grid-template-columns:2fr 1fr 1fr;grid-template-rows:190px 190px;gap:4px">

    <?php if ($img0): ?>
    <img
        src="<?= htmlspecialchars($img0['src'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        alt="<?= htmlspecialchars($img0['alt'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        style="grid-row:span 2;width:100%;height:100%;object-fit:cover"
    >
    <?php endif; ?>

    <?php if ($img1): ?>
    <img
        src="<?= htmlspecialchars($img1['src'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        alt="<?= htmlspecialchars($img1['alt'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        style="width:100%;height:100%;object-fit:cover"
    >
    <?php endif; ?>

    <?php if ($img2): ?>
    <img
        src="<?= htmlspecialchars($img2['src'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        alt="<?= htmlspecialchars($img2['alt'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        style="width:100%;height:100%;object-fit:cover"
    >
    <?php endif; ?>

    <?php if ($img3): ?>
    <img
        src="<?= htmlspecialchars($img3['src'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        alt="<?= htmlspecialchars($img3['alt'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        style="width:100%;height:100%;object-fit:cover"
    >
    <?php endif; ?>

    <!-- Caption tile (always rendered, fills last cell) -->
    <div style="background:var(--navy);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:14px">
        <?php if ($caption !== ''): ?>
        <div class="fd-serif" style="font-size:22px;color:var(--gold)"><?= $caption ?></div>
        <?php endif; ?>
        <div style="font-size:11px;opacity:.7;margin-top:4px">See more on the Media page &rarr;</div>
    </div>

</div>
