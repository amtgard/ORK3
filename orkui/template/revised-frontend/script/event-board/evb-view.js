/*
 * EvBoardView: renders the Event Schedule Board (all days side by side) into
 * #evb-root and owns the Schedule tab's Board/List view switch.
 *
 * Layout comes from EvBoardCore.buildBoard; this file only turns it into HTML.
 * blockHtml and esc are pure so node tests can load this file; everything that
 * touches the DOM runs only from init().
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

    // One block for one segment. axisStart (minutes) sets its top; it defaults to
    // 0 so the markup stays testable without a board.
    function blockHtml(seg, catsByName, axisStart) {
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

        var style = 'top:' + top.toFixed(1) + 'px;height:' + height.toFixed(1) + 'px;' +
            'left:calc(' + (+seg.left || 0).toFixed(3) + '% + 1px);width:calc(' + (+seg.width || 100).toFixed(3) + '% - 2px);' +
            '--evb-fill:' + boldOf(cat, catsByName) + ';--evb-lines:' + lines + ';' +
            (sec ? '--evb-stripe:' + boldOf(sec, catsByName) + ';' : '');

        var h = '<div class="' + cls + '" role="button" tabindex="0"' +
            ' data-id="' + esc(parseInt(item.EventScheduleId, 10) || 0) + '"' +
            ' data-day="' + esc(seg.day) + '"' +
            ' data-category="' + esc(cat) + '"' +
            (sec ? ' data-secondary-category="' + esc(sec) + '"' : '') +
            ' aria-label="' + esc((item.Title || '') + ', ' + time) + '"' +
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

    // ---- DOM state (browser only) ----
    var state = {
        rootEl: null,
        listEl: null,
        items: [],
        cats: [],          // ordered category definitions
        catsByName: {},
        filter: null,
        desiredView: 'list',
        view: 'list',
        renderCbs: []
    };
    var api = {
        PX_PER_MIN: PX_PER_MIN,
        esc: esc,
        blockHtml: blockHtml,
        board: null
    };

    function cfg() { return (win && win.EvConfig) || {}; }

    function isPhone() {
        return !!(win && win.matchMedia && win.matchMedia(PHONE_QUERY).matches);
    }

    function legendHtml() {
        var present = {};
        state.items.forEach(function (it) {
            present[catName(it.Category, state.catsByName)] = true;
            if (it.SecondaryCategory && state.catsByName[it.SecondaryCategory]) present[it.SecondaryCategory] = true;
        });
        var h = '<div class="evb-legend">';
        state.cats.forEach(function (c) {
            if (!present[c.name]) return;
            h += '<button type="button" class="evb-legend-chip" data-category="' + esc(c.name) + '" aria-pressed="false">' +
                '<span class="evb-legend-swatch" style="background:' + esc(c.bold) + '"></span>' + esc(c.name) + '</button>';
        });
        return h + '</div>';
    }

    function render() {
        if (!state.rootEl) return;
        var c = cfg();
        var board = C.buildBoard(state.items, { eventStart: c.eventStart || '', eventEnd: c.eventEnd || '', editing: false });
        api.board = board;
        var bodyH = (board.axisEnd - board.axisStart) * PX_PER_MIN;
        var bodyStyle = 'height:' + bodyH.toFixed(1) + 'px';

        var h = legendHtml();
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
            d.segs.forEach(function (sg) { h += blockHtml(sg, state.catsByName, board.axisStart); });
            h += '</div></div>';
        });
        h += '</div></div>';
        state.rootEl.innerHTML = h;
        applyFilter();
        state.renderCbs.forEach(function (fn) {
            try { fn(board); } catch (e) { if (win.console) win.console.error(e); }
        });
    }

    function applyFilter() {
        if (!state.rootEl) return;
        var f = state.filter;
        state.rootEl.querySelectorAll('.evb-block').forEach(function (b) {
            b.classList.toggle('evb-dim', !!f && b.getAttribute('data-category') !== f);
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

    function normaliseView(v) {
        if (v === 'grid') return 'board';
        return (v === 'board' || v === 'list') ? v : null;
    }

    // desiredView is what the viewer or link asked for; phones still resolve to
    // List here (the phone board arrives later). The desired value is persisted,
    // the resolved one is shown and linked.
    function setView(view, opts) {
        opts = opts || {};
        var v = normaliseView(view);
        if (v) state.desiredView = v;
        var resolved = isPhone() ? 'list' : state.desiredView;
        if (state.rootEl) state.rootEl.style.display = resolved === 'board' ? '' : 'none';
        if (state.listEl) state.listEl.style.display = resolved === 'board' ? 'none' : '';
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

    // Managers open the edit modal by clicking a block, through the list row's own
    // edit button so the modal's row lookup keeps working.
    function openEditor(block) {
        if (!cfg().canManageSchedule || !block) return;
        var row = win.document.getElementById('ev-schedule-row-' + block.getAttribute('data-id'));
        var btn = row && row.querySelector('.ev-edit-link');
        if (btn) btn.click();
    }

    // Re-fetch the page and take the fresh schedule from its JSON data script, so
    // schedule add/edit/delete (handled in revised.js) reach the board.
    function refreshFromServer() {
        win.fetch(win.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new win.DOMParser().parseFromString(html, 'text/html');
                var data = doc.getElementById('evb-schedule-data');
                if (!data) return;
                var items = JSON.parse(data.textContent || '[]');
                if (win.EvConfig) win.EvConfig.schedule = items;
                setItems(items);
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
        render();

        doc.querySelectorAll('.ev-grid-view-btn[data-ev-view]').forEach(function (b) {
            b.addEventListener('click', function () {
                setView(b.getAttribute('data-ev-view'), { persist: true });
                writeScheduleHash();
            });
        });
        state.rootEl.addEventListener('click', function (e) {
            openEditor(e.target.closest && e.target.closest('.evb-block'));
        });
        state.rootEl.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var block = e.target.closest && e.target.closest('.evb-block');
            if (!block) return;
            if (e.key === ' ') e.preventDefault();
            openEditor(block);
        });

        // A view in the link wins over the stored choice; with neither, Board for a
        // schedule with items on a wide screen, otherwise List.
        var initial = null;
        try { initial = normaliseView(win.localStorage.getItem(STORAGE_KEY)); } catch (e) { /* storage blocked */ }
        var hp = win.evHashParams || {};
        if (normaliseView(hp.view)) initial = normaliseView(hp.view);
        if (!initial) initial = (state.items.length && !isPhone()) ? 'board' : 'list';
        setView(initial, { persist: false });
        win.addEventListener('resize', function () { setView(null, { persist: false }); });

        win.evScheduleHashSuffix = function () { return state.view === 'board' ? '?view=board' : ''; };
        win.evRefreshScheduleGrid = refreshFromServer;
    }

    api.init = init;
    api.render = render;
    api.setItems = setItems;
    api.getItems = function () { return state.items; };
    api.setView = setView;
    api.getView = getView;
    api.setFilter = setFilter;
    api.onRender = function (fn) { if (typeof fn === 'function') state.renderCbs.push(fn); };
    return api;
});
