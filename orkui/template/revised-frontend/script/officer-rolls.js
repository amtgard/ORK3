/* Officer History: the office-ledger edit mode (Reports_officer_history.tpl, &edit=1).
 *
 * Loaded only in edit mode, after revised.js (orkConfirm/orkAlert, the kn-ac
 * dropdown styles, AUTOCOMPLETE_DEBOUNCE_MS). Everything is event-delegated on
 * #or-root, so a section re-rendered from the rolls ajax needs no re-wiring.
 *
 * Writes go to OfficerAdminAjax (addhistory / edithistory / deletehistory) with
 * the section's Key, and answer {status:0, Office} (add also PreviousSpan) or
 * {status:N, error}. The one office in the answer is re-rendered in place by
 * renderSection(), which mirrors the template's markup: change one, change the
 * other. Only if a write's answer carries no Office is the section re-fetched
 * (rolls, scoped to its Key). Nothing refetches the whole org.
 *
 * Server quirks this file works around:
 *  - unlinking a holder, or naming a plain-text holder on a linked row, needs
 *    MundaneId=0 alongside HolderName (HolderName alone on a linked row would
 *    only rename the name of record, which linking keeps);
 *  - an Edit that sends Period clears any exact date not sent with it, so a period
 *    edit always resends the exact dates shown in the row editor.
 */
