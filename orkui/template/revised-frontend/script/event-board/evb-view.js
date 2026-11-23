/*
 * EvBoardView: renders the Event Schedule Board (all days side by side) into
 * #evb-root and owns the Schedule tab's Board/List view switch.
 *
 * Layout comes from EvBoardCore.buildBoard; this file turns it into HTML and
 * adds the legend filter, day pills, details popover, "now" line and the
 * "now and next" strip above both views.
 * esc, blockHtml, railWidth, autolink, popoverHtml, nowNextHtml, nowMinute, nowLine,
 * openingDay, filterStatus, keptFilter and filterAccess are pure so node tests can
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
    var RAIL_PX = 22;        // an ongoing item's rail (EvBoardCore.isOngoing) at the left of its day
    var RAIL_GAP = 2;        // between rails, and between the last rail and the lanes
    var HEAD_PX = 34;        // the sticky day heads (.evb-head) cover the top of the frame
    var GUTTER_PX = 48;      // the sticky hour gutter (.evb-gutter) covers its left edge
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

    // "A", "A and B", "A, B and C".
    function andList(names) {
        if (names.length < 2) return names.join('');
        return names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1];
    }

    // A segment's day and time for a label: "Saturday 9–10a", or for one that
    // starts after midnight "Sunday 1–2a (Saturday night)". sep goes between day and time.
    function dayTime(day, startMin, time, sep) {
        var late = C.lateNight(day, startMin);
        return late ? late.day + sep + time + ' (' + late.night + ')' : C.dayLabel(day) + sep + time;
    }

    // What a screen reader hears for a block:
    // "Title, Category[ and Secondary], Day time[, at Location][, led by A and B]".
    function blockLabel(item, cat, sec, day, startMin, time) {
        var leads = (item.Leads || []).map(function (l) { return String((l && l.Persona) || '').trim(); })
            .filter(function (n) { return n !== ''; });
        var loc = String(item.Location || '').trim();
        return (item.Title || '') + ', ' + cat + (sec ? ' and ' + sec : '') + ', ' + dayTime(day, startMin, time, ' ') +
            (loc ? ', at ' + loc : '') + (leads.length ? ', led by ' + andList(leads) : '');
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

    // The width the rails take at the left of a day column (px): each rail and
    // the gap after it. 0 with no rails.
    function railWidth(count) {
        var n = Math.max(0, parseInt(count, 10) || 0);
        return n * (RAIL_PX + RAIL_GAP);
    }

    // How much of a rail shows (px): top and height are the rail's in its day body;
    // viewTop is the body offset at the bottom edge of the sticky day heads and
    // viewHeight how much of the body shows below them. 0 when none of it shows.
    function railVisible(top, height, viewTop, viewHeight) {
        var v = Math.min(top + height, viewTop + viewHeight) - Math.max(top, viewTop);
        return v > 0 ? Math.round(v) : 0;
    }

    // One block for one segment. axisStart (minutes) sets its top; it defaults to
    // 0 so the markup stays testable without a board. opts.ghost draws the dashed
    // preview of an item not yet added: not focusable and hidden from assistive tech.
    // opts.placeholder holds the place of the block being typed (evb-type.js
    // draws the real one over it): laid out like an item, but invisible.
    // A segment with a rail index (buildBoard's day.rails) is drawn as a rail:
    // RAIL_PX wide at the column's left edge, its title running down it. Every
    // other block is laid out in the width left of its day body after the rails
    // (--evb-rail-w on the day body).
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

        var rail = typeof seg.rail === 'number' && seg.rail >= 0;
        var cls = 'evb-block';
        if (rail) cls += ' evb-block-rail';
        if (!seg.isLast) cls += ' evb-block-continues';
        if (!seg.isFirst) cls += ' evb-block-continued';
        if (sec) cls += ' evb-block-striped';
        if (placeholder) cls += ' evb-placeholder';
        else if (ghost) cls += ' evb-ghost';

        // A 1px inset top and bottom keeps back-to-back items reading as two blocks.
        var lanesW = '(100% - var(--evb-rail-w, 0px))';
        var across = rail
            ? 'left:' + (1 + railWidth(seg.rail)) + 'px;width:' + RAIL_PX + 'px;'
            : 'left:calc(var(--evb-rail-w, 0px) + ' + lanesW + ' * ' + ((+seg.left || 0) / 100).toFixed(5) + ' + 1px);' +
                'width:calc(' + lanesW + ' * ' + ((+seg.width || 100) / 100).toFixed(5) + ' - 2px);';
        var style = 'top:' + (top + 1).toFixed(1) + 'px;height:' + (height - 2).toFixed(1) + 'px;' + across +
            '--evb-fill:' + boldOf(cat, catsByName) + ';--evb-lines:' + lines + ';' +
            (sec ? '--evb-stripe:' + boldOf(sec, catsByName) + ';' : '');

        var label = esc(blockLabel(item, cat, sec, seg.day, seg.startMin, time));
        var h = '<div class="' + cls + '"' + (ghost ? ' aria-hidden="true"' : ' role="button" tabindex="0"') +
            ' data-id="' + esc(parseInt(item.EventScheduleId, 10) || 0) + '"' +
            (item.cid && !ghost ? ' data-cid="' + esc(item.cid) + '"' : '') +
            ' data-day="' + esc(seg.day) + '"' +
            ' data-category="' + esc(cat) + '"' +
            (sec ? ' data-secondary-category="' + esc(sec) + '"' : '') +
            ' aria-label="' + label + '"' +
            // The label without conflict notes; evb-bulk.js appends those to it.
            (ghost ? '' : ' data-evb-label="' + label + '"') +
            ' style="' + esc(style) + '">';
        // A rail shows only its title, running down it (the label has the rest).
        if (rail) return h + '<div class="evb-block-title">' + esc(item.Title || '') + '</div></div>';
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

    // The item's time for the popover: "Saturday · 5–6p" ("Sunday · 1–2a (Saturday
    // night)" after midnight), or with weekday names on both ends when it runs past
    // its board day.
    function popoverTime(item) {
        var s = C.parseNaive(item.StartTime);
        if (!s) return '';
        var e = C.parseNaive(item.EndTime) || s;
        var segs = C.segments(item);
        if (segs.length > 1) {
            return C.dayLabel(s.day) + ' ' + C.fmtTime(s.min) + ' – ' + C.dayLabel(e.day) + ' ' + C.fmtTime(e.min);
        }
        var seg = segs[0];
        return dayTime(seg.day, seg.startMin, C.fmtRange(seg.startMin, seg.endMin), ' · ');
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

    // Whether the popover offers Edit: to a schedule manager on every item, and to
    // a feast editor (can manage the feast, not the schedule) on a feast item, one
    // with Feast and Food as its primary or secondary category.
    function popoverCanEdit(item, config) {
        config = config || {};
        if (config.canManageSchedule) return true;
        if (!config.canManageFeast || !item) return false;
        return item.Category === 'Feast and Food' || item.SecondaryCategory === 'Feast and Food';
    }

    // An entry's time in the "now and next" strip: 'until 3p' for an item on now,
    // 'at 3p' for one next. An end on another board day than nowDay names the
    // calendar day it falls on ('until Sunday 9a').
    function nowNextWhen(entry, isNow, nowDay) {
        if (!isNow) return 'at ' + C.fmtTime(entry.start.min);
        var e = entry.end;
        var real = C.parseNaive(C.toNaive(e.day, e.min)).day;
        return 'until ' + (e.day !== nowDay ? C.dayLabel(real) + ' ' : '') + C.fmtTime(e.min);
    }

    // The "now and next" strip's markup for an EvBoardCore.nowNext result: two
    // labelled lists of buttons (data-id: the item), '' when there is nothing to
    // show. A button shows a swatch in the category's colour, the title, the
    // time and the location; its label names the category too.
    function nowNextHtml(r, catsByName) {
        if (!r || (!r.now.length && !r.next.length)) return '';
        function entryHtml(en, isNow) {
            var item = en.item || {};
            var cat = catName(item.Category, catsByName);
            var sec = item.SecondaryCategory && item.SecondaryCategory !== cat &&
                Object.prototype.hasOwnProperty.call(catsByName, item.SecondaryCategory) ? item.SecondaryCategory : '';
            var when = nowNextWhen(en, isNow, r.day);
            var loc = String(item.Location || '').trim();
            var label = (item.Title || '') + ', ' + cat + (sec ? ' and ' + sec : '') + ', ' + when + (loc ? ', at ' + loc : '');
            return '<li><button type="button" class="evb-nownext-item" data-id="' + esc(parseInt(item.EventScheduleId, 10) || 0) + '"' +
                ' data-category="' + esc(cat) + '" aria-label="' + esc(label) + '">' +
                '<span class="evb-legend-swatch" aria-hidden="true" style="background:' + esc(boldOf(cat, catsByName)) + '"></span>' +
                '<span class="evb-nownext-title">' + esc(item.Title || '') + '</span>' +
                '<span class="evb-nownext-when">' + esc(when) + '</span>' +
                (loc ? '<span class="evb-nownext-loc"><i class="fas fa-map-marker-alt" aria-hidden="true"></i> ' + esc(loc) + '</span>' : '') +
                '</button></li>';
        }
        function group(key, name, list, isNow, more) {
            if (!list.length) return '';
            return '<div class="evb-nownext-group evb-nownext-' + key + '" role="group" aria-labelledby="evb-nownext-' + key + '-label">' +
                '<span class="evb-nownext-label" id="evb-nownext-' + key + '-label">' + name + '</span>' +
                '<ul class="evb-nownext-list">' + list.map(function (en) { return entryHtml(en, isNow); }).join('') +
                (more ? '<li class="evb-nownext-more">+' + more + ' more</li>' : '') + '</ul></div>';
        }
        return group('now', 'Now', r.now, true, r.more) + group('next', 'Next', r.next, false, 0);
    }

    // The board day and minute of a naive 'YYYY-MM-DD HH:MM' clock reading plus
    // elapsedMs, measured from a 5am board day.
    function nowMinute(clock, elapsedMs) {
        var p = C.parseNaive(clock);
        if (!p) return null;
        var total = p.min + Math.floor((+elapsedMs || 0) / 60000);
        var shift = Math.floor((total - C.BOARD_DAY_START) / 1440);
        return { day: C.addDays(p.day, shift), min: total - shift * 1440 };
    }

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    // The device's wall clock as a naive 'YYYY-MM-DD HH:MM'. The one place the
    // view reads a Date in the browser's zone: the device's wall clock is the
    // attendee's local time, which is what the event's naive times mean.
    function deviceClock() {
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + ' ' +
            pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }
    var clockFn = deviceClock;

    // The clock's naive reading, or null.
    function clockNow() {
        try { return clockFn(); } catch (e) { return null; }
    }

    // The board day and minute now, or null when the clock gives nothing usable.
    function currentNow() {
        return nowMinute(clockNow(), 0);
    }

    // Where the now line goes on a board ({days, axisStart, axisEnd}): its day and
    // its offset in px from the axis start, or null when now is off the board.
    function nowLine(board, now) {
        if (!board || !now || !board.days) return null;
        if (now.min < board.axisStart || now.min > board.axisEnd) return null;
        if (!board.days.some(function (d) { return d.day === now.day; })) return null;
        return { day: now.day, min: now.min, top: (now.min - board.axisStart) * PX_PER_MIN };
    }

    // The day a phone opens on: today's board day when it is on the board, else the first.
    function openingDay(board, now) {
        if (!board || !board.days || !board.days.length) return null;
        var has = now && board.days.some(function (d) { return d.day === now.day; });
        return has ? now.day : board.days[0].day;
    }

    // The legend filter's announcement: "Showing 6 Battlegame items", or with no
    // filter "Showing all items". An item counts once, by primary or secondary.
    function filterStatus(items, catsByName, filter) {
        if (!filter) return 'Showing all items';
        var n = 0;
        (items || []).forEach(function (it) {
            if (catName(it.Category, catsByName) === filter || (it.SecondaryCategory === filter && catsByName[filter])) n++;
        });
        return 'Showing ' + n + ' ' + filter + ' item' + (n === 1 ? '' : 's');
    }

    // After a render: a filter on a category no item uses any more has no chip
    // to clear it, so it is dropped and the status region says so.
    // present: {categoryName: true}. -> { filter, status } (status null: leave it).
    function keptFilter(filter, present) {
        if (filter && !(present || {})[filter]) return { filter: null, status: filterStatus([], {}, null) };
        return { filter: filter || null, status: null };
    }

    // What the legend filter does to a real block's reachability: 'hide' (out
    // of the tab order, aria-hidden), 'show' (undo the filter's own hiding) or
    // null (leave it). keep: the block is selected or has focus, so it stays
    // reachable even when it does not match.
    function filterAccess(dim, keep, hidden) {
        if (dim && !keep) return 'hide';
        return hidden ? 'show' : null;
    }

    // A schedule row from EventAjax (a save's reply, schedule_list) in the shape
    // the page's #evb-schedule-data gives the board: the same fields, text as
    // strings, leads with their Auto flag. null when it has no id.
    function pageRow(row) {
        var id = row ? parseInt(row.EventScheduleId, 10) || 0 : 0;
        if (id <= 0) return null;
        var str = function (v) { return v == null ? '' : String(v); };
        return {
            EventScheduleId: id,
            Title: str(row.Title),
            StartTime: str(row.StartTime),
            EndTime: str(row.EndTime),
            Location: str(row.Location),
            Description: str(row.Description),
            Category: str(row.Category),
            SecondaryCategory: str(row.SecondaryCategory),
            Leads: (Array.isArray(row.Leads) ? row.Leads : []).map(function (l) {
                return { MundaneId: parseInt(l && l.MundaneId, 10) || 0, Persona: str(l && l.Persona), Auto: !!(l && l.Auto) };
            }),
            Menu: str(row.Menu),
            Cost: row.Cost == null || row.Cost === '' ? null : row.Cost,
            Dietary: str(row.Dietary),
            Allergens: str(row.Allergens),
            Modified: str(row.Modified)
        };
    }

    // items with one change applied, as a new list in the server's order (by start
    // time): {saved: row} replaces the item with row's id or adds it; {removed: id}
    // drops that item. null when the change is neither (the caller re-reads).
    function withChange(items, change) {
        var list = Array.isArray(items) ? items : [];
        var idOf = function (it) { return parseInt(it && it.EventScheduleId, 10) || 0; };
        if (change && change.saved) {
            var row = pageRow(change.saved);
            if (!row) return null;
            var out = list.filter(function (it) { return idOf(it) !== row.EventScheduleId; });
            out.push(row);
            // Stable: items that start together keep the order they had.
            return out.map(function (it, i) { return { it: it, i: i }; }).sort(function (a, b) {
                var x = String(a.it.StartTime || ''), y = String(b.it.StartTime || '');
                return x < y ? -1 : x > y ? 1 : a.i - b.i;
            }).map(function (e) { return e.it; });
        }
        var gone = change ? parseInt(change.removed, 10) || 0 : 0;
        if (gone > 0) return list.filter(function (it) { return idOf(it) !== gone; });
        return null;
    }

    // Keeps the board's schedule and the List in step with the person's own
    // changes and the reads that follow them, whatever order replies come back in.
    // env (the browser wiring is in init; tests pass fakes):
    //   readPage()      -> Promise of the event page's HTML
    //   parsePage(html) -> {items, swap()} (swap puts the List and Feast tabs and
    //                      their counts in place) or null when it is not the page
    //   readList()      -> Promise of {schedule} (EvBoardEdit.fetchList: reply
    //                      checks and a time limit) or null where there is none
    //   take(items)     the board's schedule; items() the one shown now
    //   editing() / editRefresh()  Edit mode's store re-reads for itself
    // Returns {page(), change(change)}; each returns a promise that settles when
    // its work is done (tests wait on it).
    //
    // Every read and local merge gets a tick. A merge sends no request and
    // invalidates no read: a read still on its way when a merge lands has the
    // merge laid over its result (unless the read's copy of that row is the newer
    // one by Modified). A page read that saw no later merge swaps the List too;
    // one that did gives the board its data and reads the page again for the
    // List, since revised.js already put the merged row in the List itself. A
    // read is dropped only once a read sent after it has been taken.
    function refresher(env) {
        var tick = 0, dataAt = 0, swapAt = 0, flying = 0, merges = [];
        var idOf = function (it) { return parseInt(it && it.EventScheduleId, 10) || 0; };
        function settle() { if (--flying <= 0) { flying = 0; merges = []; } }
        // fn's promise, its request sent now; a throw becomes a rejection.
        function attempt(fn) { try { return Promise.resolve(fn()); } catch (e) { return Promise.reject(e); } }
        function after(at) { return merges.filter(function (m) { return m.at > at; }); }
        function overlay(items, ms) {
            return ms.reduce(function (list, m) {
                var row = m.change.saved ? pageRow(m.change.saved) : null;
                if (row) {
                    var theirs = list.filter(function (it) { return idOf(it) === row.EventScheduleId; })[0];
                    if (theirs && String(theirs.Modified || '') > row.Modified) return list;
                }
                return withChange(list, m.change) || list;
            }, items);
        }
        function takeData(at, items) {
            if (at < dataAt) return;
            dataAt = at;
            env.take(overlay(items, after(at)));
        }
        function page() {
            var at = ++tick;
            flying++;
            return attempt(env.readPage).then(function (html) {
                if (at < swapAt) return;
                var fresh = env.parsePage(html);
                if (!fresh) return;
                var follow = after(at).length > 0;
                if (!follow) { swapAt = at; fresh.swap(); }
                takeData(at, fresh.items);
                if (follow) return page();
                if (env.editing()) env.editRefresh();
            }).catch(function () { /* the page stays as it is */ }).then(settle);
        }
        function read() {
            var at = ++tick;
            flying++;
            return attempt(function () {
                var list = env.readList();
                if (!list) throw new Error('no list read');
                return list;
            }).then(function (data) {
                takeData(at, data.schedule.map(pageRow).filter(Boolean));
            }).catch(function () {
                // Refused, timed out or not on offer (a feast-only editor): the page.
                if (at < dataAt) return;
                return attempt(env.readPage).then(function (html) {
                    var fresh = env.parsePage(html);
                    if (fresh) takeData(at, fresh.items);
                });
            }).catch(function () { /* the board stays as it is; the List is already right */ }).then(settle);
        }
        function change(ch) {
            if (env.editing()) { env.editRefresh(); return Promise.resolve(); }
            var merged = withChange(env.items(), ch);
            if (!merged) return read();
            var at = ++tick;
            if (flying > 0) merges.push({ at: at, change: ch });
            env.take(merged);
            return Promise.resolve();
        }
        return { page: page, change: change };
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
        ghostKey: '',      // drawKey of the ghosts drawn now
        placeholder: null, // the item being typed on the board (evb-type.js), or null
        placeholderKey: '',
        view: 'list',
        popover: null,     // { el, block, id } while a popover is open
        statusEl: null,    // polite status region for the legend filter
        stripEl: null,     // the "now and next" strip above both views
        stripHtml: '',
        renderCbs: []
    };
    var api = {
        PX_PER_MIN: PX_PER_MIN,
        HEAD_PX: HEAD_PX,
        GUTTER_PX: GUTTER_PX,
        esc: esc,
        RAIL_PX: RAIL_PX,
        blockHtml: blockHtml,
        railWidth: railWidth,
        railVisible: railVisible,
        nowNextHtml: nowNextHtml,
        slotAt: slotAt,
        autolink: autolink,
        popoverHtml: popoverHtml,
        popoverCanEdit: popoverCanEdit,
        nowMinute: nowMinute,
        currentNow: currentNow,
        nowLine: nowLine,
        openingDay: openingDay,
        filterStatus: filterStatus,
        keptFilter: keptFilter,
        filterAccess: filterAccess,
        pageRow: pageRow,
        withChange: withChange,
        refresher: refresher,
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
                '<span class="evb-legend-swatch" style="background:' + esc(c.bold) + '"></span>' + esc(c.name) +
                // The category's number key, shown in Edit mode (event-board.css).
                (c.key != null ? '<span class="evb-legend-key" aria-hidden="true">' + esc(c.key) + '</span>' : '') + '</button>';
        });
        return h + '</div>';
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
    // When one day shows, the day of the block being typed on the board (else a
    // ghost's) wins while it shows, so the block or the preview is on screen.
    function resolvedDay() {
        if (!api.board || !api.board.days.length) return null;
        var ph = state.editing ? state.placeholder : null;
        var shown = ph || state.ghosts[0];
        var ghostDay = shown ? (C.segments(shown)[0] || {}).day : null;
        if ((isPhone() || boardHasDay(state.day)) && boardHasDay(ghostDay)) return ghostDay;
        if (boardHasDay(state.day)) return state.day;
        if (!isPhone()) return null;
        return openingDay(api.board, currentNow());
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
        var pos = nowLine(api.board, currentNow());
        if (!pos) return;
        var body = state.rootEl.querySelector('.evb-day-body[data-day="' + pos.day + '"]');
        if (!body) return;
        var line = win.document.createElement('div');
        line.className = 'evb-now';
        line.setAttribute('aria-hidden', 'true');
        line.style.top = pos.top.toFixed(1) + 'px';
        body.appendChild(line);
    }

    // The "now and next" strip, in view mode only; hidden when there is nothing
    // on or next, or now is outside the event. Unchanged markup is left alone,
    // so an open popover and focus survive the minute timer.
    function renderStrip() {
        var el = state.stripEl;
        if (!el) return;
        var c = cfg();
        var r = state.editing ? null : C.nowNext(state.items, clockNow(), c.eventStart || '', c.eventEnd || '');
        var html = nowNextHtml(r, state.catsByName);
        if (html === state.stripHtml) return;
        var doc = win.document;
        var focusedId = el.contains(doc.activeElement) ? doc.activeElement.getAttribute('data-id') : null;
        if (state.popover && el.contains(state.popover.block)) closePopover(false);
        state.stripHtml = html;
        el.innerHTML = html;
        el.hidden = !html;
        if (focusedId === null) return;
        var again = el.querySelector('.evb-nownext-item[data-id="' + focusedId + '"]') || el.querySelector('.evb-nownext-item');
        if (again) again.focus({ preventScroll: true });
    }

    // Each rail's visible height in the board's frame, as --evb-rail-vis: its title
    // sticks to the top of that part and ends in an ellipsis with it (event-board.css).
    // The day bodies start under the day heads, so a body offset equals scrollTop
    // at the heads' bottom edge.
    var railFitQueued = false;
    var railObserver = null;
    function fitRailTitles() {
        railFitQueued = false;
        var scroller = state.rootEl && state.rootEl.querySelector('.evb-scroller');
        if (!scroller || !scroller.clientHeight) return; // the board is not on screen
        var viewTop = scroller.scrollTop, viewHeight = scroller.clientHeight - HEAD_PX;
        state.rootEl.querySelectorAll('.evb-day-body > .evb-block-rail').forEach(function (r) {
            var vis = railVisible(parseFloat(r.style.top) || 0, parseFloat(r.style.height) || 0, viewTop, viewHeight) + 'px';
            if (r.style.getPropertyValue('--evb-rail-vis') !== vis) r.style.setProperty('--evb-rail-vis', vis);
        });
    }
    function queueRailFit() {
        if (railFitQueued) return;
        railFitQueued = true;
        (win.requestAnimationFrame || win.setTimeout)(fitRailTitles);
    }
    function watchRails(scroller) {
        if (railObserver) { railObserver.disconnect(); railObserver = null; }
        if (!scroller) return;
        scroller.addEventListener('scroll', queueRailFit, { passive: true });
        // The frame's size changes when the board is shown, the view or day changes, or the window resizes.
        if (win.ResizeObserver) {
            railObserver = new win.ResizeObserver(queueRailFit);
            railObserver.observe(scroller);
        }
        fitRailTitles();
    }

    function render() {
        if (!state.rootEl) return;
        closePopover(false);
        var c = cfg();
        var ghosts = state.ghosts;
        var ph = state.editing ? state.placeholder : null;
        // A placeholder with a cid stands in for that item (it is being retyped in place).
        var items = ph && ph.cid ? state.items.filter(function (it) { return it.cid !== ph.cid; }) : state.items;
        // The block being typed stays in a lane even when its times make it ongoing.
        var board = C.buildBoard(items.concat(ghosts, ph ? [ph] : []), { eventStart: c.eventStart || '', eventEnd: c.eventEnd || '',
            editing: state.editing, notRail: ph });
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
        var kept = keptFilter(state.filter, present);
        state.filter = kept.filter;
        if (kept.status !== null && state.statusEl) state.statusEl.textContent = kept.status;

        var h = legendHtml(present) + dayPillsHtml(board);
        h += '<div class="evb-scroller"><div class="evb-board">';
        h += '<div class="evb-gutter"><div class="evb-head"></div><div class="evb-gutter-body" style="' + bodyStyle + '">';
        for (var m = board.axisStart; m < board.axisEnd; m += 60) {
            h += '<div class="evb-tick" style="top:' + ((m - board.axisStart) * PX_PER_MIN).toFixed(1) + 'px">' + esc(C.fmtTime(m)) + '</div>';
        }
        h += '</div></div>';
        board.days.forEach(function (d) {
            // Rails add their own width; the lanes share what is left.
            var railW = railWidth(d.railCount);
            var weight = Math.max(d.lanes, 1.4) + railW / LANE_MIN_PX;
            h += '<div class="evb-day" data-day="' + esc(d.day) + '" style="flex-grow:' + weight.toFixed(3) + ';min-width:' + (d.lanes * LANE_MIN_PX + railW) + 'px">';
            h += '<div class="evb-head">' + esc(C.dayLabelLong(d.day)) + '</div>';
            h += '<div class="evb-day-body" data-day="' + esc(d.day) + '" style="' + bodyStyle + ';--evb-rail-w:' + railW + 'px">';
            (d.rails || []).concat(d.segs).forEach(function (sg) {
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
        watchRails(scroller);
        applyFilter();
        applyDay();
        renderNow();
        renderStrip();
        reveal(state.rootEl.querySelector('.evb-ghost'));
        state.renderCbs.forEach(function (fn) {
            try { fn(board); } catch (e) { if (win.console) win.console.error(e); }
        });
    }

    // The legend dims every block whose primary and secondary category both differ
    // from the chosen one, and takes those out of the tab order and away from
    // assistive tech while the filter is on (ghosts and the placeholder stay hidden).
    // A selected block (Edit mode) or the focused one stays reachable, only dimmed.
    // focused: the element that has or is getting focus (default: the active one).
    function applyFilter(focused) {
        if (!state.rootEl) return;
        var f = state.filter;
        var active = focused === undefined ? win.document.activeElement : focused;
        state.rootEl.querySelectorAll('.evb-block').forEach(function (b) {
            var match = b.getAttribute('data-category') === f || b.getAttribute('data-secondary-category') === f;
            var dim = !!f && !match;
            b.classList.toggle('evb-dim', dim);
            if (b.getAttribute('role') !== 'button') return; // ghosts, the placeholder, drag previews
            var keep = b.classList.contains('evb-selected') || b === active;
            var act = filterAccess(dim, keep, b.getAttribute('aria-hidden') === 'true');
            if (act === 'hide') {
                b.setAttribute('tabindex', '-1');
                b.setAttribute('aria-hidden', 'true');
            } else if (act === 'show') {
                b.setAttribute('tabindex', '0');
                b.removeAttribute('aria-hidden');
            }
        });
        state.rootEl.querySelectorAll('.evb-legend-chip').forEach(function (chip) {
            chip.setAttribute('aria-pressed', chip.getAttribute('data-category') === f ? 'true' : 'false');
        });
    }

    function setFilter(category) {
        state.filter = category || null;
        applyFilter();
        if (state.statusEl) state.statusEl.textContent = filterStatus(state.items, state.catsByName, state.filter);
    }

    function setItems(items) {
        state.items = Array.isArray(items) ? items : [];
        render();
    }

    // Edit mode: the axis widens to 7am-midnight and a click on a block opens no popover.
    function setEditing(on) {
        state.editing = !!on;
        if (!state.editing) { state.ghosts = []; state.ghostKey = ''; state.placeholder = null; state.placeholderKey = ''; }
        if (state.rootEl) state.rootEl.classList.toggle('evb-editing', state.editing);
        render();
    }

    // A cheap key of the fields a ghost or the placeholder draws from: two lists
    // with the same key draw the same board.
    function drawKey(it) {
        if (!it) return '';
        return [it.cid || '', it.EventScheduleId || 0, it.StartTime || '', it.EndTime || '', it.Category || '',
            it.SecondaryCategory || '', it.Title || '', it.Location || '', it.Menu || '',
            (it.Leads || []).map(function (l) { return (l && l.Persona) || ''; }).join(',')].join('\u0001');
    }

    // Preview items, laid out with the real ones and drawn dashed; [] clears them.
    // The board grows to take in a ghost's day or time while it shows. A list
    // that draws the same as the one on screen changes nothing.
    function setGhosts(items) {
        var next = Array.isArray(items) ? items.slice() : [];
        var key = next.map(drawKey).join('\u0002');
        if (key === state.ghostKey && next.length === state.ghosts.length) return;
        state.ghosts = next;
        state.ghostKey = key;
        render();
    }

    // The block being typed on the board ({StartTime, EndTime, Category}), laid
    // out among the real ones as an invisible .evb-placeholder; null removes it.
    // With a cid it takes the place of that item, which is not drawn meanwhile.
    function setPlaceholder(item) {
        var key = drawKey(item);
        if (!item && !state.placeholder) return;
        if (item && state.placeholder && key === state.placeholderKey) return;
        state.placeholder = item || null;
        state.placeholderKey = key;
        render();
    }

    // Scroll the board's own frame (never the page) so a block (a ghost, the
    // placeholder) is in view.
    function reveal(g) {
        var scroller = g && g.offsetParent && state.rootEl && state.rootEl.querySelector('.evb-scroller');
        if (!scroller) return;
        var sr = scroller.getBoundingClientRect(), gr = g.getBoundingClientRect();
        var head = HEAD_PX, gutter = GUTTER_PX, pad = 12; // the sticky day heads and hour gutter cover the frame's edges
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

    // Everyone opens the edit modal from the popover through the list row's own edit
    // button, so the modal reads the row the page last updated (Modified included)
    // and its row lookup keeps working; the button needs no visible list view. Only
    // a feast editor's item with no such row (not yet on the page) is opened from the
    // board's own copy of the item.
    function openEditor(id) {
        var c = cfg();
        var row = win.document.getElementById('ev-schedule-row-' + id);
        var btn = row && row.querySelector('.ev-edit-link');
        if (btn) { btn.click(); return; }
        if (c.canManageSchedule) return;
        var item = findItem(id);
        if (item && popoverCanEdit(item, c) && typeof win.evOpenScheduleModalForItem === 'function') {
            win.evOpenScheduleModalForItem(item);
        }
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
        el.innerHTML = popoverHtml(item, state.catsByName, c.uir || '', { canEdit: popoverCanEdit(item, c) });
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

    // A fresh copy of the event page: {items, swap()}, where swap puts its List
    // and Feast tabs and their counts in place; null when the copy is not the
    // event page.
    function parsePage(html) {
        var doc = win.document;
        var fresh = new win.DOMParser().parseFromString(html, 'text/html');
        var data = fresh.getElementById('evb-schedule-data');
        if (!data || !fresh.getElementById('ev-schedule-container')) return null;
        var items = JSON.parse(data.textContent || '[]');
        return {
            items: items,
            swap: function () {
                ['ev-schedule-container', 'ev-meal-list'].forEach(function (id) {
                    var mine = doc.getElementById(id), theirs = fresh.getElementById(id);
                    if (mine && theirs) mine.innerHTML = theirs.innerHTML;
                });
                ['ev-schedule-empty', 'ev-meal-empty'].forEach(function (id) {
                    var mine = doc.getElementById(id), theirs = fresh.getElementById(id);
                    if (mine && theirs) mine.style.display = theirs.style.display;
                });
                ['ev-tab-schedule', 'ev-tab-feast'].forEach(function (tab) {
                    var sel = '[data-tab="' + tab + '"] .ev-tab-count';
                    var mine = doc.querySelector(sel), theirs = fresh.querySelector(sel);
                    if (mine && theirs) mine.textContent = theirs.textContent;
                });
                if (typeof win.evBuildScheduleFilters === 'function') win.evBuildScheduleFilters();
            }
        };
    }
    // Swap in the List and Feast tabs, their counts and the board's schedule
    // from a fresh copy of the page. Returns the schedule, or false when the copy
    // is not the event page.
    function swapFromPage(html) {
        var fresh = parsePage(html);
        if (!fresh) return false;
        fresh.swap();
        if (win.EvConfig) win.EvConfig.schedule = fresh.items;
        return fresh.items;
    }

    // The board's schedule after the person's own changes (refresher above):
    //   refreshPage()          after a staff change (evOnStaffChanged): the List's
    //                          server-rendered rows (their Leads) and the board
    //                          re-read the page; in Edit mode the store re-reads.
    //   refreshFromServer(ch)  after the schedule form or a List/Feast remove
    //                          (revised.js and the template call
    //                          evRefreshScheduleGrid): {saved: row} (the save's
    //                          reply row, in the page data's shape) or {removed:
    //                          id} merge in place with no request; anything else
    //                          re-reads schedule_list (EvBoardEdit.fetchList, only
    //                          loaded for schedule managers), else the page.
    var refreshes = null; // built on first use, when EvConfig and EvBoardEdit are in place
    function refreshing() {
        if (refreshes) return refreshes;
        var readPage = function () {
            return win.fetch(win.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); });
        };
        refreshes = refresher({
            readPage: readPage,
            parsePage: parsePage,
            readList: function () {
                var E = win.EvBoardEdit;
                return E && typeof E.fetchList === 'function' ? E.fetchList() : null;
            },
            take: function (items) {
                if (win.EvConfig) win.EvConfig.schedule = items;
                if (!state.editing) setItems(items);
            },
            items: function () { return state.items; },
            editing: function () { return state.editing && !!(win.EvBoardEdit && win.EvBoardEdit.refresh); },
            // The person's own change: the store merges without a "changed by someone else" notice.
            editRefresh: function () { win.EvBoardEdit.refresh({ own: true }); }
        });
        return refreshes;
    }
    function refreshPage() { refreshing().page(); }
    function refreshFromServer(change) { refreshing().change(change); }

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
        // The legend filter is announced here (outside #evb-root, which each render replaces).
        state.statusEl = doc.createElement('div');
        state.statusEl.className = 'evb-sr';
        state.statusEl.setAttribute('role', 'status');
        state.statusEl.setAttribute('aria-live', 'polite');
        state.rootEl.parentNode.insertBefore(state.statusEl, state.rootEl);
        // "Now and next" sits above both views: before the list's filter pills
        // (which come before the list and the board).
        state.stripEl = doc.createElement('div');
        state.stripEl.className = 'evb-nownext';
        state.stripEl.setAttribute('role', 'region');
        state.stripEl.setAttribute('aria-label', 'Happening now and next');
        state.stripEl.hidden = true;
        var stripAt = doc.getElementById('ev-sched-filters') || state.listEl;
        stripAt.parentNode.insertBefore(state.stripEl, stripAt);
        // An entry opens its item's popover, anchored to the entry.
        state.stripEl.addEventListener('click', function (e) {
            var entry = e.target.closest && e.target.closest('.evb-nownext-item');
            if (entry && !state.editing) openPopover(entry);
        });
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
        // A dimmed block that takes focus (a click, or the selection moving to it)
        // stays reachable while it has it.
        state.rootEl.addEventListener('focusin', function (e) { if (state.filter) applyFilter(e.target); });
        state.rootEl.addEventListener('focusout', function (e) { if (state.filter) applyFilter(e.relatedTarget || null); });
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
            if (!p || !e.target.closest || p.el.contains(e.target) || e.target.closest('.evb-block, .evb-nownext-item')) return;
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

        // Keep the now line (while the board is on screen) and the "now and
        // next" strip (in either view) current.
        win.setInterval(function () {
            if (doc.hidden) return;
            renderStrip();
            if (state.view === 'board') renderNow();
        }, 60000);
        // The interval skips hidden tabs, so catch up as soon as the tab is back.
        doc.addEventListener('visibilitychange', function () {
            if (!doc.hidden) { renderNow(); renderStrip(); }
        });

        win.evScheduleHashSuffix = function () {
            if (state.view !== 'board') return '';
            return '?view=board' + (boardHasDay(state.day) ? '&day=' + state.day.replace(/-/g, '') : '');
        };
        win.evRefreshScheduleGrid = refreshFromServer;
        if (typeof win.evOnStaffChanged === 'function') win.evOnStaffChanged(refreshPage);
    }

    api.init = init;
    api.render = render;
    api.setItems = setItems;
    api.setEditing = setEditing;
    api.isEditing = function () { return state.editing; };
    api.setGhosts = setGhosts;
    api.setPlaceholder = setPlaceholder;
    // A category's bold fill; unknown names fall back to Other's.
    api.boldFor = function (name) { return boldOf(catName(name, state.catsByName), state.catsByName); };
    api.reveal = reveal;
    api.swapFromPage = swapFromPage;
    api.getItems = function () { return state.items; };
    api.setView = setView;
    api.getView = getView;
    api.setFilter = setFilter;
    api.applyFilter = function () { applyFilter(); };
    api.setDay = setDay;
    api.getDay = resolvedDay;
    // fn returns a naive 'YYYY-MM-DD HH:MM' for "now"; null restores the device clock.
    api.setClock = function (fn) {
        clockFn = typeof fn === 'function' ? fn : deviceClock;
        if (state.rootEl && api.board) { applyDay(); renderNow(); renderStrip(); }
    };
    api.onRender = function (fn) { if (typeof fn === 'function') state.renderCbs.push(fn); };
    return api;
});
