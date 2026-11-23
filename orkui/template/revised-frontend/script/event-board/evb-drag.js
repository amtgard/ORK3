/*
 * EvBoardDrag: moving and resizing blocks on the Event Schedule Board with a
 * mouse or pen in Edit mode. Touch never drags: a touch press is left to the
 * existing tap handling.
 *
 * A press on a block that moves more than 4px picks the item up: the block
 * stays where it is, dimmed, and a ghost of it follows the pointer, snapped to
 * 15 minutes (5 with Alt), showing the time it would get; the day under the
 * pointer is lit. Release puts the item there (every selected item, when the
 * pressed one is part of a larger selection, moves by the same offset). The
 * selected block's grips (.evb-resize-top / .evb-resize-bottom, drawn by
 * evb-select.js) resize it the same way. A press that never moves is left to
 * the click (selection, a double-click rename); the click after a real drag
 * is swallowed. Esc, or a release outside every day column, cancels. A pointer
 * held near the top or bottom of the board's frame (for 200ms) scrolls it while
 * the gesture lasts.
 *
 * The fill handle (.evb-fill-handle, drawn by evb-select.js at the bottom right
 * of a single selected block) repeats the item down its day: dragged down, it
 * previews the copies EvBoardCore.repeatSlots would make (.evb-fill-ghost) up to
 * the pointer, and release adds them as full copies and selects them. A pointer
 * that never passes the first slot adds nothing.
 *
 * evb-type.js owns a press on empty day space (its target is the day body
 * itself); this file owns one that starts on a block, so one press has one owner.
 *
 * The board re-renders its whole DOM on every change, so nothing here lives in
 * a block: the gesture is kept by cid, pointer capture is on #evb-root, and the
 * ghost is an element of this file's own, put back into its day after each render.
 *
 * Every gesture ends in one store.batch (one save, one undo step): a move or a
 * resize sends only StartTime and EndTime, and one that ends where it began
 * changes nothing; a repeat adds its copies. A move or resize keeps every item
 * it moves on the board's days (EvBoardCore.boardRange, by groupShiftLimit and
 * resizeTimes, for one item as for many): it goes only as far as fits, and with
 * no room it changes nothing. A repeat stays on the item's own board day. A
 * rail's preview is a rail while its new times keep it ongoing, else a block.
 *
 *   EvBoardDrag.attach(rootEl, api)   api = {store, getSelection, select, isEditing,
 *                                            copyItem(item, times), atLeastFive(item)}
 *   EvBoardDrag.detach()
 *   EvBoardDrag.isActive() -> bool   a gesture is in progress
 * Attached through EvBoardEdit.onEnter / onExit.
 */
