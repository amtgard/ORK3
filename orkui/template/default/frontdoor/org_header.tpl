<?php
/*
 * org_header.tpl — org branding + scoped nav for a standalone site shell.
 * PLAIN PHP (extract()+include; never Smarty). Reuses the .fd-nav markup +
 * frontdoor.css so it inherits the responsive hamburger + dark-mode styling.
 *
 * Nav source: the editable 'marketing' menu from the CMS nav store for THIS
 * org's scope (CmsNav::GetMenu('marketing', $SiteNavScopeType, $SiteNavScopeId)) —
 * the SAME menu key the scope-aware nav admin (CmsAjax::savenavitem) writes,
 * differentiated by scope. Empty
 * scope / empty menu → no links (graceful). CmsNav resolves page/post link
 * types to the GLOBAL Page/Blog routes; $orgHref() re-points those onto this
 * site's own /Site/... routes so a nav item stays inside the org site.
 *
 * In scope (from Controller_Site::_bootShell): $SiteName, $SiteLogoUrl,
 * $SiteHomeUrl, $SiteSlug, $SiteNavScopeType, $SiteNavScopeId, UIR.
 */
$uir          = defined('UIR') ? UIR : 'index.php?Route=';
$siteName     = isset($SiteName) ? (string) $SiteName : '';
$siteLogoUrl  = isset($SiteLogoUrl) ? (string) $SiteLogoUrl : '';
$homeUrl      = isset($SiteHomeUrl) && $SiteHomeUrl !== '' ? (string) $SiteHomeUrl : $uir;
$siteSlug     = isset($SiteSlug) ? (string) $SiteSlug : '';
$navScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'kingdom';
$navScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;

// Re-point globally-resolved CmsNav hrefs onto this site's scoped routes.
$orgHref = function ($href) use ($uir, $siteSlug) {
    $href = (string) $href;
    if ($siteSlug === '') {
        return $href;
    }
    $pagePrefix = $uir . 'Page/view/';
    $postPrefix = $uir . 'Blog/post/';
    if (strpos($href, $pagePrefix) === 0) {
        return $uir . 'Site/page/' . rawurlencode($siteSlug) . '/' . substr($href, strlen($pagePrefix));
    }
    if (strpos($href, $postPrefix) === 0) {
        return $uir . 'Site/post/' . rawurlencode($siteSlug) . '/' . substr($href, strlen($postPrefix));
    }
    if ($href === $uir . 'Blog' || $href === $uir . 'Blog/index') {
        return $uir . 'Site/blog/' . rawurlencode($siteSlug);
    }
    return $href;
};

// A resolved href is only emitted if it passes the shared URL-safety gate.
$safeHref = function ($href) {
    return (class_exists('CmsSanitizer') && CmsSanitizer::IsSafeUrl((string) $href)) ? (string) $href : '#';
};

$items = [];
if ($navScopeId > 0 && class_exists('APIModel')) {
    try {
        $navModel  = new APIModel('CmsNav');
        $navResult = $navModel->GetMenu('marketing', $navScopeType, $navScopeId);
        if (is_array($navResult)) {
            foreach ($navResult as $navItem) {
                $row = [
                    'label'  => (string) ($navItem['label'] ?? ''),
                    'href'   => $orgHref($navItem['href'] ?? '#'),
                    'target' => (string) ($navItem['target'] ?? ''),
                ];
                if (!empty($navItem['children']) && is_array($navItem['children'])) {
                    $kids = [];
                    foreach ($navItem['children'] as $navChild) {
                        $kids[] = [
                            'label'  => (string) ($navChild['label'] ?? ''),
                            'href'   => $orgHref($navChild['href'] ?? '#'),
                            'target' => (string) ($navChild['target'] ?? ''),
                        ];
                    }
                    if (!empty($kids)) {
                        $row['children'] = $kids;
                    }
                }
                $items[] = $row;
            }
        }
    } catch (\Throwable $e) {
        $items = [];
    }
}
?>
<nav class="fd-nav fd-nav-org">
    <a class="fd-org-brand" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES) ?>">
        <?php if ($siteLogoUrl !== '') : ?>
            <img class="fd-logo fd-org-logo" src="<?= htmlspecialchars($siteLogoUrl, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($siteName !== '' ? $siteName : 'Site logo', ENT_QUOTES) ?>">
        <?php endif; ?>
        <?php if ($siteName !== '') : ?>
            <span class="fd-org-name"><?= htmlspecialchars($siteName, ENT_QUOTES) ?></span>
        <?php endif; ?>
    </a>

    <div class="fd-navlinks">
        <?php foreach ($items as $item) : ?>
            <div class="fd-navitem">
                <?php if (!empty($item['children'])) : ?>
                    <a href="<?= htmlspecialchars($safeHref($item['href']), ENT_QUOTES) ?>"<?= !empty($item['target']) ? ' target="' . htmlspecialchars($item['target'], ENT_QUOTES) . '" rel="noopener"' : '' ?>>
                        <?= htmlspecialchars($item['label'], ENT_QUOTES) ?> &#9660;
                    </a>
                    <div class="fd-dropdown">
                        <?php foreach ($item['children'] as $child) : ?>
                            <a href="<?= htmlspecialchars($safeHref($child['href']), ENT_QUOTES) ?>"<?= !empty($child['target']) ? ' target="' . htmlspecialchars($child['target'], ENT_QUOTES) . '" rel="noopener"' : '' ?>>
                                <?= htmlspecialchars($child['label'], ENT_QUOTES) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <a href="<?= htmlspecialchars($safeHref($item['href']), ENT_QUOTES) ?>"<?= !empty($item['target']) ? ' target="' . htmlspecialchars($item['target'], ENT_QUOTES) . '" rel="noopener"' : '' ?>>
                        <?= htmlspecialchars($item['label'], ENT_QUOTES) ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <button class="fd-nav-toggle" aria-label="Menu">&#9776;</button>
</nav>
