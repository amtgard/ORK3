<?php
/**
 * Cms_dashboard.tpl — CMS landing / overview.
 * PLAIN PHP (extract()+include), NEVER Smarty. Use <?php ?>/<?= ?> only.
 *
 * Receives (from Controller_Cms::dashboard):
 *   $Recent     list of ['kind'=>'page'|'post','id','title','status','updated_at','edit_href']
 *   $Stats      ['pages','posts','page_drafts','post_drafts','drafts' => int]
 *   $PageTypes  list of ['type','label'] for the New-Page chooser
 *   $Caps       ['create','edit','publish','delete','media','nav','roles' => bool]
 *   UIR, HTTP_TEMPLATE (constants)
 */

$recent = isset($Recent) && is_array($Recent) ? $Recent : array();
$stats  = isset($Stats) && is_array($Stats) ? $Stats : array();
$caps   = isset($Caps) && is_array($Caps) ? $Caps : array();

// #09 usage analytics (view counts).
$viewSummary = isset($ViewSummary) && is_array($ViewSummary) ? $ViewSummary : array();
$topViewed   = isset($TopViewed) && is_array($TopViewed) ? $TopViewed : array();
$viewTotal   = (int)($viewSummary['total'] ?? 0);
$viewRecent  = (int)($viewSummary['recent'] ?? 0);
$viewDays    = (int)($viewSummary['recent_days'] ?? 30);
// Human-readable thousands separators for the tallies.
$nf = function ($n) {
    return number_format((int)$n);
};

$pageTypes = isset($PageTypes) && is_array($PageTypes) ? $PageTypes : array(
    array('type' => 'composed',   'label' => 'Composed / Landing'),
    array('type' => 'article',    'label' => 'Article / Text'),
    array('type' => 'media',      'label' => 'Media / Gallery'),
    array('type' => 'resource',   'label' => 'Resource / Document'),
    array('type' => 'blog_index', 'label' => 'Blog Index'),
    array('type' => 'dynamic',    'label' => 'Dynamic Data'),
);

$canCreate = !empty($caps['create']);
$canManage = !empty($caps['edit']) || !empty($caps['publish']) || !empty($caps['create']);
$isSuper   = !empty($caps['super']);

// E128: "Top content (30 days)" panel — [{title,url,count}]. Defensive: a missing
// or empty map renders nothing.
$topContent = isset($topContent) && is_array($topContent) ? $topContent : array();

$statPages  = (int)($stats['pages'] ?? 0);
$statPosts  = (int)($stats['posts'] ?? 0);
$statDrafts = (int)($stats['drafts'] ?? 0);

// Calm time-of-day greeting.
$hr = (int)date('G');
if ($hr < 5) {
    $greet = 'Good evening';
} elseif ($hr < 12) {
    $greet = 'Good morning';
} elseif ($hr < 17) {
    $greet = 'Good afternoon';
} else {
    $greet = 'Good evening';
}

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// --- Scope context (CMS Multi-Site Phase 3) ---
$scopeQ        = isset($CmsScopeQuery) ? (string)$CmsScopeQuery : '';
$dashScope     = isset($CmsScope) && is_array($CmsScope) ? $CmsScope : array('type' => 'global', 'id' => 0);
$dashIsOrgSite = ($dashScope['type'] ?? 'global') !== 'global';
$dashSite      = isset($CmsSite) && is_array($CmsSite) ? $CmsSite : array();
$dashSiteStatus = (string)($dashSite['status'] ?? 'unbuilt');
$dashSiteSlug   = (string)($dashSite['slug'] ?? '');
$dashCanPublish = !empty($CanPublishSite);
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/cms-admin.css?v=<?= filemtime(__DIR__ . '/style/cms-admin.css') ?>">

<?php // Dashboard-specific styling (.cms-dash-*/.cms-sitecard-*) lives in the
      // shared, cacheable cms-admin.css (loaded above) — no per-render inline block. ?>
