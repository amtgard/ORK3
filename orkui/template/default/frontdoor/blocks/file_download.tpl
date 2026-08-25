<?php
/**
 * Partial: file_download.tpl  (MEDIA / RESOURCE block) — CSS lives in
 * frontdoor/css/blocks.css.
 * Receives: $blockFields, shared $data, UIR
 *
 * Fields:
 *   files  array of { title, description?, url, filetype?, size_label? }
 *
 * v2 is URL-based (no PDF upload pipeline). Renders a list of download cards:
 * a FontAwesome file icon (fa-file-pdf for pdf-ish filetypes, else fa-file),
 * the title, an optional description, and a download link. The href is allowed
 * only when http(s) or relative (no javascript:/data: injection).
 *
 * "Dumb" partial: renders $blockFields only, fetches nothing. Escapes every
 * authored string with htmlspecialchars(..., ENT_QUOTES).
 */
$fdbFiles = $blockFields['files'] ?? [];
$fdbFiles = is_array($fdbFiles) ? array_values(array_filter($fdbFiles, 'is_array')) : [];

/** Allow only links the authoritative checker deems safe (rejects javascript:,
 *  data:, vbscript:, protocol-relative //host, etc.); everything else → '' (link suppressed). */
$fdbSafeHref = static function ($href): string {
    $href = (string) $href;
    return CmsSanitizer::IsSafeUrl($href) ? $href : '';
};

/** Pick a FontAwesome icon class from the filetype hint. */
$fdbIconFor = static function ($filetype): string {
    $ft = strtolower((string) $filetype);
    return (strpos($ft, 'pdf') !== false) ? 'fa-file-pdf' : 'fa-file';
};

// Drop entries that have neither a title nor a usable link.
$fdbRows = [];
foreach ($fdbFiles as $fdbFile) {
    $fdbHref = $fdbSafeHref($fdbFile['url'] ?? '');
    $fdbTitle = (string) ($fdbFile['title'] ?? '');
    if ($fdbHref === '' && $fdbTitle === '') {
        continue;
    }
    $fdbRows[] = [
        'title' => $fdbTitle !== '' ? $fdbTitle : $fdbHref,
        'desc'  => (string) ($fdbFile['description'] ?? ''),
        'href'  => $fdbHref,
        'icon'  => $fdbIconFor($fdbFile['filetype'] ?? ''),
        'size'  => (string) ($fdbFile['size_label'] ?? ''),
        'ftype' => strtoupper((string) ($fdbFile['filetype'] ?? '')),
    ];
}

if (empty($fdbRows)) {
    return;
}
?>
<div class="fd-pad">
    <div class="fdb-file-list">
        <?php foreach ($fdbRows as $fdbRow): ?>
            <?php $fdbTag = ($fdbRow['href'] !== '') ? 'a' : 'div'; ?>
            <<?= $fdbTag ?> class="fdb-file-card"<?php if ($fdbRow['href'] !== ''): ?> href="<?= htmlspecialchars($fdbRow['href'], ENT_QUOTES) ?>" download<?php endif; ?>>
                <span class="fdb-file-icon"><i class="fas <?= htmlspecialchars($fdbRow['icon'], ENT_QUOTES) ?>"></i></span>
                <span class="fdb-file-body">
                    <span class="fdb-file-title"><?= htmlspecialchars($fdbRow['title'], ENT_QUOTES) ?></span>
                    <?php if ($fdbRow['desc'] !== ''): ?>
                        <span class="fdb-file-desc" style="display:block;"><?= htmlspecialchars($fdbRow['desc'], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                    <?php if ($fdbRow['ftype'] !== '' || $fdbRow['size'] !== ''): ?>
                        <span class="fdb-file-meta" style="display:block;">
                            <?php if ($fdbRow['ftype'] !== ''): ?><span><?= htmlspecialchars($fdbRow['ftype'], ENT_QUOTES) ?></span><?php endif; ?>
                            <?php if ($fdbRow['size'] !== ''): ?><span><?= htmlspecialchars($fdbRow['size'], ENT_QUOTES) ?></span><?php endif; ?>
                        </span>
                    <?php endif; ?>
                </span>
                <?php if ($fdbRow['href'] !== ''): ?>
                    <span class="fdb-file-dl"><i class="fas fa-download"></i></span>
                <?php endif; ?>
            </<?= $fdbTag ?>>
        <?php endforeach; ?>
    </div>
</div>
