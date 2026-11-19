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
            && b.Participants === 'individual'
            && (!b.Status || b.Status === 'setup');
    }

    var _starting = {};    // bracketId → true while a quickstart is in flight (double-click guard)
    function start(bracketId, btn) {
        if (_starting[bracketId]) return Promise.resolve();
        if (_ctx && _ctx.bracketId === (parseInt(bracketId, 10) || 0) && hasPending()) return Promise.resolve();   // placements still in flight
        _starting[bracketId] = true;
        if (btn) btn.disabled = true;
        return post('TournamentAjax/bracket/' + bracketId + '/quickstart', { TournamentId: TnConfig.tournamentId })
            .then(function (d) {
                delete _starting[bracketId];
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
            && b.Participants === 'individual'
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
        bracketId = parseInt(bracketId, 10) || 0;
        if (_ctx && _ctx.bracketId !== bracketId) teardown();   // switching drafts: drop A's editor, restore and swap mode
        if (_ed) closeEditor(true);
        delete _held[bracketId];   // this paint is the one a held repaint was waiting for
        var b = bd.Bracket, parts = (bd.Participants || []).slice();
        var seats = seatEntrants(parts);
        _ctx = { bracketId: bracketId, bd: bd, seats: seats, eff: drawExtent(b, parts, seats), placed: parts.length,
            canEdit: !!TnConfig.canManage, method: b.Method, container: container };

        container.appendChild(buildToolbar(b, bracketId));
        if (b.Method === 'double') container.appendChild(el('p', 'tn-qb-note', 'Second Chance bracket builds automatically on Start.'));
        var pMap = {};
        parts.forEach(function (p) { pMap[p.ParticipantId] = p; });
        renderTree(container, draftMatches(bracketId, Math.max(4, nextPow2(_ctx.eff))), pMap);
        if (_ctx.canEdit) afterRender(container, bracketId);
    }

    function maxSeatOf(seats) {
        var m = 0;
        Object.keys(seats).forEach(function (k) { m = Math.max(m, parseInt(k, 10)); });
        return m;
    }
    function drawExtent(b, parts, seats) { return Math.max(parseInt(b.DrawSize, 10) || 0, parts.length, maxSeatOf(seats)); }

    // What Start will actually run when it differs from the drawn seats (spec "close gaps").
    // Start compacts the N placed fighters into nextPow2(N) slots with byes to the top seeds.
    // Shown when that slot count differs from the drawn one (e.g. 3 in an 8 shows seeds 2 and 3
    // facing byes, but Start gives seed 1 the bye and pairs 2 v 3), or when seats 1..N have a gap.
    function runNote() {
        var n = _ctx.placed;
        if (n < (_ctx.method === 'double' ? 3 : 2)) return '';
        var gap = false;
        for (var s = 1; s <= n; s++) if (!_ctx.seats[s]) { gap = true; break; }
        var slots = nextPow2(Math.max(n, 2)), byes = slots - n;
        if (!gap && slots === Math.max(4, nextPow2(_ctx.eff))) return '';
        return 'Start seats ' + n + ' fighters in ' + (slots === 8 ? 'an ' : 'a ') + slots + '-slot draw'
            + (byes === 1 ? ' \u2014 bye to seed 1' : byes > 1 ? ' \u2014 byes to seeds 1\u2013' + byes : '');
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
        } else if (hasPending()) {
            startBtn.disabled = true;
            startBtn.setAttribute('data-tip', 'Finishing placements\u2026');
        }
        startBtn.onclick = function () { start(bracketId, startBtn); };
        bar.appendChild(startBtn);
        var note = runNote();
        if (note) bar.appendChild(el('p', 'tn-qb-runnote', note));
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
        if (!c.canEdit) line.classList.add('tn-qb-ro');   // spectator: no prompt, no pointer
        else if (c.placed === 0 && seed === 1) hint = 'Type to search for player';
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

    var _ed = null;          // open editor: { bracketId, seed, input, dd, items, hi, timer, req, players, ... }
    var _restore = null;     // editor snapshot carried across a re-render (see snapshot())
    var _localRoster = [];   // fighters registered by this tab since load (TnConfig.registrants is a load-time snapshot)
    // While an editor is open the draw is not repainted (a repaint rebuilds the input: lost highlight,
    // re-armed Enter guard, dismissed iOS keyboard). Own writes patch lines in place and set _dirty
    // (refetch from the server on close); other repaints (collab, late refreshes) set _held instead.
    var _dirty = {};         // bracketId → true: a server refetch is owed once the editor closes
    var _held = {};          // bracketId → true: a repaint was withheld while editing

    function isEditing(bid) { return !!_ed && _ed.bracketId === (parseInt(bid, 10) || 0); }

    // Page refresh paths ask before painting: true = withheld (the store is already updated and the
    // close paints it). A bracket that stopped being a draft (peer started it) is never withheld.
    function holdRepaint(bid) {
        bid = parseInt(bid, 10) || 0;
        if (!isEditing(bid) || !isDraft(TnConfig.bracketData && TnConfig.bracketData[bid])) return false;
        _held[bid] = true;
        return true;
    }

    function runBid() {
        var sel = document.getElementById('tn-bv-bracket-select');
        return sel ? (parseInt(sel.value, 10) || 0) : 0;
    }

    function itemKey(it) {
        return it ? it.kind + ':' + (parseInt(it.MundaneId, 10) || 0) + ':' + String(it.Alias || '').toLowerCase() : '';
    }
    function indexOfKey(key) {
        if (!key) return -1;
        for (var i = 0; i < _ed.items.length; i++) if (itemKey(_ed.items[i]) === key) return i;
        return -1;
    }

    // Everything needed to reopen the editor as it was: text, highlighted row (by identity),
    // a waiting Enter, the original Enter-guard start, and the landed search results.
    function snapshot() {
        var e = _ed, term = e.input.value.trim();
        return { bracketId: e.bracketId, seed: e.seed, value: e.input.value, hiKey: itemKey(e.items[e.hi]),
            pendingEnter: !!e.pendingEnter, openedAt: e.openedAt,
            players: (!e.searching && e.playersTerm === term) ? e.players : null };
    }

    function closeEditor(keepRestore) {
        if (!_ed) return;
        if (keepRestore) _restore = snapshot();
        clearTimeout(_ed.timer);
        if (_ed.dd && _ed.dd.parentNode) _ed.dd.parentNode.removeChild(_ed.dd);
        // Remove the input too, so an orphaned line can never keep a live (and wrong-seed) editor.
        if (_ed.input && _ed.input.parentNode) _ed.input.parentNode.removeChild(_ed.input);
        if (!keepRestore) _restore = null;
        _ed = null;
    }

    // The user closed the editor (Esc, blur): one repaint, then any refetch owed by own writes.
    function finishEditing(bid) {
        var dirty = !!_dirty[bid];
        delete _dirty[bid];
        delete _held[bid];
        window.tnRenderBracketViz(bid);
        if (dirty) window.tnRefreshAndRender(bid);
    }

    // After an own write settles: refetch now, or (editor open) patch the draw in place and refetch on close.
    function settle(bid) {
        if (isEditing(bid)) { _dirty[bid] = true; patchDraw(bid); }
        else window.tnRefreshAndRender(bid);
    }

    // Rebuild the toolbar and every seat line except the one hosting the open editor.
    function patchDraw(bid) {
        var c = _ctx;
        if (!c || c.bracketId !== bid || !c.container || !c.container.isConnected) { _held[bid] = true; return; }
        var parts = c.bd.Participants || [], seats = seatEntrants(parts), eff = drawExtent(c.bd.Bracket, parts, seats);
        if (Math.max(4, nextPow2(eff)) !== Math.max(4, nextPow2(c.eff))) { _held[bid] = true; return; }   // draw resized: repaint on close
        c.seats = seats; c.eff = eff; c.placed = parts.length;
        var bar = c.container.querySelector('.tn-qb-toolbar');
        if (bar) bar.parentNode.replaceChild(buildToolbar(c.bd.Bracket, bid), bar);
        c.container.querySelectorAll('.tn-qb-line').forEach(function (line) {
            var seed = parseInt(line.dataset.seed, 10);
            if (_ed && _ed.seed === seed) return;
            var fresh = buildSeatLine(seed);
            line.parentNode.replaceChild(fresh, line);
            wireLine(fresh, bid);
        });
    }

    function bracketMundanes() {
        var ids = {}, nums = {}, aliases = {};
        (_ctx.bd.Participants || []).forEach(function (p) {
            var mid = parseInt(p.MundaneId, 10) || 0;
            if (mid > 0) ids[mid] = true; else aliases[String(p.Alias || '').toLowerCase()] = true;
            if (parseInt(p.ParticipantNumber, 10) > 0) nums[parseInt(p.ParticipantNumber, 10)] = true;
        });
        return { ids: ids, nums: nums, aliases: aliases };
    }

    // "On the roster" shows the most recently registered first, capped, so people just
    // entered on the Participants tab are one keystroke away. participant_number is
    // assigned MAX+1 per tournament, so it orders registrations.
    var ROSTER_LIMIT = 8;

    function rosterItems(term) {
        var inB = bracketMundanes(), t = term.toLowerCase(), seen = {}, out = [];
        var regs = (TnConfig.registrants || []).concat(_localRoster).slice().sort(function (a, b) {
            return (parseInt(b.ParticipantNumber, 10) || 0) - (parseInt(a.ParticipantNumber, 10) || 0);
        });
        regs.forEach(function (r) {
            if (out.length >= ROSTER_LIMIT) return;
            var num = parseInt(r.ParticipantNumber, 10) || 0, mid = parseInt(r.MundaneId, 10) || 0;
            var name = r.Alias || r.Persona || '';
            var key = mid > 0 ? 'm' + mid : 'a' + name.toLowerCase();
            if (!name || seen[key] || r.Status === 'withdrawn') return;
            if ((num && inB.nums[num]) || (mid && inB.ids[mid]) || (!mid && inB.aliases[name.toLowerCase()])) return;
            if (t && name.toLowerCase().indexOf(t) === -1 && String(r.Persona || '').toLowerCase().indexOf(t) === -1) return;
            seen[key] = true;
            out.push({ kind: 'roster', label: name, sub: 'On the roster', MundaneId: mid, Alias: name });
        });
        return out;
    }

    function renderItems() {
        var dd = _ed.dd;
        dd.innerHTML = '';
        var lastKind = null;
        _ed.items.forEach(function (it, i) {
            if (it.kind === 'roster' && lastKind !== 'roster') dd.appendChild(el('div', 'tn-qb-ac-hdr', 'On the roster'));
            lastKind = it.kind;
            var row = el('div', 'kn-ac-item' + (it.kind === 'alias' ? ' tn-qb-ac-alias' : '') + (i === _ed.hi ? ' tn-qb-ac-hi' : ''));
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', i === _ed.hi ? 'true' : 'false');
            if (it.kind === 'alias') {
                row.appendChild(el('b', '', it.label));
                row.appendChild(document.createTextNode(' '));
                row.appendChild(el('span', 'tn-qb-ac-sub', '(add without persona match)'));
            } else {
                row.appendChild(document.createTextNode(it.label));
                if (it.sub && it.kind === 'player') { row.appendChild(document.createTextNode(' ')); row.appendChild(el('span', 'tn-qb-ac-sub', '(' + it.sub + ')')); }
            }
            row.addEventListener('mousedown', function (e) { e.preventDefault(); pick(it); });
            dd.appendChild(row);
        });
        if (!_ed.items.length) { dd.classList.remove('kn-ac-open'); return; }
        window.tnFixedAcPosition(_ed.input, dd);
        dd.classList.add('kn-ac-open');
    }

    function setItems(term, players) {
        var inB = bracketMundanes(), roster = rosterItems(term), rosterMids = {};
        roster.forEach(function (r) { if (r.MundaneId) rosterMids[r.MundaneId] = true; });
        var items = roster.slice();
        (players || []).forEach(function (pl) {
            var mid = parseInt(pl.MundaneId || pl.mundane_id, 10) || 0;
            if (!mid || inB.ids[mid] || rosterMids[mid]) return;
            var sub = pl.KAbbr ? pl.KAbbr + (pl.PAbbr ? ':' + pl.PAbbr : '') : '';
            items.push({ kind: 'player', label: pl.Persona || pl.Name || '', sub: sub, MundaneId: mid, Alias: pl.Persona || pl.Name || '' });
        });
        if (term) items.push({ kind: 'alias', label: term, MundaneId: 0, Alias: term });
        _ed.items = items;
        _ed.players = players || [];
        _ed.playersTerm = term;
        _ed.hi = 0;
        renderItems();
    }

    function search(term) {
        var ed = _ed;
        clearTimeout(ed.timer);
        var req = ++ed.req;            // every call supersedes any in-flight response
        ed.searching = false;
        ed.pendingEnter = false;
        ed.wantHi = '';
        setItems(term, []);            // roster + alias row immediately
        if (term.length < 2 || !(TnConfig.searchKingdomId > 0)) return;
        ed.searching = true;
        ed.timer = setTimeout(function () {
            var url = TnConfig.uir + 'KingdomAjax/playersearch/' + TnConfig.searchKingdomId
                + '&scope=tiered' + (TnConfig.parkId > 0 ? '&ParkId=' + TnConfig.parkId : '')
                + '&q=' + encodeURIComponent(term);
            fetch(url).then(function (r) { return r.json(); }).then(function (data) {
                if (_ed !== ed || ed.req !== req) return;   // stale response
                ed.searching = false;
                setItems(term, Array.isArray(data) ? data : []);
                var w = indexOfKey(ed.wantHi);   // restored highlight that only exists among the results
                ed.wantHi = '';
                if (w >= 0) { ed.hi = w; renderItems(); }
                if (ed.pendingEnter) { ed.pendingEnter = false; if (ed.items[ed.hi]) pick(ed.items[ed.hi]); }
            }).catch(function () {
                if (_ed !== ed || ed.req !== req) return;
                ed.searching = false;
                if (ed.pendingEnter) { ed.pendingEnter = false; if (ed.items[ed.hi]) pick(ed.items[ed.hi]); }
            });
        }, 280);
    }

    // restore: a snapshot() when reopening after a re-render — keeps hi, pendingEnter and openedAt.
    function openEditor(line, seed, value, restore) {
        var r = restore || null;
        closeEditor();
        var hint = line.querySelector('.tn-qb-hint');
        if (hint) hint.parentNode.removeChild(hint);
        var input = el('input', 'tn-qb-input');
        input.type = 'text';
        input.maxLength = 100;
        input.autocomplete = 'off';
        input.placeholder = (_ctx.placed === 0 && seed === 1) ? 'Type to search for player' : 'Search player or type a name';
        input.setAttribute('aria-label', 'Seed ' + seed + ' fighter');
        input.value = value || '';
        line.appendChild(input);
        var dd = el('div', 'kn-ac-results tn-qb-ac');
        dd.setAttribute('role', 'listbox');
        document.body.appendChild(dd);
        _ed = { bracketId: _ctx.bracketId, seed: seed, input: input, dd: dd, items: [], hi: 0, timer: null, req: 0, searching: false, pendingEnter: false,
            openedAt: (r && r.openedAt) || Date.now(), players: [], playersTerm: '', wantHi: '' };
        // Remember the open editor so any re-render (own confirm refresh, peer change) reopens it.
        _restore = { bracketId: _ctx.bracketId, seed: seed, value: input.value };
        var bid = _ed.bracketId;
        input.addEventListener('input', function () {
            if (!_ed || _ed.input !== input) return;
            _restore = { bracketId: bid, seed: seed, value: input.value };
            search(input.value.trim());
        });
        input.addEventListener('keydown', function (e) {
            if (!_ed || _ed.input !== input) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (_ed.items.length) { _ed.hi = (_ed.hi + (e.key === 'ArrowDown' ? 1 : -1) + _ed.items.length) % _ed.items.length; renderItems(); }
                e.preventDefault();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                // Held/double Enter or IME commit must not auto-fill several seats from the roster.
                if (e.repeat || e.isComposing || Date.now() - _ed.openedAt < 300) return;
                var term = input.value.trim();
                if (term === '' && !_ed.items.length) return;
                // Persona search still pending/in flight: wait for it rather than adding an alias.
                var hl = _ed.items[_ed.hi];
                if (term.length >= 2 && _ed.searching && hl && hl.kind === 'alias') { _ed.pendingEnter = true; return; }
                if (_ed.items[_ed.hi]) pick(_ed.items[_ed.hi]);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeEditor();
                finishEditing(bid);
            }
        });
        input.addEventListener('blur', function () {
            setTimeout(function () {
                if (input.isConnected && _ed && _ed.input === input && document.activeElement !== input) {
                    closeEditor();
                    finishEditing(bid);
                }
            }, 200);
        });
        input.focus();
        var len = input.value.length;
        input.setSelectionRange(len, len);
        var term = input.value.trim();
        if (r && r.players && r.value.trim() === term) setItems(term, r.players);   // results already landed: same list, no flash
        else search(term);
        if (r) {
            if (r.pendingEnter && _ed.searching) _ed.pendingEnter = true;
            var i = indexOfKey(r.hiKey);
            if (i >= 0) { _ed.hi = i; renderItems(); }
            else if (r.hiKey && _ed.searching && !_ed.pendingEnter) _ed.wantHi = r.hiKey;
        }
    }

    function pick(item) {
        if (!_ed || !item || !item.Alias) return;
        var bid = _ed.bracketId, seed = _ed.seed, bd = _ctx.bd;
        var owed = !!_dirty[bid];
        delete _dirty[bid];
        delete _held[bid];
        closeEditor();
        var temp = { ParticipantId: -seed, Seed: seed, Alias: item.Alias, Persona: item.kind === 'player' ? item.label : '',
            MundaneId: item.MundaneId || 0, _pending: true };
        bd.Participants = (bd.Participants || []).concat([temp]);
        _focusNext[bid] = true;
        window.tnRenderBracketViz(bid);   // placeholder + the next seat's editor
        if (owed) window.tnRefreshAndRender(bid);   // its paint waits for that editor to close
        var fields = { TournamentId: TnConfig.tournamentId, Seed: seed, Alias: item.Alias };
        if (item.MundaneId > 0) fields.MundaneId = item.MundaneId;
        post('TournamentAjax/bracket/' + bid + '/quickplace', fields).then(function (d) {
            bd.Participants = (bd.Participants || []).filter(function (p) { return p !== temp; });
            if (!d || d.status !== 0) {
                var err = (d && d.error) || 'Could not place that fighter.';
                window.tnToast(err);
                // A peer took the seat: refetch and repaint now (the restore path moves the open editor).
                if (/^Seed \d+ was just filled/.test(err)) window.tnRefreshAndRender(bid, false, true);
                else settle(bid);
                return;
            }
            _localRoster.push({ ParticipantNumber: d.participantNumber, Alias: item.Alias, Persona: temp.Persona, MundaneId: temp.MundaneId, Status: 'active' });
            if (window.tnRefreshRoster) window.tnRefreshRoster();   // Participants tab picks up a new registrant
            var pid = parseInt(d.participantId, 10) || 0;
            var have = (bd.Participants || []).some(function (p) { return (parseInt(p.ParticipantId, 10) || 0) === pid; });
            if (pid && !have) {
                bd.Participants = (bd.Participants || []).concat([{ ParticipantId: pid, ParticipantNumber: d.participantNumber,
                    Seed: seed, Alias: item.Alias, Persona: temp.Persona, MundaneId: temp.MundaneId }]);
            }
            settle(bid);
        });
    }

    function afterRender(container, bracketId) {
        wireSwap(container, bracketId);
        // An editor that was open when the draw re-rendered (own placement, peer change, refresh).
        if (_restore && _restore.bracketId === bracketId) {
            var r = _restore;
            if (!_ctx.seats[r.seed] && r.seed <= _ctx.eff) {
                var same = container.querySelector('.tn-qb-empty[data-seed="' + r.seed + '"]');
                if (same) { openEditor(same, r.seed, r.value, r); return; }
            } else {
                window.tnToast('Seed ' + r.seed + ' was just filled.');
                var s2 = lowestEmptySeat(), ln2 = s2 && container.querySelector('.tn-qb-empty[data-seed="' + s2 + '"]');
                if (ln2) { openEditor(ln2, s2, r.value, r); return; }
            }
            _restore = null;
        }
        if (_focusNext[bracketId]) {
            delete _focusNext[bracketId];
            var s = lowestEmptySeat();
            var ln = s && container.querySelector('.tn-qb-empty[data-seed="' + s + '"]');
            if (ln) openEditor(ln, s, '');
        }
    }

    function decorateFilled(line, p, seed) {
        var x = el('button', 'tn-qb-clear', '×');
        x.type = 'button';
        x.setAttribute('aria-label', 'Remove ' + (p.Alias || p.Persona || 'fighter') + ' from this bracket');
        x.setAttribute('data-tip', 'Remove from bracket (stays on the roster)');
        x.addEventListener('click', function (e) { e.stopPropagation(); clearSeat(p); });
        line.appendChild(x);
        line.setAttribute('draggable', 'true');
        line.dataset.pid = p.ParticipantId;
    }

    function clearSeat(p) {
        var bid = _ctx.bracketId, bd = _ctx.bd;
        var pid = parseInt(p.ParticipantId, 10) || 0;
        bd.Participants = (bd.Participants || []).filter(function (q) { return (parseInt(q.ParticipantId, 10) || 0) !== pid; });
        window.tnRenderBracketViz(bid);
        post('TournamentAjax/bracket/' + bid + '/removeparticipant', { TournamentId: TnConfig.tournamentId, ParticipantId: pid })
            .then(function (d) {
                if (!d || d.status !== 0) { window.tnToast((d && d.error) || 'Could not remove that fighter.'); window.tnRefreshAndRender(bid, false, true); }
                else { settle(bid); if (window.tnRefreshRoster) window.tnRefreshRoster(); }   // their Brackets column changes
            });
    }

    // Persist a full seat map: Order[i] = participant id in seat i+1, 0 = empty seat.
    function commitSeats(bid, seatToPid) {
        var bd = _ctx.bd, max = 0;
        Object.keys(seatToPid).forEach(function (s) { max = Math.max(max, parseInt(s, 10)); });
        var order = [];
        for (var s = 1; s <= max; s++) order.push(seatToPid[s] || 0);
        (bd.Participants || []).forEach(function (p) {   // optimistic
            for (var k = 1; k <= max; k++) if (seatToPid[k] === p.ParticipantId) p.Seed = k;
        });
        window.tnRenderBracketViz(bid);
        post('TournamentAjax/bracket/' + bid + '/reorder', { TournamentId: TnConfig.tournamentId, Order: JSON.stringify(order) })
            .then(function (d) {
                if (!d || d.status !== 0) { window.tnToast((d && d.error) || 'Could not change the seeds.'); window.tnRefreshAndRender(bid, false, true); }
                else settle(bid);
            });
    }

    function hasPending() { return !!_ctx && (_ctx.bd.Participants || []).some(function (p) { return p._pending; }); }

    function shuffleSeeds(bid) {
        if (hasPending()) return;
        var pids = (_ctx.bd.Participants || []).map(function (p) { return p.ParticipantId; });
        for (var i = pids.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = pids[i]; pids[i] = pids[j]; pids[j] = t; }
        var map = {};
        pids.forEach(function (pid, i) { map[i + 1] = pid; });
        commitSeats(bid, map);
    }

    function swapSeats(bid, fromSeed, toSeed) {
        if (fromSeed === toSeed || hasPending() || toSeed > _ctx.eff) return;
        var map = {};
        Object.keys(_ctx.seats).forEach(function (s) { map[s] = _ctx.seats[s].ParticipantId; });
        var a = map[fromSeed], b = map[toSeed];
        map[toSeed] = a;
        if (b) map[fromSeed] = b; else delete map[fromSeed];
        commitSeats(bid, map);
    }

    // Called by the page whenever a non-draft paint replaces the draft: drop editor, dropdown and swap mode.
    // A refetch owed to the current Run bracket still runs (server stays authoritative); one owed to a
    // bracket the user left is dropped — its store already holds the patched own writes, and peer
    // changes keep arriving through the collab refetch.
    function teardown() {
        closeEditor(); _restore = null; _swapSrc = 0; _swapFresh = false; _held = {};
        var cur = runBid(), owed = Object.keys(_dirty);
        _dirty = {};
        owed.forEach(function (k) { if (parseInt(k, 10) === cur) window.tnRefreshAndRender(cur); });
    }

    var _swapSrc = 0;   // touch: seat chosen by long-press, waiting for a tap on the target
    var _swapFresh = false;   // true from long-press arming until the next touchstart: swallows the release click
    function wireSwap(container, bid) {
        container.querySelectorAll('.tn-qb-line:not(.tn-qb-fixed-bye)').forEach(function (line) { wireLine(line, bid); });
    }
    function wireLine(line, bid) {
        if (line.classList.contains('tn-qb-fixed-bye')) return;
        var seed = parseInt(line.dataset.seed, 10);
        if (_swapSrc && seed === _swapSrc) line.classList.add('tn-qb-swap-src');
        // Mouse: HTML5 drag a filled line onto any line.
        line.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', String(seed)); e.dataTransfer.effectAllowed = 'move'; });
        line.addEventListener('dragover', function (e) { e.preventDefault(); line.classList.add('tn-qb-drop'); });
        line.addEventListener('dragleave', function () { line.classList.remove('tn-qb-drop'); });
        line.addEventListener('drop', function (e) {
            e.preventDefault();
            line.classList.remove('tn-qb-drop');
            var from = parseInt(e.dataTransfer.getData('text/plain'), 10) || 0;
            if (!from || !_ctx.seats[from]) return;   // not a filled seat of this draw
            swapSeats(bid, from, seed);
        });
        // Touch: long-press a filled line (500ms) to pick it up, then tap the target line.
        var timer = null;
        line.addEventListener('touchstart', function () {
            _swapFresh = false;   // a new touch sequence began: the previous release click is history
            if (!line.classList.contains('tn-qb-filled')) return;
            timer = setTimeout(function () {
                _swapSrc = seed;
                _swapFresh = true;
                line.classList.add('tn-qb-swap-src');
                window.tnToast('Tap another line to swap seeds');
            }, 500);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach(function (ev) {
            line.addEventListener(ev, function () { clearTimeout(timer); }, { passive: true });
        });
        line.addEventListener('click', function (e) {
            if (!_swapSrc) return;
            e.stopPropagation();
            e.preventDefault();
            // The click synthesized by releasing the long-press must not cancel swap mode.
            if (_swapFresh && seed === _swapSrc) { _swapFresh = false; return; }
            var src = _swapSrc;
            _swapSrc = 0;
            if (src !== seed) swapSeats(bid, src, seed); else window.tnRenderBracketViz(bid);
        }, true);
    }

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
        _seedOrder: seedOrder,
        teardown: teardown,
        isEditing: isEditing,
        holdRepaint: holdRepaint,
        _test: {
            setCtx: function (c) { _ctx = c; },
            rosterItems: rosterItems,
            setItems: setItems,
            swapSeats: swapSeats,
            openEditor: openEditor,
            getEd: function () { return _ed; },
            getRestore: function () { return _restore; },
            getDirty: function () { return _dirty; },
            getHeld: function () { return _held; },
            pick: pick,
            runNote: runNote,
            getSwapSrc: function () { return _swapSrc; },
            setSwapSrc: function (v) { _swapSrc = v; }
        }
    };
})();
