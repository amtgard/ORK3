/*
 * EvBoardStore: the Edit mode's client store for the Event Schedule Board.
 *
 * Owns the items, the undo and redo history, and a serial save queue that
 * talks to the server through an injected send(wireOps) function. No DOM.
 *
 * Each item has a client id (cid): 'i' + EventScheduleId for loaded items,
 * 'n1', 'n2', ... for new ones. `saved` maps a cid to the last server-confirmed
 * copy of the item (absent for an add the server has not confirmed); a
 * rejected change reverts to it.
 *
 * status() -> {state: 'saved'|'saving'|'offline', pending, reason, message?}; reason
 * says why the store is offline ('network', 'server', 'signed-out', 'forbidden', 'protocol')
 * and is null otherwise; message, present only when the server refused a request as a
 * whole with a text, is that text. Offline keeps every change; retry() sends them again.
 *
 * Undo steps are lists of {cid, before, after} snapshots (null = absent).
 * Undoing a remove therefore brings the item back as a new add under the same
 * cid; the remove still reaches the server.
 *
 * Every request carries a token: send(wireOps, token). A request whose fate is
 * unknown (no reply, a server error, an unexpected reply) is held whole: its
 * entries stay in flight, nothing joins it, and retry() sends the same ops with
 * the same token again before anything else, so the server applies it once.
 * opts: {items, send, saveMeal, token: () => string, maxOps: ops per request}.
 *
 * An update carries `seen` (the saved copy's Modified). A result row with
 * `gone` (the row was deleted) or `conflict` (someone else changed it first; the
 * row is theirs) drops the item's queued work and history and fires
 * 'superseded' {kind: 'gone'|'conflict', cid, title}. sync(serverItems) merges a
 * fresh read of the schedule and returns {added, changed, removed}. syncRow(row)
 * merges one row saved elsewhere (the Details form) the same way, except that its
 * saved copy takes the row even while the item has work pending. pendingFor(cid)
 * says whether the item has a change queued or in flight.
 *
 * opts.normalize(next, prev), optional: called for every add and update before
 * it is applied or queued (never for undo, redo, adopt or sync, which only bring
 * back or take in states that were valid), with copies of the item as it would
 * be and as it was (null for an add). It returns {item} (the item to apply,
 * possibly adjusted) or {refuse: message}. A refused call changes nothing and
 * queues nothing: add returns null instead of a cid, update returns false (true
 * otherwise), and 'refused' {message, item} fires once the outer call ends.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.EvBoardStore = factory();
})(this, function () {
    'use strict';

    var WIRE_FIELDS = ['Title', 'StartTime', 'EndTime', 'Location', 'Description', 'Category', 'SecondaryCategory', 'Leads'];
    var MEAL_FIELDS = ['Menu', 'Cost', 'Dietary', 'Allergens'];
    var MAX_OPS = 200; // ops per request, unless opts.maxOps says otherwise

    function clone(v) { return v == null ? null : JSON.parse(JSON.stringify(v)); }
    function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

    // A request token: 22 characters of [A-Za-z0-9_-] (132 random bits).
    var TOKEN_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    function newToken() {
        var n = 22, bytes = null, out = '';
        var c = typeof crypto !== 'undefined' ? crypto : null;
        try {
            if (c && typeof c.getRandomValues === 'function') { bytes = new Uint8Array(n); c.getRandomValues(bytes); }
        } catch (e) { bytes = null; }
        for (var i = 0; i < n; i++) out += TOKEN_CHARS.charAt((bytes ? bytes[i] : Math.floor(Math.random() * 256)) & 63);
        return out;
    }

    // What a person sees of an item, for counting what a re-read changed.
    function contentKey(it) {
        var t16 = function (s) { return String(s || '').replace('T', ' ').slice(0, 16); };
        var num = function (v) { return v == null || v === '' ? '' : (isFinite(+v) ? (+v).toFixed(2) : String(v)); };
        return JSON.stringify([it.Title || '', t16(it.StartTime), t16(it.EndTime), it.Location || '',
            it.Description || '', it.Category || '', it.SecondaryCategory || '',
            (it.Leads || []).map(function (l) {
                var o = l && typeof l === 'object';
                return +(o ? l.MundaneId : l) + (o && l.Auto && l.Auto !== '0' ? 'a' : '');
            }).sort(),
            it.Menu || '', num(it.Cost), it.Dietary || '', it.Allergens || '']);
    }

    function create(opts) {
        var send = opts.send;
        var fields = WIRE_FIELDS.concat(opts.saveMeal ? MEAL_FIELDS : []);
        var makeToken = typeof opts.token === 'function' ? opts.token : newToken;
        var normalize = typeof opts.normalize === 'function' ? opts.normalize : null;
        var maxOps = typeof opts.maxOps === 'number' && opts.maxOps > 0 && opts.maxOps % 1 === 0 ? opts.maxOps : MAX_OPS;

        var list = [];      // local items, in order
        var saved = {};     // cid -> last server-confirmed copy
        var seq = 0;        // for 'n' cids
        var undoStack = [], redoStack = [];

        var queue = [];     // unsent entries: {op, cid, fields?, item?}
        var inflight = null; // entries of the request in flight (or held)
        // The request in flight, or held after an unknown outcome:
        // {sent: entries, wire: ops, token, uncertain: an earlier send of it may have been saved}.
        var current = null;
        var offline = false;
        // Why the store is offline: 'network' (no reply), 'server' (a reply with an
        // error status), 'signed-out', 'forbidden' or 'protocol'.
        var offlineReason = null;
        // With 'server': the text of a reply that refused the request as a whole, or null.
        var offlineMessage = null;

        var listeners = { change: [], status: [], rejected: [], superseded: [], refused: [] };
        var waiters = [];

        // Outer-operation bookkeeping: events fire only when the outermost call ends.
        var depth = 0, recording = null, changed = false, rejections = [], supersessions = [], refusals = [];
        var lastStatus = null, pumpScheduled = false;
        // Counts every change to the items, so a caller can cache what it derives from them.
        var version = 0;
        function dirty() { changed = true; version++; }

        function load(items) {
            version++;
            list = []; saved = {};
            (items || []).forEach(function (it) {
                var c = clone(it);
                c.cid = 'i' + c.EventScheduleId;
                list.push(c);
                saved[c.cid] = clone(c);
            });
        }
        load(opts.items);

        function indexOf(cid) {
            for (var i = 0; i < list.length; i++) if (list[i].cid === cid) return i;
            return -1;
        }
        function find(cid) { var i = indexOf(cid); return i < 0 ? null : list[i]; }
        function serverId(cid) { return saved[cid] ? saved[cid].EventScheduleId : 0; }

        // ---- events and outer operations ----

        function emit(type, payload) {
            listeners[type].slice().forEach(function (fn) {
                try { fn(payload); } catch (e) { setTimeout(function () { throw e; }, 0); }
            });
        }

        function isIdle() { return offline || (!inflight && !queue.length); }

        function run(fn, recordable) {
            var outer = depth === 0;
            if (outer && recordable) recording = { order: [], map: {} };
            depth++;
            try { return fn(); } finally {
                depth--;
                if (outer) finish();
            }
        }

        function finish() {
            var step = recording; recording = null;
            if (step && !step.discard) commitStep(step);
            var didChange = changed; changed = false;
            var rej = rejections; rejections = [];
            var sup = supersessions; supersessions = [];
            var ref = refusals; refusals = [];
            // A listener may call back into the store; each such call finishes on its own,
            // so status and idleness are read only after these listeners have run.
            if (didChange) emit('change');
            rej.forEach(function (r) { emit('rejected', r); });
            sup.forEach(function (r) { emit('superseded', r); });
            ref.forEach(function (r) { emit('refused', r); });
            var st = status(), stKey = st.state + ':' + st.pending + ':' + st.reason + ':' + (st.message || '');
            if (stKey !== lastStatus) { lastStatus = stKey; emit('status', st); }
            if (depth === 0 && isIdle()) waiters.splice(0).forEach(function (r) { r(); });
        }

        function touch(cid, rec) {
            if (!rec || !recording || recording.map[cid]) return;
            recording.map[cid] = { cid: cid, before: clone(find(cid)) };
            recording.order.push(cid);
        }

        function commitStep(step) {
            var changes = [];
            step.order.forEach(function (cid) {
                var ch = step.map[cid];
                ch.after = clone(find(cid));
                if (!same(ch.before, ch.after)) changes.push(ch);
            });
            if (!changes.length) return;
            undoStack.push(changes);
            redoStack.length = 0;
        }

        // ---- the queue ----

        function lastFor(cid) {
            for (var i = queue.length - 1; i >= 0; i--) if (queue[i].cid === cid) return i;
            return -1;
        }

        function enqueue(e) {
            var i = lastFor(e.cid);
            if (e.op === 'update') {
                if (i >= 0 && queue[i].op === 'add') return; // the add sends the item as it is when built
                if (i >= 0 && queue[i].op === 'update') { Object.assign(queue[i].fields, e.fields); return; }
            } else if (e.op === 'remove') {
                if (i >= 0 && queue[i].op === 'update') { queue.splice(i, 1); i = lastFor(e.cid); }
                if (i >= 0 && queue[i].op === 'add') { queue.splice(i, 1); return; }
            }
            queue.push(e);
            schedulePump();
        }

        function requeue(entries) {
            var all = entries.concat(queue);
            queue = [];
            all.forEach(function (e) { enqueue({ op: e.op, cid: e.cid, fields: e.fields, item: e.item }); });
        }

        function schedulePump() {
            if (pumpScheduled) return;
            pumpScheduled = true;
            Promise.resolve().then(function () { pumpScheduled = false; run(pump, false); });
        }

        // Leads go out as {MundaneId, Auto}; the local items keep Persona too.
        function wireLeads(leads) {
            return (leads || []).map(function (l) {
                var obj = l && typeof l === 'object';
                return { MundaneId: +(obj ? l.MundaneId : l), Auto: !!(obj && l.Auto) };
            });
        }
        function wireFields(src) {
            var out = {};
            fields.forEach(function (k) {
                if (src[k] === undefined) return;
                out[k] = k === 'Leads' ? wireLeads(src[k]) : clone(src[k]);
            });
            return out;
        }

        // Returns the wire op for a queue entry, null if it has nothing to send (dropped),
        // or WAIT if it cannot be addressed yet (kept: an update or remove never goes
        // out without a server id, and is never silently discarded for lacking one).
        var WAIT = {};
        function toWire(e) {
            if (e.op === 'add') {
                var local = find(e.cid);
                if (!local) return null;
                e.snap = clone(local);
                return { op: 'add', cid: e.cid, item: wireFields(local) };
            }
            var f = e.op === 'update' ? wireFields(e.fields) : null;
            if (f && !Object.keys(f).length) return null; // nothing this caller may save
            var id = serverId(e.cid);
            if (!isId(id)) return WAIT;
            if (e.op === 'remove') return { op: 'remove', id: id };
            e.sent = f;
            var w = { op: 'update', id: id, fields: f };
            // The server's Modified as last confirmed here: a newer one means someone else saved first.
            var seen = saved[e.cid].Modified;
            if (typeof seen === 'string' && seen) w.seen = seen;
            return w;
        }

        // A positive integer id (a digit string from a PHP row counts too).
        function isId(v) { return (typeof v === 'number' || typeof v === 'string') && /^[1-9]\d*$/.test(String(v)); }

        // A success response must hold one row per op sent, and give every add an id.
        // An update or remove row may say the item is gone; an update row may say it
        // was in conflict, and then carries the server's current row.
        function wellFormed(sent, results) {
            if (!Array.isArray(results) || results.length !== sent.length) return false;
            return sent.every(function (e, i) {
                var r = results[i];
                if (!r || typeof r !== 'object') return false;
                if (e.op === 'add') return !r.gone && !r.conflict && isId(r.id || (r.schedule && r.schedule.EventScheduleId));
                // An update or remove row that names an id must name a real one.
                var rowId = r.schedule && typeof r.schedule === 'object' ? r.schedule.EventScheduleId : undefined;
                if (r.gone && r.conflict) return false;
                if (r.conflict && (e.op !== 'update' || !isId(rowId))) return false;
                return (r.id === undefined || isId(r.id)) && (rowId === undefined || isId(rowId));
            });
        }

        function pump() {
            if (inflight || offline) return;
            var sent = [], wire = [], waiting = [];
            while (queue.length && wire.length < maxOps) {
                var e = queue.shift(), w = toWire(e);
                if (w === WAIT) waiting.push(e);
                else if (w) { sent.push(e); wire.push(w); }
            }
            queue = waiting.concat(queue);
            if (!wire.length) return;
            dispatch({ sent: sent, wire: wire, token: String(makeToken()), uncertain: false });
        }

        // Send a request (a new one, or a held one again exactly as it was).
        function dispatch(b) {
            current = b;
            inflight = b.sent;
            var p;
            try { p = Promise.resolve(send(clone(b.wire), b.token)); } catch (err) { p = Promise.reject(err); }
            p.then(function (res) { run(function () { settle(b, res); }, false); },
                function (err) {
                    // send may say why it failed (err.reason); otherwise the server was not reached.
                    var why = err && REASONS.indexOf(err.reason) >= 0 ? err.reason : 'network';
                    run(function () { goOffline(b, why); }, false);
                });
        }

        var REASONS = ['network', 'server', 'signed-out', 'forbidden', 'protocol'];
        // The outcomes that leave it unknown whether the server saved the request.
        var UNKNOWN = ['network', 'server', 'protocol'];

        function done() { current = null; inflight = null; }

        // Offline. A request that may have been saved is held whole (same ops, same
        // token, its entries still in flight) for retry() to send again first. One the
        // server certainly did not apply goes back to the head of the queue, unless an
        // earlier send of it may have been saved (a refusal now says nothing about then).
        function goOffline(b, reason, message) {
            offline = true;
            offlineReason = reason || 'protocol';
            offlineMessage = offlineReason === 'server' && message ? message : null;
            if (UNKNOWN.indexOf(offlineReason) >= 0) b.uncertain = true;
            if (b.uncertain) return;
            done();
            requeue(b.sent);
        }

        function settle(b, res) {
            var sent = b.sent;
            // A reply is an object with a whole-number status; anything else is not one.
            if (!res || typeof res !== 'object' || Array.isArray(res) ||
                typeof res.status !== 'number' || res.status % 1 !== 0) return goOffline(b, 'protocol');
            var fi = res.failedIndex;
            var validIndex = typeof fi === 'number' && fi % 1 === 0 && fi >= 0 && fi < sent.length;
            if (res.status === 0) {
                // A malformed success is a protocol failure: keep everything and go offline.
                if (!wellFormed(sent, res.results)) return goOffline(b, 'protocol');
                done();
                applyResults(sent, res.results);
            } else if ((res.status === 1 || res.status === 3) && validIndex) {
                // The server looks a token up before it applies anything, so a refused op
                // means no send of this request was saved.
                done();
                reject(sent, fi, res.error);
            } else if (res.status === 5) {
                return goOffline(b, 'signed-out');
            } else if (res.status === 3) {
                // Refused as a whole, not for one op: the permission is gone.
                return goOffline(b, 'forbidden');
            } else {
                // Failed as a whole, for no one op (a failed save, a refused token): the
                // server's problem. Its text is kept for the person; the request is held.
                return goOffline(b, 'server', typeof res.error === 'string' ? res.error.trim() : '');
            }
            pump();
        }

        function adopt(cid) {
            // An item with nothing further queued takes the server's row.
            var i = indexOf(cid);
            if (i < 0 || lastFor(cid) >= 0) return;
            var next = clone(saved[cid]);
            if (!same(next, list[i])) { list[i] = next; dirty(); }
        }

        // Someone else deleted the item (gone) or changed it first (conflict, row =
        // their version): it takes the server's state, its queued work and its
        // history go, and the person is told.
        function supersede(cid, kind, row) {
            var local = find(cid), i = indexOf(cid);
            var title = String((local && local.Title) || (saved[cid] && saved[cid].Title) || (row && row.Title) || '');
            queue = queue.filter(function (x) { return x.cid !== cid; });
            if (kind === 'gone') {
                delete saved[cid];
                if (i >= 0) list.splice(i, 1);
            } else {
                saved[cid] = Object.assign(clone(row), { cid: cid });
                if (i >= 0) list[i] = clone(saved[cid]); else list.push(clone(saved[cid]));
            }
            dirty();
            dropHistory(cid);
            supersessions.push({ kind: kind, cid: cid, title: title });
        }

        function applyResults(sent, results) {
            sent.forEach(function (e, i) {
                var r = results[i], row = r.schedule || {}, local;
                if (e.op === 'update' && (r.gone || r.conflict)) {
                    supersede(e.cid, r.gone ? 'gone' : 'conflict', r.schedule);
                    return;
                }
                if (e.op === 'add') {
                    var id = r.id || row.EventScheduleId;
                    saved[e.cid] = Object.assign({}, e.snap, row, { EventScheduleId: id, cid: e.cid });
                    local = find(e.cid);
                    if (local && local.EventScheduleId !== id) { local.EventScheduleId = id; dirty(); }
                    adopt(e.cid);
                } else if (e.op === 'update') {
                    saved[e.cid] = Object.assign({}, saved[e.cid], e.sent, row, { cid: e.cid });
                    local = find(e.cid);
                    if (local) {
                        // Keep the local copy's later edits; take only the server's view of what was sent.
                        if (lastFor(e.cid) < 0) {
                            var merged = Object.assign({}, local, row, { cid: e.cid });
                            if (!same(merged, local)) { list[indexOf(e.cid)] = merged; dirty(); }
                        } else if (row.EventScheduleId && local.EventScheduleId !== row.EventScheduleId) {
                            local.EventScheduleId = row.EventScheduleId; dirty();
                        }
                    }
                } else {
                    delete saved[e.cid];
                }
            });
        }

        // The server's ceiling refusal ('A schedule can hold at most N items.').
        var CEILING_MESSAGE = /^A schedule can hold at most \d+ items\.?$/;

        // Puts one refused item back as the server last confirmed it (or removes it) and
        // forgets what is queued for it. Returns what it was last locally.
        function undoLocal(cid, e) {
            var local = find(cid);
            var lastHad = clone(local) || clone(e.item) || clone(e.snap) || clone(saved[cid]);
            queue = queue.filter(function (x) { return x.cid !== cid; });
            var i = indexOf(cid);
            if (saved[cid]) {
                if (i < 0) list.push(clone(saved[cid])); else list[i] = clone(saved[cid]);
            } else if (i >= 0) {
                list.splice(i, 1);
            }
            dropHistory(cid);
            return lastHad;
        }

        function reject(sent, fi, message) {
            var e = sent[fi], cid = e.cid;
            var dropped = [];
            // Over the ceiling, every later add of this send is over it too, unless an
            // op after the refused one frees room: drop them all now, with one notice,
            // rather than one round trip and notice each.
            if (e.op === 'add' && CEILING_MESSAGE.test(String(message || '').trim()) &&
                !sent.slice(fi + 1).some(function (x) { return x.op === 'remove'; })) {
                dropped = sent.slice(fi + 1).filter(function (x) { return x.op === 'add'; });
            }
            var gone = {};
            gone[cid] = true;
            dropped.forEach(function (x) { gone[x.cid] = true; });
            var rest = sent.filter(function (x) { return !gone[x.cid]; });
            var lastHad = undoLocal(cid, e);
            dropped.forEach(function (x) { undoLocal(x.cid, x); });
            dirty();
            requeue(rest);
            rejections.push({ kind: e.op, cid: cid, item: lastHad, message: message || '', dropped: dropped.length });
        }

        // History stacks are only ever changed in place, so a caller holding them
        // (step() during an undo or redo) never pushes into a stale array.
        function keepSteps(stack, fn) {
            var kept = stack.filter(fn);
            stack.length = 0;
            kept.forEach(function (s) { stack.push(s); });
        }

        // A rejection: prune the item's changes from every step.
        function dropHistory(cid) {
            [undoStack, redoStack].forEach(function (stack) {
                stack.forEach(function (s, i) {
                    stack[i] = s.filter(function (ch) { return ch.cid !== cid; });
                });
                keepSteps(stack, function (s) { return s.length > 0; });
            });
        }

        // A non-undoable remove: no later undo or redo may bring the item back or act
        // on it, so every step touching it is dropped whole, including a batch in progress.
        function forgetItem(cid) {
            function untouched(s) { return !s.some(function (ch) { return ch.cid === cid; }); }
            keepSteps(undoStack, untouched);
            keepSteps(redoStack, untouched);
            if (recording && recording.map[cid]) recording.discard = true;
        }

        // ---- local mutations (callers wrap them in run) ----

        function doAdd(item, cid, rec) {
            touch(cid, rec);
            var c = clone(item) || {};
            c.cid = cid;
            c.EventScheduleId = 0;
            list.push(c);
            dirty();
            enqueue({ op: 'add', cid: cid });
        }

        function doUpdate(cid, f, rec) {
            var local = find(cid);
            if (!local) return;
            f = clone(f) || {};
            delete f.cid;
            delete f.EventScheduleId;
            if (!Object.keys(f).length) return;
            touch(cid, rec);
            Object.assign(local, f);
            dirty();
            enqueue({ op: 'update', cid: cid, fields: f });
        }

        // rec: record in the current undo step. forget: drop the item's history
        // (only the public remove with {undoable:false}; never undo or redo replay).
        function doRemove(cid, rec, forget) {
            var i = indexOf(cid);
            if (i < 0) return;
            touch(cid, rec);
            if (forget) forgetItem(cid);
            var snap = list.splice(i, 1)[0];
            dirty();
            enqueue({ op: 'remove', cid: cid, item: snap });
        }

        // Bring an item to a snapshot (null = absent) without recording history.
        function applySnapshot(cid, target) {
            var local = find(cid);
            if (!target) { if (local) doRemove(cid, false, false); return; }
            if (!local) { doAdd(target, cid, false); return; }
            var diff = {};
            Object.keys(target).forEach(function (k) {
                // Modified is the server's: an undo never takes it back.
                if (k === 'cid' || k === 'EventScheduleId' || k === 'Modified') return;
                if (!same(target[k], local[k])) diff[k] = clone(target[k]);
            });
            doUpdate(cid, diff, false);
        }

        // Rows the server already holds (a server-side copy): added with their
        // saved snapshots and nothing queued, as one undo step. Undo removes them
        // through the normal remove path; redo then adds them again as new rows.
        // A row with no server id, or whose id any item already has, is skipped.
        // The cid is 'i' + id unless that cid is still in use (an item restored
        // under it, a queued op, a history step); then it gets a fresh 'n' cid.
        function cidInUse(cid) {
            function inStep(s) { return s.some(function (ch) { return ch.cid === cid; }); }
            return indexOf(cid) >= 0 || !!saved[cid] || lastFor(cid) >= 0 ||
                (inflight || []).some(function (e) { return e.cid === cid; }) ||
                undoStack.some(inStep) || redoStack.some(inStep) || !!(recording && recording.map[cid]);
        }
        function adoptRows(rows) {
            var cids = [];
            run(function () {
                (rows || []).forEach(function (row) {
                    if (!row || !isId(row.EventScheduleId)) return;
                    var id = String(row.EventScheduleId);
                    if (list.some(function (it) { return String(it.EventScheduleId) === id; })) return;
                    var cid = 'i' + id;
                    if (cidInUse(cid)) cid = 'n' + (++seq);
                    touch(cid, true);
                    var c = clone(row);
                    c.cid = cid;
                    list.push(c);
                    saved[cid] = clone(c);
                    dirty();
                    cids.push(cid);
                });
            }, true);
            return cids;
        }

        function pendingFor(cid) {
            return lastFor(cid) >= 0 || (inflight || []).some(function (e) { return e.cid === cid; });
        }

        // opts.normalize on an add (prev null) or an update: {item} to apply, or
        // {refuse} (recorded for the 'refused' event). A normalize that throws, or
        // answers neither, lets the item through as it is.
        function vet(next, prev) {
            if (!normalize) return { item: next };
            var r = null;
            try { r = normalize(clone(next), clone(prev)); } catch (e) { setTimeout(function () { throw e; }, 0); }
            if (r && typeof r === 'object' && r.refuse != null && r.refuse !== false) {
                refusals.push({ message: String(r.refuse), item: clone(next) });
                return { refuse: String(r.refuse) };
            }
            return { item: r && r.item && typeof r.item === 'object' ? r.item : next };
        }

        function publicAdd(item, rec) {
            var v = vet(clone(item) || {}, null);
            if (v.refuse != null) return null;
            var cid = 'n' + (++seq);
            doAdd(v.item, cid, rec);
            return cid;
        }

        // The fields asked for, as normalize left them, and any other field it changed.
        function publicUpdate(cid, f, rec) {
            var local = find(cid);
            if (!local || !normalize) { doUpdate(cid, f, rec); return true; }
            var asked = clone(f) || {};
            delete asked.cid;
            delete asked.EventScheduleId;
            if (!Object.keys(asked).length) return true;
            var next = Object.assign(clone(local), asked);
            var v = vet(next, local);
            if (v.refuse != null) return false;
            var out = {};
            Object.keys(asked).forEach(function (k) { out[k] = v.item[k]; });
            Object.keys(v.item).forEach(function (k) {
                if (k !== 'cid' && k !== 'EventScheduleId' && !same(v.item[k], next[k])) out[k] = v.item[k];
            });
            doUpdate(cid, out, rec);
            return true;
        }

        // Merge a fresh read of the schedule. An item with nothing queued or in flight
        // takes the server's row; one with pending work keeps its local state. A row
        // the store does not know is added (not while an add is in flight: the row
        // may be that add). A saved item the server no longer has, with no pending
        // work, is removed, and its changes leave every undo and redo step; so do the
        // changes of an item whose content the read changed. All other history stays.
        // Returns {added, changed, removed}; one change event at most.
        function sync(serverItems) {
            var counts = { added: 0, changed: 0, removed: 0 };
            run(function () {
                var rows = {}, order = [], known = {};
                (serverItems || []).forEach(function (row) {
                    if (!row || !isId(row.EventScheduleId)) return;
                    var id = String(row.EventScheduleId);
                    if (!rows[id]) { rows[id] = row; order.push(id); }
                });
                Object.keys(saved).forEach(function (cid) {
                    var id = String(saved[cid].EventScheduleId);
                    known[id] = true;
                    if (pendingFor(cid)) return;
                    var i = indexOf(cid);
                    if (!rows[id]) {
                        delete saved[cid];
                        if (i >= 0) { list.splice(i, 1); counts.removed++; dirty(); }
                        // Its changes leave every step (a step left empty goes). Dropping whole
                        // steps would let an older step's snapshot undo later changes to others.
                        dropHistory(cid);
                        return;
                    }
                    mergeRow(cid, rows[id], counts);
                });
                var addOut = (inflight || []).some(function (e) { return e.op === 'add'; });
                order.forEach(function (id) {
                    if (known[id] || addOut) return;
                    if (list.some(function (it) { return String(it.EventScheduleId) === id; })) return;
                    var cid = 'i' + id;
                    if (cidInUse(cid)) cid = 'n' + (++seq);
                    var c = Object.assign(clone(rows[id]), { cid: cid });
                    list.push(c);
                    saved[cid] = clone(c);
                    counts.added++;
                    dirty();
                });
            }, false);
            return counts;
        }

        // One known item with nothing pending takes the server's row (callers wrap it in run).
        function mergeRow(cid, row, counts) {
            var i = indexOf(cid);
            var next = Object.assign(clone(row), { cid: cid });
            saved[cid] = clone(next);
            if (i < 0) { list.push(next); counts.added++; dirty(); return; }
            if (same(next, list[i])) return;
            if (contentKey(next) !== contentKey(list[i])) {
                counts.changed++;
                // Someone else changed it: an undo or redo snapshot of it would put
                // their change back without a conflict (seen is now theirs), so the
                // item's changes leave every step, as on a conflict.
                dropHistory(cid);
            }
            list[i] = next;
            dirty();
        }

        // A row the server saved outside this store (the Details form). The saved copy
        // takes it at once, so the next update's `seen` is this row's Modified; the
        // board copy takes it, by sync's rule, only when nothing is pending for the
        // item. A row the store does not know is left for the next read.
        function syncRow(row) {
            var counts = { added: 0, changed: 0, removed: 0 };
            if (!row || typeof row !== 'object' || !isId(row.EventScheduleId)) return counts;
            var id = String(row.EventScheduleId);
            run(function () {
                var cid = null;
                Object.keys(saved).forEach(function (c) { if (String(saved[c].EventScheduleId) === id) cid = c; });
                if (!cid) return;
                if (pendingFor(cid)) {
                    // Content that really changed leaves every undo and redo step, as in
                    // mergeRow: seen is now this row's, so an older snapshot would revert it.
                    if (contentKey(row) !== contentKey(saved[cid])) dropHistory(cid);
                    saved[cid] = Object.assign(clone(row), { cid: cid });
                    return;
                }
                mergeRow(cid, row, counts);
            }, false);
            return counts;
        }

        // ---- public API ----

        function status() {
            var pending = queue.length + (inflight ? inflight.length : 0);
            var st = {
                state: offline ? 'offline' : (pending ? 'saving' : 'saved'),
                pending: pending,
                reason: offline ? offlineReason : null
            };
            if (offline && offlineMessage) st.message = offlineMessage;
            return st;
        }

        function step(from, to, key, reverse) {
            if (depth > 0 || !from.length) return false;
            run(function () {
                var s = from.pop(), order = reverse ? s.slice().reverse() : s;
                order.forEach(function (ch) { applySnapshot(ch.cid, ch[key]); });
                to.push(s);
            }, false);
            return true;
        }

        lastStatus = status().state + ':0:null';

        return {
            items: function () { return list.map(clone); },
            get: function (cid) { return clone(find(cid)); },
            // -> the new item's cid, or null when opts.normalize refused it.
            add: function (item, o) {
                var cid = null;
                run(function () { cid = publicAdd(item, !o || o.undoable !== false); }, true);
                return cid;
            },
            // -> false when opts.normalize refused it, else true.
            update: function (cid, f, o) {
                var ok = true;
                run(function () { ok = publicUpdate(cid, f, !o || o.undoable !== false); }, true);
                return ok;
            },
            remove: function (cid, o) {
                var undoable = !o || o.undoable !== false;
                run(function () { doRemove(cid, undoable, !undoable); }, true);
            },
            batch: function (fn) { run(fn, true); },
            adopt: adoptRows,
            undo: function () { return step(undoStack, redoStack, 'before', true); },
            redo: function () { return step(redoStack, undoStack, 'after', false); },
            canUndo: function () { return undoStack.length > 0; },
            canRedo: function () { return redoStack.length > 0; },
            status: status,
            // A held request goes again first, unchanged; the queue follows once it settles.
            retry: function () {
                run(function () {
                    var was = offline;
                    offline = false; offlineReason = null; offlineMessage = null;
                    if (current) { if (was) dispatch(current); } else pump();
                }, false);
            },
            idle: function () {
                if (isIdle()) return Promise.resolve();
                return new Promise(function (res) { waiters.push(res); });
            },
            reset: function (serverItems) {
                if (status().state !== 'saved') return false;
                run(function () {
                    load(serverItems);
                    undoStack.length = 0; redoStack.length = 0;
                    dirty();
                }, false);
                return true;
            },
            sync: sync,
            syncRow: syncRow,
            pendingFor: function (cid) { return pendingFor(cid); },
            // Goes up with every change to the items.
            version: function () { return version; },
            on: function (type, fn) {
                if (!listeners[type]) throw new Error('Unknown event: ' + type);
                listeners[type].push(fn);
                return function () {
                    var i = listeners[type].indexOf(fn);
                    if (i >= 0) listeners[type].splice(i, 1);
                };
            }
        };
    }

    return { create: create, token: newToken };
});