<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'dashboard';
$cmsTitle   = 'Dashboard';
$cmsSub     = 'Overview of your site content';
$cmsActions = '';
include __DIR__ . '/cms/_shell_top.tpl';
?>

    <div class="cms-dash-block">
        <h2 class="cms-dash-greet"><?= $h($greet) ?>.</h2>
        <p class="cms-dash-lede">Pick up where you left off, or create something new below.</p>
    </div>

    <?php if ($dashIsOrgSite): ?>
    <?php
        $siteIsPublished = ($dashSiteStatus === 'published');
        $siteBadgeClass  = $siteIsPublished ? 'cms-sitecard-badge-pub' : 'cms-sitecard-badge-draft';
        $siteBadgeText   = $siteIsPublished ? 'Published' : ($dashSiteStatus === 'draft' ? 'Draft' : 'Not yet published');
    ?>
    <div class="cms-dash-block">
        <div class="cms-sitecard" id="cmsSiteCard"
             data-status="<?= $h($dashSiteStatus) ?>"
             data-can-publish="<?= $dashCanPublish ? '1' : '0' ?>">
            <div class="cms-sitecard-main">
                <div class="cms-sitecard-title">
                    <i class="fas fa-globe-americas"></i> Public site
                    <span class="cms-sitecard-badge <?= $siteBadgeClass ?>" id="cmsSiteBadge"><?= $h($siteBadgeText) ?></span>
                </div>
                <div class="cms-sitecard-sub" id="cmsSiteSub">
                    <?php if ($siteIsPublished): ?>
                        Your public site is live<?php if ($dashSiteSlug !== ''): ?> at <code>/k/<?= $h($dashSiteSlug) ?></code><?php endif; ?>.
                    <?php else: ?>
                        Your public site is not visible to the public yet.
                    <?php endif; ?>
                </div>
            </div>
            <div class="cms-sitecard-actions">
                <?php if ($dashCanPublish): ?>
                    <button type="button" class="cms-btn cms-btn-primary" id="cmsSitePublishBtn"<?= $siteIsPublished ? ' style="display:none;"' : '' ?>>
                        <i class="fas fa-globe"></i> Publish site
                    </button>
                    <button type="button" class="cms-btn cms-btn-ghost" id="cmsSiteUnpublishBtn"<?= $siteIsPublished ? '' : ' style="display:none;"' ?>>
                        <i class="fas fa-eye-slash"></i> Unpublish
                    </button>
                <?php else: ?>
                    <span class="cms-sitecard-note" data-tip="Only a monarch or regent (kingdom administrator) can publish the public site.">
                        <i class="fas fa-lock"></i> A monarch or regent must publish this site.
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canCreate): ?>
    <div class="cms-dash-block">
        <h3 class="cms-dash-section-title">Quick create</h3>
        <div class="cms-quick-row">
            <a class="cms-quick-card" id="cmsDashNewPage" href="<?= UIR ?>Cms/edit/new<?= $scopeQ ?>" role="button">
                <span class="cms-quick-ico"><i class="fas fa-file-alt"></i></span>
                <span class="cms-quick-text">
                    <strong>New Page</strong>
                    <span>Create a landing or content page</span>
                </span>
            </a>
            <a class="cms-quick-card" href="<?= UIR ?>Cms/editpost/new<?= $scopeQ ?>" role="button">
                <span class="cms-quick-ico"><i class="fas fa-plus"></i></span>
                <span class="cms-quick-text">
                    <strong>New Post</strong>
                    <span>Write a blog post or announcement</span>
                </span>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="cms-dash-block">
        <h3 class="cms-dash-section-title">At a glance</h3>
        <div class="cms-stat-row">
            <a class="cms-stat-tile" href="<?= UIR ?>Cms/index<?= $scopeQ ?>">
                <div class="cms-stat-num"><?= $statPages ?></div>
                <div class="cms-stat-lbl"><i class="fas fa-file-alt"></i> Page<?= $statPages === 1 ? '' : 's' ?></div>
            </a>
            <a class="cms-stat-tile" href="<?= UIR ?>Cms/posts<?= $scopeQ ?>">
                <div class="cms-stat-num"><?= $statPosts ?></div>
                <div class="cms-stat-lbl"><i class="fas fa-newspaper"></i> Post<?= $statPosts === 1 ? '' : 's' ?></div>
            </a>
            <a class="cms-stat-tile cms-stat-tile-drafts" href="<?= UIR ?>Cms/index&status=draft<?= $scopeQ ?>">
                <div class="cms-stat-num"><?= $statDrafts ?></div>
                <div class="cms-stat-lbl"><i class="fas fa-pencil-ruler"></i> Draft<?= $statDrafts === 1 ? '' : 's' ?> in progress</div>
            </a>
            <?php // #09: scope-wide view rollup. Not a link (no analytics drill-down yet). ?>
            <div class="cms-stat-tile" data-tip="<?= $h($nf($viewTotal)) ?> total views all-time on published pages &amp; posts">
                <div class="cms-stat-num"><?= $h($nf($viewRecent)) ?></div>
                <div class="cms-stat-lbl"><i class="fas fa-chart-line"></i> View<?= $viewRecent === 1 ? '' : 's' ?> (last <?= (int)$viewDays ?> days)</div>
            </div>
        </div>
    </div>

    <?php // E128: Top content over the last 30 days — [{title,url,count}]. Rendered
          // only when the controller supplied rows (defensive; empty → nothing). ?>
    <?php if (!empty($topContent)): ?>
    <div class="cms-dash-block">
        <h3 class="cms-dash-section-title">Top content (30 days)</h3>
        <div class="cms-recent-list">
            <?php foreach ($topContent as $tc):
                $tcTitle = (string)($tc['title'] ?? '(untitled)');
                $tcUrl   = (string)($tc['url'] ?? '');
                $tcCount = (int)($tc['count'] ?? 0);
            ?>
                <div class="cms-recent-item">
                    <span class="cms-recent-kind" data-tip="Views in the last 30 days">
                        <i class="fas fa-chart-line"></i>
                    </span>
                    <div class="cms-recent-main">
                        <div class="cms-recent-title">
                            <?php if ($tcUrl !== ''): ?>
                                <a href="<?= $h($tcUrl) ?>" target="_blank" rel="noopener"><?= $h($tcTitle) ?></a>
                            <?php else: ?>
                                <?= $h($tcTitle) ?>
                            <?php endif; ?>
                        </div>
                        <div class="cms-recent-meta">
                            <strong><?= $h($nf($tcCount)) ?></strong> view<?= $tcCount === 1 ? '' : 's' ?> in the last 30 days
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // #09: most-viewed content — closes the "does anyone see this?" loop. ?>
    <div class="cms-dash-block">
        <h3 class="cms-dash-section-title">Most viewed</h3>
        <?php if (empty($topViewed)): ?>
            <div class="cms-empty">
                <div class="cms-empty-icon"><i class="fas fa-chart-line"></i></div>
                <div class="cms-empty-copy">No views recorded yet — once your published pages and posts are visited, your most-read content will appear here.</div>
            </div>
        <?php else: ?>
            <div class="cms-recent-list">
                <?php foreach ($topViewed as $tv):
                    $isPage = (($tv['kind'] ?? 'page') === 'page');
                    $title  = (string)($tv['title'] ?? '(untitled)');
                    $href   = (string)($tv['edit_href'] ?? '#');
                    $total  = (int)($tv['total'] ?? 0);
                    $recent = (int)($tv['recent'] ?? 0);
                ?>
                    <div class="cms-recent-item">
                        <span class="cms-recent-kind" data-tip="<?= $isPage ? 'Page' : 'Post' ?>">
                            <i class="fas <?= $isPage ? 'fa-file-alt' : 'fa-newspaper' ?>"></i>
                        </span>
                        <div class="cms-recent-main">
                            <div class="cms-recent-title"><?= $h($title) ?></div>
                            <div class="cms-recent-meta">
                                <strong><?= $h($nf($total)) ?></strong> total view<?= $total === 1 ? '' : 's' ?>
                                &nbsp;·&nbsp; <?= $h($nf($recent)) ?> in the last <?= (int)$viewDays ?> days
                            </div>
                        </div>
                        <div class="cms-recent-actions">
                            <a class="cms-btn cms-btn-sm" href="<?= $h($href) ?>"><i class="fas fa-pen"></i> <span class="cms-btn-label">Edit</span></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="cms-dash-block">
        <h3 class="cms-dash-section-title">Continue editing</h3>
        <?php if (empty($recent)): ?>
            <div class="cms-empty">
                <div class="cms-empty-icon"><i class="fas fa-file-alt"></i></div>
                <div class="cms-empty-copy">Nothing here yet — your recent edits will appear here.</div>
                <?php if ($canCreate): ?>
                    <a class="cms-btn cms-btn-primary cms-empty-cta" href="<?= UIR ?>Cms/edit/new<?= $scopeQ ?>"><i class="fas fa-plus"></i> New Page</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="cms-recent-list">
                <?php foreach ($recent as $r):
                    $isPage  = (($r['kind'] ?? 'page') === 'page');
                    $title   = (string)($r['title'] ?? '(untitled)');
                    $status  = (string)($r['status'] ?? 'draft');
                    $isPub   = ($status === 'published');
                    $href    = (string)($r['edit_href'] ?? '#');
                    $updated = (string)($r['updated_at'] ?? '');
                    $when    = $updated !== '' ? date('M j, Y g:i A', strtotime($updated)) : '—';
                ?>
                    <div class="cms-recent-item">
                        <span class="cms-recent-kind" data-tip="<?= $isPage ? 'Page' : 'Post' ?>">
                            <i class="fas <?= $isPage ? 'fa-file-alt' : 'fa-newspaper' ?>"></i>
                        </span>
                        <div class="cms-recent-main">
                            <div class="cms-recent-title"><?= $h($title) ?></div>
                            <div class="cms-recent-meta">
                                <span class="cms-badge cms-badge-<?= $isPub ? 'published' : 'draft' ?>"><?= $isPub ? 'Published' : 'Draft' ?></span>
                                &nbsp;Updated <?= $h($when) ?>
                            </div>
                        </div>
                        <div class="cms-recent-actions">
                            <a class="cms-btn cms-btn-sm" href="<?= $h($href) ?>"><i class="fas fa-pen"></i> <span class="cms-btn-label">Edit</span></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php // E71 / E117: site maintenance tools. Refresh-cache is for anyone who can
          // manage content (scope-checked server-side); the destructive-adjacent
          // cleanup sweep is super-admin-only. Both POST via CmsAdmin.post. ?>
    <?php if ($canManage || $isSuper): ?>
    <div class="cms-dash-block">
        <h3 class="cms-dash-section-title">Site tools</h3>
        <div class="cms-quick-row">
            <?php if ($canManage): ?>
            <button type="button" class="cms-btn cms-btn-ghost" id="cmsRefreshCacheBtn"
                    data-tip="Rebuilds the cached officer / parks / map panels on your public front door.">
                <i class="fas fa-sync-alt"></i> Refresh public site cache
            </button>
            <?php endif; ?>
            <?php if ($isSuper): ?>
            <button type="button" class="cms-btn cms-btn-ghost" id="cmsRunMaintenanceBtn"
                    data-tip="Purges trashed pages, orphaned blocks, and unused tags. Never touches live content.">
                <i class="fas fa-broom"></i> Run maintenance cleanup
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="cms-dash-block">
        <a class="cms-dash-livelink" href="<?= htmlspecialchars(isset($SiteLiveUrl) ? $SiteLiveUrl : UIR) ?>" target="_blank" rel="noopener">
            <i class="fas fa-external-link-alt"></i> View live site
        </a>
    </div>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

