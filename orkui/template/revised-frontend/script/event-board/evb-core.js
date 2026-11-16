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

    /* ---------------------------------------------------------------------
     * Entry grammar: one typed line to an item, and an item back to a line.
     * ------------------------------------------------------------------- */

    var CATEGORY_NAMES = ['Administrative', 'Tournament', 'Battlegame', 'Arts and Sciences', 'Class',
        'Feast and Food', 'Court', 'Meeting', 'Roleplay and Quests', 'Other'];
    var CATEGORY_ALIASES = {
        'Administrative': ['admin'],
        'Tournament': ['tourney'],
        'Battlegame': ['bg', 'battle game'],
        'Arts and Sciences': ['as', 'a&s'],
        'Class': [],
        'Feast and Food': ['feast', 'food'],
        'Court': [],
        'Meeting': [],
        'Roleplay and Quests': ['rp', 'roleplay', 'quest'],
        'Other': []
    };
    var CATEGORY_TAGS = {
        'Administrative': 'admin', 'Tournament': 'tourney', 'Battlegame': 'bg', 'Arts and Sciences': 'as',
        'Class': 'class', 'Feast and Food': 'feast', 'Court': 'court', 'Meeting': 'meeting',
        'Roleplay and Quests': 'rp', 'Other': 'other'
    };
    // Keyword guesses, first category to match wins. Whole words, optional trailing 's'.
    var CATEGORY_KEYWORDS = [
        ['Arts and Sciences', ['a&s', 'arts and sciences', 'judging', 'dragonmaster']],
        ['Feast and Food', ['feast', 'dinner', 'lunch', 'breakfast', 'brunch', 'potluck', 'tavern']],
        ['Court', ['court', 'coronation', 'knighting', 'ceremony', 'oath']],
        ['Tournament', ['tournament', 'tourney', 'warmaster', 'weaponmaster', 'bracket']],
        ['Class', ['class', 'workshop', 'seminar']],
        ['Roleplay and Quests', ['quest', 'rp', 'roleplay', 'role play']],
        ['Battlegame', ['battlegame', 'battle game', 'bg', 'militia', 'ditch']],
        ['Meeting', ['meeting', 'althing', 'allthing']],
        ['Administrative', ['troll', 'gate', 'setup', 'set up', 'clean', 'cleanup', 'teardown', 'tear down',
            'check-in', 'checkin', 'offsite', 'crat']]
    ].map(function (row) {
        var alts = row[1].map(function (k) {
            return k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/ /g, '\\s+');
        });
        return { category: row[0], re: new RegExp('(?:^|[^a-z0-9&])(?:' + alts.join('|') + ')s?(?![a-z0-9&])', 'i') };
    });

    function squash(s) { return String(s).toLowerCase().replace(/\s+/g, ' ').trim(); }

    // A category name or alias typed in full, or null.
    function categoryByName(text) {
        var t = squash(text);
        for (var i = 0; i < CATEGORY_NAMES.length; i++) {
            var name = CATEGORY_NAMES[i];
            if (t === name.toLowerCase() || CATEGORY_ALIASES[name].indexOf(t) >= 0) return name;
        }
        return null;
    }

    // '#tag' (without the '#') to a category: a name, an alias, or a unique
    // prefix of a name.
    function resolveCategoryTag(tag) {
        var t = squash(tag || '').replace(/^#/, '');
        if (!t) return null;
        var exact = categoryByName(t);
        if (exact) return exact;
        var flat = t.replace(/ /g, '');
        var hits = CATEGORY_NAMES.filter(function (name) {
            return name.toLowerCase().replace(/ /g, '').indexOf(flat) === 0;
        });
        return hits.length === 1 ? hits[0] : null;
    }

    // A leading 'Name:' prefix sets the category and is removed; otherwise the
    // first keyword match. The title is otherwise returned unchanged.
    function guessCategory(title) {
        var t = String(title == null ? '' : title);
        var m = /^\s*([^:]{1,40}):\s*/.exec(t);
        if (m) {
            var named = categoryByName(m[1]);
            if (named) return { category: named, title: t.slice(m[0].length) };
        }
        for (var i = 0; i < CATEGORY_KEYWORDS.length; i++) {
            if (CATEGORY_KEYWORDS[i].re.test(t)) return { category: CATEGORY_KEYWORDS[i].category, title: t };
        }
        return { category: null, title: t };
    }

    // Tokens. The scanner yields quoted spans and whitespace-separated words; a
    // word is then classified by the first matching TOKEN_KINDS entry, so a new
    // kind of token is one more entry here. Anything else is a plain 'word'.
    var QUOTE_CLOSE = { '"': '"', '\u201C': '\u201D', '\u201D': '\u201D' };
    var TOKEN_KINDS = [
        { kind: 'tag', re: /^#(\S+)$/ },
        { kind: 'cost', re: /^\$(\d+(?:\.\d{1,2})?)$/ }
    ];

    function tokenize(text) {
        var out = [], i = 0, n = text.length;
        while (i < n) {
            var ch = text.charAt(i);
            if (/\s/.test(ch)) { i++; continue; }
            if (QUOTE_CLOSE[ch]) {
                var close = text.indexOf(QUOTE_CLOSE[ch], i + 1);
                var end = close < 0 ? n : close + 1;
                out.push({ kind: 'quote', text: text.slice(i, end), value: text.slice(i + 1, close < 0 ? n : close).trim() });
                i = end;
                continue;
            }
            var j = i;
            while (j < n && !/\s/.test(text.charAt(j))) j++;
            var word = text.slice(i, j), tok = { kind: 'word', text: word, value: word };
            for (var k = 0; k < TOKEN_KINDS.length; k++) {
                var m = TOKEN_KINDS[k].re.exec(word);
                if (m) { tok.kind = TOKEN_KINDS[k].kind; tok.value = m[1]; break; }
            }
            out.push(tok);
            i = j;
        }
        out.forEach(function (t) { t.claimed = false; });
        return out;
    }

    var WEEKDAY_RE = /^(sun|mon|tue|wed|thu|fri|sat)(?:day|sday|nesday|rsday|urday)?:?$/i;

    function weekdayOf(word) {
        var m = WEEKDAY_RE.exec(word);
        if (!m) return -1;
        var idx = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'].indexOf(m[1].toLowerCase());
        // Accept only the three-letter form or the real full name.
        var bare = word.replace(/:$/, '').toLowerCase();
        return bare.length === 3 || bare === WEEKDAYS[idx].toLowerCase() ? idx : -1;
    }

    // The board day a day word names, or null.
    function resolveDayWord(word, days) {
        if (!days.length) return null;
        var wd = weekdayOf(word), i;
        if (wd >= 0) {
            for (i = 0; i < days.length; i++) if (weekdayIndex(days[i]) === wd) return days[i];
            var d = addDays(days[0], 1);
            while (weekdayIndex(d) !== wd) d = addDays(d, 1);
            return d;
        }
        var m = /^(\d{1,2})\/(\d{1,2}):?$/.exec(word);
        if (!m) return null;
        var mo = +m[1], da = +m[2], y = dayParts(days[0]).y;
        if (mo < 1 || mo > 12 || da < 1 || da > 31) return null;
        var day = y + '-' + pad2(mo) + '-' + pad2(da);
        if (addDays(day, 0) !== day) return null; // 2/30 and the like
        // A date far before the event means next year's.
        if (diffDays(day, days[0]) > 180) day = (y + 1) + '-' + pad2(mo) + '-' + pad2(da);
        return day;
    }

    // One side of a time: { min, marked, mk, h, mi }. 'marked' means the side
    // needs no am/pm guess (a written marker, 24-hour form, noon or midnight).
    var SIDE_RE = /^(?:(noon)|(midnight)|(\d{1,2})(?::(\d{2}))?(am|pm|a|p)?)$/i;

    function readSide(text) {
        var m = SIDE_RE.exec(text);
        if (!m) return null;
        if (m[1]) return { min: 720, marked: true, mk: 'p', strong: true };
        if (m[2]) return { min: 0, marked: true, mk: 'a', strong: true };
        var h = +m[3], mi = m[4] ? +m[4] : 0, mk = m[5] ? m[5].charAt(0).toLowerCase() : null;
        if (mi > 59) return null;
        if (mk) {
            if (h < 1 || h > 12) return null;
            return { min: (h % 12 + (mk === 'p' ? 12 : 0)) * 60 + mi, marked: true, mk: mk, strong: true };
        }
        if (h > 23) return null;
        if (h === 0 || h > 12) return { min: h * 60 + mi, marked: true, mk: h < 12 ? 'a' : 'p', strong: !!m[4] };
        return { h: h, mi: mi, marked: false, mk: null, strong: !!m[4] };
    }

    function withMarker(side, mk) { return (side.h % 12 + (mk === 'p' ? 12 : 0)) * 60 + side.mi; }

    // Unmarked hours: 7 to 11 are morning, 12 and 1 to 6 afternoon.
    function defaultMin(side) { return withMarker(side, side.h >= 7 && side.h <= 11 ? 'a' : 'p'); }

    function sideMin(side) { return side.marked ? side.min : defaultMin(side); }

    function other(mk) { return mk === 'a' ? 'p' : 'a'; }

    // Hours before 5am belong to the night of the board day.
    function nightShift(min) { return min < BOARD_DAY_START ? min + DAY_MIN : min; }

    function resolveRange(a, b) {
        var s, e, m;
        if (a.marked === b.marked) {
            s = sideMin(a); e = sideMin(b);
        } else if (!a.marked) {
            e = b.min; m = b.mk;
            s = withMarker(a, m);
            if (s > e) s = withMarker(a, other(m));
        } else {
            s = a.min; m = a.mk;
            e = withMarker(b, m);
            if (s > e) e = withMarker(b, other(m));
        }
        // The same time on both sides is a zero-length item, not a whole day.
        if (s === e) { s = nightShift(s); return { start: s, end: s }; }
        s = nightShift(s);
        if (e <= s && !b.marked && e + 720 > s) e += 720;
        while (e <= s) e += DAY_MIN;
        return { start: s, end: e };
    }

    var LENGTH_RE = /^(?:(\d+(?:\.\d+)?)h(?:(\d+)m)?|(\d+)m|\+(\d+))$/i;

    // Minutes, or null. Anything over a day ('99h') is not a length but text.
    function readLength(text) {
        var m = LENGTH_RE.exec(text);
        if (!m) return null;
        var len = m[1] ? Math.round(parseFloat(m[1]) * 60) + (m[2] ? +m[2] : 0) : +(m[3] || m[4]);
        return len > DAY_MIN ? null : len;
    }

    var RANGE_JOINERS = ['-', '\u2013', 'to'];
    var MARKER_WORDS = ['am', 'pm'];

    function isWord(t) { return t && t.kind === 'word' && !t.claimed; }

    // A time side starting at token i, with an optional separate 'am'/'pm'
    // word after it: { side, next } or null.
    function sideAt(toks, i) {
        if (!isWord(toks[i])) return null;
        var side = readSide(toks[i].value);
        if (!side) return null;
        var next = i + 1;
        if (!side.marked && side.h >= 1 && isWord(toks[next]) && MARKER_WORDS.indexOf(toks[next].value.toLowerCase()) >= 0) {
            side = readSide(toks[i].value + toks[next].value);
            if (!side) return null;
            next++;
        }
        return { side: side, next: next };
    }

    // A range starting at token i: { start, end, next, bare } or null.
    function rangeAt(toks, i) {
        if (!isWord(toks[i])) return null;
        var one = /^(.+?)[-\u2013](.+)$/.exec(toks[i].value), a, b, next;
        if (one) {
            a = readSide(one[1]); b = readSide(one[2]); next = i + 1;
            if (a && b && !b.marked && isWord(toks[next]) && MARKER_WORDS.indexOf(toks[next].value.toLowerCase()) >= 0) {
                b = readSide(one[2] + toks[next].value); next++;
            }
        } else {
            var left = sideAt(toks, i);
            if (!left || !isWord(toks[left.next]) || RANGE_JOINERS.indexOf(toks[left.next].value.toLowerCase()) < 0) return null;
            var right = sideAt(toks, left.next + 1);
            if (!right) return null;
            a = left.side; b = right.side; next = right.next;
        }
        if (!a || !b) return null;
        var r = resolveRange(a, b);
        r.next = next;
        r.bare = !a.strong && !b.strong; // no colon and no marker on either side
        return r;
    }

    function isAt(t) { return isWord(t) && t.value.toLowerCase() === 'at'; }

    // A bare hour from 1 to 12: no colon, no marker ('6' in 'Court at 6').
    function bareHourAt(toks, i) {
        if (!isWord(toks[i])) return null;
        var side = readSide(toks[i].value);
        return side && !side.marked && !side.strong ? side : null;
    }

    // The time that starts at token i: { found, upto, weak } or null. 'weak'
    // marks a lone 'noon' or 'midnight', which yields to any other time.
    // inBlock (typing inside a block, which already has a time): a lone noon or
    // midnight is a time only straight after 'at' ('Feast at noon', not 'Midnight Madness').
    function timeAt(toks, i, firstIdx, lastIdx, inBlock) {
        var r = rangeAt(toks, i);
        if (r && (!r.bare || i === firstIdx || r.next - 1 === lastIdx)) {
            return { found: { start: r.start, end: r.end, length: null, then: false }, upto: r.next };
        }
        var sa = sideAt(toks, i);
        if (sa && sa.side.strong) {
            var word = /^[a-z]+$/i.test(toks[i].value);
            // Mid-title ('the Midnight Madness') a lone noon or midnight is a word.
            var edge = !inBlock && (i === firstIdx || sa.next - 1 === lastIdx);
            if (word && !edge && !isAt(toks[i - 1])) return null;
            return { found: { start: nightShift(sideMin(sa.side)), end: null, length: null, then: false }, upto: sa.next, weak: word };
        }
        var len = readLength(toks[i].value);
        if (len !== null) return { found: { start: null, end: null, length: len, then: false }, upto: i + 1 };
        if (i === firstIdx && toks[i].value.toLowerCase() === 'then') {
            return { found: { start: null, end: null, length: null, then: true }, upto: i + 1 };
        }
        return null;
    }

    // Find the item's time among the tokens and claim it. Returns
    // { start, end, length, then } (any may be null) or null.
    function claimTime(toks, firstIdx, lastIdx, inBlock) {
        var hit = null, i;
        for (i = 0; i < toks.length; i++) {
            if (!isWord(toks[i])) continue;
            var t = timeAt(toks, i, firstIdx, lastIdx, inBlock);
            if (!t) continue;
            if (!t.weak) { hit = { at: i, t: t }; break; }
            if (!hit) hit = { at: i, t: t };
        }
        // Only when the line has no other time: 'at' plus a bare hour, 'Court at 6'.
        for (i = 0; !hit && i < toks.length; i++) {
            var hour = isAt(toks[i - 1]) && bareHourAt(toks, i);
            if (hour) hit = { at: i, t: { found: { start: nightShift(defaultMin(hour)), end: null, length: null, then: false }, upto: i + 1 } };
        }
        if (!hit) return null;
        var found = hit.t.found, upto = hit.t.upto;
        for (i = hit.at; i < upto; i++) toks[i].claimed = true;
        // 'at 6pm': the 'at' belongs to the time, not to a location.
        if (found.start !== null && isAt(toks[hit.at - 1])) toks[hit.at - 1].claimed = true;
        // A start or 'then' may be followed by a length: '2p 45m', 'then 30m'.
        if (found.end === null && found.length === null && isWord(toks[upto]) && readLength(toks[upto].value) !== null) {
            found.length = readLength(toks[upto].value);
            toks[upto].claimed = true;
        }
        return found;
    }

    // Location: the last free standalone 'at' and the one to six free words
    // after it, stopping at the end, a non-word token ('#', '$', a quote) or a
    // token another rule claimed.
    function claimLocation(toks, locations) {
        for (var i = toks.length - 1; i >= 0; i--) {
            if (!isAt(toks[i])) continue;
            var after = sideAt(toks, i + 1);
            // Followed by a time ('at 3-4', 'at 5p') or a bare hour ('at 6'): never a location.
            if (rangeAt(toks, i + 1) || after && after.side.strong || bareHourAt(toks, i + 1)) continue;
            var j = i + 1;
            while (j < toks.length && isWord(toks[j]) && !/^[#$]/.test(toks[j].value)) j++;
            var count = j - i - 1;
            if (count < 1 || count > 6) return '';
            var words = [];
            for (var k = i; k < j; k++) { toks[k].claimed = true; if (k > i) words.push(toks[k].value); }
            var loc = words.join(' ');
            var known = (locations || []).filter(function (l) { return squash(l) === squash(loc); });
            return known.length ? known[0] : loc;
        }
        return '';
    }

    function parseEntry(text, ctx) {
        ctx = ctx || {};
        var days = ctx.days || [];
        var ign = ctx.ignore || {};
        var curDay = ctx.day || days[0] || null;
        var lastDuration = ctx.lastDuration == null ? 60 : ctx.lastDuration;
        var hasFixed = ctx.fixedStart != null && ctx.fixedEnd != null;
        var toks = tokenize(String(text == null ? '' : text));
        var given = { day: false, time: false, category: false, location: false, description: false, cost: false };
        var i;

        // Day: only the first token.
        var typedDay = null;
        if (!ctx.fixedDay && !ign.day && isWord(toks[0])) {
            typedDay = resolveDayWord(toks[0].value, days.length ? days : (curDay ? [curDay] : []));
            if (typedDay) { toks[0].claimed = true; given.day = true; }
        }
        // The whole line is a day name: the caller switches days instead of adding.
        var setDay = typedDay && toks.length === 1 && weekdayOf(toks[0].value) >= 0 ? typedDay : null;

        // Time.
        var firstIdx = -1, lastIdx = -1;
        for (i = 0; i < toks.length; i++) {
            if (toks[i].claimed || toks[i].kind === 'quote') continue;
            if (firstIdx < 0 && toks[i].kind === 'word') firstIdx = i;
            lastIdx = i;
        }
        var time = ign.time ? null : claimTime(toks, firstIdx, lastIdx, ctx.fixedStart != null);
        given.time = !!time;

        // Description: the last quoted span with free title text before it.
        if (!ign.description) {
            for (i = toks.length - 1; i >= 0; i--) {
                if (toks[i].kind !== 'quote') continue;
                var hasText = toks.slice(0, i).some(function (t) { return t.kind === 'word' && !t.claimed; });
                if (hasText) { toks[i].claimed = true; given.description = true; }
                break;
            }
        }
        var description = '';
        toks.forEach(function (t) { if (t.kind === 'quote' && t.claimed) description = t.value.replace(/\s+/g, ' ').trim(); });

        // Tags: the first resolvable is the category, the second the secondary.
        var category = null, secondary = '', tagCount = 0;
        if (!ign.category) {
            toks.forEach(function (t) {
                if (t.kind !== 'tag' || t.claimed || tagCount === 2) return;
                var c = resolveCategoryTag(t.value);
                if (!c) return;
                t.claimed = true;
                if (c === category) return; // a repeat of the category tag
                if (++tagCount === 1) category = c;
                else secondary = c;
            });
        }
        given.category = !!category;

        var location = ign.location ? '' : claimLocation(toks, ctx.locations);
        given.location = location !== '';

        var costTok = null;
        toks.forEach(function (t) { if (!costTok && t.kind === 'cost' && !t.claimed) costTok = t; });
        if (costTok) costTok.claimed = true; // held back; returned to the title below if it is not a cost

        var titleOf = function () {
            return toks.filter(function (t) { return !t.claimed; })
                .map(function (t) { return t.text; }).join(' ').replace(/\s+/g, ' ').trim();
        };
        var guessing = !category && !ign.category;

        // Cost: decided on the category the title would get without the '$' token.
        var cost = null;
        if (costTok) {
            var pre = guessing ? guessCategory(titleOf()).category : category;
            var feast = pre === 'Feast and Food' || secondary === 'Feast and Food';
            if (ctx.canFeast && !ign.cost && feast) {
                cost = parseFloat(costTok.value);
                given.cost = true;
            } else {
                costTok.claimed = false; // not a cost: it is title text
            }
        }

        // Title, then the category guess on the final title (a typed 'Name:'
        // prefix counts as given).
        var title = titleOf();
        if (guessing) {
            var g = guessCategory(title);
            category = g.category;
            if (g.title !== title) { title = g.title.replace(/\s+/g, ' ').trim(); given.category = true; }
        }
        category = category || 'Other';

        // Day and times.
        var day = ctx.fixedDay || typedDay || null;
        var startMin, endMin, len = hasFixed ? ctx.fixedEnd - ctx.fixedStart : lastDuration;
        var lastEnd = ctx.lastEnd || null;
        if (!time) {
            if (hasFixed) {
                day = day || curDay; startMin = ctx.fixedStart; endMin = ctx.fixedEnd;
            } else if (lastEnd && (!day || day === lastEnd.day)) {
                day = lastEnd.day; startMin = lastEnd.min; endMin = startMin + lastDuration;
            } else {
                day = day || curDay; startMin = 540; endMin = startMin + lastDuration;
            }
        } else if (time.start === null) {
            // A length alone, or 'then': start where the last item ended, unless
            // a typed day names another day.
            if (lastEnd && (!day || day === lastEnd.day)) { day = lastEnd.day; startMin = lastEnd.min; }
            else { day = day || curDay; startMin = ctx.fixedStart != null ? ctx.fixedStart : 540; }
            endMin = startMin + (time.length !== null ? time.length : lastDuration);
        } else {
            day = day || curDay;
            startMin = time.start;
            endMin = time.end !== null ? time.end : startMin + (time.length !== null ? time.length : len);
        }

        return {
            ok: title !== '',
            setDay: setDay,
            day: day,
            startMin: startMin,
            endMin: endMin,
            start: day ? toNaive(day, startMin) : null,
            end: day ? toNaive(day, endMin) : null,
            title: title,
            category: category,
            secondaryCategory: secondary,
            location: location,
            description: description,
            cost: cost,
            given: given
        };
    }

    // An item as a line parseEntry reads back: '<range> [#tag ]<Title>[ at
    // <Location>][ "<Description>"]'. opts.withDay prefixes the weekday.
    function formatEntry(item, opts) {
        opts = opts || {};
        var segs = segments(item);
        if (!segs.length) return '';
        var boardDay = segs[0].day, startMin = segs[0].startMin;
        var e = parseNaive(item.EndTime);
        var endMin = e ? diffDays(boardDay, e.day) * DAY_MIN + e.min : startMin;
        if (endMin < startMin) endMin = startMin;
        // A zero-length item reads back as one through an explicit '0m'.
        var out = endMin === startMin ? fmtTime(startMin) + ' 0m' : fmtRange(startMin, endMin);
        if (opts.withDay) out = dayLabel(boardDay).slice(0, 3).toLowerCase() + ' ' + out;
        var title = String(item.Title || '');
        var cat = item.Category && CATEGORY_TAGS[item.Category] ? item.Category : 'Other';
        var g = guessCategory(title);
        // Tag when the title alone would read back as another category, or would lose a prefix.
        if ((g.category || 'Other') !== cat || g.title !== title) out += ' #' + CATEGORY_TAGS[cat];
        if (title) out += ' ' + title;
        if (item.Location) out += ' at ' + item.Location;
        if (item.Description) {
            var d = String(item.Description);
            out += d.indexOf('"') >= 0 ? ' \u201C' + d + '\u201D' : ' "' + d + '"';
        }
        return out;
    }

    // The start of a line naming a board slot, ready for a title: 'Friday 1:45p '
    // or, with an end, 'Friday 1:45–2:45p '. parseEntry reads it back to the
    // same board day and minutes (an end equal to the start reads as '0m').
    function slotLine(day, startMin, endMin) {
        var t = endMin == null ? fmtTime(startMin)
            : endMin === startMin ? fmtTime(startMin) + ' 0m' : fmtRange(startMin, endMin);
        return dayLabel(day) + ' ' + t + ' ';
    }

    return {
        BOARD_DAY_START: BOARD_DAY_START,
        CATEGORY_NAMES: CATEGORY_NAMES,
        CATEGORY_TAGS: CATEGORY_TAGS,
        resolveCategoryTag: resolveCategoryTag,
        guessCategory: guessCategory,
        parseEntry: parseEntry,
        formatEntry: formatEntry,
        slotLine: slotLine,
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
