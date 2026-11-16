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
            cursor = windowEnd;
            dayNum += 1;
        }
        out[out.length - 1].isLast = true;
        return out;
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
        segments: segments
    };
});