<?php /* ---- New-Page type chooser modal (mirrors the Pages list) ---- */ ?>
<?php if ($canCreate): ?>
<div class="cms-modal-overlay" id="cmsNewModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-label="Choose a page type">
        <div class="cms-modal-head">
            <h3>Create a page</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <p class="cms-muted" style="margin-top:0;font-size:13px;">Pick a starting layout. You can add or remove any block afterward.</p>
            <div class="cms-typegrid">
                <?php foreach ($pageTypes as $pt): ?>
                    <?php // Plain-language description only — never the raw type slug (dev jargon). ?>
                    <a class="cms-typecard" href="<?= UIR ?>Cms/edit/new&type=<?= $h($pt['type']) ?><?= $scopeQ ?>">
                        <strong><?= $h($pt['label']) ?></strong>
                        <?php if (!empty($pt['description'])): ?>
                            <span><?= $h($pt['description']) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    <?php if ($canCreate): ?>
    /* The New Page quick-card opens the type chooser (falls through to its href
       if JS is unavailable). */
    var newModal = document.getElementById('cmsNewModal');
    var newPageCard = document.getElementById('cmsDashNewPage');
    /* modal open/close are shared (CmsAdmin.modal); backdrop/Esc handled there. */
    if (newPageCard && newModal) {
        newPageCard.addEventListener('click', function (e) {
            e.preventDefault();
            CmsAdmin.modal.open(newModal);
        });
    }
    <?php endif; ?>

    /* ---- Public-site publish / unpublish (org scope only) ---- */
    var siteCard = document.getElementById('cmsSiteCard');
    if (siteCard) {
        var pubBtn   = document.getElementById('cmsSitePublishBtn');
        var unpubBtn = document.getElementById('cmsSiteUnpublishBtn');
        var badge    = document.getElementById('cmsSiteBadge');
        var subEl    = document.getElementById('cmsSiteSub');

        function siteAction(endpoint, btn) {
            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Working…';
            var url = UIR + 'CmsAjax/' + endpoint
                + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '');
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': (window.CMS_CSRF || '') },
                body: ''
            }).then(function (r) { return r.json(); }).then(function (d) {
                btn.disabled = false;
                btn.innerHTML = original;
                if (!d || d.ok !== true) {
                    if (subEl) { subEl.textContent = (d && d.error) ? d.error : 'That action could not be completed.'; }
                    return;
                }
                var published = (d.status === 'published');
                if (badge) {
                    badge.textContent = published ? 'Published' : 'Draft';
                    badge.className = 'cms-sitecard-badge ' + (published ? 'cms-sitecard-badge-pub' : 'cms-sitecard-badge-draft');
                }
                if (subEl) {
                    subEl.textContent = published
                        ? 'Your public site is live.'
                        : 'Your public site is not visible to the public yet.';
                }
                if (pubBtn) { pubBtn.style.display = published ? 'none' : ''; }
                if (unpubBtn) { unpubBtn.style.display = published ? '' : 'none'; }
            }).catch(function () {
                btn.disabled = false;
                btn.innerHTML = original;
                if (subEl) { subEl.textContent = 'Network error — please try again.'; }
            });
        }

        if (pubBtn) { pubBtn.addEventListener('click', function () { siteAction('publishsite', pubBtn); }); }
        if (unpubBtn) { unpubBtn.addEventListener('click', function () { siteAction('unpublishsite', unpubBtn); }); }
    }

    /* ---- E71: refresh the public-site block caches for the acting scope ---- */
    var refreshBtn = document.getElementById('cmsRefreshCacheBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            var original = refreshBtn.innerHTML;
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing…';
            CmsAdmin.post('clearrendercache', {}).then(function (res) {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = original;
                if (!res || !res.ok) { CmsAdmin.toast((res && res.error) || 'Could not refresh the cache.', 'error'); return; }
                CmsAdmin.toast(res.message || 'Public site cache refreshed.', 'ok');
            }).catch(function () {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = original;
                CmsAdmin.toast('Network error refreshing the cache.', 'error');
            });
        });
    }

    /* ---- E117: super-admin maintenance sweep (trash purge + orphan/tag cleanup) ---- */
    var maintBtn = document.getElementById('cmsRunMaintenanceBtn');
    if (maintBtn) {
        maintBtn.addEventListener('click', function () {
            var original = maintBtn.innerHTML;
            maintBtn.disabled = true;
            maintBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cleaning up…';
            CmsAdmin.post('runmaintenance', {}).then(function (res) {
                maintBtn.disabled = false;
                maintBtn.innerHTML = original;
                if (!res || !res.ok) { CmsAdmin.toast((res && res.error) || 'Maintenance could not run.', 'error'); return; }
                CmsAdmin.toast(res.message || 'Maintenance cleanup complete.', 'ok');
            }).catch(function () {
                maintBtn.disabled = false;
                maintBtn.innerHTML = original;
                CmsAdmin.toast('Network error running maintenance.', 'error');
            });
        });
    }
})();
</script>
