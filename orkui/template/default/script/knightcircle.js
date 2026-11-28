/* Circle of Knights Manager: Roster, Candidates, Settings.
 * Reads only window.KC_CFG (set by KnightCircle_index.tpl):
 *   {kingdomId, kingdomName, userId, tab, access{CanManage,CanAppoint,...}, config, orders[{id,short,name}],
 *    stages, boardStages, transitions, urls{ajax, playersearch, player, kingdom}}
 * Vanilla JS (DataTables via jQuery for the roster table only). No native alert/confirm/prompt.
 * Never inject server/user text with innerHTML unless it went through esc().
 */
(function () {
    'use strict';
    var C = window.KC_CFG;
    if (!C) { return; }

    var CAN_MANAGE = !!(C.access && C.access.CanManage);
    var CAN_APPOINT = !!(C.access && C.access.CanAppoint);
    var STAGE_LABEL = {
        watchlist: 'Watchlist', masterhood: 'Masterhood', discussion: 'Under Discussion', voting: 'Voting',
        elect: 'Knight-Elect', knighted: 'Knighted', holding: 'Holding', dismissed: 'Dismissed'
    };
    var STAGE_HELP = {
        watchlist: 'Players being kept an eye on.',
        masterhood: 'Players who hold a Master of a ladder that leads to this knighthood.',
        discussion: 'The Circle is talking about them.',
        voting: 'A vote of the Circle is under way.',
        elect: 'Chosen, awaiting the knighting.',
        knighted: 'The knighthood has been granted. This happens by itself when the award is entered.',
        holding: 'Set aside for now, not rejected.',
        dismissed: 'Taken off the board. Kept on record, and not suggested again.'
    };
    var PATH = ['watchlist', 'masterhood', 'discussion', 'voting', 'elect', 'knighted'];
    var ORDER_BY_ID = {};
    (C.orders || []).forEach(function (o) { ORDER_BY_ID[o.id] = o; });

    /* ── helpers ─────────────────────────────────────────── */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function $(id) { return document.getElementById(id); }
    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (html != null) { e.innerHTML = html; }
        return e;
    }
    function pick(o, keys, def) {
        if (!o) { return def; }
        for (var i = 0; i < keys.length; i++) {
            if (o[keys[i]] !== undefined && o[keys[i]] !== null) { return o[keys[i]]; }
        }
        return def;
    }
    function orderShort(id) { var o = ORDER_BY_ID[id]; return o ? o.short : ''; }
    function orderChip(id) {
        var o = ORDER_BY_ID[id];
        if (!o) { return '<span class="kc-ochip kc-o-none">Order not set</span>'; }
        return '<span class="kc-ochip kc-o-' + esc(o.short.toLowerCase()) + '" data-tip="' + esc(o.name) + '">' + esc(o.short) + '</span>';
    }
    function playerLink(id, name) {
        return '<a class="kc-link" href="' + esc(C.urls.player + id) + '">' + esc(name || ('Player ' + id)) + '</a>';
    }
    function ago(d) {
        if (!d) { return ''; }
        var t = Date.parse(String(d).replace(' ', 'T'));
        if (isNaN(t)) { return String(d).slice(0, 10); }
        var days = Math.floor((Date.now() - t) / 86400000);
        if (days < 1) { return 'today'; }
        if (days < 31) { return days + 'd ago'; }
        if (days < 365) { return Math.floor(days / 30) + 'mo ago'; }
        return Math.floor(days / 365) + 'y ago';
    }
    function dateOnly(d) { return d ? String(d).slice(0, 10) : ''; }

    var toastTimer = null;
    function toast(msg, isErr) {
        var t = $('kc-toast');
        t.textContent = msg;
        t.className = 'kc-toast' + (isErr ? ' is-error' : '');
        t.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.hidden = true; }, isErr ? 6000 : 3000);
    }

    /* GET/POST against KnightCircleAjax. Resolves {status:0,...} or rejects with Error(message). */
    function api(action, params, post) {
        var url = C.urls.ajax + action + '/' + C.kingdomId;
        var opts = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        var qs = new URLSearchParams();
        Object.keys(params || {}).forEach(function (k) { if (params[k] !== undefined && params[k] !== null) { qs.append(k, params[k]); } });
        if (post) {
            opts.method = 'POST';
            qs.append('KingdomId', C.kingdomId);
            opts.body = qs;
        } else {
            url += (url.indexOf('&') > -1 ? '&' : '&') + qs.toString();
        }
        return fetch(url, opts).then(function (r) { return r.json(); }).then(function (j) {
            if (!j || j.status !== 0) {
                var e = new Error((j && j.error) || 'Something went wrong.');
                e.status = j && j.status;
                throw e;
            }
            return j;
        }, function () { throw new Error('Could not reach the server. Check your connection and try again.'); });
    }

    /* ── in-page modal ───────────────────────────────────── */
    var modalEl = null;
    function closeModal() { if (modalEl) { modalEl.remove(); modalEl = null; } }
    /* opts: {title, body (node), okLabel, onOk(done(err)), cancelLabel, danger} */
    function openModal(opts) {
        closeModal();
        var ov = el('div', 'kc-modal-ov');
        var m = el('div', 'kc-modal');
        m.setAttribute('role', 'dialog');
        m.setAttribute('aria-modal', 'true');
        m.appendChild(el('h3', 'kc-modal-title', esc(opts.title)));
        var body = el('div', 'kc-modal-body');
        body.appendChild(opts.body);
        m.appendChild(body);
        var err = el('div', 'kc-modal-err');
        err.hidden = true;
        m.appendChild(err);
        var foot = el('div', 'kc-modal-foot');
        var cancel = el('button', 'kc-btn', esc(opts.cancelLabel || 'Cancel'));
        cancel.type = 'button';
        cancel.addEventListener('click', closeModal);
        foot.appendChild(cancel);
        if (opts.onOk) {
            var ok = el('button', 'kc-btn ' + (opts.danger ? 'kc-btn-danger' : 'kc-btn-primary'), esc(opts.okLabel || 'Save'));
            ok.type = 'button';
            ok.addEventListener('click', function () {
                ok.disabled = true;
                err.hidden = true;
                opts.onOk(function (message) {
                    ok.disabled = false;
                    if (message) { err.textContent = message; err.hidden = false; }
                });
            });
            foot.appendChild(ok);
        }
        m.appendChild(foot);
        ov.appendChild(m);
        ov.addEventListener('mousedown', function (e) { if (e.target === ov) { closeModal(); } });
        document.body.appendChild(ov);
        modalEl = ov;
        var first = m.querySelector('input,select,textarea,button.kc-btn-primary');
        if (first) { first.focus(); }
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { if (modalEl) { closeModal(); } else { closePanel(); } }
    });

    /* ── player picker (fetch-driven; KingdomAjax/playersearch) ─ */
    function picker(onPick, placeholder) {
        var wrap = el('div', 'kc-pick');
        var input = el('input', 'kc-input');
        input.type = 'text';
        input.placeholder = placeholder || 'Search players in this kingdom...';
        input.autocomplete = 'off';
        var list = el('div', 'kc-pick-list');
        list.hidden = true;
        wrap.appendChild(input);
        wrap.appendChild(list);
        var timer = null, seq = 0;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2) { list.hidden = true; return; }
            timer = setTimeout(function () {
                var my = ++seq;
                fetch(C.urls.playersearch + '&scope=own&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (my !== seq) { return; }
                        list.innerHTML = '';
                        if (!data || !data.length) { list.appendChild(el('div', 'kc-pick-empty', 'No players found')); list.hidden = false; return; }
                        data.slice(0, 12).forEach(function (p) {
                            var row = el('button', 'kc-pick-item', esc(p.Persona) + ' <span class="kc-dim">' + esc((p.KAbbr || '') + (p.PAbbr ? ':' + p.PAbbr : '')) + '</span>');
                            row.type = 'button';
                            row.addEventListener('click', function () {
                                list.hidden = true;
                                input.value = p.Persona;
                                onPick(p);
                            });
                            list.appendChild(row);
                        });
                        list.hidden = false;
                    })
                    .catch(function () { list.hidden = true; });
            }, 250);
        });
        input.addEventListener('input', function () { onPick(null); });
        return wrap;
    }

    /* ── tabs ────────────────────────────────────────────── */
    var loaded = {};
    function showTab(name, push) {
        document.querySelectorAll('.kc-tab').forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.kc-pane').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-pane') === name);
        });
        if (push) {
            try { history.replaceState(null, '', location.pathname + location.search.replace(/([?&])tab=[^&]*&?/, '$1').replace(/[?&]$/, '') + (location.search.indexOf('?') < 0 ? '?' : '&') + 'tab=' + name); } catch (e) { /* ignore */ }
        }
        if (!loaded[name]) {
            loaded[name] = true;
            if (name === 'roster') { loadRoster(); }
            if (name === 'pipeline') { loadBoard(); }
            if (name === 'settings') { renderConfig(); loadOfficers(); }
        }
    }
    document.querySelectorAll('.kc-tab').forEach(function (t) {
        t.addEventListener('click', function () { showTab(t.getAttribute('data-tab'), true); });
    });

    /* ═══════════════ ROSTER ═══════════════ */
    var R = { knights: [], summary: null, config: null, windowStart: '', order: 0, eligibleOnly: false, residentOnly: false, dt: null };

    function loadRoster() {
        var msg = $('kc-roster-msg');
        msg.hidden = true;
        api('roster', {}, false).then(function (j) {
            R.knights = j.knights || [];
            R.summary = j.summary || null;
            R.config = j.config || C.config;
            R.windowStart = j.windowStart || '';
            renderRosterShell();
            renderRoster();
        }).catch(function (e) {
            msg.textContent = e.message;
            msg.className = 'kc-msg is-error';
            msg.hidden = false;
        });
    }

    function rulesSentence(c) {
        var unit = { count: 'sign-ins', days: 'days of attendance', weeks: 'weeks of attendance' }[c.signin_mode] || 'sign-ins';
        var where = c.signin_scope === 'anywhere' ? 'anywhere' : 'in this kingdom';
        var bits = ['at least ' + (+c.min_signins) + ' ' + unit + ' ' + where + ' in the last ' + (+c.window_months) + ' month' + (+c.window_months === 1 ? '' : 's')];
        if (c.online_mode === 'include') { bits[0] += ', online sign-ins counted'; }
        else if (c.online_mode === 'exclude') { bits[0] += ', online sign-ins not counted'; }
        else { bits[0] += ', online sign-ins counted as the kingdom does'; }
        if (+c.require_dues) { bits.push('paid dues' + (c.dues_scope === 'anywhere' ? ' anywhere' : ' in this kingdom')); }
        if (+c.require_waiver) { bits.push('a signed waiver'); }
        if (+c.require_residence) { bits.push('living in this kingdom'); }
        if (+c.exclude_suspended) { bits.push('not suspended'); }
        if (+c.min_knight_months) { bits.push('knighted at least ' + (+c.min_knight_months) + ' months'); }
        if (+c.use_kingdom_voting_rules) { bits.push('meeting the kingdom\'s own voting rules'); }
        return 'A knight is eligible with ' + bits.join('; ') + '.';
    }

    function renderRosterShell() {
        var c = R.config || C.config;
        var rules = $('kc-rules');
        rules.innerHTML = '<i class="fas fa-info-circle"></i> <span>' + esc(rulesSentence(c)) + '</span>'
            + (R.windowStart ? ' <span class="kc-dim">Window starts ' + esc(dateOnly(R.windowStart)) + '.</span>' : '')
            + (c.caveat ? '<div class="kc-caveat">' + esc(c.caveat) + '</div>' : '')
            + (!+c.include_principalities ? '' : '');
        var pills = $('kc-order-pills');
        pills.innerHTML = '';
        pills.appendChild(pill('All', 0, R.order === 0, function (id) { R.order = id; renderRosterShell(); renderRoster(); }));
        (C.orders || []).forEach(function (o) {
            pills.appendChild(pill(o.short, o.id, R.order === o.id, function (id) { R.order = id; renderRosterShell(); renderRoster(); }, o));
        });
    }
    function pill(label, id, active, fn, order) {
        var b = el('button', 'kc-pill' + (active ? ' is-active' : '') + (order ? ' kc-o-' + order.short.toLowerCase() : ''), esc(label));
        b.type = 'button';
        if (order) { b.setAttribute('data-tip', order.name); }
        b.addEventListener('click', function () { fn(id); });
        return b;
    }

    function visibleKnights() {
        return R.knights.filter(function (k) {
            if (R.order && !(k.Orders || []).some(function (o) { return o.AwardId === R.order; })) { return false; }
            if (R.eligibleOnly && !k.Eligible) { return false; }
            if (R.residentOnly && !k.Resident) { return false; }
            return true;
        });
    }

    function renderRoster() {
        var list = visibleKnights();
        var elig = list.filter(function (k) { return k.Eligible; }).length;
        var res = list.filter(function (k) { return k.Resident; }).length;
        var tiles = $('kc-tiles');
        tiles.innerHTML = '';
        [['Knights', list.length, 'Knights shown below.'],
         ['Eligible', elig, 'Meet every rule above.'],
         ['Not eligible', list.length - elig, 'Missing at least one rule. The Status column says which.'],
         ['Residents', res, 'Live in this kingdom. The rest hold a knighthood elsewhere but signed in here.']
        ].forEach(function (t) {
            var d = el('div', 'kc-tile', '<div class="kc-tile-n">' + esc(t[1]) + '</div><div class="kc-tile-l">' + esc(t[0]) + '</div>');
            d.setAttribute('data-tip', t[2]);
            tiles.appendChild(d);
        });
        if (R.summary && R.summary.ByOrder && !R.order) {
            (C.orders || []).forEach(function (o) {
                var b = R.summary.ByOrder[o.id];
                if (!b) { return; }
                var d = el('div', 'kc-tile kc-o-' + o.short.toLowerCase(), '<div class="kc-tile-n">' + esc(b.Count) + '</div><div class="kc-tile-l">' + esc(o.short) + ' <span class="kc-dim">(' + esc(b.Eligible) + ' eligible)</span></div>');
                d.setAttribute('data-tip', o.name);
                tiles.appendChild(d);
            });
        }
        var rows = list.map(function (k) {
            var orders = (k.Orders || []).map(function (o) { return orderChip(o.AwardId); }).join(' ');
            var sign = '<span class="kc-num">' + esc(k.SignIns) + '</span>';
            var dues = k.DuesPaid ? '<span class="kc-yes">Yes</span>' + (k.DuesUntil && k.DuesUntil.indexOf('9999') !== 0 ? ' <span class="kc-dim">to ' + esc(dateOnly(k.DuesUntil)) + '</span>' : (k.DuesUntil ? ' <span class="kc-dim">life</span>' : '')) : '<span class="kc-no">No</span>';
            var waiver = k.Waivered ? '<span class="kc-yes">Yes</span>' : '<span class="kc-no">No</span>';
            var months = k.KnightMonths == null ? '<span class="kc-dim">n/a</span>' : esc(k.KnightMonths >= 12 ? (Math.floor(k.KnightMonths / 12) + 'y ' + (k.KnightMonths % 12) + 'm') : (k.KnightMonths + 'm'));
            var status;
            if (k.Eligible) {
                status = '<span class="kc-badge kc-b-ok">Eligible</span>';
            } else {
                status = '<span class="kc-badge kc-b-no" data-tip="' + esc((k.Reasons || []).join('. ')) + '">Not eligible</span> <span class="kc-reasons">' + esc((k.Reasons || []).join('; ')) + '</span>';
            }
            if (!k.Resident) { status += ' <span class="kc-badge kc-b-away" data-tip="Lives in another kingdom, listed because of sign-ins here.">Visiting</span>'; }
            if (k.Suspended) { status += ' <span class="kc-badge kc-b-no">Suspended</span>'; }
            return [
                playerLink(k.MundaneId, k.Persona), orders, esc(k.ParkName || ''), sign, dues, waiver, months, status
            ];
        });
        if (window.jQuery && jQuery.fn && jQuery.fn.DataTable) {
            if (R.dt) { R.dt.destroy(); jQuery('#kc-roster-table tbody').empty(); }
            R.dt = jQuery('#kc-roster-table').DataTable({
                data: rows, deferRender: true, pageLength: 50, lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'All']],
                order: [[0, 'asc']], autoWidth: false,
                columnDefs: [{ targets: 7, orderable: false }, { targets: [1], type: 'html' }, { targets: 3, type: 'html-num' }],
                language: { emptyTable: 'No knights match.', zeroRecords: 'No knights match.' }
            });
        } else {
            var tb = document.querySelector('#kc-roster-table tbody');
            tb.innerHTML = rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; }).join('');
        }
    }

    $('kc-f-eligible').addEventListener('change', function () { R.eligibleOnly = this.checked; renderRoster(); });
    $('kc-f-resident').addEventListener('change', function () { R.residentOnly = this.checked; renderRoster(); });

    function csvCell(v) {
        var s = String(v == null ? '' : v);
        if (/^[=+\-@\t\r]/.test(s)) { s = "'" + s; }   // spreadsheet formula guard
        return '"' + s.replace(/"/g, '""') + '"';
    }
    $('kc-csv').addEventListener('click', function () {
        var head = ['Persona', 'Orders', 'Park', 'Resident', 'Sign-ins', 'Dues paid', 'Dues until', 'Waiver', 'Suspended', 'Months a knight', 'Eligible', 'Reasons'];
        var lines = [head.map(csvCell).join(',')];
        visibleKnights().forEach(function (k) {
            lines.push([k.Persona, (k.Orders || []).map(function (o) { return o.Name; }).join('; '), k.ParkName, k.Resident ? 'Yes' : 'No', k.SignIns,
                k.DuesPaid ? 'Yes' : 'No', k.DuesUntil && k.DuesUntil.indexOf('9999') !== 0 ? dateOnly(k.DuesUntil) : (k.DuesUntil ? 'Life' : ''),
                k.Waivered ? 'Yes' : 'No', k.Suspended ? 'Yes' : 'No', k.KnightMonths == null ? '' : k.KnightMonths,
                k.Eligible ? 'Yes' : 'No', (k.Reasons || []).join('; ')].map(csvCell).join(','));
        });
        var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'circle-of-knights-' + C.kingdomId + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    });

    /* ═══════════════ CANDIDATES ═══════════════ */
    var B = { stages: {}, suggestions: [], order: 0, showDismissed: false, drag: null, suggestOpen: true };

    function loadBoard() {
        var msg = $('kc-board-msg');
        msg.hidden = true;
        return api('board', {}, false).then(function (j) {
            B.stages = j.stages || {};
            B.suggestions = j.suggestions || [];
            renderBoard();
        }).catch(function (e) {
            msg.textContent = e.message;
            msg.className = 'kc-msg is-error';
            msg.hidden = false;
            $('kc-board').innerHTML = '';
            $('kc-path').innerHTML = '';
            $('kc-suggest').hidden = true;
        });
    }

    function cardsFor(stage) {
        return (B.stages[stage] || []).filter(function (c) {
            return !B.order || +pick(c, ['OrderAwardId', 'AwardId'], 0) === B.order;
        });
    }
    function cardId(c) { return +pick(c, ['CandidateId', 'candidate_id'], 0); }

    function allowedFrom(stage) { return (C.transitions && C.transitions[stage]) || []; }

    function renderBoard() {
        var pills = $('kc-board-pills');
        pills.innerHTML = '';
        pills.appendChild(pill('All', 0, B.order === 0, function (id) { B.order = id; renderBoard(); }));
        (C.orders || []).forEach(function (o) {
            pills.appendChild(pill(o.short, o.id, B.order === o.id, function (id) { B.order = id; renderBoard(); }, o));
        });
        renderSuggest();
        // chevron path with counts
        var path = $('kc-path');
        path.innerHTML = '';
        PATH.forEach(function (s, i) {
            var n = cardsFor(s).length;
            var chev = el('div', 'kc-chev kc-chev-' + s, '<span class="kc-chev-l">' + esc(STAGE_LABEL[s]) + '</span><span class="kc-chev-n">' + n + '</span>');
            chev.setAttribute('data-tip', STAGE_HELP[s]);
            path.appendChild(chev);
        });
        var board = $('kc-board');
        board.innerHTML = '';
        var main = el('div', 'kc-lanes');
        PATH.forEach(function (s) { main.appendChild(lane(s)); });
        board.appendChild(main);
        var side = el('div', 'kc-side');
        side.appendChild(lane('holding'));
        if (B.showDismissed) { side.appendChild(lane('dismissed')); }
        board.appendChild(side);
    }

    function lane(stage) {
        var cards = cardsFor(stage);
        var ln = el('div', 'kc-lane kc-lane-' + stage);
        ln.setAttribute('data-stage', stage);
        var head = el('div', 'kc-lane-head', '<span class="kc-lane-t">' + esc(STAGE_LABEL[stage]) + '</span><span class="kc-lane-n">' + cards.length + '</span>');
        head.setAttribute('data-tip', STAGE_HELP[stage]);
        ln.appendChild(head);
        var body = el('div', 'kc-lane-body');
        if (!cards.length) { body.appendChild(el('div', 'kc-lane-empty', 'Nobody here')); }
        cards.forEach(function (c) { body.appendChild(card(c, stage)); });
        ln.appendChild(body);
        if (CAN_MANAGE && stage !== 'knighted') {
            ln.addEventListener('dragover', function (e) {
                if (!B.drag) { return; }
                if (B.drag.stage !== stage && allowedFrom(B.drag.stage).indexOf(stage) > -1) { e.preventDefault(); ln.classList.add('is-over'); }
            });
            ln.addEventListener('dragleave', function (e) { if (!ln.contains(e.relatedTarget)) { ln.classList.remove('is-over'); } });
            ln.addEventListener('drop', function (e) {
                e.preventDefault();
                ln.classList.remove('is-over');
                var d = B.drag;
                B.drag = null;
                if (d && d.stage !== stage) { requestMove(d.card, d.stage, stage); }
            });
        }
        return ln;
    }

    function card(c, stage) {
        var id = cardId(c);
        var mid = +pick(c, ['MundaneId'], 0);
        var oid = +pick(c, ['OrderAwardId', 'AwardId'], 0);
        var d = el('div', 'kc-card-c kc-o-edge-' + orderShort(oid).toLowerCase());
        d.setAttribute('data-id', id);
        d.tabIndex = 0;
        var src = pick(c, ['Source'], '');
        var srcBadge = src === 'master_auto' ? '<span class="kc-badge kc-b-auto" data-tip="Added automatically because they hold a Master of this order\'s ladder.">Auto</span>' : '';
        var rank = pick(c, ['Rank', 'LadderRank'], null);
        d.innerHTML = '<div class="kc-c-top"><span class="kc-c-name">' + esc(pick(c, ['Persona'], 'Player ' + mid)) + '</span>' + orderChip(oid) + '</div>'
            + '<div class="kc-c-sub">' + esc(pick(c, ['ParkName'], '')) + (rank ? ' <span class="kc-dim">rank ' + esc(rank) + '</span>' : '') + ' ' + srcBadge + '</div>'
            + '<div class="kc-c-foot"><span class="kc-dim">' + esc(ago(pick(c, ['StageChangedAt', 'stage_changed_at'], ''))) + '</span></div>';
        d.addEventListener('click', function (e) { if (!e.target.closest('.kc-c-move')) { openPanel(id); } });
        d.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target === d) { openPanel(id); } });
        if (CAN_MANAGE && allowedFrom(stage).length) {
            d.draggable = true;
            d.addEventListener('dragstart', function (e) {
                B.drag = { card: c, stage: stage };
                d.classList.add('is-drag');
                try { e.dataTransfer.setData('text/plain', String(id)); e.dataTransfer.effectAllowed = 'move'; } catch (x) { /* ignore */ }
            });
            d.addEventListener('dragend', function () { d.classList.remove('is-drag'); B.drag = null; document.querySelectorAll('.kc-lane.is-over').forEach(function (l) { l.classList.remove('is-over'); }); });
            var mv = el('button', 'kc-c-move', 'Move to <i class="fas fa-caret-down"></i>');
            mv.type = 'button';
            mv.addEventListener('click', function (e) { e.stopPropagation(); moveMenu(mv, c, stage); });
            d.querySelector('.kc-c-foot').appendChild(mv);
        }
        return d;
    }

    var menuEl = null;
    function closeMenu() { if (menuEl) { menuEl.remove(); menuEl = null; } }
    document.addEventListener('click', closeMenu);
    function moveMenu(btn, c, from) {
        closeMenu();
        var m = el('div', 'kc-menu');
        allowedFrom(from).forEach(function (to) {
            var b = el('button', 'kc-menu-item', esc(STAGE_LABEL[to]));
            b.type = 'button';
            b.addEventListener('click', function (e) { e.stopPropagation(); closeMenu(); requestMove(c, from, to); });
            m.appendChild(b);
        });
        document.body.appendChild(m);
        var r = btn.getBoundingClientRect();
        m.style.top = (r.bottom + window.scrollY + 2) + 'px';
        m.style.left = Math.max(8, Math.min(r.left + window.scrollX, window.innerWidth - 190)) + 'px';
        menuEl = m;
        setTimeout(function () { document.addEventListener('click', closeMenu, { once: true }); }, 0);
    }

    function requestMove(c, from, to) {
        if (allowedFrom(from).indexOf(to) < 0) { toast('A candidate cannot move from ' + STAGE_LABEL[from] + ' to ' + STAGE_LABEL[to] + '.', true); return; }
        if (from === 'voting' || to === 'dismissed') { moveModal(c, from, to); return; }
        doMove(c, to, '', null).catch(function (e) { toast(e.message, true); });
    }
    function doMove(c, to, comment, votes) {
        var p = { CandidateId: cardId(c), ToStage: to, Comment: comment || '' };
        if (votes) { p.VotesFor = votes.f; p.VotesAgainst = votes.a; p.VotesAbstain = votes.b; }
        return api('candidate_move', p, true).then(function () {
            toast(pick(c, ['Persona'], 'Candidate') + ' moved to ' + STAGE_LABEL[to] + '.');
            return loadBoard();
        });
    }

    /* Leaving Voting records the outcome and the count; dismissing asks for a reason. */
    function moveModal(c, from, to) {
        var body = el('div');
        var fromVoting = from === 'voting';
        var html = '';
        if (fromVoting) {
            html += '<div class="kc-field"><label class="kc-label">Outcome</label><div class="kc-radios">';
            allowedFrom('voting').forEach(function (s) {
                var lab = { elect: 'Elected (becomes Knight-Elect)', discussion: 'Back to discussion', holding: 'Not now (Holding)' }[s] || STAGE_LABEL[s];
                html += '<label class="kc-radio"><input type="radio" name="kc-outcome" value="' + esc(s) + '"' + (s === to ? ' checked' : '') + '> ' + esc(lab) + '</label>';
            });
            html += '</div></div><div class="kc-field"><label class="kc-label">Votes</label><div class="kc-votes">'
                + '<label>For <input class="kc-input kc-num-in" type="number" min="0" id="kc-v-f" value="0"></label>'
                + '<label>Against <input class="kc-input kc-num-in" type="number" min="0" id="kc-v-a" value="0"></label>'
                + '<label>Abstain <input class="kc-input kc-num-in" type="number" min="0" id="kc-v-b" value="0"></label></div></div>';
        } else {
            html += '<p>' + esc(pick(c, ['Persona'], 'This candidate')) + ' will be taken off the board. The record is kept, and they will not be suggested again. You can bring them back from Show dismissed.</p>';
        }
        html += '<div class="kc-field"><label class="kc-label" for="kc-m-comment">Comment ' + (fromVoting ? '' : '(optional)') + '</label><textarea class="kc-input" id="kc-m-comment" rows="3" maxlength="500"></textarea></div>';
        body.innerHTML = html;
        openModal({
            title: fromVoting ? 'Close the vote for ' + pick(c, ['Persona'], 'candidate') : 'Dismiss ' + pick(c, ['Persona'], 'candidate'),
            body: body, okLabel: fromVoting ? 'Record outcome' : 'Dismiss', danger: !fromVoting,
            onOk: function (done) {
                var dest = to, votes = null;
                if (fromVoting) {
                    var r = body.querySelector('input[name="kc-outcome"]:checked');
                    dest = r ? r.value : to;
                    votes = { f: Math.max(0, +body.querySelector('#kc-v-f').value || 0), a: Math.max(0, +body.querySelector('#kc-v-a').value || 0), b: Math.max(0, +body.querySelector('#kc-v-b').value || 0) };
                }
                doMove(c, dest, body.querySelector('#kc-m-comment').value, votes).then(function () { closeModal(); }, function (e) { done(e.message); });
            }
        });
    }

    /* Suggested drawer */
    function renderSuggest() {
        var box = $('kc-suggest');
        var items = (B.suggestions || []).filter(function (s) { return !B.order || +pick(s, ['OrderAwardId'], 0) === B.order || +pick(s, ['OrderAwardId'], 0) === 0; });
        if (!items.length) { box.hidden = true; return; }
        box.hidden = false;
        box.innerHTML = '';
        var head = el('button', 'kc-suggest-head', '<i class="fas fa-lightbulb"></i> Suggested <span class="kc-lane-n">' + items.length + '</span> <span class="kc-dim">Players at or above ladder rank ' + esc(C.config.watchlist_min_rank) + ' who are not yet on the board.</span> <i class="fas fa-chevron-' + (B.suggestOpen ? 'up' : 'down') + ' kc-suggest-car"></i>');
        head.type = 'button';
        head.addEventListener('click', function () { B.suggestOpen = !B.suggestOpen; renderSuggest(); });
        box.appendChild(head);
        if (!B.suggestOpen) { return; }
        var list = el('div', 'kc-suggest-list');
        items.forEach(function (s) {
            var mid = +pick(s, ['MundaneId'], 0);
            var oid = +pick(s, ['OrderAwardId'], 0);
            var row = el('div', 'kc-sg');
            var rank = pick(s, ['Rank', 'LadderRank'], '');
            var ladder = pick(s, ['LadderName', 'AwardName', 'Ladder'], '');
            row.innerHTML = '<div class="kc-sg-main"><span class="kc-c-name">' + playerLink(mid, pick(s, ['Persona'], '')) + '</span> ' + (oid ? orderChip(oid) : '')
                + '<div class="kc-c-sub">' + esc(pick(s, ['ParkName'], '')) + (ladder ? ' &middot; ' + esc(ladder) : '') + (rank !== '' ? ' <span class="kc-dim">rank ' + esc(rank) + '</span>' : '') + '</div></div>';
            if (CAN_MANAGE) {
                var act = el('div', 'kc-sg-act');
                var sel = null;
                if (!oid) {
                    sel = el('select', 'kc-input kc-sel');
                    sel.innerHTML = '<option value="0">Choose order...</option>' + (C.orders || []).map(function (o) { return '<option value="' + o.id + '">' + esc(o.short) + '</option>'; }).join('');
                    sel.setAttribute('data-tip', 'This ladder does not point to one order. Pick the order they are being considered for.');
                    act.appendChild(sel);
                }
                var pr = el('button', 'kc-btn kc-btn-primary kc-btn-sm', 'Promote');
                pr.type = 'button';
                pr.setAttribute('data-tip', 'Put them on the Watchlist.');
                pr.addEventListener('click', function () {
                    var useOid = oid || (sel ? +sel.value : 0);
                    if (!useOid) { toast('Choose which order first.', true); return; }
                    pr.disabled = true;
                    api('candidate_add', { MundaneId: mid, OrderAwardId: useOid, Stage: 'watchlist', Source: 'suggested' }, true)
                        .then(function () { toast('Added to the Watchlist.'); return loadBoard(); })
                        .catch(function (e) { pr.disabled = false; toast(e.message, true); });
                });
                var ds = el('button', 'kc-btn kc-btn-sm', 'Dismiss');
                ds.type = 'button';
                ds.setAttribute('data-tip', 'Stop suggesting this player for this order. Kept on record under Show dismissed.');
                ds.addEventListener('click', function () {
                    var useOid = oid || (sel ? +sel.value : 0);
                    if (!useOid) { toast('Choose which order first.', true); return; }
                    ds.disabled = true;
                    api('candidate_add', { MundaneId: mid, OrderAwardId: useOid, Stage: 'dismissed', Source: 'suggested' }, true)
                        .then(function () { toast('Dismissed.'); return loadBoard(); })
                        .catch(function (e) { ds.disabled = false; toast(e.message, true); });
                });
                act.appendChild(pr);
                act.appendChild(ds);
                row.appendChild(act);
            }
            list.appendChild(row);
        });
        box.appendChild(list);
    }

    $('kc-show-dismissed').addEventListener('change', function () { B.showDismissed = this.checked; renderBoard(); });
    $('kc-refresh').addEventListener('click', function () {
        var b = this;
        b.disabled = true;
        loadBoard().then(function () { toast('Board refreshed.'); }).then(function () { b.disabled = false; }, function () { b.disabled = false; });
    });

    var addBtn = $('kc-add-cand');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var picked = null;
            var body = el('div');
            var pk = picker(function (p) { picked = p; });
            var f1 = el('div', 'kc-field');
            f1.appendChild(el('label', 'kc-label', 'Player'));
            f1.appendChild(pk);
            var f2 = el('div', 'kc-field');
            f2.innerHTML = '<label class="kc-label" for="kc-a-order">Order</label><select class="kc-input" id="kc-a-order"><option value="0">Choose order...</option>'
                + (C.orders || []).map(function (o) { return '<option value="' + o.id + '">' + esc(o.name) + '</option>'; }).join('') + '</select>';
            var f3 = el('div', 'kc-field');
            f3.innerHTML = '<label class="kc-label" for="kc-a-comment">Why (optional)</label><textarea class="kc-input" id="kc-a-comment" rows="2" maxlength="500"></textarea>';
            body.appendChild(f1); body.appendChild(f2); body.appendChild(f3);
            openModal({
                title: 'Add candidate', body: body, okLabel: 'Add to Watchlist',
                onOk: function (done) {
                    var oid = +body.querySelector('#kc-a-order').value;
                    if (!picked) { done('Search for a player and pick one from the list.'); return; }
                    if (!oid) { done('Choose which order they are being considered for.'); return; }
                    api('candidate_add', { MundaneId: picked.MundaneId, OrderAwardId: oid, Comment: body.querySelector('#kc-a-comment').value, Stage: 'watchlist', Source: 'manual' }, true)
                        .then(function () { closeModal(); toast('Added to the Watchlist.'); return loadBoard(); })
                        .catch(function (e) { done(e.message); });
                }
            });
        });
    }

    /* Side panel: facts, history, notes */
    var panelFor = 0;
    function closePanel() {
        var p = $('kc-panel');
        p.hidden = true;
        p.setAttribute('aria-hidden', 'true');
        $('kc-overlay').hidden = true;
        panelFor = 0;
    }
    $('kc-overlay').addEventListener('click', closePanel);

    function openPanel(id) {
        panelFor = id;
        var p = $('kc-panel');
        p.hidden = false;
        p.setAttribute('aria-hidden', 'false');
        $('kc-overlay').hidden = false;
        p.innerHTML = '<div class="kc-panel-loading">Loading...</div>';
        api('candidate', { CandidateId: id }, false).then(function (j) {
            if (panelFor !== id) { return; }
            renderPanel(j, id);
        }).catch(function (e) {
            p.innerHTML = '';
            p.appendChild(panelHead('Candidate'));
            p.appendChild(el('div', 'kc-msg is-error', esc(e.message)));
        });
    }
    function panelHead(title) {
        var h = el('div', 'kc-panel-head', '<h2 class="kc-panel-title">' + esc(title) + '</h2>');
        var x = el('button', 'kc-x', '<i class="fas fa-times"></i>');
        x.type = 'button';
        x.setAttribute('aria-label', 'Close');
        x.addEventListener('click', closePanel);
        h.appendChild(x);
        return h;
    }
    function factRow(k, v) { return '<div class="kc-fact"><span class="kc-fact-k">' + esc(k) + '</span><span class="kc-fact-v">' + v + '</span></div>'; }
    function factValue(k, v) {
        if (v === true) { return '<span class="kc-yes">Yes</span>'; }
        if (v === false) { return '<span class="kc-no">No</span>'; }
        if (v === null || v === undefined || v === '') { return '<span class="kc-dim">n/a</span>'; }
        return esc(v);
    }
    function renderPanel(j, id) {
        var p = $('kc-panel');
        var c = j.candidate || {};
        var mid = +pick(c, ['MundaneId'], 0);
        var oid = +pick(c, ['OrderAwardId', 'AwardId'], 0);
        var stage = pick(c, ['Stage'], '');
        p.innerHTML = '';
        p.appendChild(panelHead(pick(c, ['Persona'], 'Candidate')));
        var sum = el('div', 'kc-panel-sum', playerLink(mid, 'Open player record') + ' ' + orderChip(oid) + ' <span class="kc-badge kc-b-stage">' + esc(STAGE_LABEL[stage] || stage) + '</span>');
        p.appendChild(sum);
        // facts
        var facts = j.facts || {};
        var fh = '';
        Object.keys(facts).forEach(function (k) {
            var v = facts[k];
            if (v !== null && typeof v === 'object') { return; }
            fh += factRow(k.replace(/([a-z])([A-Z])/g, '$1 $2'), factValue(k, v));
        });
        p.appendChild(el('h3', 'kc-sec', 'Facts'));
        p.appendChild(el('div', 'kc-facts', fh || '<span class="kc-dim">No facts on record.</span>'));
        // history
        p.appendChild(el('h3', 'kc-sec', 'History'));
        var tl = el('ol', 'kc-timeline');
        (j.history || []).forEach(function (h) {
            var from = pick(h, ['FromStage'], null), to = pick(h, ['ToStage'], '');
            var votes = '';
            var vo = h.Votes && typeof h.Votes === 'object' ? h.Votes : null;
            if (vo) { votes = '<div class="kc-votes-line">Votes: ' + esc(vo.For || 0) + ' for, ' + esc(vo.Against || 0) + ' against, ' + esc(vo.Abstain || 0) + ' abstain</div>'; }
            var actor = pick(h, ['ActorPersona', 'Actor'], '') || (+pick(h, ['ActorId'], 0) === 0 ? 'System' : '');
            tl.appendChild(el('li', 'kc-tl-item', '<div class="kc-tl-top"><strong>' + esc(from ? (STAGE_LABEL[from] || from) + ' to ' : 'Added to ') + esc(STAGE_LABEL[to] || to) + '</strong> <span class="kc-dim">' + esc(dateOnly(pick(h, ['CreatedAt'], ''))) + (actor ? ' by ' + esc(actor) : '') + '</span></div>'
                + votes + (pick(h, ['Comment'], '') ? '<div class="kc-tl-c">' + esc(pick(h, ['Comment'], '')) + '</div>' : '')));
        });
        if (!tl.children.length) { tl.appendChild(el('li', 'kc-dim', 'Nothing yet.')); }
        p.appendChild(tl);
        // notes
        p.appendChild(el('h3', 'kc-sec', 'Notes'));
        var nl = el('div', 'kc-notes');
        (j.notes || []).forEach(function (n) {
            var nid = +pick(n, ['NoteId'], 0);
            var mine = +pick(n, ['AuthorId'], 0) === C.userId;
            var d = el('div', 'kc-note', '<div class="kc-note-top"><strong>' + esc(pick(n, ['AuthorPersona', 'Author'], 'Someone')) + '</strong> <span class="kc-dim">' + esc(dateOnly(pick(n, ['CreatedAt'], ''))) + '</span></div><div class="kc-note-b">' + esc(pick(n, ['Body'], '')) + '</div>');
            if (mine || CAN_MANAGE) {
                var del = el('button', 'kc-link-btn', 'Delete');
                del.type = 'button';
                del.addEventListener('click', function () {
                    if (del.getAttribute('data-armed') !== '1') { del.setAttribute('data-armed', '1'); del.textContent = 'Click again to delete'; return; }
                    api('note_delete', { NoteId: nid }, true).then(function () { openPanel(id); }).catch(function (e) { toast(e.message, true); });
                });
                d.appendChild(del);
            }
            nl.appendChild(d);
        });
        if (!nl.children.length) { nl.appendChild(el('div', 'kc-dim', 'No notes.')); }
        p.appendChild(nl);
        if (CAN_MANAGE) {
            var ta = el('textarea', 'kc-input');
            ta.rows = 3; ta.maxLength = 4000; ta.placeholder = 'Add a note for the Circle...';
            var add = el('button', 'kc-btn kc-btn-primary', 'Add note');
            add.type = 'button';
            add.addEventListener('click', function () {
                if (!ta.value.trim()) { return; }
                add.disabled = true;
                api('note_add', { CandidateId: id, Body: ta.value }, true).then(function () { openPanel(id); }).catch(function (e) { add.disabled = false; toast(e.message, true); });
            });
            p.appendChild(ta);
            p.appendChild(add);
        }
    }

    /* ═══════════════ SETTINGS ═══════════════ */
    var CFG_FIELDS = [
        { k: 'min_signins', t: 'num', l: 'Minimum sign-ins', min: 0, max: 52, tip: 'How many sign-ins a knight needs inside the window.' },
        { k: 'window_months', t: 'num', l: 'Window (months)', min: 1, max: 24, tip: 'How far back to count.' },
        { k: 'signin_mode', t: 'sel', l: 'Count', o: [['count', 'Every sign-in'], ['days', 'Distinct days'], ['weeks', 'Distinct weeks']], tip: 'What counts as one.' },
        { k: 'signin_scope', t: 'sel', l: 'Where sign-ins count', o: [['kingdom', 'In this kingdom'], ['anywhere', 'Anywhere']] },
        { k: 'online_mode', t: 'sel', l: 'Online sign-ins', o: [['kingdom_setting', 'As the kingdom counts them'], ['include', 'Count them'], ['exclude', 'Do not count them']] },
        { k: 'require_dues', t: 'bool', l: 'Require paid dues' },
        { k: 'dues_scope', t: 'sel', l: 'Where dues count', o: [['kingdom', 'In this kingdom'], ['anywhere', 'Anywhere']] },
        { k: 'require_waiver', t: 'bool', l: 'Require a signed waiver' },
        { k: 'require_residence', t: 'bool', l: 'Require living in this kingdom', tip: 'Off lets visiting knights who sign in here count as eligible.' },
        { k: 'include_principalities', t: 'bool', l: 'Include principalities', tip: 'Count the kingdom\'s principalities as part of it.' },
        { k: 'exclude_suspended', t: 'bool', l: 'Exclude suspended players' },
        { k: 'min_knight_months', t: 'num', l: 'Minimum months as a knight', min: 0, max: 600 },
        { k: 'use_kingdom_voting_rules', t: 'bool', l: 'Also apply the kingdom\'s voting rules', tip: 'Only has an effect if the kingdom has its own voting rules stored.' },
        { k: 'watchlist_min_rank', t: 'num', l: 'Suggest players from ladder rank', min: 1, max: 12, tip: 'Players at or above this rank on a knight ladder are suggested for the Watchlist.' },
        { k: 'officers_see_pipeline', t: 'bool', l: 'Kingdom officers can see Candidates', tip: 'Off keeps the pipeline to the Circle\'s officers.' }
    ];
    function renderConfig() {
        var f = $('kc-config-form');
        var c = C.config;
        f.innerHTML = '';
        var grid = el('div', 'kc-grid');
        CFG_FIELDS.forEach(function (fd) {
            var row = el('div', 'kc-field');
            var id = 'kc-cfg-' + fd.k;
            var dis = CAN_MANAGE ? '' : ' disabled';
            var lab = '<label class="kc-label" for="' + id + '"' + (fd.tip ? ' data-tip="' + esc(fd.tip) + '"' : '') + '>' + esc(fd.l) + '</label>';
            if (fd.t === 'num') {
                row.innerHTML = lab + '<input class="kc-input" type="number" id="' + id + '" name="' + fd.k + '" min="' + fd.min + '" max="' + fd.max + '" value="' + esc(c[fd.k]) + '"' + dis + '>';
            } else if (fd.t === 'sel') {
                row.innerHTML = lab + '<select class="kc-input" id="' + id + '" name="' + fd.k + '"' + dis + '>' + fd.o.map(function (o) { return '<option value="' + o[0] + '"' + (String(c[fd.k]) === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>';
            } else {
                row.className = 'kc-field kc-field-bool';
                row.innerHTML = '<label class="kc-check"' + (fd.tip ? ' data-tip="' + esc(fd.tip) + '"' : '') + '><input type="checkbox" id="' + id + '" name="' + fd.k + '"' + (+c[fd.k] ? ' checked' : '') + dis + '> ' + esc(fd.l) + '</label>';
            }
            grid.appendChild(row);
        });
        f.appendChild(grid);
        var cv = el('div', 'kc-field');
        cv.innerHTML = '<label class="kc-label" for="kc-cfg-caveat" data-tip="Shown above the roster. Use it for rules the numbers cannot capture.">Note shown with the roster</label><textarea class="kc-input" id="kc-cfg-caveat" name="caveat" rows="3" maxlength="1000"' + (CAN_MANAGE ? '' : ' disabled') + '>' + esc(c.caveat || '') + '</textarea>';
        f.appendChild(cv);
        if (CAN_MANAGE) {
            var btn = el('button', 'kc-btn kc-btn-primary', 'Save rules');
            btn.type = 'submit';
            var st = el('span', 'kc-save-st');
            f.appendChild(btn);
            f.appendChild(st);
            f.onsubmit = function (e) {
                e.preventDefault();
                var p = {};
                CFG_FIELDS.forEach(function (fd) {
                    var i = $('kc-cfg-' + fd.k);
                    p[fd.k] = fd.t === 'bool' ? (i.checked ? 1 : 0) : i.value;
                });
                p.caveat = $('kc-cfg-caveat').value;
                btn.disabled = true;
                st.textContent = '';
                api('config_save', p, true).then(function (j) {
                    btn.disabled = false;
                    if (j.config) { C.config = j.config; }
                    toast('Rules saved.');
                    loaded.roster = false;     // the roster must be recomputed under the new rules
                    if (document.querySelector('.kc-tab[data-tab="roster"]').classList.contains('is-active')) { loaded.roster = true; loadRoster(); }
                }).catch(function (er) { btn.disabled = false; st.textContent = er.message; st.className = 'kc-save-st is-error'; toast(er.message, true); });
            };
        }
    }

    function loadOfficers() {
        var box = $('kc-officers');
        box.innerHTML = '<div class="kc-dim">Loading...</div>';
        api('admins', {}, false).then(function (j) { renderOfficers(j.admins || []); })
            .catch(function (e) { box.innerHTML = ''; box.appendChild(el('div', 'kc-msg is-error', esc(e.message))); });
    }
    function renderOfficers(admins) {
        var box = $('kc-officers');
        box.innerHTML = '';
        var list = el('div', 'kc-officer-list');
        admins.forEach(function (a) {
            var isG = a.Role === 'gmok';
            var row = el('div', 'kc-officer', '<span class="kc-badge ' + (isG ? 'kc-b-gmok' : 'kc-b-dep') + '">' + (isG ? 'Guardian of the Circle' : 'Deputy') + '</span> ' + playerLink(a.MundaneId, a.Persona));
            if (CAN_APPOINT) {
                var rm = el('button', 'kc-link-btn', 'Remove');
                rm.type = 'button';
                rm.addEventListener('click', function () {
                    if (rm.getAttribute('data-armed') !== '1') { rm.setAttribute('data-armed', '1'); rm.textContent = 'Click again to remove'; return; }
                    api('admin_remove', { CircleAdminId: a.CircleAdminId }, true).then(function (j) { toast('Removed.'); renderOfficers(j.admins || []); }).catch(function (e) { toast(e.message, true); });
                });
                row.appendChild(rm);
            }
            list.appendChild(row);
        });
        if (!admins.length) { list.appendChild(el('div', 'kc-dim', 'No Guardian or deputies named yet.')); }
        box.appendChild(list);
        if (CAN_APPOINT) {
            var picked = null;
            var add = el('div', 'kc-officer-add');
            var pk = picker(function (p) { picked = p; }, 'Search players to appoint...');
            var g = el('button', 'kc-btn kc-btn-primary', 'Make Guardian (GMoK)');
            g.type = 'button';
            g.setAttribute('data-tip', 'Names this player the kingdom\'s Guardian of the Circle, replacing the current one. They must already be a knight.');
            var d = el('button', 'kc-btn', 'Add deputy');
            d.type = 'button';
            function go(action, btn) {
                if (!picked) { toast('Search for a player and pick one from the list.', true); return; }
                btn.disabled = true;
                api(action, { MundaneId: picked.MundaneId }, true).then(function (j) { toast('Saved.'); renderOfficers(j.admins || []); })
                    .catch(function (e) { btn.disabled = false; toast(e.message, true); });
            }
            g.addEventListener('click', function () { go('gmok_set', g); });
            d.addEventListener('click', function () { go('deputy_add', d); });
            add.appendChild(pk); add.appendChild(g); add.appendChild(d);
            box.appendChild(add);
        } else {
            box.appendChild(el('p', 'kc-hint', 'Only the kingdom\'s monarchy (or an ORK admin) can name the Guardian and deputies.'));
        }
    }

    /* ── boot ────────────────────────────────────────────── */
    if (!CAN_MANAGE) {
        var addb = $('kc-add-cand');
        if (addb) { addb.remove(); }
    }
    showTab(C.tab || 'roster', false);
})();
