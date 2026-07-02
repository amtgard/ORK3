<?php
/*
 * Site_shell.tpl — standalone per-org CMS site content region (PLAIN PHP:
 * extract()+include; NEVER Smarty — use <?php ?>/<?= ?>).
 *
 * Rendered inside default.theme with $IsOrgSite=true, which suppresses the
 * global ORK nav bar + footer. This template owns the .fd-page wrapper, the org
 * header (logo + name + scoped nav), the per-$SiteMode content, and the subtle
 * "Part of the Amtgard ORK" footer tie-back. Per-org theme tokens are injected
 * by default.theme from $fdThemeCss (scoped to .fd-page); unthemed sites fall
 * back to the frontdoor.css :root defaults (today's look).
 *
 * Contract (set by Controller_Site):
 *   $SiteMode        'home'|'page'|'post'|'blog'|'comingsoon'|'notfound'
 *   $SiteName, $SiteLogoUrl, $SiteHomeUrl, $SiteSlug
 *   $SiteNavScopeType, $SiteNavScopeId   (scoped nav via CmsNav)
 *   $SiteBlocks      ordered enabled blocks (home/page/post)
 *   $SitePost        the post row (post mode)
 *   $SitePosts,$SitePostsPage,$SitePostsPages   (blog mode)
 *   $Message         friendly text for notfound / empty states
 */
$fdDir       = DIR_TEMPLATE . 'default/frontdoor/';
$fdAssetBase = HTTP_TEMPLATE . 'default/frontdoor/';
$siteMode    = isset($SiteMode) ? (string) $SiteMode : 'home';
$fdBlocks    = isset($SiteBlocks) && is_array($SiteBlocks) ? $SiteBlocks : [];
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/frontdoor.css?v=<?= @filemtime($fdDir . 'css/frontdoor.css') ?>">
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/orgsite.css?v=<?= @filemtime($fdDir . 'css/orgsite.css') ?>">
<div class="fd-page fd-org">
<?php if (!empty($SitePreview)) : ?>
<style>
.org-preview-banner{display:flex;align-items:center;gap:10px;justify-content:center;background:#b8862b;color:#fff;padding:10px 16px;font-size:14px;line-height:1.4;text-align:center;}
.org-preview-banner i{font-size:15px;opacity:.9;}
html[data-theme="dark"] .org-preview-banner{background:#8a6420;}
@media print{.org-preview-banner{display:none;}}
</style>
<div class="org-preview-banner" role="status">
    <i class="fas fa-eye" aria-hidden="true"></i>
    <span><strong>Draft preview</strong> &mdash; this site isn&rsquo;t published yet. Only officers can see it; publish it from the CMS to go live.</span>
</div>
<?php endif; ?>
<?php include $fdDir . 'org_header.tpl'; ?>
<?php if ($siteMode === 'comingsoon') : ?>
    <section class="org-notice">
        <div class="org-notice-card">
            <i class="fas fa-hard-hat org-notice-icon" aria-hidden="true"></i>
            <h1 class="org-notice-title"><?= htmlspecialchars(!empty($SiteName) ? (string) $SiteName : 'This site') ?> is coming soon</h1>
            <p class="org-notice-text">This kingdom is building its public website. Please check back soon.</p>
        </div>
    </section>
<?php elseif ($siteMode === 'notfound') : ?>
    <section class="org-notice">
        <div class="org-notice-card">
            <i class="fas fa-compass org-notice-icon" aria-hidden="true"></i>
            <h1 class="org-notice-title">Page not found</h1>
            <p class="org-notice-text"><?= htmlspecialchars(!empty($Message) ? (string) $Message : 'This page could not be found.') ?></p>
            <?php if (!empty($SiteHomeUrl)) : ?>
            <a class="org-btn" href="<?= htmlspecialchars((string) $SiteHomeUrl, ENT_QUOTES) ?>">Back to home</a>
            <?php endif; ?>
        </div>
    </section>
<?php elseif ($siteMode === 'blog') : ?>
    <?php include $fdDir . 'org_blog_index.tpl'; ?>
<?php elseif ($siteMode === 'post') : ?>
    <article class="org-post">
        <header class="org-post-head">
            <h1 class="org-post-title"><?= htmlspecialchars(isset($SitePost['title']) ? (string) $SitePost['title'] : '') ?></h1>
            <?php if (!empty($SitePost['published_at'])) : ?>
            <?php $__ts = strtotime((string) $SitePost['published_at']); ?>
            <?php if ($__ts !== false) : ?>
            <div class="org-post-meta"><?= htmlspecialchars(date('F j, Y', $__ts)) ?></div>
            <?php endif; ?>
            <?php endif; ?>
        </header>
        <?php include $fdDir . 'render_blocks.tpl'; ?>
    </article>
<?php elseif (!empty($Message) && empty($fdBlocks)) : ?>
    <section class="org-notice">
        <div class="org-notice-card">
            <p class="org-notice-text"><?= htmlspecialchars((string) $Message) ?></p>
        </div>
    </section>
<?php else : ?>
    <?php include $fdDir . 'render_blocks.tpl'; ?>
<?php endif; ?>
<?php include $fdDir . 'org_footer.tpl'; ?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js"></script>
