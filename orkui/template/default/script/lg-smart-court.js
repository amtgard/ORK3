/*
 * Smart Planning Grid: the Smart Court layer over the ladder grid. Emitted only for
 * viewers with court authority. Rank, matching, and limit rules live in LgSmartCore;
 * this file owns the DOM, the requests, and localStorage.
 *
 * Names reach the page through textContent and setAttribute only.
 *
 * A click repaints its own cell and never walks the rows: the indexes below make each
 * cell's lookups O(1), so a 2,000-row kingdom grid stays responsive.
 */
(function () {
    'use strict';

    var cfg = window.LgSmartConfig, G = window.LgGrid, C = window.LgSmartCore;
    if (!cfg || !G || !C) return;

    // Per viewer: a stored row's recId must not reach an officer who cannot see that rec.
    var SCOPE = 'User:' + Number(cfg.Uid) + ':' + (Number(cfg.ParkId) > 0 ? 'Park:' + Number(cfg.ParkId) : 'Kingdom:' + Number(cfg.KingdomId));
    var STORE = 'lgSmartCourt:' + SCOPE;
    var CONFIRM_MS = 3000;  // Clear All second-click window
    var MINIMIZE_MS = 200;  // FAB appears as the card finishes shrinking
    var STATUS_LABEL = { draft: 'Draft', published: 'Published' };
    var NONE = [];

    var MSG = {
        unreadable : 'Your saved Smart Court session could not be read, so it was cleared.',
        staleCourt : 'Your saved Smart Court session was cleared because its court is gone, complete, or no longer yours to plan.',
        unreachable: 'Could not reach the server. Please check your connection and try again.',
        expired    : 'Your session has expired. Log in again, then save.',
        duplicate  : 'Already on this court, so it was not saved.',
        recGone    : 'Its recommendation is no longer available, so this row was not saved. Remove it and click the cell again to add it without the recommendation.',
        recGoneHint: 'Remove this row and click the cell again to add it without the recommendation.',
        lineGone   : 'This line is no longer on the court.',
        lineMoved  : 'This line has moved on from planned. Change it in the Court Planner.'
    };

    var active = false, starting = false, saving = false, cleanSave = false;
    var startToken = 0;     // a begin() answers only while its token is current
    var refreshToken = 0;   // a refresh answers only while its token is current
    var linkedIn = false;   // the page was reached by a SmartCourt=1 link
    var court = null, limit = 0, rows = [], recs = [], seq = 0, today = '';
    var recsByCell = {}, recById = {}, otherByCell = {}, rowsByCell = {}, trsByPlayer = {}, topByCol = {};
    var indexedNew = null, clearTimer = null, minimizeTimer = null, fp = null;
    var dom = {};

    /* ── Small helpers ─────────────────────────────────────── */

    function byId(id) { return document.getElementById(id); }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function icon(name, className) {
        return el('i', 'fas ' + name + (className ? ' ' + className : ''));
    }

    function has(obj, k) { return Object.prototype.hasOwnProperty.call(obj, k); }

    function key(mundaneId, col) { return mundaneId + '|' + col; }

    function localToday() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function humanDate(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
        if (!m) return 'No date';
        return new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function isRealDate(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        if (!m) return false;
        var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
        return d.getUTCFullYear() === +m[1] && d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3];
    }

    function stripOrder(name) { return String(name || '').replace(/^Order of (the )?/i, ''); }

    function colOf(kingdomAwardId) {
        var c = cfg.KaToColumn[kingdomAwardId];
        return c === undefined || c === null ? null : String(c);
    }

    function colLabel(col) {
        var info = G.awardColumns[col];
        return stripOrder(info.DisplayName || info.Name);
    }

    function rowLabel(r) {
        return r.persona + ' - ' + r.award + (Number(r.rank) > 0 ? ' ' + r.rank : '');
    }

    function findRow(id) {
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].id === id) return rows[i];
        }
        return null;
    }

    function unsavedRows() { return rows.filter(function (r) { return !r.saved; }); }

    function errorText(r) {
        if (r.status === 5) return MSG.expired;
        return r.error || MSG.unreachable;
    }

    function post(path, data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(cfg.Uir + path, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).catch(function () {
            return { status: -1, error: MSG.unreachable };
        });
    }

    // fresh skips the server's recommendation cache: for reads that decide a write.
    function fetchState(courtId, fresh) {
        var data = { KingdomId: cfg.KingdomId, ParkId: cfg.ParkId, CourtId: courtId };
        if (fresh) data.Fresh = 1;
        return post('CourtAjax/smart_grid_state', data);
    }

    /* ── Messages ──────────────────────────────────────────── */

    function setMessage(box, text, isError) {
        box.querySelector('.lg-sc-msg-text').textContent = text;
        box.classList.toggle('lg-sc-msg-error', !!isError);
        box.hidden = !text;
    }

    function notify(text, isError) {
        setMessage(active ? dom.cardMsg : dom.notice, text, isError);
    }

    /* ── Indexes ───────────────────────────────────────────── */

    function loadRecs(list) {
        recs = [];
        recsByCell = {};
        recById = {};
        (list || []).forEach(function (x) {
            var col = colOf(x.KingdomAwardId);
            if (col === null) return;
            var rec = {
                id: x.RecommendationsId, mundaneId: x.MundaneId, col: col, rank: Number(x.Rank),
                seconds: Number(x.SecondsCount) || 0, kingdomAwardId: x.KingdomAwardId
            };
            recs.push(rec);
            recById[rec.id] = rec;
            var k = key(rec.mundaneId, col);
            (recsByCell[k] = recsByCell[k] || []).push(rec);
        });
    }

    function loadOtherLines(list) {
        otherByCell = {};
        (list || []).forEach(function (x) {
            var col = colOf(x.KingdomAwardId);
            if (col === null) return;
            var k = key(x.MundaneId, col);
            var cell = otherByCell[k] = otherByCell[k] || { ids: {}, names: [] };
            if (!cell.ids[x.CourtId]) {
                cell.ids[x.CourtId] = true;
                cell.names.push(x.CourtName);
            }
        });
    }

    function lineToRow(l) {
        var col = colOf(l.KingdomAwardId);
        return {
            id: 'l' + l.CourtAwardId, mundaneId: Number(l.MundaneId), persona: l.Persona,
            award: col === null ? stripOrder(l.AwardName) : colLabel(col), col: col,
            rank: Number(l.Rank) || 0, saved: true, recId: l.RecommendationsId || null,
            status: l.Status, courtAwardId: l.CourtAwardId, error: ''
        };
    }

    // Only the card's own cells: assignRecs matches within a cell, so the rest of the
    // kingdom's recs cannot change the result.
    function relevantRecs() {
        var seen = {}, out = [];
        rows.forEach(function (r) {
            var k = key(r.mundaneId, r.col);
            if (!seen[k]) {
                seen[k] = true;
                Array.prototype.push.apply(out, recsByCell[k] || NONE);
            }
        });
        return out;
    }

    function recompute() {
        rows = C.assignRecs(rows, relevantRecs());
        rowsByCell = {};
        rows.forEach(function (r) {
            if (r.recLost && r.recId !== null) {
                r.recLost = false;
                if (r.error === MSG.recGone) r.error = '';
            }
            if (r.col !== null) {
                var k = key(r.mundaneId, r.col);
                (rowsByCell[k] = rowsByCell[k] || []).push(r);
            }
        });
    }

    // Fresh server data. Saved rows become exactly the court's live lines. An unsaved
    // row whose rec has vanished is pinned: it stays unsaved rather than quietly
    // turning into an unlinked line, since finalize would then miss the rec.
    function applyState(state) {
        var prior = {};
        unsavedRows().forEach(function (r) { prior[r.id] = r.recId; });
        loadRecs(state.Recs);
        loadOtherLines(state.OtherCourtLines);
        rows = (state.CourtLines || []).map(lineToRow).concat(unsavedRows());
        recompute();
        unsavedRows().forEach(function (r) {
            if (r.recId === null && prior[r.id] !== null && prior[r.id] !== undefined) {
                r.recLost = true;
                if (!r.error) r.error = MSG.recGone;
            }
        });
    }

    function eachGridRow(fn) {
        [G.table, G.newTable].forEach(function (dt) {
            if (!dt) return;
            var nodes = dt.rows().nodes();
            for (var i = 0; i < nodes.length; i++) fn(nodes[i]);
        });
    }

    function indexPlayers() {
        trsByPlayer = {};
        indexedNew = G.newTable;
        eachGridRow(function (tr) {
            var m = tr.getAttribute('data-mundane-id');
            (trsByPlayer[m] = trsByPlayer[m] || []).push(tr);
        });
    }

    function cellOf(tr, col) {
        for (var i = 0; i < tr.cells.length; i++) {
            if (tr.cells[i].getAttribute('data-col') === col) return tr.cells[i];
        }
        return null;
    }

    /* ── Painting ──────────────────────────────────────────── */

    function heldRank(td) { return Number(td.getAttribute('data-rank')) || 0; }

    function isMaster(td) { return td.getAttribute('data-master') === '1'; }

    function blockReason(td, mundaneId, col, held, mine) {
        if (isMaster(td)) return 'This player is a Master of this ladder.';
        if (cfg.ColumnAwards[col] === undefined || cfg.ColumnAwards[col] === null) {
            return 'This ladder has no award in this kingdom, so it cannot be added to this court.';
        }
        var top = topByCol[col];
        if (C.nextRank(held, mine, mundaneId, col) > top) {
            return 'Rank ' + top + ' is the top of this ladder.';
        }
        return '';
    }

    // One entry per rank: several people often recommend the same rank.
    function recTip(list) {
        var byRank = {};
        list.forEach(function (r) {
            var g = byRank[r.rank] = byRank[r.rank] || { rank: r.rank, count: 0, seconds: 0 };
            g.count++;
            g.seconds = Math.max(g.seconds, r.seconds);
        });
        var parts = Object.keys(byRank).map(function (k) { return byRank[k]; }).sort(function (a, b) {
            return a.rank - b.rank;
        }).map(function (g) {
            var recs = g.count > 1 ? g.count + ' recs, ' : '';
            return 'Rank ' + g.rank + ' (' + recs + (g.seconds === 1 ? '1 +1' : g.seconds + ' +1s') + ')';
        });
        return parts.length ? 'Recommended: ' + parts.join(', ') : '';
    }

    // Against the effective rank: a sibling rec at a rank already on this court is
    // spent, and a rec this card has claimed sits at or below that rank too.
    function usableRecsAbove(td, mundaneId, col, effective) {
        if (isMaster(td)) return NONE;
        return C.recsAbove(recsByCell[key(mundaneId, col)] || NONE, mundaneId, col, effective);
    }

    function clearMarks(td) {
        var marks = td.querySelectorAll('.lg-sc-mark');
        for (var i = 0; i < marks.length; i++) marks[i].parentNode.removeChild(marks[i]);
    }

    function addMark(td, node, tip) {
        node.className += ' lg-sc-mark';
        if (tip) node.setAttribute('data-tip', tip);
        td.appendChild(node);
    }

    // Writes the DOM only when the cell's look changed, so paintAll stays cheap.
    function paintCell(td) {
        var tr = td.parentNode;
        var m = tr.getAttribute('data-mundane-id'), col = td.getAttribute('data-col');
        var block = '', gray = false, count = 0, rTip = '', crownTip = '', sig = '';

        if (active) {
            var k = key(m, col), mine = rowsByCell[k] || NONE, held = heldRank(td);
            var other = otherByCell[k], effective = C.effectiveRank(held, mine, m, col);
            block    = blockReason(td, m, col, held, mine);
            // A Master sits at or above any limit, whatever its rank value says.
            gray     = limit > 0 && (isMaster(td) || C.isAtLimit(effective, limit));
            count    = C.cellCount(mine, m, col);
            rTip     = recTip(usableRecsAbove(td, m, col, effective));
            crownTip = other ? 'Already on Court: ' + other.names.join(', ') : '';
            if (block || gray || count || rTip || crownTip) {
                sig = [block, gray ? 1 : 0, count, rTip, crownTip].join('\u0001');
            }
        }

        if ((td.lgScSig || '') === sig) return;
        td.lgScSig = sig;
        clearMarks(td);
        td.classList.toggle('lg-sc-gray', gray);
        td.classList.toggle('lg-sc-block', !!block);
        if (block) td.setAttribute('data-tip', block); else td.removeAttribute('data-tip');
        if (crownTip) addMark(td, icon('fa-crown', 'lg-sc-crown'), crownTip);
        if (rTip) addMark(td, el('span', 'lg-sc-r', 'R'), rTip);
        if (count) addMark(td, el('span', 'lg-sc-plus', '+' + count));
    }

    function paintFlags(tr) {
        var show = active && C.isOverdue(tr.getAttribute('data-last-signin'), tr.getAttribute('data-last-award'), today);
        if (!!tr.lgScGlass === show) return;
        tr.lgScGlass = show;
        var flags = tr.querySelector('.lg-name-flags');
        if (!flags) return;
        flags.textContent = '';
        if (show) {
            var glass = icon('fa-hourglass-half', 'lg-sc-glass');
            glass.setAttribute('data-tip', 'Signed in within 60 days, with no award in over 180 days.');
            flags.appendChild(glass);
        }
    }

    function paintRow(tr) {
        paintFlags(tr);
        for (var i = 0; i < tr.cells.length; i++) {
            if (tr.cells[i].hasAttribute('data-col')) paintCell(tr.cells[i]);
        }
    }

    function paintAll() { eachGridRow(paintRow); }

    function repaintCell(mundaneId, col) {
        if (col === null) return;
        (trsByPlayer[mundaneId] || NONE).forEach(function (tr) {
            var td = cellOf(tr, col);
            if (td) paintCell(td);
        });
    }

    /* ── Persistence ───────────────────────────────────────── */

    function persist() {
        if (!active) return;
        var stored = unsavedRows().map(function (r) {
            return { mundaneId: r.mundaneId, persona: r.persona, col: r.col, rank: r.rank, recId: r.recId, recLost: !!r.recLost };
        });
        try {
            localStorage.setItem(STORE, JSON.stringify({ v: 1, scope: SCOPE, court: court, limit: limit, rows: stored }));
        } catch (e) { /* storage full or blocked: the session still runs */ }
    }

    function unstore() {
        try { localStorage.removeItem(STORE); } catch (e) { /* nothing to clear */ }
    }

    function readStored() {
        var raw = null;
        try { raw = localStorage.getItem(STORE); } catch (e) { return null; }
        if (raw === null) return null;
        var stored = C.parseStored(raw, SCOPE);
        if (!stored) {
            unstore();
            notify(MSG.unreadable, true);
        }
        return stored;
    }

    function findCourt(id) {
        for (var i = 0; i < cfg.Courts.length; i++) {
            if (Number(cfg.Courts[i].CourtId) === id) return cfg.Courts[i];
        }
        return null;
    }

    function validStoredRow(x) {
        if (!x || typeof x !== 'object' || typeof x.persona !== 'string') return false;
        var col = String(x.col), rank = Number(x.rank);
        return Number(x.mundaneId) > 0 && Number.isInteger(rank) && rank >= 1 && has(G.awardColumns, col) &&
            cfg.ColumnAwards[col] !== undefined && rank <= topByCol[col];
    }

    function restoreRows(list) {
        var out = [];
        list.filter(validStoredRow).forEach(function (x) {
            var m = Number(x.mundaneId), col = String(x.col), rank = Number(x.rank);
            out.push({
                id: 'u' + (++seq), mundaneId: m, persona: x.persona, award: colLabel(col), col: col, rank: rank,
                saved: false, recId: x.recId || null, recLost: !!x.recLost, status: null, courtAwardId: null,
                error: x.recLost ? MSG.recGone : ''
            });
        });
        return out;
    }

    // Rows that were fine when stored may not be now: duplicated on the court by
    // someone else, a player since suspended, or a rank since given.
    function revalidate(courtLines, nUnreadable) {
        var dups = C.findDuplicates(rows, courtLines, colOf);
        var nDup = 0, nSuspended = 0, nHeld = 0, missing = false;
        rows = rows.filter(function (r) {
            if (r.saved) return true;
            if (dups.indexOf(r.id) >= 0) { nDup++; return false; }
            var tr = (trsByPlayer[r.mundaneId] || NONE)[0];
            if (!tr) { missing = true; return true; }
            if (tr.getAttribute('data-suspended') === '1') { nSuspended++; return false; }
            var td = cellOf(tr, r.col);
            if (td && (isMaster(td) || r.rank <= heldRank(td))) { nHeld++; return false; }
            return true;
        });
        recompute();
        var parts = [];
        if (nUnreadable) parts.push(nUnreadable + ' that could not be read');
        if (nDup) parts.push(nDup + ' already on this court');
        if (nSuspended) parts.push(nSuspended + ' for a suspended player');
        if (nHeld) parts.push(nHeld + ' already held by the player');
        if (parts.length) notify('Removed unsaved rows: ' + parts.join(', ') + '.', true);
        return missing;
    }

    /* ── Card ──────────────────────────────────────────────── */

    function buildRow(r) {
        var box = el('div', 'lg-sc-row' + (r.saved ? ' lg-sc-row-saved' : ''));
        var main = el('div', 'lg-sc-row-main');
        var label = rowLabel(r);
        if (r.saved) main.appendChild(icon('fa-square-check', 'lg-sc-row-check'));
        var text = el('span', 'lg-sc-row-text', label);
        text.setAttribute('data-tip', label);
        main.appendChild(text);
        if (r.recId !== null) {
            var linked = el('span', 'lg-sc-r lg-sc-row-r', 'R');
            linked.setAttribute('data-tip', 'Linked to a recommendation');
            main.appendChild(linked);
        }
        if (C.isOverLimit(r.rank, limit)) {
            var over = icon('fa-arrows-up-to-line', 'lg-sc-row-limit');
            over.setAttribute('data-tip', 'Above your Ladder Limit of ' + limit);
            main.appendChild(over);
        }
        if (!r.saved || r.status === 'planned') {
            var x = el('button', 'lg-sc-row-x');
            x.type = 'button';
            x.disabled = saving || !!r.busy;
            x.setAttribute('data-row-id', r.id);
            x.setAttribute('aria-label', 'Remove ' + label);
            x.appendChild(icon('fa-xmark'));
            main.appendChild(x);
        } else {
            main.appendChild(el('span', 'lg-sc-row-x-gap'));
        }
        box.appendChild(main);
        if (r.error) {
            var hint = r.recLost && r.error !== MSG.recGone ? ' ' + MSG.recGoneHint : '';
            box.appendChild(el('div', 'lg-sc-row-error', r.error + hint));
        }
        return box;
    }

    function renderSaveButton(ready, unsaved) {
        var done = !saving && cleanSave && unsaved === 0 && rows.length > 0;
        var btn = dom.save;
        btn.textContent = '';
        btn.classList.toggle('lg-sc-save-done', done);
        btn.disabled = saving || ready === 0;
        if (saving) {
            btn.appendChild(icon('fa-spinner fa-spin'));
            btn.appendChild(document.createTextNode(' Saving…'));
        } else if (done) {
            btn.appendChild(icon('fa-check'));
            btn.appendChild(document.createTextNode(' Saved'));
        } else {
            btn.textContent = 'Save to Court';
        }
    }

    function renderCard(scrollToEnd) {
        var unsaved = unsavedRows();
        var ready = unsaved.filter(function (r) { return !r.recLost; }).length;
        var list = dom.list;
        list.textContent = '';
        if (rows.length) {
            var frag = document.createDocumentFragment();
            rows.forEach(function (r) { frag.appendChild(buildRow(r)); });
            list.appendChild(frag);
        } else {
            list.appendChild(el('div', 'lg-sc-empty', 'Click any cell to add an award.'));
        }
        if (scrollToEnd) list.scrollTop = list.scrollHeight;

        dom.clear.disabled = saving || unsaved.length === 0;
        renderSaveButton(ready, unsaved.length);
        dom.open.hidden = !(court && court.CourtId > 0);
        if (court && court.CourtId > 0) dom.open.href = cfg.Uir + 'Court/detail/' + court.CourtId;
        dom.fabCount.textContent = rows.length;
        dom.fabCount.hidden = rows.length === 0;
    }

    function renderCourt() {
        dom.barCourt.textContent = court.Name;
        dom.cardCourt.textContent = court.Name;
    }

    function removeRow(id) {
        var r = findRow(id);
        if (!r || saving || r.busy) return;
        if (!r.saved) {
            rows = C.removeRow(rows, id);
            recompute();
            repaintCell(r.mundaneId, r.col);
            renderCard();
            persist();
            return;
        }
        if (r.status === 'planned') removeSavedLine(r);
    }

    // Rows are rebuilt by any refresh meanwhile, so a line is found again by its id.
    function rowForLine(courtAwardId) {
        return rows.filter(function (x) { return x.courtAwardId === courtAwardId; })[0] || null;
    }

    // removeAward refuses only 'given', and this row's status may be stale: the card's
    // own new-tab button invites staging a line in the planner. Read before deleting.
    function removeSavedLine(r) {
        var courtAwardId = r.courtAwardId;
        r.busy = true;
        r.error = '';
        renderCard();
        fetchState(court.CourtId, false).then(function (st) {
            var cur = rowForLine(courtAwardId);
            if (!cur) return null;
            if (st.status !== 0 || !st.state) {
                cur.busy = false;
                cur.error = errorText(st);
                return null;
            }
            var live = (st.state.CourtLines || NONE).filter(function (l) {
                return Number(l.CourtAwardId) === Number(courtAwardId);
            })[0];
            if (!live || live.Status !== 'planned') {
                cur.busy = false;
                cur.status = live ? live.Status : 'removed';
                cur.error = live ? MSG.lineMoved : MSG.lineGone;
                return null;
            }
            return post('CourtAjax/remove_award', { CourtAwardId: courtAwardId });
        }).then(function (res) {
            var cur = rowForLine(courtAwardId);
            if (cur && res) {
                cur.busy = false;
                if (res.status === 0) {
                    rows = C.removeRow(rows, cur.id);
                    recompute();
                    repaintCell(cur.mundaneId, cur.col);
                    persist();
                    refreshAfterRemoval();
                } else {
                    cur.error = errorText(res);
                }
            }
            if (active) renderCard();
        });
    }

    // Lets a removed line's rec be offered again. Fresh, because the cached list can
    // predate that rec. A save takes the token, since its own read is newer than this.
    function refreshAfterRemoval() {
        if (saving) return;
        var token = ++refreshToken, courtId = court.CourtId;
        fetchState(courtId, true).then(function (st) {
            if (token !== refreshToken || !active || court.CourtId !== courtId) return;
            if (st.status !== 0 || !st.state) return; // the removal stands: the next read catches up
            var busy = rows.filter(function (x) { return x.busy; }).map(function (x) { return x.courtAwardId; });
            applyState(st.state);
            rows.forEach(function (x) { x.busy = busy.indexOf(x.courtAwardId) >= 0; });
            paintAll();
            renderCard();
            persist();
        });
    }

    function disarmClear() {
        clearTimeout(clearTimer);
        clearTimer = null;
        dom.clear.classList.remove('lg-sc-clear-armed');
        dom.clear.textContent = 'Clear All';
    }

    function onClearAll() {
        if (saving) return;
        if (clearTimer === null) {
            dom.clear.classList.add('lg-sc-clear-armed');
            dom.clear.textContent = 'Really clear?';
            clearTimer = setTimeout(disarmClear, CONFIRM_MS);
            return;
        }
        disarmClear();
        var gone = unsavedRows();
        rows = rows.filter(function (r) { return r.saved; });
        recompute();
        gone.forEach(function (r) { repaintCell(r.mundaneId, r.col); });
        renderCard();
        persist();
    }

    function minimize() {
        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        dom.card.classList.add('lg-sc-card-min');
        clearTimeout(minimizeTimer);
        minimizeTimer = setTimeout(function () { dom.fab.hidden = false; }, reduced ? 0 : MINIMIZE_MS);
    }

    function restore() {
        clearTimeout(minimizeTimer);
        dom.fab.hidden = true;
        dom.card.classList.remove('lg-sc-card-min');
    }

    /* ── Cell clicks ───────────────────────────────────────── */

    function onGridClick(e) {
        if (!active) return;
        var td = e.target.closest('td.lg-col-award');
        if (!td) return;
        var tr = td.parentNode;
        var m = tr.getAttribute('data-mundane-id'), col = td.getAttribute('data-col');
        if (!m || !col) return;

        var mine = rowsByCell[key(m, col)] || NONE, held = heldRank(td);
        if (blockReason(td, m, col, held, mine)) return;

        rows.push({
            id: 'u' + (++seq), mundaneId: Number(m), persona: tr.getAttribute('data-persona'),
            award: colLabel(col), col: col, rank: C.nextRank(held, mine, m, col), saved: false,
            recId: null, recLost: false, status: null, courtAwardId: null, error: ''
        });
        cleanSave = false;
        recompute();
        paintCell(td);
        renderCard(true);
        persist();
    }

    function onLimitChange() {
        limit = Number(dom.limit.value) || 0;
        paintAll();
        renderCard();
        persist();
    }

    // New Players rows arrive after Smart Court may have started. The element's first
    // draw runs while newTable is still null, so this waits for the draw after it.
    function onNewTableDraw() {
        if (!active || !G.newTable || indexedNew === G.newTable) return;
        indexPlayers();
        revalidate(NONE, 0);
        G.newTable.rows().nodes().each(function (tr) { paintRow(tr); });
        renderCard();
        persist();
    }

    /* ── Save ──────────────────────────────────────────────── */

    function ensureCourt() {
        if (court.CourtId > 0) return Promise.resolve(true);
        return post('CourtAjax/create_court', {
            KingdomId: cfg.KingdomId, ParkId: cfg.ParkId, Name: court.Name, CourtDate: court.CourtDate,
            EventCalendarDetailId: court.EventCalendarDetailId, Mode: court.Mode
        }).then(function (r) {
            if (r.status !== 0 || !r.court_id) {
                notify(errorText(r), true);
                return false;
            }
            court = { CourtId: Number(r.court_id), Name: court.Name, CourtDate: court.CourtDate, Status: 'draft', AwardCount: 0 };
            cfg.Courts.push(court);
            persist();
            return true;
        });
    }

    function saveRow(id, run) {
        var r = findRow(id);
        if (!r || r.saved) return Promise.resolve();
        var rec = r.recId !== null ? recById[r.recId] : null;
        return post('CourtAjax/add_award', {
            CourtId: court.CourtId, MundaneId: r.mundaneId,
            KingdomAwardId: rec ? rec.kingdomAwardId : cfg.ColumnAwards[r.col],
            Rank: r.rank, RecommendationsId: r.recId || 0, PassToLocal: 0, Notes: ''
        }).then(function (res) {
            var cur = findRow(id);
            if (!cur) return;
            if (res.status === 0 && res.award) {
                var lineId = Number(res.award.CourtAwardId);
                var repeat = rows.some(function (x) { return x.saved && Number(x.courtAwardId) === lineId; });
                if (repeat) {
                    // A repeat add hands back the line already on the card.
                    rows = rows.filter(function (x) { return x.id !== id; });
                } else {
                    cur.saved = true;
                    cur.courtAwardId = lineId;
                    cur.status = res.award.Status || 'planned';
                    cur.error = '';
                }
            } else {
                run.failed = true;
                cur.error = errorText(res);
                if (cur.recId !== null) run.refresh = true;
                if (res.status === 5) run.stopped = true;
            }
            recompute();
            repaintCell(cur.mundaneId, cur.col);
            renderCard();
            persist();
        });
    }

    function finishSave(clean) {
        saving = false;
        cleanSave = clean;
        renderCard();
        persist();
    }

    function saveRows(run, ids) {
        return ids.reduce(function (chain, id) {
            return chain.then(function () { return run.stopped ? null : saveRow(id, run); });
        }, Promise.resolve());
    }

    function reportFailures(run) {
        var n = unsavedRows().filter(function (r) { return r.error; }).length;
        if (run.stopped) {
            notify(MSG.expired, true);
        } else if (n) {
            notify(n + (n === 1 ? ' row' : ' rows') + ' not saved.', true);
        }
    }

    function saveAll() {
        if (saving || !active) return;
        var ready = unsavedRows().filter(function (r) { return !r.recLost; });
        if (!ready.length) return;

        saving = true;
        cleanSave = false;
        disarmClear();
        setMessage(dom.cardMsg, '');
        ready.forEach(function (r) { r.error = ''; });
        renderCard();

        refreshToken++;

        // Read first: a pass where every rec vanished must not leave an empty court.
        fetchState(court.CourtId, true).then(function (r) {
            if (r.status !== 0) {
                notify(errorText(r), true);
                return finishSave(false);
            }
            applyState(r.state);
            var dups = C.findDuplicates(rows, r.state.CourtLines, colOf);
            dups.forEach(function (id) {
                var dup = findRow(id);
                dup.recLost = false; // its rec left the list because it is on this court
                dup.error = MSG.duplicate;
            });
            paintAll();
            renderCard();
            var run = { failed: false, refresh: false, stopped: false };
            var ids = unsavedRows().filter(function (x) {
                return !x.recLost && dups.indexOf(x.id) < 0;
            }).map(function (x) { return x.id; });
            return (ids.length ? ensureCourt() : Promise.resolve(true)).then(function (ok) {
                if (!ok) return finishSave(false);
                return saveRows(run, ids).then(function () {
                    return run.refresh ? fetchState(court.CourtId, true) : null;
                }).then(function (fresh) {
                    if (fresh && fresh.status === 0) {
                        applyState(fresh.state);
                        paintAll();
                    }
                    reportFailures(run);
                    finishSave(!run.failed && unsavedRows().length === 0);
                });
            });
        }).catch(function () {
            notify(MSG.unreachable, true);
            finishSave(false);
        });
    }

    /* ── Enter and exit ────────────────────────────────────── */

    function renderButton() {
        dom.btnLabel.textContent = active ? 'Smart Court: On' : 'Smart Court';
        dom.btn.classList.toggle('lg-sc-btn-on', active);
        dom.btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    }

    function activate(state, stored) {
        var restored = restoreRows(stored);
        active = true;
        cleanSave = false;
        today = localToday();
        rows = restored;
        dom.root.classList.add('lg-sc-on');
        dom.bar.hidden = false;
        dom.card.hidden = false;
        dom.limit.value = String(limit);
        setMessage(dom.notice, '');
        setMessage(dom.cardMsg, '');
        renderButton();
        renderCourt();

        // Indexed before the redraw, so New Players' draw handler has nothing to do yet.
        indexPlayers();
        G.hideSuspended = true;
        G.refresh();
        applyState(state);
        var missing = revalidate(state.CourtLines || NONE, stored.length - restored.length);
        paintAll();
        renderCard(true);
        persist();

        // A row from the New Players box: load that box so its row can be checked too.
        if (missing && !G.newTable && !byId('lg-btn-new').classList.contains('lg-pill-active')) {
            byId('lg-btn-new').click();
        }
    }

    function setStarting(on) {
        starting = on;
        dom.go.disabled = on;
        dom.btn.disabled = on;
    }

    function begin(chosen, storedRows, storedLimit, isResume) {
        if (starting) return;
        var token = ++startToken;
        setStarting(true);
        fetchState(chosen.CourtId, false).then(function (r) {
            if (token !== startToken) return; // cancelled while loading
            setStarting(false);
            if (r.status !== 0 || !r.state) {
                if (!isResume) {
                    showModalError(errorText(r));
                } else if (r.status === -1 || r.status === 5) {
                    // Could still be good: keep it for the next try.
                    notify(errorText(r), true);
                } else {
                    unstore();
                    notify(MSG.staleCourt, true);
                    if (linkedIn) openModal();
                }
                return;
            }
            closeModal();
            court = chosen;
            limit = storedLimit;
            activate(r.state, storedRows);
        });
    }

    // True when a resume is under way, false when there was nothing good to resume.
    function resume(stored) {
        var c = stored.court, id = Number(c.CourtId), chosen = null;
        var storedLimit = Number.isInteger(stored.limit) && stored.limit >= 0 && stored.limit <= 10 ? stored.limit : 0;
        if (id > 0) {
            chosen = findCourt(id);
            if (!chosen) {
                unstore();
                notify(MSG.staleCourt, true);
                return false;
            }
        } else if (id === 0 && typeof c.Name === 'string' && c.Name.trim() !== '') {
            chosen = {
                CourtId: 0, Name: c.Name, CourtDate: String(c.CourtDate || ''),
                EventCalendarDetailId: Number(c.EventCalendarDetailId) || 0, Mode: c.Mode === 'plan' ? 'plan' : 'run'
            };
        } else {
            unstore();
            notify(MSG.unreadable, true);
            return false;
        }
        begin(chosen, stored.rows, storedLimit, true);
        return true;
    }

    function resumeStored() {
        var stored = readStored();
        return !!stored && resume(stored);
    }

    function exit() {
        if (saving || starting) return;
        // Unsaved rows wait in storage. With none, a later visit starts fresh.
        if (unsavedRows().length) persist(); else unstore();
        active = false;
        court = null;
        rows = [];
        rowsByCell = {};
        disarmClear();
        restore();
        dom.root.classList.remove('lg-sc-on');
        dom.bar.hidden = true;
        dom.card.hidden = true;
        renderButton();
        paintAll();
        G.hideSuspended = false;
        G.refresh();
    }

    function onButton() {
        if (starting) return;
        if (active) {
            exit();
            return;
        }
        if (!resumeStored()) openModal();
    }

    /* ── Modal ─────────────────────────────────────────────── */

    function option(value, text) {
        var o = el('option', '', text);
        o.value = value;
        return o;
    }

    function fillModal() {
        var none = option('0', '— None —');
        none.setAttribute('data-start', '');
        dom.event.appendChild(none);
        cfg.Events.forEach(function (ev) {
            var start = String(ev.EventStart || '').slice(0, 10);
            var o = option(ev.EventCalendarDetailId, ev.Name + (start ? ' (' + humanDate(start) + ')' : ''));
            o.setAttribute('data-start', start);
            dom.event.appendChild(o);
        });
        if (window.flatpickr) {
            fp = window.flatpickr(dom.date, {
                dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y', allowInput: true,
                onReady: function (d, s, inst) { inst.calendarContainer.classList.add('lg-sc-fp'); }
            });
        }
    }

    function fillCourts() {
        dom.court.textContent = '';
        cfg.Courts.forEach(function (c) {
            var status = STATUS_LABEL[c.Status] || c.Status;
            var count = Number(c.AwardCount) || 0;
            dom.court.appendChild(option(c.CourtId, c.Name + ' — ' + humanDate(c.CourtDate) + ' (' + status + ', ' + count + (count === 1 ? ' award)' : ' awards)')));
        });
        dom.pickExisting.disabled = cfg.Courts.length === 0;
    }

    function pickMode(useExisting) {
        dom.modal.setAttribute('data-choice', useExisting ? 'existing' : 'new');
        dom.pickNew.classList.toggle('lg-sc-choice-sel', !useExisting);
        dom.pickExisting.classList.toggle('lg-sc-choice-sel', useExisting);
        dom.pickNew.setAttribute('aria-pressed', useExisting ? 'false' : 'true');
        dom.pickExisting.setAttribute('aria-pressed', useExisting ? 'true' : 'false');
        dom.fieldsNew.hidden = useExisting;
        dom.fieldsExisting.hidden = !useExisting;
        showModalError('');
    }

    function syncRunMode() {
        var modes = dom.modal.querySelectorAll('input[name="lg-sc-mode"]');
        for (var i = 0; i < modes.length; i++) {
            modes[i].closest('.lg-sc-mode-opt').classList.toggle('lg-sc-mode-sel', modes[i].checked);
        }
    }

    function setDate(value) {
        if (fp) fp.setDate(value, false); else dom.date.value = value;
    }

    function showModalError(text) {
        dom.modalError.textContent = text;
        dom.modalError.hidden = !text;
    }

    function openModal() {
        fillCourts();
        pickMode(false);
        dom.name.value = '';
        dom.event.value = '0';
        setDate('');
        dom.modal.querySelector('input[name="lg-sc-mode"][value="run"]').checked = true;
        syncRunMode();
        dom.scrim.hidden = false;
        setTimeout(function () { dom.name.focus(); }, 50);
    }

    function closeModal() {
        dom.scrim.hidden = true;
        if (fp) fp.close();
    }

    // The officer backing out, which also abandons a start still loading.
    function dismissModal() {
        startToken++;
        setStarting(false);
        closeModal();
    }

    function onModalSubmit(e) {
        e.preventDefault();
        if (starting) return;
        if (dom.modal.getAttribute('data-choice') === 'existing') {
            var picked = findCourt(Number(dom.court.value));
            if (picked) begin(picked, [], 0, false); else showModalError('Please choose a court.');
            return;
        }
        var name = dom.name.value.trim(), date = dom.date.value.trim();
        if (!name) { showModalError('Please enter a court name.'); return; }
        if (!isRealDate(date)) { showModalError('Please choose a court date.'); return; }
        var mode = dom.modal.querySelector('input[name="lg-sc-mode"]:checked');
        begin({
            CourtId: 0, Name: name, CourtDate: date, EventCalendarDetailId: Number(dom.event.value) || 0,
            Mode: mode && mode.value === 'plan' ? 'plan' : 'run'
        }, [], 0, false);
    }

    /* ── Start ─────────────────────────────────────────────── */

    function grabElements() {
        dom = {
            root: document.querySelector('.rp-root'), btn: byId('lg-sc-btn'), btnLabel: byId('lg-sc-btn-label'),
            notice: byId('lg-sc-notice'), bar: byId('lg-sc-bar'), barCourt: byId('lg-sc-bar-court'), limit: byId('lg-sc-limit'),
            card: byId('lg-sc-card'), cardCourt: byId('lg-sc-card-court'), cardMsg: byId('lg-sc-card-msg'),
            list: byId('lg-sc-list'), clear: byId('lg-sc-clear'), open: byId('lg-sc-open'), save: byId('lg-sc-save'),
            fab: byId('lg-sc-fab'), fabCount: byId('lg-sc-fab-count'), scrim: byId('lg-sc-scrim'), modal: byId('lg-sc-modal'),
            pickNew: byId('lg-sc-pick-new'), pickExisting: byId('lg-sc-pick-existing'), fieldsNew: byId('lg-sc-fields-new'),
            fieldsExisting: byId('lg-sc-fields-existing'), name: byId('lg-sc-name'), date: byId('lg-sc-date'),
            event: byId('lg-sc-event'), court: byId('lg-sc-court'), modalError: byId('lg-sc-modal-error'), go: byId('lg-sc-go')
        };
    }

    function bind() {
        dom.btn.addEventListener('click', onButton);
        dom.limit.addEventListener('change', onLimitChange);
        dom.save.addEventListener('click', saveAll);
        dom.clear.addEventListener('click', onClearAll);
        dom.fab.addEventListener('click', restore);
        byId('lg-sc-minimize').addEventListener('click', minimize);
        dom.list.addEventListener('click', function (e) {
            var x = e.target.closest('.lg-sc-row-x');
            if (x) removeRow(x.getAttribute('data-row-id'));
        });
        [dom.notice, dom.cardMsg].forEach(function (box) {
            box.querySelector('.lg-sc-msg-x').addEventListener('click', function () { setMessage(box, ''); });
        });

        byId('lg-grid-table').addEventListener('click', onGridClick);
        byId('lg-new-table').addEventListener('click', onGridClick);
        $('#lg-new-table').on('draw.dt', onNewTableDraw);

        byId('lg-sc-form').addEventListener('submit', onModalSubmit);
        byId('lg-sc-cancel').addEventListener('click', dismissModal);
        dom.pickNew.addEventListener('click', function () { pickMode(false); });
        dom.pickExisting.addEventListener('click', function () { pickMode(true); });
        dom.modal.addEventListener('change', function (e) {
            if (e.target.name === 'lg-sc-mode') syncRunMode();
        });
        dom.event.addEventListener('change', function () {
            var start = dom.event.options[dom.event.selectedIndex].getAttribute('data-start');
            if (start) setDate(start);
        });
        dom.scrim.addEventListener('click', function (e) {
            if (e.target === dom.scrim) dismissModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !dom.scrim.hidden) dismissModal();
        });
    }

    // Outside .rp-root so an ancestor's stacking context cannot put these under the nav.
    function moveToBody() {
        [dom.card, dom.fab, dom.scrim].forEach(function (node) { document.body.appendChild(node); });
    }

    $(function () {
        grabElements();
        G.awardKeys.forEach(function (col) { topByCol[col] = C.maxRank(G.awardColumns[col].Name); });
        moveToBody();
        fillModal();
        bind();
        // Admin "Plan Court from Smart Grid" links carry SmartCourt=1. Matched on the whole
        // URL because a route's params can sit in the path rather than the query string.
        linkedIn = /[?&]SmartCourt=1(?:[&#]|$)/.test(window.location.href);
        if (!resumeStored() && linkedIn) openModal();
    });
})();
