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

// Optional render data from Controller_Site; each degrades to a no-op value.
$siteCrumbs      = (isset($SiteBreadcrumbs) && is_array($SiteBreadcrumbs)) ? $SiteBreadcrumbs : [];
$siteHomeWarning = (isset($SiteHomeWarning) && $SiteHomeWarning !== '') ? (string) $SiteHomeWarning : '';
$sitePageTitle   = isset($page_title) ? (string) $page_title : '';

// $fdHasBlockH1: does a content block already supply the page's one and only
// <h1>? Only a hero_carousel can — the editor's Level control
// (fieldNumSelect(block, 'level', …) in script/cms-block-editor.js) offers
// H2/H3/H4 only, so no authored heading block is ever level 1. If H1 is ever
// added to that Level control, a level-1 test MUST be added here with it or such
// a page renders two <h1>s. When this stays false the fallback page-title <h1>
// below runs, so the outline always has exactly one top heading (WCAG 1.3.1).
//
// The test mirrors what hero_carousel.tpl ACTUALLY renders, not merely that a
// slides array exists: that partial drops slides with no image/headline/subcopy/
// kicker, and gives the <h1> to the FIRST surviving slide only when its headline
// is non-empty (later slides get <h2>). An image-only first slide — the shape the
// editor seeds — therefore renders an <img> and no heading at all, so anything
// looser than "first renderable slide has a headline" would suppress the fallback
// on a page that ships ZERO h1s. Headline test is `!== ''` with no trim, matching
// hero_carousel.tpl. The slide filter is fdHeroRenderableSlides() in
// frontdoor/_helpers.tpl, which hero_carousel.tpl calls too, so the two cannot
// drift. (org_header.tpl below also require_once's the helpers, but this loop
// runs before that include.)
require_once $fdDir . '_helpers.tpl';
$fdHasBlockH1 = false;
foreach ($fdBlocks as $fdBlock) {
    if (empty($fdBlock['enabled'])) {
        continue;
    }
    $fdBlockType = isset($fdBlock['type']) ? (string) $fdBlock['type'] : '';
    if ($fdBlockType !== 'hero_carousel') {
        continue;
    }
    $fdSlides = (isset($fdBlock['fields']['slides']) && is_array($fdBlock['fields']['slides'])) ? $fdBlock['fields']['slides'] : [];
    $fdRenderable = fdHeroRenderableSlides($fdSlides);
    if (!empty($fdRenderable) && (string) ($fdRenderable[0]['headline'] ?? '') !== '') {
        $fdHasBlockH1 = true;
        break;
    }
}

// A standalone org site never opts into the blog CSS layer ($fdWantBlog), in ANY
// mode — not even 'blog'/'post'. The 'post' branch below renders .org-post* and
// the 'blog' branch renders org_blog_index.tpl's .org-blog-*, both styled end to
// end by orgsite.css, which this template links directly. Every selector in
// blog.css is .blog-* or .blogp-* (.org-blog-card is NOT one of them), so the
// layer would match 0 nodes here: 6,811 bytes of dead CSS. blog.css belongs to
// the IN-SHELL blog alone (Blog_index.tpl / Blog_post.tpl). If an org-site
// surface ever starts emitting .blog-* / .blogp-* markup, set $fdWantBlog = true
// immediately before the include below and the layer comes back.
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
<?php // A 404 stub pays two uncached Park queries for chrome nobody asked for. ?>
<?php if ($siteMode !== 'notfound') {
    include $fdDir . '_park_strip.tpl';
} ?>
<?php if ($siteHomeWarning !== '') : ?>
<?php // .org-home-warning styling lives in frontdoor/css/orgsite.css. ?>
<div class="org-home-warning" role="status">
    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
    <span><strong>Manager preview</strong> &mdash; <?= htmlspecialchars($siteHomeWarning, ENT_QUOTES) ?></span>
</div>
<?php endif; ?>
<?php if ($siteMode === 'comingsoon') : ?>
    <section class="org-notice">
        <div class="org-notice-card">
            <i class="fas fa-hard-hat org-notice-icon" aria-hidden="true"></i>
            <h1 class="org-notice-title"><?= htmlspecialchars(!empty($SiteName) ? (string) $SiteName : 'This site', ENT_QUOTES) ?> is coming soon</h1>
            <?php // 'Kingdom' / 'Principality' / 'Park' from the site's own scope.
                  // A principality is stored as a kingdom row, so the noun has to come
                  // from the scope rather than from the table it lives in. ?>
            <p class="org-notice-text">This <?= htmlspecialchars(!empty($SiteOrgNoun) ? strtolower((string) $SiteOrgNoun) : 'group', ENT_QUOTES) ?> is building its public website. Please check back soon.</p>
        </div>
    </section>
