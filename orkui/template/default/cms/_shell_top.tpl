<?php
/**
 * cms/_shell_top.tpl — OGRE admin shell (open).
 * OGRE = Online Gallery and Resource Engine, the product name for the CMS.
 * PLAIN PHP (extract()+include), NEVER Smarty. Use <?php ?>/<?= ?> only.
 *
 * Renders the persistent left rail + masthead workspace chrome that wraps every
 * OGRE admin surface. Include this BEFORE your page content and include
 * cms/_shell_bottom.tpl AFTER it. The ORK global app header/footer stay above;
 * this shell owns the workspace beneath them.
 *
 * Page-set variables (all optional unless noted):
 *   $cmsActive  string  which rail item is highlighted:
 *                       'dashboard'|'pages'|'posts'|'media'|'nav'  (any other
 *                       value, e.g. 'edit', highlights nothing — leaf surfaces).
 *   $cmsTitle   string  masthead display title. Default 'OGRE'.
 *   $cmsSub     string  optional subtitle under the title.
 *   $cmsCrumbs  array   optional breadcrumb: list of ['label'=>..,'href'=>?..].
 *                       The last crumb (or any without href) renders as plain text.
 *   $cmsActions string  optional raw HTML, right-aligned in the masthead
 *                       (e.g. the page's primary "New Page" button).
 *   $cmsRailExtra string optional raw HTML rendered in the rail beneath the nav
 *                       (e.g. the editor's Page-settings panel; widens the rail).
 *                       Intentionally RAW HTML.
 *   $Caps       array   capability flags; rail hides nav/media items without them.
 *   UIR                 (constant) controller route base.
 *
 * NOTE: $cmsActions is intentionally RAW HTML (button markup the page already
 * builds). Everything else is escaped here.
 */

$cmsActive  = isset($cmsActive) ? (string)$cmsActive : '';
$cmsTitle   = isset($cmsTitle) && $cmsTitle !== '' ? (string)$cmsTitle : 'OGRE';
$cmsSub     = isset($cmsSub) ? (string)$cmsSub : '';
$cmsCrumbs  = isset($cmsCrumbs) && is_array($cmsCrumbs) ? $cmsCrumbs : array();
$cmsActions = isset($cmsActions) ? (string)$cmsActions : '';
// Optional raw HTML rendered in the rail beneath the nav (e.g. the editor's
// Page-settings panel). Like $cmsActions, this is intentionally RAW HTML.
$cmsRailExtra = isset($cmsRailExtra) ? (string)$cmsRailExtra : '';
$shCaps     = isset($Caps) && is_array($Caps) ? $Caps : array();

// --- Scope context (CMS Multi-Site Phase 3) --------------------------------
// $CmsScopeQuery : '&scope=k:5' (or '' for the global front door) appended to
//                  every intra-admin link so the active scope rides along.
// $CmsScopeSel   : 'k:5' bare selector echoed to JS as window.CMS_SCOPE so AJAX
//                  fetches re-send it for server-side re-validation.
// $CmsScopeLabel : org display name for the "Editing: {Org}" banner ('' global).
$shScopeQuery = isset($CmsScopeQuery) ? (string)$CmsScopeQuery : '';
$shScopeSel   = isset($CmsScopeSel) ? (string)$CmsScopeSel : '';
$shScopeLabel = isset($CmsScopeLabel) ? (string)$CmsScopeLabel : '';

$shH = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// Rail items: [key, label, href, icon, show?]. `show` defaults to true. Each
// href carries the active scope so navigating the rail stays in-scope.
$shRail = array(
    array('dashboard', 'Dashboard',  UIR . 'Cms/dashboard' . $shScopeQuery, 'fa-tachometer-alt', true),
    array('pages',     'Pages',      UIR . 'Cms/index' . $shScopeQuery,     'fa-file-alt',   true),
    array('posts',     'Posts',      UIR . 'Cms/posts' . $shScopeQuery,     'fa-newspaper',  true),
    array('media',     'Media',      UIR . 'Cms/media' . $shScopeQuery,     'fa-images',     !empty($shCaps['media'])),
    array('nav',       'Navigation', UIR . 'Cms/nav' . $shScopeQuery,       'fa-bars',       !empty($shCaps['nav'])),
    array('theme',     'Theme',      UIR . 'Cms/theme' . $shScopeQuery,     'fa-palette',    !empty($shCaps['theme'])),
    // GLOBAL cross-org overview — super-admins only (scopeless href on purpose;
    // it lists every scope at once). Hidden for org-scoped officers.
    array('sites',     'All sites',  UIR . 'Cms/sites',                     'fa-sitemap',    !empty($shCaps['super'])),
);
?>
<?php
// E129: the caller's resolved capability set, exposed to admin JS so surfaces can
// annotate/disable actions the user lacks. Progressive — empty array is safe.
$shCmsCaps = isset($CmsCaps) && is_array($CmsCaps) ? array_values($CmsCaps) : array();
?>
<script>
window.CMS_CSRF = <?= json_encode(isset($CmsCsrf) ? (string)$CmsCsrf : '', JSON_HEX_TAG) ?>;
window.CMS_SCOPE = <?= json_encode($shScopeSel, JSON_HEX_TAG) ?>;
window.CMS_UIR = <?= json_encode(UIR, JSON_HEX_TAG) ?>;
window.CMS_CAPS = <?= json_encode($shCmsCaps, JSON_HEX_TAG) ?>;
</script>
<?php // Shared OGRE admin stylesheet — one source of truth for every admin
      // surface. Loaded HERE rather than in each of the nine page templates,
      // which previously hand-rolled this link and its cache-buster. ?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/cms/css/cms-admin.css?v=<?= filemtime(__DIR__ . '/css/cms-admin.css') ?>">
