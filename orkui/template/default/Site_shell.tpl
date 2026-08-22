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

// C13/C30 — controller-supplied render data that previously went nowhere.
$siteCrumbs      = (isset($SiteBreadcrumbs) && is_array($SiteBreadcrumbs)) ? $SiteBreadcrumbs : [];
$siteHomeWarning = (isset($SiteHomeWarning) && $SiteHomeWarning !== '') ? (string) $SiteHomeWarning : '';
$sitePageTitle   = isset($page_title) ? (string) $page_title : '';

// C26 — a page already carries exactly one <h1> when a content block supplies it
// (hero_carousel's first slide). Only then do we suppress the fallback page-title
// <h1> below, so the outline has one and only one top heading (WCAG 1.3.1).
//
// A heading block can NOT be that supplier today: the editor's Level control
// offers H2/H3/H4 only (script/cms-block-editor.js:1231-1232), so no authored
// heading block is ever level 1 (0 such rows exist). The level-1 test that used
// to live in this loop was therefore unreachable and has been removed. If H1 is
// ever added back to that Level control, this test MUST come back with it, or
// such a page renders two <h1>s.
$fdHasBlockH1 = false;
foreach ($fdBlocks as $__b) {
    if (empty($__b['enabled'])) {
        continue;
    }
    $__type = isset($__b['type']) ? (string) $__b['type'] : '';
    if ($__type === 'hero_carousel' && !empty($__b['fields']['slides']) && is_array($__b['fields']['slides'])) {
        $fdHasBlockH1 = true;
        break;
    }
}

// G3 — a standalone org site never opts into the blog CSS layer ($fdWantBlog),
// in ANY mode. F1 had narrowed it to 'blog'/'post' on the assumption that those
// two emit blog markup; measured against the served HTML, neither does. The
// 'post' branch below renders .org-post* and the 'blog' branch renders
// org_blog_index.tpl's .org-blog-* — both styled end to end by orgsite.css,
// which this template links directly. Every selector in blog.css is .blog-* or
// .blogp-* (note .org-blog-card is NOT one of them), so blog.css matched 0
// nodes on every org-site mode: 6,811 bytes of dead CSS on a post page and on
// the org blog index. blog.css belongs to the IN-SHELL blog alone
// (Blog_index.tpl / Blog_post.tpl). If an org-site surface ever starts emitting
// .blog-* / .blogp-* markup, set $fdWantBlog = true immediately before the
// include below and the layer comes back.
// tests/cms-css/boundary_test.php asserts this against the running app.
?>
<?php include $fdDir . '_assets_public.tpl'; ?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/orgsite.css?v=<?= @filemtime($fdDir . 'css/orgsite.css') ?>">
<div class="fd-page fd-org">
<?php if (!empty($SitePreview)) : ?>
<?php // .org-preview-banner styling lives in frontdoor/css/orgsite.css. ?>
<div class="org-preview-banner" role="status">
    <i class="fas fa-eye" aria-hidden="true"></i>
    <span><strong>Draft preview</strong> &mdash; this site isn&rsquo;t published yet. Only officers can see it; publish it from OGRE to go live.</span>
</div>
<?php endif; ?>
<?php include $fdDir . 'org_header.tpl'; ?>
<?php include $fdDir . '_park_strip.tpl'; ?>
<?php if ($siteHomeWarning !== '') : ?>
<?php // .org-home-warning styling lives in frontdoor/css/orgsite.css. ?>
<div class="org-home-warning" role="status">
    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
    <span><strong>Manager preview</strong> &mdash; <?= htmlspecialchars($siteHomeWarning) ?></span>
</div>
<?php endif; ?>
<?php if ($siteMode === 'comingsoon') : ?>
    <section class="org-notice">
        <div class="org-notice-card">
            <i class="fas fa-hard-hat org-notice-icon" aria-hidden="true"></i>
            <h1 class="org-notice-title"><?= htmlspecialchars(!empty($SiteName) ? (string) $SiteName : 'This site') ?> is coming soon</h1>
            <?php // 'Kingdom' / 'Principality' / 'Park' from the site's own scope —
                  // a principality is stored as a kingdom row, so hard-coding
                  // "kingdom" here told its visitors the wrong thing. ?>
            <p class="org-notice-text">This <?= htmlspecialchars(!empty($SiteOrgNoun) ? strtolower((string) $SiteOrgNoun) : 'group') ?> is building its public website. Please check back soon.</p>
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
            <h1 class="org-notice-title"><?= htmlspecialchars(!empty($SiteName) ? (string) $SiteName : 'This site') ?></h1>
            <p class="org-notice-text"><?= htmlspecialchars((string) $Message) ?></p>
        </div>
    </section>
<?php else : ?>
    <?php
    // C13 — breadcrumb trail (page mode). The controller makes the last crumb the
    // current page (url=''); a lone home crumb is skipped so top-level pages stay
    // clean. Linked ancestors, plain current page.
    $__crumbs = array_values(array_filter(
        $siteCrumbs,
        function ($c) {
            return is_array($c) && trim((string) ($c['label'] ?? '')) !== '';
        }
    ));
    ?>
    <?php if (count($__crumbs) > 1) : ?>
    <?php // .org-breadcrumbs styling lives in frontdoor/css/orgsite.css. ?>
    <nav class="org-breadcrumbs" aria-label="Breadcrumb">
        <ol>
        <?php
        $__nCrumb = count($__crumbs);
        foreach ($__crumbs as $__i => $__crumb) :
            $__label  = (string) ($__crumb['label'] ?? '');
            $__url    = (string) ($__crumb['url'] ?? '');
            $__isLast = ($__i === $__nCrumb - 1);
        ?>
            <li class="<?= $__isLast ? 'is-current' : '' ?>">
                <?php if (!$__isLast && $__url !== '') : ?>
                <a href="<?= htmlspecialchars($__url, ENT_QUOTES) ?>"><?= htmlspecialchars($__label, ENT_QUOTES) ?></a>
                <?php else : ?>
                <span<?= $__isLast ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($__label, ENT_QUOTES) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ol>
    </nav>
    <?php endif; ?>
    <?php
    // C26 — exactly one <h1>. When no content block supplies the top heading
    // (no hero, no level-1 heading block), promote the page title (page mode) or
    // site name (home) to the page's <h1>. Purely an outline fix; visual weight is
    // class-driven, and .org-page-title in frontdoor/css/orgsite.css resets the
    // orkui global heading gray-box.
    $__titleH1 = $sitePageTitle !== '' ? $sitePageTitle : (isset($SiteName) ? (string) $SiteName : '');
    ?>
    <?php if (!$fdHasBlockH1 && trim($__titleH1) !== '') : ?>
    <?php // .org-page-title-wrap / .org-page-title styling lives in frontdoor/css/orgsite.css. ?>
    <div class="org-page-title-wrap">
        <h1 class="org-page-title"><?= htmlspecialchars($__titleH1, ENT_QUOTES) ?></h1>
    </div>
    <?php endif; ?>
    <?php include $fdDir . 'render_blocks.tpl'; ?>
<?php endif; ?>
<?php include $fdDir . 'org_footer.tpl'; ?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js?v=<?= @filemtime($fdDir . 'js/frontdoor.js') ?>"></script>
