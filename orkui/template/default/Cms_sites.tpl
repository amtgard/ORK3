<?php
/**
 * Cms_sites.tpl — GLOBAL "CMS Sites" overview (super-admin only).
 * PLAIN PHP (extract()+include), NEVER Smarty. Use <?php ?>/<?= ?> only.
 *
 * Receives (from Controller_Cms::sites):
 *   $FrontDoor          ['name','subtitle','pages_total','pages_published',
 *                        'posts_total','manage_url','visit_url']  (pinned card)
 *   $KingdomSites       list of site views (kingdom scope), each:
 *                        ['site_id','scope_sel','org_name','slug','status',
 *                         'pages_total','pages_published','posts_total',
 *                         'updated_at','manage_url','visit_url']
 *   $ParkSites          same shape, park scope
 *   $ProvisionKingdoms  list of ['id','name','has_site'] for the New-site picker
 *   $Caps               capability flags (rail)
 *   UIR, HTTP_TEMPLATE  (constants)
 *
 * SECURITY: the controller bounces non-super-admins before this template ever
 * renders, so everything here is for super-admin eyes only. Still, ALL org
 * names / slugs are escaped and slugs were rawurlencode()'d into URLs upstream.
 */

$frontDoor = isset($FrontDoor) && is_array($FrontDoor) ? $FrontDoor : array();
$kSites    = isset($KingdomSites) && is_array($KingdomSites) ? $KingdomSites : array();
$pSites    = isset($ParkSites) && is_array($ParkSites) ? $ParkSites : array();
$provision = isset($ProvisionKingdoms) && is_array($ProvisionKingdoms) ? $ProvisionKingdoms : array();

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// Human status label + badge modifier for a site status.
$statusMeta = function ($status) {
    switch ((string)$status) {
        case 'published':
            return array('Published', 'published');
        case 'draft':
            return array('Draft', 'draft');
        default:
            return array('Unbuilt', 'unbuilt');
    }
};

$fmtDate = function ($raw) {
    $raw = (string)$raw;
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts ? date('M j, Y g:i A', $ts) : '—';
};

$totalSites = count($kSites) + count($pSites);
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/cms-admin.css?v=<?= filemtime(__DIR__ . '/style/cms-admin.css') ?>">

<style>
/* ---- CMS Sites overview (scoped). Dark mode via html[data-theme="dark"]. ---- */
.cms-sites-wrap { display: flex; flex-direction: column; gap: 26px; }

/* Global h1–h6 in orkui.css get a gray pill box — reset the ones we own. */
.cms-sites-wrap h2 {
    background: transparent; border: none; padding: 0; border-radius: 0;
    text-shadow: none; margin: 0; font-size: 16px; font-weight: 700;
    color: var(--ork-text);
}

