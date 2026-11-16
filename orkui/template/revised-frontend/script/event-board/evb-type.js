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
 * The same block retypes an existing item in place (editItem): it opens over
 * the item prefilled with '<Title>[ at <Location>][ "<Description>"]', and
 * Enter, Tab or a click elsewhere updates only the parts the person changed:
 * the prefill is parsed once as it opens, the edited text is parsed the same
 * way, and only parts whose parsed value differs from that first parse are
 * written (never a comparison with the stored item, which the one-line prefill
 * cannot always spell exactly). Text left as it was prefilled changes nothing.
 *
 * Attached through EvBoardEdit.onEnter / onBeforeExit / onExit.
 *   EvBoardType.isOpen() -> bool   an editing block is open
 *   EvBoardType.isFresh() -> bool  a new block is open and nothing was typed in it
 *   EvBoardType.editItem(cid, {onDone}) -> bool   retype an item in place;
 *                                  onDone({keyboard}) runs when its block closes
 *                                  (keyboard: closed with focus still in it)
 */
(function (root, factory) {
    root.EvBoardType = factory(root.EvBoardCore, root);
})(this, function (C, win) {
    'use strict';

    var STEP = 15;      // minutes per slot
    var DRAG_PX = 4;    // movement before a press becomes a drag
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
        edit: null,     // { cid, id, item, text, base, onDone } while an item is retyped in place
        typed: false,   // something was typed in the open block
        layoutKey: '',
        posKey: '',
        unshownTimer: 0,
        press: null,    // a mouse or pen press on the board
        tap: null,      // a touch press on the board
        closing: false,
        len: null,      // cached length of the last item created
        raf: 0,
        offs: []
    };

    function V() { return win.EvBoardView; }

    // Done is in progress: the store is about to go, so nothing opens or adds.
    function leaving() { return !!(s.ctx && s.ctx.isLeaving && s.ctx.isLeaving()); }

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
        s.entry.input.addEventListener('input', function () { s.typed = true; });
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
    // opts.text prefills it (an item retyped in place).
    function openBlock(day, start, end, opts) {
        s.open = { day: day, start: start, end: end };
        s.live = null;
        s.typed = false;
        s.layoutKey = s.posKey = '';
        s.blockEl.className = 'evb-type-block evb-block evb-block-editing';
        s.blockEl.hidden = false;
        s.pop.hidden = false;
        s.pop.classList.toggle('evb-type-retyping', !!s.edit);
        s.entry.input.setAttribute('aria-label', (s.edit ? 'Rename item, ' : 'New item, ') + C.dayLabel(day) + ' ' + C.fmtRange(start, end));
        // Either parses the line: onParse lays the placeholder out.
        if (opts && opts.text) s.entry.setText(opts.text);
        else s.entry.clear();
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
        var done = s.edit && s.edit.onDone, keyboard = false;
        s.open = s.live = s.edit = null;
        s.closing = true;
        try {
            s.entry.clear();
            s.blockEl.className = 'evb-type-block';
            s.blockEl.hidden = true;
            s.pop.hidden = true;
            s.blockEl.style.clipPath = '';
            V().setPlaceholder(null);
            // A retyped item closed from the keyboard hands the keys back to the
            // board (its selection), not to an input hidden in a closed block.
            keyboard = !!done && doc.activeElement === s.entry.input;
            if (keyboard) s.entry.input.blur();
        } finally {
            s.closing = false;
        }
        if (done) done({ keyboard: keyboard });
    }

    // What the text says now: the block moves, resizes and recolours to follow it.
    function onParse(r) {
        if (!s.open) return;
        var o = s.open;
        // Retyping an item keeps its category unless one is typed.
        var keep = s.edit && !(r && r.given.category) ? (s.edit.item.Category || 'Other') : null;
        s.live = r ? { day: r.day || o.day, start: r.startMin, end: r.endMin, category: keep || r.category || 'Other' }
            : { day: o.day, start: o.start, end: o.end, category: keep || 'Other' };
        var l = s.live;
        s.blockEl.setAttribute('data-category', l.category);
        s.blockEl.style.setProperty('--evb-fill', V().boldFor(l.category));
        s.metaEl.textContent = C.fmtRange(l.start, l.end);
        // Lane layout depends on the times and the category, never on the title.
        var key = [l.day, l.start, l.end, l.category].join('|');
        if (key === s.layoutKey) return;
        s.layoutKey = key;
        placeholder();
    }

    // The invisible block the board lays out where the editor is. A retyped item's
    // placeholder carries its cid, so the board leaves the item itself out.
    function placeholder() {
        var l = s.live;
        if (!l) return;
        V().setPlaceholder({
            EventScheduleId: 0, Title: '', Leads: [], Category: l.category, cid: s.edit ? s.edit.cid : undefined,
            StartTime: C.toNaive(l.day, l.start), EndTime: C.toNaive(l.day, l.end)
        });
    }

    // A refresh renames items' cids: follow the item being retyped to its new cid
    // (by server id), so it is not drawn underneath its own editor.
    function followEditTarget() {
        if (!s.open || !s.edit) return;
        var item = editTarget();
        if (!item) return;
        if (+item.EventScheduleId) s.edit.id = +item.EventScheduleId;
        s.edit.item = item;
        if (item.cid === s.edit.cid) return;
        s.edit.cid = item.cid;
        placeholder();
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
            // Not after a render's callbacks re-render: settle on the next turn.
            if (!s.unshownTimer) s.unshownTimer = win.setTimeout(settleUnshown, 0);
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
        var shown = r.top + h > vt + V().HEAD_PX && r.top < vb;
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

    // ---- retyping an item in place ----

    // An item's board day and its start and end in that day's minutes.
    function timing(item) {
        var segs = C.segments(item);
        if (!segs.length) return null;
        var day = segs[0].day, start = segs[0].startMin;
        var e = C.parseNaive(item.EndTime);
        var end = e ? C.diffDays(day, e.day) * 1440 + e.min : start;
        return { day: day, start: start, end: Math.max(end, start) };
    }

    // The line an item is retyped from (the quotes are formatEntry's).
    function editText(item) {
        var out = String(item.Title || '');
        if (item.Location) out += ' at ' + item.Location;
        if (item.Description) {
            var d = String(item.Description);
            out += d.indexOf('"') >= 0 ? ' \u201C' + d + '\u201D' : ' "' + d + '"';
        }
        return out;
    }

    // The item being retyped, by cid, or by server id after a refresh renamed it.
    function editTarget() {
        var e = s.edit, store = s.ctx.store, item = store.get(e.cid);
        if (!item && e.id) {
            store.items().some(function (it) {
                if (+it.EventScheduleId !== e.id) return false;
                item = it;
                return true;
            });
        }
        return item;
    }

    // A typed cost, or null when none was typed: an absent cost differs from $0.
    function costOf(r) { return r.given.cost && r.cost != null ? +r.cost : null; }
    // A category the line names (typed, or a 'Name:' prefix), or null: the item keeps its own.
    function categoryOf(r) { return r.given.category ? r.category : null; }

    // The title after an edit. The prefill's parse can read part of the stored
    // title differently (a '#2' from 'at Hall #2' joins it), so when the first
    // parse is not the stored title, the one span the person changed is carried
    // into the stored title where it lines up; otherwise the new parse is taken.
    function editedTitle(stored, before, after) {
        if (before === stored) return after;
        var p = 0, B = before.length, A = after.length;
        while (p < B && p < A && before[p] === after[p]) p++;
        var q = 0;
        while (q < B - p && q < A - p && before[B - 1 - q] === after[A - 1 - q]) q++;
        var end = B - q; // the changed span is before[p, end)
        if (end <= stored.length && stored.slice(0, end) === before.slice(0, end)) {
            return (stored.slice(0, p) + after.slice(p, A - q) + stored.slice(end)).replace(/\s+/g, ' ').trim();
        }
        return after;
    }

    // The fields a retyped line changes: every part whose parse differs from the
    // parse of the prefill (s.edit.base). A part the person left alone is never
    // written, whatever the parser made of it.
    function changedFields(r, base, item) {
        var f = {};
        if (r.title !== base.title) f.Title = editedTitle(String(item.Title || ''), base.title, r.title);
        if (r.location !== base.location) f.Location = r.location;
        if (r.description !== base.description) f.Description = r.description;
        if (r.start !== base.start) f.StartTime = r.start;
        if (r.end !== base.end) f.EndTime = r.end;
        var cat = categoryOf(r);
        if (cat !== categoryOf(base) && cat && cat !== (item.Category || 'Other')) f.Category = cat;
        if (r.secondaryCategory !== base.secondaryCategory) f.SecondaryCategory = r.secondaryCategory;
        // A stripe the same as the new category would never show: drop it.
        if (f.Category && f.Category === (f.SecondaryCategory !== undefined ? f.SecondaryCategory : item.SecondaryCategory)) {
            f.SecondaryCategory = '';
        }
        if (costOf(r) !== costOf(base) && costOf(r) !== null) f.Cost = r.cost;
        return f;
    }

    // Save a retyped item: 'same' (the text as prefilled), 'saved', 'empty',
    // 'invalid' (no title), 'refused' (Done is leaving) or 'gone'.
    function saveEdit() {
        var text = s.entry.getText().trim();
        if (text === s.edit.text.trim()) return 'same';
        if (!text) return 'empty';
        var r = s.entry.result();
        if (!r || !r.ok) return 'invalid';
        if (leaving()) return 'refused';
        var item = editTarget();
        if (!item) return 'gone';
        var f = s.edit.base ? changedFields(r, s.edit.base, item) : {};
        if (Object.keys(f).length) s.ctx.store.update(item.cid, f);
        return 'saved';
    }

    // Settle a retyped item; false keeps the block open (text with no title,
    // or Done leaving). An item removed meanwhile hands the text to the notice.
    function settleEdit() {
        var res = saveEdit();
        if (res === 'invalid' || res === 'refused') return false;
        if (res === 'gone') {
            var o = s.open;
            s.ctx.addNotice('The item you were renaming is no longer on the board, so the change was not saved.',
                C.slotLine(o.day, o.start, o.end) + s.entry.getText().trim());
        }
        closeBlock();
        return true;
    }

    function editItem(cid, opts) {
        if (!s.ctx || leaving()) return false;
        var item = s.ctx.store.get(cid), t = item && timing(item);
        if (!t) return false;
        if (s.open && !settle()) { s.entry.focus(); shake(); return false; }
        var text = editText(item);
        s.edit = { cid: cid, id: +item.EventScheduleId || 0, item: item, text: text, base: null, onDone: opts && opts.onDone };
        openBlock(t.day, t.start, t.end, { text: text, reveal: true });
        // What the box really holds (an input drops a description's line breaks),
        // and its parse with the item's own day and time fixed: the reference every
        // later change is measured against.
        s.edit.text = s.entry.getText();
        s.edit.base = s.entry.result();
        return true;
    }

    // Add the block's item when it reads as one. Returns 'added', 'empty' or
    // 'invalid' (text with no title), and leaves the block open.
    function addCurrent() {
        if (!s.entry.getText().trim()) return 'empty';
        var r = s.entry.result();
        if (!r || !r.ok) return 'invalid';
        return s.ctx.add(r) == null ? 'refused' : 'added';
    }

    // The block is being left (a click elsewhere, Done): add a valid entry,
    // discard an empty one, keep text with no title. False when it was kept.
    function settle() {
        if (!s.open) return true;
        if (s.edit) return settleEdit();
        var res = addCurrent();
        if (res === 'invalid' || res === 'refused') return false;
        closeBlock();
        return true;
    }

    // The board stopped showing the block (List view, another tab, another day):
    // settle it as a click elsewhere would, and a line that cannot be added yet
    // goes to the quick line (or the notice) instead of a focused invisible input.
    function settleUnshown() {
        s.unshownTimer = 0;
        if (!s.open) return;
        var ph = s.rootEl.querySelector('.evb-placeholder');
        if (ph && ph.offsetParent) return; // showing again
        var text = s.entry.getText().trim();
        if (settle()) return;
        var r = s.entry.result(), o = s.open;
        var line = (r && r.given && r.given.time ? C.dayLabel(o.day) + ' ' : C.slotLine(o.day, o.start, o.end)) + text;
        closeBlock();
        var q = s.ctx.quickLine;
        if (!q.getText().trim() && !q.input.readOnly) q.setText(line);
        else s.ctx.addNotice('The item you were typing on the board was not added yet.', line);
    }

    function menuOpen() { return s.entry.input.getAttribute('aria-expanded') === 'true'; }

    // Runs before the entry's own key handling (capture), unless its suggestion
    // list is open, which keeps Enter, Tab and Esc for picking.
    function onKeyDown(e) {
        if (!s.open || e.isComposing || e.keyCode === 229 || menuOpen() || !s.ctx.boardShown()) return;
        if (leaving()) {
            if (e.key === 'Enter' || e.key === 'Tab') e.preventDefault();
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            var retyping = !!s.edit;
            closeBlock();
            if (!retyping) s.ctx.quickLine.focus();
            return;
        }
        if (e.key !== 'Enter' && !(e.key === 'Tab' && !e.shiftKey)) return;
        if (e.altKey || e.ctrlKey || e.metaKey) return;
        if (s.edit) {
            // Retyping an item: Enter and Tab save it and close; nothing follows on.
            e.preventDefault();
            e.stopPropagation();
            if (!settleEdit()) shake();
            return;
        }
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
            // Under pointer capture the pointer can leave the day: end at the axis.
            p.end = Math.min(Math.max(p.min, cur) + STEP, V().board.axisEnd);
            p.start = Math.min(p.start, p.end - STEP);
            showHover(p.day, p.start, p.end - p.start, true);
            return;
        }
        if (p || e.pointerType === 'touch' || leaving()) { if (!p) hideHover(); return; }
        var b = emptyBody(e.target);
        if (!b) { hideHover(); return; }
        showHover(b.getAttribute('data-day'), slotFor(b, e.clientY), lastLength(), false);
    }

    function onPointerDown(e) {
        var body = emptyBody(e.target);
        if (!body || e.button !== 0 || leaving()) return;
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
            if (leaving()) return;
            // A tap fills the quick line: the day and time, ready for a title.
            var line = s.ctx.quickLine;
            line.setText(C.slotLine(t.day, t.min));
            line.input.focus();
            return;
        }
        var p = s.press;
        if (!p || e.pointerId !== p.id) return;
        s.press = null;
        try { s.rootEl.releasePointerCapture(p.id); } catch (err) { /* already released */ }
        hideHover();
        if (leaving()) return;
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
        s.offs.push(ctx.store.on('change', function () { s.len = null; followEditTarget(); }));
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
        if (s.unshownTimer) win.clearTimeout(s.unshownTimer);
        s.raf = s.unshownTimer = 0;
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
        isOpen: function () { return !!s.open; },
        isFresh: function () { return !!s.open && !s.edit && !s.typed; },
        editItem: editItem
    };
});
