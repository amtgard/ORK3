<?php
/**
 * Partial: blog_feed.tpl — DYNAMIC block. Shows the latest published posts.
 *
 * Receives: $blockFields { heading?, limit (default 3), tag? }, UIR
 *
 * Unlike most "dumb" block partials, this one READS data (like events_feed.tpl,
 * which sources $EventSummary). No controller injects blog posts onto arbitrary
 * pages, so this partial sources them itself via the CmsPost model pass-through
 * (new APIModel('CmsPost') — the same forward Model_CmsPost uses; the lib is
 * eagerly loaded at startup). Self-contained scoped style; dark-mode aware.
 */
$bfHeading = isset($blockFields['heading']) ? trim((string) $blockFields['heading']) : 'Latest News';
$bfLimit   = isset($blockFields['limit']) ? (int) $blockFields['limit'] : 3;
if ($bfLimit < 1) {
    $bfLimit = 3;
}
if ($bfLimit > 12) {
    $bfLimit = 12;
}
$bfTag = isset($blockFields['tag']) ? trim((string) $blockFields['tag']) : '';

$bfPosts = [];
if (class_exists('APIModel')) {
    try {
        $bfModel = new APIModel('CmsPost');
        $bfOpts  = ['limit' => $bfLimit, 'offset' => 0, 'scope_type' => 'global', 'scope_id' => 0];
        if ($bfTag !== '') {
            $bfOpts['tag'] = $bfTag;
        }
        $bfResult = $bfModel->ListPosts($bfOpts);
        if (is_array($bfResult) && isset($bfResult['rows']) && is_array($bfResult['rows'])) {
            $bfPosts = $bfResult['rows'];
        }
    } catch (\Throwable $e) {
        $bfPosts = [];
    }
}
$bfMoreHref = UIR . 'Blog/index' . ($bfTag !== '' ? ('&tag=' . rawurlencode($bfTag)) : '');
// Static .bf-* CSS lives in frontdoor.css (loaded on the front door AND under
// orgsite.css on org sites) — no per-render inline <style>.
?>
<div class="fd-pad fd-section-light bf-block">
    <div class="bf-head">
        <?php if ($bfHeading !== ''): ?>
            <h2 class="bf-title fd-sec-title"><?= htmlspecialchars($bfHeading, ENT_QUOTES) ?></h2>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <a class="bf-more" href="<?= htmlspecialchars($bfMoreHref, ENT_QUOTES) ?>">All news &rarr;</a>
    </div>

    <?php if (empty($bfPosts)): ?>
        <div class="bf-empty">No news yet.</div>
    <?php else: ?>
        <div class="bf-grid">
            <?php foreach ($bfPosts as $bfp): ?>
                <?php
                $bfSlug    = isset($bfp['slug']) ? (string) $bfp['slug'] : '';
                if ($bfSlug === '') {
                    continue;
                }
                $bfTitle   = htmlspecialchars((string) ($bfp['title'] ?? ''), ENT_QUOTES);
                $bfExcerpt = htmlspecialchars((string) ($bfp['excerpt'] ?? ''), ENT_QUOTES);
                $bfDate    = '';
                if (!empty($bfp['published_at'])) {
                    $bfTs = strtotime((string) $bfp['published_at']);
                    if ($bfTs !== false) {
                        $bfDate = date('M j, Y', $bfTs);
                    }
                }
                $bfHref = UIR . 'Blog/post/' . rawurlencode($bfSlug);
                ?>
                <a class="bf-card" href="<?= htmlspecialchars($bfHref, ENT_QUOTES) ?>">
                    <div class="bf-card-accent"></div>
                    <div class="bf-card-body">
                        <?php if ($bfDate !== ''): ?>
                            <div class="bf-card-date"><?= htmlspecialchars($bfDate, ENT_QUOTES) ?></div>
                        <?php endif; ?>
                        <div class="bf-card-title"><?= $bfTitle ?></div>
                        <?php if ($bfExcerpt !== ''): ?>
                            <p class="bf-card-excerpt"><?= $bfExcerpt ?></p>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
