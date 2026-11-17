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
    var ONGOING_MIN = 240;
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
    function leadAt(text, i, staff) {
        var best = null;
        (staff || []).forEach(function (s) {
            if (!s || !s.Persona || !squash(s.Persona)) return;
            var re = personaPattern(s.Persona);
            re.lastIndex = i + 1;
            var m = re.exec(text);
            if (m && (!best || m[0].length > best.len)) best = { len: m[0].length, staff: s };
        });
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

    function tokenize(text, staff) {
        var out = [], i = 0, n = text.length;
        while (i < n) {
            var ch = text.charAt(i);
            if (/\s/.test(ch)) { i++; continue; }
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
        if (r && (!r.bare || i === firstIdx || r.next - 1 === lastIdx)) {
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
        var len = readLength(toks[i].value);
        if (len !== null) return { found: { start: null, end: null, length: len, then: false }, upto: i + 1 };
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
            if (hour) hit = { at: i, t: { found: { start: nightShift(defaultMin(hour)), end: null, length: null, then: false, a: hour }, upto: i + 1 } };
        }
        // Then, in a pasted list, a bare hour leading the line: '7 Feast'. Never a
        // trailing one: titles end in numbers ('Court 7', 'Round 2').
        var lead = leadingHour && !hit && firstIdx >= 0 && firstIdx < lastIdx && bareHourAt(toks, firstIdx);
        if (lead) hit = { at: firstIdx, t: { found: { start: nightShift(defaultMin(lead)), end: null, length: null, then: false, a: lead }, upto: firstIdx + 1 } };
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
        var toks = tokenize(String(text == null ? '' : text), ctx.staff);
        var given = { day: false, time: false, category: false, location: false, description: false, cost: false, leads: false };
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

        // Day: only the first token.
        var typedDay = null;
        if (!ctx.fixedDay && !ign.day && isWord(toks[0])) {
            typedDay = resolveDayWord(toks[0].value, days.length ? days : (curDay ? [curDay] : []), ctx.eventDays);
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
        var time = ign.time ? null : claimTime(toks, firstIdx, lastIdx, ctx.fixedStart != null, ctx.leadingHour);
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

        // Order context (pasted lists pass ctx.prevStart, the previous timed
        // line's board day and start): only on the same day.
        var ordered = !!(ctx.prevStart && ctx.prevStart.day === (ctx.fixedDay || typedDay || curDay));
        var unmarked = !!(time && time.a && !time.a.marked && (!time.b || !time.b.marked));
        var morningTitle = MORNING_RE.test(title);
        // Without an order context, an unmarked start is read again with the
        // final title's words ('feast 7-9' is evening). A 'Name:' prefix or a
        // #tag is gone from the title by now, so neither sets the bias.
        var bias = ordered ? null : timeBias(title);
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

    // ---- Bulk text: many lines in, the schedule out as text, a skeleton, conflicts. ----

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
        return day ? { day: day, rest: rest.replace(LEAD_PUNCT, '') } : null;
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
                leadingHour: true, prevStart: prior });
            if (r.setDay) { day = r.setDay; return; }
            if (!r.ok || !r.day || !/[\p{L}\p{N}]/u.test(r.title)) { unread.push({ line: n + 1, text: pasted }); return; }
            r.unmatchedLeads.forEach(function (u) { if (unmatched.indexOf(u) < 0) unmatched.push(u); });
            // The same rule as parseEntry's outside.
            if (r.outside) outside.push({ line: n + 1, text: pasted, day: r.day });
            items.push({
                Title: r.title,
                StartTime: r.start,
                EndTime: r.end,
                Category: r.category,
                SecondaryCategory: r.secondaryCategory,
                Location: r.location,
                Description: r.description,
                Cost: r.cost,
                Leads: r.leads.map(function (l) { return { MundaneId: l.MundaneId, Persona: l.Persona, Auto: false }; })
            });
            day = r.day;
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

    // The usual bones of an event as lines for parseLines. Days are headed
    // 'Fri:', or 'M/D:' when two of the event's days share a weekday.
    function skeletonText(days) {
        days = days || [];
        var seen = {}, shared = days.some(function (d) {
            var w = weekdayIndex(d);
            if (seen[w]) return true;
            seen[w] = true;
            return false;
        });
        return days.map(function (d, i) {
            var p = dayParts(d);
            var lines = [(shared ? p.m + '/' + p.d : dayLabel(d).slice(0, 3)) + ':'];
            if (i === 0) {
                lines.push('12p Troll Opens', '1p Tavern Opens', '6p Feast');
                if (days.length === 1) lines.push('8p Court');
            } else if (i < days.length - 1) {
                lines.push('9a Troll Opens', '11a Tavern Opens', '6p Feast', '8p Court');
            } else {
                lines.push('9a Troll Opens', '10a Clean Up', '12p Site Closes');
            }
            return lines.join('\n');
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
        skeletonText: skeletonText,
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