<?php elseif ($siteMode === 'notfound') : ?>
    <section class="org-notice">
        <div class="org-notice-card">
            <i class="fas fa-compass org-notice-icon" aria-hidden="true"></i>
            <h1 class="org-notice-title">Page not found</h1>
            <p class="org-notice-text"><?= htmlspecialchars(!empty($Message) ? (string) $Message : 'This page could not be found.', ENT_QUOTES) ?></p>
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
            <?php // The post body's blocks ARE $fdBlocks (fed to render_blocks.tpl
                  // below), so a hero_carousel in a post supplies the page's h1. Keep the
                  // post title visible but demote it to h2 in that case, so the page still
                  // has one and only one top heading. ?>
            <?php $fdPostTitleTag = $fdHasBlockH1 ? 'h2' : 'h1'; ?>
            <<?= $fdPostTitleTag ?> class="org-post-title"><?= htmlspecialchars(isset($SitePost['title']) ? (string) $SitePost['title'] : '', ENT_QUOTES) ?></<?= $fdPostTitleTag ?>>
            <?php $fdDateLabel = fdFormatDate($SitePost['published_at'] ?? '', 'F j, Y'); ?>
            <?php if ($fdDateLabel !== '') : ?>
            <div class="org-post-meta"><?= htmlspecialchars($fdDateLabel, ENT_QUOTES) ?></div>
            <?php endif; ?>
        </header>
        <?php include $fdDir . 'render_blocks.tpl'; ?>
    </article>
<?php elseif (!empty($Message) && empty($fdBlocks)) : ?>
    <section class="org-notice">
        <div class="org-notice-card">
            <h1 class="org-notice-title"><?= htmlspecialchars(!empty($SiteName) ? (string) $SiteName : 'This site', ENT_QUOTES) ?></h1>
            <p class="org-notice-text"><?= htmlspecialchars((string) $Message, ENT_QUOTES) ?></p>
        </div>
    </section>
<?php else : ?>
    <?php
    // Breadcrumb trail (page mode). The controller makes the last crumb the
    // current page (url=''); a lone home crumb is skipped so top-level pages stay
    // clean. Linked ancestors, plain current page.
    $fdCrumbs = array_values(array_filter(
        $siteCrumbs,
        function ($c) {
            return is_array($c) && trim((string) ($c['label'] ?? '')) !== '';
        }
    ));
    ?>
    <?php if (count($fdCrumbs) > 1) : ?>
    <?php // .org-breadcrumbs styling lives in frontdoor/css/orgsite.css. ?>
    <nav class="org-breadcrumbs" aria-label="Breadcrumb">
        <ol>
        <?php
        $fdCrumbCount = count($fdCrumbs);
        foreach ($fdCrumbs as $fdCrumbIdx => $fdCrumb) :
            $fdCrumbLabel  = (string) ($fdCrumb['label'] ?? '');
            $fdCrumbUrl    = (string) ($fdCrumb['url'] ?? '');
            $fdCrumbIsLast = ($fdCrumbIdx === $fdCrumbCount - 1);
        ?>
            <li class="<?= $fdCrumbIsLast ? 'is-current' : '' ?>">
                <?php if (!$fdCrumbIsLast && $fdCrumbUrl !== '') : ?>
                <a href="<?= htmlspecialchars($fdCrumbUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($fdCrumbLabel, ENT_QUOTES) ?></a>
                <?php else : ?>
                <span<?= $fdCrumbIsLast ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($fdCrumbLabel, ENT_QUOTES) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ol>
    </nav>
    <?php endif; ?>
    <?php
    // Exactly one <h1>. When no content block supplies the top heading
    // (no hero, no level-1 heading block), promote the page title (page mode) or
    // site name (home) to the page's <h1>. Purely an outline fix; visual weight is
    // class-driven, and .org-page-title in frontdoor/css/orgsite.css resets the
    // orkui global heading gray-box.
    $fdTitleH1 = $sitePageTitle !== '' ? $sitePageTitle : (isset($SiteName) ? (string) $SiteName : '');
    ?>
    <?php if (!$fdHasBlockH1 && trim($fdTitleH1) !== '') : ?>
    <?php // .org-page-title-wrap / .org-page-title styling lives in frontdoor/css/orgsite.css. ?>
    <div class="org-page-title-wrap">
        <h1 class="org-page-title"><?= htmlspecialchars($fdTitleH1, ENT_QUOTES) ?></h1>
    </div>
    <?php endif; ?>
    <?php include $fdDir . 'render_blocks.tpl'; ?>
<?php endif; ?>
<?php include $fdDir . 'org_footer.tpl'; ?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js?v=<?= @filemtime($fdDir . 'js/frontdoor.js') ?>"></script>
