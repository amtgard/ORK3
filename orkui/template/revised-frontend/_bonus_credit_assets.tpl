<?php
/**
 * Add Bonus Credits modal: shared by the park and kingdom pages.
 *
 * Bonus credits are class credits that are NOT attendance (class.BonusCredit.php):
 * they count toward levels and nowhere else. The modal mirrors Enter Attendance,
 * but the identity of a credit is the THING it is for, not a date: the officer
 * picks a reason the org already offers (or creates one), then adds players
 * against it. The list under the form is everyone who already holds that credit.
 *
 * Credits are DEFINED by a kingdom and HANDED OUT by its parks: on a kingdom
 * page the modal can create them; on a park page it only picks from the
 * kingdom's list and grants to the park's own players.
 *
 * The host sets window.OrkBcCfg before including this file:
 *   { uir, scopeType: 'park'|'kingdom', scopeId, scopeName, kingdomId }
 * and offers a button that calls bcOpen(). Needs jQuery + jQuery UI autocomplete,
 * which both hosts already load for Enter Attendance / player search.
 */
?>
<style>
/* --z-modal, like Enter Attendance: the shared confirm dialog sits at --z-modal-top, above this. */
#bc-overlay { position:fixed; inset:0; z-index:var(--z-modal, 10100); background:rgba(0,0,0,.5); display:none; align-items:flex-start; justify-content:center; padding:40px 16px; overflow:auto; }
#bc-overlay.bc-open { display:flex; }
.bc-box { width:100%; max-width:720px; background:var(--ork-card-bg,#fff); color:var(--ork-text,#1a202c); border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,.3); display:flex; flex-direction:column; max-height:calc(100vh - 80px); }
.bc-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:16px 20px 12px; border-bottom:1px solid var(--ork-divider,#e2e8f0); }
/* The hosts style bare h3s (the kingdom page boxes them); this one is a dialog title. */
#theme_container .bc-title, .bc-title { margin:0; padding:0; font-size:16px; font-weight:700; background:none; border:0; box-shadow:none; text-shadow:none; color:inherit; }
.bc-sub { margin:2px 0 0; font-size:12px; color:var(--ork-text-muted,#718096); }
.bc-close { background:none; border:0; font-size:22px; line-height:1; color:var(--ork-text-lighter,#a0aec0); cursor:pointer; padding:0 4px; }
.bc-close:hover { color:var(--ork-text,#4a5568); }
.bc-body { padding:16px 20px; overflow-y:auto; flex:1; }
.bc-foot { padding:12px 20px; border-top:1px solid var(--ork-divider,#e2e8f0); display:flex; justify-content:flex-end; gap:8px; }
.bc-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:12px; }
.bc-field { display:flex; flex-direction:column; gap:3px; min-width:0; }
.bc-field label { font-size:11px; font-weight:600; color:var(--ork-text-muted,#718096); text-transform:uppercase; letter-spacing:.03em; }
.bc-field input[type=text], .bc-field input[type=number], .bc-field select, .bc-field textarea { font:inherit; font-size:14px; padding:7px 10px; border:1px solid var(--ork-input-border,#cbd5e0); border-radius:6px; background:var(--ork-input-bg,#fff); color:inherit; min-height:36px; box-sizing:border-box; }
.bc-amount { min-height:36px; display:flex; align-items:center; padding:7px 10px; border:1px solid transparent; font-size:14px; font-weight:600; }
.bc-grow { flex:1 1 220px; }
.bc-sm { flex:0 0 96px; }
.bc-md { flex:0 0 160px; }
.bc-btn { font:inherit; font-size:13px; font-weight:600; padding:8px 14px; border-radius:6px; border:1px solid transparent; cursor:pointer; min-height:36px; }
.bc-btn-primary { background:#2b6cb0; color:#fff; }
.bc-btn-primary:hover { background:#2c5282; }
.bc-btn-primary:disabled { opacity:.5; cursor:default; }
.bc-btn-ghost { background:transparent; border-color:var(--ork-input-border,#cbd5e0); color:inherit; }
.bc-btn-ghost:hover { background:var(--ork-bg-secondary,#f7fafc); }
.bc-new { border:1px dashed var(--ork-input-border,#cbd5e0); border-radius:8px; padding:12px; margin-bottom:12px; background:var(--ork-bg-secondary,#f7fafc); }
.bc-new[hidden] { display:none; }
.bc-check { display:flex; align-items:center; gap:6px; font-size:13px; min-height:36px; }
.bc-info { font-size:12px; color:var(--ork-text-muted,#718096); margin:-6px 0 12px; }
.bc-info[hidden] { display:none; }
.bc-feedback { display:none; padding:8px 12px; border-radius:6px; font-size:13px; margin-bottom:12px; }
.bc-feedback.bc-ok { display:block; background:#f0fff4; border:1px solid #c6f6d5; color:#276749; }
.bc-feedback.bc-err { display:block; background:#fff5f5; border:1px solid #fed7d7; color:#c53030; }
.bc-list-label { font-size:11px; font-weight:600; color:var(--ork-text-muted,#718096); text-transform:uppercase; letter-spacing:.03em; margin:8px 0 6px; display:flex; justify-content:space-between; }
.bc-table { width:100%; border-collapse:collapse; font-size:13px; }
.bc-table th { text-align:left; font-size:11px; font-weight:600; color:var(--ork-text-muted,#718096); text-transform:uppercase; padding:6px 8px; border-bottom:1px solid var(--ork-divider,#e2e8f0); }
.bc-table td { padding:6px 8px; border-bottom:1px solid var(--ork-divider,#f0f4f8); vertical-align:middle; }
.bc-table tr:last-child td { border-bottom:0; }
.bc-table .bc-num { text-align:right; white-space:nowrap; }
.bc-table .bc-muted { color:var(--ork-text-muted,#718096); font-size:12px; }
.bc-revoke { background:none; border:0; color:#c53030; cursor:pointer; font-size:12px; padding:2px 6px; }
.bc-revoke:hover { text-decoration:underline; }
.bc-empty { font-size:13px; color:var(--ork-text-muted,#a0aec0); padding:10px 0; }
.bc-table tr.bc-just-added td { background:#f0fff4; }
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
                <h3 class="bc-title" id="bc-title"><i class="fas fa-star" style="margin-right:8px;color:#d69e2e"></i>Add Bonus Credits</h3>
                <p class="bc-sub" id="bc-sub"></p>
            </div>
            <button type="button" class="bc-close" id="bc-close" aria-label="Close">&times;</button>
        </div>
        <div class="bc-body">
            <div class="bc-feedback" id="bc-feedback" role="status"></div>

            <div class="bc-row">
                <div class="bc-field bc-grow">
                    <label for="bc-reason">Credit for</label>
                    <select id="bc-reason"></select>
                </div>
            </div>
            <div class="bc-info" id="bc-reason-info" hidden></div>

            <div class="bc-new" id="bc-new" hidden>
                <div class="bc-row">
                    <div class="bc-field bc-grow">
                        <label for="bc-new-name">Name</label>
                        <input type="text" id="bc-new-name" maxlength="100" placeholder="e.g. Being purple" autocomplete="off">
                    </div>
                    <div class="bc-field bc-sm">
                        <label for="bc-new-credits">Credits</label>
                        <input type="number" id="bc-new-credits" min="0.25" max="10" step="0.25" value="1">
                    </div>
                </div>
                <div class="bc-row">
                    <div class="bc-field bc-grow">
                        <label for="bc-new-desc">Description (optional)</label>
                        <input type="text" id="bc-new-desc" maxlength="500" placeholder="What earns it" autocomplete="off">
                    </div>
                    <label class="bc-check"><input type="checkbox" id="bc-new-repeat"> Can be earned more than once</label>
                    <button type="button" class="bc-btn bc-btn-primary" id="bc-new-save">Save credit</button>
                </div>
            </div>

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
                    <label>Credits</label>
                    <div class="bc-amount" id="bc-credits" title="Set by the kingdom when the credit was defined">1</div>
                </div>
                <div class="bc-field">
                    <label>&nbsp;</label>
                    <button type="button" class="bc-btn bc-btn-primary" id="bc-add" disabled><i class="fas fa-plus"></i> Add</button>
                </div>
            </div>

            <div class="bc-list-label"><span>Granted</span><span id="bc-count"></span></div>
            <table class="bc-table" id="bc-table" style="display:none">
                <thead><tr><th>Player</th><th>Class</th><th class="bc-num">Credits</th><th>Date</th><th>By</th><th></th></tr></thead>
                <tbody id="bc-tbody"></tbody>
            </table>
            <div class="bc-empty" id="bc-empty">Pick a credit above to see who has it.</div>
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
    if (!Cfg.uir || !Cfg.scopeType || !Cfg.scopeId) return;

    function gid(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function fmtCredits(n) { n = parseFloat(n) || 0; return (Math.round(n * 100) / 100).toString(); }
    var base = Cfg.uir + 'BonusCreditAjax/';

    /* The confirm must stack ABOVE this modal (--z-modal). The park and kingdom
       pages' knConfirm (kn-confirm-overlay, --z-modal-top) does; so does the
       player page's pnConfirm. revised.js's orkConfirm overlay sits at 1200 and
       would open behind us, so it is only a fallback, and orkui.js's promise
       version (different signature: an options object) after that. */
    function confirmThen(title, message, okLabel, cb) {
        if (typeof window.knConfirm === 'function' && document.getElementById('kn-confirm-overlay')) { window.knConfirm(message, cb, title); return; }
        if (typeof window.pnConfirm === 'function') { window.pnConfirm({ title: title, message: message, confirmText: okLabel, danger: true }, cb); return; }
        var oc = window.orkConfirm;
        if (typeof oc === 'function' && oc.length >= 2) { oc(message, cb, { title: title, okLabel: okLabel, danger: true }); return; }
        if (typeof oc === 'function') {
            var p = oc({ title: title, body: message, confirmLabel: okLabel, danger: true });
            if (p && typeof p.then === 'function') { p.then(function (ok) { if (ok) cb(); }); return; }
        }
        if (window.confirm(message)) cb();
    }
    var scopeQS = 'ScopeType=' + encodeURIComponent(Cfg.scopeType) + '&ScopeId=' + Cfg.scopeId;

    var reasons = [], classes = [], loaded = false, current = null, lastClass = 0, release = null;
    var canDefine = Cfg.scopeType === 'kingdom';   // the server confirms on load

    function feedback(msg, ok) {
        var el = gid('bc-feedback');
        el.textContent = msg || '';
        el.className = 'bc-feedback' + (msg ? (ok ? ' bc-ok' : ' bc-err') : '');
    }

    // ---- open / close ----
    window.bcOpen = function () {
        var ov = gid('bc-overlay');
        gid('bc-sub').textContent = (Cfg.scopeName || '') + ' — credits that count toward class levels, not attendance.' +
            (Cfg.scopeType === 'park' ? ' Your kingdom defines them; you hand them out.' : '');
        ov.classList.add('bc-open');
        ov.setAttribute('aria-hidden', 'false');
        feedback('');
        if (window.orkDialogTrap) { release = window.orkDialogTrap(ov, { onEscape: bcClose }); }
        if (!loaded) { load(); } else { gid('bc-reason').focus(); }
    };
    function bcClose() {
        var ov = gid('bc-overlay');
        ov.classList.remove('bc-open');
        ov.setAttribute('aria-hidden', 'true');
        if (release) { release(); release = null; }
        try { jQuery('#bc-player').autocomplete('close'); } catch (e) {}
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

    // ---- data ----
    function load() {
        jQuery.getJSON(base + 'options&' + scopeQS, function (r) {
            if (!r || r.status !== 0) { feedback((r && r.error) || 'Could not load credits.', false); return; }
            reasons = r.reasons || [];
            classes = r.classes || [];
            canDefine = !!r.canDefine;
            loaded = true;
            fillClasses();
            fillReasons(reasons.length ? reasons[0].ReasonId : (canDefine ? 'new' : ''));
            gid('bc-reason').focus();
        }).fail(function () { feedback('Could not load credits.', false); });
    }
    function fillClasses() {
        var sel = gid('bc-class'), html = '<option value="">— class —</option>';
        classes.forEach(function (c) { html += '<option value="' + c.ClassId + '">' + esc(c.ClassName) + '</option>'; });
        sel.innerHTML = html;
        if (lastClass) sel.value = String(lastClass);
    }
    function fillReasons(selectValue) {
        var sel = gid('bc-reason'), html = '';
        if (!reasons.length && !canDefine) html += '<option value="">No credits set up by your kingdom yet</option>';
        reasons.forEach(function (rs) {
            html += '<option value="' + rs.ReasonId + '">' + esc(rs.Name) + ' (' + fmtCredits(rs.DefaultCredits) + (rs.DefaultCredits === 1 ? ' credit' : ' credits') +
                    (Cfg.scopeType === 'park' && rs.ScopeName ? ', ' + esc(rs.ScopeName) : '') + ')</option>';
        });
        if (canDefine) html += '<option value="new">＋ New credit…</option>';
        sel.innerHTML = html;
        sel.value = String(selectValue);
        if (sel.value !== String(selectValue)) sel.value = canDefine ? 'new' : (reasons.length ? String(reasons[0].ReasonId) : '');
        onReasonChange();
    }
    function reasonById(id) {
        for (var i = 0; i < reasons.length; i++) if (String(reasons[i].ReasonId) === String(id)) return reasons[i];
        return null;
    }
    function onReasonChange() {
        var v = gid('bc-reason').value;
        var isNew = v === 'new' && canDefine;
        gid('bc-new').hidden = !isNew;
        gid('bc-grant-row').style.display = (isNew || !v) ? 'none' : '';
        current = isNew ? null : reasonById(v);
        var info = gid('bc-reason-info');
        if (current) {
            var bits = [];
            if (current.Description) bits.push(esc(current.Description).replace(/[.!?]?$/, '.'));
            bits.push(current.Repeatable ? 'Can be earned more than once.' : 'Once per player.');
            info.innerHTML = bits.join(' · ');
            info.hidden = false;
            gid('bc-credits').textContent = fmtCredits(current.DefaultCredits);
            loadGranted(current.ReasonId);
        } else {
            info.hidden = true;
            renderGranted([]);
            gid('bc-empty').textContent = isNew ? 'Save the new credit, then add players to it.'
                : (!reasons.length && !canDefine ? 'Ask a kingdom officer to set up a credit on the kingdom page.' : 'Pick a credit above to see who has it.');
            if (isNew) setTimeout(function () { gid('bc-new-name').focus(); }, 30);
        }
        updateAdd();
    }
    gid('bc-reason').addEventListener('change', onReasonChange);

    // ---- new reason ----
    gid('bc-new-save').addEventListener('click', function () {
        var btn = this, name = gid('bc-new-name').value.trim();
        if (!name) { feedback('Give the credit a name.', false); gid('bc-new-name').focus(); return; }
        btn.disabled = true;
        jQuery.post(base + 'reason_create', {
            ScopeType: Cfg.scopeType, ScopeId: Cfg.scopeId,
            Name: name, Description: gid('bc-new-desc').value.trim(),
            DefaultCredits: gid('bc-new-credits').value, Repeatable: gid('bc-new-repeat').checked ? 1 : 0
        }, function (r) {
            btn.disabled = false;
            if (!r || r.status !== 0) { feedback((r && r.error) || 'Could not save the credit.', false); return; }
            var rs = r.reason;
            var idx = -1;
            reasons.forEach(function (x, i) { if (x.ReasonId === rs.ReasonId) idx = i; });
            if (idx >= 0) reasons[idx] = rs; else reasons.push(rs);
            reasons.sort(function (a, b) { return a.Name.localeCompare(b.Name); });
            gid('bc-new-name').value = ''; gid('bc-new-desc').value = ''; gid('bc-new-credits').value = '1'; gid('bc-new-repeat').checked = false;
            feedback((r.restored ? 'Restored “' : 'Created “') + rs.Name + '”. Now add players.', true);
            fillReasons(rs.ReasonId);
            setTimeout(function () { gid('bc-player').focus(); }, 30);
        }, 'json').fail(function () { btn.disabled = false; feedback('Request failed.', false); });
    });
    gid('bc-new-name').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); gid('bc-new-save').click(); } });

    // ---- granted list ----
    function loadGranted(reasonId) {
        gid('bc-empty').textContent = 'Loading…';
        jQuery.getJSON(base + 'reason_credits&ReasonId=' + reasonId + (Cfg.scopeType === 'park' ? '&ParkId=' + Cfg.scopeId : ''), function (r) {
            if (!current || String(current.ReasonId) !== String(reasonId)) return;
            if (!r || r.status !== 0) { feedback((r && r.error) || 'Could not load.', false); return; }
            renderGranted(r.credits || []);
        });
    }
    function rowHtml(c, justAdded) {
        return '<tr data-id="' + c.BonusCreditId + '" data-mid="' + c.MundaneId + '"' + (justAdded ? ' class="bc-just-added"' : '') + '>' +
            '<td><a href="' + Cfg.uir + 'Player/profile/' + c.MundaneId + '">' + esc(c.Persona) + '</a></td>' +
            '<td>' + esc(c.ClassName) + '</td>' +
            '<td class="bc-num">' + fmtCredits(c.Credits) + '</td>' +
            '<td class="bc-muted">' + esc(c.Date) + '</td>' +
            '<td class="bc-muted">' + esc(c.GrantedByPersona) + '</td>' +
            '<td class="bc-num"><button type="button" class="bc-revoke" data-id="' + c.BonusCreditId + '" title="Remove this credit">Remove</button></td>' +
            '</tr>';
    }
    function renderGranted(list) {
        var tb = gid('bc-tbody'), html = '';
        list.forEach(function (c) { html += rowHtml(c, false); });
        tb.innerHTML = html;
        gid('bc-table').style.display = list.length ? '' : 'none';
        gid('bc-empty').style.display = list.length ? 'none' : '';
        gid('bc-empty').textContent = Cfg.scopeType === 'park' ? 'None of this park\'s players has this credit yet.' : 'Nobody has this credit yet.';
        gid('bc-count').textContent = list.length ? list.length + (list.length === 1 ? ' player' : ' players') : '';
    }
    function prependGranted(c) {
        var tb = gid('bc-tbody');
        tb.insertAdjacentHTML('afterbegin', rowHtml(c, true));
        gid('bc-table').style.display = '';
        gid('bc-empty').style.display = 'none';
        var n = tb.querySelectorAll('tr').length;
        gid('bc-count').textContent = n + (n === 1 ? ' player' : ' players');
    }
    gid('bc-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.bc-revoke') : null;
        if (!btn) return;
        var tr = btn.closest('tr'), id = btn.getAttribute('data-id');
        var who = tr.querySelector('a') ? tr.querySelector('a').textContent : 'this player';
        function doRevoke() {
            btn.disabled = true;
            jQuery.post(base + 'revoke', { BonusCreditId: id }, function (r) {
                if (!r || r.status !== 0) { btn.disabled = false; feedback((r && r.error) || 'Could not remove it.', false); return; }
                tr.remove();
                var n = gid('bc-tbody').querySelectorAll('tr').length;
                gid('bc-count').textContent = n ? n + (n === 1 ? ' player' : ' players') : '';
                if (!n) { gid('bc-table').style.display = 'none'; gid('bc-empty').style.display = ''; }
                feedback('Removed ' + who + '’s credit.', true);
            }, 'json').fail(function () { btn.disabled = false; feedback('Request failed.', false); });
        }
        confirmThen('Remove this credit?', who + ' loses the “' + (current ? current.Name : 'bonus') + '” credit. It can be granted again.', 'Remove', doRevoke);
    });

    // ---- player search (the org's own players only: a credit cannot leave its scope) ----
    var selectedPersona = '';
    function searchUrl(term) {
        var u = Cfg.uir + 'KingdomAjax/playersearch/' + Cfg.kingdomId + '&scope=own&include_inactive=1&q=' + encodeURIComponent(term);
        if (Cfg.scopeType === 'park') u += '&park_id=' + Cfg.scopeId;
        return u;
    }
    jQuery('#bc-player').autocomplete({
        minLength: 2,
        delay: 150,
        classes: { 'ui-autocomplete': 'bc-ac' },
        source: function (req, res) {
            jQuery.getJSON(searchUrl(req.term), function (list) {
                res(jQuery.map(list || [], function (v) {
                    var where = (v.KAbbr && v.PAbbr) ? v.KAbbr + ':' + v.PAbbr : (v.ParkName || '');
                    return { label: v.Persona + (where ? ' — ' + where : '') + (parseInt(v.Active, 10) === 0 ? ' (inactive)' : ''), name: v.Persona, value: v.MundaneId };
                }));
            }).fail(function () { res([]); });
        },
        focus: function (e) { e.preventDefault(); },
        select: function (e, ui) {
            e.preventDefault();
            gid('bc-player').value = ui.item.name;
            gid('bc-player-id').value = ui.item.value;
            selectedPersona = ui.item.name;
            updateAdd();
            if (!gid('bc-class').value) gid('bc-class').focus(); else gid('bc-add').focus();
        }
    });
    gid('bc-player').addEventListener('input', function () { gid('bc-player-id').value = ''; selectedPersona = ''; updateAdd(); });
    gid('bc-class').addEventListener('change', updateAdd);
    function updateAdd() {
        var ok = current && gid('bc-player-id').value && gid('bc-class').value;
        gid('bc-add').disabled = !ok;
    }

    // ---- grant ----
    gid('bc-add').addEventListener('click', function () {
        var btn = this, mid = gid('bc-player-id').value, cls = gid('bc-class').value;
        if (!current || !mid) return;
        btn.disabled = true;
        jQuery.post(base + 'grant', { ReasonId: current.ReasonId, MundaneId: mid, ClassId: cls }, function (r) {
            if (!r || r.status !== 0) { btn.disabled = false; feedback((r && r.error) || 'Could not add the credit.', false); return; }
            lastClass = parseInt(cls, 10);
            prependGranted(r.credit);
            feedback('Added ' + fmtCredits(r.credit.Credits) + ' ' + r.credit.ClassName + ' credit for ' + r.credit.Persona + '.', true);
            gid('bc-player').value = ''; gid('bc-player-id').value = ''; selectedPersona = '';
            updateAdd();
            gid('bc-player').focus();
        }, 'json').fail(function () { btn.disabled = false; feedback('Request failed.', false); });
    });
    gid('bc-player').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !gid('bc-add').disabled) { e.preventDefault(); gid('bc-add').click(); }
    });
})();
</script>
