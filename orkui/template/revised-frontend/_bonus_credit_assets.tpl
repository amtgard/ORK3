<?php
/**
 * Bonus Credits modal: shared by the park and kingdom pages.
 *
 * Bonus credits are class credits that are NOT attendance (class.BonusCredit.php):
 * they count toward levels and nowhere else. The identity of a credit is its CATEGORY, the thing
 * it is for, not a date.
 *
 * Three jobs, three tabs (like Enter Attendance), each shown only to those who can
 * use it:
 *   Grant    — pick a credit, add players against it, see who holds it. Everyone
 *              who can open the modal: park EDIT, kingdom EDIT, granters.
 *   Categories — define a new credit category, archive or restore one, see the list.
 *              Kingdom CREATE only (canDefine).
 *   Granters — appoint people who may grant (all categories or one) without kingdom
 *              rights. Kingdom CREATE only (canAppoint).
 *
 * Credits are DEFINED by a kingdom and HANDED OUT by its parks and granters. On a
 * park page the Grant tab lists what the park's kingdom (and the kingdoms above it)
 * defined; a granter sees only what their rows cover.
 *
 * The host sets window.OrkBcCfg before including this file:
 *   { uir, scopeType: 'park'|'kingdom', scopeId, scopeName, kingdomId }
 * and offers a button that calls bcOpen(). Needs jQuery + jQuery UI autocomplete,
 * which both hosts already load for Enter Attendance / player search.
 */
