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
        var hasScope = state.rules.some(function (r) { return SCOPE_CRIT.indexOf(r.c) !== -1; });
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
    var state = {
        rules: [],          // flat list; op joins them. Groups are v2 — see note below.
        op: 'AND',
        columns: [],
        search: '',
        months: AE.defaultMonths,
        includeRevoked: false,
        includeStripped: false
    };
    var lastRows = [];
    var lastColumns = [];
    var dt = null;

    // The server's tree takes nested groups (MAX_DEPTH 6). This v1 UI builds one
    // level — a flat list joined by AND or OR — because that covers the asked-for
    // cases and a nested builder is most of Population Explorer's front end. The
    // wire format is already the nested shape, so adding groups later needs no
    // server change.
    function buildTree() {
        return {
            op: state.op,
            children: state.rules.filter(ruleComplete).map(function (r) {
                var leaf = { c: r.c, o: r.o, v: r.v };
                if (r.p != null) { leaf.p = r.p; }
                return leaf;
            })
        };
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

            // Only the single-select award list gets the searchable picker: the
            // widget assumes one value, and a kingdom or park list is short enough
            // that a plain select is no worse.
            var fancy = (def.set === 'award' && !many) ? ' data-awpick="1"' : '';
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
        if (!state.rules.length) {
            el.innerHTML = '<p class="ae-empty">No filters. Every award matches.</p>';
        } else {
            var html = '';
            state.rules.forEach(function (r, i) {
                var def = CRIT[r.c];
                html += '<div class="ae-rule" data-i="' + i + '">';
                html += i === 0
                    ? '<span class="ae-join ae-join-first">Where</span>'
                    : '<button type="button" class="ae-join ae-joinbtn" data-act="toggle-op">' + esc(state.op) + '</button>';
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
                html += '<button type="button" class="ae-x" data-act="remove" aria-label="Remove this rule">&times;</button>';
                if (def && def.note) { html += '<p class="ae-note">' + esc(def.note) + '</p>'; }
                if (r.error) { html += '<p class="ae-rule-error">' + esc(r.error) + '</p>'; }
                html += '</div>';
            });
            el.innerHTML = html;
        }
        $('ae-filter-count').textContent = state.rules.length ? '(' + state.rules.length + ')' : '';
        syncScopeNote();
        upgradeAwardPickers();
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
            var scoped = state.rules.filter(function (r) { return SCOPE_CRIT.indexOf(r.c) !== -1; });
            el.innerHTML = '<p class="ae-empty">No awards match those filters.'
                + (scoped.length
                    ? ' <button type="button" class="ae-linkbtn" id="ae-drop-scope">Search everywhere instead</button>'
                    : '')
                + '</p>';
            var drop = $('ae-drop-scope');
            if (drop) {
                drop.addEventListener('click', function () {
                    state.rules = state.rules.filter(function (r) { return SCOPE_CRIT.indexOf(r.c) === -1; });
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

    function monthsRule() {
        // The date window is a chip, not a rule the user has to write. It is added
        // to the tree at run time so a shared link reproduces exactly what was seen.
        if (!state.months) { return null; }
        return { c: 'months_ago', o: 'is', v: 1, p: state.months };
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
        state.rules.forEach(function (r) { delete r.error; });

        var tree = buildTree();
        var mr = monthsRule();
        if (mr) { tree = { op: 'AND', children: [mr].concat(tree.children) }; }

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
                if (Array.isArray(d.path) && d.path.length) {
                    var i = Number(d.path[0]) - (mr ? 1 : 0);
                    if (state.rules[i]) { state.rules[i].error = d.error; renderTree(); }
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
            setStatus(msg + (d.elapsed_ms ? ' · ' + d.elapsed_ms + ' ms' : ''));
        }).catch(function (err) {
            btn.disabled = false;
            setStatus('');
            showError(err && err.message ? err.message : 'Could not reach the server. Please try again.');
        });
    }

    // ---- share link --------------------------------------------------------
    function shareLink() {
        var payload = {
            rules: state.rules.filter(ruleComplete),
            op: state.op,
            columns: state.columns,
            search: state.search,
            months: state.months,
            includeRevoked: state.includeRevoked,
            includeStripped: state.includeStripped
        };
        var b64 = btoa(unescape(encodeURIComponent(JSON.stringify(payload))))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        return location.origin + location.pathname + '?Route=Reports/award_explorer&' + AE.linkParam + '=' + b64;
    }

    // ---- wiring ------------------------------------------------------------
    function addRule() {
        state.rules.push({ c: '', o: '', v: '' });
        renderTree();
    }

    $('ae-tree').addEventListener('change', function (e) {
        var row = e.target.closest ? e.target.closest('.ae-rule') : null;
        if (!row) { return; }
        var i = Number(row.dataset.i), r = state.rules[i], k = e.target.dataset.k;
        if (!r || !k) { return; }
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
            } else if (r.o === 'in' || r.o === 'not_in') {
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
        var row = btn.closest('.ae-rule');
        if (btn.dataset.act === 'remove' && row) {
            state.rules.splice(Number(row.dataset.i), 1);
            renderTree();
        } else if (btn.dataset.act === 'toggle-op') {
            state.op = state.op === 'AND' ? 'OR' : 'AND';
            renderTree();
        }
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
            var scope = state.rules.filter(function (r) { return SCOPE_CRIT.indexOf(r.c) !== -1; });
            state.rules = scope.concat(preset.rules.map(function (r) { return Object.assign({}, r); }));
            if (preset.months !== undefined) {
                state.months = preset.months;
                Array.prototype.forEach.call(document.querySelectorAll('.ae-chip'), function (c) {
                    c.classList.toggle('ae-chip-on', Number(c.dataset.months) === state.months);
                });
                windowHint();
            }
            var card = $('ae-filters-card');
            if (card) { card.open = true; }
            renderTree();
            run();
        });
    });

    $('ae-add-rule').addEventListener('click', addRule);
    $('ae-add-group').addEventListener('click', function () {
        // Groups are the v2 piece. Say so rather than offering a dead button.
        showError('Nested groups are not in this version yet — rules are joined by the AND/OR button on each row.');
    });
    $('ae-clear').addEventListener('click', function () {
        state.rules = []; renderTree(); showError('');
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
            Array.prototype.forEach.call(document.querySelectorAll('.ae-chip'), function (c) {
                c.classList.remove('ae-chip-on');
            });
            chip.classList.add('ae-chip-on');
            state.months = Number(chip.dataset.months) || 0;
            windowHint();
        });
    });

    function windowHint() {
        $('ae-window-hint').textContent = state.months
            ? 'Showing awards from the last ' + state.months + ' months. Switch to Everything for the full history.'
            : 'Showing the whole history — slower, and the newest rows include a handful of mis-typed future dates.';
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
            state.rules = Array.isArray(init.rules) ? init.rules : [];
            state.op = init.op === 'OR' ? 'OR' : 'AND';
            state.columns = Array.isArray(init.columns) ? init.columns : [];
            state.search = typeof init.search === 'string' ? init.search : '';
            state.months = Number(init.months) || 0;
            state.includeRevoked = !!init.includeRevoked;
            state.includeStripped = !!init.includeStripped;
            $('ae-search').value = state.search;
            $('ae-include-revoked').checked = state.includeRevoked;
            $('ae-include-stripped').checked = state.includeStripped;
            Array.prototype.forEach.call(document.querySelectorAll('.ae-chip'), function (c) {
                c.classList.toggle('ae-chip-on', Number(c.dataset.months) === state.months);
            });
        }
        // Arriving from a kingdom or park starts you filtered to it. It is a normal
        // rule in the list, so it can be removed -- being able to look at everything
        // is the point of the report. A shared link wins: it already says what it wants.
        if (!AE.initial && AE.scope && AE.scope.Type && AE.scope.Id) {
            state.rules.push({
                c: AE.scope.Type === 'Park' ? 'home_park' : 'home_kingdom',
                o: 'is',
                v: String(AE.scope.Id)
            });
        }
        if (!state.columns.length) {
            Object.keys(COLS).forEach(function (id) { if (COLS[id]['default']) { state.columns.push(id); } });
        }
        renderTree();
        renderColumns();
        windowHint();
        // Run on a SHARED LINK only, as Population Explorer does. Arriving from a
        // kingdom's Reports tab prefills the scope rule but does not fire a query:
        // opening the page to see what it can do should not cost a scan of 397k
        // rows, and the person may well want to change the filter before running.
        if (AE.initial) { run(); }
    })();
})();
