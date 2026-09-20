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
 *                         'updated_at','manage_url','visit_url',
 *                         'seeded' (template seeding finished),
 *                         'org_missing' (owning kingdom/park row is gone)]
 *   $ParkSites          same shape, park scope
 *   $ProvisionKingdoms  list of ['id','name','has_site','parks_ok'] for the
 *                        New-site picker ('parks_ok' = this kingdom's parks may
 *                        actually be provisioned right now, per CanCreateSite)
 *   $ProvisionKingdomsOk bool — may KINGDOM sites be provisioned at all right
 *                        now (the global kingdom switch, per CanCreateSite)
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
// Rollout gate for the KINGDOM half of the picker (the park half rides on each
// option's data-parks). Absent reads as ON so a controller that has not been
// updated cannot hide a working button.
$provKingdomsOk = !isset($ProvisionKingdomsOk) || !empty($ProvisionKingdomsOk);

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

$totalSites = count($kSites) + count($pSites);

// Health census. A site is "needs attention" when its starter template never
// finished seeding, or when the kingdom/park it belongs to has been deleted out
// from under it. Both are invisible in this table otherwise, so the count drives
// an opt-in filter above the tables (hidden entirely when everything is healthy).
$needsAttention = function ($s) {
    return empty($s['seeded']) || !empty($s['org_missing']);
};
$attentionCount = 0;
foreach (array_merge($kSites, $pSites) as $s) {
    if ($needsAttention($s)) {
        $attentionCount++;
    }
}
?>

<?php // Sites-overview styling (.cms-sites-*/.cms-fd-*/.cms-provision-*) lives in
      // the shared, cacheable cms-admin.css (section "CMS Sites overview"), loaded
      // once by cms/_shell_top.tpl below — no per-render inline block. ?>

