<?php
/**
 * cms/_shell_top.tpl — Royal Scriptorium CMS shell (open).
 * PLAIN PHP (extract()+include), NEVER Smarty. Use <?php ?>/<?= ?> only.
 *
 * Renders the persistent left rail + masthead workspace chrome that wraps every
 * CMS admin surface. Include this BEFORE your page content and include
 * cms/_shell_bottom.tpl AFTER it. The ORK global app header/footer stay above;
 * this shell owns the workspace beneath them.
 *
 * Page-set variables (all optional unless noted):
 *   $cmsActive  string  which rail item is highlighted:
 *                       'pages'|'posts'|'nav'  (any other value, e.g. 'edit',
 *                       highlights nothing — leaf surfaces).
 *   $cmsTitle   string  masthead display title (MedievalSharp). Default 'The Scriptorium'.
 *   $cmsSub     string  optional subtitle under the title.
 *   $cmsCrumbs  array   optional breadcrumb: list of ['label'=>..,'href'=>?..].
 *                       The last crumb (or any without href) renders as plain text.
 *   $cmsActions string  optional raw HTML, right-aligned in the masthead
 *                       (e.g. the page's primary "New Page" button).
 *   $Caps       array   capability flags; rail hides nav/media items without them.
 *   UIR                 (constant) controller route base.
 *
 * NOTE: $cmsActions is intentionally RAW HTML (button markup the page already
 * builds). Everything else is escaped here.
 */

$cmsActive  = isset($cmsActive) ? (string)$cmsActive : '';
$cmsTitle   = isset($cmsTitle) && $cmsTitle !== '' ? (string)$cmsTitle : 'The Scriptorium';
$cmsSub     = isset($cmsSub) ? (string)$cmsSub : '';
$cmsCrumbs  = isset($cmsCrumbs) && is_array($cmsCrumbs) ? $cmsCrumbs : array();
$cmsActions = isset($cmsActions) ? (string)$cmsActions : '';
$shCaps     = isset($Caps) && is_array($Caps) ? $Caps : array();

$shH = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// Rail items: [key, label, href, icon, show?]. `show` defaults to true.
// Only surfaces with a real controller action are listed.
$shRail = array(
    array('dashboard', 'Dashboard',  UIR . 'Cms/dashboard', 'fa-gauge-high', true),
    array('pages',     'Pages',      UIR . 'Cms/index',     'fa-file-alt',   true),
    array('posts',     'Posts',      UIR . 'Cms/posts',     'fa-newspaper',  true),
    array('media',     'Media',      UIR . 'Cms/media',     'fa-images',     !empty($shCaps['media'])),
    array('nav',       'Navigation', UIR . 'Cms/nav',       'fa-bars',       !empty($shCaps['nav'])),
);
?>
<div class="cms-shell">

    <aside class="cms-rail" aria-label="Scriptorium navigation">
        <div class="cms-rail-brand">
            <i class="fas fa-feather-alt cms-rail-mark" aria-hidden="true"></i>
            <span class="cms-rail-word">Scriptorium</span>
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

            <a class="cms-rail-item cms-rail-item-quiet" href="<?= $shH(UIR) ?>" target="_blank" rel="noopener">
                <i class="fas fa-arrow-up-right-from-square cms-rail-icon" aria-hidden="true"></i>
                <span class="cms-rail-label">View live site</span>
            </a>
        </nav>
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

        <div class="cms-shell-body">
