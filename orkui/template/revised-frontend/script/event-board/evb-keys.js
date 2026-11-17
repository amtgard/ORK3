/*
 * EvBoardKeys: the keyboard's slot cursor, and copy and paste, on the Event
 * Schedule Board in Edit mode.
 *
 * The slot cursor (.evb-slot-cursor[data-day][data-min]) is one 15-minute slot
 * in one day, outlined, shown while no block is selected and no editing block
 * is open. It starts where an empty time was last clicked or the last item was
 * typed in (where that item ends), else on the first day at 9:00. The arrow
 * keys move it (Up and Down 15 minutes, within the axis; Left and Right a day,
 * within the board's days) and keep it in view; typing a character opens an
 * editing block there with that character typed (EvBoardType.openAt), and Enter
 * opens one empty.
 *
 * Copy (Cmd/Ctrl+C with blocks selected and no text selected on the page) puts
 * EvBoardCore.clipboardText of the selection on the clipboard, inside the copy
 * event, and remembers the items and that text. A paste of exactly that text
 * adds copies of them (EvBoardCore.pastePlan: same spacing) in one store.batch
 * and selects them: right after the selection's latest block when blocks are
 * selected, else at the slot cursor, moved back as far as needed to stay on
 * the board's days (nothing when they cannot). Any other pasted text goes to the
 * onForeignPaste hook, and is left alone when there is none; so does a paste
 * into the quick line (the hook takes only several lines; one line is typing).
 *
 * None of it acts while Done is leaving, a page modal is open, a text field has
 * focus, a pointer gesture is in progress or the board is not the view on
 * screen. The board re-renders its whole DOM on every change, so the cursor is
 * board state, drawn again after every render and every change of selection.
 *
 * Attached through EvBoardEdit.onEnter / onExit.
 *   EvBoardKeys.cursor() -> {day, min} or null   where the slot cursor is
 *   EvBoardKeys.onForeignPaste(fn)   fn(text, event) -> true when it used text
 *                                    that is not a copy of blocks
 */
