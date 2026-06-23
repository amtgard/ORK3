<?php
/*
 * Blog_index.tpl — public blog feed (PLAIN PHP, extract()+include; never Smarty).
 *
 * Provided by Controller_Blog::index():
 *   $posts        list of post rows (author_name, excerpt, hero_media_id, slug,
 *                 title, published_at, tags[] => [['name','slug'],...])
 *   $page         current 1-based page
 *   $total_pages  total page count
 *   $tag          active tag-slug filter ('' = none)
 *
 * Hero thumbs: a row may carry hero_media_id but the list query does NOT resolve
 * media paths, so we only show a hero placeholder accent — entry pages resolve the
 * full image. Cards link to Blog/post/{slug}. Reuses fd- classes; dark-mode aware.
 */
$fdDir       = DIR_TEMPLATE . 'default/frontdoor/';
$fdAssetBase = HTTP_TEMPLATE . 'default/frontdoor/';

$blogPosts = isset($posts) && is_array($posts) ? $posts : [];
$blogPage  = isset($page) ? (int) $page : 1;
$blogPages = isset($total_pages) ? (int) $total_pages : 1;
$blogTag   = isset($tag) ? (string) $tag : '';

// Build a page link preserving the active tag.
$blogPageHref = function ($p) use ($blogTag) {
    $href = UIR . 'Blog/index';
    $qs = [];
    if ($blogTag !== '') {
        $qs[] = 'tag=' . rawurlencode($blogTag);
    }
    if ((int) $p > 1) {
        $qs[] = 'p=' . (int) $p;
    }
    if (!empty($qs)) {
        $href .= '&' . implode('&', $qs);
    }
    return $href;
};
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/frontdoor.css?v=<?= @filemtime($fdDir . 'css/frontdoor.css') ?>">
<style>
/* ---- Blog feed (scoped under .fd-page) ---------------------------------- */
.blog-wrap { max-width: 1100px; margin: 0 auto; padding: 32px 20px 56px; }
.blog-head { display: flex; justify-content: space-between; align-items: flex-end;
    gap: 16px; flex-wrap: wrap; margin-bottom: 26px; }
