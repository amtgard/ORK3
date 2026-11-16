/*
 * EvBoardCore: pure-logic helpers for the Event Schedule Board.
 *
 * Schedule times are naive local datetimes. Nothing here builds a Date from a
 * schedule time and reads fields back; time is integer minutes and days are
 * 'YYYY-MM-DD' strings. Date.UTC is used only for whole-day arithmetic and
 * weekday lookup. No DOM access.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.EvBoardCore = factory();
})(this, function () {
    'use strict';

    var BOARD_DAY_START = 300; // 5:00am: the board day begins here
    var DAY_MIN = 1440;
    var MAX_SEGMENTS = 31; // an item never spans more than this many board days
    var MAX_DAYS = 62;     // a board never draws more than this many event days
    var MIN_LAYOUT = 15;   // layout treats shorter items as this long, so they hold a lane
    var WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    function dayParts(day) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day);
        return { y: +m[1], m: +m[2], d: +m[3] };
    }

    // Whole-day count since the epoch for a 'YYYY-MM-DD' string (UTC, so no DST).
    function dayNumber(day) {
        var p = dayParts(day);
        return Math.round(Date.UTC(p.y, p.m - 1, p.d) / 86400000);
    }

    function parseNaive(str) {
        if (typeof str !== 'string') return null;
        var m = /^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}):(\d{2})(?::\d{2})?$/.exec(str.trim());
        if (!m) return null;
        var h = +m[2], mi = +m[3];
        if (h > 23 || mi > 59) return null;
        return { day: m[1], min: h * 60 + mi };
    }

    function addDays(day, n) {
        var p = dayParts(day);
        var d = new Date(Date.UTC(p.y, p.m - 1, p.d + n));
        return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate());
    }

    function diffDays(a, b) { return dayNumber(b) - dayNumber(a); }

    function toNaive(day, min) {
        var dayShift = Math.floor(min / DAY_MIN);
        var rem = min - dayShift * DAY_MIN;
        return addDays(day, dayShift) + ' ' + pad2(Math.floor(rem / 60)) + ':' + pad2(rem % 60);
    }

    function weekdayIndex(day) {
        var p = dayParts(day);
        return new Date(Date.UTC(p.y, p.m - 1, p.d)).getUTCDay();
    }

    function dayLabel(day) { return WEEKDAYS[weekdayIndex(day)]; }

    function dayLabelLong(day) {
        return dayLabel(day) + ', ' + MONTHS[dayParts(day).m - 1] + ' ' + dayParts(day).d;
    }

    function wrap(min) { return ((min % DAY_MIN) + DAY_MIN) % DAY_MIN; }

    function meridiem(min) { return wrap(min) < 720 ? 'a' : 'p'; }

    // Hour and minute without the meridiem: '9:30', '12'.
    function clock(min) {
        var w = wrap(min);
        var h = Math.floor(w / 60) % 12;
        if (h === 0) h = 12;
        var mi = w % 60;
        return mi === 0 ? String(h) : h + ':' + pad2(mi);
    }

    function fmtTime(min) { return clock(min) + meridiem(min); }

    function fmtRange(startMin, endMin) {
        if (endMin === startMin) return fmtTime(startMin);
        if (meridiem(startMin) === meridiem(endMin)) {
            return clock(startMin) + '–' + clock(endMin) + meridiem(endMin);
        }
        return fmtTime(startMin) + '–' + fmtTime(endMin);
    }

    // Split an item into one segment per board day. A board day runs from 5am to
    // 5am, so minutes are measured from the board day's own 00:00 and lie in
    // [300, 1740].
    function segments(item) {
        var s = parseNaive(item.StartTime);
        if (!s) return [];
        var e = parseNaive(item.EndTime);
        var absStart = dayNumber(s.day) * DAY_MIN + s.min;
        var absEnd = e ? dayNumber(e.day) * DAY_MIN + e.min : absStart;
        if (absEnd < absStart) absEnd = absStart;

        // First board day: the day whose 5am-to-5am window holds the start.
        var firstDayNum = Math.floor((absStart - BOARD_DAY_START) / DAY_MIN);
        var firstDay = addDays('1970-01-01', firstDayNum);
        var out = [];
        var dayNum = firstDayNum;
        var cursor = absStart;
        while (true) {
            var windowEnd = (dayNum + 1) * DAY_MIN + BOARD_DAY_START;
            var segEnd = Math.min(absEnd, windowEnd);
            out.push({
                item: item,
                day: addDays(firstDay, dayNum - firstDayNum),
                startMin: cursor - dayNum * DAY_MIN,
                endMin: segEnd - dayNum * DAY_MIN,
                isFirst: out.length === 0,
                isLast: false
            });
            if (absEnd <= windowEnd) break;
            if (out.length >= MAX_SEGMENTS) return out; // absurd data: the last segment is not the true last
            cursor = windowEnd;
            dayNum += 1;
        }
        out[out.length - 1].isLast = true;
        return out;
    }

    function categoryOf(seg) { return seg.item.Category || 'Other'; }

    function layEnd(seg) { return Math.max(seg.endMin, seg.startMin + MIN_LAYOUT); }

    // Lay one day's segments out in lanes. Clusters of overlapping segments share
    // lanes; a segment keeps to a lane last used by its own category when one is
    // free. Adds lane, left and width (percent of the day) to each segment and
    // returns the day's lane count.
    function layoutDay(segs) {
        var clusters = [], cur = [], curEnd = null;
        segs.sort(function (a, b) { return (a.startMin - b.startMin) || (layEnd(a) - layEnd(b)); });
        segs.forEach(function (sg) {
            if (cur.length && sg.startMin >= curEnd) { clusters.push(cur); cur = []; curEnd = null; }
            cur.push(sg);
            curEnd = curEnd === null ? layEnd(sg) : Math.max(curEnd, layEnd(sg));
        });
        if (cur.length) clusters.push(cur);

        var maxLanes = 0;
        clusters.forEach(function (cl) {
            var lanes = []; // { end, cat }
            cl.forEach(function (sg) {
                var pick = -1, i, cat = categoryOf(sg);
                for (i = 0; i < lanes.length; i++) {
                    if (lanes[i].end <= sg.startMin && lanes[i].cat === cat) { pick = i; break; }
                }
                if (pick < 0) {
                    for (i = 0; i < lanes.length; i++) {
                        if (lanes[i].end > sg.startMin) continue;
                        // Skip a lane whose own category needs it again before this segment ends.
                        var claimed = cl.some(function (o) {
                            return o !== sg && o.startMin >= sg.startMin && o.startMin < layEnd(sg) &&
                                categoryOf(o) === lanes[i].cat && categoryOf(o) !== cat;
                        });
                        if (!claimed) { pick = i; break; }
                    }
                }
                if (pick < 0) { lanes.push({ end: 0, cat: cat }); pick = lanes.length - 1; }
                lanes[pick].end = layEnd(sg);
                lanes[pick].cat = cat;
                sg.lane = pick;
            });
            var n = lanes.length;
            maxLanes = Math.max(maxLanes, n);
            cl.forEach(function (sg) {
                var span = 1;
                for (var c = sg.lane + 1; c < n; c++) {
                    var blocked = cl.some(function (o) {
                        return o.lane === c && o.startMin < layEnd(sg) && layEnd(o) > sg.startMin;
                    });
                    if (blocked) break;
                    span++;
                }
                sg.left = sg.lane / n * 100;
                sg.width = span / n * 100;
            });
        });
        return maxLanes;
    }

    // Turn schedule items into day columns with laid-out segments and the time
    // axis. opts = { eventStart, eventEnd, editing }; the dates are naive strings
    // or empty.
    function buildBoard(items, opts) {
        var byDay = {};
        var lo = null, hi = null;
        (items || []).forEach(function (item) {
            segments(item).forEach(function (sg) {
                (byDay[sg.day] = byDay[sg.day] || []).push(sg);
                lo = lo === null ? sg.startMin : Math.min(lo, sg.startMin);
                // Blocks draw at least MIN_LAYOUT tall, so the axis must reach that far.
                hi = hi === null ? layEnd(sg) : Math.max(hi, layEnd(sg));
            });
        });

        var evStart = parseNaive(opts.eventStart), evEnd = parseNaive(opts.eventEnd);
        if (evStart && evEnd) {
            // An end before the start still shows the start day.
            var count = Math.max(0, Math.min(diffDays(evStart.day, evEnd.day), MAX_DAYS - 1));
            for (var i = 0; i <= count; i++) {
                var d = addDays(evStart.day, i);
                byDay[d] = byDay[d] || [];
            }
        }

        var days = Object.keys(byDay).sort().map(function (day) {
            var segs = byDay[day];
            // An empty day still draws one lane.
            return { day: day, label: dayLabel(day), segs: segs, lanes: Math.max(1, layoutDay(segs)) };
        });

        var axisStart = lo === null ? 540 : Math.floor(lo / 60) * 60;
        var axisEnd = hi === null ? 1080 : Math.ceil(hi / 60) * 60;
        if (opts.editing) {
            axisStart = Math.min(axisStart, 420);
            axisEnd = Math.max(axisEnd, 1440);
        }
        return { days: days, axisStart: axisStart, axisEnd: axisEnd };
    }

    return {
        BOARD_DAY_START: BOARD_DAY_START,
        parseNaive: parseNaive,
        toNaive: toNaive,
        addDays: addDays,
        diffDays: diffDays,
        dayLabel: dayLabel,
        dayLabelLong: dayLabelLong,
        fmtTime: fmtTime,
        fmtRange: fmtRange,
        segments: segments,
        layoutDay: layoutDay,
        buildBoard: buildBoard
    };
});