(function (root, factory) {
    root.EvBoardDrag = factory(root.EvBoardCore, root);
})(this, function (C, win) {
    'use strict';

    var DRAG_PX = 4;        // movement before a press becomes a gesture
    var EDGE_PX = 40;       // auto-scroll zone at the frame's top and bottom
    var SCROLL_PX = 10;     // auto-scroll per frame
    var EDGE_DWELL_MS = 200; // how long the pointer stays in the zone before it scrolls
    var ROW = 15;           // a row of the board; a move may not start past the last
    var doc = win.document;

    var s = {
        rootEl: null,
        api: null,
        g: null,            // the press or gesture in progress
        ghost: null,        // .evb-drag-ghost while a move or resize shows (a repeat's model)
        fills: [],          // .evb-fill-ghost elements while a repeat shows
        raf: 0,
        swallow: false,     // a drag just ended: swallow its click (dispatched with the release)
        escUp: null,        // the pointer of a gesture cancelled with Esc, until it is released
        offs: []
    };

    function V() { return win.EvBoardView; }
    function editing() { return !!(s.api && s.api.isEditing()); }
    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

    function bodyFor(day) {
        return s.rootEl.querySelector('.evb-day-body[data-day="' + day + '"]');
    }

    // The board minute (unrounded) at a viewport y in a day body.
    function minuteAt(body, clientY) {
        return (clientY - body.getBoundingClientRect().top) / V().PX_PER_MIN + V().board.axisStart;
    }

    // The board minute clamped to the axis and to the board day.
    function onAxis(min) {
        var b = V().board;
        return clamp(min, Math.max(b.axisStart, C.BOARD_DAY_START), Math.min(b.axisEnd, C.BOARD_DAY_END));
    }

    // Where gestures keep items: the days the board shows (EvBoardCore.boardRange).
    function boardRange() {
        return C.boardRange(V().board.days.map(function (d) { return d.day; }));
    }

    // The shown day column at a viewport x, inside the frame's width (not under
    // the hour gutter), or null. Above or below the frame still counts as the
    // column: the minute is clamped to the axis.
    function columnAt(x) {
        var scroller = s.rootEl.querySelector('.evb-scroller');
        if (!scroller) return null;
        var sr = scroller.getBoundingClientRect();
        if (x < sr.left + V().GUTTER_PX || x > sr.right) return null;
        var bodies = s.rootEl.querySelectorAll('.evb-day-body');
        for (var i = 0; i < bodies.length; i++) {
            if (!bodies[i].offsetParent) continue;
            var r = bodies[i].getBoundingClientRect();
            if (x >= r.left && x < r.right) return bodies[i];
        }
        return null;
    }

    // A pointer delta (minutes) as a new edge: snapped to the step, unless the
    // pointer is still within half a step of where it was pressed (as C.dragTarget).
    function snapped(edge, delta, step) {
        return Math.abs(delta) < step / 2 ? edge : C.snap(edge + delta, step);
    }

    function sameTimes(item, t) {
        return String(item.StartTime).slice(0, 16) === t.StartTime && String(item.EndTime).slice(0, 16) === t.EndTime;
    }

    // ---- the gesture ----

    function onPointerDown(e) {
        s.escUp = null;
        if (e.pointerType === 'touch' || e.button !== 0 || s.g || !editing() || !V() || !V().board) return;
        // Ctrl (a context menu on macOS), Cmd and Shift presses are not drags; Alt is the fine step.
        if (e.ctrlKey || e.metaKey || e.shiftKey) return;
        var t = e.target && e.target.closest ? e.target : null;
        var block = t && t.closest('.evb-block[data-cid]');
        var body = block && block.closest('.evb-day-body');
        if (!block || !body || !s.rootEl.contains(block)) return;
        var cid = block.getAttribute('data-cid'), item = s.api.store.get(cid);
        var sp = item && C.span(item);
        if (!sp) return;
        var fill = t.closest('.evb-fill-handle');
        var grip = !fill && t.closest('.evb-resize-top, .evb-resize-bottom');
        var mode = fill ? 'fill' : grip ? (grip.classList.contains('evb-resize-top') ? 'start' : 'end') : 'move';
        var sel = s.api.getSelection() || [];
        s.g = {
            id: e.pointerId,
            cid: cid,
            mode: mode,
            // The blocks dimmed while it lasts: a move takes the whole selection along.
            group: mode === 'fill' ? [] : mode === 'move' && sel.indexOf(cid) >= 0 ? sel.slice() : [cid],
            item: item,
            sp: sp,
            pressDay: body.getAttribute('data-day'),
            pressMin: minuteAt(body, e.clientY),
            x0: e.clientX, y0: e.clientY, x: e.clientX, y: e.clientY,
            alt: e.altKey,
            // The ghost takes the pressed block's lane and look.
            proto: block,
            active: false,
            edgeSince: 0,   // when the pointer entered an auto-scroll zone
            times: null,    // the item's new {StartTime, EndTime}, or null outside every column
            day: null,      // the day the ghost is drawn in
            slots: [],      // a repeat's copies: [{StartTime, EndTime}]
            dayItems: null  // a repeat: the items on the item's board day
        };
        // A grip or handle press is a gesture, never a text selection or a focus change.
        if (grip || fill) e.preventDefault();
    }

    function onPointerMove(e) {
        var g = s.g;
        if (!g || e.pointerId !== g.id) return;
        g.x = e.clientX;
        g.y = e.clientY;
        g.alt = e.altKey;
        // The button is no longer down (its release went elsewhere, a menu took it): the press is over.
        if ((e.buttons & 1) === 0) {
            if (g.active) cancel(); else s.g = null;
            return;
        }
        if (!g.active) {
            if (Math.abs(g.x - g.x0) <= DRAG_PX && Math.abs(g.y - g.y0) <= DRAG_PX) return;
            if (!editing()) { s.g = null; return; }
            start(g);
        }
        e.preventDefault();
        update();
    }

    function start(g) {
        g.active = true;
        try { s.rootEl.setPointerCapture(g.id); } catch (err) { /* capture is a nicety */ }
        s.rootEl.classList.add('evb-dragging');
        s.rootEl.classList.toggle('evb-dragging-resize', g.mode === 'start' || g.mode === 'end');
        s.rootEl.classList.toggle('evb-dragging-fill', g.mode === 'fill');
        g.rail = g.proto.classList.contains('evb-block-rail');
        g.across = { left: g.proto.style.left, width: g.proto.style.width };
        s.ghost = ghostOf(g.proto, g.mode === 'fill' ? 'evb-fill-ghost' : 'evb-drag-ghost');
        g.proto = null;
        if (g.mode === 'fill') g.dayItems = dayItems(g.sp.day);
        if (!s.raf) s.raf = win.requestAnimationFrame(tick);
    }

    // A copy of a block to draw a preview with: its lane and look, none of its
    // identity, grips, handle or conflict badge (evb-bulk.js).
    function ghostOf(block, cls) {
        var ghost = block.cloneNode(true);
        ['evb-selected', 'evb-dim', 'evb-drag-source', 'evb-block-continues', 'evb-block-continued'].forEach(function (c) {
            ghost.classList.remove(c);
        });
        ghost.classList.add(cls);
        ['data-cid', 'role', 'tabindex', 'aria-pressed', 'aria-label'].forEach(function (a) { ghost.removeAttribute(a); });
        ghost.setAttribute('aria-hidden', 'true');
        ghost.classList.remove('evb-has-conflict');
        ghost.removeAttribute('data-evb-label');
        ghost.querySelectorAll('.evb-resize-top, .evb-resize-bottom, .evb-fill-handle, .evb-conflict').forEach(function (n) { n.parentNode.removeChild(n); });
        // A rail has no time line: the ghost gets one for when it is drawn in the
        // lanes, and keeps its title to show the time in while it is a rail.
        var title = ghost.querySelector('.evb-block-title');
        if (title) { ghost.evbTitle = title.textContent; ghost.evbTitleHtml = title.innerHTML; }
        if (!ghost.querySelector('.evb-block-meta')) {
            var meta = doc.createElement('div');
            meta.className = 'evb-block-meta';
            ghost.appendChild(meta);
        }
        return ghost;
    }

    // A move or resize preview takes the shape the item will have where it is
    // dropped: a rail when its new times keep it ongoing (EvBoardCore.isOngoing),
    // else a block in the lanes. A rail stays in its own rail on a day it is
    // already on, and on another day goes beside that day's rails, never on one.
    function shapeGhost(el, g) {
        var rail = C.isOngoing(Object.assign({}, g.item, g.times));
        el.classList.toggle('evb-block-rail', rail);
        if (rail) {
            var own = null, count = 0;
            V().board.days.forEach(function (d) {
                if (d.day !== g.day) return;
                count = d.railCount || 0;
                (d.rails || []).forEach(function (sg) { if (sg.item && sg.item.cid === g.cid && own === null) own = sg.rail; });
            });
            el.style.left = (1 + V().railWidth(own !== null ? own : count)) + 'px';
            el.style.width = V().RAIL_PX + 'px';
        } else if (g.rail) {
            // Out of the rails: the width the lanes have.
            el.style.left = 'calc(var(--evb-rail-w, 0px) + 1px)';
            el.style.width = 'calc(100% - var(--evb-rail-w, 0px) - 2px)';
        } else {
            el.style.left = g.across.left;
            el.style.width = g.across.width;
        }
    }

    // The items whose start is on a board day.
    function dayItems(day) {
        return s.api.store.items().filter(function (it) {
            var p = C.boardPos(it.StartTime);
            return p && p.day === day;
        });
    }

    // Put a preview block over its times in a day body (at least a row tall).
    function placeGhost(el, body, times) {
        var sp = C.span({ StartTime: times.StartTime, EndTime: times.EndTime });
        var px = V().PX_PER_MIN;
        el.style.top = ((sp.start - V().board.axisStart) * px + 1).toFixed(1) + 'px';
        el.style.height = (Math.max(sp.end - sp.start, 15) * px - 2).toFixed(1) + 'px';
        var range = C.fmtRange(sp.start, sp.end);
        var meta = el.querySelector('.evb-block-meta');
        if (meta) meta.textContent = range;
        // A rail has no room for a time line: the time leads its title, running down it.
        var title = el.evbTitle != null && el.querySelector('.evb-block-title');
        if (title && el.classList.contains('evb-block-rail')) title.textContent = range + ' ' + el.evbTitle;
        else if (title && title.innerHTML !== el.evbTitleHtml) title.innerHTML = el.evbTitleHtml;
        if (el.parentNode !== body) body.appendChild(el);
    }

    // Work out where the item would go from the pointer, then draw it.
    function update() {
        var g = s.g;
        if (!g || !g.active) return;
        if (g.mode === 'fill') { updateFill(g); return; }
        var step = g.alt ? 5 : 15, b = V().board, sp = g.sp;
        var col = columnAt(g.x);
        if (!col) {
            g.times = null;
            g.day = null;
            draw();
            return;
        }
        if (g.mode === 'move') {
            // The item moves by the columns the pointer crossed (from its own start
            // day, whichever segment was pressed); its start stays on the axis: not
            // above the first row, not past the last.
            g.times = C.dragTarget(g.item, g.pressDay, col.getAttribute('data-day'),
                onAxis(minuteAt(col, g.y)) - g.pressMin, step, { min: b.axisStart, max: b.axisEnd - ROW });
            // One item or a group, it moves only as far as all of it stays on the board's days.
            var to = C.span(g.times), store = s.api.store;
            var moved = g.group.length > 1 ? g.group.map(function (c) { return store.get(c); }).filter(Boolean) : [g.item];
            var lim = C.groupShiftLimit(moved, to.start - sp.start, C.diffDays(sp.day, to.day), { range: boardRange() });
            g.times = C.shiftTimes(g.item, lim.dMin, lim.dDays);
        } else {
            // The edge follows the pointer in its own day's column, whichever column the pointer is over.
            var body = bodyFor(g.pressDay);
            if (!body) return;
            var off = C.diffDays(sp.day, g.pressDay) * C.DAY_MIN;
            var edge = g.mode === 'start' ? sp.start : sp.end;
            var at = snapped(edge, onAxis(minuteAt(body, g.y)) - g.pressMin, step);
            at = off + onAxis(at - off);
            g.times = C.resizeTimes(g.item, g.mode, at, boardRange());
        }
        g.day = C.boardPos(g.times.StartTime).day;
        draw();
    }

    // A repeat: the copies that fit between the item and the pointer (on the
    // item's own board day, never past the axis), whichever column the pointer is over.
    function updateFill(g) {
        var body = bodyFor(g.pressDay), b = V().board;
        var item = s.api.store.get(g.cid);
        if (!body || !item) { g.slots = []; drawFill(); return; }
        // The pressed segment may be on the item's second day: count in its first day's minutes.
        var off = C.diffDays(g.sp.day, g.pressDay) * C.DAY_MIN;
        var until = Math.min(minuteAt(body, g.y) + off, b.axisEnd, C.BOARD_DAY_END);
        g.slots = C.repeatSlots(s.api.atLeastFive(item), g.dayItems || [], until);
        drawFill();
    }

    // The repeat's previews, one per copy, in the item's day.
    function drawFill() {
        var g = s.g;
        if (!g || !g.active || !s.ghost) return;
        var body = bodyFor(g.sp.day), n = body ? g.slots.length : 0;
        while (s.fills.length > n) {
            var el = s.fills.pop();
            if (el.parentNode) el.parentNode.removeChild(el);
        }
        for (var i = 0; i < n; i++) {
            if (!s.fills[i]) {
                s.fills[i] = s.ghost.cloneNode(true);
                s.fills[i].evbTitle = s.ghost.evbTitle;
                s.fills[i].evbTitleHtml = s.ghost.evbTitleHtml;
            }
            placeGhost(s.fills[i], body, g.slots[i]);
        }
    }

    // The ghost in its day, the original (and every item moved with it) dimmed,
    // the target day lit. Run after every render too, which replaces all three.
    function draw() {
        var g = s.g;
        if (!g || !g.active || !s.ghost) return;
        if (g.mode === 'fill') { drawFill(); return; }
        s.rootEl.querySelectorAll('.evb-block[data-cid]').forEach(function (bl) {
            bl.classList.toggle('evb-drag-source', g.group.indexOf(bl.getAttribute('data-cid')) >= 0);
        });
        s.rootEl.querySelectorAll('.evb-day').forEach(function (d) {
            d.classList.toggle('evb-drop-target', !!g.day && d.getAttribute('data-day') === g.day);
        });
        var body = g.day && bodyFor(g.day);
        if (!g.times || !body) {
            if (s.ghost.parentNode) s.ghost.parentNode.removeChild(s.ghost);
            return;
        }
        shapeGhost(s.ghost, g);
        placeGhost(s.ghost, body, g.times);
    }

    // While the gesture lasts: scroll the frame when the pointer is near its top
    // or bottom, keeping the ghost under the pointer.
    function tick() {
        s.raf = 0;
        var g = s.g;
        if (!g || !g.active) return;
        var scroller = s.rootEl.querySelector('.evb-scroller');
        if (scroller) {
            // The zones are at the visible edges: the frame's bottom is often below the window.
            var r = scroller.getBoundingClientRect(), before = scroller.scrollTop;
            var top = Math.max(r.top, 0), bottom = Math.min(r.bottom, win.innerHeight);
            var dir = g.y < top + EDGE_PX ? -1 : g.y > bottom - EDGE_PX ? 1 : 0;
            // A pointer that only passes through the zone (or a block grabbed
            // beside the edge and let go) scrolls nothing: it has to stay a moment.
            if (!dir) g.edgeSince = 0;
            else if (!g.edgeSince) g.edgeSince = Date.now();
            else if (Date.now() - g.edgeSince >= EDGE_DWELL_MS) scroller.scrollTop += dir * SCROLL_PX;
            if (scroller.scrollTop !== before) update();
        }
        s.raf = win.requestAnimationFrame(tick);
    }

    // Ends the gesture's display. A released gesture that was showing swallows the
    // click that follows (one cancelled with Esc swallows it on its release).
    function finish(swallow) {
        var g = s.g;
        s.g = null;
        if (!g || !g.active) return g;
        if (s.raf) win.cancelAnimationFrame(s.raf);
        s.raf = 0;
        try { s.rootEl.releasePointerCapture(g.id); } catch (err) { /* already released */ }
        if (s.ghost && s.ghost.parentNode) s.ghost.parentNode.removeChild(s.ghost);
        s.ghost = null;
        s.fills.forEach(function (el) { if (el.parentNode) el.parentNode.removeChild(el); });
        s.fills = [];
        s.rootEl.classList.remove('evb-dragging', 'evb-dragging-resize', 'evb-dragging-fill');
        s.rootEl.querySelectorAll('.evb-drag-source').forEach(function (bl) { bl.classList.remove('evb-drag-source'); });
        s.rootEl.querySelectorAll('.evb-drop-target').forEach(function (d) { d.classList.remove('evb-drop-target'); });
        if (swallow) swallowClick();
        return g;
    }

    // The browser sends the release's click in the same task; anything later is a new click.
    function swallowClick() {
        s.swallow = true;
        win.setTimeout(function () { s.swallow = false; }, 0);
    }

    function onPointerUp(e) {
        var g = s.g;
        // The release of a gesture cancelled with Esc: its click neither selects nor clears.
        if (!g && s.escUp === e.pointerId) { s.escUp = null; swallowClick(); return; }
        if (!g || e.pointerId !== g.id) return;
        if (!g.active) { s.g = null; return; }
        g.x = e.clientX;
        g.y = e.clientY;
        update();
        finish(true);
        if (!editing()) return;
        if (g.mode === 'fill') applyFill(g);
        else if (g.times) apply(g);
    }

    // One store.batch: a full copy of the item at each slot; the copies become the selection.
    function applyFill(g) {
        var store = s.api.store, item = store.get(g.cid);
        if (!item || !g.slots.length) return;
        var added = [];
        store.batch(function () {
            g.slots.forEach(function (t) { added.push(store.add(s.api.copyItem(item, t))); });
        });
        // A copy the store refused has no cid (its notice says why).
        added = added.filter(Boolean);
        if (added.length) s.api.select(added);
    }

    // One store.batch: the pressed item to its new times and, for a move, every
    // other selected item by the same offset.
    function apply(g) {
        var store = s.api.store, item = store.get(g.cid);
        if (!item || sameTimes(item, g.times)) return;
        var sel = s.api.getSelection() || [];
        var others = g.mode === 'move' && sel.indexOf(g.cid) >= 0 ? sel.filter(function (c) { return c !== g.cid; }) : [];
        var to = C.span(g.times);
        var dDays = C.diffDays(g.sp.day, to.day), dMin = to.start - g.sp.start;
        store.batch(function () {
            store.update(g.cid, { StartTime: g.times.StartTime, EndTime: g.times.EndTime });
            others.forEach(function (cid) {
                var o = store.get(cid);
                if (!o) return;
                var t = C.shiftTimes(o, dMin, dDays);
                if (!sameTimes(o, t)) store.update(cid, { StartTime: t.StartTime, EndTime: t.EndTime });
            });
        });
        if (sel.indexOf(g.cid) < 0) s.api.select([g.cid]);
    }

    function cancel() { finish(false); }

    function onPointerCancel(e) {
        if (s.g && e.pointerId === s.g.id) cancel();
    }

    // Esc cancels a gesture and does nothing else; Alt changes the step at once;
    // the arrow keys wait until the gesture is over.
    function onKeyDown(e) {
        var g = s.g;
        if (!g || !g.active) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopImmediatePropagation();
            s.escUp = g.id;
            cancel();
        } else if (/^Arrow/.test(e.key)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        } else if (e.key === 'Alt') {
            e.preventDefault();
            g.alt = true;
            update();
        }
    }

    function onKeyUp(e) {
        var g = s.g;
        if (!g || !g.active || e.key !== 'Alt') return;
        g.alt = false;
        update();
    }

    function onClick(e) {
        if (!s.swallow) return;
        s.swallow = false;
        e.preventDefault();
        e.stopImmediatePropagation();
    }

    // A render replaced the board: draw the gesture again, unless its item is gone.
    function onRender() {
        var g = s.g;
        if (!g || !g.active) return;
        if (!s.api.store.get(g.cid)) { cancel(); return; }
        if (g.mode === 'fill') g.dayItems = dayItems(g.sp.day);
        draw();
    }

    // ---- attach and detach ----

    function attach(rootEl, api) {
        if (s.rootEl) detach();
        if (!rootEl || !api || !V()) return;
        s.rootEl = rootEl;
        s.api = api;
        var on = function (target, type, fn, opt) {
            target.addEventListener(type, fn, opt);
            s.offs.push(function () { target.removeEventListener(type, fn, opt); });
        };
        on(rootEl, 'pointerdown', onPointerDown);
        on(doc, 'pointermove', onPointerMove, true);
        on(doc, 'pointerup', onPointerUp, true);
        on(doc, 'pointercancel', onPointerCancel, true);
        on(doc, 'keydown', onKeyDown, true);
        on(doc, 'keyup', onKeyUp, true);
        on(win, 'click', onClick, true);
        on(win, 'blur', function () { if (s.g) cancel(); });
        // A context menu takes the press (its release often never arrives).
        on(doc, 'contextmenu', function () { if (s.g) cancel(); }, true);
    }

    function detach() {
        if (!s.rootEl) return;
        cancel();
        s.offs.forEach(function (off) { off(); });
        s.offs = [];
        s.rootEl = s.api = s.g = s.escUp = null;
        s.swallow = false;
    }

    if (V() && V().onRender) V().onRender(function () { if (s.rootEl) onRender(); });
    if (win.EvBoardEdit && win.EvBoardEdit.onEnter) {
        win.EvBoardEdit.onEnter(function (ctx) {
            var sel = win.EvBoardSelect;
            attach(ctx.root, {
                store: ctx.store,
                getSelection: function () { return sel ? sel.selection() : []; },
                select: function (cids) { if (sel) sel.select(cids); },
                copyItem: function (item, times) { return sel.copyItem(item, times); },
                atLeastFive: function (item) { return sel.atLeastFive(item); },
                isEditing: function () { return !ctx.isLeaving() && V().isEditing() && ctx.boardShown(); }
            });
        });
        win.EvBoardEdit.onExit(detach);
    }

    return {
        attach: attach,
        detach: detach,
        // A gesture is showing (Undo and Redo wait for it to end).
        isActive: function () { return !!(s.g && s.g.active); }
    };
});
