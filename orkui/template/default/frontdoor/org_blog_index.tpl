<?php
/*
 * org_blog_index.tpl — scoped blog index for a standalone org site. PLAIN PHP.
 * Renders $SitePosts (from CmsPost::list_posts, scoped) as cards linking to the
 * org's own /Site/post/{slug}/{postSlug} route. Pagination uses &p= (UIR already
 * ends in ?Route=, so a second ? would empty $_GET).
 *
 * In scope: $SitePosts, $SitePostsPage, $SitePostsPages, $SiteSlug, UIR.
 */
$uir      = defined('UIR') ? UIR : '';
$slug     = isset($SiteSlug) ? (string) $SiteSlug : '';
$posts    = isset($SitePosts) && is_array($SitePosts) ? $SitePosts : [];
$pageNo   = isset($SitePostsPage) ? (int) $SitePostsPage : 1;
$pages    = isset($SitePostsPages) ? (int) $SitePostsPages : 1;
$blogBase = $uir . 'Site/blog/' . rawurlencode($slug);
?>
<section class="org-blog">
    <h1 class="org-blog-title">News</h1>
    <?php if (empty($posts)) : ?>
        <p class="org-empty">No posts have been published yet.</p>
    <?php else : ?>
        <div class="org-blog-list">
            <?php foreach ($posts as $post) : ?>
                <?php $pslug = isset($post['slug']) ? (string) $post['slug'] : ''; ?>
                <article class="org-blog-card">
                    <h2 class="org-blog-card-title">
                        <a href="<?= htmlspecialchars($uir . 'Site/post/' . rawurlencode($slug) . '/' . rawurlencode($pslug), ENT_QUOTES) ?>">
                            <?= htmlspecialchars(isset($post['title']) ? (string) $post['title'] : '', ENT_QUOTES) ?>
                        </a>
                    </h2>
                    <?php if (!empty($post['published_at'])) : ?>
                        <?php $__ts = strtotime((string) $post['published_at']); ?>
                        <?php if ($__ts !== false) : ?>
                            <div class="org-blog-card-meta"><?= htmlspecialchars(date('F j, Y', $__ts)) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($post['excerpt'])) : ?>
                        <p class="org-blog-card-excerpt"><?= htmlspecialchars((string) $post['excerpt']) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($pages > 1) : ?>
            <nav class="org-pager">
                <?php if ($pageNo > 1) : ?>
                    <a class="org-btn" href="<?= htmlspecialchars($blogBase . '&p=' . ($pageNo - 1), ENT_QUOTES) ?>">&larr; Newer</a>
                <?php endif; ?>
                <span class="org-pager-status">Page <?= (int) $pageNo ?> of <?= (int) $pages ?></span>
                <?php if ($pageNo < $pages) : ?>
                    <a class="org-btn" href="<?= htmlspecialchars($blogBase . '&p=' . ($pageNo + 1), ENT_QUOTES) ?>">Older &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
