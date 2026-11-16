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
 *   EvBoardEntry.create(hostEl, {
 *     getContext: () => ctx,      // parseEntry's ctx (days, day, lastEnd, ...)
 *     onParse(result|null),       // after every change; null when the line is empty
 *                                 // (result.ok is false while there is no title)
 *     onCommit(result) -> bool,   // Enter on a valid line or a day name
 *     onCancel(),                 // Esc on an empty line
 *     placeholder, compact
 *   }) -> { focus, setText, getText, clear, destroy, input, el, reparse, result }
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
        ['cost', 'fa-dollar-sign', 'cost']
    ];
    var PART_NAMES = {
        day: 'Day', time: 'Time', category: 'Category', secondary: 'Second category', title: 'Title',
        location: 'Location', description: 'Description', cost: 'Cost'
    };

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
            day: r.day ? C.dayLabel(r.day) : '',
            time: r.startMin != null ? C.fmtRange(r.startMin, r.endMin) : '',
            category: r.category || '',
            secondary: r.secondaryCategory || '',
            title: r.title || '',
            location: r.location || '',
            description: r.description || '',
            cost: r.cost == null ? '' : (r.cost === 0 ? 'Free' : '$' + Number(r.cost).toFixed(2))
        };
        var typed = {
            day: g.day, time: g.time, category: g.category, secondary: true, title: true,
            location: true, description: true, cost: true
        };
        var h = '';
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
                (canDismiss ? '<button type="button" class="evb-chip-x" data-ignore="' + p[2] + '" tabindex="-1"' +
                    ' aria-label="Read the ' + esc(PART_NAMES[part].toLowerCase()) + ' as title text" data-tip="Treat as title text">&times;</button>' : '') +
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

        var ignore = {};
        var result = null;
        var menu = null;          // { sug, options, active } while the list is open
        var dismissedAt = null;   // the suggestion position Esc closed, until the caret leaves it

        function context() {
            var ctx = Object.assign({}, opts.getContext ? opts.getContext() : {});
            ctx.ignore = Object.assign({}, ctx.ignore || {}, ignore);
            return ctx;
        }

        function parse() {
            var text = input.value;
            if (!text.trim()) {
                result = null;
                chipsEl.innerHTML = '';
            } else {
                result = C.parseEntry(text, context());
                chipsEl.innerHTML = chipsHtml(result, catDefs);
            }
            el.classList.remove('evb-entry-invalid');
            if (opts.onParse) opts.onParse(result);
        }

        // ---- suggestions ----
        function closeMenu() {
            menu = null;
            menuEl.hidden = true;
            menuEl.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function updateMenu() {
            var caret = input.selectionStart == null ? input.value.length : input.selectionStart;
            var sug = input.selectionStart === input.selectionEnd ? suggestionAt(input.value, caret) : null;
            if (sug && dismissedAt === sug.kind + ':' + sug.from) sug = null;
            else dismissedAt = null;
            var options = !sug ? [] : sug.kind === 'category' ? categoryOptions(sug.query)
                : locationOptions(sug.query, (opts.getContext ? opts.getContext() : {}).locations);
            options = options.slice(0, MAX_OPTIONS);
            // Nothing to pick when what is typed already is the only match.
            if (!options.length || (options.length === 1 && options[0].exact)) { closeMenu(); return; }
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
            if (!menu) return;
            var o = menu.options[i], sug = menu.sug, v = input.value;
            var caret = input.selectionStart == null ? v.length : input.selectionStart;
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

        // ---- events ----
        function onInput() {
            parse();
            updateMenu();
        }

        function commit() {
            if (!result || !(result.ok || result.setDay)) {
                // A line with no title cannot be added: say so on the field.
                if (input.value.trim()) {
                    el.classList.remove('evb-entry-invalid');
                    void el.offsetWidth; // restart the shake
                    el.classList.add('evb-entry-invalid');
                }
                return;
            }
            if (opts.onCommit && opts.onCommit(result) === false) return;
            clear();
        }

        function onKeyDown(e) {
            if (e.isComposing || e.keyCode === 229) return;
            if (menu) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    setActive(menu.active + (e.key === 'ArrowDown' ? 1 : -1));
                    return;
                }
                if (e.key === 'Enter' || (e.key === 'Tab' && !e.shiftKey)) {
                    e.preventDefault();
                    pick(menu.active);
                    return;
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    dismissedAt = menu.sug.kind + ':' + menu.sug.from;
                    closeMenu();
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
            updateMenu();
        }

        function onMenuDown(e) {
            e.preventDefault(); // keep focus in the input
            var o = e.target.closest && e.target.closest('.evb-entry-option');
            if (o) pick(+o.getAttribute('data-index'));
        }

        function onChipClick(e) {
            var x = e.target.closest && e.target.closest('.evb-chip-x');
            if (!x) return;
            ignore[x.getAttribute('data-ignore')] = true;
            parse();
            input.focus();
        }

        function onBlur() { closeMenu(); }

        input.addEventListener('input', onInput);
        input.addEventListener('keydown', onKeyDown);
        input.addEventListener('keyup', onCaret);
        input.addEventListener('click', onCaret);
        input.addEventListener('blur', onBlur);
        menuEl.addEventListener('mousedown', onMenuDown);
        chipsEl.addEventListener('mousedown', function (e) { if (e.target.closest('.evb-chip-x')) e.preventDefault(); });
        chipsEl.addEventListener('click', onChipClick);

        // ---- API ----
        function clear() {
            input.value = '';
            ignore = {};
            dismissedAt = null;
            closeMenu();
            parse();
        }

        function setText(text) {
            input.value = String(text == null ? '' : text);
            ignore = {};
            dismissedAt = null;
            closeMenu();
            parse();
            var n = input.value.length;
            try { input.setSelectionRange(n, n); } catch (e) { /* not focusable yet */ }
        }

        return {
            input: input,
            el: el,
            focus: function () { input.focus({ preventScroll: true }); },
            getText: function () { return input.value; },
            setText: setText,
            clear: clear,
            reparse: parse,
            result: function () { return result; },
            destroy: function () {
                closeMenu();
                if (el.parentNode) el.parentNode.removeChild(el);
            }
        };
    }

    return { create: create, suggestionAt: suggestionAt };
});
