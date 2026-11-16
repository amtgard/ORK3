/*
 * EvBoardSelect: acting on the blocks already on the Event Schedule Board in
 * Edit mode.
 *
 * Click a block, or move keyboard focus to it, to select it alone (a
 * double-click retypes it); Shift-click adds a block to the selection or takes
 * it out; Cmd/Ctrl+A selects every block. With blocks selected and no text
 * field or page modal in the way: Delete or Backspace removes them, 1-9 and 0
 * set their category by the category's key, Cmd/Ctrl+D duplicates them, Enter
 * retypes a single one in place (EvBoardType.editItem), Esc clears the
 * selection. Those keys are ignored on a button or link other than a block.
 * A small toolbar floats over a single selected block: Rename, Duplicate,
 * Category (the ten categories), Details (the full schedule form) and Delete;
 * with more than one selected, a badge ("3 selected") with one Delete button
 * takes its place.
 *
 * The arrow keys nudge the selection: Up and Down move it 15 minutes, Shift
 * with them moves only its end, Left and Right move it a day; Alt makes the
 * step 5 minutes. Nudged times are not snapped. Every group action is one
 * store.batch (one save, one undo step). A single selected block carries resize
 * grips (.evb-resize-top on its first segment, .evb-resize-bottom on its last)
 * and the fill handle (.evb-fill-handle, bottom right of its last), which
 * evb-drag.js handles.
 *
 * Selection is board state, kept by cid: the board re-renders its whole DOM on
 * every change, so the selected class, aria-pressed, the toolbar and (when it
 * was on a block, or the render dropped it) keyboard focus are put back after
 * every render. The toolbar lives in a layer beside #evb-root.
 *
 * Attached through EvBoardEdit.onEnter / onExit.
 *   EvBoardSelect.selected() -> cid or null    the selected item when exactly one is
 *   EvBoardSelect.selection() -> [cid]         the selection
 *   EvBoardSelect.select([cid])                select these items ([] clears)
 *   EvBoardSelect.onChange(fn)                 fn() after the selection is drawn again
 *   EvBoardSelect.copyItem(item, times) -> a new item: every field of item at times,
 *                                              without ids (meal fields only for feast managers)
 *   EvBoardSelect.revealItem(cid)              bring an item's block into view
 */
