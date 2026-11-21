/*
 * EvBoardEntry: the one-line entry widget of the Event Schedule Board's Edit
 * mode (the quick line, and later the box typed into inside a block).
 *
 * Every keystroke parses the line with EvBoardCore.parseEntry and shows what
 * was understood as chips; a chip's x tells the parser to read that part as
 * title text instead. Typing '#' suggests categories and ' at ' suggests the
 * schedule's locations. The widget never saves anything: Enter hands the parse
 * to opts.onCommit, which returns false to keep the text.
 *
 * Leads: '@' at the start of a word opens a list of event staff, then players
 * from the player search, and picking one adds a lead chip and takes the
 * '@query' out of the text. When the line's category has category leads they
 * appear as auto chips (evb-chip-auto); a removed auto chip stays removed for
 * that category until the line is cleared. An '@Persona' of a staff member
 * typed in full is a lead too; an '@word' that matches no one stays title text.
 * getLeads() is the chips: [{MundaneId, Persona, Auto}].
 *
 * A ';' line (parseEntry's result.before): the chips and their x show and act
 * on the last part only; earlier parts keep their own reading and typed leads.
 * A lead picked from the '@' list goes to the part it was typed in: in an
 * earlier part it joins that part's result.before[n].leads and shows as a chip
 * saying which item it leads; getLeads() stays the last part's.
 *
 * Title mode (setText(text, {titleOnly: true, leads})): nothing is parsed; the
 * result is {ok, titleOnly, title} with the text as typed, and the chips are the
 * leads alone. While an '@query' is being typed it is left out of the reading;
 * one still unresolved when the field is left or committed is kept as text.
 *
 *   EvBoardEntry.create(hostEl, {
 *     getContext: () => ctx,      // parseEntry's ctx (days, day, lastEnd, ...)
 *     onParse(result|null),       // after every change; null when the line is empty
 *                                 // (result.ok is false while there is no title)
 *     onCommit(result) -> bool,   // Enter on a valid line or a day name
 *     onCancel(),                 // Esc on an empty line
 *     getStaff: () => staff,      // [{MundaneId, Persona, RoleName, LeadCategories}]
 *     searchPlayers(query) -> Promise<[{MundaneId, Persona, Detail}]>,
 *     placeholder, compact
 *   }) -> { focus, setText, getText, clear, destroy, input, el, reparse, result, getLeads }
 */
