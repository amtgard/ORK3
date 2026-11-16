/*
 * EvBoardType: typing straight onto the Event Schedule Board in Edit mode.
 *
 * Click an empty time (or press and drag to choose a length) and an editing
 * block opens there with a compact EvBoardEntry in it. Enter adds the item and
 * opens the next block where it ended; Tab adds it and opens one alongside;
 * Esc (or Enter on an empty block) ends the run. Clicking elsewhere adds a
 * valid entry and discards an empty one. On touch, a tap puts '<day> <time> '
 * in the quick line instead.
 *
 * The board re-renders its whole DOM on every change, so the editing block is
 * not part of it: the board lays out an invisible placeholder in its place
 * (EvBoardView.setPlaceholder, so neighbours make room) and the real block, with
 * its input, lives in a layer beside #evb-root, positioned over the placeholder
 * after every render. Typing therefore never rebuilds the input: the caret,
 * focus and IME composition survive every re-render.
 *
 * Attached through EvBoardEdit.onEnter / onBeforeExit / onExit.
 *   EvBoardType.isOpen() -> bool   an editing block is open
 */
(function (root, factory) {
    root.EvBoardType = factory(root.EvBoardCore, root);
})(this, function (C, win) {
    'use strict';

    var STEP = 15;      // minutes per slot
    var DRAG_PX = 4;    // movement before a press becomes a drag
    var HEAD_PX = 34;   // the sticky day heads cover the top of the board's frame
    var doc = win.document;

    var s = {
        ctx: null,      // the edit context while attached
        rootEl: null,
        layer: null,    // zero-height positioning layer after #evb-root
        blockEl: null,  // the editing block (.evb-block.evb-block-editing while open)
        metaEl: null,
        pop: null,      // chips and suggestions, floating below the block
        entry: null,
        open: null,     // { day, start, end } the block as opened, while open
        live: null,     // { day, start, end, category } as the text reads now
        layoutKey: '',
        posKey: '',
        press: null,    // a mouse or pen press on the board
        tap: null,      // a touch press on the board
        closing: false,
        len: null,      // cached length of the last item created
        raf: 0,
        offs: []
    };

    function V() { return win.EvBoardView; }

    function catsByName() {
        var by = {};
        ((win.EvConfig && win.EvConfig.scheduleCategories) || []).forEach(function (c) { by[c.name] = c; });
        return by;
    }

    function boldOf(name) {
        var by = catsByName(), def = by[name] || by.Other;
        return (def && def.bold) || '#616161';
    }

    // The length of the last item created in this Edit session (60 at first).
    function lastLength() {
        if (s.len == null) s.len = s.ctx.entryContext().lastDuration || 60;
        return s.len;
    }

    // ---- the editing block ----

    function build() {
        var layer = doc.createElement('div');
        layer.className = 'evb-type-layer';
        layer.innerHTML = '<div class="evb-type-block" hidden><div class="evb-type-host"></div>' +
            '<div class="evb-block-meta evb-type-meta" aria-hidden="true"></div></div>' +
            '<div class="evb-type-pop" hidden></div>';
        s.rootEl.parentNode.insertBefore(layer, s.rootEl.nextSibling);
        s.layer = layer;
        s.blockEl = layer.querySelector('.evb-type-block');
        s.metaEl = layer.querySelector('.evb-type-meta');
        s.pop = layer.querySelector('.evb-type-pop');

        s.entry = win.EvBoardEntry.create(layer.querySelector('.evb-type-host'), {
            compact: true,
            placeholder: 'Type a title',
            getContext: function () {
                var o = s.open || { day: null, start: 0, end: 60 };
                // lastEnd: null keeps a typed length ('45m') at the block's own start.
                return s.ctx.entryContext({ fixedDay: o.day, fixedStart: o.start, fixedEnd: o.end, lastEnd: null });
            },
            onParse: onParse,
            onCommit: function () { return false; } // Enter is handled below
        });
        // The chips and suggestion list float below the block, not inside it.
        s.pop.appendChild(s.entry.el.querySelector('.evb-entry-chips'));
        s.pop.appendChild(s.entry.el.querySelector('.evb-entry-menu'));

        s.blockEl.addEventListener('keydown', onKeyDown, true);
        s.entry.input.addEventListener('blur', onBlur);
        // A press on the block or its chips keeps focus in the input.
        [s.blockEl, s.pop].forEach(function (el) {
            el.addEventListener('mousedown', function (e) {
                if (e.target === s.entry.input) return;
                e.preventDefault();
                if (s.open) s.entry.focus();
            });
        });
    }

    // Open (or move) the editing block; the same input stays focused through a run.
    function openBlock(day, start, end, opts) {
        s.open = { day: day, start: start, end: end };
        s.live = null;
        s.layoutKey = s.posKey = '';
        s.blockEl.className = 'evb-type-block evb-block evb-block-editing';
        s.blockEl.hidden = false;
        s.pop.hidden = false;
        s.entry.input.setAttribute('aria-label', 'New item, ' + C.dayLabel(day) + ' ' + C.fmtRange(start, end));
        s.entry.clear(); // parses the empty line: onParse lays the placeholder out
        s.entry.focus();
        if (opts && opts.reveal) {
            var ph = s.rootEl.querySelector('.evb-placeholder');
            if (ph) V().reveal(ph);
            position();
        }
        if (!s.raf) s.raf = win.requestAnimationFrame(tick);
    }

    function closeBlock() {
        if (!s.open) return;
        s.open = s.live = null;
        s.closing = true;
        try {
            s.entry.clear();
            s.blockEl.className = 'evb-type-block';
            s.blockEl.hidden = true;
            s.pop.hidden = true;
            s.blockEl.style.clipPath = '';
            V().setPlaceholder(null);
        } finally {
            s.closing = false;
        }
    }

    // What the text says now: the block moves, resizes and recolours to follow it.
    function onParse(r) {
        if (!s.open) return;
        var o = s.open;
        s.live = r ? { day: r.day || o.day, start: r.startMin, end: r.endMin, category: r.category || 'Other' }
            : { day: o.day, start: o.start, end: o.end, category: 'Other' };
        var l = s.live;
        s.blockEl.setAttribute('data-category', l.category);
        s.blockEl.style.setProperty('--evb-fill', boldOf(l.category));
        s.metaEl.textContent = C.fmtRange(l.start, l.end);
        // Lane layout depends on the times and the category, never on the title.
        var key = [l.day, l.start, l.end, l.category].join('|');
        if (key === s.layoutKey) return;
        s.layoutKey = key;
        V().setPlaceholder({
            EventScheduleId: 0, Title: '', Leads: [], Category: l.category,
            StartTime: C.toNaive(l.day, l.start), EndTime: C.toNaive(l.day, l.end)
        });
    }

    // Put the block over its placeholder, clipped to the board's frame, with the
    // chips below it (above it when there is no room below).
    function position() {
        if (!s.open || !s.layer) return;
        var ph = s.rootEl.querySelector('.evb-placeholder');
        var scroller = s.rootEl.querySelector('.evb-scroller');
        if (!ph || !ph.offsetParent || !scroller) {
            s.blockEl.style.clipPath = 'inset(50%)';
            s.pop.style.visibility = 'hidden';
            s.posKey = '';
            return;
        }
        var lr = s.layer.getBoundingClientRect(), r = ph.getBoundingClientRect();
        var sr = scroller.getBoundingClientRect();
        // Called every frame while open: write only when something moved.
        var key = [lr.left, lr.top, r.left, r.top, r.width, r.height, sr.top, sr.left, sr.height, sr.width,
            win.innerHeight, s.pop.offsetHeight].join(',');
        if (key === s.posKey) return;
        s.posKey = key;
        var h = Math.max(r.height, 22);
        var bs = s.blockEl.style;
        bs.left = (r.left - lr.left) + 'px';
        bs.top = (r.top - lr.top) + 'px';
        bs.width = r.width + 'px';
        bs.height = h + 'px';
        // The frame's visible inside (scrollbars excluded).
        var vt = sr.top + scroller.clientTop, vl = sr.left + scroller.clientLeft;
        var vb = vt + scroller.clientHeight, vr = vl + scroller.clientWidth;
        var it = Math.max(0, vt - r.top), ib = Math.max(0, r.top + h - vb);
        var il = Math.max(0, vl - r.left), ir = Math.max(0, r.right - vr);
        bs.clipPath = (it || ib || il || ir) ? 'inset(' + it + 'px ' + ir + 'px ' + ib + 'px ' + il + 'px)' : '';

        var ps = s.pop.style;
        var shown = r.top + h > vt + HEAD_PX && r.top < vb;
        ps.visibility = shown ? '' : 'hidden';
        ps.left = (r.left - lr.left) + 'px';
        var below = r.top + h + 4, ph2 = s.pop.offsetHeight;
        var flip = below + ph2 > win.innerHeight && r.top - 4 - ph2 > 56;
        ps.top = ((flip ? r.top - 4 - ph2 : below) - lr.top) + 'px';
    }

    function tick() {
        s.raf = 0;
        if (!s.open) return;
        position();
        s.raf = win.requestAnimationFrame(tick);
    }

    function shake() {
        var el = s.entry.el;
        el.classList.remove('evb-entry-invalid');
        void el.offsetWidth; // restart the animation
        el.classList.add('evb-entry-invalid');
    }

    // Add the block's item when it reads as one. Returns 'added', 'empty' or
    // 'invalid' (text with no title), and leaves the block open.
    function addCurrent() {
        if (!s.entry.getText().trim()) return 'empty';
        var r = s.entry.result();
        if (!r || !r.ok) return 'invalid';
        s.ctx.add(r);
        return 'added';
    }

    // The block is being left (a click elsewhere, Done): add a valid entry,
    // discard an empty one, keep text with no title. False when it was kept.
    function settle() {
        if (!s.open) return true;
        if (addCurrent() === 'invalid') return false;
        closeBlock();
        return true;
    }

    function menuOpen() { return s.entry.input.getAttribute('aria-expanded') === 'true'; }

    // Runs before the entry's own key handling (capture), unless its suggestion
    // list is open, which keeps Enter, Tab and Esc for picking.
    function onKeyDown(e) {
        if (!s.open || e.isComposing || e.keyCode === 229 || menuOpen()) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            closeBlock();
            s.ctx.quickLine.focus();
            return;
        }
        if (e.key !== 'Enter' && !(e.key === 'Tab' && !e.shiftKey)) return;
        if (e.altKey || e.ctrlKey || e.metaKey) return;
        var text = s.entry.getText().trim();
        if (!text) {
            if (e.key === 'Tab') return; // focus moves on and the empty block closes
            e.preventDefault();
            e.stopPropagation();
            closeBlock();
            s.ctx.quickLine.focus();
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        var r = s.entry.result();
        if (!r || !r.ok) { shake(); return; }
        var len = r.endMin - r.startMin;
        if (len <= 0) len = Math.max(s.open.end - s.open.start, STEP);
        s.ctx.add(r);
        // Enter: the next block starts where this one ended; Tab: alongside it.
        var start = e.key === 'Enter' ? r.endMin : r.startMin;
        openBlock(r.day, start, start + len, { reveal: e.key === 'Enter' });
    }

    function onBlur() {
        if (s.closing || !s.open) return;
        // Switching windows or tabs blurs too; focus comes back to the block.
        if (!doc.hasFocus()) return;
        settle();
    }

    // ---- the board: hover, click, drag, tap ----

    function bodyFor(day) {
        return s.rootEl.querySelector('.evb-day-body[data-day="' + day + '"]');
    }

    function slotFor(body, clientY) {
        return V().slotAt(clientY - body.getBoundingClientRect().top, V().board.axisStart, STEP);
    }

    function showHover(day, start, len, dragging) {
        var body = bodyFor(day);
        if (!body) return;
        var el = body.querySelector('.evb-slot-hover');
        if (!el) {
            hideHover();
            el = doc.createElement('div');
            el.className = 'evb-slot-hover';
            el.setAttribute('aria-hidden', 'true');
            body.insertBefore(el, body.firstChild); // under the blocks
        }
        el.classList.toggle('evb-slot-hover-drag', !!dragging);
        el.style.top = ((start - V().board.axisStart) * V().PX_PER_MIN).toFixed(1) + 'px';
        el.style.height = (len * V().PX_PER_MIN).toFixed(1) + 'px';
        el.textContent = dragging ? C.fmtRange(start, start + len) : '';
    }

    function hideHover() {
        if (!s.rootEl) return;
        s.rootEl.querySelectorAll('.evb-slot-hover').forEach(function (el) { el.parentNode.removeChild(el); });
    }

    // Only empty space: the target is the day body itself, not a block in it.
    function emptyBody(t) {
        return t && t.classList && t.classList.contains('evb-day-body') ? t : null;
    }

    function onPointerMove(e) {
        var p = s.press;
        if (p && e.pointerId === p.id) {
            if (!p.dragging && Math.abs(e.clientY - p.y0) <= DRAG_PX) return;
            p.dragging = true;
            var body = bodyFor(p.day);
            if (!body) return;
            var cur = slotFor(body, e.clientY);
            p.start = Math.min(p.min, cur);
            p.end = Math.max(p.min, cur) + STEP;
            showHover(p.day, p.start, p.end - p.start, true);
            return;
        }
        if (p || e.pointerType === 'touch') return;
        var b = emptyBody(e.target);
        if (!b) { hideHover(); return; }
        showHover(b.getAttribute('data-day'), slotFor(b, e.clientY), lastLength(), false);
    }

    function onPointerDown(e) {
        var body = emptyBody(e.target);
        if (!body || e.button !== 0) return;
        var day = body.getAttribute('data-day'), min = slotFor(body, e.clientY);
        if (e.pointerType === 'touch') {
            s.tap = { id: e.pointerId, day: day, min: min };
            return;
        }
        // No focus change and no text selection: the block opens on release.
        e.preventDefault();
        if (!settle()) { s.entry.focus(); shake(); return; }
        s.press = { id: e.pointerId, day: day, min: min, y0: e.clientY, dragging: false };
        try { s.rootEl.setPointerCapture(e.pointerId); } catch (err) { /* capture is a nicety */ }
        showHover(day, min, lastLength(), false);
    }

    function onPointerUp(e) {
        var t = s.tap;
        if (t && e.pointerId === t.id) {
            s.tap = null;
            // A tap fills the quick line: the day and time, ready for a title.
            var line = s.ctx.quickLine;
            line.setText(C.dayLabel(t.day) + ' ' + C.fmtTime(t.min) + ' ');
            line.input.focus();
            return;
        }
        var p = s.press;
        if (!p || e.pointerId !== p.id) return;
        s.press = null;
        try { s.rootEl.releasePointerCapture(p.id); } catch (err) { /* already released */ }
        hideHover();
        if (p.dragging) openBlock(p.day, p.start, p.end);
        else openBlock(p.day, p.min, p.min + lastLength());
    }

    function onPointerCancel(e) {
        if (s.tap && e.pointerId === s.tap.id) s.tap = null;
        if (s.press && e.pointerId === s.press.id) { s.press = null; hideHover(); }
    }

    function onPointerLeave() { if (!s.press) hideHover(); }

    function onScroll() { if (s.open) position(); }

    // ---- attach and detach (EvBoardEdit hooks) ----

    function attach(ctx) {
        if (s.ctx) detach();
        s.rootEl = ctx.root;
        if (!s.rootEl || !win.EvBoardEntry) return;
        s.ctx = ctx;
        s.len = null;
        build();
        var on = function (target, type, fn, opt) {
            target.addEventListener(type, fn, opt);
            s.offs.push(function () { target.removeEventListener(type, fn, opt); });
        };
        on(s.rootEl, 'pointermove', onPointerMove);
        on(s.rootEl, 'pointerdown', onPointerDown);
        on(s.rootEl, 'pointerup', onPointerUp);
        on(s.rootEl, 'pointercancel', onPointerCancel);
        on(s.rootEl, 'pointerleave', onPointerLeave);
        on(doc, 'scroll', onScroll, true);
        on(win, 'resize', onScroll);
        s.offs.push(ctx.store.on('change', function () { s.len = null; }));
    }

    // Done: a block with no title yet keeps Edit mode open.
    function beforeExit(ctx) {
        if (!s.open) return true;
        if (settle()) return true;
        s.entry.focus();
        ctx.addNotice('The block you are typing has no title yet. Add one and press Enter, or press Esc to drop it, then Done.');
        return false;
    }

    function detach() {
        if (!s.ctx) return;
        closeBlock();
        hideHover();
        s.offs.forEach(function (off) { off(); });
        s.offs = [];
        if (s.raf) win.cancelAnimationFrame(s.raf);
        s.raf = 0;
        if (s.entry) s.entry.destroy();
        if (s.layer && s.layer.parentNode) s.layer.parentNode.removeChild(s.layer);
        s.ctx = s.entry = s.layer = s.blockEl = s.metaEl = s.pop = null;
        s.press = s.tap = null;
    }

    // Every render replaces the placeholder: follow it.
    if (V() && V().onRender) V().onRender(function () { if (s.ctx) position(); });
    if (win.EvBoardEdit && win.EvBoardEdit.onEnter) {
        win.EvBoardEdit.onEnter(attach);
        win.EvBoardEdit.onBeforeExit(beforeExit);
        win.EvBoardEdit.onExit(detach);
    }

    return {
        isOpen: function () { return !!s.open; }
    };
});