(function (root, factory) {
    root.EvBoardSelect = factory(root);
})(this, function (win) {
    'use strict';

    var doc = win.document;
    var GAP = 6;            // px between the toolbar and its block
    var MEAL_FIELDS = ['Menu', 'Cost', 'Dietary', 'Allergens'];

    var s = {
        ctx: null,      // the edit context while attached
        rootEl: null,
        layer: null,
        bar: null,      // .evb-block-toolbar (one block selected)
        badge: null,    // .evb-sel-badge (several selected)
        menu: null,     // .evb-cat-menu
        sel: [],        // the selected items: [{cid, id}] (id: the server id, to find it again after a refresh renames cids)
        anchor: null,   // the cid last clicked or added, where the badge sits
        focusEl: null,  // the block that last had keyboard focus, while focus is on a block
        listeners: [],
        offs: []
    };

    function V() { return win.EvBoardView; }
    function C() { return win.EvBoardCore; }
    function cfg() { return win.EvConfig || {}; }
    function cats() { return cfg().scheduleCategories || []; }
    function esc(v) { return V().esc(v); }
    function leaving() { return !!(s.ctx && s.ctx.isLeaving()); }
    function typing() { return !!(win.EvBoardType && win.EvBoardType.isOpen()); }

    function cids() { return s.sel.map(function (e) { return e.cid; }); }
    function isSel(cid) { return cids().indexOf(cid) >= 0; }
    // The selected cid when exactly one item is selected.
    function one() { return s.sel.length === 1 ? s.sel[0].cid : null; }
    function anchorCid() { return isSel(s.anchor) ? s.anchor : (s.sel.length ? s.sel[s.sel.length - 1].cid : null); }

    // ---- the toolbar and the badge ----

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
            '<button type="button" class="evb-block-tool" data-evb-act="duplicate" aria-label="Duplicate" data-tip="Duplicate">' +
            '<i class="far fa-clone" aria-hidden="true"></i></button>' +
            '<button type="button" class="evb-block-tool" data-evb-act="category" aria-label="Category" data-tip="Category"' +
            ' aria-expanded="false" aria-controls="evb-cat-menu"><span class="evb-tool-swatch" aria-hidden="true"></span></button>' +
            '<button type="button" class="evb-block-tool" data-evb-act="details" aria-label="Details" data-tip="Details">' +
            '<i class="fas fa-sliders-h" aria-hidden="true"></i></button>' +
            '<button type="button" class="evb-block-tool evb-block-tool-danger" data-evb-act="delete" aria-label="Delete" data-tip="Delete">' +
            '<i class="fas fa-trash-alt" aria-hidden="true"></i></button>' +
            '<div class="evb-cat-menu" id="evb-cat-menu" role="group" aria-label="Category" hidden>' + menu + '</div>' +
            '</div>' +
            '<div class="evb-sel-badge" role="toolbar" aria-label="Selected items" hidden>' +
            '<span class="evb-sel-count" role="status" aria-live="polite"></span>' +
            '<button type="button" class="evb-block-tool evb-block-tool-danger" data-evb-act="delete" aria-label="Delete the selected items" data-tip="Delete">' +
            '<i class="fas fa-trash-alt" aria-hidden="true"></i></button>' +
            '</div>';
        s.rootEl.parentNode.insertBefore(layer, s.rootEl.nextSibling);
        s.layer = layer;
        s.bar = layer.querySelector('.evb-block-toolbar');
        s.badge = layer.querySelector('.evb-sel-badge');
        s.menu = layer.querySelector('.evb-cat-menu');
        s.bar.addEventListener('click', onToolbarClick);
        s.badge.addEventListener('click', onToolbarClick);
    }

    // Every block of an item (an item past midnight has two).
    function blocksOf(cid) {
        if (!cid || !s.rootEl) return [];
        return Array.prototype.slice.call(s.rootEl.querySelectorAll('.evb-block[data-cid]'))
            .filter(function (b) { return b.getAttribute('data-cid') === cid; });
    }

    // The first of them on screen, or null.
    function shownBlockOf(cid) {
        var list = blocksOf(cid);
        for (var i = 0; i < list.length; i++) if (list[i].offsetParent) return list[i];
        return null;
    }

    // Where the toolbar or badge sits: the single selected block; for several,
    // the earliest selected block (by day, then start) inside the board's frame,
    // else the earliest one in a shown day.
    function shownBlock() {
        if (s.sel.length < 2) return shownBlockOf(anchorCid());
        var list = cids(), scroller = s.rootEl.querySelector('.evb-scroller');
        var sr = scroller && scroller.getBoundingClientRect();
        var shown = Array.prototype.slice.call(s.rootEl.querySelectorAll('.evb-block[data-cid]')).filter(function (b) {
            return list.indexOf(b.getAttribute('data-cid')) >= 0 && b.offsetParent;
        }).map(function (b) {
            return { b: b, day: b.getAttribute('data-day') || '', top: parseFloat(b.style.top) || 0, r: b.getBoundingClientRect() };
        }).sort(function (x, y) { return x.day < y.day ? -1 : x.day > y.day ? 1 : x.top - y.top; });
        var inView = sr && shown.filter(function (o) {
            return o.r.bottom > sr.top + V().HEAD_PX && o.r.top < sr.bottom && o.r.right > sr.left + V().GUTTER_PX && o.r.left < sr.right;
        });
        return ((inView && inView[0]) || shown[0] || {}).b || null;
    }

    function anyShown() {
        return s.sel.some(function (e) { return !!shownBlockOf(e.cid); });
    }

    // Just above the block's top-left corner (over the sticky day head if need
    // be); below it when there is no room above in the board's frame and the
    // window; hidden when the block is out of view.
    function position(el) {
        if (!el) return;
        var b = shownBlock();
        var scroller = s.rootEl.querySelector('.evb-scroller');
        if (!b || !scroller || typing()) { hideEl(el); return; }
        var lr = s.layer.getBoundingClientRect(), r = b.getBoundingClientRect(), sr = scroller.getBoundingClientRect();
        var ft = sr.top + scroller.clientTop, fb = ft + scroller.clientHeight;
        var vl = sr.left + scroller.clientLeft + V().GUTTER_PX, vr = sr.left + scroller.clientLeft + scroller.clientWidth;
        if (r.bottom <= ft + V().HEAD_PX || r.top >= fb || r.right <= vl || r.left >= vr) { hideEl(el); return; }
        el.hidden = false;
        var h = el.offsetHeight, w = el.offsetWidth;
        var vt = Math.max(ft, 0), vb = Math.min(fb, win.innerHeight);
        var top = r.top - GAP - h;
        if (top < vt) top = r.bottom + GAP + h <= vb ? r.bottom + GAP : Math.max(vt, Math.min(top, vb - h));
        var left = Math.max(vl, Math.min(r.left, vr - w));
        el.style.top = (top - lr.top) + 'px';
        el.style.left = (left - lr.left) + 'px';
        if (el === s.bar) placeMenu();
    }

    function hideEl(el) {
        if (!el) return;
        el.hidden = true;
        if (el === s.bar) setMenu(false);
    }

    function hideBars() {
        hideEl(s.bar);
        hideEl(s.badge);
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

    // After every render and every change of selection: find each item again (a
    // refresh renames new items' cids), mark their blocks and show the toolbar or
    // the badge. refocus: 'render' puts focus back on a selected block when the
    // render removed the block that had it; 'force' (a rename closed from the
    // keyboard) puts it there always.
    function apply(refocus) {
        if (!s.ctx || !s.rootEl) return;
        if (s.sel.length) {
            var store = s.ctx.store, all = null;
            s.sel = s.sel.map(function (e) {
                var item = store.get(e.cid);
                if (!item && e.id) {
                    all = all || store.items();
                    all.some(function (it) {
                        if (+it.EventScheduleId !== e.id) return false;
                        item = it;
                        return true;
                    });
                }
                if (!item) return null;
                if (s.anchor === e.cid) s.anchor = item.cid;
                return { cid: item.cid, id: +item.EventScheduleId || e.id };
            }).filter(Boolean);
        }
        var single = one(), list = cids();
        s.rootEl.querySelectorAll('.evb-block[data-cid]').forEach(function (b) {
            var cid = b.getAttribute('data-cid'), on = list.indexOf(cid) >= 0;
            b.classList.toggle('evb-selected', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            grips(b, !!single && cid === single);
        });
        if (single) {
            syncToolbar(s.ctx.store.get(single));
            hideEl(s.badge);
            position(s.bar);
        } else if (list.length) {
            hideEl(s.bar);
            s.badge.querySelector('.evb-sel-count').textContent = list.length + ' selected';
            position(s.badge);
        } else {
            hideBars();
        }
        if (refocus && list.length && !typing()) {
            var a = doc.activeElement, b = shownBlock();
            var dropped = s.focusEl && !doc.contains(s.focusEl) && (!a || a === doc.body);
            if (b && a !== b && (refocus === 'force' || dropped)) b.focus({ preventScroll: true });
        }
        s.listeners.forEach(function (fn) {
            try { fn(); } catch (e) { if (win.console) win.console.error(e); }
        });
    }

    // The resize grips and the fill handle: the grips on the single selected
    // item's first segment (top) and last (bottom), the fill handle on its last;
    // out of the tab order and hidden from assistive tech (the keys resize it,
    // Cmd/Ctrl+D copies it). Every render rebuilds the blocks, so apply() puts them back.
    function grips(b, on) {
        var first = !b.classList.contains('evb-block-continued'), last = !b.classList.contains('evb-block-continues');
        [['evb-resize-top', first], ['evb-resize-bottom', last], ['evb-fill-handle', last]].forEach(function (g) {
            var el = b.querySelector(':scope > .' + g[0]);
            if (on && g[1] && !el) {
                el = doc.createElement('div');
                el.className = g[0];
                el.setAttribute('aria-hidden', 'true');
                if (g[0] === 'evb-fill-handle') el.setAttribute('data-tip', 'Drag to repeat');
                b.appendChild(el);
            } else if (!(on && g[1]) && el) {
                b.removeChild(el);
            }
        });
    }

    function syncToolbar(item) {
        if (!item) return;
        var cat = item.Category || 'Other';
        s.bar.querySelector('.evb-tool-swatch').style.background = V().boldFor(cat);
        s.bar.querySelector('[data-evb-act="category"]').setAttribute('data-tip', 'Category: ' + cat);
        s.menu.querySelectorAll('[data-category]').forEach(function (m) {
            m.setAttribute('aria-pressed', m.getAttribute('data-category') === cat ? 'true' : 'false');
        });
    }

    // Select exactly these items (those still in the store); anchor: where the badge sits.
    function setSel(list, anchor) {
        if (!s.ctx) return;
        var store = s.ctx.store, seen = {}, next = [];
        (list || []).forEach(function (cid) {
            var item = cid && !seen[cid] && store.get(cid);
            if (!item) return;
            seen[cid] = true;
            next.push({ cid: cid, id: +item.EventScheduleId || 0 });
        });
        if (next.length !== 1 || one() !== next[0].cid) setMenu(false);
        s.sel = next;
        s.anchor = anchor && seen[anchor] ? anchor : (next.length ? next[next.length - 1].cid : null);
        apply();
    }

    function select(cid) { setSel([cid], cid); }

    // Shift-click: add the item to the selection, or take it out.
    function toggle(cid) {
        var list = cids();
        if (list.indexOf(cid) >= 0) setSel(list.filter(function (c) { return c !== cid; }));
        else setSel(list.concat([cid]), cid);
    }

    function clear() {
        if (!s.sel.length) return;
        setSel([]);
    }

    function selection() { return cids(); }

    // Bring an item's block into view: its day, when the board shows a single
    // day, and the frame's scroll.
    function revealItem(cid) {
        var item = s.ctx && s.ctx.store.get(cid);
        if (!item) return;
        var day = C().span(item).day, shown = V().getDay();
        if (shown && day && day !== shown) V().setDay(day);
        var b = shownBlockOf(item.cid);
        if (b) V().reveal(b);
        if (s.sel.length) position(one() ? s.bar : s.badge);
    }

    // ---- nudging with the arrow keys ----

    // Up/Down move the selection by step minutes, Shift+Up/Down move only its
    // end (never under 5 minutes long), Left/Right move it a day. One undo step.
    function nudge(key, shift, step) {
        var store = s.ctx.store, Co = C();
        var dir = (key === 'ArrowUp' || key === 'ArrowLeft') ? -1 : 1;
        var list = cids().filter(function (c) { return !!store.get(c); });
        if (!list.length || leaving()) return;
        var sideways = key === 'ArrowLeft' || key === 'ArrowRight';
        var dMin = sideways ? 0 : dir * step, dDays = sideways ? dir : 0;
        // A group moves together, only as far as all of it stays on the axis and
        // the board's days (a single block may still step onto a new day).
        if (list.length > 1 && !shift) {
            var b = V().board, days = b.days.map(function (d) { return d.day; });
            var lim = Co.groupShiftLimit(list.map(function (c) { return store.get(c); }), dMin, dDays, {
                minStart: Math.max(b.axisStart, Co.BOARD_DAY_START), maxEnd: Math.min(b.axisEnd, Co.BOARD_DAY_END),
                firstDay: days[0], lastDay: days[days.length - 1]
            });
            dMin = lim.dMin;
            dDays = lim.dDays;
            if (!dMin && !dDays) return;
        }
        store.batch(function () {
            list.forEach(function (cid) {
                var item = store.get(cid), t;
                if (shift && !sideways) t = Co.resizeTimes(item, 'end', Co.span(item).end + dir * step);
                else t = Co.shiftTimes(item, dMin, dDays);
                store.update(cid, { StartTime: t.StartTime, EndTime: t.EndTime });
            });
        });
        // The axis grows to take the items in; keep the anchor on screen.
        revealItem(anchorCid());
    }

    // ---- actions ----

    // Every selected item to the category; auto-filled leads follow it, hand-picked ones stay.
    function setCategory(name) {
        var store = s.ctx.store;
        var items = cids().map(function (c) { return store.get(c); }).filter(Boolean);
        if (!items.length || leaving()) return;
        setMenu(false);
        store.batch(function () {
            items.forEach(function (item) {
                if ((item.Category || 'Other') === name) return;
                var f = { Category: name };
                // A stripe the same as the new category would never show.
                if (item.SecondaryCategory === name) f.SecondaryCategory = '';
                var leads = C().swapAutoLeads(item.Leads || [], cfg().staffList || [], name);
                if (JSON.stringify(leads) !== JSON.stringify(item.Leads || [])) f.Leads = leads;
                store.update(item.cid, f);
            });
        });
    }

    function hasMeal(item) {
        return MEAL_FIELDS.some(function (k) { return item[k] != null && String(item[k]).trim() !== ''; });
    }

    // Removing meal details that this person could not put back asks first (once,
    // for the whole selection) and cannot be undone for those items; every other
    // item is removed at once, undoably, in the same step.
    function removeSelected() {
        var store = s.ctx.store;
        var list = cids().filter(function (c) { return !!store.get(c); });
        if (!list.length || leaving()) return;
        var locked = cfg().canManageFeast ? [] : list.filter(function (c) { return hasMeal(store.get(c)); });
        var go = function () {
            clear();
            store.batch(function () {
                list.forEach(function (cid) {
                    if (!store.get(cid)) return;
                    if (locked.indexOf(cid) >= 0) store.remove(cid, { undoable: false });
                    else store.remove(cid);
                });
            });
        };
        if (!locked.length) { go(); return; }
        var msg = list.length === 1
            ? 'This item has menu and dietary details that you will not be able to restore. Remove it?'
            : (locked.length === 1 ? 'One of these items has' : locked.length + ' of these items have') +
                ' menu and dietary details that you will not be able to restore. Remove all ' + list.length + '?';
        var ctx = s.ctx;
        win.orkConfirm(msg, function () {
            if (s.ctx !== ctx || leaving()) return;
            list = list.filter(function (c) { return !!store.get(c); });
            if (list.length) go();
        }, { title: list.length === 1 ? 'Remove item' : 'Remove items', okLabel: 'Remove', danger: true });
    }

    // A new item with every field of item (leads with their Auto flags; meal
    // fields only for someone who can manage feast) at times, without ids.
    function copyItem(item, times) {
        var c = JSON.parse(JSON.stringify(item));
        delete c.cid;
        delete c.EventScheduleId;
        if (!cfg().canManageFeast) MEAL_FIELDS.forEach(function (k) { delete c[k]; });
        c.StartTime = times.StartTime;
        // A copy of an item with no length has none either.
        var sp = C().span(item);
        c.EndTime = sp && sp.end === sp.start ? times.StartTime : times.EndTime;
        return c;
    }

    // An item with no length counts as 5 minutes long when copies of it are
    // spaced along the day (copyItem keeps the copies at no length).
    function atLeastFive(item) {
        var sp = C().span(item);
        if (!sp || sp.end > sp.start) return item;
        return Object.assign({}, item, { EndTime: C().toNaive(sp.day, sp.start + 5) });
    }

    // A copy of every selected item starting where it ends; the copies become the selection.
    function duplicate() {
        var store = s.ctx.store;
        var items = cids().map(function (c) { return store.get(c); }).filter(Boolean);
        if (!items.length || leaving()) return;
        setMenu(false);
        var added = [];
        store.batch(function () {
            items.forEach(function (item) {
                added.push(store.add(copyItem(item, C().duplicateTimes(atLeastFive(item)))));
            });
        });
        setSel(added, added[0]);
        revealItem(added[0]);
    }

    function rename() {
        var cid = one();
        if (!cid || leaving() || !win.EvBoardType || !win.EvBoardType.editItem) return;
        setMenu(false);
        // The block under the editor replaces the item while it is retyped; when it
        // closes, the keys go back to the selected block.
        var onDone = function (how) { apply(how && how.keyboard ? 'force' : false); };
        if (win.EvBoardType.editItem(cid, { onDone: onDone })) hideBars();
    }

    var STILL_SAVING = 'Still saving. Try Details again in a moment.';

    // The full form, once the item has a server id. An add still saving is waited
    // for, and the form opens only if that item is still the selection; a store
    // that cannot save says so instead.
    function details() {
        var cid = one(), ctx = s.ctx, store = ctx.store;
        if (!cid || leaving() || typeof win.evOpenScheduleModalForItem !== 'function') return;
        setMenu(false);
        var item = store.get(cid);
        if (item && +item.EventScheduleId) { win.evOpenScheduleModalForItem(item); return; }
        if (store.status().state === 'offline') { ctx.addNotice(STILL_SAVING); return; }
        store.idle().then(function () {
            if (s.ctx !== ctx || one() !== cid || leaving()) return;
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
        else if (name === 'duplicate') duplicate();
        else if (name === 'category') setMenu(!menuOpen(), { focus: true });
        else if (name === 'details') details();
        else if (name === 'delete') removeSelected();
    }

    // ---- pointer and keys ----

    function blockAt(t) { return t && t.closest ? t.closest('.evb-block[data-cid]') : null; }

    function onRootClick(e) {
        var b = blockAt(e.target);
        if (!b) return;
        if (e.shiftKey) toggle(b.getAttribute('data-cid'));
        else select(b.getAttribute('data-cid'));
    }

    // A Shift-press on a block neither moves focus (which would select the block
    // alone) nor selects text: the click toggles it.
    function onMouseDown(e) {
        if (e.shiftKey && blockAt(e.target)) e.preventDefault();
    }

    // Keyboard focus arriving on a block outside the selection selects it alone.
    // The focused block is remembered so a render that replaces it can hand focus
    // to its successor.
    function onFocusIn(e) {
        var b = blockAt(e.target);
        if (!b) return;
        s.focusEl = b;
        if (!isSel(b.getAttribute('data-cid')) && !leaving()) select(b.getAttribute('data-cid'));
    }

    // Focus moving on to something that is not a block (a removed element moves
    // it nowhere, and is left to the render's refocus).
    function onFocusOut(e) {
        var to = e.relatedTarget;
        if (to && !blockAt(to)) s.focusEl = null;
    }

    function onDblClick(e) {
        var b = blockAt(e.target);
        if (!b || e.shiftKey) return;
        select(b.getAttribute('data-cid'));
        rename();
    }

    // A click anywhere but a block, the toolbar or a page dialog clears the selection.
    // A target its own handler replaced (a Save button showing a spinner) is no longer
    // in the page, so where it was is unknown: it changes nothing.
    function onDocClick(e) {
        if (!s.sel.length || !e.target.closest || !doc.contains(e.target) || s.ctx.modalOpen()) return;
        if (e.target.closest('.evb-block[data-cid], .evb-block-toolbar, .evb-sel-badge, .evb-type-layer, #ork-confirm-overlay, #ev-schedule-modal')) return;
        clear();
    }

    // Board keys may act: Edit mode is showing the board, nothing else has the
    // keys, and no pointer gesture is in progress.
    function keysFree(e) {
        return !s.ctx.isTextField(e.target) && !s.ctx.modalOpen() && s.ctx.boardShown() && !typing() && !leaving() &&
            !(win.EvBoardDrag && win.EvBoardDrag.isActive());
    }

    // Cmd/Ctrl+A selects every block; Cmd/Ctrl+D duplicates the selection.
    function onModKey(e) {
        if (e.altKey || e.shiftKey) return;
        var k = String(e.key).toLowerCase();
        if ((k !== 'a' && k !== 'd') || !keysFree(e)) return;
        if (k === 'a') {
            var all = s.ctx.store.items().map(function (it) { return it.cid; });
            if (!all.length) return;
            e.preventDefault();
            setMenu(false);
            setSel(all, isSel(s.anchor) ? s.anchor : all[0]);
            return;
        }
        if (!s.sel.length || !anyShown()) return;
        e.preventDefault();
        duplicate();
    }

    function onKeyDown(e) {
        if (e.defaultPrevented || e.isComposing) return;
        if (e.metaKey || e.ctrlKey) { onModKey(e); return; }
        // Alt only changes the arrow keys' step; Shift only the vertical arrows'.
        var arrow = /^Arrow(Up|Down|Left|Right)$/.test(e.key);
        if (e.altKey && !arrow) return;
        if (arrow && e.shiftKey && /Left|Right/.test(e.key)) return;
        // With nothing selected the keys belong to the slot cursor (evb-keys.js).
        if (!s.sel.length || !keysFree(e)) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            if (menuOpen()) setMenu(false, { focus: true });
            else clear();
            return;
        }
        // A focused button or link (the mode bar's Undo, a toolbar tool) keeps
        // its keys: Enter presses it, and nothing else acts on the selection.
        var t = e.target && e.target.closest ? e.target : null;
        var onBlock = t && s.rootEl.contains(t) ? blockAt(t) : null;
        if (!onBlock && t && t.closest('button, a[href]')) return;
        if (!arrow && !/^(Delete|Backspace|Enter|[0-9])$/.test(e.key)) return;
        // The rest act only on a selection with a block on screen.
        if (!anyShown()) return;
        if (arrow) {
            e.preventDefault();
            nudge(e.key, e.shiftKey, e.altKey ? 5 : 15);
            return;
        }
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
        if (e.key === 'Enter' && one()) {
            e.preventDefault();
            rename();
        }
    }

    function onScroll() {
        if (one()) position(s.bar);
        else if (s.sel.length) position(s.badge);
    }

    // ---- attach and detach (EvBoardEdit hooks) ----

    function attach(ctx) {
        if (s.ctx) detach();
        if (!ctx.root || !V()) return;
        s.ctx = ctx;
        s.rootEl = ctx.root;
        s.sel = [];
        s.anchor = null;
        build();
        var on = function (target, type, fn, opt) {
            target.addEventListener(type, fn, opt);
            s.offs.push(function () { target.removeEventListener(type, fn, opt); });
        };
        on(s.rootEl, 'click', onRootClick);
        on(s.rootEl, 'mousedown', onMouseDown);
        on(s.rootEl, 'focusin', onFocusIn);
        on(s.rootEl, 'focusout', onFocusOut);
        on(s.rootEl, 'dblclick', onDblClick);
        on(doc, 'click', onDocClick);
        on(doc, 'keydown', onKeyDown);
        on(doc, 'scroll', onScroll, true);
        on(win, 'resize', onScroll);
        // Toolbar presses keep focus where it was.
        on(s.layer, 'mousedown', function (e) { e.preventDefault(); });
    }

    function detach() {
        if (!s.ctx) return;
        s.offs.forEach(function (off) { off(); });
        s.offs = [];
        if (s.rootEl) {
            s.rootEl.querySelectorAll('.evb-selected').forEach(function (b) { b.classList.remove('evb-selected'); });
            s.rootEl.querySelectorAll('.evb-block[aria-pressed]').forEach(function (b) { b.removeAttribute('aria-pressed'); });
            s.rootEl.querySelectorAll('.evb-resize-top, .evb-resize-bottom, .evb-fill-handle').forEach(function (g) { g.parentNode.removeChild(g); });
        }
        if (s.layer && s.layer.parentNode) s.layer.parentNode.removeChild(s.layer);
        s.ctx = s.layer = s.bar = s.badge = s.menu = s.rootEl = s.focusEl = null;
        s.sel = [];
        s.anchor = null;
    }

    if (V() && V().onRender) V().onRender(function () { if (s.ctx) apply('render'); });
    if (win.EvBoardEdit && win.EvBoardEdit.onEnter) {
        win.EvBoardEdit.onEnter(attach);
        win.EvBoardEdit.onExit(detach);
    }

    return {
        selected: function () { return one(); },
        selection: selection,
        select: function (list) { setSel(list); },
        onChange: function (fn) { if (typeof fn === 'function') s.listeners.push(fn); },
        copyItem: copyItem,
        atLeastFive: atLeastFive,
        revealItem: revealItem
    };
});