<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'sites';
$cmsTitle   = 'OGRE Sites';
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

    <?php /* ---- Health filter: only rendered when something actually needs looking
             at, so a healthy network carries no dead control. Pure client-side row
             hiding — the tables are already fully rendered. ---- */ ?>
    <?php if ($attentionCount > 0): ?>
    <div class="cms-sites-filterbar">
        <label class="cms-sites-filter">
            <input type="checkbox" id="cmsSitesAttentionOnly">
            Show only sites needing attention
            <span class="cms-site-badge cms-site-badge-orphan"><?= (int)$attentionCount ?></span>
        </label>
    </div>
    <?php endif; ?>

    <?php
    /* ---- Reusable row renderer for a site table ---- */
    $renderSiteRows = function ($rows) use ($h, $statusMeta, $cmsFmtDate, $needsAttention) {
        foreach ($rows as $s):
            list($statusLabel, $statusMod) = $statusMeta($s['status'] ?? 'unbuilt');
            $isPub    = ((string)($s['status'] ?? '') === 'published');
            $slug     = (string)($s['slug'] ?? '');
            $visitUrl = (string)($s['visit_url'] ?? '');
            $sel      = (string)($s['scope_sel'] ?? '');
            // Same label the Site cell renders — the confirm dialog names the site
            // it is about to rewrite, so it must never say "(unnamed org)" when
            // the table says otherwise.
            $orgLabel = (string)($s['org_name'] ?? '') !== ''
                ? (string)$s['org_name']
                : (!empty($s['org_missing']) ? '(deleted org)' : '(unnamed org)');
    ?>
        <tr data-scope="<?= $h($sel) ?>"<?= $needsAttention($s) ? ' data-attention="1"' : '' ?>>
            <td data-label="Site">
                <?php // An orphaned site's org name comes back empty (the JOIN found no row) —
                      // say WHICH nothing it is rather than rendering a blank cell. ?>
                <div class="cms-sites-org"><?= $h($orgLabel) ?></div>
                <?php if ($slug !== ''): ?>
                    <?php if ($isPub && $visitUrl !== ''): ?>
                        <a class="cms-sites-slug" href="<?= $h($visitUrl) ?>" target="_blank" rel="noopener noreferrer">/<?= $h($slug) ?> <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <span class="cms-sites-slug">/<?= $h($slug) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td data-label="Status">
                <div class="cms-sites-badges">
                    <span class="cms-site-badge cms-site-badge-<?= $h($statusMod) ?>" data-status-badge><?= $h($statusLabel) ?></span>
                    <?php // HEALTH badges, separate from the publish status: a half-seeded site
                          // and a site whose org row has been deleted are otherwise invisible. ?>
                    <?php if (empty($s['seeded'])): ?>
                        <span class="cms-site-badge cms-site-badge-seeding"
                              data-tip="This site's starter template never finished seeding — it has no starter pages or nav.">Seeding incomplete</span>
                    <?php endif; ?>
                    <?php if (!empty($s['org_missing'])): ?>
                        <span class="cms-site-badge cms-site-badge-orphan"
                              data-tip="The kingdom or park this site belongs to no longer exists.">Orphaned org</span>
                    <?php endif; ?>
                </div>
            </td>
            <?php // Published pages only (the count is gated on the SITE's own status, so an
                  // unpublished site reports 0 however many pages it holds) over the total. ?>
            <td data-label="Publicly visible" class="cms-sites-metric cms-muted"
                data-tip="Pages the public can actually reach, out of all pages. A site that is not published shows 0.">
                <?= (int)($s['pages_published'] ?? 0) ?> / <?= (int)($s['pages_total'] ?? 0) ?>
            </td>
            <td data-label="Posts" class="cms-sites-metric cms-muted"><?= (int)($s['posts_total'] ?? 0) ?></td>
            <td data-label="Updated" class="cms-muted"><?= $h($cmsFmtDate($s['updated_at'] ?? '')) ?></td>
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
                    <?php // Recovery action: re-runs the starter template through EnsureSite's
                          // existing repair pass. Offered on every provisioned org site, not
                          // only the "Seeding incomplete" ones — the report this answers is
                          // "a page is missing / the nav is empty", which happens on sites
                          // whose marker IS stamped. Safe to offer broadly because the repair
                          // pass only ever ADDS (see CmsSite::ClearSeedMarker's contract), and
                          // the dialog spells out what it will and will not touch. ?>
                    <?php if ($s['scope_type'] === 'kingdom' || $s['scope_type'] === 'park'): ?>
                        <button type="button" class="cms-btn cms-btn-sm"
                                data-reseedsite
                                data-scope="<?= $h($sel) ?>"
                                data-site="<?= $h($orgLabel) ?>"
                                data-tip="Re-run this site's starter template to create any starter pages and navigation it is missing">
                            <i class="fas fa-wrench" aria-hidden="true"></i> Re-run seed
                        </button>
                    <?php endif; ?>
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
                        <th scope="col" data-tip="Published pages over total pages. A site that is not itself published shows 0 &mdash; nothing on it is publicly reachable.">Publicly visible</th>
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
                        <th scope="col" data-tip="Published pages over total pages. A site that is not itself published shows 0 &mdash; nothing on it is publicly reachable.">Publicly visible</th>
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
                Opening a site in OGRE for the first time automatically creates and
                seeds it. Pick a kingdom — or drill into one of its parks — then open the
                dashboard to build it out.
            </p>
            <div class="cms-provision-grid">
                <div class="cms-provision-col">
                    <label class="cms-provision-label" for="cmsProvKingdom">Kingdom</label>
                    <div class="cms-provision-row">
                        <?php // data-kingdoms-ok: is the global kingdom-sites switch on? With it
                              // off, opening a kingdom dashboard dead-ends on the blocked card,
                              // so the Open button stays disabled and the hint says why. ?>
                        <select id="cmsProvKingdom" class="cms-select" aria-label="Kingdom"
                                data-kingdoms-ok="<?= $provKingdomsOk ? '1' : '0' ?>">
                            <option value="">Choose a kingdom…</option>
                            <?php foreach ($provision as $k): ?>
                                <?php // data-parks: may this kingdom's PARKS be provisioned right now
                                      // (CanCreateSite, resolved server-side)? The park picker below
                                      // reads it instead of offering a create button that cannot work. ?>
                                <option value="<?= (int)$k['id'] ?>"<?= empty($k['has_site']) ? ' class="cms-optnosite"' : '' ?>
                                        data-parks="<?= !empty($k['parks_ok']) ? '1' : '0' ?>">
                                    <?= $h($k['name']) ?><?= !empty($k['has_site']) ? ' — has site' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="cms-btn cms-btn-primary" id="cmsProvKingdomOpen" disabled data-tip="Open (and create) this kingdom's site">Open</button>
                    </div>
                    <div class="cms-provision-hint">
                        <?= $provKingdomsOk
                            ? 'Kingdoms without a site yet are shown in bold.'
                            : 'Kingdom websites are not switched on yet, so no kingdom site can be created. An ORK administrator turns them on in the OGRE settings.' ?>
                    </div>
                </div>
                <div class="cms-provision-col">
                    <label class="cms-provision-label" for="cmsProvPark">Park (optional)</label>
                    <div class="cms-provision-row">
                        <select id="cmsProvPark" class="cms-select" aria-label="Park" disabled>
                            <option value="">Choose a kingdom first…</option>
                        </select>
                        <button type="button" class="cms-btn cms-btn-primary" id="cmsProvParkOpen" disabled data-tip="Open (and create) this park's site">Open</button>
                    </div>
                    <div class="cms-provision-hint" id="cmsProvParkHint">Parks are listed for the selected kingdom (and its principalities).</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php /* Shared destructive-action dialog (CmsAdmin.confirm); native confirm() is banned. */ ?>
<?php include __DIR__ . '/cms/_confirm_modal.tpl'; ?>

<div class="cms-toast" id="cmsToast" role="status" aria-live="polite" aria-atomic="true"></div>

<script>
(function () {
    'use strict';
    var UIR  = window.CMS_UIR;
    var AJAX = UIR + 'CmsAjax/';

    /* ---- toast (shared: CmsAdmin.toast — resolves the page's .cms-toast) ---- */
    var toast = CmsAdmin.toast;

    /* ---- modal helpers (shared: CmsAdmin.modal; backdrop/Esc handled there) ---- */
    var openModal = CmsAdmin.modal.open;
    var closeModal = CmsAdmin.modal.close;

    /* ---- Health filter (Seeding incomplete / Orphaned org) ---- */
    var attentionOnly = document.getElementById('cmsSitesAttentionOnly');
    if (attentionOnly) {
        attentionOnly.addEventListener('change', function () {
            var on = attentionOnly.checked;
            document.querySelectorAll('.cms-sites-table tbody tr').forEach(function (tr) {
                tr.hidden = on && tr.getAttribute('data-attention') !== '1';
            });
        });
    }

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
     * Re-run the starter seed (super-admin recovery for "Seeding incomplete").
     * Same per-row scope selector as publish/unpublish. Guarded by the shared
     * confirm dialog — this writes to a site that may be live.
     * ==================================================================== */
    document.querySelectorAll('[data-reseedsite]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var scopeSel = btn.getAttribute('data-scope') || '';
            var name     = btn.getAttribute('data-site') || 'this site';
            if (!scopeSel) { toast('Missing site scope.', 'error'); return; }
            CmsAdmin.confirm(
                'Re-run the starter template?',
                'This re-runs the starter template for ' + name + ', creating the starter pages and navigation items it is missing. '
                    + 'It only ADDS: pages anyone deleted stay deleted, and a home page the org already chose is not re-pointed. '
                    + 'If the site has no home page or no theme yet, this sets those — including a default palette from its heraldry. '
                    + 'The site may already be public.',
                'Re-run seed',
                function () {
                    CmsAdmin.confirmBusy(true);
                    var original = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Repairing…';
                    postSite('reseedsite', scopeSel).then(function (res) {
                        CmsAdmin.confirmBusy(false);
                        CmsAdmin.confirmClose();
                        btn.disabled = false;
                        btn.innerHTML = original;
                        if (!res || !res.ok) { toast((res && res.error) || 'The starter repair could not run.', 'error'); return; }
                        /* Report what actually happened — the message is built
                           server-side from a before/after census, so "nothing
                           needed repair" is never dressed up as a fix. */
                        toast(res.message || 'Starter repair finished.', 'ok');
                        /* Clear the row's health state ONLY when the seed really
                           completed (res.seeded); otherwise the badge and the
                           button both stay, because the site is still broken. */
                        if (res.seeded) {
                            var row = btn.closest('tr');
                            if (row) {
                                var badge = row.querySelector('.cms-site-badge-seeding');
                                if (badge) { badge.remove(); }
                                /* Still needs attention if its org row is gone. */
                                if (!row.querySelector('.cms-site-badge-orphan')) {
                                    row.removeAttribute('data-attention');
                                }
                            }
                            btn.remove();
                        }
                    }).catch(function () {
                        CmsAdmin.confirmBusy(false);
                        CmsAdmin.confirmClose();
                        btn.disabled = false;
                        btn.innerHTML = original;
                        toast('Network error running the starter repair.', 'error');
                    });
                }
            );
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
    /* Rollout gate, kingdom half: resolved server-side onto the select. */
    var kingdomsOk = !kSel || kSel.getAttribute('data-kingdoms-ok') !== '0';

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

    var parkHint = document.getElementById('cmsProvParkHint');
    var PARK_HINT_DEFAULT = parkHint ? parkHint.textContent : '';

    if (kSel) {
        kSel.addEventListener('change', function () {
            var kid = kSel.value;
            if (kOpen) { kOpen.disabled = !kid || !kingdomsOk; }
            if (!kid) {
                if (parkHint) { parkHint.textContent = PARK_HINT_DEFAULT; }
                resetPark(true, 'Choose a kingdom first…');
                return;
            }
            /* Rollout gate (CanCreateSite, resolved server-side onto the option):
               opening a park dashboard here would call EnsureSite, get null back
               and strand the admin on a dashboard with no site. Say why instead. */
            var opt = kSel.options[kSel.selectedIndex];
            if (opt && opt.getAttribute('data-parks') !== '1') {
                resetPark(true, 'Park sites are not enabled for this kingdom');
                if (parkHint) {
                    parkHint.textContent = 'This kingdom has not enabled park websites (or the global park switch is off), so its parks cannot be provisioned yet.';
                }
                return;
            }
            if (parkHint) { parkHint.textContent = PARK_HINT_DEFAULT; }
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
