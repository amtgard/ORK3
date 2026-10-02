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

    var _ctx = null;       // state of the draft currently drawn in the Run view
    var _focusNext = {};   // bracketId → true: open the lowest empty seat's editor after render
    try {
        var _qf = parseInt(sessionStorage.getItem('tnQbFocus'), 10) || 0;
        if (_qf) { _focusNext[_qf] = true; sessionStorage.removeItem('tnQbFocus'); }
    } catch (e) {}

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }
    function nextPow2(n) { var p = 1; while (p < n) p *= 2; return p; }
    function byPid(a, b) { return (parseInt(a.ParticipantId, 10) || 0) - (parseInt(b.ParticipantId, 10) || 0); }

    // JS port of Tournament::bracket_seed_order — standard order (8 → [1,8,4,5,2,7,3,6]),
    // consumed two at a time as round-1 pairings. Must stay identical to the PHP.
    function seedOrder(slots) {
        var rounds = Math.round(Math.log(Math.max(1, slots)) / Math.LN2), seeds = [1];
        for (var r = 0; r < rounds; r++) {
            var next = [], sum = seeds.length * 2 + 1;
            seeds.forEach(function (s) { next.push(s); next.push(sum - s); });
            seeds = next;
        }
        return seeds;
    }

    // seed → participant. Mirrors Tournament::quick_seed_order (before gaps close): seeded
    // entrants keep their seat (duplicate → lower ParticipantId wins), seed-0 entrants take the
    // lowest empty seats by ParticipantId.
    function seatEntrants(participants) {
        var seats = {}, unseeded = [];
        participants.slice().sort(byPid).forEach(function (p) {
            var s = parseInt(p.Seed, 10) || 0;
            if (s > 0 && !seats[s]) seats[s] = p; else unseeded.push(p);
        });
        var s = 1;
        unseeded.forEach(function (p) { while (seats[s]) s++; seats[s] = p; });
        return seats;
    }

    function lowestEmptySeat() {
        for (var s = 1; s <= _ctx.eff; s++) if (!_ctx.seats[s]) return s;
        return 0;
    }

    function isDraft(bd) {
        var b = bd && bd.Bracket;
        return !!b && (parseInt(b.DrawSize, 10) || 0) > 0
            && (b.Method === 'single' || b.Method === 'double')
            && (!b.Status || b.Status === 'setup')
            && Array.isArray(bd.Matches) && bd.Matches.length === 0;
    }

    function draftMatches(bracketId, slots) {
        var order = seedOrder(slots), ms = [], o = 1;
        var rounds = Math.round(Math.log(slots) / Math.LN2);
        for (var i = 0; i < order.length; i += 2) {
            ms.push({ MatchId: 'qb-' + bracketId + '-1-' + (i / 2 + 1), BracketId: bracketId, Round: 1, Match: i / 2 + 1, Order: o++,
                BracketSide: 'winners', Participant1Id: 0, Participant2Id: 0, Result: null, _qbDraft: true, _qbSeeds: [order[i], order[i + 1]] });
        }
        var n = slots / 2;
        for (var r = 2; r <= rounds; r++) {
            n = n / 2;
            for (var m = 1; m <= n; m++) {
                ms.push({ MatchId: 'qb-' + bracketId + '-' + r + '-' + m, BracketId: bracketId, Round: r, Match: m, Order: o++,
                    BracketSide: 'winners', Participant1Id: 0, Participant2Id: 0, Result: null, _qbDraft: true, _qbSeeds: null });
            }
        }
        return ms;
    }

    function renderDraft(container, bd, bracketId, renderTree) {
        var b = bd.Bracket, parts = (bd.Participants || []).slice();
        var seats = seatEntrants(parts);
        var maxSeat = 0;
        Object.keys(seats).forEach(function (k) { maxSeat = Math.max(maxSeat, parseInt(k, 10)); });
        var eff = Math.max(parseInt(b.DrawSize, 10) || 0, parts.length, maxSeat);
        _ctx = { bracketId: bracketId, bd: bd, seats: seats, eff: eff, placed: parts.length,
            canEdit: !!TnConfig.canManage, method: b.Method };

        container.appendChild(buildToolbar(b, bracketId));
        if (b.Method === 'double') container.appendChild(el('p', 'tn-qb-note', 'Second Chance bracket builds automatically on Start.'));
        var pMap = {};
        parts.forEach(function (p) { pMap[p.ParticipantId] = p; });
        renderTree(container, draftMatches(bracketId, Math.max(4, nextPow2(eff))), pMap);
        if (_ctx.canEdit) afterRender(container, bracketId);
    }

    // Task 6 replaces this with editor restore + drag/swap wiring.
    function afterRender(container, bracketId) {
        if (_focusNext[bracketId]) {
            delete _focusNext[bracketId];
            var s = lowestEmptySeat();
            var ln = s && container.querySelector('.tn-qb-empty[data-seed="' + s + '"]');
            if (ln) openEditor(ln, s, '');
        }
    }

    function buildToolbar(b, bracketId) {
        var bar = el('div', 'tn-qb-toolbar');
        var badge = el('span', 'tn-qb-badge');
        badge.innerHTML = '<i class="fas fa-bolt"></i> Quick Bracket';
        bar.appendChild(badge);
        var method = (TnConfig.methodLabels || {})[b.Method] || b.Method;
        bar.appendChild(el('span', 'tn-qb-count', method + ' · ' + _ctx.placed + ' of ' + _ctx.eff + ' placed'));
        if (!_ctx.canEdit) return bar;

        var shuffleBtn = el('button', 'tn-btn tn-btn-outline tn-btn-sm');
        shuffleBtn.type = 'button';
        shuffleBtn.innerHTML = '<i class="fas fa-random"></i> Shuffle';
        shuffleBtn.setAttribute('data-tip', 'Randomize the seeds of everyone placed');
        shuffleBtn.disabled = _ctx.placed < 2;
        shuffleBtn.onclick = function () { shuffleSeeds(bracketId); };
        bar.appendChild(shuffleBtn);

        var editBtn = el('button', 'tn-btn tn-btn-outline tn-btn-sm');
        editBtn.type = 'button';
        editBtn.innerHTML = '<i class="fas fa-pen"></i> Edit';
        editBtn.onclick = function () {
            window.tnOpenEditBracketModal(bracketId, {
                style: b.Style, styleNote: b.StyleNote || '', method: b.Method, rings: parseInt(b.Rings, 10) || 1,
                participants: b.Participants, seeding: b.Seeding, durationMinutes: parseInt(b.DurationMinutes, 10) || 0,
                bestOf: parseInt(b.BestOf, 10) || 1, pointRounds: parseInt(b.PointRounds, 10) || 3,
                pointMode: b.PointMode || 'fixed', pointScale: b.PointScale || '5,3,1,0'
            });
        };
        bar.appendChild(editBtn);

        var min = b.Method === 'double' ? 3 : 2;
        var startBtn = el('button', 'tn-btn tn-btn-primary tn-btn-sm');
        startBtn.type = 'button';
        startBtn.innerHTML = '<i class="fas fa-play"></i> Start Bracket';
        if (_ctx.placed < min) {
            startBtn.disabled = true;
            startBtn.setAttribute('data-tip', 'Place at least ' + min + ' fighters to start');
        }
        startBtn.onclick = function () { start(bracketId, startBtn); };
        bar.appendChild(startBtn);
        return bar;
    }

    function seedChip(seed) { return el('span', 'tn-bv-seed', String(seed)); }

    function buildSeatLine(seed) {
        var c = _ctx, line = el('div', 'tn-bv-slot tn-qb-line');
        line.dataset.seed = seed;
        line.appendChild(seedChip(seed));
        if (seed > c.eff) {
            line.classList.add('tn-bv-bye', 'tn-qb-fixed-bye');
            line.appendChild(el('span', '', 'Bye'));
            return line;
        }
        var p = c.seats[seed];
        if (p) {
            line.classList.add('tn-qb-filled');
            var isAlias = !(parseInt(p.MundaneId, 10) > 0);
            line.appendChild(el('span', 'tn-qb-name' + (isAlias ? ' tn-qb-alias' : ''), p.Alias || p.Persona || '—'));
            if (isAlias) line.appendChild(el('span', 'tn-qb-alias-tag', 'alias'));
            if (p._pending) line.classList.add('tn-match-pending');
            if (c.canEdit && !p._pending) decorateFilled(line, p, seed);
            return line;
        }
        line.classList.add('tn-qb-empty');
        var hint = '';
        if (c.placed === 0 && seed === 1) hint = 'Type to search for player';
        else if (c.placed >= 2) hint = 'Bye fight or type to search';
        line.appendChild(el('span', 'tn-qb-hint', hint));
        if (c.canEdit) {
            line.tabIndex = 0;
            line.setAttribute('role', 'button');
            line.setAttribute('aria-label', 'Seed ' + seed + ': search for a player');
            line.addEventListener('click', function () { if (!line.querySelector('.tn-qb-input')) openEditor(line, seed, ''); });
            line.addEventListener('keydown', function (e) {
                if ((e.key === 'Enter' || e.key === ' ') && e.target === line) { e.preventDefault(); openEditor(line, seed, ''); }
            });
        }
        return line;
    }

    function buildDraftBox(m) {
        var box = el('div', 'tn-bv-match tn-qb-match');
        box.dataset.matchid = m.MatchId;
        var hit = el('div', 'tn-bv-hit');
        [0, 1].forEach(function (k) {
            if (m._qbSeeds) {
                hit.appendChild(buildSeatLine(m._qbSeeds[k]));
            } else {
                var s = el('div', 'tn-bv-slot');
                s.appendChild(el('span', 'tn-bv-tbd-label', 'Awaiting Rd ' + (parseInt(m.Round, 10) - 1)));
                hit.appendChild(s);
            }
        });
        box.appendChild(hit);
        return box;
    }

    // Placeholders implemented in Task 6.
    function openEditor() {}
    function decorateFilled() {}
    function shuffleSeeds() {}

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
        _post: post,
        _seatEntrants: seatEntrants,
        _seedOrder: seedOrder
    };
})();
