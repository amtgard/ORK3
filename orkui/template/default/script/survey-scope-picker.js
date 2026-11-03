/* Survey scope picker — the Kingdom / Park <select> that the New Survey modal
   and the builder's Audience card share.

   An ORK admin's list is every active kingdom and every active park, several
   hundred rows in one flat dropdown; a kingdom officer with principalities
   gets fifty-odd. This groups the parks under their kingdom (an <optgroup>
   per kingdom, the kingdom itself first as "All of <kingdom>") and, once the
   list is long, puts a filter box above the select that narrows it as you
   type — matching the park, its kingdom, or the kingdom alone. Enter in the
   box picks the first match; Escape clears it. The option already chosen is
   always kept in the list, so filtering never changes the value by itself.

   Usage: SvScopePicker.attach(selectEl, scopes[, { threshold: 12 }])
     scopes: SurveyAjax/scopes rows — {scope_type, scope_id, name, kingdom_id?, kingdom?}
   The select keeps its id, classes and data- attributes; only its options are
   rebuilt, and a change made through the box fires a bubbling 'change' on it.
   Safe to call again on a fresh select after a re-render. */
(function (w, d) {
    'use strict';

    var DEFAULT_THRESHOLD = 12;

    function fold(s) {
        s = String(s || '').toLowerCase();
        try { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) { /* no normalize: match as typed */ }
        return s;
    }

    /** scopes -> [{label, items:[{value, text, hay}]}] in display order, with
        the ORK row (when present) as a group of its own at the top. */
    function group(scopes) {
        var groups = [], byKingdom = {}, loose = null, i, sc, key, g;
        for (i = 0; i < scopes.length; i++) {
            sc = scopes[i];
            if (sc.scope_type === 'ork') {
                groups.push({ label: '', items: [{ value: 'ork:0', text: sc.name || 'All of Amtgard', hay: fold(sc.name) + ' ork amtgard' }] });
                break;
            }
        }
        for (i = 0; i < scopes.length; i++) {
            sc = scopes[i];
            if (sc.scope_type !== 'kingdom') { continue; }
            key = String(parseInt(sc.scope_id, 10) || 0);
            g = { label: sc.name, items: [{ value: 'kingdom:' + key, text: 'All of ' + sc.name, hay: fold(sc.name) + ' kingdom' }] };
            byKingdom[key] = g;
            groups.push(g);
        }
        for (i = 0; i < scopes.length; i++) {
            sc = scopes[i];
            if (sc.scope_type !== 'park') { continue; }
            key = String(parseInt(sc.kingdom_id, 10) || 0);
            g = byKingdom[key];
            if (!g) {
                // A park held directly, whose kingdom is not in the list.
                if (sc.kingdom) {
                    g = byKingdom[key] = { label: sc.kingdom, items: [] };
                    groups.push(g);
                } else {
                    g = loose = loose || { label: 'Parks', items: [] };
                    if (groups.indexOf(g) < 0) { groups.push(g); }
                }
            }
            g.items.push({ value: 'park:' + (parseInt(sc.scope_id, 10) || 0), text: sc.name,
                           hay: fold(sc.name) + ' ' + fold(g.label) + ' park' });
        }
        return groups;
    }

    function attach(select, scopes, opts) {
        if (!select || !scopes || !scopes.length) { return null; }
        opts = opts || {};
        var threshold = opts.threshold || DEFAULT_THRESHOLD;
        var groups    = group(scopes);
        var current   = { value: select.value, text: select.selectedIndex >= 0 ? select.options[select.selectedIndex].text : '' };
        var filter    = null;

        function known(value) {
            var i, j;
            for (i = 0; i < groups.length; i++) {
                for (j = 0; j < groups[i].items.length; j++) {
                    if (groups[i].items[j].value === value) { return true; }
                }
            }
            return false;
        }

        function build(term) {
            var q = fold(term).trim(), keep = select.value, shown = 0, any = false, i, j, g, it, og, o, frag;
            frag = d.createDocumentFragment();
            // The chosen scope may be one this editor could not pick today
            // (an admin moved the survey here): keep it so the select is never blank.
            if (keep && !known(keep)) {
                o = d.createElement('option'); o.value = keep; o.textContent = current.text || keep; o.selected = true;
                frag.appendChild(o); any = true;
            }
            for (i = 0; i < groups.length; i++) {
                g = groups[i];
                og = g.label ? d.createElement('optgroup') : frag;
                if (g.label) { og.label = g.label; }
                for (j = 0; j < g.items.length; j++) {
                    it = g.items[j];
                    if (q && it.hay.indexOf(q) < 0 && it.value !== keep) { continue; }
                    o = d.createElement('option'); o.value = it.value; o.textContent = it.text;
                    if (it.value === keep) { o.selected = true; }
                    og.appendChild(o);
                    shown++;
                }
                if (g.label && og.childNodes.length) { frag.appendChild(og); any = true; }
            }
            if (q && shown <= (keep ? 1 : 0)) {
                o = d.createElement('option'); o.disabled = true; o.textContent = 'No kingdom or park matches “' + term.trim() + '”';
                frag.insertBefore(o, frag.firstChild);
            }
            select.innerHTML = '';
            select.appendChild(frag);
            if (keep) { select.value = keep; }
        }

        function firstMatch() {
            var i, o;
            for (i = 0; i < select.options.length; i++) {
                o = select.options[i];
                if (!o.disabled && o.value !== select.value) { return o; }
            }
            return null;
        }

        build('');

        if (scopes.length >= threshold) {
            filter = d.createElement('input');
            filter.type = 'search';
            filter.className = 'sv-input sv-scopepick-filter';
            filter.placeholder = 'Filter kingdoms and parks…';
            filter.autocomplete = 'off';
            filter.setAttribute('aria-label', 'Filter the scope list');
            if (select.id) { filter.setAttribute('aria-controls', select.id); }
            select.parentNode.insertBefore(filter, select);
            filter.addEventListener('input', function () { build(filter.value); }, false);
            filter.addEventListener('keydown', function (e) {
                var o;
                if (e.key === 'Escape' && filter.value) {
                    e.preventDefault(); e.stopPropagation();
                    filter.value = ''; build('');
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    o = firstMatch();
                    if (!o) { return; }
                    select.value = o.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    select.focus();
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    select.focus();
                }
            }, false);
        }

        return {
            select: select,
            filter: filter,
            reset: function () { if (filter) { filter.value = ''; } build(''); }
        };
    }

    w.SvScopePicker = { attach: attach, group: group };
})(window, document);
