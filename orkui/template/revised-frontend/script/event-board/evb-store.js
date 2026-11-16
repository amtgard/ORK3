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
 * status() -> {state: 'saved'|'saving'|'offline', pending, reason}; reason says
 * why the store is offline ('network', 'signed-out', 'forbidden', 'protocol')
 * and is null otherwise. Offline keeps every change; retry() sends them again.
 *
 * Undo steps are lists of {cid, before, after} snapshots (null = absent).
 * Undoing a remove therefore brings the item back as a new add under the same
 * cid; the remove still reaches the server.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.EvBoardStore = factory();
})(this, function () {
    'use strict';

    var WIRE_FIELDS = ['Title', 'StartTime', 'EndTime', 'Location', 'Description', 'Category', 'SecondaryCategory', 'Leads'];
    var MEAL_FIELDS = ['Menu', 'Cost', 'Dietary', 'Allergens'];
    var MAX_OPS = 200; // ops per request

    function clone(v) { return v == null ? null : JSON.parse(JSON.stringify(v)); }
    function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

    function create(opts) {
        var send = opts.send;
        var fields = WIRE_FIELDS.concat(opts.saveMeal ? MEAL_FIELDS : []);

        var list = [];      // local items, in order
        var saved = {};     // cid -> last server-confirmed copy
        var seq = 0;        // for 'n' cids
        var undoStack = [], redoStack = [];

        var queue = [];     // unsent entries: {op, cid, fields?, item?}
        var inflight = null; // entries of the request in flight
        var offline = false;
        // Why the store is offline: 'network', 'signed-out', 'forbidden' or 'protocol'.
        var offlineReason = null;

        var listeners = { change: [], status: [], rejected: [] };
        var waiters = [];

        // Outer-operation bookkeeping: events fire only when the outermost call ends.
        var depth = 0, recording = null, changed = false, rejections = [];
        var lastStatus = null, pumpScheduled = false;

        function load(items) {
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
            // A listener may call back into the store; each such call finishes on its own,
            // so status and idleness are read only after these listeners have run.
            if (didChange) emit('change');
            rej.forEach(function (r) { emit('rejected', r); });
            var st = status(), stKey = st.state + ':' + st.pending + ':' + st.reason;
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
            return { op: 'update', id: id, fields: f };
        }

        // A positive integer id (a digit string from a PHP row counts too).
        function isId(v) { return (typeof v === 'number' || typeof v === 'string') && /^[1-9]\d*$/.test(String(v)); }

        // A success response must hold one row per op sent, and give every add an id.
        function wellFormed(sent, results) {
            if (!Array.isArray(results) || results.length !== sent.length) return false;
            return sent.every(function (e, i) {
                var r = results[i];
                if (!r || typeof r !== 'object') return false;
                return e.op !== 'add' || isId(r.id || (r.schedule && r.schedule.EventScheduleId));
            });
        }

        function pump() {
            if (inflight || offline) return;
            var sent = [], wire = [], waiting = [];
            while (queue.length && wire.length < MAX_OPS) {
                var e = queue.shift(), w = toWire(e);
                if (w === WAIT) waiting.push(e);
                else if (w) { sent.push(e); wire.push(w); }
            }
            queue = waiting.concat(queue);
            if (!wire.length) return;
            inflight = sent;
            var p;
            try { p = Promise.resolve(send(wire)); } catch (err) { p = Promise.reject(err); }
            p.then(function (res) { run(function () { settle(sent, res); }, false); },
                function (err) {
                    // send may say why it failed (err.reason); otherwise the server was not reached.
                    var why = err && REASONS.indexOf(err.reason) >= 0 ? err.reason : 'network';
                    run(function () { goOffline(sent, why); }, false);
                });
        }

        var REASONS = ['network', 'signed-out', 'forbidden', 'protocol'];

        function goOffline(sent, reason) {
            inflight = null;
            offline = true;
            offlineReason = reason || 'protocol';
            requeue(sent);
        }

        function settle(sent, res) {
            if (!res || typeof res !== 'object') return goOffline(sent, 'protocol');
            var fi = res.failedIndex;
            var validIndex = typeof fi === 'number' && fi % 1 === 0 && fi >= 0 && fi < sent.length;
            if (res.status === 0) {
                // A malformed success is a protocol failure: keep everything and go offline.
                if (!wellFormed(sent, res.results)) return goOffline(sent, 'protocol');
                inflight = null;
                applyResults(sent, res.results);
            } else if ((res.status === 1 || res.status === 3) && validIndex) {
                inflight = null;
                reject(sent, fi, res.error);
            } else if (res.status === 5) {
                return goOffline(sent, 'signed-out');
            } else if (res.status === 3) {
                // Refused as a whole, not for one op: the permission is gone.
                return goOffline(sent, 'forbidden');
            } else {
                return goOffline(sent, 'protocol');
            }
            pump();
        }

        function adopt(cid) {
            // An item with nothing further queued takes the server's row.
            var i = indexOf(cid);
            if (i < 0 || lastFor(cid) >= 0) return;
            var next = clone(saved[cid]);
            if (!same(next, list[i])) { list[i] = next; changed = true; }
        }

        function applyResults(sent, results) {
            sent.forEach(function (e, i) {
                var r = results[i], row = r.schedule || {}, local;
                if (e.op === 'add') {
                    var id = r.id || row.EventScheduleId;
                    saved[e.cid] = Object.assign({}, e.snap, row, { EventScheduleId: id, cid: e.cid });
                    local = find(e.cid);
                    if (local && local.EventScheduleId !== id) { local.EventScheduleId = id; changed = true; }
                    adopt(e.cid);
                } else if (e.op === 'update') {
                    saved[e.cid] = Object.assign({}, saved[e.cid], e.sent, row, { cid: e.cid });
                    local = find(e.cid);
                    if (local) {
                        // Keep the local copy's later edits; take only the server's view of what was sent.
                        if (lastFor(e.cid) < 0) {
                            var merged = Object.assign({}, local, row, { cid: e.cid });
                            if (!same(merged, local)) { list[indexOf(e.cid)] = merged; changed = true; }
                        } else if (row.EventScheduleId && local.EventScheduleId !== row.EventScheduleId) {
                            local.EventScheduleId = row.EventScheduleId; changed = true;
                        }
                    }
                } else {
                    delete saved[e.cid];
                }
            });
        }

        function reject(sent, fi, message) {
            var e = sent[fi], cid = e.cid, local = find(cid);
            var lastHad = clone(local) || clone(e.item) || clone(e.snap) || clone(saved[cid]);
            var rest = sent.filter(function (x) { return x.cid !== cid; });
            queue = queue.filter(function (x) { return x.cid !== cid; });
            var i = indexOf(cid);
            if (saved[cid]) {
                if (i < 0) list.push(clone(saved[cid])); else list[i] = clone(saved[cid]);
            } else if (i >= 0) {
                list.splice(i, 1);
            }
            changed = true;
            dropHistory(cid);
            requeue(rest);
            rejections.push({ kind: e.op, cid: cid, item: lastHad, message: message || '' });
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
            changed = true;
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
            changed = true;
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
            changed = true;
            enqueue({ op: 'remove', cid: cid, item: snap });
        }

        // Bring an item to a snapshot (null = absent) without recording history.
        function applySnapshot(cid, target) {
            var local = find(cid);
            if (!target) { if (local) doRemove(cid, false, false); return; }
            if (!local) { doAdd(target, cid, false); return; }
            var diff = {};
            Object.keys(target).forEach(function (k) {
                if (k === 'cid' || k === 'EventScheduleId') return;
                if (!same(target[k], local[k])) diff[k] = clone(target[k]);
            });
            doUpdate(cid, diff, false);
        }

        // ---- public API ----

        function status() {
            var pending = queue.length + (inflight ? inflight.length : 0);
            return {
                state: offline ? 'offline' : (pending ? 'saving' : 'saved'),
                pending: pending,
                reason: offline ? offlineReason : null
            };
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
            add: function (item, o) {
                var cid = 'n' + (++seq);
                run(function () { doAdd(item, cid, !o || o.undoable !== false); }, true);
                return cid;
            },
            update: function (cid, f, o) {
                run(function () { doUpdate(cid, f, !o || o.undoable !== false); }, true);
            },
            remove: function (cid, o) {
                var undoable = !o || o.undoable !== false;
                run(function () { doRemove(cid, undoable, !undoable); }, true);
            },
            batch: function (fn) { run(fn, true); },
            undo: function () { return step(undoStack, redoStack, 'before', true); },
            redo: function () { return step(redoStack, undoStack, 'after', false); },
            canUndo: function () { return undoStack.length > 0; },
            canRedo: function () { return redoStack.length > 0; },
            status: status,
            retry: function () {
                run(function () { offline = false; offlineReason = null; pump(); }, false);
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
                    changed = true;
                }, false);
                return true;
            },
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

    return { create: create };
});
