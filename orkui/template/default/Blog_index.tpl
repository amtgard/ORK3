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

<div class="fd-page">
<?php include $fdDir . 'site_header.tpl'; ?>
<div class="blog-wrap">
    <div class="blog-head">
        <h1 class="blog-title fd-serif">Amtgard News</h1>
        <a class="blog-rss" href="<?= UIR ?>Blog/rss" data-tip="RSS feed">
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
<script src="<?= $fdAssetBase ?>js/frontdoor.js"></script>
