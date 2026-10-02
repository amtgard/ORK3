/*
 * Tournament Quick Bracket (spec docs/superpowers/specs/2026-10-02-tournament-quick-bracket-design.md).
 * Loaded by Tournametnew_index.tpl after its inline scripts; exposes window.TnQuickBracket.
 * Page globals used: TnConfig, tnOpenAsSheet, tnCloseModal, tnShowFeedback, tnFixedAcPosition,
 * tnToast, tnNewActionId, tnRegisterAction, tnCollabNudge, tnRefreshAndRender,
 * tnRenderBracketViz, tnOpenEditBracketModal.
 */
(function () {
    'use strict';
    if (!window.TnConfig) return;

    var OVERLAY  = 'tn-quickbracket-overlay';
    var PREF_KEY = 'tnQbPrefs';

    function loadPrefs() {
        var p = { method: 'single', size: 8 };
        try {
            var s = JSON.parse(localStorage.getItem(PREF_KEY) || 'null');
            if (s && (s.method === 'single' || s.method === 'double')) p.method = s.method;
            if (s && [4, 8, 12, 16, 24, 32].indexOf(parseInt(s.size, 10)) !== -1) p.size = parseInt(s.size, 10);
        } catch (e) {}
        return p;
    }
    function savePrefs(p) { try { localStorage.setItem(PREF_KEY, JSON.stringify(p)); } catch (e) {} }

    function setChip(groupId, value) {
        var g = document.getElementById(groupId);
        if (!g) return;
        g.querySelectorAll('.tn-qb-chip').forEach(function (c) {
            var on = c.getAttribute('data-value') === String(value);
            c.setAttribute('aria-checked', on ? 'true' : 'false');
            c.tabIndex = on ? 0 : -1;
        });
    }
    function chipVal(groupId) {
        var c = document.querySelector('#' + groupId + ' .tn-qb-chip[aria-checked="true"]');
        return c ? c.getAttribute('data-value') : null;
    }
    function wireChips(groupId) {
        var g = document.getElementById(groupId);
        if (!g) return;
        g.addEventListener('click', function (e) {
            var c = e.target.closest('.tn-qb-chip');
            if (c) setChip(groupId, c.getAttribute('data-value'));
        });
        // Radio-group arrow keys.
        g.addEventListener('keydown', function (e) {
            if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(e.key) === -1) return;
            var chips = Array.prototype.slice.call(g.querySelectorAll('.tn-qb-chip'));
            var i = chips.findIndex(function (c) { return c.getAttribute('aria-checked') === 'true'; });
            i = (i + ((e.key === 'ArrowLeft' || e.key === 'ArrowUp') ? -1 : 1) + chips.length) % chips.length;
            setChip(groupId, chips[i].getAttribute('data-value'));
            chips[i].focus();
            e.preventDefault();
        });
    }

    // POST helper: own-action id (echo dedup), collab nudge, FormData body, JSON reply.
    function post(path, fields) {
        var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
        if (window.tnRegisterAction) window.tnRegisterAction(actionId);
        if (window.tnCollabNudge) window.tnCollabNudge();
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        fd.append('ActionId', actionId);
        return fetch(TnConfig.uir + path, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { status: 1, error: 'Network error — try again.' }; });
    }

    function openModal() {
        if (!TnConfig.canManage) return;
        var p = loadPrefs();
        setChip('tn-qb-method', p.method);
        setChip('tn-qb-size', p.size);
        var fb = document.getElementById('tn-qb-feedback');
        if (fb) fb.style.display = 'none';
        var btn = document.getElementById('tn-qb-generate');
        if (btn) btn.disabled = false;
        window.tnOpenAsSheet(OVERLAY, {});
        setTimeout(function () { if (btn) btn.focus(); }, 50);
    }

    function generate() {
        var method = chipVal('tn-qb-method');
        var size   = parseInt(chipVal('tn-qb-size'), 10);
        var btn    = document.getElementById('tn-qb-generate');
        if (!method || !size) return;
        savePrefs({ method: method, size: size });
        btn.disabled = true;
        post('TournamentAjax/tournament/' + TnConfig.tournamentId + '/quickbracket', { Method: method, DrawSize: size })
            .then(function (d) {
                if (!d || d.status !== 0) {
                    btn.disabled = false;
                    tnShowFeedback('tn-qb-feedback', (d && d.error) || 'Could not create the bracket.', false);
                    return;
                }
                try {
                    sessionStorage.setItem('tnOpenTab', 'bracketviz');
                    sessionStorage.setItem('tnRunBracket_' + TnConfig.tournamentId, String(d.bracketId));
                    sessionStorage.setItem('tnQbFocus', String(d.bracketId));
                } catch (e) {}
                window.location.reload();
            });
    }

    function isDraftBracket(bracketId) {
        var bd = TnConfig.bracketData && TnConfig.bracketData[bracketId];
        var b  = bd && bd.Bracket;
        return !!b && (parseInt(b.DrawSize, 10) || 0) > 0
            && (b.Method === 'single' || b.Method === 'double')
            && (!b.Status || b.Status === 'setup');
    }

    function start(bracketId, btn) {
        if (btn) btn.disabled = true;
        return post('TournamentAjax/bracket/' + bracketId + '/quickstart', { TournamentId: TnConfig.tournamentId })
            .then(function (d) {
                if (!d || d.status !== 0) {
                    if (btn) btn.disabled = false;
                    window.tnToast((d && d.error) || 'Could not start the bracket.');
                    return;
                }
                window.tnRefreshAndRender(bracketId);
            });
    }

    // Filled in by the draft-draw task.
    function isDraft() { return false; }
    function renderDraft() {}
    function buildDraftBox() { return document.createElement('div'); }

    document.addEventListener('DOMContentLoaded', function () {
        wireChips('tn-qb-method');
        wireChips('tn-qb-size');
        var btn = document.getElementById('tn-qb-generate');
        if (btn) btn.addEventListener('click', generate);
        var ov = document.getElementById(OVERLAY);
        if (ov) ov.addEventListener('click', function (e) { if (e.target === ov) window.tnCloseModal(OVERLAY); });
    });

    window.TnQuickBracket = {
        openModal: openModal,
        start: start,
        isDraftBracket: isDraftBracket,
        isDraft: isDraft,
        renderDraft: renderDraft,
        buildDraftBox: buildDraftBox,
        _post: post
    };
})();
