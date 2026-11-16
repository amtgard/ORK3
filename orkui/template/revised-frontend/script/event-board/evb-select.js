/*
 * EvBoardSelect: acting on the blocks already on the Event Schedule Board in
 * Edit mode.
 *
 * Click a block, or move keyboard focus to it, to select it (a double-click
 * retypes it). With a block selected and no text field or page modal in the
 * way: Delete or Backspace removes it, 1-9 and 0 set its category by the
 * category's key, Enter retypes it in place (EvBoardType.editItem), Esc clears
 * the selection. Those keys are ignored on a button or link other than a
 * block. A small toolbar floats over the selected block: Rename, Category (the
 * ten categories), Details (the full schedule form) and Delete.
 *
 * Selection is board state, kept by cid: the board re-renders its whole DOM on
 * every change, so the selected class, aria-pressed, the toolbar and (when it
 * was on a block, or the render dropped it) keyboard focus are put back after
 * every render. The toolbar lives in a layer beside #evb-root.
 *
 * Attached through EvBoardEdit.onEnter / onExit.
 *   EvBoardSelect.selected() -> cid or null
 */
(function (root, factory) {
    root.EvBoardSelect = factory(root);
})(this, function (win) {
    'use strict';

    var doc = win.document;
    var GAP = 6;            // px between the toolbar and its block
    var GUTTER_PX = 48;     // the hour gutter covers the frame's left edge
    var MEAL_FIELDS = ['Menu', 'Cost', 'Dietary', 'Allergens'];

    var s = {
        ctx: null,      // the edit context while attached
        rootEl: null,
        layer: null,
        bar: null,      // .evb-block-toolbar
        menu: null,     // .evb-cat-menu
        cid: null,      // the selected item
        id: 0,          // its server id, to find it again after a refresh renames cids
        focusEl: null,  // the block that last had keyboard focus, while focus is on a block
        offs: []
    };

    function V() { return win.EvBoardView; }
    function cfg() { return win.EvConfig || {}; }
    function cats() { return cfg().scheduleCategories || []; }
    function esc(v) { return V().esc(v); }
    function leaving() { return !!(s.ctx && s.ctx.isLeaving()); }
    function typing() { return !!(win.EvBoardType && win.EvBoardType.isOpen()); }

    // ---- the toolbar ----

    function build() {
        var layer = doc.createElement('div');
        layer.className = 'evb-select-layer';
        var menu = cats().map(function (c) {
            return '<button type="button" aria-pressed="false" data-category="' + esc(c.name) + '"' +
                ' aria-label="' + esc(c.name + ' (' + c.key + ')') + '" data-tip="' + esc(c.name) + '"' +
                ' style="--evb-fill:' + esc(c.bold) + '">' + esc(c.key) + '</button>';
        }).join('');
        layer.innerHTML = '<div class="evb-block-toolbar" role="toolbar" aria-label="Selected item" hidden>' +
            '<button type="button" class="evb-block-tool" data-evb-act="rename" aria-label="Rename" data-tip="Rename">' +
            '<i class="fas fa-pencil-alt" aria-hidden="true"></i></button>' +
            '<button type="button" class="evb-block-tool" data-evb-act="category" aria-label="Category" data-tip="Category"' +
            ' aria-expanded="false" aria-controls="evb-cat-menu"><span class="evb-tool-swatch" aria-hidden="true"></span></button>' +
            '<button type="button" class="evb-block-tool" data-evb-act="details" aria-label="Details" data-tip="Details">' +
            '<i class="fas fa-sliders-h" aria-hidden="true"></i></button>' +
            '<button type="button" class="evb-block-tool evb-block-tool-danger" data-evb-act="delete" aria-label="Delete" data-tip="Delete">' +
            '<i class="fas fa-trash-alt" aria-hidden="true"></i></button>' +
            '<div class="evb-cat-menu" id="evb-cat-menu" role="group" aria-label="Category" hidden>' + menu + '</div>' +
            '</div>';
        s.rootEl.parentNode.insertBefore(layer, s.rootEl.nextSibling);
        s.layer = layer;
        s.bar = layer.querySelector('.evb-block-toolbar');
        s.menu = layer.querySelector('.evb-cat-menu');
        s.bar.addEventListener('click', onToolbarClick);
    }

    // Every block of the selected item (an item past midnight has two).
    function blocks() {
        if (!s.cid || !s.rootEl) return [];
        return Array.prototype.slice.call(s.rootEl.querySelectorAll('.evb-block[data-cid]'))
            .filter(function (b) { return b.getAttribute('data-cid') === s.cid; });
    }

    // The first of them on screen, or null.
    function shownBlock() {
        var list = blocks();
        for (var i = 0; i < list.length; i++) if (list[i].offsetParent) return list[i];
        return null;
    }

    // Just above the block's top-left corner (over the sticky day head if need
    // be); below it when there is no room above in the board's frame and the
    // window; hidden when the block is out of view.
    function position() {
        if (!s.bar) return;
        var b = shownBlock();
        var scroller = s.rootEl.querySelector('.evb-scroller');
        if (!b || !scroller || typing()) { hideBar(); return; }
        var lr = s.layer.getBoundingClientRect(), r = b.getBoundingClientRect(), sr = scroller.getBoundingClientRect();
        var ft = sr.top + scroller.clientTop, fb = ft + scroller.clientHeight;
        var vl = sr.left + scroller.clientLeft + GUTTER_PX, vr = sr.left + scroller.clientLeft + scroller.clientWidth;
        if (r.bottom <= ft + V().HEAD_PX || r.top >= fb || r.right <= vl || r.left >= vr) { hideBar(); return; }
        s.bar.hidden = false;
        var h = s.bar.offsetHeight, w = s.bar.offsetWidth;
        var vt = Math.max(ft, 0), vb = Math.min(fb, win.innerHeight);
        var top = r.top - GAP - h;
        if (top < vt) top = r.bottom + GAP + h <= vb ? r.bottom + GAP : Math.max(vt, Math.min(top, vb - h));
        var left = Math.max(vl, Math.min(r.left, vr - w));
        s.bar.style.top = (top - lr.top) + 'px';
        s.bar.style.left = (left - lr.left) + 'px';
        placeMenu();
    }

    function hideBar() {
        if (!s.bar) return;
        s.bar.hidden = true;
        setMenu(false);
    }

    // The menu opens below the toolbar, or above it near the bottom of the window.
    function placeMenu() {
        if (!s.menu || s.menu.hidden) return;
        s.menu.classList.remove('evb-cat-menu-up');
        var mr = s.menu.getBoundingClientRect();
        if (mr.bottom > win.innerHeight - 8 && s.bar.getBoundingClientRect().top - mr.height - 4 > 8) {
            s.menu.classList.add('evb-cat-menu-up');
        }
    }

    function catButton() { return s.bar && s.bar.querySelector('[data-evb-act="category"]'); }

    // opts.focus: opening moves focus to the current category's button; closing
    // returns it to the toolbar's Category button (as does closing with focus inside).
    function setMenu(open, opts) {
        if (!s.menu) return;
        var had = s.menu.contains(doc.activeElement);
        s.menu.hidden = !open;
        catButton().setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            placeMenu();
            if (opts && opts.focus) {
                var cur = s.menu.querySelector('[aria-pressed="true"]') || s.menu.querySelector('button');
                if (cur) cur.focus({ preventScroll: true });
            }
        } else if (((opts && opts.focus) || had) && !s.bar.hidden) {
            catButton().focus({ preventScroll: true });
        }
    }

    function menuOpen() { return !!(s.menu && !s.menu.hidden); }

    // ---- selection ----

    // After every render: find the item again (a refresh renames new items'
    // cids), mark its blocks and move the toolbar to it. refocus: 'render' puts
    // focus back on the selected block when the render removed the block that
    // had it; 'force' (a rename closed from the keyboard) puts it there always.
    function apply(refocus) {
        if (!s.ctx || !s.rootEl) return;
        if (s.cid) {
            var store = s.ctx.store, item = store.get(s.cid);
            if (!item && s.id) {
                store.items().some(function (it) {
                    if (+it.EventScheduleId !== s.id) return false;
                    item = it;
                    return true;
                });
            }
            if (item) {
                s.cid = item.cid;
                s.id = +item.EventScheduleId || s.id;
                syncToolbar(item);
            } else {
                s.cid = null;
                s.id = 0;
            }
        }
        s.rootEl.querySelectorAll('.evb-block[data-cid]').forEach(function (b) {
            var on = !!s.cid && b.getAttribute('data-cid') === s.cid;
            b.classList.toggle('evb-selected', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (s.cid) position(); else hideBar();
        if (refocus && s.cid && !typing()) {
            var a = doc.activeElement, b = shownBlock();
            var dropped = s.focusEl && !doc.contains(s.focusEl) && (!a || a === doc.body);
            if (b && a !== b && (refocus === 'force' || dropped)) b.focus({ preventScroll: true });
        }
    }

    function syncToolbar(item) {
        var cat = item.Category || 'Other';
        s.bar.querySelector('.evb-tool-swatch').style.background = V().boldFor(cat);
        s.bar.querySelector('[data-evb-act="category"]').setAttribute('data-tip', 'Category: ' + cat);
        s.menu.querySelectorAll('[data-category]').forEach(function (m) {
            m.setAttribute('aria-pressed', m.getAttribute('data-category') === cat ? 'true' : 'false');
        });
    }

    function select(cid) {
        var item = s.ctx.store.get(cid);
        if (!item) return;
        if (s.cid !== cid) setMenu(false);
        s.cid = cid;
        s.id = +item.EventScheduleId || 0;
        apply();
    }

    function clear() {
        if (!s.cid) return;
        s.cid = null;
        s.id = 0;
        apply();
    }

    // ---- actions ----

    function setCategory(name) {
        var item = s.cid && s.ctx.store.get(s.cid);
        if (!item || leaving()) return;
        setMenu(false);
        if ((item.Category || 'Other') === name) return;
        var f = { Category: name };
        // A stripe the same as the new category would never show.
        if (item.SecondaryCategory === name) f.SecondaryCategory = '';
        s.ctx.store.update(s.cid, f);
    }

    function hasMeal(item) {
        return MEAL_FIELDS.some(function (k) { return item[k] != null && String(item[k]).trim() !== ''; });
    }

    // Removing an item's meal details that this person could not put back asks
    // first and cannot be undone; every other removal is immediate and undoable.
    function removeSelected() {
        var cid = s.cid, item = cid && s.ctx.store.get(cid);
        if (!item || leaving()) return;
        if (hasMeal(item) && !cfg().canManageFeast) {
            win.orkConfirm('This item has menu and dietary details that you will not be able to restore. Remove it?', function () {
                if (!s.ctx || leaving() || !s.ctx.store.get(cid)) return;
                if (s.cid === cid) clear();
                s.ctx.store.remove(cid, { undoable: false });
            }, { title: 'Remove item', okLabel: 'Remove', danger: true });
            return;
        }
        clear();
        s.ctx.store.remove(cid);
    }

    function rename() {
        if (!s.cid || leaving() || !win.EvBoardType || !win.EvBoardType.editItem) return;
        setMenu(false);
        // The block under the editor replaces the item while it is retyped; when it
        // closes, the keys go back to the selected block.
        var onDone = function (how) { apply(how && how.keyboard ? 'force' : false); };
        if (win.EvBoardType.editItem(s.cid, { onDone: onDone })) hideBar();
    }

    var STILL_SAVING = 'Still saving. Try Details again in a moment.';

    // The full form, once the item has a server id. An add still saving is waited
    // for, and the form opens only if that item is still the selection; a store
    // that cannot save says so instead.
    function details() {
        var cid = s.cid, ctx = s.ctx, store = ctx.store;
        if (!cid || leaving() || typeof win.evOpenScheduleModalForItem !== 'function') return;
        setMenu(false);
        var item = store.get(cid);
        if (item && +item.EventScheduleId) { win.evOpenScheduleModalForItem(item); return; }
        if (store.status().state === 'offline') { ctx.addNotice(STILL_SAVING); return; }
        store.idle().then(function () {
            if (s.ctx !== ctx || s.cid !== cid || leaving()) return;
            var now = store.get(cid);
            if (now && +now.EventScheduleId) win.evOpenScheduleModalForItem(now);
            else if (now) ctx.addNotice(STILL_SAVING);
        });
    }

    function onToolbarClick(e) {
        var t = e.target.closest ? e.target : null;
        if (!t) return;
        var cat = t.closest('.evb-cat-menu [data-category]');
        if (cat) { setCategory(cat.getAttribute('data-category')); return; }
        var act = t.closest('[data-evb-act]');
        if (!act) return;
        var name = act.getAttribute('data-evb-act');
        if (name === 'rename') rename();
        else if (name === 'category') setMenu(!menuOpen(), { focus: true });
        else if (name === 'details') details();
        else if (name === 'delete') removeSelected();
    }

    // ---- pointer and keys ----

    function onRootClick(e) {
        var b = e.target.closest && e.target.closest('.evb-block[data-cid]');
        if (b) select(b.getAttribute('data-cid'));
    }

    // Keyboard focus arriving on a block selects it. The focused block is
    // remembered so a render that replaces it can hand focus to its successor.
    function onFocusIn(e) {
        var b = e.target.closest && e.target.closest('.evb-block[data-cid]');
        if (!b) return;
        s.focusEl = b;
        if (b.getAttribute('data-cid') !== s.cid && !leaving()) select(b.getAttribute('data-cid'));
    }

    // Focus moving on to something that is not a block (a removed element moves
    // it nowhere, and is left to the render's refocus).
    function onFocusOut(e) {
        var to = e.relatedTarget;
        if (to && !(to.closest && to.closest('.evb-block[data-cid]'))) s.focusEl = null;
    }

    function onDblClick(e) {
        var b = e.target.closest && e.target.closest('.evb-block[data-cid]');
        if (!b) return;
        select(b.getAttribute('data-cid'));
        rename();
    }

    // A click anywhere but a block, the toolbar or a page dialog clears the selection.
    // A target its own handler replaced (a Save button showing a spinner) is no longer
    // in the page, so where it was is unknown: it changes nothing.
    function onDocClick(e) {
        if (!s.cid || !e.target.closest || !doc.contains(e.target) || s.ctx.modalOpen()) return;
        if (e.target.closest('.evb-block[data-cid], .evb-block-toolbar, .evb-type-layer, #ork-confirm-overlay, #ev-schedule-modal')) return;
        clear();
    }

    function onKeyDown(e) {
        if (e.defaultPrevented || e.isComposing || e.metaKey || e.ctrlKey || e.altKey) return;
        var t = e.target && e.target.closest ? e.target : null;
        var onBlock = t && s.rootEl.contains(t) ? t.closest('.evb-block[data-cid]') : null;
        if (!s.cid && !onBlock) return;
        if (s.ctx.isTextField(e.target) || s.ctx.modalOpen() || !s.ctx.boardShown() || typing() || leaving()) return;
        if (e.key === 'Escape') {
            if (!s.cid) return;
            e.preventDefault();
            if (menuOpen()) setMenu(false, { focus: true });
            else clear();
            return;
        }
        // A focused button or link (the mode bar's Undo, a toolbar tool) keeps
        // its keys: Enter presses it, and nothing else acts on the selection.
        if (!onBlock && t && t.closest('button, a[href]')) return;
        if (!/^(Delete|Backspace|Enter|[0-9])$/.test(e.key)) return;
        // The keys act on the focused block (focus selects it; Esc may have cleared it).
        if (onBlock && onBlock.getAttribute('data-cid') !== s.cid) select(onBlock.getAttribute('data-cid'));
        if (!s.cid) return;
        // The rest act only on a block on screen.
        if (!shownBlock()) return;
        if (e.key === 'Delete' || e.key === 'Backspace') {
            e.preventDefault();
            removeSelected();
            return;
        }
        if (/^[0-9]$/.test(e.key)) {
            var def = cats().filter(function (c) { return String(c.key) === e.key; })[0];
            if (!def) return;
            e.preventDefault();
            setCategory(def.name);
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            rename();
        }
    }

    function onScroll() { if (s.cid) position(); }

    // ---- attach and detach (EvBoardEdit hooks) ----

    function attach(ctx) {
        if (s.ctx) detach();
        if (!ctx.root || !V()) return;
        s.ctx = ctx;
        s.rootEl = ctx.root;
        s.cid = null;
        s.id = 0;
        build();
        var on = function (target, type, fn, opt) {
            target.addEventListener(type, fn, opt);
            s.offs.push(function () { target.removeEventListener(type, fn, opt); });
        };
        on(s.rootEl, 'click', onRootClick);
        on(s.rootEl, 'focusin', onFocusIn);
        on(s.rootEl, 'focusout', onFocusOut);
        on(s.rootEl, 'dblclick', onDblClick);
        on(doc, 'click', onDocClick);
        on(doc, 'keydown', onKeyDown);
        on(doc, 'scroll', onScroll, true);
        on(win, 'resize', onScroll);
        // Toolbar presses keep focus where it was.
        on(s.bar, 'mousedown', function (e) { e.preventDefault(); });
    }

    function detach() {
        if (!s.ctx) return;
        s.offs.forEach(function (off) { off(); });
        s.offs = [];
        if (s.rootEl) {
            s.rootEl.querySelectorAll('.evb-selected').forEach(function (b) { b.classList.remove('evb-selected'); });
            s.rootEl.querySelectorAll('.evb-block[aria-pressed]').forEach(function (b) { b.removeAttribute('aria-pressed'); });
        }
        if (s.layer && s.layer.parentNode) s.layer.parentNode.removeChild(s.layer);
        s.ctx = s.layer = s.bar = s.menu = s.rootEl = s.focusEl = null;
        s.cid = null;
        s.id = 0;
    }

    if (V() && V().onRender) V().onRender(function () { if (s.ctx) apply('render'); });
    if (win.EvBoardEdit && win.EvBoardEdit.onEnter) {
        win.EvBoardEdit.onEnter(attach);
        win.EvBoardEdit.onExit(detach);
    }

    return {
        selected: function () { return s.cid; }
    };
});