?>
<style>
/* --z-modal, like Enter Attendance: the page's confirm dialogs sit at --z-modal-top, above this. */
#bc-overlay { position:fixed; inset:0; z-index:var(--z-modal, 10100); background:rgba(0,0,0,.5); display:none; align-items:flex-start; justify-content:center; padding:40px 16px; overflow:auto; }
#bc-overlay.bc-open { display:flex; }
.bc-box { width:100%; max-width:760px; background:var(--ork-card-bg,#fff); color:var(--ork-text,#1a202c); border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,.3); display:flex; flex-direction:column; max-height:calc(100vh - 80px); }
.bc-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:16px 20px 12px; border-bottom:1px solid var(--ork-divider,#e2e8f0); }
/* The hosts style bare h3s (the kingdom page boxes them); this one is a dialog title. */
#theme_container .bc-title, .bc-title { margin:0; padding:0; font-size:16px; font-weight:700; background:none; border:0; box-shadow:none; text-shadow:none; color:inherit; }
.bc-sub { margin:2px 0 0; font-size:12px; color:var(--ork-text-muted,#718096); }
.bc-close { background:none; border:0; font-size:22px; line-height:1; color:var(--ork-text-lighter,#a0aec0); cursor:pointer; padding:0 4px; }
.bc-close:hover { color:var(--ork-text,#4a5568); }
.bc-body { padding:16px 20px; overflow-y:auto; flex:1; }
.bc-foot { padding:12px 20px; border-top:1px solid var(--ork-divider,#e2e8f0); display:flex; justify-content:flex-end; gap:8px; }
/* Tabs */
.bc-tabs { display:flex; gap:4px; margin:0 0 16px; padding:3px; background:var(--ork-bg-secondary,#f7fafc); border:1px solid var(--ork-divider,#e2e8f0); border-radius:8px; }
.bc-tabs[hidden] { display:none; }
.bc-tab { flex:1 1 0; font:inherit; font-size:13px; font-weight:600; padding:8px 12px; border:0; border-radius:6px; background:transparent; color:var(--ork-text-secondary,#4a5568); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:6px; }
.bc-tab[hidden] { display:none; }
.bc-tab.bc-tab-active { background:var(--ork-card-bg,#fff); color:var(--ork-text,#1a202c); box-shadow:0 1px 3px rgba(0,0,0,.12); }
.bc-pane[hidden] { display:none; }
/* Form bits */
.bc-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:12px; }
.bc-field { display:flex; flex-direction:column; gap:3px; min-width:0; }
.bc-field label { font-size:11px; font-weight:600; color:var(--ork-text-muted,#718096); text-transform:uppercase; letter-spacing:.03em; }
.bc-field input[type=text], .bc-field input[type=number], .bc-field select { font:inherit; font-size:14px; padding:7px 10px; border:1px solid var(--ork-input-border,#cbd5e0); border-radius:6px; background:var(--ork-input-bg,#fff); color:inherit; min-height:36px; box-sizing:border-box; }
.bc-amount { min-height:36px; display:flex; align-items:center; padding:7px 10px; border:1px solid transparent; font-size:14px; font-weight:600; }
.bc-grow { flex:1 1 220px; }
.bc-sm { flex:0 0 96px; }
.bc-md { flex:0 0 170px; }
.bc-btn { font:inherit; font-size:13px; font-weight:600; padding:8px 14px; border-radius:6px; border:1px solid transparent; cursor:pointer; min-height:36px; }
.bc-btn-primary { background:#2b6cb0; color:#fff; }
.bc-btn-primary:hover { background:#2c5282; }
.bc-btn-primary:disabled { opacity:.5; cursor:default; }
.bc-btn-ghost { background:transparent; border-color:var(--ork-input-border,#cbd5e0); color:inherit; }
.bc-btn-ghost:hover { background:var(--ork-bg-secondary,#f7fafc); }
.bc-btn-ghost:disabled { opacity:.5; cursor:default; }
.bc-check { display:flex; align-items:center; gap:6px; font-size:13px; min-height:36px; }
.bc-check-sm { min-height:0; font-size:12px; color:var(--ork-text-muted,#718096); }
.bc-info { font-size:12px; color:var(--ork-text-muted,#718096); margin:-6px 0 12px; }
.bc-info[hidden] { display:none; }
.bc-feedback { display:none; padding:8px 12px; border-radius:6px; font-size:13px; margin-bottom:12px; }
.bc-feedback.bc-ok { display:block; background:#f0fff4; border:1px solid #c6f6d5; color:#276749; }
.bc-feedback.bc-err { display:block; background:#fff5f5; border:1px solid #fed7d7; color:#c53030; }
.bc-list-label { font-size:11px; font-weight:600; color:var(--ork-text-muted,#718096); text-transform:uppercase; letter-spacing:.03em; margin:8px 0 6px; display:flex; justify-content:space-between; align-items:center; }
.bc-table { width:100%; border-collapse:collapse; font-size:13px; }
.bc-table th { text-align:left; font-size:11px; font-weight:600; color:var(--ork-text-muted,#718096); text-transform:uppercase; padding:6px 8px; border-bottom:1px solid var(--ork-divider,#e2e8f0); }
.bc-table td { padding:6px 8px; border-bottom:1px solid var(--ork-divider,#f0f4f8); vertical-align:middle; }
.bc-table tr:last-child td { border-bottom:0; }
.bc-table .bc-num { text-align:right; white-space:nowrap; }
.bc-table .bc-muted { color:var(--ork-text-muted,#718096); font-size:12px; }
.bc-table tr.bc-just-added td { background:#f0fff4; }
.bc-table tr.bc-archived td { opacity:.6; }
.bc-link { background:none; border:0; padding:0; color:#2b6cb0; cursor:pointer; font:inherit; font-size:12px; }
.bc-link:hover { text-decoration:underline; }
.bc-link-danger { color:#c53030; }
.bc-empty { font-size:13px; color:var(--ork-text-muted,#a0aec0); padding:10px 0; }
.bc-new { border:1px dashed var(--ork-input-border,#cbd5e0); border-radius:8px; padding:12px; margin-bottom:16px; background:var(--ork-bg-secondary,#f7fafc); }
.bc-new .bc-list-label { margin-top:0; }
.bc-granter-list { list-style:none; margin:0 0 14px; padding:0; font-size:13px; }
.bc-granter-list li { display:flex; align-items:center; gap:8px; padding:5px 0; border-bottom:1px solid var(--ork-divider,#f0f4f8); }
.bc-granter-list li:last-child { border-bottom:0; }
.bc-granter-list .bc-muted { flex:1; color:var(--ork-text-muted,#718096); font-size:12px; }
.bc-archived-tag { font-size:11px; font-weight:600; color:#c53030; margin-left:6px; }
.bc-away-tag { display:inline-block; font-size:11px; font-weight:600; color:var(--ork-text-muted,#718096); border:1px solid var(--ork-divider,#e2e8f0); border-radius:10px; padding:0 6px; margin-left:6px; vertical-align:middle; }
.bc-setting { display:flex; align-items:flex-start; gap:10px; padding:12px 14px; border:1px solid var(--ork-divider,#e2e8f0); border-radius:8px; background:var(--ork-bg-secondary,#f7fafc); margin:0 0 12px; cursor:pointer; }
.bc-setting input { margin-top:3px; }
.bc-setting strong { display:block; font-size:14px; }
.bc-setting .bc-muted { display:block; margin-top:2px; }
.bc-setting-text p { font-size:13px; margin:0 0 8px; }
.bc-setting-text p:last-child { margin-bottom:0; }
html[data-theme="dark"] .bc-table tr.bc-just-added td { background:#1c2f24; }
html[data-theme="dark"] .bc-feedback.bc-ok { background:#1c2f24; border-color:#276749; color:#9ae6b4; }
html[data-theme="dark"] .bc-feedback.bc-err { background:#3b1d1d; border-color:#9b2c2c; color:#feb2b2; }
.ui-autocomplete.bc-ac { z-index:10200 !important; }
@media (max-width: 600px) {
    #bc-overlay { padding:12px 8px; }
    .bc-sm, .bc-md { flex:1 1 45%; }
}
</style>

<div id="bc-overlay" role="dialog" aria-modal="true" aria-labelledby="bc-title" aria-hidden="true">
    <div class="bc-box">
        <div class="bc-head">
            <div>
                <h3 class="bc-title" id="bc-title"><i class="fas fa-star" style="margin-right:8px;color:#d69e2e"></i>Bonus Credits</h3>
                <p class="bc-sub" id="bc-sub"></p>
            </div>
            <button type="button" class="bc-close" id="bc-close" aria-label="Close">&times;</button>
        </div>
        <div class="bc-body">
            <div class="bc-feedback" id="bc-feedback" role="status"></div>

            <div class="bc-tabs" id="bc-tabs" role="tablist" hidden>
                <button type="button" class="bc-tab bc-tab-active" data-pane="bc-pane-grant" role="tab"><i class="fas fa-plus"></i> Grant</button>
                <button type="button" class="bc-tab" data-pane="bc-pane-credits" id="bc-tab-credits" role="tab" hidden><i class="fas fa-list"></i> Categories</button>
                <button type="button" class="bc-tab" data-pane="bc-pane-granters" id="bc-tab-granters" role="tab" hidden><i class="fas fa-user-check"></i> Granters</button>
                <button type="button" class="bc-tab" data-pane="bc-pane-settings" id="bc-tab-settings" role="tab" hidden><i class="fas fa-sliders-h"></i> Settings</button>
            </div>

            <!-- ───────── Grant ───────── -->
            <div class="bc-pane" id="bc-pane-grant">
                <div class="bc-row">
                    <div class="bc-field bc-grow">
                        <label for="bc-category">Category</label>
                        <select id="bc-category"></select>
                    </div>
                </div>
                <div class="bc-info" id="bc-category-info" hidden></div>
                <div class="bc-row" id="bc-grant-row">
                    <div class="bc-field bc-grow">
                        <label for="bc-player">Player</label>
                        <input type="text" id="bc-player" autocomplete="off" placeholder="Search players…">
                        <input type="hidden" id="bc-player-id">
                    </div>
                    <div class="bc-field bc-md">
                        <label for="bc-class">Class</label>
                        <select id="bc-class"></select>
                    </div>
                    <div class="bc-field bc-sm">
                        <label for="bc-credits">Credits</label>
                        <input type="number" id="bc-credits" min="0.25" max="10" step="0.25" value="1">
                    </div>
                    <div class="bc-field">
                        <label>&nbsp;</label>
                        <button type="button" class="bc-btn bc-btn-primary" id="bc-add" disabled><i class="fas fa-plus"></i> Add</button>
                    </div>
                </div>
                <div class="bc-info" id="bc-allowance" hidden></div>
                <div class="bc-info" id="bc-outside-hint" hidden></div>
                <div class="bc-list-label"><span>Granted</span><span id="bc-count"></span></div>
                <table class="bc-table" id="bc-table" style="display:none">
                    <thead><tr><th>Player</th><th>Class</th><th class="bc-num">Credits</th><th>Date</th><th>By</th><th></th></tr></thead>
                    <tbody id="bc-tbody"></tbody>
                </table>
                <div class="bc-empty" id="bc-empty">Pick a credit above to see who has it.</div>
            </div>

            <!-- ───────── Credits (kingdom CREATE) ───────── -->
            <div class="bc-pane" id="bc-pane-credits" hidden>
                <div class="bc-new">
                    <div class="bc-list-label"><span>Define a new credit category</span></div>
                    <div class="bc-row">
                        <div class="bc-field bc-grow">
                            <label for="bc-new-name">Name</label>
                            <input type="text" id="bc-new-name" maxlength="100" placeholder="e.g. Being purple" autocomplete="off">
                        </div>
                        <div class="bc-field bc-sm">
                            <label for="bc-new-credits" title="The most a player may receive for this category in total; granters may split it across classes">Credits per player</label>
                            <input type="number" id="bc-new-credits" min="0.25" max="10" step="0.25" value="1">
                        </div>
                    </div>
                    <div class="bc-row" style="margin-bottom:0">
                        <div class="bc-field bc-grow">
                            <label for="bc-new-desc">Description (optional)</label>
                            <input type="text" id="bc-new-desc" maxlength="500" placeholder="What earns it" autocomplete="off">
                        </div>
                        <label class="bc-check"><input type="checkbox" id="bc-new-repeat"> Can be earned more than once</label>
                        <button type="button" class="bc-btn bc-btn-primary" id="bc-new-save" disabled>Save category</button>
                    </div>
                </div>
                <div class="bc-list-label">
                    <span id="bc-categories-label">This Kingdom's credit categories</span>
                    <label class="bc-check bc-check-sm"><input type="checkbox" id="bc-show-archived"> Show archived</label>
                </div>
                <table class="bc-table" id="bc-categories-table" style="display:none">
                    <thead><tr><th>Category</th><th class="bc-num">Per player</th><th>Earned</th><th class="bc-num">Holders</th><th class="bc-num bc-ork-only">Kingdoms</th><th></th></tr></thead>
                    <tbody id="bc-categories-tbody"></tbody>
                </table>
                <div class="bc-empty" id="bc-categories-empty">No credit categories defined yet.</div>

                <!-- Kingdom hosts only: the Amtgard-wide credits this kingdom may adopt. -->
                <div id="bc-ork-section" hidden>
                    <div class="bc-list-label" style="margin-top:18px"><span>Amtgard-wide credit categories</span></div>
                    <p class="bc-info">Credit categories defined by ORK admins for all of Amtgard. They reach this Kingdom's players only once this Kingdom adopts them; a parent Kingdom's adoption also covers its principalities. The amount is Amtgard's and cannot be changed here.</p>
                    <table class="bc-table" id="bc-ork-table" style="display:none">
                        <thead><tr><th>Category</th><th class="bc-num">Per player</th><th>Earned</th><th>Status</th><th></th></tr></thead>
                        <tbody id="bc-ork-tbody"></tbody>
                    </table>
                    <div class="bc-empty" id="bc-ork-empty">No Amtgard-wide credit categories have been defined.</div>
                </div>
            </div>

            <!-- ───────── Granters (kingdom CREATE) ───────── -->
            <div class="bc-pane" id="bc-pane-granters" hidden>
                <p class="bc-info" style="margin-top:0">Granters can hand out and remove this Kingdom's credits anywhere in the Kingdom without holding Kingdom rights: all of them, or one specific category. They cannot define new categories. Only officers with CREATE authority can appoint or remove them.</p>
                <div class="bc-list-label"><span>Granters</span><span id="bc-granters-count"></span></div>
                <ul class="bc-granter-list" id="bc-granter-list"></ul>
                <div class="bc-row" style="margin-bottom:0">
                    <div class="bc-field bc-grow">
                        <label for="bc-granter-search">Player</label>
                        <input type="text" id="bc-granter-search" autocomplete="off" placeholder="Search players to appoint…">
                        <input type="hidden" id="bc-granter-id">
                    </div>
                    <div class="bc-field bc-md">
                        <label for="bc-granter-scope">Can grant</label>
                        <select id="bc-granter-scope"><option value="0">All categories</option></select>
                    </div>
                    <div class="bc-field">
                        <label>&nbsp;</label>
                        <button type="button" class="bc-btn bc-btn-ghost" id="bc-granter-add" disabled><i class="fas fa-user-plus"></i> Add granter</button>
                    </div>
                </div>
            </div>

            <!-- ───────── Settings (kingdom CREATE) ───────── -->
            <div class="bc-pane" id="bc-pane-settings" hidden>
                <div class="bc-list-label" style="margin-top:0"><span>Credits from other Kingdoms</span></div>
                <label class="bc-setting" for="bc-outside-toggle">
                    <input type="checkbox" id="bc-outside-toggle">
                    <span><strong>Let this Kingdom's players receive bonus credits from other Kingdoms</strong><span class="bc-muted" id="bc-outside-status"></span></span>
                </label>
                <div class="bc-setting-text" id="bc-outside-desc"></div>
            </div>
        </div>
        <div class="bc-foot">
            <button type="button" class="bc-btn bc-btn-ghost" id="bc-done">Done</button>
        </div>
    </div>
</div>

<script>
(function () {
    if (window.__bcBound) return;
    window.__bcBound = true;
    var Cfg = window.OrkBcCfg || {};
    // The Amtgard host has scope id 0; a kingdom or park host must name one.
    if (!Cfg.uir || !Cfg.scopeType || (Cfg.scopeType !== 'ork' && !Cfg.scopeId)) return;

    function gid(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function fmtCredits(n) { n = parseFloat(n) || 0; return (Math.round(n * 100) / 100).toString(); }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
    var base = Cfg.uir + 'BonusCreditAjax/';
    var scopeQS = 'ScopeType=' + encodeURIComponent(Cfg.scopeType) + '&ScopeId=' + Cfg.scopeId;

    /* The confirm must stack ABOVE this modal (--z-modal). The park and kingdom
       pages' knConfirm (kn-confirm-overlay, --z-modal-top) does; so does the
       player page's pnConfirm. revised.js's orkConfirm overlay sits at 1200 and
       would open behind us, so it is only a fallback, and orkui.js's promise
       version (different signature: an options object) after that. */
    function confirmThen(title, message, okLabel, cb) {
        if (typeof window.knConfirm === 'function' && gid('kn-confirm-overlay')) { window.knConfirm(message, cb, title); return; }
        if (typeof window.pnConfirm === 'function') { window.pnConfirm({ title: title, message: message, confirmText: okLabel, danger: true }, cb); return; }
        var oc = window.orkConfirm;
        if (typeof oc === 'function' && oc.length >= 2) { oc(message, cb, { title: title, okLabel: okLabel, danger: true }); return; }
        if (typeof oc === 'function') {
            var p = oc({ title: title, body: message, confirmLabel: okLabel, danger: true });
            if (p && typeof p.then === 'function') { p.then(function (ok) { if (ok) cb(); }); return; }
        }
        if (window.confirm(message)) cb();
    }

    // categories: everything the server returned (live + archived when canDefine).
    // orkCredits: on a kingdom host, every live Amtgard-wide credit with this kingdom's adoption status.
    var categories = [], classes = [], granters = [], orkCredits = [], loaded = false, current = null, lastClass = 0, release = null;
    var canDefine = false, canAppoint = false;
    var isOrk = Cfg.scopeType === 'ork', isKingdom = Cfg.scopeType === 'kingdom';
    // outside: on a kingdom host, whether its players may receive other kingdoms' credits (own answer, inherited answer, who set it).
    var outside = null;

    function feedback(msg, ok) {
        var el = gid('bc-feedback');
        el.textContent = msg || '';
        el.className = 'bc-feedback' + (msg ? (ok ? ' bc-ok' : ' bc-err') : '');
    }
    function liveCategories() { return categories.filter(function (r) { return !r.ArchivedAt; }); }
    function categoryById(id) {
        for (var i = 0; i < categories.length; i++) if (String(categories[i].CategoryId) === String(id)) return categories[i];
        return null;
    }

    // ---- tabs ----
    function showTab(pane) {
        Array.prototype.forEach.call(document.querySelectorAll('#bc-tabs .bc-tab'), function (t) { t.classList.toggle('bc-tab-active', t.getAttribute('data-pane') === pane); });
        Array.prototype.forEach.call(document.querySelectorAll('#bc-overlay .bc-pane'), function (p) { p.hidden = p.id !== pane; });
        feedback('');
        try { jQuery('#bc-player, #bc-granter-search').autocomplete('close'); } catch (e) {}
    }
    gid('bc-tabs').addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('.bc-tab') : null;
        if (t) showTab(t.getAttribute('data-pane'));
    });

    // ---- open / close ----
    window.bcOpen = function () {
        var ov = gid('bc-overlay');
        gid('bc-sub').textContent = (Cfg.scopeName || '') + ' — credits that count toward class levels, not attendance.' +
            (Cfg.scopeType === 'park' ? ' Your Kingdom defines them; you hand them out.' : '') +
            (isOrk ? ' Each Kingdom chooses whether to adopt them.' : '');
        ov.classList.add('bc-open');
        ov.setAttribute('aria-hidden', 'false');
        feedback('');
        if (window.orkDialogTrap) { release = window.orkDialogTrap(ov, { onEscape: bcClose }); }
        if (!loaded) { load(); } else { showTab('bc-pane-grant'); gid('bc-category').focus(); }
    };
    function bcClose() {
        var ov = gid('bc-overlay');
        ov.classList.remove('bc-open');
        ov.setAttribute('aria-hidden', 'true');
        if (release) { release(); release = null; }
        try { jQuery('#bc-player, #bc-granter-search').autocomplete('close'); } catch (e) {}
    }
    gid('bc-close').addEventListener('click', bcClose);
    gid('bc-done').addEventListener('click', bcClose);
    (function () {
        var ov = gid('bc-overlay'), down = false;
        ov.addEventListener('mousedown', function (e) { down = (e.target === this); });
        ov.addEventListener('click', function (e) { if (down && e.target === this) bcClose(); down = false; });
    })();
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && gid('bc-overlay').classList.contains('bc-open') && !release) bcClose();
    });

    // ---- load ----
    function load(keep) {
        // Kingdom CREATE always gets archived credits too: the Credits tab lists them behind a toggle.
        jQuery.getJSON(base + 'options&' + scopeQS + (Cfg.scopeType !== 'park' ? '&archived=1' : ''), function (r) {
            if (!r || r.status !== 0) { feedback((r && r.error) || 'Could not load credits.', false); return; }
            categories = r.categories || [];
            classes = r.classes || [];
            granters = r.granters || [];
            orkCredits = r.orkCredits || [];
            outside = r.outside || null;
            canDefine = !!r.canDefine;
            canAppoint = !!r.canAppoint;
            loaded = true;
            gid('bc-tabs').hidden = !(canDefine || canAppoint);
            gid('bc-tab-credits').hidden = !canDefine;
            gid('bc-tab-granters').hidden = !canAppoint;
            gid('bc-tab-settings').hidden = !(canDefine && isKingdom);
            gid('bc-categories-label').textContent = isOrk ? 'Amtgard-wide credit categories' : 'This Kingdom\'s credit categories';
            gid('bc-ork-section').hidden = isOrk || !canDefine;
            fillClasses();
            fillGrantPicker(keep);
            renderCategories();
            renderOrkCredits();
            renderGranters();
            renderOutside();
            if (!keep) { showTab('bc-pane-grant'); gid('bc-category').focus(); }
        }).fail(function () { feedback('Could not load credits.', false); });
    }
    function fillClasses() {
        var sel = gid('bc-class'), html = '<option value="">— class —</option>';
        classes.forEach(function (c) { html += '<option value="' + c.ClassId + '">' + esc(c.ClassName) + '</option>'; });
        sel.innerHTML = html;
        if (lastClass) sel.value = String(lastClass);
    }

    // ================= Grant tab =================
    function fillGrantPicker(selectValue) {
        var sel = gid('bc-category'), live = liveCategories(), html = '';
        if (!live.length) html += '<option value="">' + (canDefine ? 'No credit categories yet — see the Categories tab' : 'No credit categories set up by your Kingdom yet') + '</option>';
        live.forEach(function (rs) {
            // Name the defining org when it is not this page's own: a park sees its
            // kingdom's credits, and any host sees adopted Amtgard-wide ones.
            var from = (rs.ScopeType === 'ork' && !isOrk) || (Cfg.scopeType === 'park' && rs.ScopeType === 'kingdom') ? ', ' + esc(rs.ScopeName || 'Amtgard') : '';
            html += '<option value="' + rs.CategoryId + '">' + esc(rs.Name) + ' (' + plural(fmtCredits(rs.DefaultCredits) * 1, 'credit', 'credits') + from + ')</option>';
        });
        sel.innerHTML = html;
        var want = selectValue && categoryById(selectValue) && !categoryById(selectValue).ArchivedAt ? String(selectValue) : (live.length ? String(live[0].CategoryId) : '');
        sel.value = want;
        onCategoryChange();
    }
    function onCategoryChange() {
        var v = gid('bc-category').value;
        current = v ? categoryById(v) : null;
        gid('bc-grant-row').style.display = current ? '' : 'none';
        var info = gid('bc-category-info');
        if (current) {
            var bits = [];
            if (current.Description) bits.push(esc(current.Description).replace(/[.!?]?$/, '.'));
            bits.push(current.Repeatable
                ? 'Can be earned more than once; up to ' + fmtCredits(current.DefaultCredits) + ' per grant.'
                : 'Up to ' + fmtCredits(current.DefaultCredits) + ' per player in total, in any split across classes.');
            info.innerHTML = bits.join(' · ');
            info.hidden = false;
            // A player may already be picked: their allowance for THIS category.
            if (gid('bc-player-id').value) loadAllowance(); else setAmountBounds(null);
            loadGranted(current.CategoryId);
        } else {
            info.hidden = true;
            renderGranted([]);
            gid('bc-empty').textContent = canDefine ? 'Define a credit category on the Categories tab, then grant it here.'
                : (Cfg.scopeType === 'park' ? 'A Kingdom officer with CREATE authority sets up credit categories on the Kingdom page.' : 'No credits to grant yet.');
        }
        updateAdd();
    }
    gid('bc-category').addEventListener('change', onCategoryChange);

    function loadGranted(categoryId) {
        gid('bc-empty').textContent = 'Loading…';
        var where = Cfg.scopeType === 'park' ? '&ParkId=' + Cfg.scopeId : (Cfg.scopeType === 'kingdom' ? '&KingdomId=' + Cfg.scopeId : '');
        jQuery.getJSON(base + 'category_credits&CategoryId=' + categoryId + where, function (r) {
            if (!current || String(current.CategoryId) !== String(categoryId)) return;
            if (!r || r.status !== 0) { feedback((r && r.error) || 'Could not load.', false); return; }
            renderGranted(r.credits || []);
        });
    }
    function rowHtml(c, justAdded) {
        return '<tr data-id="' + c.BonusCreditId + '" data-mid="' + c.MundaneId + '"' + (justAdded ? ' class="bc-just-added"' : '') + '>' +
            '<td><a href="' + Cfg.uir + 'Player/profile/' + c.MundaneId + '">' + esc(c.Persona) + '</a>' + awayTag(c) + '</td>' +
            '<td>' + esc(c.ClassName) + '</td>' +
            '<td class="bc-num">' + fmtCredits(c.Credits) + '</td>' +
            '<td class="bc-muted">' + esc(c.Date) + '</td>' +
            '<td class="bc-muted">' + esc(c.GrantedByPersona) + '</td>' +
            '<td class="bc-num"><button type="button" class="bc-link bc-link-danger bc-revoke" data-id="' + c.BonusCreditId + '" title="Remove this credit">Remove</button></td>' +
            '</tr>';
    }
    // A holder whose home kingdom is not this page's: say where they are from.
    function awayTag(c) {
        if (isOrk || !Cfg.kingdomId || !c.PlayerKingdomId || String(c.PlayerKingdomId) === String(Cfg.kingdomId)) return '';
        return '<span class="bc-away-tag" title="' + esc(c.PlayerKingdomName || '') + '">' + esc(c.PlayerKingdomAbbr || c.PlayerKingdomName || 'other Kingdom') + '</span>';
    }
    function setCount(n) {
        gid('bc-count').textContent = n ? plural(n, 'player', 'players') : '';
        gid('bc-table').style.display = n ? '' : 'none';
        gid('bc-empty').style.display = n ? 'none' : '';
    }
    function renderGranted(list) {
        var tb = gid('bc-tbody'), html = '';
        list.forEach(function (c) { html += rowHtml(c, false); });
        tb.innerHTML = html;
        setCount(list.length);
        gid('bc-empty').textContent = Cfg.scopeType === 'park' ? 'None of this park\'s players has this credit yet.'
            : (Cfg.scopeType === 'kingdom' ? 'None of this Kingdom\'s players has this credit yet.' : 'Nobody has this credit yet.');
    }
    gid('bc-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.bc-revoke') : null;
        if (!btn) return;
        var tr = btn.closest('tr'), id = btn.getAttribute('data-id');
        var who = tr.querySelector('a') ? tr.querySelector('a').textContent : 'this player';
        confirmThen('Remove this credit?', who + ' loses the “' + (current ? current.Name : 'bonus') + '” credit. It can be granted again.', 'Remove', function () {
            btn.disabled = true;
            jQuery.post(base + 'revoke', { BonusCreditId: id }, function (r) {
                if (!r || r.status !== 0) { btn.disabled = false; feedback((r && r.error) || 'Could not remove it.', false); return; }
                var mid = tr.getAttribute('data-mid');
                tr.remove();
                setCount(gid('bc-tbody').querySelectorAll('tr').length);
                if (current) { current.Granted = Math.max(0, (current.Granted || 1) - 1); renderCategories(); }
                // The removed credit goes back into that player's allowance: refresh the hint and cap if they are the one picked.
                if (mid && gid('bc-player-id').value === mid) loadAllowance();
                feedback('Removed ' + who + '’s credit.', true);
            }, 'json').fail(function () { btn.disabled = false; feedback('Request failed.', false); });
        });
    });

    // The amount box: capped at what the picked player has left of the allowance
    // (or the per-grant maximum for a repeatable category), prefilled with it.
    function setAmountBounds(info) {
        var box = gid('bc-credits'), hint = gid('bc-allowance');
        if (!current) { hint.hidden = true; return; }
        var cap = current.DefaultCredits;
        if (info && info.Remaining !== null && info.Remaining !== undefined) cap = info.Remaining;
        box.max = cap > 0 ? cap : 0.25;
        box.value = cap > 0 ? fmtCredits(cap) : '';
        box.disabled = cap <= 0;
        if (!info) { hint.hidden = true; return; }
        var name = gid('bc-player').value || 'This player';
        if (info.Repeatable) {
            hint.textContent = name + ' has ' + fmtCredits(info.Held) + ' so far for this category; it can be earned more than once, up to ' + fmtCredits(info.Allowance) + ' per grant.';
        } else if (cap <= 0) {
            hint.textContent = name + ' already has the full ' + fmtCredits(info.Allowance) + ' for this category.';
        } else {
            hint.textContent = name + ' has ' + fmtCredits(info.Held) + ' of ' + fmtCredits(info.Allowance) + ' for this category; ' + fmtCredits(cap) + ' left to give.';
        }
        hint.hidden = false;
        updateAdd();
    }
    function loadAllowance() {
        var mid = gid('bc-player-id').value;
        if (!current || !mid) { setAmountBounds(null); return; }
        var cid = current.CategoryId;
        jQuery.getJSON(base + 'allowance&CategoryId=' + cid + '&MundaneId=' + mid, function (r) {
            if (!current || current.CategoryId !== cid || gid('bc-player-id').value !== mid) return;
            if (r && r.status === 0) setAmountBounds(r.allowance);
        });
    }

    // Player search: all of Amtgard, nearest first. A park host lists its own
    // members, then its kingdom, then everyone; a kingdom host its own players
    // first. The kingdom's credits may cross into a kingdom that accepts
    // outside credits, and the server refuses the rest with a message that
    // says why. An Amtgard host searches everyone; the server still refuses a
    // player whose kingdom has not adopted the credit.
    function searchUrl(term) {
        if (isOrk) return Cfg.uir + 'KingdomAjax/playersearch/0&scope=all&include_inactive=1&q=' + encodeURIComponent(term);
        if (isKingdom) return Cfg.uir + 'KingdomAjax/playersearch/' + Cfg.kingdomId + '&scope=all&include_inactive=1&q=' + encodeURIComponent(term);
        return Cfg.uir + 'ParkAjax/park/' + Cfg.scopeId + '/playersearch&scope=all&prioritize=1&include_inactive=1&q=' + encodeURIComponent(term);
    }
    function acItems(list, withInactive) {
        return jQuery.map(list || [], function (v) {
            var where = (v.KAbbr && v.PAbbr) ? v.KAbbr + ':' + v.PAbbr : (v.ParkName || '');
            return { label: v.Persona + (where ? ' — ' + where : '') + (withInactive && parseInt(v.Active, 10) === 0 ? ' (inactive)' : ''), name: v.Persona, value: v.MundaneId };
        });
    }
    jQuery('#bc-player').autocomplete({
        minLength: 2, delay: 150, classes: { 'ui-autocomplete': 'bc-ac' },
        source: function (req, res) { jQuery.getJSON(searchUrl(req.term), function (list) { res(acItems(list, true)); }).fail(function () { res([]); }); },
        focus: function (e) { e.preventDefault(); },
        select: function (e, ui) {
            e.preventDefault();
            // The Enter that picked this name must not also Add (see keydown
            // below). The flag lives only for the rest of this event dispatch:
            // a mouse pick sets it too, and it must not swallow a later Enter.
            pickedByKey = true; setTimeout(function () { pickedByKey = false; }, 0);
            gid('bc-player').value = ui.item.name;
            gid('bc-player-id').value = ui.item.value;
            updateAdd();
            loadAllowance();
            // Picking a name only fills the field. Move on to the class if none is
            // set; otherwise stay put so the next Enter is a deliberate Add.
            if (!gid('bc-class').value) gid('bc-class').focus();
        }
    });
    gid('bc-player').addEventListener('input', function () { gid('bc-player-id').value = ''; setAmountBounds(null); updateAdd(); });
    gid('bc-class').addEventListener('change', updateAdd);
    gid('bc-credits').addEventListener('input', updateAdd);
    function updateAdd() {
        var box = gid('bc-credits'), amt = parseFloat(box.value);
        var ok = current && gid('bc-player-id').value && gid('bc-class').value && !box.disabled && amt >= 0.25 && amt <= parseFloat(box.max) + 0.0001;
        gid('bc-add').disabled = !ok;
    }
    gid('bc-add').addEventListener('click', function () {
        var btn = this, mid = gid('bc-player-id').value, cls = gid('bc-class').value, amt = gid('bc-credits').value;
        if (!current || !mid) return;
        btn.disabled = true;
        jQuery.post(base + 'grant', { CategoryId: current.CategoryId, MundaneId: mid, ClassId: cls, Credits: amt, ScopeType: Cfg.scopeType, ScopeId: Cfg.scopeId }, function (r) {
            if (!r || r.status !== 0) { updateAdd(); feedback((r && r.error) || 'Could not add the credit.', false); return; }
            lastClass = parseInt(cls, 10);
            gid('bc-tbody').insertAdjacentHTML('afterbegin', rowHtml(r.credit, true));
            setCount(gid('bc-tbody').querySelectorAll('tr').length);
            current.Granted = (current.Granted || 0) + 1; renderCategories();
            var left = (r.remaining === null || r.remaining === undefined) ? '' : (r.remaining > 0 ? ' ' + fmtCredits(r.remaining) + ' left to give.' : ' That is the full allowance.');
            feedback('Added ' + fmtCredits(r.credit.Credits) + ' ' + r.credit.ClassName + ' credit for ' + r.credit.Persona + '.' + left, true);
            // Keep the player if some allowance remains, so a split ("2 Bard, then 2 Healer") is two quick adds.
            if (r.remaining === null || r.remaining === undefined || r.remaining > 0) { loadAllowance(); gid('bc-class').focus(); }
            else { gid('bc-player').value = ''; gid('bc-player-id').value = ''; setAmountBounds(null); updateAdd(); gid('bc-player').focus(); }
        }, 'json').fail(function () { btn.disabled = false; feedback('Request failed.', false); });
    });
    // Enter adds only when a player is already chosen and the suggestion list is
    // closed. While the list is open, Enter belongs to the autocomplete (it picks
    // the highlighted name); without this check the same keypress also fired Add.
    // jQuery UI's own keydown handler was bound first, so by the time this ran
    // it had already picked the highlighted name, closed the list and (via
    // select -> updateAdd) enabled Add: the "list is open" test passed and the
    // same Enter granted the credit. Three independent tells, any one of which
    // stops that: the list is still open (capture phase runs this first in
    // current browsers), the select handler flagged this keypress, or jQuery UI
    // already consumed it (it preventDefaults the Enter that picks an item).
    var pickedByKey = false;
    gid('bc-player').addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var menuOpen = false;
        try { menuOpen = jQuery('#bc-player').autocomplete('widget').is(':visible'); } catch (err) {}
        if (menuOpen || pickedByKey || e.defaultPrevented) return;
        if (!gid('bc-add').disabled) { e.preventDefault(); gid('bc-add').click(); }
    }, true);

    // ================= Credits tab (kingdom CREATE) =================
    function renderCategories() {
        if (!canDefine) return;
        var showArchived = gid('bc-show-archived').checked;
        // Only the credits this host defines: a kingdom's adopted Amtgard-wide ones live in the section below.
        var list = categories.filter(function (r) { return (showArchived || !r.ArchivedAt) && r.ScopeType === (isOrk ? 'ork' : 'kingdom'); });
        var tb = gid('bc-categories-tbody'), html = '';
        Array.prototype.forEach.call(document.querySelectorAll('#bc-categories-table .bc-ork-only'), function (el) { el.style.display = isOrk ? '' : 'none'; });
        list.forEach(function (rs) {
            var archived = !!rs.ArchivedAt;
            html += '<tr data-id="' + rs.CategoryId + '"' + (archived ? ' class="bc-archived"' : '') + '>' +
                '<td>' + esc(rs.Name) + (archived ? '<span class="bc-archived-tag">archived</span>' : '') +
                    (rs.Description ? '<div class="bc-muted">' + esc(rs.Description) + '</div>' : '') + '</td>' +
                '<td class="bc-num">' + fmtCredits(rs.DefaultCredits) + '</td>' +
                '<td class="bc-muted">' + (rs.Repeatable ? 'Repeatable' : 'Once') + '</td>' +
                '<td class="bc-num">' + (rs.Granted || 0) + '</td>' +
                (isOrk ? '<td class="bc-num" title="Kingdoms that have adopted it">' + (rs.Adoptions || 0) + '</td>' : '') +
                '<td class="bc-num">' + (archived
                    ? '<button type="button" class="bc-link bc-category-restore" data-id="' + rs.CategoryId + '">Restore</button>'
                    : '<button type="button" class="bc-link bc-category-grant" data-id="' + rs.CategoryId + '">Grant</button> &nbsp; ' +
                      '<button type="button" class="bc-link bc-link-danger bc-category-archive" data-id="' + rs.CategoryId + '">Archive</button>') + '</td>' +
                '</tr>';
        });
        tb.innerHTML = html;
        gid('bc-categories-table').style.display = list.length ? '' : 'none';
        gid('bc-categories-empty').style.display = list.length ? 'none' : '';
        gid('bc-categories-empty').textContent = categories.length && !showArchived && !list.length ? 'Every category is archived. Tick “Show archived” to restore one.' : 'No credit categories defined yet.';
        fillGranterScope();
    }

    // Kingdom hosts: the Amtgard-wide credits, with Adopt / Withdraw.
    function renderOrkCredits() {
        if (isOrk || !canDefine) return;
        var tb = gid('bc-ork-tbody'), html = '';
        orkCredits.forEach(function (rs) {
            var status = rs.Adopted ? 'Adopted' + (rs.AdoptedByPersona ? ' by ' + esc(rs.AdoptedByPersona) : '') + (rs.AdoptedAt ? ' <span class="bc-muted">' + esc(String(rs.AdoptedAt).slice(0, 10)) + '</span>' : '')
                : (rs.InheritedFrom ? 'Adopted by ' + esc(rs.InheritedFrom) + ' <span class="bc-muted">(covers this principality)</span>' : '<span class="bc-muted">Not adopted</span>');
            html += '<tr data-id="' + rs.CategoryId + '">' +
                '<td>' + esc(rs.Name) + (rs.Description ? '<div class="bc-muted">' + esc(rs.Description) + '</div>' : '') + '</td>' +
                '<td class="bc-num">' + fmtCredits(rs.DefaultCredits) + '</td>' +
                '<td class="bc-muted">' + (rs.Repeatable ? 'Repeatable' : 'Once') + '</td>' +
                '<td>' + status + '</td>' +
                '<td class="bc-num">' + (rs.Adopted
                    ? '<button type="button" class="bc-link bc-link-danger bc-ork-withdraw" data-id="' + rs.CategoryId + '">Withdraw</button>'
                    : '<button type="button" class="bc-link bc-ork-adopt" data-id="' + rs.CategoryId + '">Adopt</button>') + '</td>' +
                '</tr>';
        });
        tb.innerHTML = html;
        gid('bc-ork-table').style.display = orkCredits.length ? '' : 'none';
        gid('bc-ork-empty').style.display = orkCredits.length ? 'none' : '';
    }
    gid('bc-ork-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('button[data-id]') : null;
        if (!btn) return;
        var rs = null;
        orkCredits.forEach(function (x) { if (String(x.CategoryId) === btn.getAttribute('data-id')) rs = x; });
        if (!rs) return;
        var adopt = btn.classList.contains('bc-ork-adopt');
        var go = function () {
            btn.disabled = true;
            jQuery.post(base + (adopt ? 'adopt' : 'withdraw'), { CategoryId: rs.CategoryId, KingdomId: Cfg.kingdomId }, function (r) {
                if (!r || r.status !== 0) { btn.disabled = false; feedback((r && r.error) || 'Could not update the adoption.', false); return; }
                orkCredits = r.orkCredits || orkCredits;
                if (r.categories) categories = r.categories;
                renderOrkCredits();
                renderCategories();
                fillGrantPicker(current ? current.CategoryId : null);
                feedback((adopt ? 'Adopted the “' : 'Withdrew the “') + rs.Name + '” category' + (adopt ? '. Its credits can now be granted to this Kingdom\'s players.' : '. Credits already granted stay.'), true);
            }, 'json').fail(function () { btn.disabled = false; feedback('Request failed.', false); });
        };
        if (adopt) {
            go();
        } else {
            confirmThen('Withdraw this category?', 'The “' + rs.Name + '” category will no longer be offered in this Kingdom. Credits already granted stay. You can adopt it again later.', 'Withdraw', go);
        }
    });
    gid('bc-show-archived').addEventListener('change', renderCategories);
    gid('bc-new-save').addEventListener('click', function () {
        var btn = this, name = gid('bc-new-name').value.trim();
        if (!name) { updateNewSave(); gid('bc-new-name').focus(); return; }
        btn.disabled = true;
        jQuery.post(base + 'category_create', {
            ScopeType: Cfg.scopeType, ScopeId: Cfg.scopeId,
            Name: name, Description: gid('bc-new-desc').value.trim(),
            DefaultCredits: gid('bc-new-credits').value, Repeatable: gid('bc-new-repeat').checked ? 1 : 0
        }, function (r) {
            updateNewSave();
            if (!r || r.status !== 0) { feedback((r && r.error) || 'Could not save the category.', false); return; }
            var rs = r.category, idx = -1;
            categories.forEach(function (x, i) { if (x.CategoryId === rs.CategoryId) idx = i; });
            if (idx >= 0) categories[idx] = rs; else categories.push(rs);
            categories.sort(function (a, b) { return a.Name.localeCompare(b.Name); });
            gid('bc-new-name').value = ''; gid('bc-new-desc').value = ''; gid('bc-new-credits').value = '1'; gid('bc-new-repeat').checked = false;
            updateNewSave();
            renderCategories();
            fillGrantPicker(rs.CategoryId);
            feedback((r.restored ? 'Restored the “' : 'Created the “') + rs.Name + '” category. Use Grant on its row, or the Grant tab, to add players.', true);
        }, 'json').fail(function () { btn.disabled = false; feedback('Request failed.', false); });
    });
    // Save is live only once there is a name; Enter in the name field saves when it is.
    function updateNewSave() { gid('bc-new-save').disabled = !gid('bc-new-name').value.trim(); }
    gid('bc-new-name').addEventListener('input', updateNewSave);
    gid('bc-new-name').addEventListener('keydown', function (e) { if (e.key === 'Enter' && !gid('bc-new-save').disabled) { e.preventDefault(); gid('bc-new-save').click(); } });
    gid('bc-categories-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('button[data-id]') : null;
        if (!btn) return;
        var rs = categoryById(btn.getAttribute('data-id'));
        if (!rs) return;
        if (btn.classList.contains('bc-category-grant')) {
            fillGrantPicker(rs.CategoryId);
            showTab('bc-pane-grant');
            gid('bc-player').focus();
            return;
        }
        var archive = btn.classList.contains('bc-category-archive');
        var go = function () {
            jQuery.post(base + (archive ? 'category_archive' : 'category_restore'), { CategoryId: rs.CategoryId }, function (r) {
                if (!r || r.status !== 0) { feedback((r && r.error) || 'Could not update the category.', false); return; }
                categories.forEach(function (x, i) { if (x.CategoryId === rs.CategoryId) categories[i] = r.category; });
                renderCategories();
                fillGrantPicker(current ? current.CategoryId : null);
                feedback((archive ? 'Archived the “' : 'Restored the “') + rs.Name + '” category.' + (archive ? ' It is no longer offered; players who hold its credits keep them.' : ''), true);
            }, 'json').fail(function () { feedback('Request failed.', false); });
        };
        if (archive) {
            confirmThen('Archive this category?', '“' + rs.Name + '” will no longer be offered to parks or players. ' +
                (rs.Granted ? plural(rs.Granted, 'player keeps', 'players keep') + ' the credits already granted. ' : '') + 'You can restore the category later.', 'Archive', go);
        } else {
            go();
        }
    });

    // ================= Granters tab (kingdom CREATE) =================
    function fillGranterScope() {
        var sel = gid('bc-granter-scope'), keep = sel.value, opts = '<option value="0">All categories</option>';
        liveCategories().forEach(function (rs) { opts += '<option value="' + rs.CategoryId + '">' + esc(rs.Name) + ' only</option>'; });
        sel.innerHTML = opts;
        sel.value = keep;
        if (sel.value !== keep) sel.value = '0';
    }
    function renderGranters() {
        if (!canAppoint) return;
        var ul = gid('bc-granter-list'), html = '';
        granters.forEach(function (m) {
            var scope = m.AllCredits ? 'all categories' : '“' + esc(m.CategoryName) + '” only';
            var dormant = m.CategoryArchived ? '<span class="bc-archived-tag" title="This category is archived; the appointment applies again if it is restored">archived</span>' : '';
            // The park is where the granter plays, not where they may grant: that is the whole kingdom.
            html += '<li data-id="' + m.GranterId + '"><a href="' + Cfg.uir + 'Player/profile/' + m.MundaneId + '">' + esc(m.Persona) + '</a>' +
                    (m.Park ? '<span class="bc-muted" style="flex:0 0 auto">(' + esc(m.Park) + ')</span>' : '') +
                    '<span class="bc-muted">can grant ' + scope + ' across the Kingdom' + dormant + (m.AddedByPersona ? ' · added by ' + esc(m.AddedByPersona) : '') + '</span>' +
                    '<button type="button" class="bc-link bc-link-danger bc-granter-remove" data-id="' + m.GranterId + '" data-who="' + esc(m.Persona) + '" data-scope="' + scope + '">Remove</button></li>';
        });
        ul.innerHTML = html || '<li class="bc-empty">No granters yet. Kingdom officers can still grant credits.</li>';
        gid('bc-granters-count').textContent = granters.length ? plural(granters.length, 'granter', 'granters') : '';
        fillGranterScope();
    }
    jQuery('#bc-granter-search').autocomplete({
        minLength: 2, delay: 150, classes: { 'ui-autocomplete': 'bc-ac' },
        source: function (req, res) {
            jQuery.getJSON(Cfg.uir + 'KingdomAjax/playersearch/' + Cfg.kingdomId + '&scope=own&q=' + encodeURIComponent(req.term), function (list) { res(acItems(list, false)); }).fail(function () { res([]); });
        },
        focus: function (e) { e.preventDefault(); },
        select: function (e, ui) {
            e.preventDefault();
            gid('bc-granter-search').value = ui.item.name;
            gid('bc-granter-id').value = ui.item.value;
            gid('bc-granter-add').disabled = false;
        }
    });
    gid('bc-granter-search').addEventListener('input', function () { gid('bc-granter-id').value = ''; gid('bc-granter-add').disabled = true; });
    gid('bc-granter-add').addEventListener('click', function () {
        var btn = this, mid = gid('bc-granter-id').value, who = gid('bc-granter-search').value;
        var scopeSel = gid('bc-granter-scope'), rid = scopeSel.value, scopeText = scopeSel.options[scopeSel.selectedIndex] ? scopeSel.options[scopeSel.selectedIndex].text : 'all categories';
        if (!mid) return;
        btn.disabled = true;
        jQuery.post(base + 'granter_add', { KingdomId: Cfg.kingdomId, MundaneId: mid, CategoryId: rid }, function (r) {
            if (!r || r.status !== 0) { btn.disabled = false; feedback((r && r.error) || 'Could not add the granter.', false); return; }
            granters = r.granters || [];
            renderGranters();
            gid('bc-granter-search').value = ''; gid('bc-granter-id').value = ''; scopeSel.value = '0';
            feedback(who + ' can now grant ' + (rid === '0' ? 'this Kingdom\'s credits.' : scopeText.replace(/ only$/, '') + '.'), true);
        }, 'json').fail(function () { btn.disabled = false; feedback('Request failed.', false); });
    });
    gid('bc-granter-list').addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.bc-granter-remove') : null;
        if (!btn) return;
        var rowId = btn.getAttribute('data-id'), who = btn.getAttribute('data-who') || 'this player', scope = btn.getAttribute('data-scope') || 'all categories';
        confirmThen('Remove this granter?', who + ' will no longer be able to grant ' + scope + ' in this Kingdom. Credits they already granted stay.', 'Remove', function () {
            btn.disabled = true;
            jQuery.post(base + 'granter_remove', { KingdomId: Cfg.kingdomId, GranterId: rowId }, function (r) {
                if (!r || r.status !== 0) { btn.disabled = false; feedback((r && r.error) || 'Could not remove the granter.', false); return; }
                granters = r.granters || [];
                renderGranters();
                feedback('Removed ' + who + ' as a granter.', true);
            }, 'json').fail(function () { btn.disabled = false; feedback('Request failed.', false); });
        });
    });

    // ================= Settings tab (kingdom CREATE) =================
    // One yes/no: may other kingdoms' bonus credits reach this kingdom's
    // players? The Grant tab's hint reads the same answer.
    function renderOutside() {
        if (isOrk) return;
        var name = Cfg.scopeName || 'this Kingdom';
        var hint = gid('bc-outside-hint');
        hint.hidden = false;
        hint.innerHTML = 'Players of other Kingdoms can be searched too (type their Kingdom\'s abbreviation first to narrow, e.g. <code>NB: name</code>). ' +
            (isKingdom ? 'Only this Kingdom\'s own categories can go to them' : 'Only your Kingdom\'s own categories can go to them') +
            ', and only if their Kingdom accepts credits from other Kingdoms.';
        if (!isKingdom || !canDefine || !outside) return;
        var box = gid('bc-outside-toggle'), status = gid('bc-outside-status');
        var on = !!outside.Effective;
        box.checked = on;
        box.disabled = false;
        var who = (outside.UpdatedByPersona ? ' · set by ' + esc(outside.UpdatedByPersona) : '') + (outside.UpdatedAt ? ' ' + esc(String(outside.UpdatedAt).slice(0, 10)) : '');
        var s = '';
        if (outside.Source === 'default') {
            s = 'On by default — no one has changed it yet.';
        } else if (outside.Source === 'inherited') {
            s = (on ? 'On' : 'Off') + ' · inherited from ' + esc(outside.InheritedFrom) + who + '. This principality has not chosen for itself; flipping the switch here makes its own choice.';
        } else {
            s = (on ? 'On' : 'Off') + who;
        }
        status.innerHTML = s;
        gid('bc-outside-desc').innerHTML =
            '<p><strong>What it does.</strong> Credits from other Kingdoms are accepted unless a Kingdom turns this off. While it is on, another Kingdom that offers a bonus credit can grant that credit to players whose home is in ' + esc(name) + '. ' +
            'Their Kingdom officers, park officers or granters do the granting, from their own Kingdom or park pages; the credit counts toward the player\'s class levels exactly like one of ours. It is never attendance.</p>' +
            '<p><strong>What it does not do.</strong> It does not let anyone here hand out other Kingdoms\' credits, and it does not change who can grant ours. Amtgard-wide credits are separate: those reach our players only when adopted on the Categories tab.</p>' +
            '<p><strong>When it is off</strong>, only ' + esc(name) + '\'s own credits and the Amtgard-wide ones it has adopted can reach its players. Turning it off stops new grants from other Kingdoms; credits already given stay. A principality follows its parent Kingdom\'s choice until it makes its own here.</p>';
    }
    gid('bc-outside-toggle').addEventListener('change', function () {
        var box = this, want = box.checked;
        box.disabled = true;
        jQuery.post(base + 'outside_set', { KingdomId: Cfg.kingdomId, Accept: want ? 1 : 0 }, function (r) {
            box.disabled = false;
            if (!r || r.status !== 0) { box.checked = !want; feedback((r && r.error) || 'Could not save the setting.', false); return; }
            outside = r.outside || outside;
            renderOutside();
            feedback(want ? 'Other Kingdoms can now grant their bonus credits to ' + (Cfg.scopeName || 'this Kingdom') + '\'s players.'
                          : 'Other Kingdoms can no longer grant bonus credits to ' + (Cfg.scopeName || 'this Kingdom') + '\'s players. Credits already given stay.', true);
        }, 'json').fail(function () { box.disabled = false; box.checked = !want; feedback('Request failed.', false); });
    });
})();
</script>