<?php // Shared CMS admin helpers (CmsAdmin.toast/.modal/.post) — one source of
      // truth for the toast, modal controller, and CSRF-aware urlencoded POST
      // that every CMS surface uses. Loaded ONCE here so every page gets it. ?>
<script src="<?= HTTP_TEMPLATE ?>default/script/cms-admin.js?v=<?= filemtime(__DIR__ . '/../script/cms-admin.js') ?>"></script>
<?php
// E129: annotate/disable admin actions the user lacks the capability for. Reads
// window.CMS_CAPS (emitted above); no-op when the set is empty so nothing breaks.
?>
<script>
(function () {
    'use strict';
    var caps = Array.isArray(window.CMS_CAPS) ? window.CMS_CAPS : [];
    if (!caps.length) { return; }
    function has(cap) { return caps.indexOf(cap) !== -1; }
    function annotate() {
        document.querySelectorAll('[data-cms-cap]').forEach(function (el) {
            var need = el.getAttribute('data-cms-cap');
            if (!need || has(need)) { return; }
            el.classList.add('cms-cap-denied');
            el.setAttribute('aria-disabled', 'true');
            if (!el.getAttribute('data-tip')) {
                el.setAttribute('data-tip', 'You do not have the “' + need + '” permission for this.');
            }
            if (el.tagName === 'BUTTON' || el.tagName === 'INPUT') { el.disabled = true; }
            el.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); }, true);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', annotate);
    } else {
        annotate();
    }
})();
</script>
<div class="cms-shell">

    <aside class="cms-rail<?= $cmsRailExtra !== '' ? ' cms-rail-wide' : '' ?>" aria-label="Content management navigation">
        <div class="cms-rail-brand" data-tip="OGRE — Online Gallery and Resource Engine">
            <i class="fas fa-folder-open cms-rail-mark" aria-hidden="true"></i>
            <span class="cms-rail-word">OGRE</span>
            <?php // Expansion for screen readers; the wordmark collapses to the
                  // mark alone on narrow rails, so the name lives here too. ?>
            <span class="cms-sr-only">Online Gallery and Resource Engine</span>
        </div>

        <nav class="cms-rail-nav">
            <?php foreach ($shRail as $item):
                if (empty($item[4])) {
                    continue;
                }
                $isActive = ($cmsActive === $item[0]);
            ?>
                <a class="cms-rail-item<?= $isActive ? ' active' : '' ?>"
                   href="<?= $shH($item[2]) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                    <i class="fas <?= $shH($item[3]) ?> cms-rail-icon" aria-hidden="true"></i>
                    <span class="cms-rail-label"><?= $shH($item[1]) ?></span>
                </a>
            <?php endforeach; ?>

            <div class="cms-rail-divider" role="separator"></div>

            <a class="cms-rail-item cms-rail-item-quiet" href="<?= $shH(isset($SiteLiveUrl) ? $SiteLiveUrl : UIR) ?>" target="_blank" rel="noopener">
                <i class="fas fa-external-link-alt cms-rail-icon" aria-hidden="true"></i>
                <span class="cms-rail-label">View live site</span>
            </a>
        </nav>

        <?php if ($cmsRailExtra !== ''): ?>
            <div class="cms-rail-extra"><?= $cmsRailExtra ?></div>
        <?php endif; ?>
    </aside>

    <main class="cms-main">

        <div class="cms-shell-masthead">
            <div class="cms-shell-masthead-text">
                <?php if (!empty($cmsCrumbs)): ?>
                    <nav class="cms-crumbs" aria-label="Breadcrumb">
                        <?php
                        $shLast = count($cmsCrumbs) - 1;
                        foreach ($cmsCrumbs as $shI => $shCrumb):
                            $shLabel = isset($shCrumb['label']) ? (string)$shCrumb['label'] : '';
                            $shHref  = isset($shCrumb['href']) ? (string)$shCrumb['href'] : '';
                            if ($shHref !== '' && $shI !== $shLast):
                            ?>
                                <a class="cms-crumb" href="<?= $shH($shHref) ?>"><?= $shH($shLabel) ?></a>
                                <span class="cms-crumb-sep" aria-hidden="true">/</span>
                            <?php else: ?>
                                <span class="cms-crumb cms-crumb-current"><?= $shH($shLabel) ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>

                <h1 class="cms-shell-title cms-title"><?= $shH($cmsTitle) ?></h1>
                <?php if ($cmsSub !== ''): ?>
                    <div class="cms-shell-sub"><?= $shH($cmsSub) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($cmsActions !== ''): ?>
                <div class="cms-shell-actions"><?= $cmsActions ?></div>
            <?php endif; ?>
        </div>

        <?php if ($shScopeLabel !== ''): ?>
            <?php // .cms-scope-banner styling lives in the shared cms-admin.css
                  // (the surrounding CMS admin surfaces already load it) — no
                  // per-render inline <style> duplicated on every scoped page. ?>
            <div class="cms-scope-banner" role="status">
                <i class="fas fa-globe-americas cms-scope-ico" aria-hidden="true"></i>
                <span>
                    Editing: <strong><?= $shH($shScopeLabel) ?></strong> — public site.
                    <span class="cms-scope-hint">This is separate from the global ORK front door.</span>
                </span>
            </div>
        <?php endif; ?>

        <div class="cms-shell-body">
