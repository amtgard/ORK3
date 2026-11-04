/* Officer History: the office-ledger edit mode (Reports_officer_history.tpl, &edit=1).
 *
 * Loaded only in edit mode, after revised.js (orkConfirm/orkAlert, the kn-ac
 * dropdown styles, AUTOCOMPLETE_DEBOUNCE_MS). Everything is event-delegated on
 * #or-root, so a section re-rendered from the rolls ajax needs no re-wiring.
 *
 * Writes go to OfficerAdminAjax (addhistory / edithistory / deletehistory), which
 * answers {status:0} or {status:N, error}. After every write the section is
 * re-fetched (rolls) and re-rendered by renderSection(), which mirrors the
 * template's markup: change one, change the other.
 *
 * Server quirks this file works around:
 *  - unlinking a holder, or naming a plain-text holder on a linked row, needs
 *    MundaneId=0 alongside HolderName (HolderName alone is a no-op on a linked row);
 *  - an Edit that sends Period clears any exact date not sent with it, so a period
 *    edit always resends the exact dates shown in the row editor.
 */
(function () {
    'use strict';

    var C = window.OrConfig;
    var root = document.getElementById('or-root');
    if (!C || !root) { return; }

    var DEBOUNCE = (typeof AUTOCOMPLETE_DEBOUNCE_MS === 'number') ? AUTOCOMPLETE_DEBOUNCE_MS : 250;
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var readCache = {};   // (current ? 'c|' : 'p|') + period text -> parseperiod response
    var editingRow = null;

    // ── Transport ────────────────────────────────────────────

    function post(action, fields) {
        var fd = new FormData();
        fd.append('ParkId', String(C.parkId || 0));
        Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch(C.uir + 'OfficerAdminAjax/officer/' + C.kingdomId + '/' + action, {
            method: 'POST', body: fd, credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d && Number(d.status) === 5) {
                throw new Error('You are signed out, or no longer allowed to edit these rolls. Reload the page to continue.');
            }
            return d || { status: 1, error: 'No response.' };
        });
    }

    function alertUser(msg) {
        if (typeof window.orkAlert === 'function') { window.orkAlert(msg, { title: 'Officer History' }); }
    }

    function failed(err) {
        alertUser((err && err.message) ? err.message : 'The request failed. Check your connection and try again.');
    }

    // ── DOM building (textContent only; never innerHTML with data) ──

    function h(tag, attrs, kids) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) { return; }
            if (k === 'class') { e.className = v; } else if (k === 'text') { e.textContent = v; } else { e.setAttribute(k, v === true ? '' : String(v)); }
        });
        (kids || []).forEach(function (c) {
            if (c === null || c === undefined || c === '') { return; }
            e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return e;
    }

    function icon(name) { return h('i', { 'class': 'fas fa-' + name, 'aria-hidden': 'true' }); }

    function plural(n, one, many) { return (n | 0) + ' ' + ((n | 0) === 1 ? one : many); }

    function fmtDate(d) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || '');
        if (!m || m[1] === '0000') { return ''; }
        return MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
    }

    function holderName(t) { return t.MundaneId > 0 ? (t.Persona || '') : String(t.HolderName || '').trim(); }

    function holderNode(t) {
        if (t.MundaneId > 0) {
            return h('a', { 'class': 'or-holder-link', href: C.uir + 'Player/profile/' + t.MundaneId, text: t.Persona || ('Player #' + t.MundaneId) });
        }
        var name = String(t.HolderName || '').trim();
        return name ? h('span', { 'class': 'or-holder-text', text: name }) : h('span', { 'class': 'or-holder-unknown', text: 'Unknown' });
    }

    function periodNodes(t) {
        var label = String(t.PeriodLabel || '');
        if (!t.IsCurrent) { return [label]; }
        return [label.replace(/\s*–\s*present$/, ''), ' ', h('span', { 'class': 'or-dash', text: '–' }), ' ', h('span', { 'class': 'or-present', text: 'present' })];
    }

    function exactText(t) {
        var s = fmtDate(t.StartDate), e = fmtDate(t.EndDate);
        if (!s && !e) { return ''; }
        return 'Exact: ' + (s || '?') + ' – ' + (e || (t.IsCurrent ? 'present' : '?'));
    }

    function seasonText(s, y) {
        return (s && y !== null && y !== undefined && y !== '') ? s.charAt(0).toUpperCase() + s.slice(1) + ' ' + parseInt(y, 10) : '';
    }

    // The period as the row editor shows it, e.g. "Spring 2023 – Fall 2023".
    function periodText(t) {
        var start = seasonText(t.StartSeason, t.StartYear), end = seasonText(t.EndSeason, t.EndYear);
        if (t.IsCurrent || !start) { return start; }
        if (!end) { return start + ' –'; }
        return end === start ? start : start + ' – ' + end;
    }

    function holderField(value, id, placeholder) {
        var input = h('input', {
            type: 'text', 'class': 'or-input or-holder-input', autocomplete: 'off', spellcheck: 'false', maxlength: '120',
            placeholder: placeholder || 'Holder', 'aria-label': 'Holder: search players or type a name'
        });
        input.value = value || '';
        var hidden = h('input', { type: 'hidden', 'class': 'or-holder-id' });
        hidden.value = id ? String(id) : '';
        var hint = h('div', { 'class': 'or-hint', text: 'plain text: not linked to a player' });
        hint.hidden = !(input.value && !hidden.value);
        return h('div', { 'class': 'or-ac-wrap' }, [input, hidden, h('div', { 'class': 'kn-ac-results or-ac-results', role: 'listbox' }), hint]);
    }

    function summaryNode(o) {
        var meta = h('span', { 'class': 'or-office-meta' }, [h('span', { 'class': 'or-count', text: plural(o.TermCount, 'term', 'terms') })]);
        if (o.GapCount > 0) { meta.appendChild(h('span', { 'class': 'or-tag or-tag-gap', text: plural(o.GapCount, 'gap', 'gaps') })); }
        if (o.OverlapCount > 0) { meta.appendChild(h('span', { 'class': 'or-tag or-tag-overlap', text: plural(o.OverlapCount, 'overlap', 'overlaps') })); }
        return h('summary', { 'class': 'or-office-summary' }, [
            h('i', { 'class': 'fas fa-chevron-right or-chevron', 'aria-hidden': 'true' }),
            h('span', { 'class': 'or-office-title', text: o.Title || 'Office' }),
            o.Retired ? h('span', { 'class': 'or-tag or-tag-retired', text: 'former office' }) : null,
            meta
        ]);
    }

    function termRow(t) {
        var hid = t.OfficerHistoryId | 0, current = !!t.IsCurrent, synthetic = !!t.Synthetic || hid === 0;
        var exact = exactText(t);
        var holderTd = h('td', { 'class': 'or-col-holder', 'data-label': 'Holder' }, [holderNode(t)]);
        if (current) {
            holderTd.appendChild(document.createTextNode(' '));
            holderTd.appendChild(h('span', { 'class': 'or-lock', 'data-tip': 'Set from Manage Officers' }, [icon('lock'), h('span', { 'class': 'or-sr', text: 'Set from Manage Officers' })]));
        }
        var periodTd = h('td', { 'class': 'or-col-period', 'data-label': 'Period' }, periodNodes(t));
        if (exact) { periodTd.appendChild(h('span', { 'class': 'or-exact', text: exact })); }
        var actions = h('td', { 'class': 'or-col-actions' });
        if (synthetic) {
            actions.appendChild(h('span', { 'class': 'or-tag or-tag-quiet', 'data-tip': 'Seated in Manage Officers, with no history row yet. Set from Manage Officers.', text: 'seated' }));
        } else {
            actions.appendChild(h('button', { type: 'button', 'class': 'or-icon-btn', 'data-or-act': 'edit', 'aria-label': 'Edit',
                'data-tip': current ? 'Edit the start and note. The holder and end are set from Manage Officers.' : 'Edit' }, [icon('pen')]));
            if (!current && !(t.MundaneId > 0)) {
                actions.appendChild(h('button', { type: 'button', 'class': 'or-icon-btn', 'data-or-act': 'link', 'aria-label': 'Link to player', 'data-tip': 'Link to player' }, [icon('link')]));
            }
            if (!current) {
                actions.appendChild(h('button', { type: 'button', 'class': 'or-icon-btn or-icon-danger', 'data-or-act': 'delete', 'aria-label': 'Delete', 'data-tip': 'Delete' }, [icon('trash-alt')]));
            }
        }
        return h('tr', {
            'class': 'or-row' + (current ? ' or-row-current' : '') + (synthetic ? ' or-row-synthetic' : ''),
            'data-history-id': hid, 'data-holder': holderName(t), 'data-mundane-id': t.MundaneId | 0,
            'data-period': periodText(t), 'data-note': t.Note || '', 'data-start-date': t.StartDate || '',
            'data-end-date': t.EndDate || '', 'data-current': current ? '1' : '0'
        }, [holderTd, periodTd, h('td', { 'class': 'or-col-note', 'data-label': 'Note', text: t.Note || '' }), actions]);
    }

    function entryRow(positionId) {
        return h('tr', { 'class': 'or-entry', 'data-or-entry': true, 'data-position-id': positionId }, [
            h('td', { 'class': 'or-col-holder', 'data-label': 'Holder' }, [holderField('', '', 'Holder')]),
            h('td', { 'class': 'or-col-period', 'data-label': 'Period' }, [
                h('input', { type: 'text', 'class': 'or-input or-period-input', autocomplete: 'off', spellcheck: 'false',
                    placeholder: 'Period, e.g. Spring–Fall 23', 'aria-label': 'Period' }),
                h('div', { 'class': 'or-readback', 'aria-live': 'polite' })
            ]),
            h('td', { 'class': 'or-col-note', 'data-label': 'Note' }, [
                h('input', { type: 'text', 'class': 'or-input or-note-input', maxlength: '500', placeholder: 'Note', 'aria-label': 'Note' })
            ]),
            h('td', { 'class': 'or-col-actions' }, [
                h('button', { type: 'button', 'class': 'or-btn-add', 'data-or-act': 'add', 'data-tip': 'Add this term (Enter)' }, [icon('plus'), ' Add'])
            ])
        ]);
    }

    function renderSection(o, open) {
        var tbody = h('tbody');
        (o.Terms || []).forEach(function (t) {
            tbody.appendChild(termRow(t));
            var f = t.FlagAfter;
            if (f && f.Type) {
                var gap = f.Type === 'gap';
                tbody.appendChild(h('tr', { 'class': 'or-flag-row or-flag-' + (gap ? 'gap' : 'overlap') }, [
                    h('td', { colspan: '4' }, [icon(gap ? 'ellipsis-v' : 'exclamation-triangle'), ' ' + (f.Label || '')])
                ]));
            }
        });
        if (o.PositionId > 0) {
            tbody.appendChild(entryRow(o.PositionId));
        } else {
            tbody.appendChild(h('tr', { 'class': 'or-entry-none' }, [h('td', { colspan: '4', text: 'This office is no longer in the registry, so new terms are not added here.' })]));
        }
        var thead = h('thead', {}, [h('tr', {}, [
            h('th', { 'class': 'or-col-holder', text: 'Holder' }), h('th', { 'class': 'or-col-period', text: 'Period' }),
            h('th', { 'class': 'or-col-note', text: 'Note' }), h('th', { 'class': 'or-col-actions' }, [h('span', { 'class': 'or-sr', text: 'Actions' })])
        ])]);
        return h('details', { 'class': 'or-office', 'data-key': o.Key, 'data-position-id': o.PositionId | 0, open: !!open }, [
            summaryNode(o),
            h('div', { 'class': 'or-office-body' }, [h('div', { 'class': 'or-ledger-wrap' }, [h('table', { 'class': 'or-ledger' }, [thead, tbody])])])
        ]);
    }

    // ── Section refresh ──────────────────────────────────────

    function sectionOf(el) { return el.closest('details.or-office'); }

    function findSection(key) {
        var all = root.querySelectorAll('details.or-office');
        for (var i = 0; i < all.length; i++) { if (all[i].getAttribute('data-key') === key) { return all[i]; } }
        return null;
    }

    // Re-fetch the rolls and re-render one section in place. after: {focusEntry, prefill}
    function refreshSection(key, after) {
        after = after || {};
        return post('rolls', {}).then(function (d) {
            if (Number(d.status) !== 0) { throw new Error(d.error || 'The rolls could not be reloaded. Reload the page.'); }
            var office = null;
            (d.Offices || []).forEach(function (o) { if (o.Key === key) { office = o; } });
            var old = findSection(key);
            if (!office || !old) { return; }
            if (editingRow && old.contains(editingRow)) { editingRow = null; }
            var fresh = renderSection(office, true);
            old.parentNode.replaceChild(fresh, old);
            if (after.focusEntry) {
                var entry = fresh.querySelector('tr.or-entry');
                if (entry) {
                    var period = entry.querySelector('.or-period-input');
                    if (after.prefill && period) {
                        period.value = after.prefill;
                        readBack(period);
                    }
                    var holder = entry.querySelector('.or-holder-input');
                    if (holder) { holder.focus(); }
                }
            }
        });
    }

    // ── Messages ─────────────────────────────────────────────

    function rowMessage(tr, text, ok) {
        var box = tr.querySelector('.or-readback');
        if (!box) {
            box = tr.querySelector('.or-hint');
            if (box) { box.hidden = false; }
        }
        if (!box) { alertUser(text); return; }
        box.textContent = text;
        box.classList.toggle('or-err', !ok);
        box.classList.toggle('or-ok', !!ok);
    }

    // ── Period read-back ─────────────────────────────────────

    function isCurrentRow(tr) { return !!tr && tr.getAttribute('data-current') === '1'; }

    function readBack(input) {
        var tr = input.closest('tr');
        var box = tr ? tr.querySelector('.or-readback') : null;
        var text = input.value.trim();
        var current = isCurrentRow(tr);
        clearTimeout(input._orTimer);
        if (!box) { return; }
        if (!text) { box.textContent = ''; box.className = 'or-readback'; return; }
        var cacheKey = (current ? 'c|' : 'p|') + text;
        var show = function (d) {
            if (input.value.trim() !== text) { return; }
            box.className = 'or-readback ' + (Number(d.status) === 0 ? 'or-ok' : 'or-err');
            box.textContent = Number(d.status) === 0 ? d.Label : (d.error || 'Could not read that period.');
        };
        if (readCache[cacheKey]) { show(readCache[cacheKey]); return; }
        input._orTimer = setTimeout(function () {
            post('parseperiod', { Period: text, Current: current ? '1' : '' }).then(function (d) {
                readCache[cacheKey] = d;
                show(d);
            }).catch(function () { /* the save reports any real problem */ });
        }, DEBOUNCE);
    }

    // The span to pre-fill after saving `text`: the term before, of the same length.
    function previousSpan(text) {
        var key = 'p|' + text;
        if (readCache[key]) { return Promise.resolve(readCache[key].PreviousSpan || ''); }
        return post('parseperiod', { Period: text }).then(function (d) {
            readCache[key] = d;
            return Number(d.status) === 0 ? (d.PreviousSpan || '') : '';
        }).catch(function () { return ''; });
    }

    // ── Holder search (global: officers often served elsewhere first) ──

    var searchSeq = 0;

    function acResults(wrap) { return wrap.querySelector('.kn-ac-results'); }

    function acClose(wrap) {
        var r = acResults(wrap);
        if (r) { r.classList.remove('kn-ac-open'); r.textContent = ''; }
    }

    function acCloseAll(except) {
        root.querySelectorAll('.or-ac-wrap').forEach(function (w) { if (w !== except) { acClose(w); } });
    }

    function acOpen(wrap) {
        var r = acResults(wrap);
        return !!r && r.classList.contains('kn-ac-open');
    }

    function holderHint(wrap) {
        var input = wrap.querySelector('.or-holder-input'), id = wrap.querySelector('.or-holder-id'), hint = wrap.querySelector('.or-hint');
        if (!hint) { return; }
        hint.className = 'or-hint';
        var tr = wrap.closest('tr');
        if (tr && tr._orMode === 'link') {
            hint.textContent = 'Pick the player this name belongs to.';
            hint.hidden = false;
            return;
        }
        hint.textContent = 'plain text: not linked to a player';
        hint.hidden = !(input.value.trim() && !id.value);
    }

    function holderInput(input) {
        var wrap = input.closest('.or-ac-wrap');
        wrap.querySelector('.or-holder-id').value = '';
        holderHint(wrap);
        clearTimeout(input._orTimer);
        var q = input.value.trim();
        if (q.length < 2) { acClose(wrap); return; }
        input._orTimer = setTimeout(function () {
            var seq = ++searchSeq;
            fetch(C.uir + 'SearchAjax/universal&focus=player&inactive=1&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (seq !== searchSeq || document.activeElement !== input || input.value.trim() !== q) { return; }
                    acRender(wrap, (d && d.players) || []);
                })
                .catch(function () { acClose(wrap); });
        }, DEBOUNCE);
    }

    function acRender(wrap, players) {
        var r = acResults(wrap);
        r.textContent = '';
        if (!players.length) {
            r.appendChild(h('div', { 'class': 'kn-ac-empty or-ac-empty', text: 'No players match. It will be saved as plain text.' }));
        }
        players.slice(0, 12).forEach(function (p) {
            var meta = [p.abbr, p.park].filter(Boolean).join(' · ');
            r.appendChild(h('div', { 'class': 'kn-ac-item', role: 'option', 'data-id': p.id, 'data-name': p.name }, [
                p.name,
                meta ? h('span', { 'class': 'or-ac-meta', text: ' (' + meta + ')' }) : null,
                Number(p.active) === 0 ? h('span', { 'class': 'or-ac-inactive', text: ' inactive' }) : null
            ]));
        });
        acCloseAll(wrap);
        r.classList.add('kn-ac-open');
    }

    function acMove(wrap, dir) {
        var items = Array.prototype.slice.call(wrap.querySelectorAll('.kn-ac-item'));
        if (!items.length) { return; }
        var i = items.findIndex(function (it) { return it.classList.contains('kn-ac-focused'); });
        items.forEach(function (it) { it.classList.remove('kn-ac-focused'); });
        i = i < 0 ? (dir > 0 ? 0 : items.length - 1) : (i + dir + items.length) % items.length;
        items[i].classList.add('kn-ac-focused');
        items[i].scrollIntoView({ block: 'nearest' });
    }

    function acPick(wrap, item) {
        var input = wrap.querySelector('.or-holder-input');
        input.value = item.getAttribute('data-name') || '';
        wrap.querySelector('.or-holder-id').value = item.getAttribute('data-id') || '';
        acClose(wrap);
        holderHint(wrap);
        var tr = wrap.closest('tr');
        if (tr && tr._orMode === 'link') { saveLink(tr); return; }
        // Move on to the period, as Tab would.
        var next = tr ? tr.querySelector('.or-period-input:not([readonly])') : null;
        if (next) { next.focus(); } else { input.focus(); }
    }

    // ── Add (entry row) ──────────────────────────────────────

    function addTerm(tr) {
        if (tr._orBusy) { return; }
        var section = sectionOf(tr);
        var wrap = tr.querySelector('.or-ac-wrap');
        var holder = wrap.querySelector('.or-holder-input'), hid = wrap.querySelector('.or-holder-id');
        var period = tr.querySelector('.or-period-input'), note = tr.querySelector('.or-note-input');
        var name = holder.value.trim(), text = period.value.trim();
        if (!name) { rowMessage(tr, 'Enter a holder: pick a player or type a name.'); holder.focus(); return; }
        if (!text) { rowMessage(tr, 'Enter a period, e.g. "Spring–Fall 23".'); period.focus(); return; }
        var id = parseInt(hid.value, 10) || 0;
        var btn = tr.querySelector('[data-or-act="add"]');
        tr._orBusy = true;
        if (btn) { btn.disabled = true; }
        post('addhistory', {
            PositionId: tr.getAttribute('data-position-id'),
            MundaneId: id,
            HolderName: id ? '' : name,
            Period: text,
            Note: note.value.trim()
        }).then(function (d) {
            if (Number(d.status) !== 0) {
                rowMessage(tr, d.error || 'The term could not be added.');
                period.focus();
                return null;
            }
            return previousSpan(text).then(function (span) {
                return refreshSection(section.getAttribute('data-key'), { focusEntry: true, prefill: span });
            });
        }).catch(failed).then(function () {
            tr._orBusy = false;
            if (btn) { btn.disabled = false; }
        });
    }

    // ── Edit in place ────────────────────────────────────────

    function dateInput(value, placeholder) {
        var input = h('input', { type: 'text', 'class': 'or-input or-date-input', placeholder: placeholder, autocomplete: 'off', 'aria-label': placeholder });
        input.value = value || '';
        return input;
    }

    function initDate(input) {
        if (typeof window.flatpickr === 'function') {
            window.flatpickr(input, {
                altInput: true, altFormat: 'M j, Y', dateFormat: 'Y-m-d', allowInput: true,
                altInputClass: 'or-input or-date-input-alt', maxDate: 'today'
            });
        } else {
            input.type = 'date';
        }
    }

    function cancelEdit(tr) {
        if (!tr || !tr._orOrig) { return; }
        tr.querySelectorAll('.or-date-input').forEach(function (i) { if (i._flatpickr) { i._flatpickr.destroy(); } });
        tr.innerHTML = tr._orOrig;   // the server- or renderSection-built markup this row had before editing
        tr._orOrig = null;
        tr._orMode = null;
        tr.classList.remove('or-row-editing');
        if (editingRow === tr) { editingRow = null; }
    }

    function beginEditing(tr, mode) {
        if (editingRow && editingRow !== tr) { cancelEdit(editingRow); }
        if (tr._orOrig) { return false; }
        tr._orOrig = tr.innerHTML;
        tr._orMode = mode;
        tr.classList.add('or-row-editing');
        editingRow = tr;
        return true;
    }

    function editActions(tr, withSave) {
        var cell = tr.querySelector('td.or-col-actions');
        cell.textContent = '';
        if (withSave) {
            cell.appendChild(h('button', { type: 'button', 'class': 'or-btn-save', 'data-or-act': 'save', 'data-tip': 'Save (Enter)' }, [icon('check'), ' Save']));
            cell.appendChild(document.createElement('br'));
        }
        cell.appendChild(h('button', { type: 'button', 'class': 'or-btn-cancel', 'data-or-act': 'cancel', 'data-tip': 'Cancel (Esc)' }, ['Cancel']));
    }

    function startEdit(tr, focusCell) {
        if (!beginEditing(tr, 'edit')) { return; }
        var current = isCurrentRow(tr);
        var d = tr.dataset;
        var holderTd = tr.querySelector('td.or-col-holder'), periodTd = tr.querySelector('td.or-col-period'), noteTd = tr.querySelector('td.or-col-note');

        if (!current) {
            holderTd.textContent = '';
            holderTd.appendChild(holderField(d.holder, parseInt(d.mundaneId, 10) > 0 ? d.mundaneId : '', 'Holder'));
        }

        periodTd.textContent = '';
        var period = h('input', { type: 'text', 'class': 'or-input or-period-input', autocomplete: 'off', spellcheck: 'false',
            placeholder: current ? 'Start, e.g. Spring 24' : 'Period, e.g. Spring–Fall 23', 'aria-label': current ? 'Start' : 'Period' });
        period.value = d.period || '';
        var start = dateInput(d.startDate, current ? 'Exact start (optional)' : 'Exact start');
        var pair = h('div', { 'class': 'or-date-pair' }, [start]);
        if (!current) { pair.appendChild(dateInput(d.endDate, 'Exact end')); }
        periodTd.appendChild(period);
        periodTd.appendChild(h('div', { 'class': 'or-readback', 'aria-live': 'polite' }));
        periodTd.appendChild(pair);
        pair.querySelectorAll('.or-date-input').forEach(initDate);

        noteTd.textContent = '';
        var note = h('input', { type: 'text', 'class': 'or-input or-note-input', maxlength: '500', placeholder: 'Note', 'aria-label': 'Note' });
        note.value = d.note || '';
        noteTd.appendChild(note);

        editActions(tr, true);
        if (period.value) { readBack(period); }

        var target = (focusCell === 'note') ? note
            : (focusCell === 'period' || current) ? period
            : (tr.querySelector('.or-holder-input') || period);
        target.focus();
        if (typeof target.select === 'function') { target.select(); }
    }

    function saveEdit(tr) {
        if (tr._orBusy) { return; }
        var d = tr.dataset, current = isCurrentRow(tr);
        var fields = { OfficerHistoryId: d.historyId };
        var changed = false;

        var wrap = tr.querySelector('.or-ac-wrap');
        if (!current && wrap) {
            var name = wrap.querySelector('.or-holder-input').value.trim();
            var id = parseInt(wrap.querySelector('.or-holder-id').value, 10) || 0;
            var origId = parseInt(d.mundaneId, 10) || 0;
            if (id > 0 && id !== origId) {
                fields.MundaneId = id;
                changed = true;
            } else if (id === 0 && (origId > 0 || name !== (d.holder || ''))) {
                if (!name) { rowMessage(tr, 'Enter a holder: pick a player or type a name.'); return; }
                // MundaneId=0 is what unlinks; HolderName alone is ignored on a linked row.
                fields.MundaneId = 0;
                fields.HolderName = name;
                changed = true;
            }
        }

        var periodInput = tr.querySelector('.or-period-input');
        var text = periodInput.value.trim();
        var dates = tr.querySelectorAll('.or-date-input');
        var startDate = dates[0] ? dates[0].value.trim() : '';
        var endDate = dates[1] ? dates[1].value.trim() : '';
        if (text !== (d.period || '').trim()) {
            if (!text && !startDate) { rowMessage(tr, 'A term needs a start: enter a period or an exact start date.'); periodInput.focus(); return; }
            fields.Period = text;
            // A sent Period clears any exact date not sent with it: resend the ones shown.
            fields.StartDate = startDate;
            if (!current) { fields.EndDate = endDate; }
            changed = true;
        } else {
            if (startDate !== (d.startDate || '')) { fields.StartDate = startDate; changed = true; }
            if (!current && endDate !== (d.endDate || '')) { fields.EndDate = endDate; changed = true; }
        }

        var noteInput = tr.querySelector('.or-note-input');
        if (noteInput && noteInput.value.trim() !== (d.note || '').trim()) {
            fields.Note = noteInput.value.trim();
            changed = true;
        }

        if (!changed) { cancelEdit(tr); return; }
        var section = sectionOf(tr);
        tr._orBusy = true;
        post('edithistory', fields).then(function (r) {
            if (Number(r.status) !== 0) {
                rowMessage(tr, r.error || 'The term could not be saved.');
                return null;
            }
            return refreshSection(section.getAttribute('data-key'));
        }).catch(failed).then(function () { tr._orBusy = false; });
    }

    // ── Link a plain-text holder to a player ─────────────────

    function startLink(tr) {
        if (!beginEditing(tr, 'link')) { return; }
        var holderTd = tr.querySelector('td.or-col-holder');
        holderTd.textContent = '';
        var field = holderField(tr.dataset.holder, '', 'Search players to link');
        holderTd.appendChild(field);
        editActions(tr, false);
        var input = field.querySelector('.or-holder-input');
        input.focus();
        input.select();
        holderInput(input);   // search the typed name straight away; holderHint shows the link prompt
    }

    function saveLink(tr) {
        if (tr._orBusy) { return; }
        var id = parseInt(tr.querySelector('.or-holder-id').value, 10) || 0;
        if (!id) { rowMessage(tr, 'Pick a player from the list to link this name.'); return; }
        var section = sectionOf(tr);
        tr._orBusy = true;
        post('edithistory', { OfficerHistoryId: tr.dataset.historyId, MundaneId: id }).then(function (r) {
            if (Number(r.status) !== 0) { rowMessage(tr, r.error || 'The holder could not be linked.'); return null; }
            return refreshSection(section.getAttribute('data-key'));
        }).catch(failed).then(function () { tr._orBusy = false; });
    }

    // ── Delete ───────────────────────────────────────────────

    function deleteTerm(tr) {
        var who = tr.dataset.holder || 'an unknown holder';
        var period = (tr.dataset.period || '').trim();
        var msg = 'Delete the term of ' + who + (period ? ' (' + period + ')' : '') + '? This cannot be undone.';
        var run = function () {
            var section = sectionOf(tr);
            post('deletehistory', { OfficerHistoryId: tr.dataset.historyId }).then(function (r) {
                if (Number(r.status) !== 0) { alertUser(r.error || 'The term could not be deleted.'); return null; }
                return refreshSection(section.getAttribute('data-key'));
            }).catch(failed);
        };
        if (typeof window.orkConfirm === 'function') {
            window.orkConfirm(msg, run, { title: 'Delete term', okLabel: 'Delete' });
        }
    }

    // ── Event delegation ─────────────────────────────────────

    root.addEventListener('input', function (e) {
        var t = e.target;
        if (t.classList.contains('or-holder-input')) { holderInput(t); }
        else if (t.classList.contains('or-period-input')) { readBack(t); }
    });

    root.addEventListener('mousedown', function (e) {
        // Keep focus in the holder input while a dropdown item is chosen.
        if (e.target.closest('.kn-ac-item')) { e.preventDefault(); }
    });

    root.addEventListener('click', function (e) {
        var item = e.target.closest('.kn-ac-item');
        if (item) { acPick(item.closest('.or-ac-wrap'), item); return; }

        var btn = e.target.closest('[data-or-act]');
        var tr = e.target.closest('tr');
        if (btn && tr) {
            var act = btn.getAttribute('data-or-act');
            if (act === 'add') { addTerm(tr); }
            else if (act === 'edit') { startEdit(tr); }
            else if (act === 'link') { startLink(tr); }
            else if (act === 'delete') { deleteTerm(tr); }
            else if (act === 'save') { saveEdit(tr); }
            else if (act === 'cancel') { cancelEdit(tr); }
            return;
        }
        // Click a cell of a past or current row to edit it in place.
        if (tr && tr.classList.contains('or-row') && !tr.classList.contains('or-row-synthetic')
            && !tr._orOrig && !e.target.closest('a, button, input, .or-lock')) {
            var td = e.target.closest('td');
            if (td && !td.classList.contains('or-col-actions')) {
                startEdit(tr, td.classList.contains('or-col-note') ? 'note' : td.classList.contains('or-col-period') ? 'period' : 'holder');
            }
        }
    });

    root.addEventListener('keydown', function (e) {
        var t = e.target;
        var tr = t.closest ? t.closest('tr') : null;
        var wrap = t.classList && t.classList.contains('or-holder-input') ? t.closest('.or-ac-wrap') : null;

        if (wrap && acOpen(wrap)) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                acMove(wrap, e.key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (e.key === 'Enter') {
                var focused = wrap.querySelector('.kn-ac-item.kn-ac-focused');
                if (focused) { e.preventDefault(); acPick(wrap, focused); return; }
                acClose(wrap);
            }
            if (e.key === 'Escape') { e.preventDefault(); acClose(wrap); return; }
        }
        if (!tr) { return; }
        if (e.key === 'Enter' && t.tagName === 'INPUT') {
            if (tr.classList.contains('or-entry')) { e.preventDefault(); addTerm(tr); }
            else if (tr._orMode === 'link') { e.preventDefault(); saveLink(tr); }
            else if (tr._orMode === 'edit') { e.preventDefault(); saveEdit(tr); }
        } else if (e.key === 'Escape' && tr._orOrig) {
            e.preventDefault();
            cancelEdit(tr);
        }
    });

    root.addEventListener('focusout', function (e) {
        var wrap = e.target.classList && e.target.classList.contains('or-holder-input') ? e.target.closest('.or-ac-wrap') : null;
        if (wrap) { setTimeout(function () { if (!wrap.contains(document.activeElement)) { acClose(wrap); } }, 120); }
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest || !e.target.closest('.or-ac-wrap')) { acCloseAll(null); }
    });
})();