.blog-title {
    background: transparent; border: none; padding: 0; border-radius: 0;
    text-shadow: none; margin: 0; font-size: 30px; line-height: 1.1;
}
.blog-rss {
    display: inline-flex; align-items: center; gap: 6px;
    color: #b8860b; font-weight: 700; font-size: 13px; text-decoration: none;
}
.blog-rss:hover { text-decoration: underline; }
.blog-tagnote { font-size: 13px; color: #667; margin: -14px 0 22px; }
.blog-tagnote a { color: #1d4ed8; text-decoration: none; }
.blog-tagnote a:hover { text-decoration: underline; }

.blog-grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;
}
.blog-card {
    display: flex; flex-direction: column; text-decoration: none; color: inherit;
    background: #fff; border: 1px solid #e4e8f0; border-radius: 12px;
    overflow: hidden; transition: box-shadow .15s ease, transform .15s ease;
}
.blog-card:hover { box-shadow: 0 8px 24px rgba(20,30,60,.12); transform: translateY(-2px); }
.blog-card-accent { height: 6px; background: var(--gold, #d4af37); }
.blog-card-body { padding: 16px 18px 18px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
.blog-card-meta { font-size: 12px; color: #8a93a6; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
.blog-card-title { font-size: 18px; font-weight: 700; line-height: 1.25; margin: 0; color: #1a2236; }
.blog-card-excerpt { font-size: 14px; color: #50596e; line-height: 1.5; margin: 0; }
.blog-card-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: auto; padding-top: 6px; }
.blog-tag {
    font-size: 11px; font-weight: 600; color: #475063;
    background: #eef1f7; border: 1px solid #dde2ec; border-radius: 999px;
    padding: 2px 9px; text-decoration: none;
}
.blog-tag:hover { background: #e3e8f3; }

.blog-pager { display: flex; justify-content: center; align-items: center; gap: 14px; margin-top: 36px; }
.blog-pager-btn {
    display: inline-flex; align-items: center; gap: 6px;
    border: 1px solid #d3d9e6; border-radius: 8px; padding: 8px 16px;
    font-weight: 600; font-size: 14px; color: #1a2236; text-decoration: none; background: #fff;
}
.blog-pager-btn:hover { background: #f3f5fa; }
.blog-pager-btn.is-disabled { opacity: .4; pointer-events: none; }
.blog-pager-info { font-size: 13px; color: #667; }

@media (max-width: 860px) { .blog-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .blog-grid { grid-template-columns: 1fr; } }

/* ---- Dark mode (html[data-theme="dark"]) -------------------------------- */
html[data-theme="dark"] .blog-card { background: #1b2233; border-color: #2c3650; }
html[data-theme="dark"] .blog-card-title { color: #eef2fa; }
html[data-theme="dark"] .blog-card-excerpt { color: #b6c0d4; }
html[data-theme="dark"] .blog-card-meta { color: #8794ad; }
html[data-theme="dark"] .blog-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.45); }
html[data-theme="dark"] .blog-tag { color: #c4cde0; background: #232c42; border-color: #34405e; }
html[data-theme="dark"] .blog-tag:hover { background: #2c3650; }
html[data-theme="dark"] .blog-tagnote { color: #9aa6bd; }
html[data-theme="dark"] .blog-pager-btn { background: #1b2233; border-color: #2c3650; color: #eef2fa; }
html[data-theme="dark"] .blog-pager-btn:hover { background: #232c42; }
html[data-theme="dark"] .blog-pager-info { color: #9aa6bd; }
</style>

<div class="fd-page">
<div class="blog-wrap">
    <div class="blog-head">
        <h1 class="blog-title fd-serif">Amtgard News</h1>
        <a class="blog-rss" href="<?= UIR ?>Blog/rss" title="RSS feed">
            <i class="fas fa-rss"></i> RSS
        </a>
    </div>

    <?php if ($blogTag !== ''): ?>
        <div class="blog-tagnote">
            Showing posts tagged &ldquo;<strong><?= htmlspecialchars($blogTag, ENT_QUOTES) ?></strong>&rdquo;.
            <a href="<?= UIR ?>Blog/index">Clear filter</a>
        </div>
    <?php endif; ?>

    <?php if (empty($blogPosts)): ?>
        <div class="fd-empty">No posts published yet. Check back soon!</div>
    <?php else: ?>
        <div class="blog-grid">
            <?php foreach ($blogPosts as $bp): ?>
                <?php
                $slug    = isset($bp['slug']) ? (string) $bp['slug'] : '';
                $title   = htmlspecialchars((string) ($bp['title'] ?? ''), ENT_QUOTES);
                $excerpt = htmlspecialchars((string) ($bp['excerpt'] ?? ''), ENT_QUOTES);
                $author  = htmlspecialchars((string) ($bp['author_name'] ?? ''), ENT_QUOTES);
                $tags    = (isset($bp['tags']) && is_array($bp['tags'])) ? $bp['tags'] : [];
                $dateLabel = '';
                if (!empty($bp['published_at'])) {
                    $ts = strtotime((string) $bp['published_at']);
                    if ($ts !== false) {
                        $dateLabel = date('M j, Y', $ts);
                    }
                }
                $postHref = UIR . 'Blog/post/' . rawurlencode($slug);
                ?>
                <a class="blog-card" href="<?= htmlspecialchars($postHref, ENT_QUOTES) ?>">
                    <div class="blog-card-accent"></div>
                    <div class="blog-card-body">
                        <div class="blog-card-meta">
                            <?php if ($dateLabel !== ''): ?><?= htmlspecialchars($dateLabel, ENT_QUOTES) ?><?php endif; ?>
                            <?php if ($author !== ''): ?><?= ($dateLabel !== '' ? ' &middot; ' : '') ?><?= $author ?><?php endif; ?>
                        </div>
                        <h2 class="blog-card-title"><?= $title ?></h2>
                        <?php if ($excerpt !== ''): ?>
                            <p class="blog-card-excerpt"><?= $excerpt ?></p>
                        <?php endif; ?>
                        <?php if (!empty($tags)): ?>
                            <div class="blog-card-tags">
                                <?php foreach ($tags as $t): ?>
                                    <span class="blog-tag"><?= htmlspecialchars((string) ($t['name'] ?? ''), ENT_QUOTES) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($blogPages > 1): ?>
            <div class="blog-pager">
                <a class="blog-pager-btn<?= $blogPage <= 1 ? ' is-disabled' : '' ?>"
                   href="<?= htmlspecialchars($blogPageHref($blogPage - 1), ENT_QUOTES) ?>">
                    <i class="fas fa-chevron-left"></i> Newer
                </a>
                <span class="blog-pager-info">Page <?= (int) $blogPage ?> of <?= (int) $blogPages ?></span>
                <a class="blog-pager-btn<?= $blogPage >= $blogPages ? ' is-disabled' : '' ?>"
                   href="<?= htmlspecialchars($blogPageHref($blogPage + 1), ENT_QUOTES) ?>">
                    Older <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</div>
