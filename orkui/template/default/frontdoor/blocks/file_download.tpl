<?php
/**
 * Partial: file_download.tpl  (MEDIA / RESOURCE block) — self-contained (own scoped <style>)
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

/** Allow only http(s) or relative links; everything else → '' (link suppressed). */
$fdbSafeHref = static function ($href): string {
    $href = (string) $href;
    if ($href === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $href) || preg_match('#^[/?\#]#', $href)) {
        return $href;
    }
    return '';
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
<style>
/* scoped: fdb-file */
.fdb-file-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-width: 760px;
    margin: 0 auto;
}
.fdb-file-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 18px;
    background: #fff;
    border: 1px solid #e4e8f0;
    border-radius: 10px;
    text-decoration: none;
    color: inherit;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.fdb-file-card:hover {
    border-color: #c7cee0;
    box-shadow: 0 4px 14px rgba(0, 0, 0, .07);
}
.fdb-file-icon {
    flex: 0 0 auto;
    width: 44px;
    height: 44px;
    border-radius: 8px;
    background: #f1f3f8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #5a648a;
}
.fdb-file-body { flex: 1 1 auto; min-width: 0; }
.fdb-file-title {
    font-weight: 700;
    font-size: 15px;
    color: #1a2236;
    word-break: break-word;
}
.fdb-file-desc {
    font-size: 13px;
    line-height: 1.5;
    color: #667;
    margin-top: 2px;
}
.fdb-file-meta {
    font-size: 12px;
    color: #8a93ab;
    margin-top: 4px;
}
.fdb-file-meta span + span::before {
    content: "·";
    margin: 0 6px;
}
.fdb-file-dl {
    flex: 0 0 auto;
    font-size: 18px;
    color: #5a648a;
}
.fdb-file-card:hover .fdb-file-dl { color: #1d4ed8; }

html[data-theme="dark"] .fdb-file-card {
    background: #141b2d;
    border-color: #283250;
}
html[data-theme="dark"] .fdb-file-card:hover {
    border-color: #3a466e;
}
html[data-theme="dark"] .fdb-file-icon {
    background: #1e2740;
    color: #a9b3d0;
}
html[data-theme="dark"] .fdb-file-title { color: #e6eaf4; }
html[data-theme="dark"] .fdb-file-desc { color: #9aa6c0; }
html[data-theme="dark"] .fdb-file-meta { color: #7d88a6; }
html[data-theme="dark"] .fdb-file-dl { color: #a9b3d0; }
html[data-theme="dark"] .fdb-file-card:hover .fdb-file-dl { color: #7ea2ff; }
</style>
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
