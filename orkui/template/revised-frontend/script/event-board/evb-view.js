/*
 * EvBoardView: renders the Event Schedule Board (all days side by side) into
 * #evb-root and owns the Schedule tab's Board/List view switch.
 *
 * Layout comes from EvBoardCore.buildBoard; this file turns it into HTML and
 * adds the legend filter, day pills, details popover and "now" line.
 * esc, blockHtml, autolink, popoverHtml and nowMinute are pure so node tests can
 * load this file; everything that touches the DOM runs only from init().
 */
(function (root, factory) {
    var isNode = typeof module === 'object' && module.exports;
    var core = isNode ? require('./evb-core.js') : root.EvBoardCore;
    var api = factory(core, root);
    if (isNode) module.exports = api;
    else root.EvBoardView = api;
})(this, function (C, win) {
    'use strict';

    var PX_PER_MIN = 1.2;    // 18px per 15 minutes
    var MIN_BLOCK_MIN = 15;  // shorter items still draw this tall
    var TALL_MIN = 90;       // blocks this long show location and leads
    var LANE_MIN_PX = 110;   // narrowest a lane may get before the board scrolls
    var STORAGE_KEY = 'ev-sched-view-mode';
    var PHONE_QUERY = '(max-width: 700px)';

    function esc(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function catName(name, catsByName) {
        return (name && Object.prototype.hasOwnProperty.call(catsByName, name)) ? name : 'Other';
    }

    function boldOf(name, catsByName) {
        var def = catsByName[name] || catsByName.Other;
        return (def && def.bold) || '#616161';
    }

    // The minute at a vertical offset (px) inside a day body, floored to step
    // minutes and never above the axis start.
    function slotAt(offsetY, axisStart, step) {
        var steps = Math.floor(Math.max(0, offsetY) / PX_PER_MIN / step + 1e-9);
        return axisStart + steps * step;
    }

    // One block for one segment. axisStart (minutes) sets its top; it defaults to
    // 0 so the markup stays testable without a board. opts.ghost draws the dashed
    // preview of an item not yet added: not focusable and hidden from assistive tech.
    // opts.placeholder holds the place of the block being typed (evb-type.js
    // draws the real one over it): laid out like an item, but invisible.
    function blockHtml(seg, catsByName, axisStart, opts) {
        var placeholder = !!(opts && opts.placeholder);
        var ghost = !!(opts && opts.ghost) || placeholder;
        var item = seg.item || {};
        var cat = catName(item.Category, catsByName);
        var sec = item.SecondaryCategory && item.SecondaryCategory !== cat &&
            Object.prototype.hasOwnProperty.call(catsByName, item.SecondaryCategory) ? item.SecondaryCategory : '';
        var dur = seg.endMin - seg.startMin;
        var tall = dur >= TALL_MIN;
        var top = (seg.startMin - (axisStart || 0)) * PX_PER_MIN;
        var height = Math.max(dur, MIN_BLOCK_MIN) * PX_PER_MIN;
        var time = (seg.isFirst ? '' : '→ ') + C.fmtRange(seg.startMin, seg.endMin) + (seg.isLast ? '' : ' →');
        var hasMenu = (cat === 'Feast and Food' || item.SecondaryCategory === 'Feast and Food') &&
            String(item.Menu || '').trim() !== '';
        // Title lines that fit above the time line (6px padding, 13px meta line, 15px per title line).
        var lines = Math.max(1, Math.floor((height - 6 - 13) / 15));

        var cls = 'evb-block';
        if (!seg.isLast) cls += ' evb-block-continues';
        if (!seg.isFirst) cls += ' evb-block-continued';
        if (sec) cls += ' evb-block-striped';
        if (placeholder) cls += ' evb-placeholder';
        else if (ghost) cls += ' evb-ghost';

        // A 1px inset top and bottom keeps back-to-back items reading as two blocks.
        var style = 'top:' + (top + 1).toFixed(1) + 'px;height:' + (height - 2).toFixed(1) + 'px;' +
            'left:calc(' + (+seg.left || 0).toFixed(3) + '% + 1px);width:calc(' + (+seg.width || 100).toFixed(3) + '% - 2px);' +
            '--evb-fill:' + boldOf(cat, catsByName) + ';--evb-lines:' + lines + ';' +
            (sec ? '--evb-stripe:' + boldOf(sec, catsByName) + ';' : '');

        var h = '<div class="' + cls + '"' + (ghost ? ' aria-hidden="true"' : ' role="button" tabindex="0"') +
            ' data-id="' + esc(parseInt(item.EventScheduleId, 10) || 0) + '"' +
            (item.cid && !ghost ? ' data-cid="' + esc(item.cid) + '"' : '') +
            ' data-day="' + esc(seg.day) + '"' +
            ' data-category="' + esc(cat) + '"' +
            (sec ? ' data-secondary-category="' + esc(sec) + '"' : '') +
            ' aria-label="' + esc((item.Title || '') + ', ' + C.dayLabel(seg.day) + ' ' + time) + '"' +
            ' style="' + esc(style) + '">';
        h += '<div class="evb-block-title">' + esc(item.Title || '') +
            (hasMenu ? ' <i class="fas fa-scroll evb-block-menu" data-tip="Has menu"></i>' : '') + '</div>';
        h += '<div class="evb-block-meta">' + esc(time) + '</div>';
        if (tall && item.Location) {
            h += '<div class="evb-block-meta evb-block-loc"><i class="fas fa-map-marker-alt"></i> ' + esc(item.Location) + '</div>';
        }
        if (tall && item.Leads && item.Leads.length) {
            h += '<div class="evb-block-meta evb-block-leads">' +
                esc(item.Leads.map(function (l) { return l.Persona; }).join(', ')) + '</div>';
        }
        return h + '</div>';
    }

    // Escape text and link its http(s) URLs, as the list's ev_autolink does:
    // trailing sentence punctuation and an unmatched ')' stay outside the link.
    function autolink(text) {
        var parts = String(text === null || text === undefined ? '' : text).split(/(https?:\/\/[^\s<>"'`]+)/i);
        var out = '';
        parts.forEach(function (part, i) {
            if (i % 2 === 0) { out += esc(part); return; }
            var tail = '';
            while (part !== '') {
                var last = part.charAt(part.length - 1);
                var opens = (part.match(/\(/g) || []).length, closes = (part.match(/\)/g) || []).length;
                if ('.,;:!?'.indexOf(last) !== -1 || (last === ')' && closes > opens)) {
                    tail = last + tail;
                    part = part.slice(0, -1);
                    continue;
                }
                break;
            }
            if (part === '') { out += esc(tail); return; }
            out += '<a href="' + esc(part) + '" target="_blank" rel="noopener noreferrer">' + esc(part) + '</a>' + esc(tail);
        });
        return out;
    }

    // The item's time for the popover: "Saturday · 5–6p", or with weekday names
    // on both ends when it runs past its board day.
    function popoverTime(item) {
        var s = C.parseNaive(item.StartTime);
        if (!s) return '';
        var e = C.parseNaive(item.EndTime) || s;
        var segs = C.segments(item);
        if (segs.length > 1) {
            return C.dayLabel(s.day) + ' ' + C.fmtTime(s.min) + ' – ' + C.dayLabel(e.day) + ' ' + C.fmtTime(e.min);
        }
        var seg = segs[0];
        return C.dayLabel(seg.day) + ' · ' + C.fmtRange(seg.startMin, seg.endMin);
    }

    function costText(cost) {
        if (cost === null || cost === undefined || String(cost).trim() === '') return '';
        var n = Number(cost);
        if (!isFinite(n)) return '';
        return n === 0 ? 'Free' : '$' + n.toFixed(2);
    }

    // The details popover for one item. uir is the UI root for profile links;
    // opts.canEdit adds an Edit button.
    function popoverHtml(item, catsByName, uir, opts) {
        opts = opts || {};
        var cat = catName(item.Category, catsByName);
        var sec = item.SecondaryCategory && item.SecondaryCategory !== cat &&
            Object.prototype.hasOwnProperty.call(catsByName, item.SecondaryCategory) ? item.SecondaryCategory : '';
        var id = parseInt(item.EventScheduleId, 10) || 0;
        var isFeast = cat === 'Feast and Food' || sec === 'Feast and Food';

        function catTag(name) {
            return '<span class="evb-popover-cat"><span class="evb-legend-swatch" style="background:' +
                esc(boldOf(name, catsByName)) + '"></span>' + esc(name) + '</span>';
        }
        function row(icon, label, html) {
            return '<div class="evb-popover-row"><i class="fas ' + icon + '" aria-hidden="true"></i>' +
                '<span class="evb-sr">' + esc(label) + ': </span><div>' + html + '</div></div>';
        }

        var h = '<div class="evb-popover-head">' +
            '<div class="evb-popover-cats">' + catTag(cat) + (sec ? catTag(sec) : '') + '</div>' +
            '<button type="button" class="evb-popover-close" aria-label="Close">&times;</button></div>';
        h += '<div class="evb-popover-title">' + esc(item.Title || '') + '</div>';
        h += row('fa-clock', 'Time', esc(popoverTime(item)));
        if (item.Location) h += row('fa-map-marker-alt', 'Location', esc(item.Location));
        if (item.Leads && item.Leads.length) {
            h += row('fa-user', 'Led by', item.Leads.map(function (l) {
                return '<a href="' + esc((uir || '') + 'Player/profile/' + (parseInt(l.MundaneId, 10) || 0)) + '">' + esc(l.Persona) + '</a>';
            }).join(', '));
        }
        if (item.Description) h += '<div class="evb-popover-desc">' + autolink(item.Description) + '</div>';
        if (isFeast) {
            var meal = '';
            if (String(item.Menu || '').trim() !== '') meal += row('fa-scroll', 'Menu', esc(item.Menu));
            var cost = costText(item.Cost);
            if (cost) meal += row('fa-tag', 'Cost', esc(cost));
            if (item.Dietary) meal += row('fa-leaf', 'Dietary', esc(item.Dietary));
            if (item.Allergens) meal += row('fa-exclamation-triangle', 'Allergens', esc(item.Allergens));
            if (meal) h += '<div class="evb-popover-meal">' + meal + '</div>';
        }
        if (opts.canEdit) {
            h += '<div class="evb-popover-actions"><button type="button" class="evb-popover-edit" data-id="' + id + '">' +
                '<i class="fas fa-pencil-alt" aria-hidden="true"></i> Edit</button></div>';
        }
        return h;
    }

    // The board day and minute for the "now" line: the server's clock at page
    // load plus the time elapsed since, measured from a 5am board day.
    function nowMinute(serverNow, elapsedMs) {
        var p = C.parseNaive(serverNow);
        if (!p) return null;
        var total = p.min + Math.floor((+elapsedMs || 0) / 60000);
        var shift = Math.floor((total - C.BOARD_DAY_START) / 1440);
        return { day: C.addDays(p.day, shift), min: total - shift * 1440 };
    }

    // ---- DOM state (browser only) ----
    var state = {
        rootEl: null,
        listEl: null,
        items: [],
        cats: [],          // ordered category definitions
        catsByName: {},
        filter: null,
        day: null,         // the day chosen by pill or link; null is all days
        desiredView: 'list',
        editing: false,    // Edit mode: the axis widens and blocks open no popover
        ghosts: [],        // preview items drawn dashed among the real ones
        placeholder: null, // the item being typed on the board (evb-type.js), or null
        view: 'list',
        popover: null,     // { el, block, id } while a popover is open
        nowBase: 0,        // performance.now() when the page's serverNow was taken
        renderCbs: []
    };
    var api = {
        PX_PER_MIN: PX_PER_MIN,
        esc: esc,
        blockHtml: blockHtml,
        slotAt: slotAt,
        autolink: autolink,
        popoverHtml: popoverHtml,
        nowMinute: nowMinute,
        board: null
    };

    function cfg() { return (win && win.EvConfig) || {}; }

    function isPhone() {
        return !!(win && win.matchMedia && win.matchMedia(PHONE_QUERY).matches);
    }

    // The categories items on the board use, as primary or secondary.
    function presentCategories() {
        var present = {};
        state.items.forEach(function (it) {
            present[catName(it.Category, state.catsByName)] = true;
            if (it.SecondaryCategory && state.catsByName[it.SecondaryCategory]) present[it.SecondaryCategory] = true;
        });
        return present;
    }

    function legendHtml(present) {
        var h = '<div class="evb-legend">';
        state.cats.forEach(function (c) {
            if (!present[c.name]) return;
            h += '<button type="button" class="evb-legend-chip" data-category="' + esc(c.name) + '" aria-pressed="false">' +
                '<span class="evb-legend-swatch" style="background:' + esc(c.bold) + '"></span>' + esc(c.name) + '</button>';
        });
        return h + '</div>';
    }

    function currentNow() {
        var c = cfg();
        if (!c.serverNow || !win.performance) return null;
        return nowMinute(c.serverNow, win.performance.now() - state.nowBase);
    }

    // Day pills (only when the board has more than one day): "All days" plus one
    // per day; phones hide "All days" and always show a single day.
    function dayPillsHtml(board) {
        if (board.days.length < 2) return '';
        var now = currentNow();
        var long = board.days.length > 7;
        var h = '<div class="ev-grid-day-pills evb-day-pills" role="group" aria-label="Day shown">';
        h += '<button type="button" class="ev-grid-day-pill evb-day-pill evb-day-pill-all" data-day="" aria-pressed="false">All days</button>';
        board.days.forEach(function (d) {
            h += '<button type="button" class="ev-grid-day-pill evb-day-pill" data-day="' + esc(d.day) + '" aria-pressed="false">' +
                (now && now.day === d.day ? '<span class="ev-grid-day-pill-today" data-tip="Today"></span>' : '') +
                esc(long ? C.dayLabelLong(d.day) : d.label) + '</button>';
        });
        return h + '</div>';
    }

    function boardHasDay(day) {
        return !!(day && api.board && api.board.days.some(function (d) { return d.day === day; }));
    }

    // The single day on show, or null for all days. A chosen day wins; phones
    // fall back to today's board day when it is on the board, else the first day.
    // When one day shows, a ghost's day wins while it shows, so the preview is on screen.
    function resolvedDay() {
        if (!api.board || !api.board.days.length) return null;
        var ghostDay = state.ghosts.length ? (C.segments(state.ghosts[0])[0] || {}).day : null;
        if ((isPhone() || boardHasDay(state.day)) && boardHasDay(ghostDay)) return ghostDay;
        if (boardHasDay(state.day)) return state.day;
        if (!isPhone()) return null;
        var now = currentNow();
        return now && boardHasDay(now.day) ? now.day : api.board.days[0].day;
    }

    function applyDay() {
        if (!state.rootEl) return;
        var shown = resolvedDay();
        state.rootEl.querySelectorAll('.evb-day').forEach(function (el) {
            el.classList.toggle('evb-day-off', !!shown && el.getAttribute('data-day') !== shown);
        });
        state.rootEl.querySelectorAll('.evb-day-pill').forEach(function (p) {
            var on = (p.getAttribute('data-day') || null) === shown;
            p.classList.toggle('ev-grid-day-pill-active', on);
            p.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    // The "now" line across today's board day, when the minute is on the axis.
    function renderNow() {
        if (!state.rootEl || !api.board) return;
        state.rootEl.querySelectorAll('.evb-now').forEach(function (el) { el.parentNode.removeChild(el); });
        var now = currentNow();
        if (!now || now.min < api.board.axisStart || now.min > api.board.axisEnd) return;
        var body = state.rootEl.querySelector('.evb-day-body[data-day="' + now.day + '"]');
        if (!body) return;
        var line = win.document.createElement('div');
        line.className = 'evb-now';
        line.setAttribute('aria-hidden', 'true');
        line.style.top = ((now.min - api.board.axisStart) * PX_PER_MIN).toFixed(1) + 'px';
        body.appendChild(line);
    }

    function render() {
        if (!state.rootEl) return;
        closePopover(false);
        var c = cfg();
        var ghosts = state.ghosts;
        var ph = state.editing ? state.placeholder : null;
        var board = C.buildBoard(state.items.concat(ghosts, ph ? [ph] : []), { eventStart: c.eventStart || '', eventEnd: c.eventEnd || '', editing: state.editing });
        // Re-rendering replaces the scroller; keep the same minute and day position in view.
        var oldScroller = state.rootEl.querySelector('.evb-scroller');
        var keep = oldScroller && api.board ? {
            top: oldScroller.scrollTop + (api.board.axisStart - board.axisStart) * PX_PER_MIN,
            left: oldScroller.scrollLeft
        } : null;
        api.board = board;
        var bodyH = (board.axisEnd - board.axisStart) * PX_PER_MIN;
        var bodyStyle = 'height:' + bodyH.toFixed(1) + 'px';

        var present = presentCategories();
        // A filter on a category no item uses any more has no chip to clear it.
        if (state.filter && !present[state.filter]) state.filter = null;

        var h = legendHtml(present) + dayPillsHtml(board);
        h += '<div class="evb-scroller"><div class="evb-board">';
        h += '<div class="evb-gutter"><div class="evb-head"></div><div class="evb-gutter-body" style="' + bodyStyle + '">';
        for (var m = board.axisStart; m < board.axisEnd; m += 60) {
            h += '<div class="evb-tick" style="top:' + ((m - board.axisStart) * PX_PER_MIN).toFixed(1) + 'px">' + esc(C.fmtTime(m)) + '</div>';
        }
        h += '</div></div>';
        board.days.forEach(function (d) {
            var weight = Math.max(d.lanes, 1.4);
            h += '<div class="evb-day" data-day="' + esc(d.day) + '" style="flex-grow:' + weight + ';min-width:' + (d.lanes * LANE_MIN_PX) + 'px">';
            h += '<div class="evb-head">' + esc(C.dayLabelLong(d.day)) + '</div>';
            h += '<div class="evb-day-body" data-day="' + esc(d.day) + '" style="' + bodyStyle + '">';
            d.segs.forEach(function (sg) {
                h += blockHtml(sg, state.catsByName, board.axisStart,
                    { ghost: ghosts.indexOf(sg.item) >= 0, placeholder: sg.item === ph });
            });
            h += '</div></div>';
        });
        h += '</div></div>';
        state.rootEl.innerHTML = h;
        var scroller = state.rootEl.querySelector('.evb-scroller');
        if (keep && scroller) {
            scroller.scrollTop = Math.max(0, keep.top);
            scroller.scrollLeft = keep.left;
        }
        applyFilter();
        applyDay();
        renderNow();
        reveal(state.rootEl.querySelector('.evb-ghost'));
        state.renderCbs.forEach(function (fn) {
            try { fn(board); } catch (e) { if (win.console) win.console.error(e); }
        });
    }

    // The legend dims every block whose primary and secondary category both differ
    // from the chosen one.
    function applyFilter() {
        if (!state.rootEl) return;
        var f = state.filter;
        state.rootEl.querySelectorAll('.evb-block').forEach(function (b) {
            var match = b.getAttribute('data-category') === f || b.getAttribute('data-secondary-category') === f;
            b.classList.toggle('evb-dim', !!f && !match);
        });
        state.rootEl.querySelectorAll('.evb-legend-chip').forEach(function (chip) {
            chip.setAttribute('aria-pressed', chip.getAttribute('data-category') === f ? 'true' : 'false');
        });
    }

    function setFilter(category) {
        state.filter = category || null;
        applyFilter();
    }

    function setItems(items) {
        state.items = Array.isArray(items) ? items : [];
        render();
    }

    // Edit mode: the axis widens to 7am-midnight and a click on a block opens no popover.
    function setEditing(on) {
        state.editing = !!on;
        if (!state.editing) { state.ghosts = []; state.placeholder = null; }
        if (state.rootEl) state.rootEl.classList.toggle('evb-editing', state.editing);
        render();
    }

    // Preview items, laid out with the real ones and drawn dashed; [] clears them.
    // The board grows to take in a ghost's day or time while it shows.
    function setGhosts(items) {
        var next = Array.isArray(items) ? items.slice() : [];
        if (!next.length && !state.ghosts.length) return;
        state.ghosts = next;
        render();
    }

    // The block being typed on the board ({StartTime, EndTime, Category}), laid
    // out among the real ones as an invisible .evb-placeholder; null removes it.
    function setPlaceholder(item) {
        if (!item && !state.placeholder) return;
        state.placeholder = item || null;
        render();
    }

    // Scroll the board's own frame (never the page) so a block (a ghost, the
    // placeholder) is in view.
    function reveal(g) {
        var scroller = g && g.offsetParent && state.rootEl && state.rootEl.querySelector('.evb-scroller');
        if (!scroller) return;
        var sr = scroller.getBoundingClientRect(), gr = g.getBoundingClientRect();
        var head = 34, gutter = 48, pad = 12; // the sticky day heads and hour gutter cover the frame's edges
        if (gr.top < sr.top + head) scroller.scrollTop -= sr.top + head + pad - gr.top;
        else if (gr.bottom > sr.bottom) scroller.scrollTop += Math.min(gr.bottom - sr.bottom + pad, gr.top - sr.top - head - pad);
        if (gr.left < sr.left + gutter) scroller.scrollLeft -= sr.left + gutter + pad - gr.left;
        else if (gr.right > sr.right) scroller.scrollLeft += Math.min(gr.right - sr.right + pad, gr.left - sr.left - gutter - pad);
    }

    function normaliseView(v) {
        if (v === 'grid') return 'board';
        return (v === 'board' || v === 'list') ? v : null;
    }

    // In Board view the legend is the only category filter, so the tab panel's
    // evb-board-mode class hides the list's filter pills (event-board.css).
    function setView(view, opts) {
        opts = opts || {};
        var v = normaliseView(view);
        if (v) state.desiredView = v;
        var resolved = state.desiredView;
        if (resolved !== 'board') closePopover(false);
        if (state.rootEl) state.rootEl.style.display = resolved === 'board' ? '' : 'none';
        if (state.listEl) state.listEl.style.display = resolved === 'board' ? 'none' : '';
        var panel = win.document.getElementById('ev-tab-schedule');
        if (panel) panel.classList.toggle('evb-board-mode', resolved === 'board');
        win.document.querySelectorAll('.ev-grid-view-btn[data-ev-view]').forEach(function (b) {
            var active = b.getAttribute('data-ev-view') === resolved;
            b.classList.toggle('ev-grid-view-active', active);
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        state.view = resolved;
        if (opts.persist === true) {
            try { win.localStorage.setItem(STORAGE_KEY, state.desiredView); } catch (e) { /* storage blocked */ }
        }
    }

    function getView() { return state.view; }

    function scheduleTabActive() {
        var panel = win.document.getElementById('ev-tab-schedule');
        return !!(panel && panel.classList.contains('ev-tab-visible'));
    }

    function writeScheduleHash() {
        if (scheduleTabActive() && typeof win.evWriteHash === 'function') win.evWriteHash('ev-tab-schedule');
    }

    // Show one day (YYYY-MM-DD) or, with null, all days; the link carries it.
    function setDay(day) {
        state.day = (typeof day === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(day)) ? day : null;
        applyDay();
        writeScheduleHash();
    }

    // Managers open the edit modal from the popover, through the list row's own
    // edit button so the modal's row lookup keeps working.
    function openEditor(id) {
        if (!cfg().canManageSchedule) return;
        var row = win.document.getElementById('ev-schedule-row-' + id);
        var btn = row && row.querySelector('.ev-edit-link');
        if (btn) btn.click();
    }

    function findItem(id) {
        for (var i = 0; i < state.items.length; i++) {
            if (String(parseInt(state.items[i].EventScheduleId, 10)) === String(id)) return state.items[i];
        }
        return null;
    }

    var pointerDown = false; // a mouse or touch press is in progress (set in init)

    function closePopover(restoreFocus) {
        var p = state.popover;
        if (!p) return;
        state.popover = null;
        if (p.el.parentNode) p.el.parentNode.removeChild(p.el);
        if (restoreFocus && p.block && win.document.contains(p.block)) p.block.focus({ preventScroll: true });
    }

    // Below the block, flipped above it when it would leave the viewport, and
    // kept 8px inside the viewport's edges.
    function placePopover(el, block) {
        var r = block.getBoundingClientRect();
        var vw = win.document.documentElement.clientWidth, vh = win.innerHeight;
        var w = el.offsetWidth, h = el.offsetHeight, gap = 6, edge = 8;
        var top = r.bottom + gap;
        if (top + h > vh - edge) {
            top = r.top - h - gap >= edge ? r.top - h - gap : Math.max(edge, vh - h - edge);
        }
        el.style.left = Math.max(edge, Math.min(r.left, vw - w - edge)) + 'px';
        el.style.top = top + 'px';
    }

    function openPopover(block) {
        var id = block.getAttribute('data-id');
        var again = state.popover && state.popover.block === block;
        closePopover(false);
        if (again) return; // a second click on the same block closes it
        var item = findItem(id);
        if (!item) return;
        var c = cfg();
        var el = win.document.createElement('div');
        el.className = 'evb-popover';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-label', item.Title || 'Schedule item');
        el.setAttribute('tabindex', '-1');
        el.innerHTML = popoverHtml(item, state.catsByName, c.uir || '', { canEdit: !!c.canManageSchedule });
        el.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target : null;
            if (!t) return;
            if (t.closest('.evb-popover-close')) { closePopover(true); return; }
            var edit = t.closest('.evb-popover-edit');
            if (edit) {
                closePopover(false);
                openEditor(edit.getAttribute('data-id'));
            }
        });
        // The popover sits at the end of <body>, so keyboard focus leaving it would
        // otherwise land far from the board (or leave the page). Tab past the last
        // control, Shift+Tab before the first, or focus moving elsewhere without a
        // pointer closes it and returns focus to the block. Pointer presses are
        // left to the document click handler, which keeps the clicked target.
        el.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') return;
            var f = el.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
            var first = f[0], last = f[f.length - 1];
            var a = win.document.activeElement;
            var out = !f.length || (e.shiftKey ? (a === first || a === el) : a === last);
            if (!out) return;
            e.preventDefault();
            closePopover(true);
        });
        el.addEventListener('focusout', function (e) {
            var to = e.relatedTarget;
            if (pointerDown || !to || el.contains(to)) return;
            if (state.popover && state.popover.el === el) closePopover(true);
        });
        win.document.body.appendChild(el);
        placePopover(el, block);
        var r = block.getBoundingClientRect();
        state.popover = { el: el, block: block, top: r.top, left: r.left };
        el.focus({ preventScroll: true });
    }

    // Re-fetch the page and take the fresh schedule from its JSON data script, so
    // schedule add/edit/delete (handled in revised.js) reach the board. Each
    // refresh is numbered; a response to one that is no longer the latest is
    // dropped, so a slow earlier fetch cannot overwrite a newer schedule.
    var refreshSeq = 0;
    function refreshFromServer() {
        // In Edit mode the store owns the board, so it re-reads the schedule instead.
        if (state.editing && win.EvBoardEdit && win.EvBoardEdit.refresh) { win.EvBoardEdit.refresh(); return; }
        var seq = ++refreshSeq;
        win.fetch(win.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                if (seq !== refreshSeq) return;
                var doc = new win.DOMParser().parseFromString(html, 'text/html');
                var data = doc.getElementById('evb-schedule-data');
                if (!data) return;
                var items = JSON.parse(data.textContent || '[]');
                if (win.EvConfig) win.EvConfig.schedule = items;
                if (!state.editing) setItems(items);
            })
            .catch(function () { /* leave the board as it is; the list is already correct */ });
    }

    function init() {
        var doc = win.document;
        state.rootEl = doc.getElementById('evb-root');
        state.listEl = doc.getElementById('ev-schedule-container');
        if (!state.rootEl || !state.listEl) return;
        var c = cfg();
        state.cats = Array.isArray(c.scheduleCategories) ? c.scheduleCategories : [];
        state.catsByName = {};
        state.cats.forEach(function (cat) { state.catsByName[cat.name] = cat; });
        state.items = Array.isArray(c.schedule) ? c.schedule : [];
        state.nowBase = win.performance ? win.performance.now() : 0;
        // A day in the link (YYYYMMDD) shows only that day until "All days" is chosen.
        var hp = win.evHashParams || {};
        var hd = /^(\d{4})(\d{2})(\d{2})$/.exec(hp.day || '');
        if (hd) state.day = hd[1] + '-' + hd[2] + '-' + hd[3];
        render();

        doc.querySelectorAll('.ev-grid-view-btn[data-ev-view]').forEach(function (b) {
            b.addEventListener('click', function () {
                setView(b.getAttribute('data-ev-view'), { persist: true });
                writeScheduleHash();
            });
        });
        state.rootEl.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target : null;
            if (!t) return;
            var chip = t.closest('.evb-legend-chip');
            if (chip) {
                var cat = chip.getAttribute('data-category');
                setFilter(state.filter === cat ? null : cat);
                return;
            }
            var pill = t.closest('.evb-day-pill');
            if (pill) { setDay(pill.getAttribute('data-day') || null); return; }
            var block = t.closest('.evb-block');
            if (block && !state.editing) openPopover(block);
        });
        state.rootEl.addEventListener('keydown', function (e) {
            if (state.editing || (e.key !== 'Enter' && e.key !== ' ')) return;
            var block = e.target.closest && e.target.closest('.evb-block');
            if (!block) return;
            e.preventDefault();
            openPopover(block);
        });
        // The popover closes on an outside click (a click on another block opens
        // that block's instead), on Esc, and when a scroll moves its block. Scroll
        // events land a frame late, so one from just before the click (scrolling
        // the block into view) leaves the block where it was and is ignored.
        doc.addEventListener('click', function (e) {
            var p = state.popover;
            if (!p || !e.target.closest || p.el.contains(e.target) || e.target.closest('.evb-block')) return;
            closePopover(false);
        });
        doc.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && state.popover) closePopover(true);
        });
        // Focus that moves during a press comes from the pointer, not the keyboard.
        doc.addEventListener('pointerdown', function () { pointerDown = true; }, true);
        ['pointerup', 'pointercancel'].forEach(function (type) {
            doc.addEventListener(type, function () { win.setTimeout(function () { pointerDown = false; }, 0); }, true);
        });
        win.addEventListener('scroll', function () {
            var p = state.popover;
            if (!p) return;
            var r = p.block.getBoundingClientRect();
            if (Math.abs(r.top - p.top) > 1 || Math.abs(r.left - p.left) > 1) closePopover(false);
        }, true);

        // A view in the link wins over the stored choice; with neither, Board for a
        // schedule with items on a wide screen, otherwise List.
        var initial = null;
        try { initial = normaliseView(win.localStorage.getItem(STORAGE_KEY)); } catch (e) { /* storage blocked */ }
        if (normaliseView(hp.view)) initial = normaliseView(hp.view);
        if (!initial) initial = (state.items.length && !isPhone()) ? 'board' : 'list';
        setView(initial, { persist: false });
        win.addEventListener('resize', function () {
            closePopover(false);
            setView(null, { persist: false });
            applyDay();
        });

        // Keep the now line current while the board is on screen.
        win.setInterval(function () {
            if (state.view === 'board' && !doc.hidden) renderNow();
        }, 60000);
        // The interval skips hidden tabs, so catch up as soon as the tab is back.
        doc.addEventListener('visibilitychange', function () {
            if (!doc.hidden) renderNow();
        });

        win.evScheduleHashSuffix = function () {
            if (state.view !== 'board') return '';
            return '?view=board' + (boardHasDay(state.day) ? '&day=' + state.day.replace(/-/g, '') : '');
        };
        win.evRefreshScheduleGrid = refreshFromServer;
    }

    api.init = init;
    api.render = render;
    api.setItems = setItems;
    api.setEditing = setEditing;
    api.isEditing = function () { return state.editing; };
    api.setGhosts = setGhosts;
    api.setPlaceholder = setPlaceholder;
    api.reveal = reveal;
    api.getItems = function () { return state.items; };
    api.setView = setView;
    api.getView = getView;
    api.setFilter = setFilter;
    api.setDay = setDay;
    api.getDay = resolvedDay;
    api.onRender = function (fn) { if (typeof fn === 'function') state.renderCbs.push(fn); };
    return api;
});