(function () {
    'use strict';

    var C = window.OrConfig;
    var root = document.getElementById('or-root');
    if (!C || !root) { return; }

    var DEBOUNCE = (typeof AUTOCOMPLETE_DEBOUNCE_MS === 'number') ? AUTOCOMPLETE_DEBOUNCE_MS : 250;
    var MODE_TIPS = {
        season: 'Season Entry - use to indicate the general term if you do not know the specific dates.',
        calendar: 'Calendar Entry - use to enter in specific start and end dates when known.'
    };
    // Season | Calendar for entry rows: the last mode chosen, kept for this page session.
    var entryMode = 'season';
    try { if (window.sessionStorage.getItem('orEntryMode') === 'calendar') { entryMode = 'calendar'; } } catch (e) { /* storage blocked */ }
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

    // HolderName is the name the term was recorded under (kept when it is linked);
    // a linked holder with none is named by the persona. The template's
    // $orHolderName / $orHolder build the same.
    function holderName(t) {
        var recorded = String(t.HolderName || '').trim();
        return (recorded || !(t.MundaneId > 0)) ? recorded : String(t.Persona || '').trim();
    }

    function holderNode(t) {
        var name = holderName(t);
        if (t.MundaneId > 0) {
            var persona = String(t.Persona || '').trim();
            var link = h('a', { 'class': 'or-holder-link', href: C.uir + 'Player/profile/' + t.MundaneId },
                [name || h('span', { 'class': 'or-holder-unknown', text: 'Unknown' })]);
            if (!persona || persona.toLowerCase() === name.toLowerCase()) { return link; }
            var frag = document.createDocumentFragment();
            frag.appendChild(link);
            frag.appendChild(document.createTextNode(' '));
            frag.appendChild(h('span', { 'class': 'or-holder-aka', text: 'now known as ' + persona }));
            return frag;
        }
        return name ? h('span', { 'class': 'or-holder-text', text: name }) : h('span', { 'class': 'or-holder-unknown', text: 'Unknown' });
    }

    function periodNodes(t) {
        if (!t.IsCurrent) { return [String(t.PeriodLabel || '')]; }
        return [String(t.PeriodLabelBase || ''), ' ', h('span', { 'class': 'or-dash', text: '–' }), ' ', h('span', { 'class': 'or-present', text: 'present' })];
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
        var holderTd = h('td', { 'class': 'or-col-holder', 'data-label': 'Holder' }, [holderNode(t)]);
        if (current) {
            holderTd.appendChild(document.createTextNode(' '));
            holderTd.appendChild(h('span', { 'class': 'or-lock', 'data-tip': 'Set from Manage Officers' }, [icon('lock'), h('span', { 'class': 'or-sr', text: 'Set from Manage Officers' })]));
        }
        var periodTd = h('td', { 'class': 'or-col-period', 'data-label': 'Period' }, periodNodes(t));
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
            'data-period': t.PeriodText || '', 'data-note': t.Note || '', 'data-start-date': t.StartDate || '',
            'data-start-season': (t.StartSeason && t.StartYear !== null && t.StartYear !== undefined) ? '1' : '0',
            'data-end-date': t.EndDate || '', 'data-current': current ? '1' : '0'
        }, [holderTd, periodTd, h('td', { 'class': 'or-col-note', 'data-label': 'Note', text: t.Note || '' }), actions]);
    }

    function dateInput(value, placeholder, side) {
        var input = h('input', { type: 'text', 'class': 'or-input or-date-input', 'data-side': side, placeholder: placeholder, autocomplete: 'off', 'aria-label': placeholder });
        input.value = value || '';
        return input;
    }

    // The period field: a Season | Calendar toggle left of the inputs (the template's
    // $orPeriodField builds the same markup for server-rendered entry rows).
    // opts: {period, startDate, endDate, current}
    function periodField(opts) {
        var current = !!opts.current;
        var toggle = h('div', { 'class': 'or-mode', role: 'group', 'aria-label': 'Entry mode' }, [
            h('button', { type: 'button', 'class': 'or-mode-btn', 'data-or-mode': 'season', 'aria-pressed': 'true', tabindex: '0',
                'aria-label': 'Season entry', 'data-tip': MODE_TIPS.season }, [icon('sun')]),
            h('button', { type: 'button', 'class': 'or-mode-btn', 'data-or-mode': 'calendar', 'aria-pressed': 'false', tabindex: '-1',
                'aria-label': 'Calendar entry', 'data-tip': MODE_TIPS.calendar }, [icon('calendar-alt')])
        ]);
        var period = h('input', { type: 'text', 'class': 'or-input or-period-input', autocomplete: 'off', spellcheck: 'false',
            placeholder: current ? 'Start, e.g. Spring 24' : 'Period, e.g. Spring–Fall 23', 'aria-label': current ? 'Start' : 'Period' });
        period.value = opts.period || '';
        var pair = h('div', { 'class': 'or-date-pair' }, [dateInput(opts.startDate, 'Start date', 'start')]);
        if (!current) { pair.appendChild(dateInput(opts.endDate, 'End date (blank: unknown)', 'end')); }
        var calendar = h('div', { 'class': 'or-pane-calendar' }, [pair]);
        calendar.hidden = true;
        return h('div', { 'class': 'or-period-field', 'data-mode': 'season' }, [
            toggle,
            h('div', { 'class': 'or-period-inputs' }, [
                h('div', { 'class': 'or-pane-season' }, [period]),
                calendar,
                h('div', { 'class': 'or-readback', 'aria-live': 'polite' })
            ])
        ]);
    }

    function initDate(input) {
        if (input._flatpickr || input.type === 'date') { return; }
        if (typeof window.flatpickr === 'function') {
            window.flatpickr(input, {
                altInput: true, altFormat: 'F j, Y', dateFormat: 'Y-m-d', allowInput: true,
                altInputClass: 'or-input or-date-input-alt', maxDate: 'today'
            });
            var alt = input._flatpickr && input._flatpickr.altInput;
            if (alt) { alt.setAttribute('aria-label', input.getAttribute('aria-label') || ''); }
        } else {
            input.type = 'date';
        }
    }

    // Calendar mode uses allowInput: Flatpickr commits typed alt text only on blur
    // or in its own Enter handler, and that may run after this file's Enter handler,
    // so a date typed and then Entered could be read before it is committed. Commit
    // it here, parsed as Flatpickr itself would (the alt format), before any read.
    // Returns an error message when typed text is not a date Flatpickr accepts (it
    // would otherwise be dropped silently and a blank end read as "unknown").
    function commitTypedDates(field) {
        var error = '';
        field.querySelectorAll('.or-date-input').forEach(function (i) {
            var fp = i._flatpickr;
            if (!fp || !fp.altInput) { return; }
            var typed = fp.altInput.value.trim();
            if (!typed) {
                if (i.value) { fp.clear(); }
                return;
            }
            var shown = fp.selectedDates.length ? fp.formatDate(fp.selectedDates[0], fp.config.altFormat) : '';
            if (typed !== shown) {
                fp.setDate(typed, true, fp.config.altFormat);
                if (!fp.selectedDates.length && !error) {
                    error = 'Could not read the date "' + typed + '". Pick it from the calendar; dates cannot be in the future.';
                }
            }
        });
        return error;
    }

    function fieldMode(field) { return field && field.getAttribute('data-mode') === 'calendar' ? 'calendar' : 'season'; }

    // The first input of the field's active pane (Flatpickr's visible alt input in Calendar).
    function activeInput(field) {
        if (fieldMode(field) === 'season') { return field.querySelector('.or-period-input'); }
        var start = field.querySelector('.or-date-input');
        return (start && start._flatpickr && start._flatpickr.altInput) || start;
    }

    function setMode(field, mode) {
        field.setAttribute('data-mode', mode);
        field.querySelectorAll('.or-mode-btn').forEach(function (b) {
            var on = b.getAttribute('data-or-mode') === mode;
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.setAttribute('tabindex', on ? '0' : '-1');
        });
        field.querySelector('.or-pane-season').hidden = mode !== 'season';
        field.querySelector('.or-pane-calendar').hidden = mode !== 'calendar';
        var box = field.querySelector('.or-readback');
        box.textContent = '';
        box.className = 'or-readback';
        if (mode === 'calendar') {
            field.querySelectorAll('.or-date-input').forEach(initDate);
        } else {
            var period = field.querySelector('.or-period-input');
            if (period.value.trim()) { readBack(period); }
        }
    }

    function chooseMode(btn, focusField) {
        var field = btn.closest('.or-period-field');
        var mode = btn.getAttribute('data-or-mode');
        setMode(field, mode);
        var tr = field.closest('tr');
        if (tr && tr.classList.contains('or-entry')) {
            entryMode = mode;
            try { window.sessionStorage.setItem('orEntryMode', mode); } catch (e) { /* storage blocked */ }
        }
        if (focusField) { var t = activeInput(field); if (t) { t.focus(); } }
    }

    function entryRow(positionId) {
        return h('tr', { 'class': 'or-entry', 'data-or-entry': true, 'data-position-id': positionId }, [
            h('td', { 'class': 'or-col-holder', 'data-label': 'Holder' }, [holderField('', '', 'Holder')]),
            h('td', { 'class': 'or-col-period', 'data-label': 'Period' }, [periodField({})]),
            h('td', { 'class': 'or-col-note', 'data-label': 'Note' }, [
                h('input', { type: 'text', 'class': 'or-input or-note-input', maxlength: '500', placeholder: 'Note', 'aria-label': 'Note' })
            ]),
            h('td', { 'class': 'or-col-actions' }, [
                h('button', { type: 'button', 'class': 'or-btn-add', 'data-or-act': 'add', 'data-tip': 'Add this term (Enter)' }, [icon('plus'), ' Add'])
            ])
        ]);
    }

    function renderSection(o, open, order) {
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
        return h('details', { 'class': 'or-office', 'data-key': o.Key, 'data-position-id': o.PositionId | 0, 'data-order': order | 0, open: !!open }, [
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

    // Re-render one section in place from a write's answer (d.Office). When the
    // answer carries no Office, fetch just that section (rolls, scoped by Key).
    // after: {focusEntry, prefill}
    function refreshSection(key, d, after) {
        if (d && d.Office !== undefined) { return Promise.resolve(showOffice(key, d.Office, after)); }
        return post('rolls', { Key: key }).then(function (r) {
            if (Number(r.status) !== 0) { throw new Error(r.error || 'The rolls could not be reloaded. Reload the page.'); }
            showOffice(key, (r.Offices || [])[0] || null, after);
        });
    }

    function showOffice(key, office, after) {
        after = after || {};
        var old = findSection(key);
        if (!office || !old || office.Key !== key) { return; }
        if (editingRow && old.contains(editingRow)) { editingRow = null; }
        var fresh = renderSection(office, true, parseInt(old.getAttribute('data-order'), 10) || 0);
        old.parentNode.replaceChild(fresh, old);
        unfold(fresh, office);
        applyEntryMode(fresh);
        if (after.focusEntry) {
            var entry = fresh.querySelector('tr.or-entry');
            if (entry) {
                var period = entry.querySelector('.or-period-input');
                if (after.prefill && period && entryMode === 'season') {
                    period.value = after.prefill;
                    readBack(period);
                }
                var holder = entry.querySelector('.or-holder-input');
                if (holder) { holder.focus(); }
            }
        }
    }

    // Put every entry row in the sticky entry mode.
    function applyEntryMode(scope) {
        scope.querySelectorAll('tr.or-entry .or-period-field').forEach(function (f) {
            if (fieldMode(f) !== entryMode) { setMode(f, entryMode); }
        });
    }

    // An office in "Other offices (n)" that now has terms moves to its registry
    // position among the main sections (data-order, the rolls order the page was
    // drawn in); the fold's count follows, and the fold goes away once it is empty.
    function unfold(section, office) {
        var fold = section.closest('details.or-folded');
        if (!fold || !(office.TermCount > 0)) { return; }
        var mine = parseInt(section.getAttribute('data-order'), 10) || 0;
        var list = document.getElementById('or-offices');
        var before = fold;
        Array.prototype.some.call(list.querySelectorAll(':scope > details.or-office'), function (s) {
            if ((parseInt(s.getAttribute('data-order'), 10) || 0) > mine) { before = s; return true; }
            return false;
        });
        list.insertBefore(section, before);
        var left = fold.querySelectorAll('details.or-office').length;
        if (!left) { fold.parentNode.removeChild(fold); return; }
        var count = fold.querySelector('.or-folded-count');
        if (count) { count.textContent = '(' + left + ')'; }
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

    // A search is "pending" from the keystroke (through the debounce and the fetch)
    // until its results are shown or dropped; an Enter pressed meanwhile is queued
    // (_orEnterQueued) and replayed on the results, never saved past them.
    function searchDone(input) {
        clearTimeout(input._orTimer);
        input._orPending = false;
        input._orEnterQueued = false;
    }

    function holderInput(input) {
        var wrap = input.closest('.or-ac-wrap');
        wrap.querySelector('.or-holder-id').value = '';
        holderHint(wrap);
        clearTimeout(input._orTimer);
        input._orEnterQueued = false;   // a new keystroke cancels an Enter queued before it
        var q = input.value.trim();
        if (q.length < 2) { searchDone(input); acClose(wrap); return; }
        input._orPending = true;
        input._orTimer = setTimeout(function () {
            var seq = ++searchSeq;
            fetch(C.uir + 'SearchAjax/universal&focus=player&inactive=1&kid=' + (C.kingdomId | 0)
                + (C.parkId ? '&pid=' + (C.parkId | 0) : '') + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (seq !== searchSeq || input.value.trim() !== q) { return; }   // a newer search owns the state
                    var queued = !!input._orEnterQueued;
                    searchDone(input);
                    if (document.activeElement !== input) { return; }
                    acRender(wrap, (d && d.players) || [], q);
                    if (queued) { replayEnter(wrap, input); }
                })
                .catch(function () {
                    if (seq === searchSeq) { searchDone(input); }
                    acClose(wrap);
                });
        }, DEBOUNCE);
    }

    // The result Enter should pick for typed text q: an exact name match, else the
    // first name that starts with q (case-insensitive). null when neither.
    function acBestMatch(items, q) {
        var want = String(q || '').trim().toLowerCase();
        if (!want) { return null; }
        var name = function (it) { return String(it.getAttribute('data-name') || '').trim().toLowerCase(); };
        return items.filter(function (it) { return name(it) === want; })[0]
            || items.filter(function (it) { return name(it).indexOf(want) === 0; })[0]
            || null;
    }

    function acRender(wrap, players, q) {
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
        var best = acBestMatch(Array.prototype.slice.call(r.querySelectorAll('.kn-ac-item')), q);
        if (best) { best.classList.add('kn-ac-focused'); }
        if (players.length) { r.appendChild(h('div', { 'class': 'or-ac-foot', 'aria-hidden': 'true' })); }
        acFoot(wrap);
        acCloseAll(wrap);
        r.classList.add('kn-ac-open');
    }

    // The key hint under the results, for what Enter will do now.
    function acFoot(wrap) {
        var foot = wrap.querySelector('.or-ac-foot');
        if (!foot) { return; }
        var tr = wrap.closest('tr');
        var link = !!(tr && tr._orMode === 'link');
        var focused = !!wrap.querySelector('.kn-ac-item.kn-ac-focused');
        foot.textContent = focused
            ? (link ? 'Enter links the highlighted player.' : 'Enter picks the highlighted player. Esc keeps the typed name as plain text.')
            : (link ? 'Use the arrow keys to pick a player.' : 'Arrow keys pick a player. Enter closes the list, then Enter saves the name as plain text.');
    }

    function acMove(wrap, dir) {
        var items = Array.prototype.slice.call(wrap.querySelectorAll('.kn-ac-item'));
        if (!items.length) { return; }
        var i = items.findIndex(function (it) { return it.classList.contains('kn-ac-focused'); });
        items.forEach(function (it) { it.classList.remove('kn-ac-focused'); });
        i = i < 0 ? (dir > 0 ? 0 : items.length - 1) : (i + dir + items.length) % items.length;
        items[i].classList.add('kn-ac-focused');
        items[i].scrollIntoView({ block: 'nearest' });
        acFoot(wrap);
    }

    // Enter in a holder input. Returns true when the press is used up here:
    //  - a search is pending: queue it, to be replayed on the results;
    //  - a highlighted player: pick it;
    //  - players listed, none highlighted: this Enter only closes the list (the
    //    next Enter saves the typed name as plain text).
    // false (the row's own Enter runs: save) when the list is closed, or open
    // with no players at all ("It will be saved as plain text").
    function holderEnter(wrap, input) {
        if (input._orPending) { input._orEnterQueued = true; return true; }
        if (!acOpen(wrap)) { return false; }
        var focused = wrap.querySelector('.kn-ac-item.kn-ac-focused');
        if (focused) { acPick(wrap, focused); return true; }
        var listed = !!wrap.querySelector('.kn-ac-item');
        acClose(wrap);
        return listed;
    }

    // An Enter that waited for a search: handled as if pressed on its results.
    function replayEnter(wrap, input) {
        if (holderEnter(wrap, input)) { return; }
        var tr = input.closest('tr');
        if (tr) { rowEnter(tr); }
    }

    function acPick(wrap, item) {
        var input = wrap.querySelector('.or-holder-input');
        searchDone(input);
        searchSeq++;   // drop any fetch still in flight for the text picked over
        input.value = item.getAttribute('data-name') || '';
        wrap.querySelector('.or-holder-id').value = item.getAttribute('data-id') || '';
        acClose(wrap);
        holderHint(wrap);
        var tr = wrap.closest('tr');
        if (tr && tr._orMode === 'link') { saveLink(tr); return; }
        // Move on to the period, as Tab would.
        var field = tr ? tr.querySelector('.or-period-field') : null;
        var next = field ? activeInput(field) : null;
        if (next) { next.focus(); } else { input.focus(); }
    }

    // ── Add (entry row) ──────────────────────────────────────

    function addTerm(tr) {
        if (tr._orBusy) { return; }
        var section = sectionOf(tr);
        var wrap = tr.querySelector('.or-ac-wrap');
        var holder = wrap.querySelector('.or-holder-input'), hid = wrap.querySelector('.or-holder-id');
        var field = tr.querySelector('.or-period-field'), note = tr.querySelector('.or-note-input');
        var calendar = fieldMode(field) === 'calendar';
        var period = field.querySelector('.or-period-input');
        var name = holder.value.trim(), text = period.value.trim();
        var dateError = calendar ? commitTypedDates(field) : '';
        if (dateError) { rowMessage(tr, dateError); activeInput(field).focus(); return; }
        var dates = field.querySelectorAll('.or-date-input');
        var startDate = dates[0].value.trim(), endDate = dates[1] ? dates[1].value.trim() : '';
        if (!name) { rowMessage(tr, 'Enter a holder: pick a player or type a name.'); holder.focus(); return; }
        if (!calendar && !text) { rowMessage(tr, 'Enter a period, e.g. "Spring–Fall 23".'); period.focus(); return; }
        if (calendar && !startDate) { rowMessage(tr, 'Enter a start date. Leave the end blank if it is unknown.'); activeInput(field).focus(); return; }
        var id = parseInt(hid.value, 10) || 0;
        var btn = tr.querySelector('[data-or-act="add"]');
        var fields = {
            PositionId: tr.getAttribute('data-position-id'),
            MundaneId: id,
            // The name of record: for a picked player, the persona at the pick
            // (acPick fills it; typing afterwards clears the id).
            HolderName: name,
            Note: note.value.trim()
        };
        // Calendar sends exact dates and no Period; a blank end means end unknown.
        if (calendar) { fields.StartDate = startDate; fields.EndDate = endDate; } else { fields.Period = text; }
        tr._orBusy = true;
        if (btn) { btn.disabled = true; }
        var key = section.getAttribute('data-key');
        fields.Key = key;
        post('addhistory', fields).then(function (d) {
            if (Number(d.status) !== 0) {
                rowMessage(tr, d.error || 'The term could not be added.');
                var back = activeInput(field);
                if (back) { back.focus(); }
                return null;
            }
            // One round trip: the answer carries the redrawn office and the next pre-fill.
            return refreshSection(key, d, { focusEntry: true, prefill: calendar ? '' : (d.PreviousSpan || '') });
        }).catch(failed).then(function () {
            tr._orBusy = false;
            if (btn) { btn.disabled = false; }
        });
    }

    // ── Edit in place ────────────────────────────────────────

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
        var field = periodField({ period: d.period, startDate: d.startDate, endDate: current ? '' : d.endDate, current: current });
        periodTd.appendChild(field);
        // Calendar when the term has an exact date, otherwise Season.
        var hasExact = !!(d.startDate || (!current && d.endDate));
        if (hasExact) { setMode(field, 'calendar'); }
        var period = field.querySelector('.or-period-input');

        noteTd.textContent = '';
        var note = h('input', { type: 'text', 'class': 'or-input or-note-input', maxlength: '500', placeholder: 'Note', 'aria-label': 'Note' });
        note.value = d.note || '';
        noteTd.appendChild(note);

        editActions(tr, true);
        if (!hasExact && period.value) { readBack(period); }

        var target = (focusCell === 'note') ? note
            : (focusCell === 'period' || current) ? activeInput(field)
            : (tr.querySelector('.or-holder-input') || activeInput(field));
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
            // The same name retyped on a linked row, without re-picking it, keeps the link.
            var sameLinked = id === 0 && origId > 0 && name === (d.holder || '').trim();
            if (id > 0 && id !== origId) {
                fields.MundaneId = id;
                changed = true;
            } else if (id === 0 && !sameLinked && (origId > 0 || name !== (d.holder || ''))) {
                if (!name) { rowMessage(tr, 'Enter a holder: pick a player or type a name.'); return; }
                // MundaneId=0 is what unlinks; HolderName alone is ignored on a linked row.
                fields.MundaneId = 0;
                fields.HolderName = name;
                changed = true;
            }
        }

        var field = tr.querySelector('.or-period-field');
        var periodInput = field.querySelector('.or-period-input');
        var text = periodInput.value.trim();
        var dateError = fieldMode(field) === 'calendar' ? commitTypedDates(field) : '';
        if (dateError) { rowMessage(tr, dateError); activeInput(field).focus(); return; }
        var dates = field.querySelectorAll('.or-date-input');
        var startDate = dates[0] ? dates[0].value.trim() : '';
        var endDate = dates[1] ? dates[1].value.trim() : '';
        var hadExact = !!(d.startDate || (!current && d.endDate));
        if (fieldMode(field) === 'season') {
            // Season: send the period when it changed, or when the row had exact dates
            // and the user switched to Season -- a sent Period clears the unsent dates.
            if (text !== (d.period || '').trim() || hadExact) {
                if (!text) { rowMessage(tr, 'Enter a period, e.g. "Spring–Fall 23".'); periodInput.focus(); return; }
                fields.Period = text;
                changed = true;
            }
        } else {
            // Calendar: exact dates only, never Period (the season stays as a fallback).
            // Clearing the only start (a start date with no stored start season) is
            // refused, as the server refuses it; a row stored with no start may stay so.
            if (!startDate && d.startDate && d.startSeason !== '1') {
                rowMessage(tr, 'A term needs a start: enter a start date.');
                activeInput(field).focus();
                return;
            }
            if (startDate !== (d.startDate || '')) { fields.StartDate = startDate; changed = true; }
            if (!current && endDate !== (d.endDate || '')) { fields.EndDate = endDate; changed = true; }
        }

        var noteInput = tr.querySelector('.or-note-input');
        if (noteInput && noteInput.value.trim() !== (d.note || '').trim()) {
            fields.Note = noteInput.value.trim();
            changed = true;
        }

        if (!changed) { cancelEdit(tr); return; }
        var key = sectionOf(tr).getAttribute('data-key');
        fields.Key = key;
        tr._orBusy = true;
        post('edithistory', fields).then(function (r) {
            if (Number(r.status) !== 0) {
                rowMessage(tr, r.error || 'The term could not be saved.');
                return null;
            }
            return refreshSection(key, r);
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
        var key = sectionOf(tr).getAttribute('data-key');
        tr._orBusy = true;
        // MundaneId alone: the server keeps the typed name as the name of record.
        post('edithistory', { OfficerHistoryId: tr.dataset.historyId, MundaneId: id, Key: key }).then(function (r) {
            if (Number(r.status) !== 0) { rowMessage(tr, r.error || 'The holder could not be linked.'); return null; }
            return refreshSection(key, r);
        }).catch(failed).then(function () { tr._orBusy = false; });
    }

    // ── Delete ───────────────────────────────────────────────

    function deleteTerm(tr) {
        var who = tr.dataset.holder || 'an unknown holder';
        var period = (tr.dataset.period || '').trim();
        var msg = 'Delete the term of ' + who + (period ? ' (' + period + ')' : '') + '? This cannot be undone.';
        var run = function () {
            var key = sectionOf(tr).getAttribute('data-key');
            post('deletehistory', { OfficerHistoryId: tr.dataset.historyId, Key: key }).then(function (r) {
                if (Number(r.status) !== 0) { alertUser(r.error || 'The term could not be deleted.'); return null; }
                return refreshSection(key, r);
            }).catch(failed);
        };
        if (typeof window.orkConfirm === 'function') {
            window.orkConfirm(msg, run, { title: 'Delete term', okLabel: 'Delete' });
        }
    }

    // The row's Enter: add from the entry row, save a link or an edit. true if handled.
    function rowEnter(tr) {
        if (tr.classList.contains('or-entry')) { addTerm(tr); return true; }
        if (tr._orMode === 'link') { saveLink(tr); return true; }
        if (tr._orMode === 'edit') { saveEdit(tr); return true; }
        return false;
    }

    // ── Event delegation ─────────────────────────────────────

    root.addEventListener('input', function (e) {
        var t = e.target;
        if (t.classList.contains('or-holder-input')) { holderInput(t); }
        else if (t.classList.contains('or-period-input')) { readBack(t); }
    });

    root.addEventListener('mousedown', function (e) {
        // Keep focus in the holder input while a dropdown item is chosen.
        if (e.target.closest('.kn-ac-results')) { e.preventDefault(); }
    });

    root.addEventListener('click', function (e) {
        var item = e.target.closest('.kn-ac-item');
        if (item) { acPick(item.closest('.or-ac-wrap'), item); return; }

        var modeBtn = e.target.closest('[data-or-mode]');
        if (modeBtn) { chooseMode(modeBtn, e.detail > 0); return; }

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

        // Season | Calendar is one tab stop; the arrow keys move between the two.
        if (t.hasAttribute && t.hasAttribute('data-or-mode') && /^Arrow(Left|Right|Up|Down)$/.test(e.key)) {
            e.preventDefault();
            var other = t.closest('.or-mode').querySelector('[data-or-mode="' + (t.getAttribute('data-or-mode') === 'season' ? 'calendar' : 'season') + '"]');
            chooseMode(other, false);
            other.focus();
            return;
        }

        // Enter in the holder never saves typed text past a listed match (holderEnter).
        if (wrap && e.key === 'Enter' && holderEnter(wrap, t)) { e.preventDefault(); return; }
        if (wrap && acOpen(wrap)) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                acMove(wrap, e.key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (e.key === 'Escape') { e.preventDefault(); acClose(wrap); return; }
        }
        if (!tr) { return; }
        if (e.key === 'Enter' && t.tagName === 'INPUT') {
            if (rowEnter(tr)) { e.preventDefault(); }
        } else if (e.key === 'Escape' && tr._orOrig) {
            e.preventDefault();
            cancelEdit(tr);
        }
    });

    root.addEventListener('focusout', function (e) {
        var wrap = e.target.classList && e.target.classList.contains('or-holder-input') ? e.target.closest('.or-ac-wrap') : null;
        if (wrap) {
            // Leaving the holder (Tab) drops its search: the hint shows the name as plain text.
            var input = e.target;
            setTimeout(function () {
                if (!wrap.contains(document.activeElement)) {
                    if (input._orPending) { searchDone(input); searchSeq++; }
                    acClose(wrap);
                }
            }, 120);
        }
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest || !e.target.closest('.or-ac-wrap')) { acCloseAll(null); }
    });

    // Escape closes an open date picker first (focus is on Flatpickr's alt input, or
    // inside the calendar, which lives on <body>). Capture phase, so the row editor's
    // Esc-cancels handler on #or-root never sees this press; a second Esc cancels.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        var open = Array.prototype.filter.call(root.querySelectorAll('.or-date-input'), function (i) {
            return i._flatpickr && i._flatpickr.isOpen;
        });
        if (!open.length) { return; }
        e.preventDefault();
        e.stopPropagation();
        open.forEach(function (i) {
            var fp = i._flatpickr;
            var alt = fp.altInput;
            // Focus back on the visible field FIRST: Flatpickr opens on focus, so closing
            // before refocusing (focus inside the calendar) would leave it open again.
            if (alt && root.contains(alt) && document.activeElement !== alt) { alt.focus(); }
            fp.close();
            // Belt and braces for an open queued by that focus.
            setTimeout(function () { if (fp.isOpen) { fp.close(); } }, 0);
        });
    }, true);

    applyEntryMode(root);
})();