/* Pinned front-door card */
.cms-fd-card {
    display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
    padding: 18px 20px; border-radius: 14px;
    border: 1px solid var(--cms-gold-deep, #caa23a); border-left-width: 5px;
    background: linear-gradient(180deg, #fffaec, #fff6dd);
}
html[data-theme="dark"] .cms-fd-card {
    background: rgba(240, 180, 41, .08);
    border-color: var(--cms-gold, #f0b429);
}
.cms-fd-mark {
    flex: 0 0 auto; width: 54px; height: 54px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    background: var(--cms-gold, #f0b429); color: #1a1205; font-size: 24px;
}
.cms-fd-text { flex: 1 1 220px; min-width: 0; }
.cms-fd-name { font-size: 18px; font-weight: 700; color: var(--ork-text); }
.cms-fd-sub  { font-size: 12.5px; color: var(--ork-text-muted); margin-top: 2px; }
.cms-fd-stats { display: flex; gap: 22px; flex: 0 0 auto; flex-wrap: wrap; }
.cms-fd-stat { text-align: center; }
.cms-fd-stat-num { font-size: 19px; font-weight: 700; color: var(--ork-text); line-height: 1.1; }
.cms-fd-stat-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--ork-text-muted); }
.cms-fd-actions { display: flex; gap: 8px; flex: 0 0 auto; flex-wrap: wrap; }

/* Section blocks */
.cms-sites-section-head {
    display: flex; align-items: baseline; gap: 10px; margin-bottom: 12px;
}
.cms-sites-count { font-size: 12.5px; color: var(--ork-text-muted); }

/* Table (reuses .cms-table; a couple of overview-specific tweaks) */
.cms-sites-table td, .cms-sites-table th { vertical-align: middle; }
.cms-sites-org { font-weight: 600; color: var(--ork-text); }
.cms-sites-slug {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; color: var(--ork-text-muted); text-decoration: none;
}
a.cms-sites-slug:hover { color: var(--cms-gold-deep, #caa23a); text-decoration: underline; }
html[data-theme="dark"] a.cms-sites-slug:hover { color: var(--cms-gold, #f0b429); }
.cms-sites-metric { white-space: nowrap; font-variant-numeric: tabular-nums; }

/* Status badges: green / amber / gray */
.cms-site-badge {
    display: inline-block; padding: 2px 9px; border-radius: 999px;
    font-size: 11.5px; font-weight: 600; line-height: 1.5; white-space: nowrap;
}
.cms-site-badge-published { background: var(--ork-badge-green-bg); color: var(--ork-badge-green-text); }
.cms-site-badge-draft { background: var(--ork-badge-orange-bg); color: var(--ork-badge-orange-text); }
.cms-site-badge-unbuilt { background: var(--ork-badge-gray-bg); color: var(--ork-badge-gray-text); }

.cms-sites-rowactions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }

/* Provision panel */
.cms-provision {
    padding: 18px 20px; border-radius: 12px;
    border: 1px solid var(--ork-border-dark); background: var(--ork-bg-secondary);
}
.cms-provision-grid {
    display: flex; gap: 18px; flex-wrap: wrap; margin-top: 12px;
}
.cms-provision-col { flex: 1 1 260px; min-width: 220px; }
.cms-provision-label {
    display: block; font-size: 12.5px; font-weight: 600;
    color: var(--ork-text-secondary); margin-bottom: 6px;
}
.cms-provision-row { display: flex; gap: 8px; }
.cms-provision-row .cms-select { flex: 1 1 auto; }
.cms-provision-hint { font-size: 12px; color: var(--ork-text-muted); margin-top: 8px; }
.cms-optnosite { font-weight: 700; }

@media (max-width: 640px) {
    .cms-fd-stats { width: 100%; justify-content: space-between; }
    .cms-fd-actions { width: 100%; }
    .cms-fd-actions .cms-btn { flex: 1 1 auto; justify-content: center; }
}
</style>

<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'sites';
$cmsTitle   = 'CMS Sites';
$cmsSub     = 'Every kingdom & park site across the network';
$cmsActions = '<button type="button" class="cms-btn cms-btn-primary" id="cmsNewSiteBtn"><i class="fas fa-plus"></i> New site</button>';
include __DIR__ . '/cms/_shell_top.tpl';
?>

<div class="cms-sites-wrap">

    <?php /* ---- Pinned: Amtgard International (global front door) ---- */ ?>
    <?php if (!empty($frontDoor)): ?>
    <section class="cms-fd-card" aria-label="Global front door">
        <div class="cms-fd-mark"><i class="fas fa-globe-americas" aria-hidden="true"></i></div>
        <div class="cms-fd-text">
            <div class="cms-fd-name"><?= $h($frontDoor['name'] ?? 'Amtgard International') ?></div>
            <div class="cms-fd-sub">
                <?= $h($frontDoor['subtitle'] ?? 'Global front door') ?>
                &nbsp;·&nbsp;<span class="cms-site-badge cms-site-badge-published">Always live</span>
            </div>
        </div>
        <div class="cms-fd-stats">
            <div class="cms-fd-stat">
                <div class="cms-fd-stat-num"><?= (int)($frontDoor['pages_published'] ?? 0) ?> / <?= (int)($frontDoor['pages_total'] ?? 0) ?></div>
                <div class="cms-fd-stat-lbl">Pages</div>
            </div>
            <div class="cms-fd-stat">
                <div class="cms-fd-stat-num"><?= (int)($frontDoor['posts_total'] ?? 0) ?></div>
                <div class="cms-fd-stat-lbl">Posts</div>
            </div>
        </div>
        <div class="cms-fd-actions">
            <a class="cms-btn cms-btn-primary" href="<?= $h($frontDoor['manage_url'] ?? UIR) ?>" data-tip="Open the global front-door CMS admin">
                <i class="fas fa-sliders-h" aria-hidden="true"></i> Manage
            </a>
            <a class="cms-btn" href="<?= $h($frontDoor['visit_url'] ?? UIR) ?>" target="_blank" rel="noopener noreferrer" data-tip="Open the public front door in a new tab">
                <i class="fas fa-external-link-alt" aria-hidden="true"></i> Visit
            </a>
        </div>
    </section>
    <?php endif; ?>

    <?php
    /* ---- Reusable row renderer for a site table ---- */
    $renderSiteRows = function ($rows) use ($h, $statusMeta, $fmtDate) {
        foreach ($rows as $s):
            list($statusLabel, $statusMod) = $statusMeta($s['status'] ?? 'unbuilt');
            $isPub    = ((string)($s['status'] ?? '') === 'published');
            $slug     = (string)($s['slug'] ?? '');
            $visitUrl = (string)($s['visit_url'] ?? '');
            $sel      = (string)($s['scope_sel'] ?? '');
    ?>
        <tr data-scope="<?= $h($sel) ?>">
            <td data-label="Site">
                <div class="cms-sites-org"><?= $h($s['org_name'] !== '' ? $s['org_name'] : '(unnamed org)') ?></div>
                <?php if ($slug !== ''): ?>
                    <?php if ($isPub && $visitUrl !== ''): ?>
                        <a class="cms-sites-slug" href="<?= $h($visitUrl) ?>" target="_blank" rel="noopener noreferrer">/<?= $h($slug) ?> <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <span class="cms-sites-slug">/<?= $h($slug) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td data-label="Status">
                <span class="cms-site-badge cms-site-badge-<?= $h($statusMod) ?>" data-status-badge><?= $h($statusLabel) ?></span>
            </td>
            <td data-label="Pages" class="cms-sites-metric cms-muted">
                <?= (int)($s['pages_published'] ?? 0) ?> / <?= (int)($s['pages_total'] ?? 0) ?>
            </td>
            <td data-label="Posts" class="cms-sites-metric cms-muted"><?= (int)($s['posts_total'] ?? 0) ?></td>
            <td data-label="Updated" class="cms-muted"><?= $h($fmtDate($s['updated_at'] ?? '')) ?></td>
            <td data-label="Actions">
                <div class="cms-sites-rowactions">
                    <a class="cms-btn cms-btn-sm" href="<?= $h($s['manage_url'] ?? '#') ?>" data-tip="Open this site's CMS admin"><i class="fas fa-sliders-h" aria-hidden="true"></i> Manage</a>
                    <button type="button" class="cms-btn cms-btn-sm<?= $isPub ? ' cms-btn-ghost' : '' ?>"
                            data-pubsite
                            data-scope="<?= $h($sel) ?>"
                            data-status="<?= $isPub ? 'published' : 'draft' ?>"
                            data-tip="<?= $isPub ? 'Take this site offline (return to draft)' : 'Make this site publicly visible' ?>">
                        <?php if ($isPub): ?><i class="fas fa-eye-slash" aria-hidden="true"></i> Unpublish<?php else: ?><i class="fas fa-globe" aria-hidden="true"></i> Publish<?php endif; ?>
                    </button>
                </div>
            </td>
        </tr>
    <?php endforeach;
    };
    ?>

    <?php if ($totalSites === 0): ?>
        <div class="cms-empty">
            <div class="cms-empty-icon"><i class="fas fa-sitemap"></i></div>
            <div class="cms-empty-copy">No kingdom or park sites have been started yet.</div>
            <button type="button" class="cms-btn cms-btn-primary cms-empty-cta" id="cmsNewSiteEmptyBtn">
                <i class="fas fa-plus"></i> Provision the first site
            </button>
        </div>
    <?php endif; ?>

    <?php /* ---- Kingdoms section ---- */ ?>
    <?php if (!empty($kSites)): ?>
    <section aria-label="Kingdom sites">
        <div class="cms-sites-section-head">
            <h2><i class="fas fa-crown" aria-hidden="true" style="color:var(--cms-gold,#f0b429);margin-right:6px;"></i>Kingdoms</h2>
            <span class="cms-sites-count"><?= count($kSites) ?> site<?= count($kSites) === 1 ? '' : 's' ?></span>
        </div>
        <div class="cms-table-wrap">
            <table class="cms-table cms-sites-table">
                <thead>
                    <tr>
                        <th scope="col">Site</th>
                        <th scope="col">Status</th>
                        <th scope="col">Pages</th>
                        <th scope="col">Posts</th>
                        <th scope="col">Updated</th>
                        <th scope="col" style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody><?php $renderSiteRows($kSites); ?></tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php /* ---- Parks section ---- */ ?>
    <?php if (!empty($pSites)): ?>
    <section aria-label="Park sites">
        <div class="cms-sites-section-head">
            <h2><i class="fas fa-map-marker-alt" aria-hidden="true" style="color:var(--cms-gold,#f0b429);margin-right:6px;"></i>Parks</h2>
            <span class="cms-sites-count"><?= count($pSites) ?> site<?= count($pSites) === 1 ? '' : 's' ?></span>
        </div>
        <div class="cms-table-wrap">
            <table class="cms-table cms-sites-table">
                <thead>
                    <tr>
                        <th scope="col">Site</th>
                        <th scope="col">Status</th>
                        <th scope="col">Pages</th>
                        <th scope="col">Posts</th>
                        <th scope="col">Updated</th>
                        <th scope="col" style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody><?php $renderSiteRows($pSites); ?></tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

</div><!-- /.cms-sites-wrap -->

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

<?php /* ---- New-site (provision) modal ---- */ ?>
<div class="cms-modal-overlay" id="cmsNewSiteModal">
    <div class="cms-modal" role="dialog" aria-modal="true" aria-label="Provision a site">
        <div class="cms-modal-head">
            <h3>Provision a site</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <p class="cms-muted" style="margin-top:0;font-size:13px;">
                Opening a site's CMS admin for the first time automatically creates and
                seeds it. Pick a kingdom — or drill into one of its parks — then open the
                dashboard to build it out.
            </p>
            <div class="cms-provision-grid">
                <div class="cms-provision-col">
                    <label class="cms-provision-label" for="cmsProvKingdom">Kingdom</label>
                    <div class="cms-provision-row">
                        <select id="cmsProvKingdom" class="cms-select" aria-label="Kingdom">
                            <option value="">Choose a kingdom…</option>
                            <?php foreach ($provision as $k): ?>
                                <option value="<?= (int)$k['id'] ?>"<?= empty($k['has_site']) ? ' class="cms-optnosite"' : '' ?>>
                                    <?= $h($k['name']) ?><?= !empty($k['has_site']) ? ' — has site' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="cms-btn cms-btn-primary" id="cmsProvKingdomOpen" disabled data-tip="Open (and create) this kingdom's site">Open</button>
                    </div>
                    <div class="cms-provision-hint">Kingdoms without a site yet are shown in bold.</div>
                </div>
                <div class="cms-provision-col">
                    <label class="cms-provision-label" for="cmsProvPark">Park (optional)</label>
                    <div class="cms-provision-row">
                        <select id="cmsProvPark" class="cms-select" aria-label="Park" disabled>
                            <option value="">Choose a kingdom first…</option>
                        </select>
                        <button type="button" class="cms-btn cms-btn-primary" id="cmsProvParkOpen" disabled data-tip="Open (and create) this park's site">Open</button>
                    </div>
                    <div class="cms-provision-hint">Parks are listed for the selected kingdom (and its principalities).</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="cms-toast" id="cmsSitesToast" role="status" aria-live="polite" aria-atomic="true"></div>

<script>
(function () {
    'use strict';
    var UIR  = <?= json_encode(UIR) ?>;
    var AJAX = UIR + 'CmsAjax/';

    /* ---- toast ---- */
    var toastEl = document.getElementById('cmsSitesToast');
    var toastTimer = null;
    function toast(msg, kind) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.className = 'cms-toast cms-show' + (kind ? ' cms-toast-' + kind : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.className = 'cms-toast'; }, 3200);
    }

    /* ---- modal helpers ---- */
    function openModal(el) { if (el) { el.classList.add('cms-open'); } }
    function closeModal(el) { if (el) { el.classList.remove('cms-open'); } }
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

    var newModal = document.getElementById('cmsNewSiteModal');
    ['cmsNewSiteBtn', 'cmsNewSiteEmptyBtn'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b) { b.addEventListener('click', function () { openModal(newModal); }); }
    });

    /* ====================================================================
     * Publish / Unpublish a site — keys off the ROW's own scope selector
     * (k:{id} / p:{id}), NOT the page's window.CMS_SCOPE (which is global here).
     * Super-admin passes cms_can + _resolveScope for any scope.
     * ==================================================================== */
    function postSite(endpoint, scopeSel) {
        return fetch(AJAX + endpoint + '&scope=' + encodeURIComponent(scopeSel), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': (window.CMS_CSRF || '') },
            body: ''
        }).then(function (r) { return r.json(); });
    }

    document.querySelectorAll('[data-pubsite]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var scopeSel = btn.getAttribute('data-scope') || '';
            var cur = btn.getAttribute('data-status');
            var publishing = (cur !== 'published');
            var endpoint = publishing ? 'publishsite' : 'unpublishsite';
            if (!scopeSel) { toast('Missing site scope.', 'error'); return; }
            btn.disabled = true;
            postSite(endpoint, scopeSel).then(function (res) {
                btn.disabled = false;
                if (!res || !res.ok) { toast((res && res.error) || 'Action failed.', 'error'); return; }
                var nowPub = (res.status === 'published');
                btn.setAttribute('data-status', nowPub ? 'published' : 'draft');
                btn.classList.toggle('cms-btn-ghost', nowPub);
                btn.innerHTML = nowPub
                    ? '<i class="fas fa-eye-slash" aria-hidden="true"></i> Unpublish'
                    : '<i class="fas fa-globe" aria-hidden="true"></i> Publish';
                btn.setAttribute('data-tip', nowPub ? 'Take this site offline (return to draft)' : 'Make this site publicly visible');
                var row = btn.closest('tr');
                var badge = row ? row.querySelector('[data-status-badge]') : null;
                if (badge) {
                    badge.className = 'cms-site-badge cms-site-badge-' + (nowPub ? 'published' : 'draft');
                    badge.textContent = nowPub ? 'Published' : 'Draft';
                }
                toast(nowPub ? 'Site published.' : 'Site returned to draft.', 'ok');
            }).catch(function () { btn.disabled = false; toast('Network error.', 'error'); });
        });
    });

    /* ====================================================================
     * Provisioning: pick a kingdom → open its scoped dashboard (auto-creates +
     * seeds via EnsureSite). Optional kingdom→park cascade for park sites.
     * ==================================================================== */
    var kSel     = document.getElementById('cmsProvKingdom');
    var kOpen    = document.getElementById('cmsProvKingdomOpen');
    var pSel     = document.getElementById('cmsProvPark');
    var pOpen    = document.getElementById('cmsProvParkOpen');

    function resetPark(disabled, placeholder) {
        if (!pSel) { return; }
        pSel.innerHTML = '';
        var opt = document.createElement('option');
        opt.value = '';
        opt.textContent = placeholder || 'Choose a park…';
        pSel.appendChild(opt);
        pSel.disabled = !!disabled;
        if (pOpen) { pOpen.disabled = true; }
    }

    if (kSel) {
        kSel.addEventListener('change', function () {
            var kid = kSel.value;
            if (kOpen) { kOpen.disabled = !kid; }
            if (!kid) { resetPark(true, 'Choose a kingdom first…'); return; }
            resetPark(true, 'Loading parks…');
            fetch(UIR + 'KingdomAjax/kingdom/' + encodeURIComponent(kid) + '/getparks', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    resetPark(false, 'Choose a park…');
                    (d && d.parks ? d.parks : []).forEach(function (pk) {
                        var o = document.createElement('option');
                        o.value = pk.ParkId;
                        o.textContent = pk.Name;
                        pSel.appendChild(o);
                    });
                })
                .catch(function () { resetPark(true, 'Could not load parks'); toast('Could not load parks.', 'error'); });
        });
    }
    if (pSel && pOpen) {
        pSel.addEventListener('change', function () { pOpen.disabled = !pSel.value; });
    }
    if (kOpen) {
        kOpen.addEventListener('click', function () {
            var kid = kSel ? kSel.value : '';
            if (!kid) { return; }
            window.location.href = UIR + 'Cms/dashboard&scope=k:' + encodeURIComponent(kid);
        });
    }
    if (pOpen) {
        pOpen.addEventListener('click', function () {
            var pid = pSel ? pSel.value : '';
            if (!pid) { return; }
            window.location.href = UIR + 'Cms/dashboard&scope=p:' + encodeURIComponent(pid);
        });
    }
})();
</script>
