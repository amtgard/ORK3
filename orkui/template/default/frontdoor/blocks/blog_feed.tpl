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
?>
<style>
.bf-block { background: var(--fd-bg); }
.bf-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 18px; gap: 12px; }
.bf-title { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; margin: 0; font-size: 24px; }
.bf-more { color: #1d4ed8; font-weight: 600; font-size: 14px; text-decoration: none; white-space: nowrap; }
.bf-more:hover { text-decoration: underline; }
.bf-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.bf-card {
    display: block; text-decoration: none; color: inherit;
    background: var(--fd-bg); border: 1px solid #e4e8f0; border-radius: 10px; overflow: hidden;
    transition: box-shadow .15s ease, transform .15s ease;
}
.bf-card:hover { box-shadow: 0 6px 18px rgba(20,30,60,.12); transform: translateY(-2px); }
.bf-card-accent { height: 6px; background: var(--gold, #d4af37); }
.bf-card-body { padding: 14px 16px 16px; }
.bf-card-date { font-size: 12px; color: #b8860b; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.bf-card-title { font-weight: 700; font-size: 15px; margin: 5px 0 6px; line-height: 1.3; color: var(--fd-text); }
.bf-card-excerpt { font-size: 13px; color: #50596e; line-height: 1.45; margin: 0; }
.bf-empty { color: #8899aa; font-style: italic; text-align: center; padding: 18px; }

@media (max-width: 820px) { .bf-grid { grid-template-columns: 1fr; } }

html[data-theme="dark"] .bf-block { background: transparent; }
html[data-theme="dark"] .bf-card { background: #1b2233; border-color: #2c3650; }
html[data-theme="dark"] .bf-card-title { color: #eef2fa; }
html[data-theme="dark"] .bf-card-excerpt { color: #b6c0d4; }
html[data-theme="dark"] .bf-card:hover { box-shadow: 0 6px 18px rgba(0,0,0,.45); }
</style>
<div class="fd-pad fd-section-light bf-block" style="background:#fff;">
    <div class="bf-head">
        <?php if ($bfHeading !== ''): ?>
            <h3 class="bf-title fd-sec-title"><?= htmlspecialchars($bfHeading, ENT_QUOTES) ?></h3>
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