(function (root, factory) {
    root.EvBoardKeys = factory(root.EvBoardCore, root);
})(this, function (C, win) {
    'use strict';

    var STEP = 15;          // the cursor's height, and an Up or Down move
    var START_MIN = 540;    // 9:00, on the first day, when nothing says otherwise
    var doc = win.document;

    var s = {
        ctx: null,
        rootEl: null,
        pos: null,          // {day, min} where the cursor was put; drawn clamped to the board
        count: 0,           // how many items the store held, to notice an add
        lastEnd: null,      // {day, min} where the last item typed in ended, as last seen
        copied: null,       // {items, text} from the last copy
        offs: []
    };

    function V() { return win.EvBoardView; }
    function Sel() { return win.EvBoardSelect; }
    function leaving() { return !!(s.ctx && s.ctx.isLeaving()); }
    function typing() { return !!(win.EvBoardType && win.EvBoardType.isOpen()); }
    function selection() { return Sel() ? Sel().selection() : []; }

    // Board keys may act: Edit mode is showing the board, nothing else has the
    // keys, and no pointer gesture is in progress.
    function keysFree(target) {
        return !!s.ctx && !s.ctx.isTextField(target) && !s.ctx.modalOpen() && s.ctx.boardShown() && !typing() && !leaving() &&
            !(win.EvBoardDrag && win.EvBoardDrag.isActive());
    }

    // Controls whose own arrow keys must not move the cursor.
    var ARROW_OWNERS = 'input[type="radio"], input[type="range"], select, [role="menu"], [role="menuitem"], ' +
        '[role="listbox"], [role="option"], [role="tab"], [role="slider"]';

    function bodyFor(day) {
        return s.rootEl.querySelector('.evb-day-body[data-day="' + day + '"]');
    }

    // ---- the cursor ----

    // Where the cursor is: its day one of the board's (the nearest), its minute
    // on a 15-minute slot within the axis. Null when the board has no days.
    function current() {
        var b = V().board, days = ((b && b.days) || []).map(function (d) { return d.day; });
        if (!days.length) return null;
        var p = s.pos || { day: days[0], min: START_MIN };
        var day = p.day;
        if (days.indexOf(day) < 0) day = day < days[0] ? days[0] : days[days.length - 1];
        var lo = Math.max(b.axisStart, C.BOARD_DAY_START), hi = Math.min(b.axisEnd, C.BOARD_DAY_END) - STEP;
        var min = Math.max(lo, Math.min(hi, Math.floor(p.min / STEP) * STEP));
        return { day: day, min: min };
    }

    function shown() { return !!s.ctx && !selection().length && !typing(); }

    // Draw the cursor in its day (after every render, which removes it).
    function draw() {
        if (!s.rootEl) return null;
        var cur = shown() ? current() : null, body = cur && bodyFor(cur.day);
        var el = s.rootEl.querySelector('.evb-slot-cursor');
        if (!body) {
            if (el) el.parentNode.removeChild(el);
            return null;
        }
        if (!el) {
            el = doc.createElement('div');
            el.className = 'evb-slot-cursor';
            el.setAttribute('aria-hidden', 'true');
        }
        el.setAttribute('data-day', cur.day);
        el.setAttribute('data-min', String(cur.min));
        el.style.top = ((cur.min - V().board.axisStart) * V().PX_PER_MIN).toFixed(1) + 'px';
        el.style.height = (STEP * V().PX_PER_MIN).toFixed(1) + 'px';
        el.textContent = C.fmtTime(cur.min);
        if (el.parentNode !== body) body.appendChild(el);
        return el;
    }

    // Move by minutes or days, then show the cursor: its day, when the board
    // shows a single day, and the frame's scroll.
    function move(dMin, dDays) {
        var cur = current();
        if (!cur) return;
        var days = V().board.days.map(function (d) { return d.day; });
        var i = Math.max(0, Math.min(days.length - 1, days.indexOf(cur.day) + dDays));
        s.pos = { day: days[i], min: cur.min + dMin };
        var el = draw(), one = V().getDay();
        if (one && one !== days[i]) V().setDay(days[i]);
        if (el) V().reveal(el);
    }

    // An item typed in (the quick line, the board) moves the cursor to where it ends.
    function onStoreChange() {
        var n = s.ctx.store.items().length, grew = n > s.count;
        s.count = n;
        if (!grew) return;
        // The edit context records the add after the store's own change event.
        Promise.resolve().then(function () {
            if (!s.ctx) return;
            var last = s.ctx.entryContext().lastEnd;
            if (last && (!s.lastEnd || last.day !== s.lastEnd.day || last.min !== s.lastEnd.min)) {
                s.lastEnd = last;
                s.pos = { day: last.day, min: last.min };
                draw();
            }
        });
    }

    // A press on empty time puts the cursor there (evb-type.js opens the block).
    function onPointerDown(e) {
        var t = e.target;
        if (!t || !t.classList || !t.classList.contains('evb-day-body') || leaving()) return;
        s.pos = {
            day: t.getAttribute('data-day'),
            min: V().slotAt(e.clientY - t.getBoundingClientRect().top, V().board.axisStart, STEP)
        };
    }

    function onKeyDown(e) {
        if (e.defaultPrevented || e.isComposing || e.keyCode === 229 || !s.ctx || selection().length || !keysFree(e.target)) return;
        // A focused button or link keeps its keys.
        var t = e.target && e.target.closest ? e.target : null;
        if (t && !t.closest('.evb-block') && t.closest('button, a[href]')) return;
        // AltGr (Ctrl+Alt on Windows) types characters; it is not a shortcut.
        var altGr = !!(e.getModifierState && e.getModifierState('AltGraph'));
        // Option with a character on a Mac types it (accented letters). Elsewhere Alt
        // with a letter is the browser's (menus, accesskeys), and Alt with an arrow
        // or another key that is not a character stays a shortcut everywhere.
        var optionChar = !!(win.EvBoardEdit && win.EvBoardEdit.isMac && win.EvBoardEdit.isMac()) &&
            e.altKey && !e.ctrlKey && !e.metaKey && String(e.key).length === 1 && /\S/.test(e.key);
        var mod = (e.ctrlKey || e.metaKey || e.altKey) && !altGr && !optionChar;
        var cur = current();
        if (!cur || !bodyFor(cur.day) || !win.EvBoardType) return;
        var arrow = /^Arrow(Up|Down|Left|Right)$/.exec(e.key);
        if (arrow) {
            if (mod || altGr || e.shiftKey || (t && t.closest(ARROW_OWNERS))) return;
            e.preventDefault();
            if (arrow[1] === 'Up') move(-STEP, 0);
            else if (arrow[1] === 'Down') move(STEP, 0);
            else move(0, arrow[1] === 'Left' ? -1 : 1);
            return;
        }
        if (mod) return;
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            win.EvBoardType.openAt(cur.day, cur.min, '');
            return;
        }
        // A printable character (not a space) starts an item with it.
        if (e.key.length === 1 && /\S/.test(e.key)) {
            e.preventDefault();
            win.EvBoardType.openAt(cur.day, cur.min, e.key);
        }
    }

    // ---- copy and paste ----

    function onCopy(e) {
        var list = selection();
        if (!list.length || !keysFree(doc.activeElement) || !e.clipboardData) return;
        // Text selected on the page is the browser's to copy.
        var ts = win.getSelection && win.getSelection();
        if (ts && !ts.isCollapsed && String(ts).trim()) return;
        var store = s.ctx.store;
        var items = list.map(function (c) { return store.get(c); }).filter(Boolean);
        if (!items.length) return;
        var text = C.clipboardText(items);
        e.clipboardData.setData('text/plain', text);
        e.preventDefault();
        s.copied = { items: JSON.parse(JSON.stringify(items)), text: text };
    }

    // Line endings may come back as CRLF from the system clipboard.
    function lines(text) { return String(text == null ? '' : text).replace(/\r\n?/g, '\n'); }

    // Where pasted blocks go: right after the selection's latest block (on its
    // day) when blocks are selected, else at the cursor.
    function pasteAt() {
        var store = s.ctx.store, best = null;
        selection().forEach(function (cid) {
            var it = store.get(cid), sp = it && C.span(it);
            if (!sp) return;
            var end = C.diffDays('1970-01-01', sp.day) * C.DAY_MIN + sp.end;
            if (!best || end > best.abs) best = { abs: end, day: sp.day, min: sp.end };
        });
        return best ? { day: best.day, min: best.min } : current();
    }

    // The blocks copied here, pasted in one step and selected. They stay on the
    // board's days (EvBoardCore.boardRange): placed only as far from the copied
    // items as all of them fit, and not at all when there is no room.
    function pasteBlocks() {
        var at = pasteAt(), store = s.ctx.store, added = [];
        var range = C.boardRange(V().board.days.map(function (d) { return d.day; }));
        var plan = at ? C.pastePlan(s.copied.items, at.day, at.min, range) : [];
        if (!plan.length) return;
        store.batch(function () {
            plan.forEach(function (it) { added.push(store.add(Sel().copyItem(it, it))); });
        });
        // A copy the store refused has no cid (its notice says why).
        added = added.filter(Boolean);
        if (!added.length) return;
        Sel().select(added);
        Sel().revealItem(added[0]);
    }

    // The one paste listener. Text copied from the board as blocks pastes those
    // blocks; any other text goes to the onForeignPaste hook, if there is one,
    // and is left alone otherwise. A paste into the quick line goes to the hook
    // too (it takes several lines, evb-bulk.js); otherwise it is the line's own.
    function onPaste(e) {
        if (!s.ctx || !e.clipboardData || !Sel()) return;
        var ql = s.ctx.quickLine && s.ctx.quickLine.input;
        if (ql && doc.activeElement === ql) {
            if (!leaving() && !s.ctx.modalOpen() && foreignPaste &&
                foreignPaste(e.clipboardData.getData('text/plain'), e) === true) e.preventDefault();
            return;
        }
        if (!keysFree(doc.activeElement)) return;
        var text = e.clipboardData.getData('text/plain');
        if (s.copied && lines(text) === lines(s.copied.text)) {
            e.preventDefault();
            pasteBlocks();
            return;
        }
        if (foreignPaste && foreignPaste(text, e) === true) e.preventDefault();
    }

    var foreignPaste = null;

    // ---- attach and detach (EvBoardEdit hooks) ----

    function attach(ctx) {
        if (s.ctx) detach();
        if (!ctx.root || !V()) return;
        s.ctx = ctx;
        s.rootEl = ctx.root;
        s.pos = s.lastEnd = s.copied = null;
        s.count = ctx.store.items().length;
        var on = function (target, type, fn, opt) {
            target.addEventListener(type, fn, opt);
            s.offs.push(function () { target.removeEventListener(type, fn, opt); });
        };
        on(s.rootEl, 'pointerdown', onPointerDown);
        on(doc, 'keydown', onKeyDown);
        on(doc, 'copy', onCopy);
        on(doc, 'paste', onPaste);
        // An editing block opening or closing moves focus: show or hide the cursor.
        on(doc, 'focusin', draw);
        on(doc, 'focusout', function () { win.setTimeout(draw, 0); });
        s.offs.push(ctx.store.on('change', onStoreChange));
        draw();
    }

    function detach() {
        if (!s.ctx) return;
        s.offs.forEach(function (off) { off(); });
        s.offs = [];
        if (s.rootEl) s.rootEl.querySelectorAll('.evb-slot-cursor').forEach(function (el) { el.parentNode.removeChild(el); });
        s.ctx = s.rootEl = s.pos = s.lastEnd = s.copied = null;
    }

    if (V() && V().onRender) V().onRender(function () { if (s.ctx) draw(); });
    if (Sel() && Sel().onChange) Sel().onChange(function () { if (s.ctx) draw(); });
    if (win.EvBoardEdit && win.EvBoardEdit.onEnter) {
        win.EvBoardEdit.onEnter(attach);
        win.EvBoardEdit.onExit(detach);
    }

    return {
        cursor: function () { return s.ctx ? current() : null; },
        // fn(text, event) -> true when it used the pasted text (the paste is then
        // default-prevented). One function; a later call replaces it.
        onForeignPaste: function (fn) { foreignPaste = typeof fn === 'function' ? fn : null; }
    };
});
