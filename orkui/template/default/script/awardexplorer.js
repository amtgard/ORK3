/* ============================================================
   Award Explorer — filter builder, run, render, share.

   Reads only window.AE (uir, registry, initial, linkParam,
   defaultMonths). The server is the authority on what is a valid
   criterion, operand and value; everything here is convenience.
   A rule the UI lets you build and the server rejects shows the
   server's message against the row — the UI never silently drops it.
   ============================================================ */
(function () {
    'use strict';
    if (!window.AE || !AE.registry) { return; }

    var CRIT = AE.registry.criteria || {};
    var COLS = AE.registry.columns || {};
    var OPTS = AE.registry.options || {};
    var $ = function (id) { return document.getElementById(id); };

    // Criteria that say WHERE, in both readings: where the award was given, and
    // where the recipient is now. A quick pick keeps these and replaces the rest.
    var SCOPE_CRIT = ['kingdom', 'park', 'home_kingdom', 'home_park'];
    // Filtering on one of these without its column on screen is what made
    // "Park is Felfrost" look broken, so the builder turns the matching one on.
    var CRIT_COLUMN = {
        park: 'park',
        kingdom: 'kingdom',
        home_park: 'recipient_park',
        home_kingdom: 'recipient_kingdom',
        given_by: 'given_by',
        rank: 'rank'
    };

    /**
     * The opening banner says "filters start on X, press Run". That is only true
     * while the scope rule is still there and nothing has run yet. Clearing the
     * filters used to leave it claiming a filter that no longer existed, because
     * it was only ever removed inside run().
     */
    function syncScopeNote() {
        var note = document.querySelector('.ae-scope');
        if (!note) { return; }
        var hasScope = leaves(state.tree).some(function (r) { return SCOPE_CRIT.indexOf(r.c) !== -1; });
        if (!hasScope) { note.remove(); }
    }

    function showColumnFor(critId) {
        var col = CRIT_COLUMN[critId];
        if (col && COLS[col] && state.columns.indexOf(col) === -1) {
            state.columns.push(col);
            renderColumns();
        }
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Operand wording depends on what is being compared. One shared map read
    // "is after / more than" for every comparison, which is nonsense on a Rank
    // and clutter on a date.
    var OPLABEL = {
        date: {
            eq: 'is', ne: 'is not',
            gt: 'is after', gte: 'is on or after',
            lt: 'is before', lte: 'is on or before',
            between: 'is between'
        },
        number: {
            eq: 'is', ne: 'is not',
            gt: 'is more than', gte: 'is at least',
            lt: 'is less than', lte: 'is at most',
            between: 'is between'
        },
        set: { is: 'is', is_not: 'is not', in: 'is any of', not_in: 'is none of' }
    };

    function opLabel(op, type) {
        var table = (type === 'date' || type === 'number') ? OPLABEL[type] : OPLABEL.set;
        return table[op] || OPLABEL.set[op] || OPLABEL.number[op] || op;
    }

    // ---- state -------------------------------------------------------------
    // The filter is a TREE, the shape the server already validates: a group has an
    // op and children, and a child is either a rule or another group. MAX_DEPTH on
    // the server is 6; this stops one short so the builder cannot construct
    // something the server would then refuse.
    var MAX_DEPTH = 5;
    var state = {
        tree: { op: 'AND', children: [] },
        columns: [],
        search: '',
        includeRevoked: false,
        includeStripped: false
    };
    var lastRows = [];
    var lastColumns = [];
    var dt = null;

    /** Strip every scope rule, wherever it sits in the tree. */
    function dropScopeRules(n) {
        if (!isGroup(n)) { return; }
        n.children = n.children.filter(function (c) {
            if (isGroup(c)) { dropScopeRules(c); return c.children.length > 0; }
            return SCOPE_CRIT.indexOf(c.c) === -1;
        });
    }

    function isGroup(n) { return n && Object.prototype.hasOwnProperty.call(n, 'children'); }

    /** The node at a path; [] is the root group. */
    function nodeAt(path) {
        var n = state.tree;
        for (var i = 0; i < path.length; i++) {
            if (!isGroup(n)) { return null; }
            n = n.children[path[i]];
        }
        return n || null;
    }

    function removeAt(path) {
        if (!path.length) { return; }
        var parent = nodeAt(path.slice(0, -1));
        if (isGroup(parent)) { parent.children.splice(path[path.length - 1], 1); }
    }

    function pathOf(el) {
        var raw = el && el.getAttribute('data-path');
        if (raw === null || raw === undefined) { return null; }
        return raw === '' ? [] : raw.split('.').map(Number);
    }

    /** Every rule in the tree, in order. */
    function leaves(n, out) {
        out = out || [];
        if (!n) { return out; }
        if (isGroup(n)) { n.children.forEach(function (c) { leaves(c, out); }); }
        else { out.push(n); }
        return out;
    }

    function leafWire(r) {
        var leaf = { c: r.c, o: r.o, v: r.v };
        if (r.p != null) { leaf.p = r.p; }
        return leaf;
    }

    /**
     * The tree as the server wants it: finished rules only, and groups that end up
     * empty dropped. A half-filled row is one the person is still writing, and
     * sending it would only return a validation error about an unfinished rule.
     */
    function pruneTree(n) {
        if (!isGroup(n)) { return ruleComplete(n) ? leafWire(n) : null; }
        var kids = n.children.map(pruneTree).filter(Boolean);
        return kids.length ? { op: n.op, children: kids } : null;
    }

    function buildTree() {
        return pruneTree(state.tree) || { op: state.tree.op, children: [] };
    }

    function ruleComplete(r) {
        if (!r || !r.c || !r.o) { return false; }
        if (CRIT[r.c] && CRIT[r.c].param && (r.p == null || r.p === '')) { return false; }
        if (r.o === 'between') { return Array.isArray(r.v) && r.v[0] !== '' && r.v[1] !== ''; }
        return r.v !== '' && r.v != null && !(Array.isArray(r.v) && r.v.length === 0);
    }

    // ---- filter UI ---------------------------------------------------------
    function critOptions(selected) {
        var groups = {};
        Object.keys(CRIT).forEach(function (id) {
            // Hidden criteria stay valid on the wire -- the date chips send one, and
            // older share links carry others -- they are just not offered by hand.
            if (CRIT[id].hidden) { return; }
            var g = CRIT[id].group || 'Other';
            (groups[g] = groups[g] || []).push(id);
        });
        var html = '<option value="">Choose a filter…</option>';
        Object.keys(groups).forEach(function (g) {
            html += '<optgroup label="' + esc(g) + '">';
            groups[g].forEach(function (id) {
                html += '<option value="' + esc(id) + '"' + (id === selected ? ' selected' : '') + '>'
                    + esc(CRIT[id].label) + '</option>';
            });
            html += '</optgroup>';
        });
        return html;
    }

    function valueField(r, def) {
        if (!def) { return ''; }
        if (def.type === 'bool') {
            return '<select class="ae-v" data-k="v">'
                + '<option value="yes"' + (String(r.v) === 'yes' ? ' selected' : '') + '>yes</option>'
                + '<option value="no"' + (String(r.v) === 'no' ? ' selected' : '') + '>no</option></select>';
        }
        if (def.type === 'date') {
            if (r.o === 'between') {
                var a = Array.isArray(r.v) ? r.v[0] : '', b = Array.isArray(r.v) ? r.v[1] : '';
                return '<input type="date" class="ae-v" data-k="v0" value="' + esc(a) + '">'
                    + '<span class="ae-and">and</span>'
                    + '<input type="date" class="ae-v" data-k="v1" value="' + esc(b) + '">';
            }
            return '<input type="date" class="ae-v" data-k="v" value="' + esc(r.v || '') + '">';
        }
        if (def.type === 'number') {
            var min = def.min != null ? ' min="' + Number(def.min) + '"' : '';
            var max = def.max != null ? ' max="' + Number(def.max) + '"' : '';
            if (r.o === 'between') {
                var x = Array.isArray(r.v) ? r.v[0] : '', y = Array.isArray(r.v) ? r.v[1] : '';
                return '<input type="number" class="ae-v" data-k="v0"' + min + max + ' value="' + esc(x) + '">'
                    + '<span class="ae-and">and</span>'
                    + '<input type="number" class="ae-v" data-k="v1"' + min + max + ' value="' + esc(y) + '">';
            }
            return '<input type="number" class="ae-v" data-k="v"' + min + max + ' value="' + esc(r.v == null ? '' : r.v) + '">';
        }
        var many = (r.o === 'in' || r.o === 'not_in');
        var list = OPTS[def.set] || (def.type === 'peerage_set' ? OPTS.peerage : null);

        // A real picker whenever an option list was shipped. Only the small sets
        // are: ork_kingdomaward is 4,874 rows, so "Award" still takes an id while
        // "Award (any kingdom)" offers all 149 catalog awards by name — which is
        // what someone hunting a known award actually wants.
        if (list && list.length) {
            var chosen = Array.isArray(r.v) ? r.v.map(String) : (r.v == null || r.v === '' ? [] : [String(r.v)]);
            var opt = function (o) {
                // data-award-id / data-is-ladder are what the shared picker reads to
                // show a ladder award's description (AWARD_DESCRIPTIONS in revised.js).
                return '<option value="' + esc(o.id) + '"'
                    + ' data-award-id="' + esc(o.id) + '"'
                    + (o.ladder ? ' data-is-ladder="1"' : '')
                    + (chosen.indexOf(String(o.id)) !== -1 ? ' selected' : '') + '>' + esc(o.name) + '</option>';
            };

            // Awards arrive carrying a group, so the picker reads like the one in
            // Add Award and Recommend an Award: Ladder Awards, Knighthoods,
            // Masterhoods, Paragons, and so on. Anything ungrouped is a plain list.
            var body;
            if (list[0] && list[0].group) {
                var buckets = {};
                list.forEach(function (o) { (buckets[o.group] = buckets[o.group] || []).push(o); });
                var order = (AE.registry.awardGroupOrder || []).slice();
                Object.keys(buckets).forEach(function (g) { if (order.indexOf(g) === -1) { order.push(g); } });
                body = order.filter(function (g) { return buckets[g]; }).map(function (g) {
                    return '<optgroup label="' + esc(g) + '">' + buckets[g].map(opt).join('') + '</optgroup>';
                }).join('');
            } else {
                body = list.map(opt).join('');
            }

            // Searchable picker for the long lists. Award is 149 and park is
            // 1,101 -- a native select of 1,101 parks is a wall you scroll, not a
            // list you choose from. Kingdom is 37 and genuinely fine as it is, so
            // it keeps the native control rather than gaining a search box for
            // two screenfuls.
            var PICKABLE = { award: 'Select awards\u2026', park: 'Search parks\u2026' };
            var fancy = PICKABLE[def.set]
                ? ' data-awpick="1" data-aw-placeholder="' + esc(PICKABLE[def.set]) + '"'
                : '';
            return '<select class="ae-v ae-v-pick" data-k="v"' + (many ? ' multiple size="8"' : '') + fancy + '>'
                + (many ? '' : '<option value="">choose…</option>') + body + '</select>';
        }

        // No list for this set: an id, which the server rejects if it does not exist.
        var val = Array.isArray(r.v) ? r.v.join(', ') : (r.v == null ? '' : r.v);
        return '<input type="text" class="ae-v" data-k="v" inputmode="numeric" '
            + 'placeholder="' + (many ? 'ids, comma separated' : 'id') + '" value="' + esc(val) + '">';
    }

    function renderTree() {
        var el = $('ae-tree');
        el.innerHTML = renderGroup(state.tree, [], 0);
        var n = leaves(state.tree).length;
        $('ae-filter-count').textContent = n ? '(' + n + ')' : '';
        syncScopeNote();
        syncChips();
        upgradeAwardPickers();
    }

    /**
     * A group renders its children, its own AND/OR joiner, and its own Add
     * buttons. The root has no frame and no Remove: it is the filter itself, not
     * a group inside one. Nested groups are boxed so the bracketing is visible --
     * "kingdom AND (Garber OR Master Garber)" has to be readable at a glance, or
     * the builder is a flat list with extra steps.
     */
    function renderGroup(g, path, depth) {
        var key = path.join('.');
        var inner = g.children.map(function (child, i) {
            var p = path.concat(i);
            var joiner = i === 0
                ? '<span class="ae-join ae-join-first">Where</span>'
                : '<button type="button" class="ae-join ae-joinbtn" data-act="toggle-op" data-path="'
                  + esc(key) + '" title="Switch this group between AND and OR">' + esc(g.op) + '</button>';
            return isGroup(child)
                ? '<div class="ae-grouprow">' + joiner + renderGroup(child, p, depth + 1) + '</div>'
                : renderRule(child, p, joiner);
        }).join('');

        if (!g.children.length) {
            inner = depth === 0
                ? '<p class="ae-empty">No filters. Every award matches.</p>'
                : '<p class="ae-empty">Empty group \u2014 add a rule, or remove it.</p>';
        }

        var actions = '<div class="ae-treeactions">'
            + '<button type="button" class="ae-btn" data-act="add-rule" data-path="' + esc(key) + '">'
            + '<i class="fas fa-plus" aria-hidden="true"></i> Add rule</button>'
            + (depth < MAX_DEPTH
                ? '<button type="button" class="ae-btn" data-act="add-group" data-path="' + esc(key) + '">'
                  + '<i class="fas fa-object-group" aria-hidden="true"></i> Add group</button>'
                : '')
            + (depth > 0
                ? '<button type="button" class="ae-btn ae-btn-quiet" data-act="remove" data-path="' + esc(key)
                  + '">Remove group</button>'
                : '')
            + '</div>';

        return depth === 0 ? inner + actions : '<div class="ae-group">' + inner + actions + '</div>';
    }

    // Mirrors the shared picker's own budget: past this it shows "N selected".
    var TRIGGER_BUDGET = 60;

    /**
     * Spell out a multi-value choice, but only when the control itself cannot.
     * The picker names the awards while they fit and collapses to "N selected"
     * after that, and a filter nobody can read is useless on a shared link --
     * which is the main way this report gets passed around.
     */
    function chosenNames(r, def) {
        if (!def || !Array.isArray(r.v) || !r.v.length) { return ''; }
        var list = OPTS[def.set] || (def.type === 'peerage_set' ? OPTS.peerage : null);
        var names;
        if (list) {
            var byId = {};
            list.forEach(function (o) { byId[String(o.id)] = o.name; });
            names = r.v.map(function (v) { return byId[String(v)] || v; });
        } else {
            names = r.v.slice();
        }
        var joined = names.join(', ');
        if (joined.length <= TRIGGER_BUDGET) { return ''; }   // the trigger already says it
        return names.length + ' chosen: ' + joined;
    }

    function renderRule(r, path, joiner) {
        var def = CRIT[r.c];
        var key = esc(path.join('.'));
        var html = '<div class="ae-rule" data-path="' + key + '">' + joiner;
        html += '<select class="ae-c" data-k="c">' + critOptions(r.c) + '</select>';
        if (def) {
            html += '<select class="ae-o" data-k="o">' + (def.operands || []).map(function (o) {
                return '<option value="' + esc(o) + '"' + (o === r.o ? ' selected' : '') + '>'
                    + esc(opLabel(o, def.type)) + '</option>';
            }).join('') + '</select>';
            if (def.param) {
                html += '<input type="number" class="ae-p" data-k="p" min="1" placeholder="months" value="'
                    + esc(r.p == null ? '' : r.p) + '">';
            }
            html += valueField(r, def);
        }
        html += '<button type="button" class="ae-x" data-act="remove" data-path="' + key
             + '" aria-label="Remove this rule">&times;</button>';
        // Spell out a multi-value choice under the row. The trigger shortens to a
        // count once the names get long, and a filter nobody can read is useless
        // on a shared link -- which is the main way this report gets passed around.
        var picked = chosenNames(r, def);
        if (picked) { html += '<p class="ae-chosen">' + esc(picked) + '</p>'; }
        if (def && def.note) { html += '<p class="ae-note">' + esc(def.note) + '</p>'; }
        if (r.error) { html += '<p class="ae-rule-error">' + esc(r.error) + '</p>'; }
        return html + '</div>';
    }

    /**
     * Turn the award select into the searchable chooser used by Add Award and
     * Recommend an Award -- grouped, type-to-filter, with ladder descriptions.
     *
     * renderTree() rebuilds the rows, so this runs after every render on whatever
     * selects are now in the DOM. If revised.js did not load, the plain grouped
     * select is still perfectly usable.
     */
    function upgradeAwardPickers() {
        if (typeof window.awInitPicker !== 'function') { return; }
        Array.prototype.forEach.call(document.querySelectorAll('#ae-tree select[data-awpick]'), function (sel) {
            if (sel._awTrigger) { return; }
            try { window.awInitPicker(sel); } catch (e) { /* plain select remains */ }
        });
    }

    // ---- columns -----------------------------------------------------------
    function renderColumns() {
        var html = '';
        Object.keys(COLS).forEach(function (id) {
            var on = state.columns.indexOf(id) !== -1;
            html += '<label class="ae-colpick"><input type="checkbox" value="' + esc(id) + '"'
                + (on ? ' checked' : '') + '> ' + esc(COLS[id].label) + '</label>';
        });
        $('ae-columns').innerHTML = html;
        $('ae-column-count').textContent = '(' + state.columns.length + ')';
    }

    // ---- results -----------------------------------------------------------
    function renderResults(rows, columns) {
        var el = $('ae-results');
        if (dt) { dt.destroy(); dt = null; }
        if (!rows.length) {
            // An empty result is often the scope, not the question: a principality
            // has no knightings of its own, so "Knights" from Painted Skies is
            // correctly zero and unhelpfully blank. Offer the obvious next move
            // rather than leaving it looking broken.
            var scoped = leaves(state.tree).filter(function (r) { return SCOPE_CRIT.indexOf(r.c) !== -1; });
            el.innerHTML = '<p class="ae-empty">No awards match those filters.'
                + (scoped.length
                    ? ' <button type="button" class="ae-linkbtn" id="ae-drop-scope">Search everywhere instead</button>'
                    : '')
                + '</p>';
            var drop = $('ae-drop-scope');
            if (drop) {
                drop.addEventListener('click', function () {
                    dropScopeRules(state.tree);
                    renderTree();
                    run();
                });
            }
            return;
        }

        // Columns that point at a profile, and the row key carrying the id.
        var LINKS = {
            recipient:         { id: 'recipient_id',         route: 'Player/profile/' },
            recipient_park:    { id: 'recipient_park_id',    route: 'Park/profile/' },
            recipient_kingdom: { id: 'recipient_kingdom_id', route: 'Kingdom/profile/' },
            given_by:          { id: 'given_by_id',          route: 'Player/profile/' },
            kingdom:           { id: 'kingdom_id',           route: 'Kingdom/profile/' },
            park:              { id: 'park_id',              route: 'Park/profile/' }
        };

        var cell = function (c) {
            return function (v, type, row) {
                // Sorting, searching and export get the plain text; only the
                // rendered cell gets markup, or DataTables would sort on the HTML.
                if (type !== 'display') { return v == null ? '' : v; }
                var link = LINKS[c];
                if (link && v) {
                    var id = Number(row && row[link.id]) || 0;
                    if (id > 0) {
                        return '<a href="' + esc(AE.uir + link.route + id) + '">' + esc(v) + '</a>';
                    }
                }
                if (c === 'rank') {
                    var n = Number(v) || 0;
                    return n > 0
                        ? '<span class="ladder-rank" data-lvl="' + Math.min(n, 10) + '">' + n + '</span>'
                        : '<span class="ae-muted">\u2014</span>';
                }
                if (c === 'revoked') { return Number(v) ? '<span class="ae-revoked">revoked</span>' : ''; }
                if (c === 'date' && !v) {
                    return '<span class="ae-muted" title="No date was recorded for this award">unknown</span>';
                }
                return esc(v);
            };
        };

        el.innerHTML = '<div class="ae-tablewrap"><table class="ae-table" id="ae-dt"><thead><tr>'
            + columns.map(function (c) { return '<th>' + esc((COLS[c] || {}).label || c) + '</th>'; }).join('')
            + '</tr></thead></table></div>';
        var table = $('ae-dt');

        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.DataTable) {
            // DataTables did not load: a plain table, still escaped, still readable.
            var body = rows.map(function (row) {
                return '<tr>' + columns.map(function (c) {
                    return '<td>' + cell(c)(row[c], 'display', row) + '</td>';
                }).join('') + '</tr>';
            }).join('');
            table.insertAdjacentHTML('beforeend', '<tbody>' + body + '</tbody>');
            return;
        }

        dt = window.jQuery(table).DataTable({
            data: rows,
            columns: columns.map(function (c) {
                return {
                    data: function (row) { return row[c] === undefined ? null : row[c]; },
                    render: cell(c),
                    className: c === 'rank' ? 'dt-body-right' : ''
                };
            }),
            // The server already returned these newest-first and that ordering is
            // part of what the report means, so do not re-sort on load.
            order: [],
            pageLength: 25,
            lengthMenu: [[25, 50, 100, 250, -1], [25, 50, 100, 250, 'All']],
            deferRender: true,
            autoWidth: false,
            dom: '<"ae-dt-top"lBf>rt<"ae-dt-bottom"ip>',
            buttons: [{
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'ae-dt-btn',
                title: 'Award Explorer' + (AE.scope && AE.scope.Name ? ' \u2014 ' + AE.scope.Name : '')
            }],
            language: { search: 'Search results:', emptyTable: 'No awards match these filters.' }
        });
    }

    // ---- run ---------------------------------------------------------------
    function setStatus(msg) { $('ae-status').textContent = msg || ''; }
    function showError(msg) {
        var e = $('ae-error');
        if (!msg) { e.hidden = true; e.textContent = ''; return; }
        e.hidden = false; e.textContent = msg;
        if (e.scrollIntoView) { e.scrollIntoView({ block: 'nearest' }); }
    }

    /** YYYY-MM-DD, N months before today. */
    function monthsAgoDate(months) {
        var d = new Date();
        d.setMonth(d.getMonth() - Number(months));
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }

    function dateRuleIndex() {
        for (var i = 0; i < state.tree.children.length; i++) {
            var c = state.tree.children[i];
            if (!isGroup(c) && c.c === 'date' && c.o === 'gte') { return i; }
        }
        return -1;
    }

    /**
     * A chip writes an ordinary rule: "Award date is on or after 2025-10-13".
     *
     * It used to be a window applied at run time and shown nowhere, which is how
     * a search for Felfrost's Garbers came back without the ones from 2011-2021.
     * As a rule it is visible in the builder, editable to any date, removable,
     * and it travels in a share link as exactly what it is.
     *
     * The date is resolved when the chip is clicked rather than at run time, so a
     * shared link returns the same rows for the person who opens it as it did for
     * the person who sent it.
     */
    function setWindow(months) {
        var at = dateRuleIndex();
        if (!months) {
            if (at !== -1) { state.tree.children.splice(at, 1); }
        } else {
            var rule = { c: 'date', o: 'gte', v: monthsAgoDate(months) };
            if (at === -1) { state.tree.children.unshift(rule); }
            else { state.tree.children[at] = rule; }
        }
        syncChips();
    }

    /** Light the chip whose date matches the rule; none, once you edit the date. */
    function syncChips() {
        var at = dateRuleIndex();
        var cur = at === -1 ? null : state.tree.children[at].v;
        Array.prototype.forEach.call(document.querySelectorAll('.ae-chip'), function (c) {
            var m = Number(c.dataset.months) || 0;
            var on = m === 0 ? (cur === null) : (cur === monthsAgoDate(m));
            c.classList.toggle('ae-chip-on', on);
        });
        windowHint();
    }

    function run() {
        var btn = $('ae-run');
        if (btn.disabled) { return; }
        // "Press Run" is stale instruction once something has.
        var scopeNote = document.querySelector('.ae-scope');
        if (scopeNote) { scopeNote.remove(); }
        btn.disabled = true;
        setStatus('Running…');
        showError('');
        leaves(state.tree).forEach(function (r) { delete r.error; });

        var tree = buildTree();

        var payload = {
            Tree: tree,
            Columns: state.columns,
            Search: state.search,
            IncludeRevoked: state.includeRevoked,
            IncludeStripped: state.includeStripped,
            // One fetch, capped server-side; DataTables paginates what comes back.
            // That is how every other report in the ORK behaves, and it keeps the
            // client-side CSV honest -- it exports what you are looking at.
            Limit: 5000,
            WithTotal: true
        };

        fetch(AE.uir + 'Reports/award_explorer_json', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().catch(function () { throw new Error('The server sent a reply this page could not read.'); });
        }).then(function (d) {
            btn.disabled = false;
            if (d.status === 5) { showError('Your session has ended. Please log in again.'); setStatus(''); return; }
            if (d.status !== 0) {
                showError(d.error || 'That query could not be run.');
                // A path points at the rule the server rejected; the offset of 1 is the
                // date-window rule this page prepends, which is not one of the user's.
                // The server's path indexes the tree it was sent; walk the same
                // path to find the rule, allowing for the date-window rule this
                // page prepends at the root.
                if (Array.isArray(d.path) && d.path.length) {
                    var node = nodeAt(d.path);
                    if (node && !isGroup(node)) { node.error = d.error; renderTree(); }
                }
                setStatus('');
                return;
            }
            lastColumns = d.columns || [];
            lastRows = d.rows || [];
            renderResults(lastRows, lastColumns);
            var shown = lastRows.length;
            var msg = (d.total != null ? d.total.toLocaleString() : shown) + ' grants';
            // Say so when the cap bit, rather than quietly showing a partial answer.
            if (d.has_more) { msg = 'showing the first ' + shown.toLocaleString() + ' of ' + msg + ' — narrow your filter'; }

            // Name the filters that are NOT rules in the builder. The date window
            // is a chip at the top of the page and the exclusions are checkboxes,
            // so by the time you are reading the table there is nothing saying a
            // whole era has been left out -- which reads as missing data, not as a
            // filter doing its job.
            // The date window is a visible rule now, so it no longer needs naming
            // here -- only the two checkboxes are still filters with no row.
            var applied = [];
            if (state.includeRevoked) { applied.push('including revoked'); }
            if (state.includeStripped) { applied.push('including stripped'); }
            if (applied.length) { msg += ' · ' + applied.join(' · '); }
            if (d.elapsed_ms) { msg += ' · ' + d.elapsed_ms + ' ms'; }
            setStatus(msg);

            // And offer the way out, since "why is my 2019 award missing" is the
            // first thing a date window makes someone ask.
            var st = $('ae-status');
            if (dateRuleIndex() !== -1 && st) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'ae-linkbtn';
                b.textContent = 'search all time';
                b.addEventListener('click', function () {
                    setWindow(0);
                    renderTree();
                    run();
                });
                st.appendChild(document.createTextNode(' · '));
                st.appendChild(b);
            }
        }).catch(function (err) {
            btn.disabled = false;
            setStatus('');
            showError(err && err.message ? err.message : 'Could not reach the server. Please try again.');
        });
    }

    // ---- share link --------------------------------------------------------
    function shareLink() {
        var payload = {
            tree: buildTree(),
            columns: state.columns,
            search: state.search,
            includeRevoked: state.includeRevoked,
            includeStripped: state.includeStripped
        };
        var b64 = btoa(unescape(encodeURIComponent(JSON.stringify(payload))))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        return location.origin + location.pathname + '?Route=Reports/award_explorer&' + AE.linkParam + '=' + b64;
    }

    // ---- wiring ------------------------------------------------------------
    $('ae-tree').addEventListener('change', function (e) {
        var row = e.target.closest ? e.target.closest('.ae-rule') : null;
        if (!row) { return; }
        var r = nodeAt(pathOf(row) || []), k = e.target.dataset.k;
        if (!r || isGroup(r) || !k) { return; }
        if (k === 'c') {
            r.c = e.target.value;
            showColumnFor(r.c);
            var def = CRIT[r.c];
            r.o = def && def.operands ? def.operands[0] : '';
            r.v = ''; r.p = null; delete r.error;
        } else if (k === 'o') {
            r.o = e.target.value;
            if (r.o === 'between' && !Array.isArray(r.v)) { r.v = ['', '']; }
            if (r.o !== 'between' && Array.isArray(r.v)) { r.v = ''; }
        } else if (k === 'p') {
            r.p = e.target.value === '' ? null : Number(e.target.value);
        } else if (k === 'v0' || k === 'v1') {
            if (!Array.isArray(r.v)) { r.v = ['', '']; }
            r.v[k === 'v0' ? 0 : 1] = e.target.value;
        } else if (k === 'v') {
            if (e.target.multiple) {
                r.v = Array.prototype.map.call(e.target.selectedOptions, function (o) { return o.value; });
                // Do NOT re-render: the picker stays open across toggles, and
                // rebuilding the row would destroy the list being clicked.
                return;
            }
            if (r.o === 'in' || r.o === 'not_in') {
                r.v = String(e.target.value).split(',').map(function (x) { return x.trim(); }).filter(Boolean);
            } else {
                r.v = e.target.value;
            }
        }
        renderTree();
    });

    $('ae-tree').addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-act]') : null;
        if (!btn) { return; }
        var path = pathOf(btn);
        if (path === null) { return; }
        var act = btn.dataset.act;

        if (act === 'remove') {
            removeAt(path);
        } else if (act === 'toggle-op') {
            var g = nodeAt(path);
            if (isGroup(g)) { g.op = g.op === 'AND' ? 'OR' : 'AND'; }
        } else if (act === 'add-rule') {
            var g2 = nodeAt(path);
            if (isGroup(g2)) { g2.children.push({ c: '', o: '', v: '' }); }
        } else if (act === 'add-group') {
            var g3 = nodeAt(path);
            // A new group opens on OR. A group that repeats its parent's AND says
            // nothing, and the reason to reach for one is almost always "any of
            // these" inside an otherwise ANDed filter.
            if (isGroup(g3)) { g3.children.push({ op: 'OR', children: [{ c: '', o: '', v: '' }] }); }
        } else {
            return;
        }
        renderTree();
    });

    $('ae-columns').addEventListener('change', function (e) {
        if (e.target.type !== 'checkbox') { return; }
        var id = e.target.value, at = state.columns.indexOf(id);
        if (e.target.checked && at === -1) { state.columns.push(id); }
        if (!e.target.checked && at !== -1) { state.columns.splice(at, 1); }
        $('ae-column-count').textContent = '(' + state.columns.length + ')';
    });

    // ---- quick picks -------------------------------------------------------
    // The answer to "how do I just see all the Knights". Each one sets ordinary
    // rules, so the result is a filter the person can read, adjust and share --
    // not a special mode they then have to escape from.
    var PRESETS = {
        knights:      { rules: [{ c: 'peerage', o: 'is', v: 'Knight' }],  months: 0 },
        masters:      { rules: [{ c: 'peerage', o: 'is', v: 'Master' }],  months: 0 },
        paragons:     { rules: [{ c: 'peerage', o: 'is', v: 'Paragon' }], months: 0 },
        kingdomlevel: { rules: [{ c: 'peerage', o: 'is', v: 'Kingdom-Level-Award' }], months: 0 },
        ladders:      { rules: [{ c: 'is_ladder', o: 'is', v: 'yes' }] },
        titles:       { rules: [{ c: 'is_title', o: 'is', v: 'yes' }] }
    };

    Array.prototype.forEach.call(document.querySelectorAll('.ae-quickbtn'), function (b) {
        b.addEventListener('click', function () {
            var preset = PRESETS[b.dataset.preset];
            if (!preset) { return; }
            // Keep WHERE you are looking, replace WHAT you are looking for. Clicking
            // Knights from a kingdom's Reports tab means "this kingdom's knightings",
            // not "every knighting in Amtgard" -- wiping the scope rule threw away the
            // one thing the person had already told us.
            var scope = leaves(state.tree).filter(function (r) { return SCOPE_CRIT.indexOf(r.c) !== -1; });
            state.tree = {
                op: 'AND',
                children: scope.concat(preset.rules.map(function (r) { return Object.assign({}, r); }))
            };
            // Peerages are rare enough that a 12-month window usually shows
            // nothing, so those presets drop the date rule entirely.
            if (preset.months !== undefined) { setWindow(preset.months); }
            var card = $('ae-filters-card');
            if (card) { card.open = true; }
            renderTree();
            run();
        });
    });

    /**
     * Back to the report this page would have shown with no link: default
     * columns, default window, no search, nothing included, and the opening
     * scope rule if we arrived from a kingdom or park.
     *
     * The share parameter is dropped from the URL as well. Leaving it there
     * means the next refresh quietly restores the shared filter, which is the
     * opposite of starting over -- and editing the address bar should not be
     * the only way out of someone else's report.
     */
    function startOver() {
        state.tree = { op: 'AND', children: [] };
        state.search = '';
        state.includeRevoked = false;
        state.includeStripped = false;
        state.columns = [];
        Object.keys(COLS).forEach(function (id) { if (COLS[id]['default']) { state.columns.push(id); } });

        if (AE.scope && AE.scope.Type && AE.scope.Id) {
            state.tree.children.push({
                c: AE.scope.Type === 'Park' ? 'home_park' : 'home_kingdom',
                o: 'is',
                v: String(AE.scope.Id)
            });
        }

        $('ae-search').value = '';
        $('ae-include-revoked').checked = false;
        $('ae-include-stripped').checked = false;
        setWindow(AE.defaultMonths);

        if (window.history && history.replaceState) {
            try {
                var u = new URL(window.location.href);
                u.searchParams.delete(AE.linkParam);
                history.replaceState(null, '', u.pathname + u.search + u.hash);
            } catch (e) { /* an old browser keeps the parameter; nothing else breaks */ }
        }

        var note = $('ae-shared-note');
        if (note) { note.remove(); }
        if (dt) { dt.destroy(); dt = null; }
        $('ae-results').innerHTML = '<p class="ae-empty">Choose your filters and press <strong>Run</strong>.</p>';
        setStatus('');
        showError('');
        renderTree();
        renderColumns();
        windowHint();
    }

    var startBtn = $('ae-start-over');
    if (startBtn) { startBtn.addEventListener('click', startOver); }

    // Clears everything in the Filters card -- the rules AND the two include
    // checkboxes beneath them. Leaving the checkboxes set while the rules
    // vanished was the same kind of invisible filter as the date window.
    // The window itself lives in its own card and is left alone; Start over
    // is the one that resets everything.
    $('ae-clear').addEventListener('click', function () {
        state.tree = { op: 'AND', children: [] };
        state.includeRevoked = false;
        state.includeStripped = false;
        $('ae-include-revoked').checked = false;
        $('ae-include-stripped').checked = false;
        renderTree();
        showError('');
    });
    $('ae-run').addEventListener('click', function () { run(); });

    $('ae-search').addEventListener('input', function () { state.search = this.value; });
    $('ae-search').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); run(); }
    });
    $('ae-include-revoked').addEventListener('change', function () { state.includeRevoked = this.checked; });
    $('ae-include-stripped').addEventListener('change', function () { state.includeStripped = this.checked; });

    Array.prototype.forEach.call(document.querySelectorAll('.ae-chip'), function (chip) {
        chip.addEventListener('click', function () {
            setWindow(Number(chip.dataset.months) || 0);
            renderTree();   // the rule it wrote has to appear in the builder
        });
    });

    // Says what the chips will search, in the present tense: the hint is read
    // before Run as often as after it, so "Showing" was wrong half the time.
    // It deliberately does NOT mention the handful of mis-typed future dates in
    // the data -- that is a note to whoever maintains the ORK, not something to
    // put in front of everyone who wants to search more than a year back.
    /** "12 months" / "5 years" -- say it the way the chip does, not in raw months. */
    function windowWords(months) {
        var n = Number(months) || 0;
        if (n % 12 === 0 && n >= 24) { return (n / 12) + ' years'; }
        if (n === 12) { return '12 months'; }
        return n + (n === 1 ? ' month' : ' months');
    }

    function windowHint() {
        var at = dateRuleIndex();
        $('ae-window-hint').textContent = at === -1
            ? 'Every award the ORK has recorded. This can take a few seconds.'
            : 'From ' + state.tree.children[at].v + ' onwards — shown as a rule below, which you can edit or remove.';
    }

    $('ae-share').addEventListener('click', function () {
        var link = shareLink();
        var done = function () { setStatus('Link copied.'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(done, function () { window.prompt('Copy this link:', link); });
        } else {
            window.prompt('Copy this link:', link);
        }
    });

    $('ae-csv').addEventListener('click', function () {
        if (!lastRows.length) { showError('Run the report first — there is nothing to export yet.'); return; }
        var head = lastColumns.map(function (c) { return (COLS[c] || {}).label || c; });
        var cell = function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; };
        var csv = head.map(cell).join(',') + '\r\n'
            + lastRows.map(function (r) { return lastColumns.map(function (c) { return cell(r[c]); }).join(','); }).join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(["﻿" + csv], { type: 'text/csv;charset=utf-8' }));
        a.download = 'award-explorer-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
    });

    // ---- boot --------------------------------------------------------------
    (function init() {
        var init = AE.initial;
        if (init && typeof init === 'object') {
            // Links made before the builder had groups carry a flat rules list.
            if (init.tree && Array.isArray(init.tree.children)) {
                state.tree = init.tree;
            } else if (Array.isArray(init.rules)) {
                state.tree = { op: init.op === 'OR' ? 'OR' : 'AND', children: init.rules };
            }
            state.columns = Array.isArray(init.columns) ? init.columns : [];
            state.search = typeof init.search === 'string' ? init.search : '';

            state.includeRevoked = !!init.includeRevoked;
            state.includeStripped = !!init.includeStripped;
            $('ae-search').value = state.search;
            $('ae-include-revoked').checked = state.includeRevoked;
            $('ae-include-stripped').checked = state.includeStripped;
            // Links made while the window was a separate field still carry months.
            if (init.months && dateRuleIndex() === -1) { setWindow(Number(init.months)); }
        }
        // Arriving from a kingdom or park starts you filtered to it. It is a normal
        // rule in the list, so it can be removed -- being able to look at everything
        // is the point of the report. A shared link wins: it already says what it wants.
        if (!AE.initial && AE.scope && AE.scope.Type && AE.scope.Id) {
            state.tree.children.push({
                c: AE.scope.Type === 'Park' ? 'home_park' : 'home_kingdom',
                o: 'is',
                v: String(AE.scope.Id)
            });
        }
        if (!state.columns.length) {
            Object.keys(COLS).forEach(function (id) { if (COLS[id]['default']) { state.columns.push(id); } });
        }
        // The default window is a rule like any other, written at boot rather than
        // applied invisibly at run time. A shared link brings its own, so only a
        // fresh visit gets this one.
        if (!AE.initial && dateRuleIndex() === -1) { setWindow(AE.defaultMonths); }
        renderTree();
        renderColumns();
        // Run on a SHARED LINK only, as Population Explorer does. Arriving from a
        // kingdom's Reports tab prefills the scope rule but does not fire a query:
        // opening the page to see what it can do should not cost a scan of 397k
        // rows, and the person may well want to change the filter before running.
        if (AE.initial) { run(); }
    })();
})();
