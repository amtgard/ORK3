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

    // A board segment that starts after midnight: the calendar day it really
    // falls on and the night it belongs to, or null before midnight.
    // lateNight('2026-11-21', 1500) -> { day: 'Sunday', dayISO: '2026-11-22', night: 'Saturday night' }
    function lateNight(boardDay, startMin) {
        if (!(startMin >= DAY_MIN)) return null;
        var real = addDays(boardDay, Math.floor(startMin / DAY_MIN));
        return { day: dayLabel(real), dayISO: real, night: dayLabel(boardDay) + ' night' };
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

    // The word for the board day an end in day's minutes falls on, or null when
    // it falls on day itself: the weekday ('Sun', or 'sun' when lower), or 'M/D'
    // when a week or more on. fmtSpan and formatEntry both name it this way.
    function endDayWord(day, endMin, lower) {
        if (endMin <= DAY_MIN + BOARD_DAY_START) return null;
        var endDay = addDays(day, Math.floor(endMin / DAY_MIN));
        if (diffDays(day, endDay) >= 7) return +endDay.slice(5, 7) + '/' + +endDay.slice(8);
        var wd = dayLabel(endDay).slice(0, 3);
        return lower ? wd.toLowerCase() : wd;
    }

    // A range from a board day's minutes. One that ends on a later board day
    // names the end's day: '9a–Sun 2p'.
    function fmtSpan(day, startMin, endMin) {
        var word = day ? endDayWord(day, endMin) : null;
        if (!word) return fmtRange(startMin, endMin);
        return fmtTime(startMin) + '–' + word + ' ' + fmtTime(endMin);
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

    // Always-open facilities (Troll, Tavern): an item whose primary category is
    // one of ONGOING_CATS and that lasts ONGOING_MIN minutes or more. The board
    // draws it as a narrow rail at the left of its day instead of in a lane.
    var ONGOING_MIN = 360; // six hours: a four-hour tournament or feast stays a block
    var ONGOING_CATS = ['Administrative', 'Feast and Food'];

    function isOngoing(item) {
        if (!item || ONGOING_CATS.indexOf(item.Category) < 0) return false;
        var sp = span(item);
        return !!sp && sp.end - sp.start >= ONGOING_MIN;
    }

    function byStartThenTitle(a, b) {
        var ta = String(a.item.Title || ''), tb = String(b.item.Title || '');
        return (a.startMin - b.startMin) || (ta < tb ? -1 : ta > tb ? 1 : 0);
    }

    // Turn schedule items into day columns with laid-out segments and the time
    // axis. opts = { eventStart, eventEnd, editing, notRail }; the dates are naive
    // strings or empty. Ongoing items (isOngoing) are left out of the lanes: each
    // day lists their segments in rails (by start, then title), each with its
    // rail index, and railCount. opts.notRail: an item laid out in a lane even
    // when it is ongoing (the block being typed on the board).
    function buildBoard(items, opts) {
        var byDay = {}, railsByDay = {};
        var lo = null, hi = null;
        (items || []).forEach(function (item) {
            var rail = item !== opts.notRail && isOngoing(item);
            segments(item).forEach(function (sg) {
                byDay[sg.day] = byDay[sg.day] || [];
                if (rail) (railsByDay[sg.day] = railsByDay[sg.day] || []).push(sg);
                else byDay[sg.day].push(sg);
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
            var rails = (railsByDay[day] || []).sort(byStartThenTitle);
            rails.forEach(function (sg, i) { sg.rail = i; });
            // An empty day still draws one lane.
            return { day: day, label: dayLabel(day), segs: segs, lanes: Math.max(1, layoutDay(segs)),
                rails: rails, railCount: rails.length };
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
    var KEYWORD_ROWS = [
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
    ];

    function keywordRow(category, words) {
        var alts = words.map(function (k) {
            return k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/ /g, '\\s+');
        });
        return { category: category, re: new RegExp('(?:^|[^a-z0-9&])(?:' + alts.join('|') + ')s?(?![a-z0-9&])', 'i') };
    }

    var CATEGORY_KEYWORDS = KEYWORD_ROWS.map(function (row) { return keywordRow(row[0], row[1]); });

    // The built-in tables as setCategories entries, kept for resetCategories, and
    // the built-in keyword priority (guessing tries these categories first).
    var DEFAULT_CATEGORIES = CATEGORY_NAMES.map(function (name) {
        var kw = KEYWORD_ROWS.filter(function (row) { return row[0] === name; })[0];
        return { name: name, tag: CATEGORY_TAGS[name], aliases: CATEGORY_ALIASES[name].slice(), keywords: kw ? kw[1].slice() : [] };
    });
    var KEYWORD_PRIORITY = KEYWORD_ROWS.map(function (row) { return row[0]; });

    function squash(s) { return String(s).toLowerCase().replace(/\s+/g, ' ').trim(); }

    function stringList(v) {
        return Array.isArray(v) ? v.filter(function (s) { return typeof s === 'string' && squash(s); }) : [];
    }

    // Replace the category tables in place (other code holds the exported arrays).
    function applyCategories(rows) {
        var k;
        CATEGORY_NAMES.length = 0;
        for (k in CATEGORY_TAGS) if (Object.prototype.hasOwnProperty.call(CATEGORY_TAGS, k)) delete CATEGORY_TAGS[k];
        for (k in CATEGORY_ALIASES) if (Object.prototype.hasOwnProperty.call(CATEGORY_ALIASES, k)) delete CATEGORY_ALIASES[k];
        CATEGORY_KEYWORDS.length = 0;
        rows.forEach(function (r) {
            CATEGORY_NAMES.push(r.name);
            CATEGORY_TAGS[r.name] = r.tag;
            CATEGORY_ALIASES[r.name] = r.aliases.map(squash);
        });
        // Guessing order: ascending keywordRank when every entry has one (ties
        // keep list order); otherwise the built-in priority for the categories
        // it knows, then the rest in list order.
        var ranked = rows.every(function (r) { return typeof r.rank === 'number'; });
        var ordered = rows.slice().sort(function (a, b) {
            if (ranked) return a.rank - b.rank || rows.indexOf(a) - rows.indexOf(b);
            var pa = KEYWORD_PRIORITY.indexOf(a.name), pb = KEYWORD_PRIORITY.indexOf(b.name);
            pa = pa < 0 ? KEYWORD_PRIORITY.length + rows.indexOf(a) : pa;
            pb = pb < 0 ? KEYWORD_PRIORITY.length + rows.indexOf(b) : pb;
            return pa - pb;
        });
        ordered.forEach(function (r) {
            var words = r.keywords.map(squash);
            if (words.length) CATEGORY_KEYWORDS.push(keywordRow(r.name, words));
        });
    }

    // Categories from the server (EvConfig.scheduleCategories): each entry a name
    // ('name' or 'Name'), a one-word 'tag', and optional 'aliases' and 'keywords'.
    // A numeric 'keywordRank' on every entry sets the guessing order. Anything
    // else (not a non-empty array, an entry without a name and a tag, or a list
    // with no 'Other') changes nothing. Returns whether the tables were replaced.
    function setCategories(list) {
        if (!Array.isArray(list) || !list.length) return false;
        var rows = [], seen = {};
        for (var i = 0; i < list.length; i++) {
            var e = list[i];
            if (!e || typeof e !== 'object') return false;
            var name = typeof e.name === 'string' ? e.name : e.Name;
            var tag = typeof e.tag === 'string' ? e.tag : e.Tag;
            if (typeof name !== 'string' || !name.trim() || typeof tag !== 'string') return false;
            name = name.trim();
            tag = tag.trim().replace(/^#/, '');
            if (!/^\S+$/.test(tag) || seen[name]) return false;
            seen[name] = true;
            var rank = typeof e.keywordRank === 'number' && isFinite(e.keywordRank) ? e.keywordRank : null;
            rows.push({ name: name, tag: tag, aliases: stringList(e.aliases), keywords: stringList(e.keywords), rank: rank });
        }
        // parseEntry and formatEntry fall back to 'Other'.
        if (!seen.Other) return false;
        applyCategories(rows);
        return true;
    }

    // Back to the built-in tables.
    function resetCategories() { applyCategories(DEFAULT_CATEGORIES); }

    // A category name, tag or alias typed in full, or null.
    function categoryByName(text) {
        var t = squash(text);
        for (var i = 0; i < CATEGORY_NAMES.length; i++) {
            var name = CATEGORY_NAMES[i];
            if (t === name.toLowerCase() || t === String(CATEGORY_TAGS[name]).toLowerCase() ||
                CATEGORY_ALIASES[name].indexOf(t) >= 0) return name;
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

    // Tokens. The scanner yields '@' lead spans (leadAt, below), quoted spans and whitespace-separated words; a
    // word is then classified by the first matching TOKEN_KINDS entry, so a new
    // kind of token is one more entry here. Anything else is a plain 'word'.
    var QUOTE_CLOSE = { '"': '"', '\u201C': '\u201D', '\u201D': '\u201D' };
    var TOKEN_KINDS = [
        { kind: 'tag', re: /^#(\S+)$/ },
        { kind: 'cost', re: /^\$(\d+(?:\.\d{1,2})?)$/ }
    ];

    // The largest cost the schedule's DECIMAL(8,2) column holds; a '$' token
    // above it is not claimed as a cost and stays in the title.
    var COST_MAX = 999999.99;

    // A staff persona as a pattern that matches it ignoring case and the width
    // of its spaces, and only up to the end of a word ('@Luna' not in '@Lunatic').
    function personaPattern(persona) {
        var src = squash(persona).replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/ /g, '\\s+');
        return new RegExp(src + '(?![\\p{L}\\p{N}])', 'iuy');
    }

    var LEAD_TRAIL = ',;.:';

    // '@' at the start of a word, at text[i]: { kind: 'lead', lead } spanning the
    // longest staff persona after it (which may hold spaces or quotes) and any
    // punctuation (LEAD_TRAIL) right after it, else
    // { kind: 'unlead' } spanning the one word after it, else null (a bare '@').
    // The longest staff persona written at text[pos]: { len, staff } or null.
    function staffAt(text, pos, staff) {
        var best = null;
        (staff || []).forEach(function (s) {
            if (!s || !s.Persona || !squash(s.Persona)) return;
            var re = personaPattern(s.Persona);
            re.lastIndex = pos;
            var m = re.exec(text);
            if (m && (!best || m[0].length > best.len)) best = { len: m[0].length, staff: s };
        });
        return best;
    }

    function leadAt(text, i, staff) {
        var best = staffAt(text, i + 1, staff);
        if (best) {
            var end = i + 1 + best.len;
            // Punctuation right after the name goes with it ('@Luna, @Kai', '@Luna.').
            while (end < text.length && LEAD_TRAIL.indexOf(text.charAt(end)) >= 0) end++;
            return { kind: 'lead', text: text.slice(i, end), value: best.staff.Persona, end: end,
                lead: { MundaneId: +best.staff.MundaneId, Persona: best.staff.Persona } };
        }
        var j = i + 1;
        while (j < text.length && !/\s/.test(text.charAt(j))) j++;
        if (j === i + 1) return null;
        return { kind: 'unlead', text: text.slice(i, j), value: text.slice(i + 1, j), end: j };
    }

    // A staff member named after a cue at text[i] ('taught by Luna', 'with
    // Luna', 'run by @Luna'): a lead token spanning the cue and the name, or null.
    // Only staff: 'Fun with Swords' stays text.
    var LEAD_CUE_RE = /(?:(?:taught|run|hosted|led|presented|organi[sz]ed|judged)\s+by|with)\s+@?/iy;
    function cueLeadAt(text, i, staff) {
        LEAD_CUE_RE.lastIndex = i;
        var m = LEAD_CUE_RE.exec(text);
        if (!m) return null;
        var best = staffAt(text, i + m[0].length, staff);
        if (!best) return null;
        var end = i + m[0].length + best.len;
        // The name must end its word: 'with Luna's group' and 'with Luna-ish'
        // are about something else, and stay text.
        if (/['’‘\-‐‑]/.test(text.charAt(end))) return null;
        while (end < text.length && LEAD_TRAIL.indexOf(text.charAt(end)) >= 0) end++;
        return { kind: 'lead', text: text.slice(i, end), value: best.staff.Persona, end: end,
            lead: { MundaneId: +best.staff.MundaneId, Persona: best.staff.Persona } };
    }

    // A word as the grammar reads it (its text stays as typed for the title): an
    // em dash, minus or arrow is a range dash, 'a.m.' is 'am', ''til' is 'til',
    // and a trailing comma, semicolon or full stop is dropped ('Sat.', '9a,').
    function canonWord(w) {
        var v = w.replace(/\u2014|\u2012|\u2212|->|\u2192/g, '\u2013')
            .replace(/([ap])\.m\.?(?=$|[-\u2013,;.])/gi, '$1m')
            .replace(/^['\u2019]til$/i, 'til');
        var trimmed = v.replace(/[,;.]+$/, '');
        return trimmed || v;
    }

    function tokenize(text, staff) {
        var out = [], i = 0, n = text.length;
        while (i < n) {
            var ch = text.charAt(i);
            if (/\s/.test(ch)) { i++; continue; }
            var cue = cueLeadAt(text, i, staff);
            if (cue) {
                i = cue.end;
                delete cue.end;
                out.push(cue);
                continue;
            }
            // '@' leads claim their whole span first: a persona may hold quotes.
            var lead = ch === '@' ? leadAt(text, i, staff) : null;
            if (lead) {
                i = lead.end;
                delete lead.end;
                out.push(lead);
                continue;
            }
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
            if (tok.kind === 'word') tok.value = canonWord(word);
            out.push(tok);
            i = j;
        }
        out.forEach(function (t) { t.claimed = false; });
        return out;
    }

    var WEEKDAY_RE = /^(sun|mon|tue|wed|thu|fri|sat)(?:day|sday|nesday|rsday|urday)?:?$/i;

    // Words a near-miss of a weekday that are not one ('Sundae Bar').
    var NOT_WEEKDAYS = ['sundae', 'sundaes', 'sundial', 'sundials', 'mondo'];

    // Optimal string alignment distance (edits, with a swap of two neighbours as one).
    function editDistance(a, b) {
        var d = [], i, j;
        for (i = 0; i <= a.length; i++) { d[i] = [i]; }
        for (j = 0; j <= b.length; j++) d[0][j] = j;
        for (i = 1; i <= a.length; i++) {
            for (j = 1; j <= b.length; j++) {
                var cost = a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1;
                d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
                if (i > 1 && j > 1 && a.charAt(i - 1) === b.charAt(j - 2) && a.charAt(i - 2) === b.charAt(j - 1)) {
                    d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
                }
            }
        }
        return d[a.length][b.length];
    }

    // A typo keeps the name's 'day': its three letters all in the last four
    // ('Satuday', 'Sundya', 'Firday'), or the name with one letter dropped
    // ending 'dy' or 'ay' ('Saturdy'). Real words a letter off a weekday change
    // the ending instead ('Sundry', 'Sunray', 'Saturnal', 'Sundae').
    function keepsDay(w, n) {
        var tail = w.slice(-4);
        if (['d', 'a', 'y'].every(function (c) { return tail.indexOf(c) >= 0; })) return true;
        return w.length < n.length && /(?:dy|ay)$/.test(w);
    }

    // A misspelt full weekday name ('Satuday', 'Thrusday', 'Firday'): one edit
    // away (two for eight letters or more), the same first letter, the 'day'
    // kept (keepsDay), and only one weekday that near. -1 otherwise.
    function fuzzyWeekday(word) {
        var w = word.replace(/:$/, '').toLowerCase();
        if (!/^[a-z]{6,}$/.test(w) || NOT_WEEKDAYS.indexOf(w) >= 0) return -1;
        var hit = -1, best = 3;
        WEEKDAYS.forEach(function (name, idx) {
            var n = name.toLowerCase();
            if (n.charAt(0) !== w.charAt(0) || !keepsDay(w, n)) return;
            var dist = editDistance(w, n);
            if (dist > (w.length >= 8 ? 2 : 1)) return;
            if (dist < best) { best = dist; hit = idx; } else if (dist === best) hit = -2;
        });
        return hit < 0 ? -1 : hit;
    }

    function weekdayOf(word) {
        var m = WEEKDAY_RE.exec(word);
        if (!m) return fuzzyWeekday(word);
        var idx = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'].indexOf(m[1].toLowerCase());
        // Accept only the three-letter form or the real full name.
        var bare = word.replace(/:$/, '').toLowerCase();
        return bare.length === 3 || bare === WEEKDAYS[idx].toLowerCase() ? idx : fuzzyWeekday(word);
    }

    // The board day a day word names, or null. A weekday is looked up in near
    // (the event's own days), else in days when near is empty; one they do not
    // have is the date with that weekday nearest to them: before the first or
    // after the last, the one before when both are as near (never a week on).
    function resolveDayWord(word, days, near) {
        if (!days.length) return null;
        var wd = weekdayOf(word), i;
        if (wd >= 0) {
            var pool = near && near.length ? near : days;
            for (i = 0; i < pool.length; i++) if (weekdayIndex(pool[i]) === wd) return pool[i];
            return nearestWeekday(wd, pool);
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

    // The date with weekday wd nearest to any of days (the earlier of two as near).
    function nearestWeekday(wd, days) {
        var sorted = days.slice().sort(), first = sorted[0], last = sorted[sorted.length - 1];
        var d = addDays(first, -((weekdayIndex(first) - wd + 7) % 7 || 7)), best = null, bestGap = Infinity;
        for (; diffDays(d, last) >= -7; d = addDays(d, 7)) {
            var gap = Infinity;
            for (var i = 0; i < sorted.length; i++) gap = Math.min(gap, Math.abs(diffDays(d, sorted[i])));
            if (gap < bestGap) { best = d; bestGap = gap; }
        }
        return best;
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

    // A four-digit 24-hour time ('0900', '1330') as a side, or null.
    function readFourDigit(text) {
        var m = /^([01]\d|2[0-3])([0-5]\d)$/.exec(text);
        if (!m) return null;
        var min = +m[1] * 60 + +m[2];
        return { min: min, marked: true, mk: min < 720 ? 'a' : 'p', strong: true };
    }

    function withMarker(side, mk) { return (side.h % 12 + (mk === 'p' ? 12 : 0)) * 60 + side.mi; }

    // Unmarked hours: 7 to 11 are morning, 12 and 1 to 6 afternoon. With an
    // evening bias (timeBias) 7 to bias.pmMax are evening, and with a midnight
    // bias 12 is 12am.
    function defaultMin(side, bias) {
        if (bias && bias.midnight && side.h === 12) return withMarker(side, 'a');
        var morning = side.h >= 7 && side.h <= 11 && !(bias && side.h <= bias.pmMax);
        return withMarker(side, morning ? 'a' : 'p');
    }

    function sideMin(side, bias) { return side.marked ? side.min : defaultMin(side, bias); }

    // Title words that move an unmarked start: evening words read 7 to 11 as pm
    // ('feast 7-9'), 'court' only 7 and 8 (Sunday-morning court at 10 is common),
    // and 'midnight' reads 12 as 12am. A morning word cancels all of them.
    // 'tavern' is not an evening word: 'Tavern Opens' at 9am is normal.
    var EVENING_RE = /\b(?:night|evening|dinner|supper|feast|bardic|midnight|revel)\b/i;
    var COURT_RE = /\bcourt\b/i;
    var MORNING_RE = /\b(?:breakfast|morning|brunch|lunch)\b/i;

    function timeBias(title) {
        if (MORNING_RE.test(title)) return null;
        if (EVENING_RE.test(title)) return { pmMax: 11, midnight: /\bmidnight\b/i.test(title) };
        if (COURT_RE.test(title)) return { pmMax: 8, midnight: false };
        return null;
    }

    function other(mk) { return mk === 'a' ? 'p' : 'a'; }

    // Hours before 5am belong to the night of the board day.
    function nightShift(min) { return min < BOARD_DAY_START ? min + DAY_MIN : min; }

    // bias (timeBias) moves only an unmarked start; an unmarked end then reads
    // forward from it ('feast 9-1' is 9pm to 1am).
    function resolveRange(a, b, bias) {
        var s, e, m;
        if (a.marked === b.marked) {
            s = sideMin(a, bias); e = sideMin(b);
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

    var LENGTH_RE = /^(?:(\d+(?:\.\d+)?)(?:h|hrs?|hours?)(?:(\d+)(?:m|mins?|minutes?))?|(\d+)(?:m|mins?|minutes?)|\+(\d+))$/i;
    var HOUR_UNIT = /^(?:h|hrs?|hours?)$/, MIN_UNIT = /^(?:m|mins?|minutes?)$/;

    // Minutes, or null. Anything over a day ('99h') is not a length but text.
    function readLength(text) {
        var m = LENGTH_RE.exec(text);
        if (!m) return null;
        var len = m[1] ? Math.round(parseFloat(m[1]) * 60) + (m[2] ? +m[2] : 0) : +(m[3] || m[4]);
        return len > DAY_MIN ? null : len;
    }

    var RANGE_JOINERS = ['-', '\u2013', 'to', 'until', 'till', 'til', 'thru', 'through'];
    // Before a lone time, these make it the end ('Troll until 5p').
    var UNTIL_WORDS = ['until', 'till', 'til'];
    var MARKER_WORDS = ['am', 'pm'];

    function isWord(t) { return t && t.kind === 'word' && !t.claimed; }

    function lowerAt(toks, i) { return isWord(toks[i]) ? toks[i].value.toLowerCase() : ''; }

    // A length at token i, after an optional 'for': '45m', '1h30m', '+45',
    // '2 hrs', '1.5 hours', '1 hour 30 minutes', '90 minutes', 'an hour', 'half
    // an hour'. { len, next } or null; over a day is not a length.
    function lengthAt(toks, i) {
        if (!isWord(toks[i])) return null;
        var j = lowerAt(toks, i) === 'for' ? i + 1 : i, len = null;
        var w = lowerAt(toks, j), w1 = lowerAt(toks, j + 1), w2 = lowerAt(toks, j + 2);
        var one = w ? readLength(toks[j].value) : null;
        if (one !== null) {
            len = one; j += 1;
        } else if (/^\d+(?:\.\d+)?$/.test(w) && HOUR_UNIT.test(w1)) {
            len = Math.round(parseFloat(w) * 60); j += 2;
            if (/^\d+$/.test(lowerAt(toks, j)) && MIN_UNIT.test(lowerAt(toks, j + 1))) { len += +lowerAt(toks, j); j += 2; }
        } else if (/^\d+$/.test(w) && MIN_UNIT.test(w1)) {
            len = +w; j += 2;
        } else if ((w === 'an' || w === 'one') && w1 === 'hour') {
            len = 60; j += 2;
        } else if (w === 'half' && w1 === 'an' && w2 === 'hour') {
            len = 30; j += 3;
        } else if ((w === 'half' && w1 === 'hour') || (w === 'a' && w1 === 'half' && w2 === 'hour')) {
            len = 30; j += w === 'a' ? 3 : 2;
        }
        if (len === null || len > DAY_MIN) return null;
        // Words, not a '45m' token: a Title-Cased unit with more title after it
        // names the thing ('One Hour Challenge', '24 Hour Vigil'), unless 'for' leads it.
        var start = lowerAt(toks, i) === 'for' ? i + 1 : i, words = j - start > 1;
        if (words && start === i && isWord(toks[j])) {
            for (var k = start; k < j; k++) {
                if ((HOUR_UNIT.test(lowerAt(toks, k)) || MIN_UNIT.test(lowerAt(toks, k))) && /^[A-Z]/.test(toks[k].text)) return null;
            }
        }
        return { len: len, next: j, words: words, lead: start > i };
    }

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
        } else if (!side.marked && side.h === 12 && side.mi === 0 && /^(?:noon|midnight)$/.test(lowerAt(toks, next))) {
            // '12 noon', '12 midnight'.
            side = readSide(lowerAt(toks, next));
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
        r.a = a; r.b = b;
        return r;
    }

    // A four-digit 24-hour range at token i ('0900-1000', '0900 - 1000'), ending
    // at least MIN_FOUR minutes after it starts and never past midnight:
    // { start, end, next } or null. A lone four-digit number is never a time.
    var MIN_FOUR = 5;
    function fourRangeAt(toks, i) {
        if (!isWord(toks[i])) return null;
        var one = /^(\d{4})[-–](\d{4})$/.exec(toks[i].value), a, b, next;
        if (one) {
            a = readFourDigit(one[1]); b = readFourDigit(one[2]); next = i + 1;
        } else {
            a = readFourDigit(toks[i].value);
            if (!a || !isWord(toks[i + 1]) || RANGE_JOINERS.indexOf(toks[i + 1].value.toLowerCase()) < 0 || !isWord(toks[i + 2])) return null;
            b = readFourDigit(toks[i + 2].value); next = i + 3;
        }
        if (!a || !b || b.min < a.min + MIN_FOUR) return null;
        var s = nightShift(a.min);
        return { start: s, end: b.min + (s - a.min), next: next };
    }

    // Whether a token outside [from, to) holds a time with a colon or an am/pm
    // marker ('9a', '3:15', '10-11p', '9 am').
    function strongTimeElsewhere(toks, from, to) {
        for (var k = 0; k < toks.length; k++) {
            if (k >= from && k < to) { k = to - 1; continue; }
            if (!isWord(toks[k])) continue;
            var sa = sideAt(toks, k);
            if (sa && sa.side.strong && /\d/.test(toks[k].value)) return true;
            var parts = toks[k].value.split(/[-–]/);
            if (parts.length === 2 && parts.some(function (p) { var sd = /\d/.test(p) && readSide(p); return sd && sd.strong; })) return true;
        }
        return false;
    }

    function isAt(t) { return isWord(t) && t.value.toLowerCase() === 'at'; }
    function isFrom(t) { return isWord(t) && t.value.toLowerCase() === 'from'; }

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
        // A four-digit range counts only at the start or end of the line, and
        // only when no other time with a colon or marker is written ('2026-2027 Kickoff 9a').
        var four = fourRangeAt(toks, i);
        if (four && (i === firstIdx || four.next - 1 === lastIdx) && !strongTimeElsewhere(toks, i, four.next)) {
            return { found: { start: four.start, end: four.end, length: null, then: false }, upto: four.next };
        }
        var r = rangeAt(toks, i);
        // A bare range ('9 to 5') only at an end of the line or after 'from'.
        if (r && (!r.bare || i === firstIdx || r.next - 1 === lastIdx || isFrom(toks[i - 1]))) {
            return { found: { start: r.start, end: r.end, length: null, then: false, a: r.a, b: r.b }, upto: r.next };
        }
        var sa = sideAt(toks, i);
        if (sa && sa.side.strong) {
            var word = /^[a-z]+$/i.test(toks[i].value);
            // Mid-title ('the Midnight Madness') a lone noon or midnight is a word.
            var edge = !inBlock && (i === firstIdx || sa.next - 1 === lastIdx);
            if (word && !edge && !isAt(toks[i - 1])) return null;
            return { found: { start: nightShift(sideMin(sa.side)), end: null, length: null, then: false, a: sa.side }, upto: sa.next, weak: word };
        }
        var ln = lengthAt(toks, i);
        if (ln) return { found: { start: null, end: null, length: ln.len, then: false }, upto: ln.next };
        if (i === firstIdx && toks[i].value.toLowerCase() === 'then') {
            return { found: { start: null, end: null, length: null, then: true }, upto: i + 1 };
        }
        return null;
    }

    // Find the item's time among the tokens and claim it. Returns
    // { start, end, length, then, a, b } (any may be null or absent; a and b are
    // the sides read, for re-reading with a bias) or null. leadingHour (pasted
    // lines only): a bare hour leading a line with a title after it is a time.
    function claimTime(toks, firstIdx, lastIdx, inBlock, leadingHour) {
        var hit = null, i;
        // 'from 9 to 5 Troll': the line's first word is 'from', so the range after it leads the line.
        if (isFrom(toks[firstIdx])) firstIdx++;
        for (i = 0; i < toks.length; i++) {
            if (!isWord(toks[i])) continue;
            var t = timeAt(toks, i, firstIdx, lastIdx, inBlock);
            if (!t) continue;
            if (!t.weak) { hit = { at: i, t: t }; break; }
            if (!hit) hit = { at: i, t: t };
        }
        // 'until 5p', 'till 5': a lone time after it is the end; the start comes
        // from the clock, as a length's does. A bare hour counts here too.
        var untilAt = function (k) { return isWord(toks[k - 1]) && UNTIL_WORDS.indexOf(lowerAt(toks, k - 1)) >= 0; };
        if (hit && hit.t.found.start !== null && hit.t.found.end === null && hit.t.found.a && untilAt(hit.at)) {
            hit.t.found = { start: null, end: null, length: null, then: false, until: hit.t.found.a };
        }
        // A lone start with 'until <time>' later on the line ('9a Troll until
        // 5p'): the two read as a range, as '9a until 5p' does.
        if (hit && hit.t.found.start !== null && hit.t.found.end === null && hit.t.found.a && !hit.t.found.until) {
            for (i = hit.t.upto + 1; i < toks.length; i++) {
                var ue = untilAt(i) && sideAt(toks, i);
                if (!ue) continue;
                var ur = resolveRange(hit.t.found.a, ue.side);
                hit.t.found.start = ur.start; hit.t.found.end = ur.end; hit.t.found.b = ue.side;
                for (var uk = i - 1; uk < ue.next; uk++) toks[uk].claimed = true;
                break;
            }
        }
        for (i = 1; !hit && i < toks.length; i++) {
            var us = untilAt(i) && sideAt(toks, i);
            if (us) hit = { at: i, t: { found: { start: null, end: null, length: null, then: false, until: us.side }, upto: us.next } };
        }
        if (hit && hit.t.found.until) toks[hit.at - 1].claimed = true;
        // Only when the line has no other time: 'at' or 'from' plus a bare hour, 'Court at 6'.
        for (i = 0; !hit && i < toks.length; i++) {
            var hour = (isAt(toks[i - 1]) || isFrom(toks[i - 1])) && bareHourAt(toks, i);
            if (hour) hit = { at: i, t: { found: { start: nightShift(defaultMin(hour)), end: null, length: null, then: false, a: hour }, upto: i + 1 } };
        }
        // Then, in a pasted list, a bare hour leading the line: '7 Feast'. Never a
        // trailing one: titles end in numbers ('Court 7', 'Round 2').
        var lead = leadingHour && !hit && firstIdx >= 0 && firstIdx < lastIdx && bareHourAt(toks, firstIdx);
        if (lead) hit = { at: firstIdx, t: { found: { start: nightShift(defaultMin(lead)), end: null, length: null, then: false, a: lead }, upto: firstIdx + 1 } };
        if (!hit) return null;
        var found = hit.t.found, upto = hit.t.upto;
        for (i = hit.at; i < upto; i++) toks[i].claimed = true;
        // 'at 6pm', 'from 9 to 5': the word belongs to the time, not to a location or the title.
        if (found.start !== null && (isAt(toks[hit.at - 1]) || isFrom(toks[hit.at - 1]))) toks[hit.at - 1].claimed = true;
        // A start or 'then' may be followed by a length: '2p 45m', 'then 30m'.
        // A start may also have one elsewhere on the line: '2p Battlegame for 90
        // minutes' (the time's own reach, upto, is unchanged by that one).
        if (found.end === null && found.length === null && !found.until) {
            var more = lengthAt(toks, upto), from = upto;
            // Away from the time, a length in words must read as one: after
            // 'for', or ending the line ('2p Court for an hour', '2p Court 1
            // hour'), not inside the title ('2p the one hour challenge').
            for (i = 0; !more && found.start !== null && i < toks.length; i++) {
                if (i === upto) continue;
                var el = lengthAt(toks, i);
                if (el && el.words && !el.lead && toks.slice(el.next).some(isWord) && !isAt(toks[el.next])) el = null;
                if ((more = el)) from = i;
            }
            if (more) {
                found.length = more.len;
                for (i = from; i < more.next; i++) toks[i].claimed = true;
                if (from === upto) upto = more.next;
            }
        }
        found.at = hit.at;
        found.upto = upto;
        return found;
    }

    var NOT_PLACES = /^(?:dusk|dawn|sunset|sundown|sunrise|nightfall|twilight|night|dark|daybreak)$/;

    // Location: the last free standalone 'at' and the one to six free words
    // after it, stopping at the end, a non-word token ('#', '$', a quote) or a
    // token another rule claimed.
    function claimLocation(toks, locations) {
        for (var i = toks.length - 1; i >= 0; i--) {
            if (!isAt(toks[i])) continue;
            var after = sideAt(toks, i + 1);
            // Followed by a time ('at 3-4', 'at 5p') or a bare hour ('at 6'): never a location.
            if (rangeAt(toks, i + 1) || after && after.side.strong || bareHourAt(toks, i + 1)) continue;
            // 'at dusk', 'at sunset': a time of day this board cannot place, never a location.
            if (NOT_PLACES.test(lowerAt(toks, i + 1))) continue;
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
        return knownLocation(toks, locations);
    }

    // Without 'at', only a location the schedule already uses: after 'in' or
    // 'on' (and an optional 'the': 'in the Pavilion'), or ending the line when
    // it is two words or more ('Battlegame Main Field'; one word, 'Open
    // Tavern', is too often a title). The longest wins; '' when none.
    function knownLocation(toks, locations) {
        var list = (locations || []).map(function (l) { return { name: l, words: squash(l).split(' ') }; })
            .filter(function (l) { return l.words[0]; })
            .sort(function (a, b) { return b.words.length - a.words.length; });
        if (!list.length) return '';
        var last = -1, i;
        for (i = 0; i < toks.length; i++) if (isWord(toks[i])) last = i;
        var matchAt = function (p) {
            for (var n = 0; n < list.length; n++) {
                var w = list[n].words, ok = true;
                for (var k = 0; k < w.length && ok; k++) ok = isWord(toks[p + k]) && squash(toks[p + k].value) === w[k];
                if (ok) return list[n];
            }
            return null;
        };
        for (i = 0; i < toks.length; i++) {
            var w = lowerAt(toks, i);
            if (w !== 'in' && w !== 'on') continue;
            var p = lowerAt(toks, i + 1) === 'the' ? i + 2 : i + 1;
            var hit = matchAt(p);
            if (!hit) continue;
            for (var k = i; k < p + hit.words.length; k++) toks[k].claimed = true;
            return hit.name;
        }
        for (i = 1; i <= last; i++) {
            var end = matchAt(i);
            if (!end || end.words.length < 2 || i + end.words.length - 1 !== last) continue;
            var before = false;
            for (var b = 0; b < i && !before; b++) before = isWord(toks[b]);
            if (!before) continue;
            for (var c = i; c <= last; c++) toks[c].claimed = true;
            return end.name;
        }
        return '';
    }

    // ---- All-day shorthand and spans across days ----
    // 'all day' is only a shorthand for 7am to 7pm; nothing is stored as all-day.
    var ALL_DAY_START = 420;
    var ALL_DAY_END = 1140;
    var SPAN_JOINERS = ['-', '–', 'to', 'through', 'thru', 'until', 'till', 'til'];
    var WHOLE_RE = /^(?:all|whole|entire)$/;
    var EVENT_RE = /^(?:weekend|event)$/;

    // 'all day', 'all-day' or 'allday' at token i: the index after it (and after
    // a 'long'), or -1.
    function allDayAt(toks, i) {
        var w = lowerAt(toks, i), next = -1;
        if (/^all-?day$/.test(w)) next = i + 1;
        else if (w === 'all' && lowerAt(toks, i + 1) === 'day') next = i + 2;
        return next >= 0 && lowerAt(toks, next) === 'long' ? next + 1 : next;
    }

    // One end of a span at token i: a day and a time in either order, or one of
    // them: { day, side, next, dayAfter } (day or side may be null; dayAfter when
    // the day is written after the time, '2p Sun'), or null.
    function spanEndAt(toks, i, dayOf) {
        if (!isWord(toks[i])) return null;
        var day = dayOf(toks[i].value), j = day ? i + 1 : i, side = null, after = false;
        var sa = sideAt(toks, j);
        if (sa) { side = sa.side; j = sa.next; }
        if (!day && side && isWord(toks[j])) { day = dayOf(toks[j].value); if (day) { j++; after = true; } }
        return day || side ? { day: day, side: side, next: j, dayAfter: after } : null;
    }

    // A span's board day and minutes: an end without a time is 7pm, a start
    // without one 7am. Null unless it ends after it starts.
    function spanTimes(aDay, aSide, bDay, bSide) {
        var s = aSide ? sideMin(aSide) : ALL_DAY_START, e = bSide ? sideMin(bSide) : ALL_DAY_END, day = aDay;
        if (s < BOARD_DAY_START) { day = addDays(day, -1); s += DAY_MIN; }
        var end = diffDays(day, bDay) * DAY_MIN + e;
        return end > s ? { day: day, start: s, end: end } : null;
    }

    // A range across days at token i ('9am Saturday to 2pm Sunday', 'Sat 9a -
    // Sun 2p', 'Saturday through Sunday', 'Sat-Sun', '9a to sun 2p'). The end
    // must name its day; a start without one is on leadDay (a day word leading
    // the line: 'Sat Tournament 9a to Sun 2p'), else on defDay. A day written
    // after the end's time ('9a to 5p Saturday') is a span's end only when the
    // start names a day too: alone it is the day of a same-day range.
    // { day, start, end, from, upto, usedLead } or null.
    function crossDayAt(toks, i, dayOf, defDay, leadDay) {
        if (!isWord(toks[i])) return null;
        var fused = /^([^-–]+)[-–]([^-–]+)$/.exec(toks[i].value);
        if (fused && dayOf(fused[1]) && dayOf(fused[2])) {
            var f = spanTimes(dayOf(fused[1]), null, dayOf(fused[2]), null);
            return f && { day: f.day, start: f.start, end: f.end, from: i, upto: i + 1 };
        }
        var a = spanEndAt(toks, i, dayOf);
        if (!a || SPAN_JOINERS.indexOf(lowerAt(toks, a.next)) < 0) return null;
        var b = spanEndAt(toks, a.next + 1, dayOf);
        if (!b || !b.day) return null;
        var startDay = a.day || leadDay || defDay;
        if (!startDay || b.dayAfter && !a.day && !leadDay) return null;
        var t = spanTimes(startDay, a.side, b.day, b.side);
        return t && { day: t.day, start: t.start, end: t.end, from: i, upto: b.next, usedLead: !a.day && !!leadDay };
    }

    // The all-day shorthand or a span across days, claimed: { day, start, end,
    // plain } (day null for a plain 'all day', which keeps the line's own day;
    // plain for that shorthand, which an explicit time on the line overrides),
    // or null. dayOf is null when the day is fixed or dismissed: then only a
    // plain 'all day' counts.
    function claimSpan(toks, dayOf, defDay, wholeDays) {
        var found = null, i;
        // A day word leading the line is a span's start day when the start names none.
        var leadDay = dayOf && isWord(toks[0]) ? dayOf(toks[0].value) : null;
        for (i = 0; i < toks.length && !found; i++) {
            if (!isWord(toks[i])) continue;
            var ad = allDayAt(toks, i), w = lowerAt(toks, i);
            if (dayOf) {
                // 'all day Saturday through Sunday' and 'Saturday through Sunday'.
                var x = (ad > 0 && crossDayAt(toks, ad, dayOf, defDay, i > 0 ? leadDay : null)) ||
                    crossDayAt(toks, i, dayOf, defDay, i > 0 ? leadDay : null);
                if (x) {
                    found = x; found.from = i;
                    if (x.usedLead) toks[0].claimed = true;
                    break;
                }
                // 'all weekend', 'whole event': the event's first day to its last.
                if (WHOLE_RE.test(w) && EVENT_RE.test(lowerAt(toks, i + 1)) && wholeDays.length) {
                    var j = i + 2;
                    if (lowerAt(toks, j) === 'long') j++;
                    var wt = spanTimes(wholeDays[0], null, wholeDays[wholeDays.length - 1], null);
                    if (wt) { found = { day: wt.day, start: wt.start, end: wt.end, from: i, upto: j }; break; }
                }
                // 'all Saturday', 'all Saturday long'.
                if (w === 'all' && ad < 0 && isWord(toks[i + 1]) && dayOf(toks[i + 1].value)) {
                    var k = lowerAt(toks, i + 2) === 'long' ? i + 3 : i + 2;
                    found = { day: dayOf(toks[i + 1].value), start: ALL_DAY_START, end: ALL_DAY_END, from: i, upto: k };
                    break;
                }
            }
            if (ad > 0) {
                // 'all day', with a day word right before or after it taken as the day.
                found = { day: null, start: ALL_DAY_START, end: ALL_DAY_END, from: i, upto: ad, plain: true };
                if (dayOf && isWord(toks[ad]) && dayOf(toks[ad].value)) { found.day = dayOf(toks[ad].value); found.upto++; }
                else if (dayOf && isWord(toks[i - 1]) && dayOf(toks[i - 1].value)) { found.day = dayOf(toks[i - 1].value); found.from--; }
            }
        }
        if (!found) return null;
        for (i = found.from; i < found.upto; i++) toks[i].claimed = true;
        return { day: found.day, start: found.start, end: found.end, plain: !!found.plain };
    }

    // ---- More of the line: several days, relative days, anchors, feast details ----

    // 'M/D' for a board day, the form resolveDayWord reads.
    function mdOf(day) { return +day.slice(5, 7) + '/' + +day.slice(8); }

    var NUMBER_WORDS = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];

    // Relative day words become day words the grammar already reads: 'today',
    // 'tonight', 'tomorrow' (from ctx.today when it is one of the event's days,
    // else from the selected day), 'day 2', 'first day', 'last day'. The token
    // keeps its text; its value becomes 'M/D', and the pairs merge into one token.
    // 'tonight' is marked (it reads an unmarked hour as the evening).
    function relativeDays(toks, base, evDays) {
        for (var i = 0; i < toks.length; i++) {
            var w = lowerAt(toks, i), day = null, two = false;
            if (!w) continue;
            if (w === 'today' || w === 'tonight') day = base;
            else if (w === 'tomorrow' || w === 'tmrw') day = base && addDays(base, 1);
            else if (w === 'day' && evDays.length) {
                var nw = lowerAt(toks, i + 1), n = /^\d+$/.test(nw) ? +nw : NUMBER_WORDS.indexOf(nw) + 1;
                if (n >= 1 && n <= evDays.length) { day = evDays[n - 1]; two = true; }
            } else if ((w === 'first' || w === 'last' || w === 'final') && lowerAt(toks, i + 1) === 'day' && evDays.length) {
                day = w === 'first' ? evDays[0] : evDays[evDays.length - 1]; two = true;
            }
            if (!day) continue;
            if (two) {
                toks[i].text += ' ' + toks[i + 1].text;
                toks.splice(i + 1, 1);
            }
            toks[i].value = mdOf(day);
            toks[i].relative = true;
            if (w === 'tonight') toks[i].tonight = true;
        }
    }

    var EVERY_DAY_RE = /^(?:daily|everyday)$/;
    var DAY_LIST_SEPS = ['&', 'and', '+', ',', '/'];

    // Several days for one line: 'every day', 'each day', 'daily' (the event's
    // days), or a list of two or more day words ('Fri & Sat', 'Fri, Sat and
    // Sun', 'Fri/Sat'). Claimed; { days, every } or null.
    function claimDays(toks, dayOf, wholeDays) {
        for (var i = 0; i < toks.length; i++) {
            var w = lowerAt(toks, i), w1 = lowerAt(toks, i + 1);
            if (wholeDays.length > 1 && (EVERY_DAY_RE.test(w) || ((w === 'every' || w === 'each') && w1 === 'day'))) {
                toks[i].claimed = true;
                if (!EVERY_DAY_RE.test(w)) toks[i + 1].claimed = true;
                return { days: wholeDays.slice(), every: true };
            }
            if (!isWord(toks[i])) continue;
            // A fused list: 'Fri/Sat', 'Fri,Sat', 'Fri&Sat'.
            var parts = toks[i].text.split(/[\/,&+]/).filter(function (x) { return x; });
            if (parts.length > 1 && parts.every(function (x) { return dayOf(x); })) {
                toks[i].claimed = true;
                return { days: uniqueDays(parts.map(dayOf)), every: false };
            }
            if (!dayOf(toks[i].value)) continue;
            var list = [dayOf(toks[i].value)], used = [i], j = i + 1;
            while (j < toks.length) {
                // A comma ending the day word ('Fri, Sat') joins as a separator does.
                var comma = /,$/.test(toks[j - 1].text);
                var sep = DAY_LIST_SEPS.indexOf(lowerAt(toks, j)) >= 0;
                var k = sep ? j + 1 : j;
                if (!(sep || comma) || !isWord(toks[k]) || !dayOf(toks[k].value)) break;
                list.push(dayOf(toks[k].value));
                for (var u = j; u <= k; u++) used.push(u);
                j = k + 1;
            }
            var days = uniqueDays(list);
            if (days.length < 2) continue;
            used.forEach(function (u) { toks[u].claimed = true; });
            return { days: days, every: false };
        }
        return null;
    }

    function uniqueDays(list) {
        return list.filter(function (d, k) { return d && list.indexOf(d) === k; }).sort();
    }

    function escapeRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

    var ANCHOR_WORDS = ['after', 'following', 'before'];
    var ANCHOR_SKIP = ['the'];

    // An item named after 'after', 'following' or 'before' ('Battlegame after
    // court', 'before the feast'): the longest run of up to five free words that
    // is a schedule item's title, or a whole-word part of one. On the line's day
    // when one matches there (the earliest), else, when no day is typed, the
    // only match on any day. Claimed; { kind, item, sp } or null.
    function claimAnchor(toks, items, day, dayTyped) {
        if (!items || !items.length) return null;
        for (var i = 0; i < toks.length; i++) {
            var w = lowerAt(toks, i);
            if (ANCHOR_WORDS.indexOf(w) < 0) continue;
            var p = ANCHOR_SKIP.indexOf(lowerAt(toks, i + 1)) >= 0 ? i + 2 : i + 1, words = [];
            for (var j = p; j < toks.length && j < p + 5 && isWord(toks[j]) && !/^[#$@]/.test(toks[j].value); j++) {
                words.push(squash(toks[j].value));
            }
            for (var n = words.length; n >= 1; n--) {
                var hit = anchorItem(items, words.slice(0, n).join(' '), day, dayTyped);
                if (!hit) continue;
                for (var k = i; k < p + n; k++) toks[k].claimed = true;
                return { kind: w === 'before' ? 'before' : 'after', item: hit.item, sp: hit.sp };
            }
        }
        return null;
    }

    function anchorItem(items, phrase, day, dayTyped) {
        if (phrase.replace(/[^\p{L}\p{N}]/gu, '').length < 3) return null;
        var re = new RegExp('(?:^| )' + escapeRe(phrase) + 's?(?= |$)');
        var exact = [], part = [];
        items.forEach(function (it) {
            var sp = span(it);
            if (!sp) return;
            var t = squash(String(it.Title || '').replace(/[^\p{L}\p{N}&'\s]/gu, ' '));
            if (t === phrase) exact.push({ item: it, sp: sp });
            else if (re.test(t)) part.push({ item: it, sp: sp });
        });
        var pick = function (list) {
            var onDay = list.filter(function (x) { return x.sp.day === day; });
            var pool = onDay.length ? onDay : (!dayTyped && list.length === 1 ? list : []);
            pool.sort(function (a, b) { return a.sp.start - b.sp.start; });
            return pool[0] || null;
        };
        return pick(exact) || pick(part);
    }

    // Feast details, as the item form's checkboxes name them.
    var DIET_WORDS = {
        vegetarian: 'Vegetarian Option', veggie: 'Vegetarian Option', vegan: 'Vegan Option',
        'gluten-free': 'Gluten-Free Option', glutenfree: 'Gluten-Free Option', gf: 'Gluten-Free Option',
        kosher: 'Kosher', halal: 'Halal', keto: 'Keto', paleo: 'Paleo'
    };
    var ALLERGEN_WORDS = {
        dairy: 'Dairy', milk: 'Dairy', egg: 'Eggs', eggs: 'Eggs', fish: 'Fish', shellfish: 'Shellfish',
        shrimp: 'Shellfish', nut: 'Tree Nuts', nuts: 'Tree Nuts', peanut: 'Peanuts', peanuts: 'Peanuts',
        wheat: 'Wheat', gluten: 'Gluten', soy: 'Soy', sesame: 'Sesame'
    };
    var DIET_AFTER = ['option', 'options', 'available', 'friendly', 'menu'];
    var LIST_SEPS = ['and', '&', '/', '+'];

    // A run of words naming entries of map ('vegan and GF', 'nuts, dairy'),
    // two-word names first ('gluten free', 'tree nuts'). { names, next } or null.
    function wordRun(toks, i, map) {
        var names = [], j = i, more = true;
        while (more) {
            var w = lowerAt(toks, j), w1 = lowerAt(toks, j + 1), name = null, step = 1;
            if (w === 'gluten' && w1 === 'free' && map === DIET_WORDS) { name = 'Gluten-Free Option'; step = 2; }
            else if (w === 'tree' && (w1 === 'nut' || w1 === 'nuts') && map === ALLERGEN_WORDS) { name = 'Tree Nuts'; step = 2; }
            else if (w && Object.prototype.hasOwnProperty.call(map, w)) name = map[w];
            if (!name) break;
            if (names.indexOf(name) < 0) names.push(name);
            j += step;
            more = /,$/.test(toks[j - 1].text) || (LIST_SEPS.indexOf(lowerAt(toks, j)) >= 0 && (j += 1));
        }
        // A trailing separator ('vegan and') is not the run's.
        while (j > i && LIST_SEPS.indexOf(String(toks[j - 1].value).toLowerCase()) >= 0) j--;
        return names.length ? { names: names, next: j } : null;
    }

    // Dietary options and allergens of a meal, claimed: { dietary, allergens }
    // (comma lists, '' when none) or null. Dietary words count after the title
    // and only when 'with' leads them, a word like 'options' follows them, or
    // they end the line ('Feast vegan and GF options'; 'Vegan Potluck' stays a
    // title). Allergens follow 'contains' or 'allergens'.
    function claimFeastDetails(toks) {
        var dietary = [], allergens = [], seenTitle = false, i, k;
        var claim = function (a, b) { for (k = a; k < b; k++) toks[k].claimed = true; };
        for (i = 0; i < toks.length; i++) {
            var w = lowerAt(toks, i);
            if (!w) continue;
            if (/^(?:contains|allergens?):?$/.test(w)) {
                var al = wordRun(toks, i + 1, ALLERGEN_WORDS);
                if (al && seenTitle) { claim(i, al.next); allergens = allergens.concat(al.names); i = al.next - 1; continue; }
            }
            var withAt = w === 'with' ? i + 1 : i;
            var run = seenTitle ? wordRun(toks, withAt, DIET_WORDS) : null;
            if (run) {
                var end = run.next;
                if (DIET_AFTER.indexOf(lowerAt(toks, end)) >= 0) end++;
                var rest = false;
                for (k = end; k < toks.length && !rest; k++) rest = isWord(toks[k]);
                if (withAt > i || end > run.next || !rest) {
                    claim(i, end);
                    dietary = dietary.concat(run.names);
                    i = end - 1;
                    continue;
                }
            }
            seenTitle = true;
        }
        var uniq = function (l) { return l.filter(function (x, n) { return l.indexOf(x) === n; }).join(', '); };
        return dietary.length || allergens.length ? { dietary: uniq(dietary), allergens: uniq(allergens) } : null;
    }

    // The line split at a '; ' (a semicolon with a space or the end after it:
    // '@Luna;@Kai' is one lead list) outside quotes, each part trimmed, empty
    // parts dropped.
    // Only a part that starts with a time or a day is an item of its own
    // ('9a Breakfast; 10a Court', 'Sat 9a Court; Sun 10a Feast'); any other ';'
    // is title text ('6p Feast: roast boar; turnips', '9a Talk; bring water')
    // or joins a lead list ('9a Talk @Luna; @Kai').
    function splitEntry(text, ctx) {
        var out = [], cur = '', close = null;
        for (var i = 0; i < text.length; i++) {
            var ch = text.charAt(i);
            if (close) { if (ch === close) close = null; }
            else if (QUOTE_CLOSE[ch]) close = QUOTE_CLOSE[ch];
            else if (ch === ';' && (i === text.length - 1 || /\s/.test(text.charAt(i + 1)))) { out.push(cur); cur = ''; continue; }
            cur += ch;
        }
        out.push(cur);
        var joined = [];
        out.forEach(function (x, n) {
            if (n > 0 && !x.trim()) return;
            if (n > 0 && joined.length && !startsWhen(x.trim(), ctx || {})) joined[joined.length - 1] += ';' + x;
            else joined.push(x);
        });
        return joined.map(function (x) { return x.trim(); }).filter(function (x) { return x; });
    }

    // Whether a ';' part starts with a time ('10a', '9:30-10', 'noon', 'at 6',
    // 'then', a written length such as '30m') or a day ('Sun', '11/22', 'Nov 22',
    // 'tomorrow', 'day 2', 'every day', 'Fri/Sat', 'all day').
    function startsWhen(text, ctx) {
        var days = ctx.days || [], curDay = ctx.day || days[0] || null;
        var pool = days.length ? days : (curDay ? [curDay] : []);
        var whole = ctx.eventDays && ctx.eventDays.length ? ctx.eventDays : days;
        if (pool.length && leadingDay(text, pool, ctx.eventDays)) return true;
        var toks = tokenize(text, ctx.staff);
        if (!isWord(toks[0])) return false;
        if (pool.length) {
            relativeDays(toks, ctx.today && whole.indexOf(ctx.today) >= 0 ? ctx.today : curDay, whole);
            if (toks[0].relative || resolveDayWord(toks[0].value, pool, ctx.eventDays)) return true;
            var fused = toks[0].text.split(/[\/,&+]/).filter(function (x) { return x; });
            if (fused.length > 1 && fused.every(function (x) { return resolveDayWord(x, pool, ctx.eventDays); })) return true;
        }
        var w = lowerAt(toks, 0);
        if (EVERY_DAY_RE.test(w) || ((w === 'every' || w === 'each') && lowerAt(toks, 1) === 'day')) return true;
        if (allDayAt(toks, 0) > 0) return true;
        var first = isAt(toks[0]) || isFrom(toks[0]) ? 1 : 0;
        if (first && bareHourAt(toks, 1)) return true;
        var t = timeAt(toks, first, first, toks.length - 1, false);
        // A length counts only written with a digit ('30m', '2 hrs'), not 'an hour of Q&A'.
        if (t && (t.found.start !== null || t.found.end !== null || t.found.then || /\d/.test(toks[first].value))) return true;
        return !!(ctx.leadingHour && first === 0 && toks.length > 1 && bareHourAt(toks, 0));
    }

    // The items a parse adds, in order: those of the parts before it on a ';'
    // line (r.before), then its own, one per day of r.days (else r.day).
    // -> [{ r, day, start, end }] (naive start and end). None when the line's
    // own (last) part is not ok: Enter refuses the whole line, so nothing of it
    // is added or previewed.
    function entryParts(r) {
        var out = [];
        if (!r || !r.ok) return out;
        (r.before || []).concat([r]).forEach(function (x) {
            if (!x || !x.ok || !x.day) return;
            (x.days && x.days.length ? x.days : [x.day]).forEach(function (d) {
                out.push({ r: x, day: d, start: toNaive(d, x.startMin), end: toNaive(d, x.endMin) });
            });
        });
        return out;
    }

    // Parse a line. A line with ';' (outside quotes, not inside a block) is
    // several items read in order, each part starting from the one before as a
    // pasted list does: the result is the last part's, with the earlier parts'
    // results in before (only those with a title). Each result's part is its
    // 0-based place among the parts. ctx.ignore applies to the last part only;
    // ctx.partIgnore[n] (an ignore object) to part n.
    function parseEntry(text, ctx) {
        ctx = ctx || {};
        var src = String(text == null ? '' : text);
        var parts = ctx.fixedDay || ctx.fixedStart != null ? null : splitEntry(src, ctx);
        var last = parts && parts.length > 1 ? parts.length - 1 : 0;
        var ignoreOf = function (n) {
            return Object.assign({}, ctx.partIgnore && ctx.partIgnore[n], n === last ? ctx.ignore : null);
        };
        if (!last) {
            var one = parseOne(parts && parts.length === 1 ? parts[0] : src, Object.assign({}, ctx, { ignore: ignoreOf(0) }));
            one.part = 0;
            return one;
        }
        var c = Object.assign({}, ctx), before = [], r = null;
        parts.forEach(function (part, n) {
            c.ignore = ignoreOf(n);
            r = parseOne(part, c);
            r.part = n;
            if (n === last) return;
            if (r.setDay) { c.day = r.setDay; c.lastEnd = null; return; }
            if (!r.ok) return;
            before.push(r);
            c.day = r.day;
            c.items = (c.items || []).concat(entryParts(r).map(function (p) {
                return { Title: r.title, StartTime: p.start, EndTime: p.end };
            }));
            if (setsClock(r)) {
                c.lastEnd = { day: r.day, min: r.endMin };
                c.lastDuration = r.endMin - r.startMin;
            }
        });
        r.before = before;
        return r;
    }

    function parseOne(text, ctx) {
        ctx = ctx || {};
        var days = ctx.days || [];
        var ign = ctx.ignore || {};
        var curDay = ctx.day || days[0] || null;
        var lastDuration = ctx.lastDuration == null ? 60 : ctx.lastDuration;
        var hasFixed = ctx.fixedStart != null && ctx.fixedEnd != null;
        var dayPool = days.length ? days : (curDay ? [curDay] : []);
        var dayFree = !ctx.fixedDay && !ign.day;
        text = String(text == null ? '' : text);
        // A date leading the line in words ('Nov 21 9a court', 'Saturday, November
        // 21: ...') reads as the 'M/D' the grammar knows.
        if (dayFree && dayPool.length) {
            var ld = leadingDay(text, dayPool, ctx.eventDays);
            if (ld && ld.date && ld.rest) text = mdOf(ld.day) + ' ' + ld.rest;
        }
        var toks = tokenize(text, ctx.staff);
        var given = { day: false, time: false, category: false, location: false, description: false, cost: false, leads: false, dietary: false };
        var i;

        // Leads: '@Persona' for staff, '@word' for anyone else. Both leave the
        // title, unless ctx.keepUnmatchedLeads keeps an unmatched one as text.
        var leads = [], unmatchedLeads = [];
        toks.forEach(function (t) {
            if (t.kind === 'lead') {
                if (!leads.some(function (l) { return l.MundaneId === t.lead.MundaneId; })) leads.push(t.lead);
            } else if (t.kind === 'unlead') {
                unmatchedLeads.push(t.value);
                if (ctx.keepUnmatchedLeads) { t.kind = 'word'; t.value = t.text; return; }
            } else {
                return;
            }
            t.claimed = true;
            given.leads = true;
        });

        var dayOf = dayFree ? function (w) { return resolveDayWord(w, dayPool, ctx.eventDays); } : null;
        var wholeDays = ctx.eventDays && ctx.eventDays.length ? ctx.eventDays : days;
        // 'today', 'tomorrow', 'day 2', 'last day' read as day words.
        if (dayOf) relativeDays(toks, ctx.today && wholeDays.indexOf(ctx.today) >= 0 ? ctx.today : curDay, wholeDays);

        // All day, all weekend, or a span across days: it may name the day itself.
        var span = ign.time ? null : claimSpan(toks, dayOf, curDay, wholeDays);

        // Several days ('every day', 'Fri & Sat'): one item each, read on the first.
        var multi = dayOf && (!span || (span.plain && !span.day)) ? claimDays(toks, dayOf, wholeDays) : null;
        // A day word leading such a line is the line's too, not title text:
        // with 'every day' it says nothing more ('Fri 9a Troll every day' is
        // still every day); with a list it joins the list ('Fri 9a Troll, Sat & Sun').
        var lead0 = 0;
        while (lead0 < toks.length && toks[lead0].kind === 'tag') lead0++;
        if (multi && isWord(toks[lead0]) && dayOf(toks[lead0].value)) {
            if (!multi.every) multi.days = uniqueDays(multi.days.concat([dayOf(toks[lead0].value)]));
            toks[lead0].claimed = true;
        }

        // Day: only the first token, read past any leading '#tag' ('#bg sun ditch'
        // is Sunday's; tags are claimed later, so without this the day word would
        // be title text and the item land on the current day).
        var dayIdx = 0;
        while (dayIdx < toks.length && toks[dayIdx].kind === 'tag') dayIdx++;
        var typedDay = (span && span.day) || (multi && multi.days[0]);
        if (typedDay) given.day = true;
        if (!typedDay && !ctx.fixedDay && !ign.day && isWord(toks[dayIdx])) {
            typedDay = resolveDayWord(toks[dayIdx].value, days.length ? days : (curDay ? [curDay] : []), ctx.eventDays);
            if (typedDay) { toks[dayIdx].claimed = true; given.day = true; }
        }
        // The whole line is a day name: the caller switches days instead of adding.
        var setDay = typedDay && toks.length === 1 && weekdayOf(toks[0].value) >= 0 ? typedDay : null;

        // Time.
        var firstIdx = -1, lastIdx = -1, trailIdx = -1;
        for (i = 0; i < toks.length; i++) {
            if (toks[i].claimed || toks[i].kind === 'quote') continue;
            if (firstIdx < 0 && toks[i].kind === 'word') firstIdx = i;
            lastIdx = i;
        }
        // A day word ending the line, when no day is typed: the day of a time
        // just before it ('Tournament 9a to 5p Saturday'). The time then ends the line.
        if (!typedDay && dayOf && lastIdx > firstIdx && isWord(toks[lastIdx]) && dayOf(toks[lastIdx].value)) {
            trailIdx = lastIdx;
            for (lastIdx--; lastIdx >= 0 && (toks[lastIdx].claimed || toks[lastIdx].kind === 'quote'); lastIdx--);
        }
        var time = null;
        if (span && span.plain && !ign.time) {
            // 'all day' with an explicit time on the line: the time wins, and
            // anything else claimTime would take (a length, 'then') stays text.
            var was = toks.map(function (t) { return t.claimed; });
            time = claimTime(toks, firstIdx, lastIdx, ctx.fixedStart != null, ctx.leadingHour);
            if (time && time.start !== null) span = null;
            else { toks.forEach(function (t, k) { t.claimed = was[k]; }); time = null; }
        }
        if (!time) {
            time = span ? { start: span.start, end: span.end, length: null, then: false }
                : ign.time ? null : claimTime(toks, firstIdx, lastIdx, ctx.fixedStart != null, ctx.leadingHour);
        }
        given.time = !!time;
        if (trailIdx >= 0 && time && !span && time.start !== null && time.upto === trailIdx) {
            typedDay = dayOf(toks[trailIdx].value);
            toks[trailIdx].claimed = true;
            given.day = true;
        }
        // A full day name (or a date) right before or after the time, mid-line
        // ('Battlegame Saturday 3p', 'Battlegame 3p Saturday at Main Field'). An
        // abbreviation is too often a word ('9a Sun Dance'), and one after the
        // time must end the line or come before 'at'.
        if (!typedDay && dayOf && time && !span && time.at != null) {
            var midDay = function (k) {
                return isWord(toks[k]) && (/^\d/.test(toks[k].value) || toks[k].value.replace(/:$/, '').length > 3) && dayOf(toks[k].value);
            };
            var bi = time.at - 1, ai = time.upto;
            if (bi >= 0 && toks[bi].claimed && /^(?:at|from)$/i.test(toks[bi].value)) bi--;
            var afterOk = midDay(ai) && (!toks.slice(ai + 1).some(isWord) || isAt(toks[ai + 1]));
            var near = midDay(bi) ? bi : afterOk ? ai : -1;
            if (near >= 0) { typedDay = dayOf(toks[near].value); toks[near].claimed = true; given.day = true; }
        }
        // A relative day anywhere ('Bardic tonight', 'Court tomorrow 10a').
        if (!typedDay && dayOf) {
            for (i = 0; i < toks.length && !typedDay; i++) {
                if (isWord(toks[i]) && toks[i].relative) { typedDay = dayOf(toks[i].value); toks[i].claimed = true; given.day = true; }
            }
        }
        var tonight = toks.some(function (t) { return t.tonight && t.claimed; });

        // After or before another item, when no start is written ('Battlegame
        // after court', 'Feast prep 2h before feast'). Not inside a block.
        var anchor = null;
        if (!span && !ign.time && !hasFixed && (!time || (time.start === null && !time.then && !time.until))) {
            anchor = claimAnchor(toks, ctx.items, ctx.fixedDay || typedDay || curDay, !!typedDay);
            if (anchor) given.time = true;
        }

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
            if (ctx.canFeast && !ign.cost && feast && parseFloat(costTok.value) <= COST_MAX) {
                cost = parseFloat(costTok.value);
                given.cost = true;
            } else {
                costTok.claimed = false; // not a cost: it is title text
            }
        }

        // Feast details ('vegan and GF options', 'contains nuts, dairy'): like a
        // cost, only for a meal and only for someone who may manage feasts.
        var details = null;
        if (ctx.canFeast && !ign.dietary) {
            var held = toks.map(function (t) { return t.claimed; });
            var fd = claimFeastDetails(toks);
            var meal = fd && ((guessing ? guessCategory(titleOf()).category : category) === 'Feast and Food' ||
                secondary === 'Feast and Food');
            if (meal) { details = fd; given.dietary = true; }
            else if (fd) toks.forEach(function (t, k) { t.claimed = held[k]; });
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

        // Order context (pasted lists pass ctx.prevStart, the previous timed
        // line's board day and start): only on the same day.
        var ordered = !!(ctx.prevStart && ctx.prevStart.day === (ctx.fixedDay || typedDay || curDay));
        var unmarked = !!(time && time.a && !time.a.marked && (!time.b || !time.b.marked));
        var morningTitle = MORNING_RE.test(title);
        // Without an order context, an unmarked start is read again with the
        // final title's words ('feast 7-9' is evening). A 'Name:' prefix or a
        // #tag is gone from the title by now, so neither sets the bias.
        var bias = ordered ? null : timeBias(title) || (tonight && !morningTitle ? { pmMax: 11, midnight: false } : null);
        if (time && time.a && bias) {
            if (time.b) {
                var rb = resolveRange(time.a, time.b, bias);
                time.start = rb.start; time.end = rb.end;
            } else {
                time.start = nightShift(sideMin(time.a, bias));
            }
        }

        // Day and times.
        var day = ctx.fixedDay || typedDay || null;
        var startMin, endMin, len = hasFixed ? ctx.fixedEnd - ctx.fixedStart : lastDuration;
        var lastEnd = ctx.lastEnd || null;
        if (anchor) {
            // After: from the item's end; before: ending at its start.
            var alen = time && time.length !== null ? time.length : lastDuration;
            day = anchor.sp.day;
            if (anchor.kind === 'after') { startMin = anchor.sp.end; endMin = startMin + alen; }
            else { endMin = anchor.sp.start; startMin = endMin - alen; }
            // A start outside the anchor's board day belongs to the board day
            // it falls in ('10 hours before' a 9a court starts the night before),
            // as a span's start does; the outside flag below then sees that day.
            while (startMin < BOARD_DAY_START) { day = addDays(day, -1); startMin += DAY_MIN; endMin += DAY_MIN; }
            while (startMin >= DAY_MIN + BOARD_DAY_START) { day = addDays(day, 1); startMin -= DAY_MIN; endMin -= DAY_MIN; }
        } else if (!time) {
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
            if (time.until) {
                // 'until 5p': the first such time after the start (an unmarked hour
                // takes the half day that comes first).
                var u = time.until, step = u.marked ? DAY_MIN : 720;
                endMin = u.marked ? u.min : withMarker(u, 'a');
                while (endMin <= startMin) endMin += step;
            }
        } else {
            day = day || curDay;
            startMin = time.start;
            endMin = time.end !== null ? time.end : startMin + (time.length !== null ? time.length : len);
            // Time order, with an order context: an unmarked default reading that
            // starts before the previous start moves only when that is plainly
            // the evening or the night. 7 to 11 go to pm when the previous start
            // is noon or later and pm is not before it; 12 to 4 go past midnight
            // when the previous start is 18:00 or later. A 7 to 11 that starts
            // before the previous start also goes to pm when the title's words
            // would read that hour as pm ('10 Opening Court', '7 Feast'); one
            // already at or after the previous start is never moved by its
            // words. Otherwise it stands (lists are not always sorted).
            var prev = ctx.prevStart, h = time.a && time.a.h;
            if (ordered && unmarked && !morningTitle && startMin < prev.min) {
                var later = startMin + 720, words = timeBias(title);
                if ((h >= 7 && h <= 11 && ((prev.min >= 720 && later >= prev.min) || (words && h <= words.pmMax))) ||
                        ((h === 12 || h <= 4) && prev.min >= 1080 && later < BOARD_DAY_START + DAY_MIN)) {
                    startMin = later; endMin += 720;
                }
            }
        }

        // Outside: on none of the event's own days (ctx.eventDays), or of ctx.days without them.
        var evDays = ctx.eventDays && ctx.eventDays.length ? ctx.eventDays : days;

        return {
            ok: title !== '',
            setDay: setDay,
            day: day,
            outside: !!(day && evDays.length && evDays.indexOf(day) < 0),
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
            leads: leads,
            unmatchedLeads: unmatchedLeads,
            span: !!span,
            days: multi ? multi.days : null,
            everyDay: !!(multi && multi.every),
            anchor: anchor ? { kind: anchor.kind, title: String(anchor.item.Title || '') } : null,
            dietary: details ? details.dietary : '',
            allergens: details ? details.allergens : '',
            given: given
        };
    }

    // An item as a line parseEntry reads back: '<range> [#tag [#secondary ]]<Title>[ at
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
        // Ending on a later board day: '9a to sun 2p' (a date when a week or more on).
        var endWord = endDayWord(boardDay, endMin, true);
        if (endWord) out = fmtTime(startMin) + ' to ' + endWord + ' ' + fmtTime(endMin);
        if (opts.withDay) out = dayLabel(boardDay).slice(0, 3).toLowerCase() + ' ' + out;
        var title = String(item.Title || '');
        var cat = item.Category && CATEGORY_TAGS[item.Category] ? item.Category : 'Other';
        var g = guessCategory(title);
        var sec = item.SecondaryCategory && CATEGORY_TAGS[item.SecondaryCategory] && item.SecondaryCategory !== cat
            ? item.SecondaryCategory : '';
        // Tag when the title alone would read back as another category, or would lose a
        // prefix; a secondary category is always a second tag after the primary's.
        if (sec || (g.category || 'Other') !== cat || g.title !== title) out += ' #' + CATEGORY_TAGS[cat];
        if (sec) out += ' #' + CATEGORY_TAGS[sec];
        if (title) out += ' ' + title;
        if (item.Location) out += ' at ' + item.Location;
        if (item.Description) {
            var d = String(item.Description);
            out += d.indexOf('"') >= 0 ? ' \u201C' + d + '\u201D' : ' "' + d + '"';
        }
        (item.Leads || []).forEach(function (l) { if (l && l.Persona) out += ' @' + l.Persona; });
        return out;
    }

    // Staff who lead a category, in staff order, as auto-filled leads.
    function leadsForCategory(staff, category) {
        var out = [];
        (staff || []).forEach(function (s) {
            if (!category || (s.LeadCategories || []).indexOf(category) < 0) return;
            if (out.some(function (l) { return l.MundaneId === +s.MundaneId; })) return;
            out.push({ MundaneId: +s.MundaneId, Persona: s.Persona, Auto: true });
        });
        return out;
    }

    // An item's leads after its category becomes newCategory: hand-picked leads
    // kept, auto-filled ones replaced by the new category's, nobody twice.
    function swapAutoLeads(leads, staff, newCategory) {
        var out = (leads || []).filter(function (l) { return !l.Auto; });
        leadsForCategory(staff, newCategory).forEach(function (l) {
            if (!out.some(function (o) { return +o.MundaneId === l.MundaneId; })) out.push(l);
        });
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

    // ---- Gesture arithmetic: pure helpers for drag, resize, nudge, repeat, paste. ----

    var MIN_ITEM = 5;       // a gesture never leaves an item shorter than this
    var REPEAT_GAP = 30;    // default gap between repeated copies

    function snap(min, step) { return Math.round(min / step) * step; }

    // The board day and board minute (300 to 1740) of a naive time.
    function boardPos(naive) {
        var p = parseNaive(naive);
        if (!p) return null;
        return p.min < BOARD_DAY_START ? { day: addDays(p.day, -1), min: p.min + DAY_MIN } : p;
    }

    // An item's start and end as board minutes from its start's board day.
    function span(item) {
        var s = boardPos(item.StartTime);
        if (!s) return null;
        var e = parseNaive(item.EndTime);
        var endMin = e ? diffDays(s.day, e.day) * DAY_MIN + e.min : s.min;
        if (endMin < s.min) endMin = s.min;
        return { day: s.day, start: s.min, end: endMin };
    }

    // Minutes since the epoch day's board 00:00, for ordering items across days.
    function absStart(sp) { return dayNumber(sp.day) * DAY_MIN + sp.start; }

    function times(day, start, end) {
        return { StartTime: toNaive(day, start), EndTime: toNaive(day, end) };
    }

    function moveTimes(item, toDay, toStartMin) {
        var sp = span(item);
        return times(toDay, toStartMin, toStartMin + (sp.end - sp.start));
    }

    // Where a dragged item goes. The pointer was pressed in the pressDay column
    // and is now over pointerDay, deltaMin board minutes from where it was
    // pressed. The item moves by as many days as the pointer crossed columns
    // (from its own start day, whichever of its segments was pressed), and its
    // start snaps to step, except within half a step of where it was. bounds
    // ({min, max}, board minutes) clamps the new start.
    function dragTarget(item, pressDay, pointerDay, deltaMin, step, bounds) {
        var sp = span(item);
        var day = addDays(sp.day, diffDays(pressDay, pointerDay));
        var start = Math.abs(deltaMin) < step / 2 ? sp.start : snap(sp.start + deltaMin, step);
        if (bounds) start = Math.max(bounds.min, Math.min(bounds.max, start));
        return moveTimes(item, day, start);
    }

    function shiftTimes(item, deltaMin, deltaDays) {
        var sp = span(item);
        return times(addDays(sp.day, deltaDays || 0), sp.start + deltaMin, sp.end + deltaMin);
    }

    // range (boardRange): the moved edge stays within the stretch of board days
    // the item starts in.
    function resizeTimes(item, edge, toMin, range) {
        var sp = span(item);
        var start = sp.start, end = sp.end;
        var base = dayNumber(sp.day) * DAY_MIN, run = range ? runAt(base + sp.start, rangeRuns(range)) : null;
        if (run && edge === 'start') toMin = Math.max(toMin, run.s - base);
        else if (run) toMin = Math.min(toMin, run.e - base);
        if (edge === 'start') start = Math.min(toMin, end - MIN_ITEM);
        else end = Math.max(toMin, start + MIN_ITEM);
        return times(sp.day, start, end);
    }

    // Copies of the item, one after another, each ending at or before untilMin
    // (a board minute on the item's board day). The gap follows the nearest
    // earlier same-category item in dayItems; 30 minutes when there is none or
    // the gap would be negative.
    function repeatSlots(item, dayItems, untilMin) {
        var sp = span(item);
        var len = sp.end - sp.start;
        var cat = item.Category || 'Other';
        var here = absStart(sp);
        var prevStart = null, prevEnd = 0;
        (dayItems || []).forEach(function (o) {
            if (o === item || (o.cid && o.cid === item.cid)) return;
            if ((o.Category || 'Other') !== cat) return;
            var os = span(o);
            if (!os) return;
            var a = absStart(os);
            if (a >= here || (prevStart !== null && a <= prevStart)) return;
            prevStart = a;
            prevEnd = a + (os.end - os.start);
        });
        var gap = prevStart === null ? REPEAT_GAP : here - prevEnd;
        if (gap < 0) gap = REPEAT_GAP;
        var step = len + gap, out = [];
        if (step <= 0) return out;
        for (var s = sp.end + gap; s + len <= untilMin && out.length < 500; s += step) {
            out.push(times(sp.day, s, s + len));
        }
        return out;
    }

    // ---- The board's bounds: one rule for every gesture. ----

    // Where gestures keep items: from 5:00am on the first of the board's days
    // (the days it shows: 'YYYY-MM-DD') to 5:00am after the last, as naive times.
    // runs: the stretches of consecutive days, since an item may only lie on days
    // the board already shows. -> { firstDay, lastDay, start, end, runs: [{start, end}] }, or null.
    function boardRange(days) {
        var list = (days || []).filter(function (d) { return typeof d === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(d); }).sort();
        if (!list.length) return null;
        var runs = [], from = list[0];
        list.forEach(function (d, i) {
            var next = list[i + 1];
            if (next && diffDays(d, next) <= 1) return;
            runs.push({ start: toNaive(from, BOARD_DAY_START), end: toNaive(d, BOARD_DAY_START + DAY_MIN) });
            from = next;
        });
        return { firstDay: list[0], lastDay: list[list.length - 1], start: runs[0].start, end: runs[runs.length - 1].end, runs: runs };
    }

    function absNaive(naive) {
        var p = parseNaive(naive);
        return p ? dayNumber(p.day) * DAY_MIN + p.min : null;
    }

    // A range's runs as absolute minutes: [{s, e}].
    function rangeRuns(range) {
        return (range.runs || [range]).map(function (r) { return { s: absNaive(r.start), e: absNaive(r.end) }; });
    }

    // The run a start (absolute minutes) falls in, or null.
    function runAt(abs, runs) {
        for (var i = 0; i < runs.length; i++) if (runs[i].s <= abs && abs < runs[i].e) return runs[i];
        return null;
    }

    // An item from s to e (absolute minutes) lies wholly within one run. An item
    // with no length must start before the run's end (5:00am there is the next day).
    function placeFits(s, e, runs) {
        var r = runAt(s, runs);
        return !!r && e <= r.e;
    }

    // Each item's absolute start and end: [{s, e}].
    function spanRows(items) {
        var rows = [];
        (items || []).forEach(function (it) {
            var sp = span(it);
            if (sp) { var s = absStart(sp); rows.push({ s: s, e: s + (sp.end - sp.start) }); }
        });
        return rows;
    }

    function shiftFits(rows, t, runs) {
        return rows.every(function (r) { return placeFits(r.s + t, r.e + t, runs); });
    }

    // The shift between from and to (absolute minutes, both included) nearest to
    // at which every row fits, or null. Where to does not fit, the shift that
    // stops a row at the edge of its run, as near to as fits.
    function fitAlong(rows, from, to, runs) {
        if (shiftFits(rows, to, runs)) return to;
        var up = to > from, seen = {}, cands = [];
        rows.forEach(function (r) {
            runs.forEach(function (run) {
                var t = up ? run.e - r.e - (r.e === r.s ? 1 : 0) : run.s - r.s;
                if (seen[t] || (up ? t < from || t >= to : t > from || t <= to)) return;
                seen[t] = true;
                cands.push(t);
            });
        });
        cands.sort(function (a, b) { return up ? b - a : a - b; });
        for (var i = 0; i < cands.length; i++) if (shiftFits(rows, cands[i], runs)) return cands[i];
        return shiftFits(rows, from, runs) ? from : null;
    }

    // How far a group of items (or one item: the same rule) may move together:
    // dMin (board minutes) and dDays reduced toward 0 so that every item, moved by
    // both, lies wholly within bounds.range (boardRange). A day move is never cut
    // to part of a day: a shorter day move is tried, then none. When even that
    // leaves no room, nothing moves ({dMin: 0, dDays: 0}). Without a range the
    // move is returned as asked. -> {dMin, dDays}
    function groupShiftLimit(items, dMin, dDays, bounds) {
        var m = dMin || 0, d = dDays || 0;
        var rows = bounds && bounds.range ? spanRows(items) : [];
        if (!rows.length || (!m && !d)) return { dMin: m, dDays: d };
        var runs = rangeRuns(bounds.range);
        for (var k = d; ; k += d > 0 ? -1 : 1) {
            var t = fitAlong(rows, k * DAY_MIN, k * DAY_MIN + m, runs);
            if (t !== null) return { dMin: t - k * DAY_MIN, dDays: k };
            if (k === 0) return { dMin: 0, dDays: 0 };
        }
    }

    // ---- The server's own limit on an item's times (a safety net in the store). ----

    var WINDOW_DAYS = 7;

    // The date of an event's naive start or end, or null when it cannot be used.
    // As the server reads it (PHP '!Y-m-d' on the first ten characters): an
    // impossible date rolls over (11-31 is 12-01), and a year before 1000 (a
    // zero date) cannot be used.
    function eventDate(naive) {
        var m = typeof naive === 'string' ? /^(\d{4})-(\d{2})-(\d{2})/.exec(naive) : null;
        if (!m || +m[1] < 1000) return null;
        return addDays(m[1] + '-' + m[2] + '-' + m[3], 0);
    }

    // Where the server lets an item start: from 00:00 seven days before the
    // event's start date to 23:59 seven days after its end date. An end date that
    // is missing, unreadable or before the start date counts as the start date.
    // -> { start, end } (naive), or null when the start date cannot be read.
    function hardWindow(eventStart, eventEnd) {
        var first = eventDate(eventStart);
        if (!first) return null;
        var last = eventDate(eventEnd);
        if (!last || diffDays(first, last) < 0) last = first;
        return { start: addDays(first, -WINDOW_DAYS) + ' 00:00', end: addDays(last, WINDOW_DAYS) + ' 23:59' };
    }

    var OUTSIDE_EVENT = 'That time is outside the event.';
    var END_BEFORE_START = 'An item cannot end before it starts.';

    // Why the server would refuse an item's times, or '' (never a refusal the
    // server would not make). prev: the item before an update (null for an add);
    // only an update that touches the times is judged. limit: hardWindow, or null for none.
    function timesRefusal(item, prev, limit) {
        if (!item) return '';
        var s = absNaive(item.StartTime), e = absNaive(item.EndTime);
        var ps = prev ? absNaive(prev.StartTime) : null, pe = prev ? absNaive(prev.EndTime) : null;
        var startChanged = !prev || s !== ps, endChanged = !prev || e !== pe;
        if ((startChanged || endChanged) && s !== null && e !== null && e < s) return END_BEFORE_START;
        // The server judges the window whenever StartTime is sent, and every times
        // change here sends both times, so an end-only change is judged too.
        if ((startChanged || endChanged) && s !== null && limit && (s < absNaive(limit.start) || s > absNaive(limit.end))) return OUTSIDE_EVENT;
        return '';
    }

    // The event's own days ('YYYY-MM-DD'), as buildBoard draws them; [] without both dates.
    function eventDays(eventStart, eventEnd) {
        var s = parseNaive(eventStart), e = parseNaive(eventEnd), out = [];
        if (!s || !e) return out;
        var count = Math.max(0, Math.min(diffDays(s.day, e.day), MAX_DAYS - 1));
        for (var i = 0; i <= count; i++) out.push(addDays(s.day, i));
        return out;
    }

    // 'Thursday 19 November'.
    function dayDateLabel(day) {
        var p = dayParts(day);
        return dayLabel(day) + ' ' + p.d + ' ' + MONTHS[p.m - 1];
    }

    // A copy's times, straight after the item. range (boardRange): the copy moves
    // only as far as fits on the board's days; null when it has no room at all.
    function duplicateTimes(item, range) {
        var sp = span(item), len = sp.end - sp.start, shift = len;
        if (range) {
            shift = groupShiftLimit([item], len, 0, { range: range }).dMin;
            if (len > 0 && shift <= 0) return null;
        }
        return times(sp.day, sp.start + shift, sp.end + shift);
    }

    // Copies of the items without ids, the earliest placed at toDay / toStartMin
    // and the rest keeping their offsets from it. Nested values are copied.
    // range (boardRange): the copies move from where the items are only as far
    // as all of them fit on the board's days (groupShiftLimit); [] when there is
    // no room for them.
    function pastePlan(copied, toDay, toStartMin, range) {
        var rows = [];
        (copied || []).forEach(function (it) {
            var sp = span(it);
            if (sp) rows.push({ item: it, abs: absStart(sp), len: sp.end - sp.start, sp: sp });
        });
        rows.sort(function (a, b) { return a.abs - b.abs; });
        if (!rows.length) return [];
        var first = rows[0].sp, day = toDay, at = toStartMin;
        if (range) {
            var dDays = diffDays(first.day, toDay), dMin = toStartMin - first.start;
            var lim = groupShiftLimit(rows.map(function (r) { return r.item; }), dMin, dDays, { range: range });
            var asked = dDays * DAY_MIN + dMin, got = lim.dDays * DAY_MIN + lim.dMin;
            if ((asked && !got) || !shiftFits(spanRows(rows.map(function (r) { return r.item; })), got, rangeRuns(range))) return [];
            day = first.day;
            at = first.start + got;
        }
        return rows.map(function (r) {
            var c = JSON.parse(JSON.stringify(r.item));
            delete c.EventScheduleId;
            delete c.cid;
            var start = at + (r.abs - rows[0].abs);
            var t = times(day, start, start + r.len);
            c.StartTime = t.StartTime;
            c.EndTime = t.EndTime;
            return c;
        });
    }

    // One 'day range title ...' line per item, sorted by start.
    function clipboardText(items) {
        var rows = [];
        (items || []).forEach(function (it) {
            var sp = span(it);
            if (sp) rows.push({ item: it, abs: absStart(sp) });
        });
        rows.sort(function (a, b) { return a.abs - b.abs; });
        return rows.map(function (r) { return formatEntry(r.item, { withDay: true }); }).join('\n');
    }

    // ---- Bulk text: many lines in, the schedule out as text, conflicts. ----

    var MONTH_RE = '(' + MONTHS.map(function (m) { return m.slice(0, 3) + '(?:' + m.slice(3) + ')?'; }).join('|') + ')';
    var ORD = '(?:st|nd|rd|th)?', YEAR = '(?:,?\\s+\\d{4})?';
    // A date after a weekday: 'M/D', 'November 20' or '20 November', each with an optional year.
    var DATE_RE = '(?:(\\d{1,2})/(\\d{1,2})(?:/\\d{2,4})?' +
        '|' + MONTH_RE + '\\.?\\s+(\\d{1,2})' + ORD + YEAR +
        '|(\\d{1,2})' + ORD + '\\s+' + MONTH_RE + '\\.?' + YEAR + ')';
    // A date ends at a space or punctuation, but not at a dash into a number ('Mar 3-4').
    var DATE_AT = new RegExp('^' + DATE_RE + '(?![\\w/]|[\\-–]\\d)', 'i');
    var PUNCT = '\\s:;,.!\\-–—';
    var AFTER_WEEKDAY = new RegExp('^(?:$|[' + PUNCT + '])');
    var BEFORE_DATE = /^[\s,\-–—]+/;
    var LEAD_PUNCT = new RegExp('^[' + PUNCT + ']+');

    function monthNumber(name) {
        var k = name.slice(0, 3).toLowerCase();
        for (var i = 0; i < MONTHS.length; i++) if (MONTHS[i].slice(0, 3).toLowerCase() === k) return i + 1;
        return 0;
    }

    // The day a line starts with: a weekday name, a date ('11/21', 'Nov 21',
    // '21 November'), or a weekday then a date ('Sat 11/21', 'Saturday - Nov 21').
    // Returns { day, rest } with the punctuation after the day dropped from rest,
    // or null. A written date decides the day; the weekday decides only when there
    // is no date (or the date is not a real one). An empty rest is a heading.
    function leadingDay(line, days, near) {
        var wd = /^[a-z]+/i.exec(line), word = null, date = null, rest = line, m;
        if (wd && weekdayOf(wd[0]) >= 0 && AFTER_WEEKDAY.test(line.slice(wd[0].length))) {
            word = wd[0];
            rest = line.slice(word.length);
            var sep = BEFORE_DATE.exec(rest);
            if (sep && (m = DATE_AT.exec(rest.slice(sep[0].length)))) {
                date = m;
                rest = rest.slice(sep[0].length + m[0].length);
            }
        } else if ((m = DATE_AT.exec(line))) {
            date = m;
            rest = line.slice(m[0].length);
        } else {
            return null;
        }
        var day = null;
        if (date) {
            var mo = date[1] ? +date[1] : date[3] ? monthNumber(date[3]) : date[6] ? monthNumber(date[6]) : 0;
            var da = date[2] ? +date[2] : date[4] ? +date[4] : date[5] ? +date[5] : 0;
            day = mo && da ? resolveDayWord(mo + '/' + da, days) : null;
        }
        if (!day && word) day = resolveDayWord(word, days, near);
        return day ? { day: day, rest: rest.replace(LEAD_PUNCT, ''), date: !!date } : null;
    }

    // Paste formatting off a line: markdown emphasis pairs, a '# ' heading mark,
    // a leading bullet or list number, then single '*' or '_' wrapping the whole
    // line. '#tag' (no space) and a mid-line '_' stay. A line of only rule
    // characters ('---', '***', '- - -') becomes empty.
    function cleanLine(line) {
        var t = line.trim();
        if (/^[-*_=~•·–—\s]+$/.test(t)) return '';
        t = t.replace(/(\*\*|__)(.+?)\1/g, '$2').trim();
        t = t.replace(/^#{1,6}\s+/, '');
        t = t.replace(/^(?:[-*•·]|\d+[.)])\s+/, '');
        t = t.replace(/^([*_])(?!\s)(.*\S)\1$/, '$2');
        return t.trim();
    }

    // Whether an added item sets the clock for the next line (its end and its
    // length): not an all-day line or a long span across days (12 hours or
    // more); an overnight '10p to sat 2a' does. r: { span, startMin, endMin }.
    function setsClock(r) {
        return !(r && r.span && r.endMin - r.startMin >= 720);
    }

    // Lines of text to items, in order. Each item carries the day and the clock
    // to the next line; a heading line sets the day and starts it afresh (9:00,
    // the caller's default length). A line whose title has no letter or digit is
    // reported in unread with its 1-based line number; an item outside the event
    // (parseEntry's outside: off ctx.eventDays, or ctx.days without them) is
    // still returned, and also listed in outside.
    function parseLines(text, ctx) {
        ctx = ctx || {};
        var days = ctx.days || [];
        var day = ctx.day || days[0] || null;
        var baseDuration = ctx.lastDuration == null ? 60 : ctx.lastDuration;
        var lastEnd = ctx.lastEnd || null, lastDuration = baseDuration, prevStart = null;
        var items = [], unread = [], unmatched = [], outside = [];
        String(text == null ? '' : text).split(/\r\n|\r|\n/).forEach(function (raw, n) {
            var pasted = raw.trim(), line = cleanLine(raw);
            // The time order runs from the previous timed line; a blank line, a
            // heading, a new day and a line with no time each start it afresh.
            var prior = prevStart;
            prevStart = null;
            if (!line) return;
            var lead = leadingDay(line, days.length ? days : (day ? [day] : []), ctx.eventDays);
            if (lead && !lead.rest) {
                // A heading: only a day, perhaps with punctuation.
                if (!lastEnd || lastEnd.day !== lead.day) { lastEnd = null; lastDuration = baseDuration; }
                day = lead.day;
                return;
            }
            // A line led by a day: hand parseEntry that day as one 'M/D' word, the
            // form it already reads as the item's day.
            if (lead) line = +lead.day.slice(5, 7) + '/' + +lead.day.slice(8) + ' ' + lead.rest;
            var r = parseEntry(line, { days: days, day: day, lastEnd: lastEnd, lastDuration: lastDuration,
                staff: ctx.staff, canFeast: ctx.canFeast, locations: ctx.locations, eventDays: ctx.eventDays,
                today: ctx.today, items: (ctx.items || []).concat(items), leadingHour: true, prevStart: prior });
            if (r.setDay) { day = r.setDay; return; }
            if (!r.ok || !r.day || !/[\p{L}\p{N}]/u.test(r.title)) { unread.push({ line: n + 1, text: pasted }); return; }
            r.unmatchedLeads.forEach(function (u) { if (unmatched.indexOf(u) < 0) unmatched.push(u); });
            // The same rule as parseEntry's outside.
            if (r.outside) outside.push({ line: n + 1, text: pasted, day: r.day });
            // One item per day of an 'every day' line, after any ';' parts before it.
            entryParts(r).forEach(function (p) {
                var x = p.r, it = {
                    Title: x.title,
                    StartTime: p.start,
                    EndTime: p.end,
                    Category: x.category,
                    SecondaryCategory: x.secondaryCategory,
                    Location: x.location,
                    Description: x.description,
                    Cost: x.cost,
                    Leads: x.leads.map(function (l) { return { MundaneId: l.MundaneId, Persona: l.Persona, Auto: false }; })
                };
                if (x.dietary) it.Dietary = x.dietary;
                if (x.allergens) it.Allergens = x.allergens;
                items.push(it);
            });
            day = r.day;
            // An all-day line or a long span across days sets no clock for the
            // lines after it (an overnight '10p to sat 2a' does); on a new day
            // the next untimed line starts that day afresh, as after a heading.
            if (!setsClock(r)) {
                if (lastEnd && lastEnd.day !== r.day) { lastEnd = null; lastDuration = baseDuration; }
                return;
            }
            if (r.given.time) prevStart = { day: r.day, min: r.startMin };
            lastEnd = { day: r.day, min: r.endMin };
            lastDuration = r.endMin - r.startMin;
        });
        return { items: items, unread: unread, unmatchedLeads: unmatched, outside: outside };
    }

    // The items as text parseLines reads back: per board day in order, a
    // 'Friday, November 20' heading and one formatEntry line per item by start.
    function scheduleText(items) {
        var byDay = {};
        (items || []).forEach(function (it) {
            var sp = span(it);
            if (sp) (byDay[sp.day] = byDay[sp.day] || []).push({ item: it, start: sp.start });
        });
        return Object.keys(byDay).sort().map(function (d) {
            var rows = byDay[d].sort(function (a, b) { return a.start - b.start; });
            return [dayLabelLong(d)].concat(rows.map(function (r) { return formatEntry(r.item); })).join('\n');
        }).join('\n\n');
    }

    // Double bookings: for each pair of items whose times truly overlap (zero-length
    // items never do), a message on both for each shared lead (by MundaneId) and
    // for a shared location (ignoring case and the width of spaces). Keyed by cid,
    // or 'i' + EventScheduleId.
    function conflicts(items) {
        var rows = [];
        (items || []).forEach(function (it) {
            var key = it.cid || (it.EventScheduleId ? 'i' + it.EventScheduleId : null);
            var sp = key && span(it);
            if (!sp || sp.end <= sp.start) return;
            var abs = absStart(sp);
            rows.push({ key: key, item: it, start: abs, end: abs + (sp.end - sp.start),
                loc: String(it.Location || '').trim() });
        });
        rows.sort(function (a, b) { return a.start - b.start; });
        var out = {};
        function note(row, msg) {
            var list = out[row.key] = out[row.key] || [];
            if (list.indexOf(msg) < 0) list.push(msg);
        }
        function clash(row, other) {
            (row.item.Leads || []).forEach(function (l) {
                if (!l || !(other.item.Leads || []).some(function (o) { return o && +o.MundaneId === +l.MundaneId; })) return;
                note(row, l.Persona + ' is also on ' + other.item.Title);
            });
            if (row.loc && squash(row.loc) === squash(other.loc)) {
                note(row, row.loc + ' is also booked for ' + other.item.Title);
            }
        }
        for (var i = 0; i < rows.length; i++) {
            for (var j = i + 1; j < rows.length && rows[j].start < rows[i].end; j++) {
                clash(rows[i], rows[j]);
                clash(rows[j], rows[i]);
            }
        }
        return out;
    }

    // ---- Now and next: what an attendee wants to know during the event. ----

    var NOW_MAX = 4, NEXT_MAX = 3;

    // What is on at nowNaive and what starts next, or null when nowNaive is not
    // within the event's board days (the first day's 5:00am up to 5:00am after
    // the last day). -> { day, min, now: [entry], more, next: [entry] } (day and
    // min: now's board day and board minute), an entry being
    // { item, ongoing, start: {day, min}, end: {day, min} } (board day and board
    // minute, as boardPos). now: items in progress (start <= now < end),
    // ordinary ones first by start, then ongoing ones (isOngoing), at most
    // NOW_MAX; more counts the ones left out. next: items starting after now
    // and before the board day ends, by start, at most NEXT_MAX.
    function nowNext(items, nowNaive, eventStart, eventEnd) {
        var now = boardPos(nowNaive), evS = parseNaive(eventStart), evE = parseNaive(eventEnd);
        if (!now || !evS || !evE) return null;
        // An end before the start still holds the start day (as buildBoard).
        var lastDay = diffDays(evS.day, evE.day) > 0 ? evE.day : evS.day;
        if (diffDays(evS.day, now.day) < 0 || diffDays(now.day, lastDay) < 0) return null;
        var at = dayNumber(now.day) * DAY_MIN + now.min;
        var dayEnd = dayNumber(now.day) * DAY_MIN + BOARD_DAY_START + DAY_MIN;
        var cur = [], next = [];
        (items || []).forEach(function (it) {
            var sp = span(it);
            if (!sp) return;
            var s = absStart(sp), e = s + (sp.end - sp.start);
            // An end at or before now's board day's 5:00am is told on that day ('until 5a', not
            // 'until Sunday 5a'), whichever board day the item started on.
            var end = e <= dayEnd
                ? { day: now.day, min: e - dayNumber(now.day) * DAY_MIN }
                : boardPos(toNaive(sp.day, sp.end));
            var row = { abs: s, title: String(it.Title || ''), entry: {
                item: it, ongoing: isOngoing(it), start: boardPos(it.StartTime), end: end
            } };
            if (s <= at && at < e) cur.push(row);
            else if (s > at && s < dayEnd) next.push(row);
        });
        function order(a, b) {
            return (a.abs - b.abs) || (a.title < b.title ? -1 : a.title > b.title ? 1 : 0);
        }
        cur.sort(function (a, b) { return (a.entry.ongoing - b.entry.ongoing) || order(a, b); });
        next.sort(order);
        var pick = function (r) { return r.entry; };
        return {
            day: now.day,
            min: now.min,
            now: cur.slice(0, NOW_MAX).map(pick),
            more: Math.max(0, cur.length - NOW_MAX),
            next: next.slice(0, NEXT_MAX).map(pick)
        };
    }

    return {
        ONGOING_MIN: ONGOING_MIN,
        isOngoing: isOngoing,
        nowNext: nowNext,
        parseLines: parseLines,
        scheduleText: scheduleText,
        conflicts: conflicts,
        snap: snap,
        boardPos: boardPos,
        span: span,
        dragTarget: dragTarget,
        moveTimes: moveTimes,
        shiftTimes: shiftTimes,
        resizeTimes: resizeTimes,
        repeatSlots: repeatSlots,
        duplicateTimes: duplicateTimes,
        groupShiftLimit: groupShiftLimit,
        boardRange: boardRange,
        hardWindow: hardWindow,
        timesRefusal: timesRefusal,
        eventDays: eventDays,
        dayDateLabel: dayDateLabel,
        pastePlan: pastePlan,
        clipboardText: clipboardText,
        BOARD_DAY_START: BOARD_DAY_START,
        BOARD_DAY_END: BOARD_DAY_START + DAY_MIN, // 5:00am the next morning
        DAY_MIN: DAY_MIN,
        CATEGORY_NAMES: CATEGORY_NAMES,
        CATEGORY_TAGS: CATEGORY_TAGS,
        setCategories: setCategories,
        resetCategories: resetCategories,
        lateNight: lateNight,
        resolveCategoryTag: resolveCategoryTag,
        guessCategory: guessCategory,
        parseEntry: parseEntry,
        formatEntry: formatEntry,
        entryParts: entryParts,
        splitEntry: splitEntry,
        fmtSpan: fmtSpan,
        endDayWord: endDayWord,
        setsClock: setsClock,
        leadsForCategory: leadsForCategory,
        swapAutoLeads: swapAutoLeads,
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
