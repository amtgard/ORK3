/* Population Explorer — Help guide dialog.
 * Markup: #pe-help in Reports_populationexplorer.tpl (static prose), opened by #pe-help-open.
 * The Criteria reference (#pe-help-ref-list) is built here from window.PE.registry, the
 * same registry the rule picker reads, so it cannot drift from the builder. Registry text
 * only ever goes in through textContent. Operator symbols come from PE.opLabel, which
 * populationexplorer.js sets (it loads first).
 * Dialog: focus moves in on open and is trapped; Esc, the close button and a backdrop
 * click close it; focus returns to the Help button; <html> is clamped so the page
 * behind does not scroll; the table of contents scrolls the dialog, never the URL hash.
 */
(function () {
    'use strict';

    var PE = window.PE;
    var overlay = document.getElementById('pe-help');
    var opener = document.getElementById('pe-help-open');
    if (!PE || !PE.registry || !overlay || !opener) { return; }

    var box = overlay.querySelector('.pe-help-box');
    var body = document.getElementById('pe-help-body');
    var title = document.getElementById('pe-help-title');
    var closeBtn = document.getElementById('pe-help-close');
    // A <body> child, so no ancestor's stacking context or transform can trap the fixed overlay.
    document.body.appendChild(overlay);

    /* ── Criteria reference (generated) ──────────────────── */
    var WORDS = {
        date: { eq: 'on', ne: 'not on', gt: 'after', gte: 'on or after', lt: 'before', lte: 'on or before', between: 'between (both ends included)' },
        number: { eq: 'equals', ne: 'does not equal', gt: 'more than', gte: 'at least', lt: 'less than', lte: 'at most', between: 'between (both ends included)' },
        enum_set: { is: 'is', is_not: 'is not', in: 'is any of', not_in: 'is none of' },
        bool: { is: 'is Yes / No' },
        peerage_set: { has_any: 'has any of', has_all: 'has all of', has_none: 'has none of', is: 'holds any (Yes / No)' }
    };
    var SET_NAMES = { 'class': 'classes', park: 'parks', kingdom: 'kingdoms', award: 'awards' };

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text != null) { n.textContent = text; }
        return n;
    }
    function fmt(n) { return Number(n).toLocaleString(); }

    function valueText(def) {
        switch (def.type) {
            case 'date': return 'Date';
            case 'number':
                if (typeof def.min === 'number' && typeof def.max === 'number') { return 'Whole number, ' + fmt(def.min) + ' to ' + fmt(def.max); }
                if (typeof def.min === 'number') { return 'Whole number, ' + fmt(def.min) + ' or more'; }
                return 'Whole number';
            case 'bool': return 'Yes / No';
            case 'enum_set': return 'Pick ' + (SET_NAMES[def.set] || 'from a list');
            case 'peerage_set': return 'Pick orders, or Yes / No';
        }
        return '';
    }

    // One pill per operator: the builder's symbol, plus plain words where the symbol is not a word.
    function opPills(def) {
        var wrap = el('div', 'pe-help-ops');
        var labels = PE.opLabel || {};
        var words = WORDS[def.type] || {};
        (def.operands || []).forEach(function (o) {
            var pill = el('span', 'pe-help-op');
            var sym = labels[o] || o;
            var word = words[o] || sym;
            if (/^[a-z]/i.test(sym)) {
                pill.textContent = word;
            } else {
                pill.appendChild(el('b', null, sym));
                pill.appendChild(document.createTextNode(' ' + word));
            }
            wrap.appendChild(pill);
        });
        return wrap;
    }

    function tag(text, extra) { return el('span', 'pe-help-tag' + (extra ? ' ' + extra : ''), text); }

    function notes(item, def) {
        if (def.note) { item.appendChild(el('p', 'pe-help-ref-note', def.note)); }
        if (def.neg_note) {
            var n = String(def.neg_note);
            item.appendChild(el('p', 'pe-help-ref-note', 'With a “not” operator, ' + n.charAt(0).toLowerCase() + n.slice(1)));
        }
    }

    // Criteria that differ only in their label (the ladders) share one entry.
    function signature(def) {
        return JSON.stringify([def.type, def.operands, def.param, def.min, def.max, def.set, def.peerage, def.note, def.neg_note, !!def.restricted]);
    }

    function buildItem(defs) {
        var def = defs[0];
        var item = el('li', 'pe-help-ref-item');
        var head = el('div', 'pe-help-ref-head');
        if (defs.length === 1) {
            head.appendChild(el('span', 'pe-help-ref-name', def.label));
        } else {
            head.appendChild(el('span', 'pe-help-ref-name', 'Each of these ' + defs.length + ' criteria'));
        }
        head.appendChild(tag(valueText(def)));
        if (def.param) { head.appendChild(tag('+ N months, 1 to 60')); }
        if (def.restricted) { head.appendChild(tag('Officers only', 'pe-help-tag-officer')); }
        item.appendChild(head);
        if (defs.length > 1) {
            var names = el('div', 'pe-help-ref-names');
            defs.forEach(function (d) { names.appendChild(el('span', 'pe-help-chip', d.label)); });
            item.appendChild(names);
        }
        item.appendChild(opPills(def));
        notes(item, def);
        return item;
    }

    var refBuilt = false;
    function buildReference() {
        if (refBuilt) { return; }
        refBuilt = true;
        var host = document.getElementById('pe-help-ref-list');
        if (!host) { return; }
        var crit = PE.registry.criteria || {};
        var groups = {};
        var order = [];
        Object.keys(crit).forEach(function (id) {
            var g = crit[id].group || 'Other';
            if (!groups[g]) { groups[g] = []; order.push(g); }
            groups[g].push(crit[id]);
        });
        order.forEach(function (g) {
            var sec = el('div', 'pe-help-ref-group');
            sec.appendChild(el('h4', 'pe-help-h4', g));
            var list = el('ul', 'pe-help-ref-list');
            var defs = groups[g];
            for (var i = 0; i < defs.length;) {
                var j = i + 1;
                while (j < defs.length && signature(defs[j]) === signature(defs[i])) { j++; }
                if (j - i >= 3) {
                    list.appendChild(buildItem(defs.slice(i, j)));
                } else {
                    for (var k = i; k < j; k++) { list.appendChild(buildItem([defs[k]])); }
                }
                i = j;
            }
            sec.appendChild(list);
            host.appendChild(sec);
        });
    }

    /* ── dialog ──────────────────────────────────────────── */
    var isOpen = false;
    var downOnBackdrop = false;

    function tabbables() {
        return Array.prototype.filter.call(
            box.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (n) { return !n.disabled && n.offsetParent !== null; }
        );
    }

    function openHelp() {
        if (isOpen) { return; }
        buildReference();
        isOpen = true;
        overlay.hidden = false;
        document.documentElement.classList.add('pe-help-lock'); // <html> only: the one page scroller
        opener.setAttribute('aria-expanded', 'true');
        void overlay.offsetWidth; // start the fade from the hidden state
        overlay.classList.add('is-open');
        title.focus();
    }

    function closeHelp() {
        if (!isOpen) { return; }
        isOpen = false;
        overlay.classList.remove('is-open');
        overlay.hidden = true;
        document.documentElement.classList.remove('pe-help-lock');
        opener.setAttribute('aria-expanded', 'false');
        opener.focus();
    }

    // Tab / Shift+Tab wrap inside the dialog. Works from a focused heading (tabindex -1)
    // too: the next or previous tabbable is found by document position.
    function trapTab(e) {
        var list = tabbables();
        if (!list.length) { e.preventDefault(); title.focus(); return; }
        var a = document.activeElement;
        var target = null;
        if (!box.contains(a)) {
            target = e.shiftKey ? list[list.length - 1] : list[0];
        } else if (e.shiftKey) {
            var prev = list.filter(function (n) { return n.compareDocumentPosition(a) & Node.DOCUMENT_POSITION_FOLLOWING; });
            if (!prev.length) { target = list[list.length - 1]; }
        } else {
            var next = list.filter(function (n) { return a.compareDocumentPosition(n) & Node.DOCUMENT_POSITION_FOLLOWING; });
            if (!next.length) { target = list[0]; }
        }
        if (target) { e.preventDefault(); target.focus(); }
    }

    opener.addEventListener('click', openHelp);
    closeBtn.addEventListener('click', closeHelp);
    overlay.addEventListener('mousedown', function (e) { downOnBackdrop = e.target === overlay; });
    overlay.addEventListener('click', function (e) {
        // Only a click that starts and ends on the backdrop: a text selection dragged out of the box must not close it.
        if (e.target === overlay && downOnBackdrop) { closeHelp(); }
        downOnBackdrop = false;
    });
    document.addEventListener('keydown', function (e) {
        if (!isOpen) { return; }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeHelp(); return; }
        if (e.key === 'Tab') { trapTab(e); }
    }, true);
    document.addEventListener('focusin', function (e) {
        if (isOpen && !box.contains(e.target)) { title.focus(); }
    });

    // Table of contents: scroll the dialog body to the section and focus its heading.
    var toc = overlay.querySelector('.pe-help-toc');
    if (toc) {
        toc.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a[href^="#"]') : null;
            if (!a) { return; }
            e.preventDefault(); // never touch the page's URL hash
            var t = document.getElementById(a.getAttribute('href').slice(1));
            if (!t || !body.contains(t)) { return; }
            body.scrollTop += t.getBoundingClientRect().top - body.getBoundingClientRect().top - 12;
            try { t.focus({ preventScroll: true }); } catch (err) { t.focus(); }
        });
    }
})();