(function (root, factory) {
    root.EvBoardEntry = factory(root.EvBoardCore, root);
})(this, function (C, win) {
    'use strict';

    var MAX_OPTIONS = 10; // every category fits
    var seq = 0;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function squash(s) { return String(s).toLowerCase().replace(/\s+/g, ' ').trim(); }

    // Chip parts in display order: [part, icon, parser 'ignore' key].
    var PARTS = [
        ['day', 'fa-calendar-day', 'day'],
        ['time', 'fa-clock', 'time'],
        ['category', 'fa-tag', 'category'],
        ['secondary', 'fa-tag', 'category'],
        ['title', 'fa-heading', null],
        ['location', 'fa-map-marker-alt', 'location'],
        ['description', 'fa-quote-left', 'description'],
        ['cost', 'fa-dollar-sign', 'cost'],
        ['dietary', 'fa-leaf', 'dietary'],
        ['allergens', 'fa-exclamation-triangle', 'dietary']
    ];
    var PART_NAMES = {
        day: 'Day', time: 'Time', category: 'Category', secondary: 'Second category', title: 'Title',
        location: 'Location', description: 'Description', cost: 'Cost', dietary: 'Dietary', allergens: 'Allergens'
    };

    // The day chip: 'Saturday', or for several days 'Every day' or 'Fri, Sat'.
    function dayText(r) {
        if (r.days && r.days.length > 1) {
            return r.everyDay ? 'Every day' : r.days.map(function (d) { return C.dayLabel(d).slice(0, 3); }).join(', ');
        }
        return r.day ? C.dayLabel(r.day) : '';
    }

    // The time chip: the range, and the item it hangs off ('after Opening Court').
    function timeText(r) {
        if (r.startMin == null) return '';
        var t = C.fmtSpan(r.day, r.startMin, r.endMin);
        if (!r.anchor) return t;
        var name = r.anchor.title.length > 24 ? r.anchor.title.slice(0, 23) + '\u2026' : r.anchor.title;
        return t + ' (' + r.anchor.kind + ' ' + name + ')';
    }

    function categoryDefs() {
        var list = (win.EvConfig && win.EvConfig.scheduleCategories) || [];
        var by = {};
        list.forEach(function (c) { by[c.name] = c; });
        return by;
    }

    // One chip per understood part. A guessed part is dimmer; an x appears only
    // where dismissing would change the reading.
    function chipsHtml(r, catDefs) {
        var g = r.given || {};
        var vals = {
            day: dayText(r),
            time: timeText(r),
            category: r.category || '',
            secondary: r.secondaryCategory || '',
            title: r.title || '',
            location: r.location || '',
            description: r.description || '',
            cost: r.cost == null ? '' : (r.cost === 0 ? 'Free' : '$' + Number(r.cost).toFixed(2)),
            dietary: r.dietary || '',
            allergens: r.allergens || ''
        };
        var typed = {
            day: g.day, time: g.time, category: g.category, secondary: true, title: true,
            location: true, description: true, cost: true, dietary: true, allergens: true
        };
        var h = '';
        // A ';' line: the items before the one being typed, counted.
        var before = (r.before || []).length;
        if (before) {
            h += '<span class="evb-chip evb-chip-more" data-part="more" data-tip="Items before the last \u2018; \u2019 in the line">' +
                '<i class="fas fa-layer-group" aria-hidden="true"></i>' +
                '<span class="evb-chip-label">' + esc('+' + before + (before === 1 ? ' item' : ' items')) + '</span></span>';
        }
        PARTS.forEach(function (p) {
            var part = p[0], val = vals[part];
            if (!val) return;
            var guess = !typed[part];
            var canDismiss = p[2] && (typed[part] || (part === 'category' && val !== 'Other'));
            var style = '';
            if (part === 'category' || part === 'secondary') {
                var def = catDefs[val] || catDefs.Other;
                if (def && def.bold) style = ' style="--evb-chip-fill:' + esc(def.bold) + '"';
            }
            h += '<span class="evb-chip evb-chip-' + part + (guess ? ' evb-chip-guess' : '') + '" data-part="' + part + '"' + style +
                (guess ? ' data-tip="' + esc(PART_NAMES[part]) + ' (a guess)"' : '') + '>' +
                '<i class="fas ' + p[1] + '" aria-hidden="true"></i>' +
                '<span class="evb-sr">' + esc(PART_NAMES[part]) + ': </span>' +
                '<span class="evb-chip-label">' + esc(val) + '</span>' +
                (canDismiss ? '<button type="button" class="evb-chip-x" data-ignore="' + p[2] + '"' +
                    ' aria-label="Not a ' + esc(PART_NAMES[part].toLowerCase()) + '" data-tip="Treat as title text">&times;</button>' : '') +
                '</span>';
        });
        return h;
    }

    // The suggestion the caret is in, from the text before it: a '#tag' being
    // typed, or the words after a standalone ' at '. Null when neither.
    function suggestionAt(text, caret) {
        var before = text.slice(0, caret);
        var tag = /(^|\s)#([^\s#]*)$/.exec(before);
        if (tag) return { kind: 'category', from: before.length - tag[2].length - 1, query: tag[2] };
        var at = /(^|\s)at\s+((?:[^\s#$"“]+\s+){0,5}[^\s#$"“]*)$/i.exec(before);
        if (at) return { kind: 'location', from: before.length - at[2].length, query: at[2] };
        return null;
    }

    // An '@' lead being typed: from an '@' at the start of a word to the caret
    // (names may hold spaces). Null when there is none.
    function leadQueryAt(text, caret) {
        var m = /(^|\s)@([^@\n]{0,40})$/.exec(text.slice(0, caret));
        return m ? { kind: 'lead', from: caret - m[2].length - 1, query: m[2] } : null;
    }

    // Lead chips, after the others: a person icon and the persona; an auto-filled
    // one has a dotted edge and says 'auto'. One picked for an earlier part of a
    // ';' line (l.of: that part's title) says which item it leads.
    function leadChipsHtml(leads) {
        return leads.map(function (l) {
            var tip = l.Auto ? 'Leads this category' : l.of != null ? 'Leads \u2018' + l.of + '\u2019' : '';
            return '<span class="evb-chip evb-chip-lead' + (l.Auto ? ' evb-chip-auto' : '') + '" data-part="lead"' +
                ' data-mundane-id="' + (+l.MundaneId) + '"' + (tip ? ' data-tip="' + esc(tip) + '"' : '') + '>' +
                '<i class="fas fa-user" aria-hidden="true"></i>' +
                '<span class="evb-sr">Lead: </span>' +
                '<span class="evb-chip-label">' + esc(l.Persona) + '</span>' +
                (l.Auto ? '<span class="evb-chip-suffix">auto</span>' : '') +
                '<button type="button" class="evb-chip-x" data-lead="' + (+l.MundaneId) + '"' +
                (l.of != null ? ' data-lead-part="' + (+l.part) + '"' : '') +
                ' aria-label="Remove lead ' + esc(l.Persona) + '" data-tip="Remove lead">&times;</button>' +
                '</span>';
        }).join('');
    }

    // The viewport x of the caret position 'index' in a text input.
    var measureCtx = null;
    function caretX(input, index) {
        var r = input.getBoundingClientRect();
        try {
            var cs = win.getComputedStyle(input);
            measureCtx = measureCtx || win.document.createElement('canvas').getContext('2d');
            measureCtx.font = cs.font || (cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily);
            var w = measureCtx.measureText(input.value.slice(0, index)).width;
            var x = r.left + (parseFloat(cs.borderLeftWidth) || 0) + (parseFloat(cs.paddingLeft) || 0) + w - input.scrollLeft;
            return Math.max(r.left, Math.min(x, r.right - 8));
        } catch (e) {
            return r.left;
        }
    }

    function categoryOptions(query) {
        var q = squash(query).replace(/ /g, '');
        var exact = C.resolveCategoryTag(query);
        return C.CATEGORY_NAMES.filter(function (name) {
            var tag = C.CATEGORY_TAGS[name];
            return !q || name === exact || name.toLowerCase().replace(/ /g, '').indexOf(q) === 0 || tag.indexOf(q) === 0;
        }).map(function (name) {
            var flat = name.toLowerCase().replace(/ /g, '');
            return { label: name, insert: '#' + C.CATEGORY_TAGS[name], exact: q === C.CATEGORY_TAGS[name] || q === flat };
        });
    }

    function locationOptions(query, locations) {
        var q = squash(query);
        var starts = [], contains = [];
        (locations || []).forEach(function (loc) {
            var l = squash(loc);
            if (!q || l.indexOf(q) === 0) starts.push(loc);
            else if (l.indexOf(q) > 0) contains.push(loc);
        });
        return starts.concat(contains).map(function (loc) {
            return { label: loc, insert: loc, exact: squash(loc) === q };
        });
    }

    function create(hostEl, opts) {
        opts = opts || {};
        var id = 'evb-entry-' + (++seq);
        var doc = win.document;
        var el = doc.createElement('div');
        el.className = 'evb-entry' + (opts.compact ? ' evb-entry-compact' : '');
        el.innerHTML =
            '<div class="evb-entry-field">' +
            '<input type="text" class="evb-entry-input" autocomplete="off" autocapitalize="off" spellcheck="false"' +
            ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' + id + '-menu"' +
            ' aria-describedby="' + id + '-chips" placeholder="' + esc(opts.placeholder || '') + '">' +
            '<div class="evb-entry-menu" id="' + id + '-menu" role="listbox" hidden></div>' +
            '</div>' +
            '<div class="evb-entry-chips" id="' + id + '-chips"></div>';
        hostEl.appendChild(el);
        var input = el.querySelector('.evb-entry-input');
        var menuEl = el.querySelector('.evb-entry-menu');
        var chipsEl = el.querySelector('.evb-entry-chips');
        var catDefs = categoryDefs();

        var ignore = {};          // the chips' x: part index -> parseEntry ignore keys
        var result = null;
        var menu = null;          // { sug, options, active } while the list is open
        var dismissedAt = null;   // the suggestion position Esc closed, until the caret leaves it
        var titleOnly = false;    // a rename: the text is the title, nothing is parsed
        var picked = [];          // leads given (a rename's own) or picked: [{MundaneId, Persona, Auto, part}]
                                  // (part: the ';' part picked in; null, or the last part or past it, is the line's own)
        var removed = {};         // 'p<part>:<id>' a typed '@Persona' removed; 'a<part>:<id>|<category>' an auto lead removed
        var leadsNow = [];        // the lead chips of the last part: picked, then typed, then the category's
        var leadsEarlier = [];    // the lead chips picked for an earlier part of a ';' line
        var search = null;        // { query, pending, results } of the player search
        var searchSeq = 0, searchTimer = 0;
        var seenPlayers = [];     // personas the last search found (keeps a spaced '@query' going)

        function staff() { return (opts.getStaff && opts.getStaff()) || []; }

        function context() {
            var ctx = Object.assign({}, opts.getContext ? opts.getContext() : {});
            // A chip's x applies to the part it was shown for; ctx.ignore stays the last part's.
            ctx.partIgnore = ignore;
            ctx.staff = staff();
            // An '@word' that matches no one stays in the title as typed.
            ctx.keepUnmatchedLeads = true;
            return ctx;
        }

        function caretPos() { return input.selectionStart == null ? input.value.length : input.selectionStart; }

        // The '@query' at the caret while it can still name someone: one word, or
        // words some staff member or a found player's persona contains.
        function leadSuggestion() {
            if (input.selectionStart !== input.selectionEnd) return null;
            var sug = leadQueryAt(input.value, caretPos());
            if (!sug || !/\s/.test(sug.query)) return sug;
            var q = squash(sug.query);
            var names = staff().map(function (s) { return s.Persona; }).concat(seenPlayers);
            return names.some(function (n) { return squash(n).indexOf(q) >= 0; }) ? sug : null;
        }
        function activeLead() {
            var sug = leadSuggestion();
            return sug && dismissedAt !== 'lead:' + sug.from ? sug : null;
        }

        // The text without an '@query' still being typed.
        function textToRead() {
            var v = input.value, a = activeLead();
            return a ? v.slice(0, a.from) + v.slice(a.from + 1 + a.query.length) : v;
        }

        // The part of the parse the chips show (its last), or 0.
        function lastPart() { return result && !titleOnly && result.part ? result.part : 0; }
        function onLast(l) { return l.part == null || l.part >= lastPart(); }

        // The last part's lead chips, and those picked for an earlier part. An
        // earlier part's picked leads join its parsed leads (result.before[n].leads),
        // which its item takes along with its typed ones; one picked for a part
        // that is no longer an item is left out.
        function computeLeads() {
            var out = [], earlier = [], part = lastPart();
            var has = function (mid) { return out.some(function (l) { return l.MundaneId === mid; }); };
            picked.forEach(function (l) {
                if (onLast(l) && !has(+l.MundaneId)) out.push({ MundaneId: +l.MundaneId, Persona: l.Persona, Auto: !!l.Auto, src: 'x' });
            });
            if (result && !titleOnly) {
                (result.leads || []).forEach(function (l) {
                    if (!removed['p' + part + ':' + l.MundaneId] && !has(l.MundaneId)) out.push({ MundaneId: l.MundaneId, Persona: l.Persona, Auto: false, src: 'p' });
                });
                var cat = result.category;
                C.leadsForCategory(staff(), cat).forEach(function (l) {
                    if (!removed['a' + part + ':' + l.MundaneId + '|' + cat] && !has(l.MundaneId)) {
                        out.push({ MundaneId: l.MundaneId, Persona: l.Persona, Auto: true, src: 'a', cat: cat });
                    }
                });
                (result.before || []).forEach(function (b) {
                    picked.forEach(function (l) {
                        if (onLast(l) || l.part !== b.part) return;
                        var mid = +l.MundaneId;
                        if (!b.leads.some(function (x) { return x.MundaneId === mid; })) b.leads.push({ MundaneId: mid, Persona: l.Persona });
                        earlier.push({ MundaneId: mid, Persona: l.Persona, Auto: false, src: 'x', part: b.part, of: b.title });
                    });
                });
            }
            leadsNow = out;
            leadsEarlier = earlier;
        }

        function parse() {
            var text = textToRead();
            if (titleOnly) {
                // Stored as typed: only the ends are trimmed.
                result = input.value.trim() ? { ok: text.trim() !== '', titleOnly: true, title: text.trim(), given: {} } : null;
            } else {
                result = text.trim() ? C.parseEntry(text, context()) : null;
            }
            computeLeads();
            chipsEl.innerHTML = (result && !titleOnly ? chipsHtml(result, catDefs) : '') + leadChipsHtml(leadsNow.concat(leadsEarlier));
            el.classList.remove('evb-entry-invalid');
            if (opts.onParse) opts.onParse(result);
        }

        // ---- leads ----
        // part: the ';' part the '@' was typed in (null in a rename).
        function addLead(person, part) {
            var mid = +person.MundaneId;
            var p = part == null ? null : part;
            // One person once per part: picking an auto-filled lead makes it hand-picked.
            picked = picked.filter(function (l) { return !(+l.MundaneId === mid && (l.part == null ? null : l.part) === p); });
            picked.push({ MundaneId: mid, Persona: person.Persona, Auto: false, part: p });
            if (p != null) delete removed['p' + p + ':' + mid];
        }

        // part: given for a chip picked for an earlier part; else a last-part chip.
        function removeLead(mid, part) {
            if (part != null) {
                picked = picked.filter(function (x) { return !(+x.MundaneId === mid && x.part === part); });
                return;
            }
            var l = leadsNow.filter(function (x) { return x.MundaneId === mid; })[0];
            if (!l) return;
            var n = lastPart();
            if (l.src === 'x') picked = picked.filter(function (x) { return !(+x.MundaneId === mid && onLast(x)); });
            else if (l.src === 'p') removed['p' + n + ':' + mid] = true;
            else removed['a' + n + ':' + mid + '|' + l.cat] = true;
        }

        // The ';' part of the line that text (the line up to a point) ends in.
        function partAt(text) {
            if (titleOnly) return null;
            var ctx = context();
            // Inside a block the line is one item (parseEntry does not split it).
            if (ctx.fixedDay || ctx.fixedStart != null) return 0;
            return Math.max(0, C.splitEntry(text, ctx).length - 1);
        }

        function cancelSearch() {
            searchSeq++;
            if (searchTimer) win.clearTimeout(searchTimer);
            searchTimer = 0;
            search = null;
        }

        // The player search, after the same pause as the schedule modal's.
        function startSearch(q) {
            cancelSearch();
            if (!opts.searchPlayers || q.length < 2) return;
            var mine = searchSeq;
            search = { query: q, pending: true, results: [] };
            searchTimer = win.setTimeout(function () {
                searchTimer = 0;
                var done = function (list) {
                    if (mine !== searchSeq) return;
                    search.pending = false;
                    search.results = Array.isArray(list) ? list : [];
                    seenPlayers = search.results.map(function (p) { return p.Persona || ''; });
                    if (doc.activeElement !== input) return;
                    updateMenu();
                    parse();
                };
                var p;
                try { p = Promise.resolve(opts.searchPlayers(q)); } catch (e) { p = Promise.reject(e); }
                p.then(done, function () { done([]); });
            }, 220);
        }

        function leadOptions(sug) {
            var q = squash(sug.query);
            var team = staff();
            var staffIds = team.map(function (s) { return +s.MundaneId; });
            var staffOpts = team.filter(function (s) { return !q || squash(s.Persona).indexOf(q) >= 0; })
                .map(function (s) { return { kind: 'staff', person: { MundaneId: +s.MundaneId, Persona: s.Persona }, staff: s }; });
            if (!search || search.query !== q) startSearch(q);
            var players = search && search.query === q ? search.results : [];
            var playerOpts = players.filter(function (p) { return +p.MundaneId > 0 && staffIds.indexOf(+p.MundaneId) < 0; })
                .map(function (p) { return { kind: 'player', person: { MundaneId: +p.MundaneId, Persona: p.Persona }, detail: p.Detail || '' }; });
            return { staff: staffOpts, players: playerOpts, pending: !!(search && search.query === q && search.pending) };
        }

        function leadOptionHtml(o, i) {
            var inner;
            if (o.kind === 'staff') {
                var leads = o.staff.LeadCategories || [];
                inner = '<span class="evb-entry-option-name">' + esc(o.person.Persona) + '</span>' +
                    (o.staff.RoleName ? '<span class="evb-entry-option-muted">' + esc(o.staff.RoleName) + '</span>' : '') +
                    (leads.length ? '<span class="evb-entry-option-swatches" aria-hidden="true">' + leads.map(function (c) {
                        var def = catDefs[c] || {};
                        return '<span class="evb-legend-swatch" style="background:' + esc(def.bold || '#616161') + '"></span>';
                    }).join('') + '</span><span class="evb-sr">, leads ' + esc(leads.join(', ')) + '</span>' : '');
            } else {
                inner = '<span class="evb-entry-option-name">' + esc(o.person.Persona) +
                    (o.detail ? ' <span class="evb-entry-option-muted">(' + esc(o.detail) + ')</span>' : '') + '</span>';
            }
            return '<div class="evb-entry-option evb-entry-option-lead" role="option" id="' + id + '-opt-' + i + '" data-index="' + i + '"' +
                ' aria-selected="false">' + inner + '</div>';
        }

        function showLeadMenu(sug) {
            var found = leadOptions(sug);
            var options = found.staff.concat(found.players);
            var keep = menu && menu.sug.kind === 'lead' && menu.sug.from === sug.from ? menu.active : 0;
            menu = { sug: sug, options: options, active: 0 };
            var h = '', i = 0;
            if (found.staff.length) {
                h += '<div class="evb-entry-menu-head" aria-hidden="true">Event staff</div>';
                found.staff.forEach(function (o) { h += leadOptionHtml(o, i++); });
            }
            if (found.players.length) {
                h += '<div class="evb-entry-menu-head" aria-hidden="true">Players</div>';
                found.players.forEach(function (o) { h += leadOptionHtml(o, i++); });
            }
            if (found.pending) h += '<div class="evb-entry-menu-note" role="status">Searching…</div>';
            else if (!options.length) h += '<div class="evb-entry-menu-note" role="status">No one found</div>';
            menuEl.innerHTML = h;
            menuEl.classList.add('evb-entry-menu-fixed');
            menuEl.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            setActive(Math.min(keep, Math.max(options.length - 1, 0)));
            placeLeadMenu();
        }

        // Fixed under the '@' so no scrolling frame clips it; above the input when
        // there is no room below.
        function placeLeadMenu() {
            if (!menu || menu.sug.kind !== 'lead') return;
            var r = input.getBoundingClientRect();
            var w = menuEl.offsetWidth, h = menuEl.offsetHeight;
            var vw = win.innerWidth || doc.documentElement.clientWidth;
            var vh = win.innerHeight || doc.documentElement.clientHeight;
            var left = Math.max(8, Math.min(caretX(input, menu.sug.from), vw - w - 8));
            var top = r.bottom + 4;
            if (top + h > vh && r.top - 4 - h > 0) top = r.top - 4 - h;
            menuEl.style.left = Math.round(left) + 'px';
            menuEl.style.top = Math.round(top) + 'px';
        }

        function pickLead(o) {
            var sug = menu.sug, v = input.value;
            var before = v.slice(0, sug.from), rest = v.slice(sug.from + 1 + sug.query.length);
            if (!before || /\s$/.test(before)) rest = rest.replace(/^\s+/, '');
            input.value = before + rest;
            input.setSelectionRange(before.length, before.length);
            addLead(o.person, partAt(before));
            cancelSearch();
            closeMenu();
            parse();
            updateMenu();
        }

        // ---- suggestions ----
        function closeMenu() {
            menu = null;
            menuEl.hidden = true;
            menuEl.innerHTML = '';
            menuEl.classList.remove('evb-entry-menu-fixed');
            menuEl.style.left = menuEl.style.top = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function updateMenu() {
            var caret = caretPos();
            var collapsed = input.selectionStart === input.selectionEnd;
            var sug = leadSuggestion() || (collapsed ? suggestionAt(input.value, caret) : null);
            if (sug && dismissedAt === sug.kind + ':' + sug.from) sug = null;
            else dismissedAt = null;
            if (sug && sug.kind === 'lead') { showLeadMenu(sug); return; }
            cancelSearch();
            var options = !sug ? [] : sug.kind === 'category' ? categoryOptions(sug.query)
                : locationOptions(sug.query, (opts.getContext ? opts.getContext() : {}).locations);
            options = options.slice(0, MAX_OPTIONS);
            // Nothing to pick when what is typed already is the only match.
            if (!options.length || (options.length === 1 && options[0].exact)) { closeMenu(); return; }
            closeMenu();
            menu = { sug: sug, options: options, active: 0 };
            var head = sug.kind === 'category' ? 'Category' : 'Locations in this schedule';
            menuEl.innerHTML = '<div class="evb-entry-menu-head" aria-hidden="true">' + head + '</div>' +
                options.map(function (o, i) {
                    var swatch = '';
                    if (sug.kind === 'category') {
                        var def = catDefs[o.label] || {};
                        swatch = '<span class="evb-legend-swatch" style="background:' + esc(def.bold || '#616161') + '"></span>';
                    }
                    return '<div class="evb-entry-option" role="option" id="' + id + '-opt-' + i + '" data-index="' + i + '"' +
                        ' aria-selected="false">' + swatch + '<span>' + esc(o.label) + '</span>' +
                        (sug.kind === 'category' ? '<span class="evb-entry-option-tag">' + esc(o.insert) + '</span>' : '') + '</div>';
                }).join('');
            menuEl.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            setActive(0);
        }

        function setActive(i) {
            if (!menu) return;
            var n = menu.options.length;
            if (!n) { input.removeAttribute('aria-activedescendant'); return; }
            menu.active = (i + n) % n;
            menuEl.querySelectorAll('.evb-entry-option').forEach(function (o, k) {
                var on = k === menu.active;
                o.classList.toggle('evb-entry-option-active', on);
                o.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            input.setAttribute('aria-activedescendant', id + '-opt-' + menu.active);
        }

        // Replace the token being typed with the picked option and a space.
        function pick(i) {
            if (!menu || !menu.options[i]) return;
            var o = menu.options[i], sug = menu.sug, v = input.value;
            if (sug.kind === 'lead') { pickLead(o); return; }
            var caret = caretPos();
            var rest = v.slice(caret);
            if (sug.kind === 'category') rest = rest.replace(/^\S*/, ''); // the rest of the tag word
            rest = rest.replace(/^\s+/, '');
            var head = v.slice(0, sug.from) + o.insert + ' ';
            input.value = head + rest;
            input.setSelectionRange(head.length, head.length);
            closeMenu();
            parse();
            updateMenu();
        }

        // Esc (or Enter with nothing to pick) closes the list and leaves the text.
        function dismissMenu() {
            dismissedAt = menu.sug.kind + ':' + menu.sug.from;
            cancelSearch();
            closeMenu();
            parse(); // an '@query' left as typed is read as text again
        }

        // ---- events ----
        // The list first: it decides whether an '@query' is still being typed.
        function onInput() {
            updateMenu();
            parse();
        }

        function commit() {
            // An '@query' still open is committed as the text it is.
            settleQuery();
            if (!result || !(result.ok || result.setDay)) {
                // A line with no title cannot be added: say so on the field, in words
                // as well as the shake (a line of day, time and category chips with
                // nothing left over looks complete). parse() rebuilds the chips on the
                // next keystroke, which clears the note.
                if (input.value.trim()) {
                    el.classList.remove('evb-entry-invalid');
                    void el.offsetWidth; // restart the shake
                    el.classList.add('evb-entry-invalid');
                    if (!chipsEl.querySelector('.evb-chip-note')) {
                        chipsEl.insertAdjacentHTML('beforeend',
                            '<span class="evb-chip evb-chip-note" role="status">' +
                            '<i class="fas fa-exclamation-circle" aria-hidden="true"></i>' +
                            '<span class="evb-chip-label">Add a title, then press Enter</span></span>');
                    }
                }
                return;
            }
            if (opts.onCommit && opts.onCommit(result) === false) return;
            clear();
        }

        function onKeyDown(e) {
            if (e.isComposing || e.keyCode === 229) return;
            if (menu) {
                var n = menu.options.length;
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    setActive(menu.active + (e.key === 'ArrowDown' ? 1 : -1));
                    return;
                }
                if (e.key === 'Enter' || (e.key === 'Tab' && !e.shiftKey)) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (n) pick(menu.active);
                    else dismissMenu();
                    return;
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    dismissMenu();
                    return;
                }
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                commit();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                if (input.value) clear();
                else if (opts.onCancel) opts.onCancel();
            }
        }

        // Caret moves (arrows, clicks) can enter or leave a suggestion.
        function onCaret(e) {
            if (e.type === 'keyup' && /^(Arrow(Up|Down)|Enter|Tab|Escape)$/.test(e.key)) return;
            if (e.type === 'keyup' && !/^(Arrow(Left|Right)|Home|End)$/.test(e.key)) return;
            var was = textToRead();
            updateMenu();
            if (textToRead() !== was) parse();
        }

        function onMenuDown(e) {
            e.preventDefault(); // keep focus in the input
            var o = e.target.closest && e.target.closest('.evb-entry-option');
            if (o) pick(+o.getAttribute('data-index'));
        }

        function onChipClick(e) {
            var x = e.target.closest && e.target.closest('.evb-chip-x');
            if (!x) return;
            if (x.hasAttribute('data-lead')) {
                removeLead(+x.getAttribute('data-lead'), x.hasAttribute('data-lead-part') ? +x.getAttribute('data-lead-part') : null);
            } else {
                // Only the part the chips show: an earlier part keeps its reading.
                var n = lastPart();
                ignore[n] = ignore[n] || {};
                ignore[n][x.getAttribute('data-ignore')] = true;
            }
            parse();
            input.focus();
        }

        // Leaving the field settles an unresolved '@query' as ordinary text, as
        // Enter with nothing to pick does: nothing typed is dropped.
        function onBlur() {
            cancelSearch();
            closeMenu();
            settleQuery();
        }

        // An '@query' still open becomes ordinary text.
        function settleQuery() {
            var a = activeLead();
            if (!a) return;
            dismissedAt = 'lead:' + a.from;
            parse();
        }

        function onViewportMove() { if (menu && menu.sug.kind === 'lead') placeLeadMenu(); }

        input.addEventListener('input', onInput);
        input.addEventListener('keydown', onKeyDown);
        input.addEventListener('keyup', onCaret);
        input.addEventListener('click', onCaret);
        input.addEventListener('blur', onBlur);
        menuEl.addEventListener('mousedown', onMenuDown);
        chipsEl.addEventListener('mousedown', function (e) { if (e.target.closest('.evb-chip-x')) e.preventDefault(); });
        chipsEl.addEventListener('click', onChipClick);
        win.addEventListener('scroll', onViewportMove, true);
        win.addEventListener('resize', onViewportMove);

        // ---- API ----
        function reset(o) {
            ignore = {};
            dismissedAt = null;
            titleOnly = !!(o && o.titleOnly);
            picked = (o && o.leads || []).map(function (l) {
                return { MundaneId: +l.MundaneId, Persona: l.Persona, Auto: !!l.Auto };
            });
            removed = {};
            seenPlayers = [];
            cancelSearch();
            closeMenu();
        }

        function clear() {
            input.value = '';
            reset(null);
            parse();
        }

        // o: {titleOnly, leads} for a rename; a plain line otherwise.
        function setText(text, o) {
            input.value = String(text == null ? '' : text);
            reset(o);
            var n = input.value.length;
            try { input.setSelectionRange(n, n); } catch (e) { /* not focusable yet */ }
            // Text put in whole is read whole: an '@' at its end is not being typed.
            var tail = leadQueryAt(input.value, n);
            if (tail) dismissedAt = 'lead:' + tail.from;
            parse();
        }

        return {
            input: input,
            el: el,
            focus: function () { input.focus({ preventScroll: true }); },
            getText: function () { return input.value; },
            setText: setText,
            clear: clear,
            reparse: parse,
            settleQuery: settleQuery,
            result: function () { return result; },
            getLeads: function () {
                return leadsNow.map(function (l) { return { MundaneId: l.MundaneId, Persona: l.Persona, Auto: !!l.Auto }; });
            },
            destroy: function () {
                cancelSearch();
                closeMenu();
                win.removeEventListener('scroll', onViewportMove, true);
                win.removeEventListener('resize', onViewportMove);
                if (el.parentNode) el.parentNode.removeChild(el);
            }
        };
    }

    return { create: create, suggestionAt: suggestionAt, leadQueryAt: leadQueryAt };
});
