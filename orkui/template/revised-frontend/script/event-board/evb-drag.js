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
 * evb-type.js owns a press on empty day space (its target is the day body
 * itself); this file owns one that starts on a block, so one press has one owner.
 *
 * The board re-renders its whole DOM on every change, so nothing here lives in
 * a block: the gesture is kept by cid, pointer capture is on #evb-root, and the
 * ghost is an element of this file's own, put back into its day after each render.
 *
 * Every gesture ends in one store.batch (one save, one undo step) that sends
 * only StartTime and EndTime; one that ends where it began changes nothing.
 *
 *   EvBoardDrag.attach(rootEl, api)   api = {store, getSelection, select, isEditing}
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
        ghost: null,        // .evb-drag-ghost while a gesture shows
        raf: 0,
        swallow: false,     // a drag just ended: swallow its click (dispatched with the release)
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
        var grip = t.closest('.evb-resize-top, .evb-resize-bottom');
        s.g = {
            id: e.pointerId,
            cid: cid,
            mode: grip ? (grip.classList.contains('evb-resize-top') ? 'start' : 'end') : 'move',
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
            day: null       // the day the ghost is drawn in
        };
        // A grip press is a resize, never a text selection or a focus change.
        if (grip) e.preventDefault();
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
        s.rootEl.classList.toggle('evb-dragging-resize', g.mode !== 'move');
        var ghost = g.proto.cloneNode(true);
        ['evb-selected', 'evb-dim', 'evb-drag-source', 'evb-block-continues', 'evb-block-continued'].forEach(function (c) {
            ghost.classList.remove(c);
        });
        ghost.classList.add('evb-drag-ghost');
        ['data-cid', 'role', 'tabindex', 'aria-pressed', 'aria-label'].forEach(function (a) { ghost.removeAttribute(a); });
        ghost.setAttribute('aria-hidden', 'true');
        ghost.querySelectorAll('.evb-resize-top, .evb-resize-bottom').forEach(function (n) { n.parentNode.removeChild(n); });
        g.proto = null;
        s.ghost = ghost;
        if (!s.raf) s.raf = win.requestAnimationFrame(tick);
    }

    // Work out where the item would go from the pointer, then draw it.
    function update() {
        var g = s.g;
        if (!g || !g.active) return;
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
                onAxis(minuteAt(col, g.y)) - g.pressMin, step, {
                    min: Math.max(b.axisStart, C.BOARD_DAY_START),
                    max: Math.min(b.axisEnd - ROW, C.BOARD_DAY_END)
                });
        } else {
            // The edge follows the pointer in its own day's column, whichever column the pointer is over.
            var body = bodyFor(g.pressDay);
            if (!body) return;
            var off = C.diffDays(sp.day, g.pressDay) * C.DAY_MIN;
            var edge = g.mode === 'start' ? sp.start : sp.end;
            var to = snapped(edge, onAxis(minuteAt(body, g.y)) - g.pressMin, step);
            to = off + onAxis(to - off);
            g.times = C.resizeTimes(g.item, g.mode, to);
        }
        g.day = C.boardPos(g.times.StartTime).day;
        draw();
    }

    // The ghost in its day, the original dimmed, the target day lit. Run after
    // every render too, which replaces all three.
    function draw() {
        var g = s.g;
        if (!g || !g.active || !s.ghost) return;
        s.rootEl.querySelectorAll('.evb-block[data-cid]').forEach(function (bl) {
            bl.classList.toggle('evb-drag-source', bl.getAttribute('data-cid') === g.cid);
        });
        s.rootEl.querySelectorAll('.evb-day').forEach(function (d) {
            d.classList.toggle('evb-drop-target', !!g.day && d.getAttribute('data-day') === g.day);
        });
        var body = g.day && bodyFor(g.day);
        if (!g.times || !body) {
            if (s.ghost.parentNode) s.ghost.parentNode.removeChild(s.ghost);
            return;
        }
        var sp = C.span({ StartTime: g.times.StartTime, EndTime: g.times.EndTime });
        var px = V().PX_PER_MIN;
        s.ghost.style.top = ((sp.start - V().board.axisStart) * px + 1).toFixed(1) + 'px';
        s.ghost.style.height = (Math.max(sp.end - sp.start, 15) * px - 2).toFixed(1) + 'px';
        var meta = s.ghost.querySelector('.evb-block-meta');
        if (meta) meta.textContent = C.fmtRange(sp.start, sp.end);
        if (s.ghost.parentNode !== body) body.appendChild(s.ghost);
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

    // Ends the gesture's display. A gesture that was showing swallows the click
    // that follows unless it was cancelled with Esc.
    function finish(swallow) {
        var g = s.g;
        s.g = null;
        if (!g || !g.active) return g;
        if (s.raf) win.cancelAnimationFrame(s.raf);
        s.raf = 0;
        try { s.rootEl.releasePointerCapture(g.id); } catch (err) { /* already released */ }
        if (s.ghost && s.ghost.parentNode) s.ghost.parentNode.removeChild(s.ghost);
        s.ghost = null;
        s.rootEl.classList.remove('evb-dragging', 'evb-dragging-resize');
        s.rootEl.querySelectorAll('.evb-drag-source').forEach(function (bl) { bl.classList.remove('evb-drag-source'); });
        s.rootEl.querySelectorAll('.evb-drop-target').forEach(function (d) { d.classList.remove('evb-drop-target'); });
        if (swallow) {
            // The browser sends the release's click in the same task; anything later is a new click.
            s.swallow = true;
            win.setTimeout(function () { s.swallow = false; }, 0);
        }
        return g;
    }

    function onPointerUp(e) {
        var g = s.g;
        if (!g || e.pointerId !== g.id) return;
        if (!g.active) { s.g = null; return; }
        g.x = e.clientX;
        g.y = e.clientY;
        update();
        finish(true);
        if (!g.times || !editing()) return;
        apply(g);
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
        s.rootEl = s.api = s.g = null;
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
