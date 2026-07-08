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
// (hero_carousel's first slide, or a heading block set to level 1). Only then do
// we suppress the fallback page-title <h1> below, so the outline has one and only
// one top heading (WCAG 1.3.1).
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
    if ($__type === 'heading' && (int) ($__b['fields']['level'] ?? 2) === 1 && trim((string) ($__b['fields']['text'] ?? '')) !== '') {
        $fdHasBlockH1 = true;
        break;
    }
}
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
<?php if ($siteHomeWarning !== '') : ?>
<style>
.org-home-warning{display:flex;align-items:flex-start;gap:10px;max-width:1120px;margin:16px auto 0;padding:12px 16px;background:#fff4e0;border:1px solid #e6b866;border-left:4px solid #b8862b;border-radius:var(--fd-radius,8px);color:#5a4210;font-family:var(--fd-font-body);font-size:.9rem;line-height:1.45;}
.org-home-warning i{color:#b8862b;font-size:1rem;margin-top:2px;flex:0 0 auto;}
.org-home-warning strong{color:#7a5710;}
html[data-theme="dark"] .org-home-warning{background:#3a2f14;border-color:#6b5220;border-left-color:#caa03e;color:#f0e2c2;}
html[data-theme="dark"] .org-home-warning i{color:#caa03e;}
html[data-theme="dark"] .org-home-warning strong{color:#f7ecca;}
@media print{.org-home-warning{display:none;}}
</style>
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
    <style>
    .org-breadcrumbs{max-width:1120px;margin:0 auto;padding:18px 20px 0;}
    .org-breadcrumbs ol{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-family:var(--fd-font-body);font-size:.86rem;line-height:1.4;}
    .org-breadcrumbs li{display:flex;align-items:center;gap:6px;color:var(--fd-text-muted);}
    .org-breadcrumbs li:not(:first-child)::before{content:"\f105";font-family:"Font Awesome 5 Free";font-weight:900;font-size:.72rem;opacity:.6;}
    .org-breadcrumbs a{color:var(--fd-text-muted);text-decoration:none;}
    .org-breadcrumbs a:hover,.org-breadcrumbs a:focus-visible{color:var(--fd-accent);text-decoration:underline;}
    .org-breadcrumbs .is-current{color:var(--fd-text);font-weight:600;}
    </style>
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
    // class-driven, and the orkui global heading gray-box is reset below.
    $__titleH1 = $sitePageTitle !== '' ? $sitePageTitle : (isset($SiteName) ? (string) $SiteName : '');
    ?>
    <?php if (!$fdHasBlockH1 && trim($__titleH1) !== '') : ?>
    <style>
    .org-page-title-wrap{max-width:1120px;margin:0 auto;padding:14px 20px 0;}
    .org-page-title{background:transparent;border:none;border-radius:0;padding:0;margin:0;text-shadow:none;font-family:var(--fd-font-heading);font-size:2rem;line-height:1.15;color:var(--fd-text);}
    </style>
    <div class="org-page-title-wrap">
        <h1 class="org-page-title"><?= htmlspecialchars($__titleH1, ENT_QUOTES) ?></h1>
    </div>
    <?php endif; ?>
    <?php include $fdDir . 'render_blocks.tpl'; ?>
<?php endif; ?>
<?php include $fdDir . 'org_footer.tpl'; ?>
</div>
<script src="<?= $fdAssetBase ?>js/frontdoor.js"></script>
