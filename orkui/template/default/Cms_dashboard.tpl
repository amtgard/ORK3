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

$pageTypes = isset($PageTypes) && is_array($PageTypes) ? $PageTypes : array(
    array('type' => 'composed',   'label' => 'Composed / Landing'),
    array('type' => 'article',    'label' => 'Article / Text'),
    array('type' => 'media',      'label' => 'Media / Gallery'),
    array('type' => 'resource',   'label' => 'Resource / Document'),
    array('type' => 'blog_index', 'label' => 'Blog Index'),
    array('type' => 'dynamic',    'label' => 'Dynamic Data'),
);

$canCreate = !empty($caps['create']);

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

<style>
/* ---- Dashboard-only styling (reuses cms- tokens; dark-mode via vars) ---- */
.cms-dash-greet {
    font-family: inherit;
    font-size: 22px;
    color: var(--cms-gold, #f0b429);
    margin: 0 0 4px;
    background: transparent; border: none; padding: 0; border-radius: 0;
    text-shadow: none;
}
.cms-dash-lede { color: var(--ork-text-muted); font-size: 14px; margin: 0 0 22px; }

.cms-dash-section-title {
    font-size: 13px; text-transform: uppercase; letter-spacing: .06em;
    color: var(--ork-text-muted); font-weight: 700; margin: 0 0 12px;
    background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.cms-dash-block { margin-bottom: 30px; }

/* Quick-create cards */
.cms-quick-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;
}
.cms-quick-card {
    display: flex; align-items: center; gap: 14px;
    border: 1px solid var(--ork-border-dark); border-radius: 11px;
    background: var(--ork-bg-secondary); padding: 16px 18px;
    text-decoration: none; color: var(--ork-text);
    transition: border-color .12s, transform .08s, box-shadow .12s; cursor: pointer;
}
.cms-quick-card:hover {
    border-color: var(--cms-gold, #f0b429); transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(0, 0, 0, .08);
}
.cms-quick-ico {
    flex: 0 0 auto; width: 42px; height: 42px; border-radius: 10px;
    display: grid; place-items: center; font-size: 18px;
    background: linear-gradient(180deg, var(--cms-gold, #f0b429), #e0a420); color: #1a1205;
}
.cms-quick-text strong { display: block; font-size: 15px; }
.cms-quick-text span { font-size: 12.5px; color: var(--ork-text-muted); }

/* Stat tiles */
.cms-stat-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px;
}
.cms-stat-tile {
    border: 1px solid var(--ork-border); border-radius: 11px;
    background: var(--ork-bg-secondary); padding: 16px 18px;
    text-decoration: none; color: var(--ork-text); display: block;
    transition: border-color .12s;
}
a.cms-stat-tile:hover { border-color: var(--cms-gold-deep, #caa23a); }
.cms-stat-num { font-size: 28px; font-weight: 800; line-height: 1; color: var(--ork-text); }
.cms-stat-lbl { font-size: 12.5px; color: var(--ork-text-muted); margin-top: 6px; }
.cms-stat-tile-drafts .cms-stat-num { color: var(--cms-gold-deep, #caa23a); }

/* Continue-editing list */
.cms-recent-list { display: flex; flex-direction: column; border: 1px solid var(--ork-border); border-radius: 11px; overflow: hidden; }
.cms-recent-item {
    display: flex; align-items: center; gap: 12px; padding: 12px 14px;
    border-bottom: 1px solid var(--ork-border); background: var(--ork-bg);
}
.cms-recent-item:last-child { border-bottom: none; }
.cms-recent-item:hover { background: var(--ork-bg-secondary); }
.cms-recent-kind {
    flex: 0 0 auto; width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center;
    background: var(--ork-bg-tertiary); color: var(--ork-text-muted); font-size: 13px;
}
.cms-recent-main { flex: 1 1 auto; min-width: 0; }
.cms-recent-title { font-weight: 600; color: var(--ork-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cms-recent-meta { font-size: 12px; color: var(--ork-text-muted); }
.cms-recent-actions { flex: 0 0 auto; }

/* Public-site status card (Multi-Site) */
.cms-sitecard {
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    border: 1px solid var(--ork-border-dark); border-left: 4px solid var(--cms-gold, #f0b429);
    border-radius: 11px; background: var(--ork-bg-secondary); padding: 16px 18px;
}
.cms-sitecard-main { flex: 1 1 260px; min-width: 0; }
.cms-sitecard-title { font-size: 15.5px; font-weight: 700; color: var(--ork-text); display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
.cms-sitecard-title .fa-globe-americas { color: var(--cms-gold-deep, #caa23a); }
.cms-sitecard-sub { font-size: 13px; color: var(--ork-text-muted); margin-top: 5px; }
.cms-sitecard-sub code { background: var(--ork-bg-tertiary); padding: 1px 6px; border-radius: 5px; font-size: 12.5px; }
.cms-sitecard-badge { font-size: 11.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; text-transform: uppercase; letter-spacing: .04em; }
.cms-sitecard-badge-pub { background: #1f7a3d; color: #fff; }
.cms-sitecard-badge-draft { background: var(--ork-bg-tertiary); color: var(--ork-text-muted); border: 1px solid var(--ork-border); }
.cms-sitecard-actions { flex: 0 0 auto; display: flex; align-items: center; gap: 8px; }
.cms-sitecard-note { font-size: 13px; color: var(--ork-text-muted); display: inline-flex; align-items: center; gap: 7px; }
.cms-sitecard-note .fa-lock { color: var(--cms-gold-deep, #caa23a); }
html[data-theme="dark"] .cms-sitecard-badge-pub { background: #2e9d55; }

.cms-dash-livelink { display: inline-flex; align-items: center; gap: 7px; color: var(--ork-text-muted); text-decoration: none; font-size: 13.5px; }
.cms-dash-livelink:hover { color: var(--cms-gold-deep, #caa23a); text-decoration: underline; }
html[data-theme="dark"] .cms-dash-livelink:hover { color: var(--cms-gold, #f0b429); }

@media (max-width: 560px) {
    .cms-recent-actions .cms-btn-label { display: none; }
}
</style>

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
        </div>
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

    <div class="cms-dash-block">
        <a class="cms-dash-livelink" href="<?= UIR ?>" target="_blank" rel="noopener">
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
                    <a class="cms-typecard" href="<?= UIR ?>Cms/edit/new&type=<?= $h($pt['type']) ?><?= $scopeQ ?>">
                        <strong><?= $h($pt['label']) ?></strong>
                        <span><?= $h($pt['type']) ?></span>
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
    function openModal(el) { if (el) { el.classList.add('cms-open'); } }
    function closeModal(el) { if (el) { el.classList.remove('cms-open'); } }
    if (newPageCard && newModal) {
        newPageCard.addEventListener('click', function (e) {
            e.preventDefault();
            openModal(newModal);
        });
    }
    document.addEventListener('click', function (e) {
        var closer = e.target.closest('[data-close-modal]');
        if (closer) { closeModal(closer.closest('.cms-modal-overlay')); return; }
        if (e.target.classList && e.target.classList.contains('cms-modal-overlay')) {
            closeModal(e.target);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.cms-modal-overlay.cms-open').forEach(closeModal);
        }
    });
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
})();
</script>
