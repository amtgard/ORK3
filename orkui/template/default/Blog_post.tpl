<?php
/*
 * Blog_post.tpl — single blog entry (PLAIN PHP, extract()+include; never Smarty).
 *
 * Provided by Controller_Blog::post():
 *   $post        post row (title, excerpt, author_name, published_at, tags[]) or null
 *   $post_blocks ordered enabled body blocks (renderer shape) — rendered via the
 *                shared frontdoor/render_blocks.tpl ($fdBlocks)
 *   $hero        media ref for hero_media_id (src/thumb/alt/focal) or null
 *   $Message     set (e.g. "Post not found.") when there is no post
 *
 * The body reuses the SAME block renderer pages use, so post bodies inherit
 * front-door block styling. Dark-mode aware.
 */
$fdDir       = DIR_TEMPLATE . 'default/frontdoor/';
$fdAssetBase = HTTP_TEMPLATE . 'default/frontdoor/';

$postFound = isset($post) && is_array($post) && !empty($post);
$fdBlocks  = (isset($post_blocks) && is_array($post_blocks)) ? $post_blocks : [];
$heroRef   = (isset($hero) && is_array($hero)) ? $hero : null;
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/frontdoor.css?v=<?= @filemtime($fdDir . 'css/frontdoor.css') ?>">
<style>
/* ---- Blog entry (scoped under .fd-page) --------------------------------- */
.blogp-wrap { max-width: 820px; margin: 0 auto; padding: 28px 20px 8px; }
.blogp-back {
    display: inline-flex; align-items: center; gap: 6px;
    color: #1d4ed8; font-weight: 600; font-size: 14px; text-decoration: none; margin-bottom: 20px;
}
.blogp-back:hover { text-decoration: underline; }
.blogp-title {
    background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
    margin: 0 0 10px; font-size: 34px; line-height: 1.15; color: #16203a;
}
.blogp-meta { font-size: 14px; color: #6b7488; margin-bottom: 14px; }
.blogp-tags { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 8px; }
.blogp-tag {
    font-size: 12px; font-weight: 600; color: #475063;
    background: #eef1f7; border: 1px solid #dde2ec; border-radius: 999px;
    padding: 3px 11px; text-decoration: none;
}
.blogp-tag:hover { background: #e3e8f3; }
.blogp-hero {
    margin: 18px 0 6px; border-radius: 14px; overflow: hidden;
    border: 1px solid #e4e8f0; background: #f2f4f8;
}
.blogp-hero img { display: block; width: 100%; height: auto; }
.blogp-body { margin-top: 8px; }
.blogp-empty { padding: 2rem; text-align: center; color: #8899aa; }
.blogp-footer { max-width: 820px; margin: 0 auto; padding: 8px 20px 48px; }

/* ---- Dark mode (html[data-theme="dark"]) -------------------------------- */
html[data-theme="dark"] .blogp-title { color: #eef2fa; }
html[data-theme="dark"] .blogp-meta { color: #9aa6bd; }
html[data-theme="dark"] .blogp-tag { color: #c4cde0; background: #232c42; border-color: #34405e; }
html[data-theme="dark"] .blogp-tag:hover { background: #2c3650; }
html[data-theme="dark"] .blogp-hero { background: #1b2233; border-color: #2c3650; }
html[data-theme="dark"] .blogp-empty { color: #9aa6bd; }
</style>

<div class="fd-page">
<?php include $fdDir . 'site_header.tpl'; ?>
<?php if (!$postFound): ?>
    <div class="blogp-wrap">
        <a class="blogp-back" href="<?= UIR ?>Blog/index"><i class="fas fa-arrow-left"></i> Back to all posts</a>
        <p class="blogp-empty"><?= htmlspecialchars((string) ($Message ?? 'Post not found.'), ENT_QUOTES) ?></p>
    </div>
<?php else: ?>
    <?php
    $title   = htmlspecialchars((string) ($post['title'] ?? ''), ENT_QUOTES);
    $author  = htmlspecialchars((string) ($post['author_name'] ?? ''), ENT_QUOTES);
    $tags    = (isset($post['tags']) && is_array($post['tags'])) ? $post['tags'] : [];
    $dateLabel = '';
    if (!empty($post['published_at'])) {
        $ts = strtotime((string) $post['published_at']);
        if ($ts !== false) {
            $dateLabel = date('F j, Y', $ts);
        }
    }
    $heroSrc   = $heroRef ? (string) ($heroRef['src'] ?? '') : '';
    $heroAlt   = $heroRef ? (string) ($heroRef['alt'] ?? '') : '';
    $heroFocal = $heroRef ? (string) ($heroRef['focal'] ?? '50% 50%') : '';
    ?>
    <div class="blogp-wrap">
        <a class="blogp-back" href="<?= UIR ?>Blog/index"><i class="fas fa-arrow-left"></i> Back to all posts</a>

        <h1 class="blogp-title fd-serif"><?= $title ?></h1>

        <div class="blogp-meta">
            <?php if ($dateLabel !== ''): ?><?= htmlspecialchars($dateLabel, ENT_QUOTES) ?><?php endif; ?>
            <?php if ($author !== ''): ?><?= ($dateLabel !== '' ? ' &middot; by ' : 'By ') ?><?= $author ?><?php endif; ?>
        </div>

        <?php if (!empty($tags)): ?>
            <div class="blogp-tags">
                <?php foreach ($tags as $t): ?>
                    <a class="blogp-tag" href="<?= htmlspecialchars(UIR . 'Blog/index&tag=' . rawurlencode((string) ($t['slug'] ?? '')), ENT_QUOTES) ?>">
                        <?= htmlspecialchars((string) ($t['name'] ?? ''), ENT_QUOTES) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($heroSrc !== ''): ?>
            <div class="blogp-hero">
                <img src="<?= htmlspecialchars($heroSrc, ENT_QUOTES) ?>"
                     alt="<?= htmlspecialchars($heroAlt, ENT_QUOTES) ?>"
                     style="object-position:<?= htmlspecialchars($heroFocal, ENT_QUOTES) ?>;">
            </div>
        <?php endif; ?>
    </div>

    <div class="blogp-body">
        <?php include $fdDir . 'render_blocks.tpl'; ?>
    </div>

    <div class="blogp-footer">
        <a class="blogp-back" href="<?= UIR ?>Blog/index"><i class="fas fa-arrow-left"></i> Back to all posts</a>
    </div>
<?php endif; ?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js"></script>
