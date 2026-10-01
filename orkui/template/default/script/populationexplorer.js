/* Population Explorer — filter builder, columns, run, share link, export.
 * Reads only window.PE (set by Reports_populationexplorer.tpl):
 *   { registry:{criteria, columns, options}, scope:{type,id,name}, initial, urls:{run, export, page, share, player} }
 * Vanilla JS. Never inject server/user text with innerHTML unless it went through esc().
 */
(function () {
    'use strict';

    var PE = window.PE;
    if (!PE || !PE.registry || !PE.registry.criteria) { return; }

    var REG = PE.registry;
    var CRIT = REG.criteria || {};
    var COLS = REG.columns || {};
    var OPTS = REG.options || {};
    var UI_MAX_DEPTH = 3;        // server allows 6; the UI caps nesting at 3
    var MAX_SHARE_URL = 7500;    // whole share URL; nginx answers 414 a little above 8 KB
    var DEFAULT_MONTHS = 6;
    var MAX_MONTHS = 60;
    var NEGATED = { ne: 1, is_not: 1, not_in: 1, has_none: 1 };

    var OP_LABEL = {
        eq: '=', ne: '≠', gt: '>', gte: '≥', lt: '<', lte: '≤', between: 'between',
        is: 'is', is_not: 'is not', in: 'is any of', not_in: 'is none of',
        has_any: 'has any of', has_all: 'has all of', has_none: 'has none of'
    };
    var DEFAULT_OP = { date: 'gte', number: 'gte', enum_set: 'in', bool: 'is', peerage_set: 'has_any' };

    /* ── helpers ─────────────────────────────────────────── */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text != null) { n.textContent = text; }
        return n;
    }
    function $(id) { return document.getElementById(id); }
    var uidSeq = 0;
    function uid() { uidSeq += 1; return 'pe' + uidSeq; }
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function humanDate(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
        if (!m) { return iso == null ? '' : String(iso); }
        return MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
    }
    function fmtInt(n) { return Number(n || 0).toLocaleString(); }

    /* ── option lists ────────────────────────────────────── */
    function optionsFor(def) {
        if (def.type === 'peerage_set') {
            var out = [];
            (def.peerage || []).forEach(function (pe) {
                ((OPTS.order || {})[pe] || []).forEach(function (o) { out.push([o[0], o[1]]); });
            });
            out.sort(function (a, b) { return String(a[1]).localeCompare(String(b[1])); });
            return out;
        }
        var list = OPTS[def.set] || [];
        return list.map(function (o) {
            var name = o[1];
            if (def.set === 'award' && o[2]) { name += ' · ' + o[2]; }
            return [o[0], name];
        });
    }
    var optNameCache = {};
    function optionName(def, id) {
        var key = (def.set || 'peer') + ':' + (def.peerage || []).join(',');
        if (!optNameCache[key]) {
            optNameCache[key] = {};
            optionsFor(def).forEach(function (o) { optNameCache[key][o[0]] = o[1]; });
        }
        var n = optNameCache[key][id];
        return n != null ? n : ('#' + id);
    }

    /* ── state ───────────────────────────────────────────── */
    // group: {kind:'group', id, op, children}
    // rule:  {kind:'rule', id, c, o, v, p}
    function newGroup(op) { return { kind: 'group', id: uid(), op: op || 'AND', children: [] }; }
    function newRule(c) {
        var r = { kind: 'rule', id: uid(), c: null, o: null, v: null, p: null };
        setCriterion(r, c || firstCriterionId());
        return r;
    }
    function firstCriterionId() { for (var k in CRIT) { if (Object.prototype.hasOwnProperty.call(CRIT, k)) { return k; } } return null; }

    function defaultValue(type, o) {
        switch (type) {
            case 'date': return o === 'between' ? ['', ''] : '';
            case 'number': return o === 'between' ? ['', ''] : '';
            case 'bool': return 'yes';
            case 'enum_set': return [];
            case 'peerage_set': return o === 'is' ? 'yes' : [];
        }
        return '';
    }
    function setCriterion(rule, c) {
        var def = CRIT[c];
        rule.c = c;
        var ops = def.operands || [];
        rule.o = ops.indexOf(DEFAULT_OP[def.type]) >= 0 ? DEFAULT_OP[def.type] : ops[0];
        rule.v = defaultValue(def.type, rule.o);
        rule.p = def.param ? DEFAULT_MONTHS : null;
    }
    function setOperand(rule, o) {
        var def = CRIT[rule.c];
        var prev = rule.o;
        rule.o = o;
        if (def.type === 'date' || def.type === 'number') {
            if (o === 'between' && prev !== 'between') { rule.v = [typeof rule.v === 'string' ? rule.v : '', '']; }
            else if (o !== 'between' && prev === 'between') { rule.v = Array.isArray(rule.v) ? (rule.v[0] || '') : ''; }
        } else if (def.type === 'enum_set') {
            if ((o === 'is' || o === 'is_not') && Array.isArray(rule.v) && rule.v.length > 1) { rule.v = rule.v.slice(0, 1); }
        } else if (def.type === 'peerage_set') {
            if ((o === 'is') !== (prev === 'is')) { rule.v = defaultValue('peerage_set', o); }
        }
    }

    function yn(v) { return (v === 1 || v === '1' || v === true || v === 'yes') ? 'yes' : 'no'; }

    // Server-normalized tree -> internal state.
    function fromWire(node) {
        if (node && Array.isArray(node.children)) {
            var g = newGroup(node.op === 'OR' ? 'OR' : 'AND');
            node.children.forEach(function (ch) {
                var n = fromWire(ch);
                if (n) { g.children.push(n); }
            });
            return g;
        }
        if (!node || !CRIT[node.c]) { return null; }
        var def = CRIT[node.c];
        var r = { kind: 'rule', id: uid(), c: node.c, o: node.o, v: null, p: def.param ? (parseInt(node.p, 10) || DEFAULT_MONTHS) : null };
        if ((def.operands || []).indexOf(r.o) < 0) { r.o = def.operands[0]; }
        var v = node.v;
        switch (def.type) {
            case 'date':
            case 'number':
                if (r.o === 'between') { r.v = Array.isArray(v) ? [String(v[0] == null ? '' : v[0]), String(v[1] == null ? '' : v[1])] : ['', '']; }
                else { r.v = v == null || Array.isArray(v) ? '' : String(v); }
                break;
            case 'bool': r.v = yn(v); break;
            case 'enum_set': r.v = (Array.isArray(v) ? v : (v == null ? [] : [v])).map(function (x) { return parseInt(x, 10); }).filter(function (x) { return x > 0; }); break;
            case 'peerage_set':
                if (r.o === 'is') { r.v = yn(v); }
                else { r.v = (Array.isArray(v) ? v : []).map(function (x) { return parseInt(x, 10); }).filter(function (x) { return x > 0; }); }
                break;
        }
        return r;
    }

    function numOrRaw(s) {
        var t = String(s == null ? '' : s).trim();
        return /^-?\d{1,9}$/.test(t) ? parseInt(t, 10) : t;
    }
    // Internal state -> wire tree (indices match the DOM, so rule_path maps back).
    function toWire(node) {
        if (node.kind === 'group') {
            return { op: node.op, children: node.children.map(toWire) };
        }
        var def = CRIT[node.c];
        var out = { c: node.c, o: node.o };
        var v = node.v;
        switch (def.type) {
            case 'date': out.v = Array.isArray(v) ? [v[0] || '', v[1] || ''] : (v || ''); break;
            case 'number': out.v = Array.isArray(v) ? [numOrRaw(v[0]), numOrRaw(v[1])] : numOrRaw(v); break;
            case 'bool': out.v = v; break;
            case 'enum_set': out.v = (node.o === 'is' || node.o === 'is_not') ? (v.length ? v[0] : null) : v.slice(); break;
            case 'peerage_set': out.v = node.o === 'is' ? v : v.slice(); break;
        }
        if (def.param) { out.p = numOrRaw(node.p); }
        return out;
    }

    var root = newGroup('AND');
    var selectedCols = defaultColumns();
    var errorRuleId = null;
    var errorText = '';
    var lastRun = null;          // {tree, columns} of the last successful run
    var fpInstances = [];
    var dt = null;
    var running = false;
    var msgIsRuleError = false;  // #pe-results-msg currently holds "Fix the highlighted rule"
    var idleHtml = '';           // the idle placeholder's original text

    /* ── "changed since last run" hint ───────────────────── */
    var dirtyTimer = null;
    function scheduleDirtyCheck() {
        clearTimeout(dirtyTimer);
        dirtyTimer = setTimeout(function () {
            setDirty(!!lastRun && JSON.stringify(currentState()) !== JSON.stringify(lastRun));
        }, 0);
    }
    function setDirty(on) {
        var h = $('pe-dirty');
        if (h) { h.hidden = !on; }
    }

    /* ── screen-reader announcements (persistent live region) ── */
    var liveTimer = null;
    function announce(text) {
        var n = $('pe-live');
        if (!n) { return; }
        n.textContent = '';
        clearTimeout(liveTimer);
        liveTimer = setTimeout(function () { n.textContent = text; }, 60);
    }
    function announceResults(j) {
        var total = parseInt(j.total, 10) || 0;
        var scopeTotal = parseInt(j.scope_total, 10) || 0;
        var ms = parseInt(j.elapsed_ms, 10) || 0;
        var parts = [fmtInt(total) + (total === 1 ? ' player' : ' players') + ' found'];
        if (scopeTotal > 0) { parts[0] += ' (' + $('pe-stat-pct').textContent + ' of ' + (PE.scope.type === 'Park' ? 'park' : 'kingdom') + ')'; }
        parts.push('in ' + (ms >= 1000 ? (ms / 1000).toFixed(1) + ' seconds' : ms + ' milliseconds'));
        if (j.truncated) { parts.push('showing the first ' + fmtInt((j.rows || []).length)); }
        announce(parts.join(' ') + '.');
    }
    function resetIdle() {
        var p = $('pe-results-idle').querySelector('p');
        if (p && idleHtml) { p.innerHTML = idleHtml; } // static template markup, not user text
    }

    function defaultColumns() {
        var out = [];
        for (var k in COLS) {
            if (Object.prototype.hasOwnProperty.call(COLS, k) && (COLS[k]['default'] || k === 'persona')) { out.push(k); }
        }
        return out;
    }
    function orderedColumns() {
        var out = [];
        for (var k in COLS) {
            if (Object.prototype.hasOwnProperty.call(COLS, k) && (k === 'persona' || selectedCols.indexOf(k) >= 0)) { out.push(k); }
        }
        return out;
    }
    function currentState() { return { tree: toWire(root), columns: orderedColumns() }; }

    function findPath(node, path) {
        var n = node;
        for (var i = 0; i < path.length; i++) {
            if (!n || n.kind !== 'group' || !n.children[path[i]]) { return null; }
            n = n.children[path[i]];
        }
        return n;
    }
    function removeNode(group, id) {
        for (var i = 0; i < group.children.length; i++) {
            var ch = group.children[i];
            if (ch.id === id) { group.children.splice(i, 1); return true; }
            if (ch.kind === 'group' && removeNode(ch, id)) { return true; }
        }
        return false;
    }
    // Parent group and index of a node, for putting focus back after a removal.
    function locate(group, id) {
        for (var i = 0; i < group.children.length; i++) {
            var ch = group.children[i];
            if (ch.id === id) { return { group: group, index: i }; }
            if (ch.kind === 'group') { var f = locate(ch, id); if (f) { return f; } }
        }
        return null;
    }
    // Remove a node, re-render, then focus the previous sibling's first control,
    // or the parent group's "+ Rule" when there is no previous sibling.
    function removeAndRefocus(id) {
        var at = locate(root, id);
        removeNode(root, id);
        render();
        if (!at) { return; }
        var prev = at.index > 0 ? at.group.children[at.index - 1] : null;
        if (prev) { focusNode(prev.id); } else { focusNode(at.group.id, '.pe-btn-add'); }
    }

    /* ── builder render ──────────────────────────────────── */
    function destroyPickers() {
        fpInstances.forEach(function (fp) { try { fp.destroy(); } catch (e) { /* already gone */ } });
        fpInstances = [];
    }

    function render() {
        closeAllChipLists();
        destroyPickers();
        var host = $('pe-builder');
        host.textContent = '';
        host.appendChild(renderGroup(root, 1));
    }

    function renderGroup(g, depth) {
        var box = el('div', 'pe-group' + (depth === 1 ? ' pe-group-root' : '') + ' pe-depth-' + depth);
        box.setAttribute('data-node', g.id);
        if (errorRuleId === g.id) { box.classList.add('pe-invalid'); }

        var head = el('div', 'pe-group-head');
        var seg = el('div', 'pe-seg');
        seg.setAttribute('role', 'group');
        seg.setAttribute('aria-label', 'Combine rules with');
        ['AND', 'OR'].forEach(function (op) {
            var b = el('button', 'pe-seg-btn' + (g.op === op ? ' is-active' : ''), op);
            b.type = 'button';
            b.setAttribute('aria-pressed', g.op === op ? 'true' : 'false');
            b.setAttribute('data-tip', op === 'AND' ? 'Match players who meet ALL of these rules' : 'Match players who meet ANY of these rules');
            b.addEventListener('click', function () { if (g.op !== op) { g.op = op; clearError(); render(); focusNode(g.id, '.pe-seg-btn.is-active'); } });
            seg.appendChild(b);
        });
        head.appendChild(seg);
        head.appendChild(el('span', 'pe-group-hint', g.op === 'AND' ? 'all of these' : 'any of these'));

        var actions = el('div', 'pe-group-actions');
        var addRule = el('button', 'pe-btn-add');
        addRule.type = 'button';
        addRule.innerHTML = '<i class="fas fa-plus"></i> Rule';
        addRule.setAttribute('aria-label', 'Add rule');
        addRule.addEventListener('click', function () {
            var r = newRule();
            g.children.push(r);
            render();
            focusNode(r.id);
        });
        actions.appendChild(addRule);

        var addGroup = el('button', 'pe-btn-add');
        addGroup.type = 'button';
        addGroup.innerHTML = '<i class="fas fa-layer-group"></i> Group';
        addGroup.setAttribute('aria-label', 'Add group');
        if (depth >= UI_MAX_DEPTH) {
            addGroup.setAttribute('aria-disabled', 'true');
            addGroup.classList.add('is-disabled');
            addGroup.setAttribute('data-tip', 'Groups can nest ' + UI_MAX_DEPTH + ' levels deep');
        } else {
            addGroup.setAttribute('data-tip', 'Add a nested group with its own AND / OR');
        }
        addGroup.addEventListener('click', function () {
            if (depth >= UI_MAX_DEPTH) { return; }
            var ng = newGroup(g.op === 'AND' ? 'OR' : 'AND');
            var r = newRule();
            ng.children.push(r);
            g.children.push(ng);
            render();
            focusNode(r.id);
        });
        actions.appendChild(addGroup);

        if (depth > 1) {
            var rm = el('button', 'pe-btn-remove');
            rm.type = 'button';
            rm.innerHTML = '<i class="fas fa-xmark"></i>';
            rm.setAttribute('aria-label', 'Remove group');
            rm.setAttribute('data-tip', 'Remove this group and its rules');
            rm.addEventListener('click', function () { clearError(); removeAndRefocus(g.id); });
            actions.appendChild(rm);
        }
        head.appendChild(actions);
        box.appendChild(head);

        var body = el('div', 'pe-group-body');
        if (g.children.length === 0) {
            body.appendChild(el('div', 'pe-group-empty', depth === 1
                ? 'No rules yet. Run now to list everyone in scope, or add a rule to narrow it down.'
                : 'Empty group. Add a rule, or remove the group.'));
        }
        g.children.forEach(function (ch, i) {
            if (i > 0) {
                var con = el('div', 'pe-connector');
                con.appendChild(el('span', 'pe-connector-label pe-connector-' + g.op.toLowerCase(), g.op));
                body.appendChild(con);
            }
            body.appendChild(ch.kind === 'group' ? renderGroup(ch, depth + 1) : renderRule(ch));
        });
        box.appendChild(body);
        if (depth === 1 && errorRuleId === g.id && errorText) {
            box.appendChild(el('div', 'pe-rule-error', errorText));
        }
        return box;
    }

    function renderRule(r) {
        var def = CRIT[r.c];
        var row = el('div', 'pe-rule' + (errorRuleId === r.id ? ' pe-invalid' : ''));
        row.setAttribute('data-node', r.id);

        // Criteria
        var critCell = el('div', 'pe-rule-crit');
        var sel = el('select', 'pe-input pe-select pe-crit-select');
        sel.setAttribute('aria-label', 'Criteria');
        var groups = {};
        var order = [];
        Object.keys(CRIT).forEach(function (k) {
            var gname = CRIT[k].group || 'Other';
            if (!groups[gname]) { groups[gname] = el('optgroup'); groups[gname].label = gname; order.push(gname); }
            var o = el('option', null, CRIT[k].label);
            o.value = k;
            if (k === r.c) { o.selected = true; }
            groups[gname].appendChild(o);
        });
        order.forEach(function (gname) { sel.appendChild(groups[gname]); });
        sel.addEventListener('change', function () { setCriterion(r, sel.value); clearErrorFor(r); render(); focusNode(r.id, '.pe-crit-select'); });
        critCell.appendChild(sel);

        if (def.param) {
            var pw = el('label', 'pe-param');
            pw.appendChild(el('span', 'pe-param-text', 'N ='));
            var pin = el('input', 'pe-input pe-param-input');
            pin.type = 'number';
            pin.min = '1';
            pin.max = '60';
            pin.step = '1';
            pin.inputMode = 'numeric';
            pin.value = r.p == null ? '' : String(r.p);
            pin.setAttribute('aria-label', 'Number of months');
            pin.addEventListener('input', function () { r.p = pin.value; clearErrorFor(r); });
            pw.appendChild(pin);
            pw.appendChild(el('span', 'pe-param-text', 'months'));
            critCell.appendChild(pw);
        }
        row.appendChild(critCell);

        // Operand
        var opCell = el('div', 'pe-rule-op');
        var osel = el('select', 'pe-input pe-select pe-op-select');
        osel.setAttribute('aria-label', 'Operand');
        (def.operands || []).forEach(function (o) {
            var label = OP_LABEL[o] || o;
            if (def.type === 'peerage_set' && o === 'is') { label = 'holds any'; }
            var opt = el('option', null, label);
            opt.value = o;
            if (o === r.o) { opt.selected = true; }
            osel.appendChild(opt);
        });
        osel.addEventListener('change', function () { setOperand(r, osel.value); clearErrorFor(r); render(); focusNode(r.id, '.pe-op-select'); });
        opCell.appendChild(osel);
        var tip = operandTip(def, r.o);
        if (tip) {
            // Visual tip on hover / keyboard focus (:focus-within); the same text is
            // the select's description for screen readers.
            opCell.setAttribute('data-tip', tip);
            var note = el('span', 'pe-sr-only', tip);
            note.id = 'pe-opnote-' + r.id;
            opCell.appendChild(note);
            osel.setAttribute('aria-describedby', note.id);
        }
        row.appendChild(opCell);

        // Value
        var valCell = el('div', 'pe-rule-val');
        renderValue(valCell, r, def);
        row.appendChild(valCell);

        // Remove
        var rm = el('button', 'pe-btn-remove pe-rule-remove');
        rm.type = 'button';
        rm.innerHTML = '<i class="fas fa-xmark"></i>';
        rm.setAttribute('aria-label', 'Remove rule');
        rm.setAttribute('data-tip', 'Remove this rule');
        rm.addEventListener('click', function () { clearErrorFor(r); removeAndRefocus(r.id); });
        row.appendChild(rm);

        if (errorRuleId === r.id && errorText) {
            var er = el('div', 'pe-rule-error', errorText);
            er.setAttribute('role', 'alert');
            row.appendChild(er);
        }
        return row;
    }

    // Negated operands: the registry's neg_note says what happens to players with no
    // value (spec §3.2: nullable fields do not match; set-style criteria such as
    // "has none of" or "classes in the last N months" do). Plus the criterion's own note.
    function operandTip(def, o) {
        var parts = [];
        if (NEGATED[o] && def.neg_note) { parts.push(def.neg_note); }
        if (def.note) { parts.push(def.note); }
        return parts.join(' ');
    }

    function renderValue(cell, r, def) {
        switch (def.type) {
            case 'date':
                if (r.o === 'between') {
                    cell.appendChild(dateInput(r, 0));
                    cell.appendChild(el('span', 'pe-and', 'and'));
                    cell.appendChild(dateInput(r, 1));
                } else {
                    cell.appendChild(dateInput(r, null));
                }
                break;
            case 'number':
                if (r.o === 'between') {
                    cell.appendChild(numberInput(r, 0));
                    cell.appendChild(el('span', 'pe-and', 'and'));
                    cell.appendChild(numberInput(r, 1));
                } else {
                    cell.appendChild(numberInput(r, null));
                }
                break;
            case 'bool':
                cell.appendChild(yesNo(r));
                break;
            case 'enum_set':
                cell.appendChild(chipSelect(r, def, r.o === 'is' || r.o === 'is_not'));
                break;
            case 'peerage_set':
                cell.appendChild(r.o === 'is' ? yesNo(r) : chipSelect(r, def, false));
                break;
        }
    }

    function readSlot(r, idx) { return idx === null ? (r.v || '') : ((r.v || [])[idx] || ''); }
    function writeSlot(r, idx, val) {
        if (idx === null) { r.v = val; } else { if (!Array.isArray(r.v)) { r.v = ['', '']; } r.v[idx] = val; }
    }

    function dateInput(r, idx) {
        var wrap = el('span', 'pe-date');
        var inp = el('input', 'pe-input pe-date-input');
        inp.type = 'text';
        inp.value = readSlot(r, idx);
        inp.placeholder = idx === 1 ? 'End date' : (idx === 0 ? 'Start date' : 'Choose a date');
        inp.setAttribute('aria-label', idx === 1 ? 'End date' : (idx === 0 ? 'Start date' : 'Date'));
        wrap.appendChild(inp);
        if (typeof window.flatpickr === 'function') {
            // Defer until the input is in the document so altInput copies the classes cleanly.
            setTimeout(function () {
                if (!inp.isConnected) { return; }
                var fp = window.flatpickr(inp, {
                    dateFormat: 'Y-m-d',
                    altInput: true,
                    altFormat: 'M j, Y',
                    allowInput: true,
                    disableMobile: true,
                    onReady: function (_d, _s, inst) {
                        inst.calendarContainer.classList.add('pe-fp');
                        if (inst.altInput) { inst.altInput.setAttribute('aria-label', inp.getAttribute('aria-label')); }
                    },
                    onChange: function (_d, str) { writeSlot(r, idx, str); clearErrorFor(r); }
                });
                fpInstances.push(fp);
            }, 0);
        } else {
            inp.placeholder = 'YYYY-MM-DD';
            inp.addEventListener('input', function () { writeSlot(r, idx, inp.value.trim()); clearErrorFor(r); });
        }
        return wrap;
    }

    function numberInput(r, idx) {
        var inp = el('input', 'pe-input pe-num-input');
        inp.type = 'number';
        inp.step = '1';
        inp.inputMode = 'numeric';
        inp.value = readSlot(r, idx);
        inp.placeholder = idx === 1 ? 'to' : (idx === 0 ? 'from' : 'Number');
        inp.setAttribute('aria-label', idx === 1 ? 'Upper value' : (idx === 0 ? 'Lower value' : 'Value'));
        inp.addEventListener('input', function () { writeSlot(r, idx, inp.value); clearErrorFor(r); });
        return inp;
    }

    function yesNo(r) {
        var seg = el('div', 'pe-seg pe-seg-yn');
        seg.setAttribute('role', 'group');
        seg.setAttribute('aria-label', 'Yes or no');
        [['yes', 'Yes'], ['no', 'No']].forEach(function (pair) {
            var b = el('button', 'pe-seg-btn' + (r.v === pair[0] ? ' is-active' : ''), pair[1]);
            b.type = 'button';
            b.setAttribute('aria-pressed', r.v === pair[0] ? 'true' : 'false');
            b.addEventListener('click', function () {
                r.v = pair[0];
                clearErrorFor(r);
                Array.prototype.forEach.call(seg.children, function (x) {
                    var on = x === b;
                    x.classList.toggle('is-active', on);
                    x.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
            });
            seg.appendChild(b);
        });
        return seg;
    }

    /* ── chip multi-select (custom; not jQuery UI) ───────── */
    var openChipLists = [];
    function closeAllChipLists() {
        openChipLists.slice().forEach(function (fn) { fn(); });
        openChipLists = [];
    }

    function chipSelect(r, def, single) {
        var opts = optionsFor(def);
        if (!Array.isArray(r.v)) { r.v = []; }
        var box = el('div', 'pe-chips' + (single ? ' pe-chips-single' : ''));
        var chipWrap = el('span', 'pe-chip-wrap');
        var input = el('input', 'pe-chip-input');
        input.type = 'text';
        input.autocomplete = 'off';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-label', 'Choose ' + (def.set || 'awards'));
        var listId = uid() + '-list';
        input.setAttribute('aria-controls', listId);
        var list = el('ul', 'pe-chip-list');
        list.id = listId;
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        var active = -1;
        var visible = [];

        function placeholder() {
            if (r.v.length) { return single ? '' : 'Add…'; }
            if (!opts.length) { return 'No options in this scope'; }
            return single ? 'Choose one…' : 'Choose one or more…';
        }
        function drawChips() {
            chipWrap.textContent = '';
            r.v.forEach(function (id) {
                var chip = el('span', 'pe-chip');
                chip.appendChild(el('span', 'pe-chip-label', optionName(def, id)));
                var x = el('button', 'pe-chip-x');
                x.type = 'button';
                x.innerHTML = '<i class="fas fa-xmark"></i>';
                x.setAttribute('aria-label', 'Remove ' + optionName(def, id));
                x.addEventListener('click', function (e) {
                    e.stopPropagation();
                    r.v = r.v.filter(function (v) { return v !== id; });
                    clearErrorFor(r);
                    drawChips();
                    if (!list.hidden) { drawList(); }
                });
                chip.appendChild(x);
                chipWrap.appendChild(chip);
            });
            input.placeholder = placeholder();
            box.classList.toggle('has-value', r.v.length > 0);
        }
        function drawList() {
            var q = input.value.trim().toLowerCase();
            list.textContent = '';
            visible = opts.filter(function (o) {
                return r.v.indexOf(o[0]) < 0 && (!q || String(o[1]).toLowerCase().indexOf(q) >= 0);
            }).slice(0, 200);
            if (!visible.length) {
                var li = el('li', 'pe-chip-empty', q ? 'No matches' : 'Nothing left to add');
                list.appendChild(li);
            }
            visible.forEach(function (o, i) {
                var li = el('li', 'pe-chip-opt' + (i === active ? ' is-active' : ''), o[1]);
                li.setAttribute('role', 'option');
                li.id = listId + '-' + i;
                li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(o[0]); });
                list.appendChild(li);
            });
            if (active >= 0 && visible[active]) { input.setAttribute('aria-activedescendant', listId + '-' + active); }
            else { input.removeAttribute('aria-activedescendant'); }
        }
        function open() {
            if (!list.hidden) { return; }
            closeAllChipLists();
            list.hidden = false;
            box.classList.add('is-open');
            input.setAttribute('aria-expanded', 'true');
            active = -1;
            drawList();
            openChipLists.push(close);
        }
        function close() {
            list.hidden = true;
            box.classList.remove('is-open');
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            openChipLists = openChipLists.filter(function (f) { return f !== close; });
        }
        function pick(id) {
            if (single) { r.v = [id]; } else if (r.v.indexOf(id) < 0) { r.v.push(id); }
            clearErrorFor(r);
            input.value = '';
            drawChips();
            if (single) { close(); } else { active = -1; drawList(); }
        }

        input.addEventListener('focus', open);
        input.addEventListener('input', function () { open(); active = 0; drawList(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); open(); active = Math.min(active + 1, visible.length - 1); drawList(); scrollActive(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); drawList(); scrollActive(); }
            else if (e.key === 'Enter') { e.preventDefault(); if (!list.hidden && visible[active]) { pick(visible[active][0]); } }
            else if (e.key === 'Escape') { close(); }
            else if (e.key === 'Backspace' && input.value === '' && r.v.length) { r.v.pop(); clearErrorFor(r); drawChips(); if (!list.hidden) { drawList(); } }
            else if (e.key === 'Tab') { close(); }
        });
        input.addEventListener('blur', function () { setTimeout(function () { if (!box.contains(document.activeElement)) { close(); } }, 120); });
        function scrollActive() {
            var n = list.children[active];
            if (n && n.scrollIntoView) { n.scrollIntoView({ block: 'nearest' }); }
        }
        box.addEventListener('mousedown', function (e) {
            if (e.target === box || e.target === chipWrap) { e.preventDefault(); input.focus(); }
        });

        box.appendChild(chipWrap);
        box.appendChild(input);
        box.appendChild(list);
        drawChips();
        return box;
    }
    document.addEventListener('mousedown', function (e) {
        if (!e.target.closest || !e.target.closest('.pe-chips')) { closeAllChipLists(); }
    });

    function focusNode(id, sub) {
        var n = document.querySelector('[data-node="' + id + '"]');
        if (!n) { return; }
        var f = n.querySelector(sub || 'select, input, button');
        if (f) { try { f.focus({ preventScroll: false }); } catch (e) { f.focus(); } }
    }

    /* ── errors ──────────────────────────────────────────── */
    function clearError() { errorRuleId = null; errorText = ''; }
    function clearErrorFor(r) {
        scheduleDirtyCheck();
        if (errorRuleId !== r.id) { return; }
        var n = document.querySelector('[data-node="' + r.id + '"]');
        if (n) {
            n.classList.remove('pe-invalid');
            var er = n.querySelector(':scope > .pe-rule-error');
            if (er) { er.remove(); }
        }
        clearError();
        if (msgIsRuleError) { showMsg('', ''); } // the "Fix the highlighted rule" banner goes with it
    }

    // Client-side completeness check, shared by Run and Copy link. The server
    // re-validates everything; this only catches unfinished rules early.
    // Returns {node, msg} for the first incomplete rule, or null.
    var ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
    var INT = /^-?\d{1,9}$/;
    function checkRule(r) {
        var def = CRIT[r.c];
        if (!def) { return 'Choose a criterion'; }
        if (def.param) {
            var p = String(r.p == null ? '' : r.p).trim();
            if (!/^\d{1,2}$/.test(p) || +p < 1 || +p > MAX_MONTHS) { return 'Months must be 1 to ' + MAX_MONTHS; }
        }
        var v = r.v;
        var between = r.o === 'between';
        switch (def.type) {
            case 'date':
            case 'number':
                var re = def.type === 'date' ? ISO_DATE : INT;
                var vals = between ? (Array.isArray(v) ? v : ['', '']) : [v];
                for (var i = 0; i < vals.length; i++) {
                    var t = String(vals[i] == null ? '' : vals[i]).trim();
                    if (t === '') { return def.type === 'date' ? (between ? 'Choose both dates' : 'Choose a date') : (between ? 'Enter both numbers' : 'Enter a number'); }
                    if (!re.test(t)) { return def.type === 'date' ? 'Value must be a valid date' : 'Value must be a whole number'; }
                }
                if (between) {
                    var a = def.type === 'date' ? String(vals[0]) : +vals[0];
                    var b = def.type === 'date' ? String(vals[1]) : +vals[1];
                    if (a > b) { return 'Between range must be in ascending order'; }
                }
                return null;
            case 'enum_set':
                return Array.isArray(v) && v.length ? null : 'Choose at least one';
            case 'peerage_set':
                if (r.o === 'is') { return null; }
                return Array.isArray(v) && v.length ? null : 'Choose at least one';
        }
        return null;
    }
    function firstIncomplete(node) {
        if (node.kind === 'rule') { var m = checkRule(node); return m ? { node: node, msg: m } : null; }
        for (var i = 0; i < node.children.length; i++) {
            var f = firstIncomplete(node.children[i]);
            if (f) { return f; }
        }
        return null;
    }
    // Highlight a rule with its message, inline and in the results banner.
    function markRuleError(node, msg) {
        errorRuleId = node.id;
        errorText = msg;
        render();
        var n = document.querySelector('[data-node="' + node.id + '"]');
        if (n && n.scrollIntoView) { n.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        focusNode(node.id);
        showMsg('error', '<i class="fas fa-circle-exclamation"></i> <span>Fix the highlighted rule: ' + esc(msg) + '</span>');
        msgIsRuleError = true;
        announce('Fix the highlighted rule: ' + msg);
    }

    /* ── columns card ────────────────────────────────────── */
    function renderColumns() {
        var host = $('pe-columns');
        host.textContent = '';
        var groups = {};
        var order = [];
        Object.keys(COLS).forEach(function (k) {
            var g = COLS[k].group || 'Other';
            if (!groups[g]) {
                groups[g] = el('fieldset', 'pe-col-group');
                groups[g].appendChild(el('legend', 'pe-col-group-title', g));
                order.push(g);
            }
            var lab = el('label', 'pe-col-check');
            var cb = el('input');
            cb.type = 'checkbox';
            cb.value = k;
            cb.checked = k === 'persona' || selectedCols.indexOf(k) >= 0;
            if (k === 'persona') {
                cb.disabled = true;
                lab.classList.add('is-locked');
                lab.setAttribute('data-tip', 'Persona is always included');
            }
            cb.addEventListener('change', function () {
                if (cb.checked) { if (selectedCols.indexOf(k) < 0) { selectedCols.push(k); } }
                else { selectedCols = selectedCols.filter(function (c) { return c !== k; }); }
                scheduleDirtyCheck();
            });
            lab.appendChild(cb);
            lab.appendChild(el('span', null, COLS[k].label));
            if (k === 'persona') { var lk = el('i', 'fas fa-lock pe-col-lock'); lk.setAttribute('aria-hidden', 'true'); lab.appendChild(lk); }
            groups[g].appendChild(lab);
        });
        order.forEach(function (g) { host.appendChild(groups[g]); });
    }

    /* ── run ─────────────────────────────────────────────── */
    function postJson(payload) {
        return fetch(PE.urls.run, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (res) {
            if (isLoginRedirect(res)) { return { status: 5, error: 'Not logged in' }; }
            return res.text().then(function (t) {
                try { return JSON.parse(t); } catch (e) { return { status: -1, error: 'The server returned an unexpected response (HTTP ' + res.status + ').' }; }
            });
        });
    }

    // A replaced/expired session is redirected to Login by the base controller.
    function isLoginRedirect(res) { return !!(res.redirected && /Route=Login/i.test(res.url || '')); }
    function loginPromptHtml() {
        return '<i class="fas fa-circle-exclamation"></i> <span>Your session has ended. <a href="' + esc(PE.urls.run.replace(/Reports\/population_explorer_json$/, 'Login/login')) + '">Log in again</a> and re-run.</span>';
    }

    function requestFor(state) {
        return { ScopeType: PE.scope.type, ScopeId: PE.scope.id, Tree: state.tree, Columns: state.columns };
    }


    function setRunning(on) {
        running = on;
        var btn = $('pe-run');
        btn.disabled = on;
        btn.classList.toggle('is-loading', on);
        btn.querySelector('i').className = on ? 'fas fa-spinner fa-spin' : 'fas fa-play';
        btn.querySelector('span').textContent = on ? 'Running…' : 'Run';
        $('pe-results-card').classList.toggle('is-loading', on);
    }

    function showMsg(kind, html) {
        msgIsRuleError = false;
        var m = $('pe-results-msg');
        m.innerHTML = html ? '<div class="pe-banner pe-banner-' + kind + '" role="' + (kind === 'error' ? 'alert' : 'status') + '">' + html + '</div>' : '';
    }

    function run() {
        if (running) { return; }
        var bad = firstIncomplete(root);
        if (bad) { markRuleError(bad.node, bad.msg); if (!dt) { resetIdle(); $('pe-results-idle').hidden = false; } return; }
        var state = currentState();
        if (errorRuleId) { clearError(); render(); } // re-render only to drop a stale rule error
        setRunning(true);
        showMsg('', '');
        resetIdle();
        $('pe-results-idle').hidden = true;
        if (!dt) {
            $('pe-table-area').hidden = true;
            showMsg('info', '<i class="fas fa-spinner fa-spin"></i> <span>Running the query…</span>');
        }
        postJson(requestFor(state)).then(function (j) {
            setRunning(false);
            if (!j || j.status !== 0) { return handleError(j || {}); }
            lastRun = state;
            setExportEnabled(true);
            showResults(j);
            updatePct(j.total, parseInt(j.scope_total, 10) || 0);
            setDirty(false);
            announceResults(j);
        }, function () {
            setRunning(false);
            handleError({ status: -1, error: 'Could not reach the server. Check your connection and try again.' });
        });
    }

    function handleError(j) {
        var msg = j.error ? String(j.error) : 'The report could not be run.';
        // The endpoint joins a generic status sentence and the detail as "Generic.: Detail"; keep the detail.
        msg = msg.replace(/^[^:]*\.:\s+/, '');
        if (/not logged in/i.test(msg)) {
            showMsg('error', loginPromptHtml());
            announce('Your session has ended. Log in again and re-run.');
            return;
        }
        if (Array.isArray(j.rule_path)) {
            var node = findPath(root, j.rule_path);
            if (node) {
                markRuleError(node, msg);
                if (!dt) { $('pe-results-idle').hidden = false; }
                return;
            }
        }
        showMsg('error', '<i class="fas fa-circle-exclamation"></i> <span>' + esc(msg) + '</span>');
        announce(msg);
        if (!dt) { $('pe-results-idle').hidden = false; }
    }

    function updatePct(total, scopeTotal) {
        var pct = $('pe-stat-pct');
        if (scopeTotal > 0) {
            var p = (total / scopeTotal) * 100;
            pct.textContent = (p > 0 && p < 0.1 ? '<0.1' : (p >= 10 ? Math.round(p) : p.toFixed(1))) + '%';
        } else {
            pct.textContent = '—';
        }
    }

    function cellRenderer(col) {
        return function (data, type, row) {
            if (col.id === 'persona') {
                var name = data == null || data === '' ? '(no persona)' : String(data);
                if (type !== 'display') { return name; }
                return '<a class="pe-persona" href="' + esc(PE.urls.player + parseInt(row.MundaneId, 10)) + '">' + esc(name) + '</a>';
            }
            if (data == null) { return type === 'display' ? '<span class="pe-null">—</span>' : ''; }
            switch (col.type) {
                case 'date':
                    return type === 'display' || type === 'filter' ? esc(humanDate(data)) : data;
                case 'bool':
                    if (type === 'display') { return data ? '<span class="pe-yes">Yes</span>' : '<span class="pe-no">No</span>'; }
                    return data ? 'Yes' : 'No';
                case 'number':
                    return type === 'display' ? esc(fmtInt(data)) : data;
                default:
                    return type === 'display' ? esc(data) : data;
            }
        };
    }

    function showResults(j) {
        var cols = Array.isArray(j.columns) ? j.columns : [];
        var rows = Array.isArray(j.rows) ? j.rows : [];
        $('pe-stat-results').textContent = fmtInt(j.total);
        var ms = parseInt(j.elapsed_ms, 10) || 0;
        $('pe-stat-time').textContent = ms >= 1000 ? (ms / 1000).toFixed(1) + ' s' : ms + ' ms';

        if (j.truncated) {
            showMsg('warn', '<i class="fas fa-triangle-exclamation"></i> <span>Showing ' + esc(fmtInt(rows.length)) + ' of ' + esc(fmtInt(j.total)) + ' — narrow your filter to see everyone.</span>');
        } else {
            showMsg('', '');
        }

        if (dt) { try { dt.destroy(); } catch (e) { /* ignore */ } dt = null; }
        var area = $('pe-table-area');
        area.textContent = '';
        if (!rows.length) {
            area.hidden = true;
            var idle = $('pe-results-idle');
            idle.hidden = false;
            idle.querySelector('p').textContent = 'No players match these filters. Loosen a rule or switch a group to OR.';
            return;
        }
        $('pe-results-idle').hidden = true;
        area.hidden = false;

        var table = el('table', 'display pe-table');
        table.id = 'pe-table';
        table.style.width = '100%';
        var thead = el('thead');
        var tr = el('tr');
        cols.forEach(function (c) { tr.appendChild(el('th', null, c.label)); });
        thead.appendChild(tr);
        table.appendChild(thead);
        area.appendChild(table);

        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.DataTable) {
            // DataTables failed to load: plain table fallback, still escaped.
            var tb = el('tbody');
            rows.forEach(function (row) {
                var r2 = el('tr');
                cols.forEach(function (c) { var td = el('td'); td.innerHTML = cellRenderer(c)(row[c.id], 'display', row); r2.appendChild(td); });
                tb.appendChild(r2);
            });
            table.appendChild(tb);
            return;
        }
        dt = window.jQuery(table).DataTable({
            data: rows,
            columns: cols.map(function (c) {
                return {
                    data: function (row) { return row[c.id] === undefined ? null : row[c.id]; },
                    render: cellRenderer(c),
                    className: c.type === 'number' ? 'dt-body-right' : ''
                };
            }),
            order: [[0, 'asc']],
            pageLength: 25,
            lengthMenu: [[25, 50, 100, 250, -1], [25, 50, 100, 250, 'All']],
            deferRender: true,
            autoWidth: false,
            dom: '<"pe-dt-top"lBf>rt<"pe-dt-bottom"ip>',
            // Buttons print writes the title into the print window's <title>/<h1> unescaped.
            buttons: [{ extend: 'print', text: '<i class="fas fa-print"></i> Print', className: 'pe-dt-btn', title: 'Population Explorer — ' + esc(PE.scope.name || '') }],
            language: { search: 'Search results:', emptyTable: 'No players match these filters.' }
        });
    }

    /* ── share link ──────────────────────────────────────── */
    function b64url(str) {
        var bytes = unescape(encodeURIComponent(str));
        return btoa(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function shareUrl() {
        // `pe`, not `q`: analytics records any `q` parameter as a site search.
        var url = PE.urls.share + b64url(JSON.stringify(currentState()));
        var full = url;
        try { full = new URL(url, window.location.href).href; } catch (e) { /* relative URL: measure as is */ }
        return full.length > MAX_SHARE_URL ? null : url;
    }
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallbackCopy(text); });
        }
        return Promise.resolve(fallbackCopy(text));
    }
    function fallbackCopy(text) {
        var ta = el('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        ta.remove();
        return ok;
    }
    var toastTimer = null;
    function toast(text, kind) {
        var t = $('pe-toast');
        t.textContent = text;
        t.className = 'pe-toast' + (kind ? ' pe-toast-' + kind : '');
        t.hidden = false;
        void t.offsetWidth;
        t.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            t.classList.remove('is-visible');
            setTimeout(function () { t.hidden = true; }, 200);
        }, 2800);
    }
    function onCopyLink() {
        // Never encode unfinished rules: the recipient's page would reject the whole link.
        var bad = firstIncomplete(root);
        if (bad) {
            markRuleError(bad.node, bad.msg);
            toast('Finish or remove the highlighted rule before copying a link.', 'error');
            return;
        }
        var url = shareUrl();
        if (!url) { toast('This filter is too large to share as a link. Remove a few rules and try again.', 'error'); return; }
        try { window.history.replaceState(null, '', url); } catch (e) { /* cross-origin guard */ }
        copyText(url).then(function (ok) {
            toast(ok ? 'Link copied. Anyone with access to this scope can open it.' : 'Could not copy automatically. The link is in your address bar.', ok ? 'ok' : 'error');
        });
    }

    /* ── export ──────────────────────────────────────────── */
    function setExportEnabled(on) {
        var b = $('pe-export');
        if (!b) { return; }
        b.setAttribute('aria-disabled', on ? 'false' : 'true');
        b.classList.toggle('is-disabled', !on);
        b.setAttribute('data-tip', on ? 'Download the last run as an Excel file' : 'Run the report first, then export the results to Excel');
    }
    var exporting = false;
    function setExportBusy(on) {
        exporting = on;
        var b = $('pe-export');
        if (!b) { return; }
        b.classList.toggle('is-busy', on);
        b.setAttribute('aria-busy', on ? 'true' : 'false');
        var i = b.querySelector('i');
        if (i) { i.className = on ? 'fas fa-spinner fa-spin' : 'fas fa-file-excel'; }
    }
    function filenameFrom(disposition) {
        var m = /filename="?([^";]+)"?/i.exec(disposition || '');
        return m ? m[1] : '';
    }
    function saveBlob(blob, name) {
        var url = URL.createObjectURL(blob);
        var a = el('a');
        a.href = url;
        a.download = name;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { a.remove(); URL.revokeObjectURL(url); }, 1000);
    }
    // fetch + blob, so a failed export shows an error here instead of replacing the page.
    function onExport() {
        if (!lastRun) { toast('Run the report first, then export.', 'error'); return; }
        if (exporting) { return; }
        setExportBusy(true);
        var body = new URLSearchParams();
        body.set('payload', JSON.stringify(requestFor(lastRun)));
        fetch(PE.urls.export, { method: 'POST', credentials: 'same-origin', body: body }).then(function (res) {
            if (res.status === 401 || isLoginRedirect(res)) { return { login: true }; }
            var type = res.headers.get('Content-Type') || '';
            if (!res.ok || type.indexOf('spreadsheetml') < 0) {
                return res.text().then(function (t) {
                    var text = String(t || '').trim();
                    return { error: res.ok || !text || text.charAt(0) === '<' ? 'The export could not be created (HTTP ' + res.status + ').' : text };
                });
            }
            return res.blob().then(function (b) {
                saveBlob(b, filenameFrom(res.headers.get('Content-Disposition')) || 'population-explorer.xlsx');
                return { ok: true };
            });
        }).then(function (out) {
            setExportBusy(false);
            if (out.login) { showMsg('error', loginPromptHtml()); announce('Your session has ended. Log in again to export.'); return; }
            if (out.error) { toast(out.error, 'error'); announce(out.error); return; }
            toast('Excel file downloaded.', 'ok');
        }, function () {
            setExportBusy(false);
            toast('Could not reach the server. Check your connection and try again.', 'error');
        });
    }

    /* ── boot ────────────────────────────────────────────── */
    function init() {
        var initial = PE.initial;
        if (initial && initial.tree) {
            var g = fromWire(initial.tree);
            if (g && g.kind === 'group') { root = g; }
            if (Array.isArray(initial.columns) && initial.columns.length) {
                selectedCols = initial.columns.filter(function (c) { return COLS[c]; });
                if (selectedCols.indexOf('persona') < 0) { selectedCols.unshift('persona'); }
            }
        }
        // A fresh page starts with zero rules: the empty-root hint explains Run lists everyone.
        render();
        renderColumns();
        setExportEnabled(false);
        var idleP = $('pe-results-idle').querySelector('p');
        idleHtml = idleP ? idleP.innerHTML : '';

        $('pe-run').addEventListener('click', run);
        $('pe-clear').addEventListener('click', function () {
            root = newGroup('AND');
            clearError();
            if (msgIsRuleError) { showMsg('', ''); }
            render();
            focusNode(root.id, '.pe-btn-add');
            scheduleDirtyCheck();
        });
        $('pe-columns-reset').addEventListener('click', function () { selectedCols = defaultColumns(); renderColumns(); scheduleDirtyCheck(); });
        // Structural edits (add/remove/toggle/yes-no/chips) all happen inside the builder.
        ['click', 'change', 'input', 'keyup'].forEach(function (ev) {
            $('pe-builder').addEventListener(ev, scheduleDirtyCheck);
        });
        $('pe-copy-link').addEventListener('click', onCopyLink);
        $('pe-export').addEventListener('click', onExport);
        $('pe-builder').addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); run(); }
        });

        if (initial && initial.tree) { run(); }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
