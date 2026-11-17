/*
 * EvBoardEdit: the Event Schedule Board's Edit mode, for people who can manage
 * the schedule. Owns the mode (enter/exit), the mode bar (save state, Undo,
 * Redo, help, Done), the transport to the batch endpoint, and the wiring
 * between the store (EvBoardStore), the board (EvBoardView) and the quick line
 * (EvBoardEntry).
 *
 *   EvBoardEdit.enter() / exit() -> Promise / isEditing()
 *   EvBoardEdit.store            the store while editing, else null
 *   EvBoardEdit.entryContext(overrides) -> parseEntry ctx built from the store
 *   EvBoardEdit.refresh(opts) -> Promise
 *                                re-read the schedule when nothing is unsaved and
 *                                merge it (store.sync; Undo survives); {own: true}
 *                                for the person's own save, which needs no notice
 *   EvBoardEdit.noteSaved(row)   the Details form saved this row: the store takes
 *                                it now (store.syncRow); nothing when not editing
 *
 * While editing, the schedule is re-read on window focus, when the page becomes
 * visible and every minute (only when everything is saved), and leaving the page
 * asks first while anything is unsaved or typed.
 *
 * Other Edit-mode surfaces (evb-type.js, evb-select.js) attach through three
 * hooks, each called with the same edit context {store, root, head, quickLine,
 * entryContext, add(parse, leads), getStaff, searchPlayers,
 * addNotice(message, restoreText, restoreLeads, {tone: 'info'}), isLeaving,
 * isTextField, modalOpen, boardShown} (see editContext below):
 *   EvBoardEdit.onEnter(fn)      after Edit mode opens
 *   EvBoardEdit.onBeforeExit(fn) when Done is pressed, before anything else;
 *                                returning false keeps Edit mode open
 *   EvBoardEdit.onExit(fn)       as Edit mode closes, before the store goes
 */
(function (root, factory) {
    root.EvBoardEdit = factory(root.EvBoardCore, root);
})(this, function (C, win) {
    'use strict';

    var doc = win.document;
    var IS_MAC = /Mac|iPhone|iPad/.test((win.navigator && win.navigator.platform) || '');
    var MOD = IS_MAC ? '⌘' : 'Ctrl';
    var PLACEHOLDER = 'Type an item: sat 9:30-10:15 bg: squatch ball at main field';

    var state = {
        editing: false,
        opening: false,
        leaving: null,      // the exit() promise while Done is in progress
        exiting: false,     // from Done's press until leaving has fully ended
        store: null,
        entry: null,
        head: null,         // the mode bar + quick line + notice
        offs: [],           // store unsubscribers
        recent: [],         // [{cid, id}] items added from the quick line, oldest first
        day: null,          // the quick line's default day
        changeSeq: 0,
        refreshSeq: 0,
        committing: false,
        refreshOwed: false, // a refresh asked for while changes were unsaved
        owedOwn: false,     // ...and every such ask was the person's own save
        reading: 0,         // the refreshSeq of the read on its way, 0 when none
        readingOwn: false,
        readDone: null,     // the promise of the latest read: a read it replaced resolves with it
        owedWaiters: [],    // resolvers of refreshes deferred while changes were unsaved
        updated: null,      // {row, n}: the "Schedule updated" notice row and its count
        pollTimer: 0,
        facts: null,        // what entryContext derives from the items, cached
        unloadBound: false
    };
    var POLL_MS = 60000;
    var api = { store: null };
    var hooks = { enter: [], beforeExit: [], exit: [] };

    function V() { return win.EvBoardView; }
    function cfg() { return win.EvConfig || {}; }
    function esc(s) { return V().esc(s); }
    function endpoint(name) {
        var c = cfg();
        return c.uir + 'EventAjax/' + name + '/' + c.eventId + '/' + c.detailId;
    }
    var XHR = { 'X-Requested-With': 'XMLHttpRequest' };

    // ---- transport ----

    // A non-2xx answer or a body that is not JSON rejects, and the store goes
    // offline: a reply with an error status is the server's problem ('server'),
    // a request that got no reply at all rejects as fetch does ('network').
    // The token makes a resent request apply once.
    function send(ops, token) {
        var fd = new win.FormData();
        fd.append('Ops', JSON.stringify(ops));
        if (token) fd.append('BatchToken', token);
        return win.fetch(endpoint('schedule_batch'), { method: 'POST', body: fd, headers: XHR, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) {
                    var bad = new Error('HTTP ' + r.status);
                    bad.reason = 'server';
                    bad.status = r.status;
                    throw bad;
                }
                return r.json().catch(function () {
                    var err = new Error('The reply was not JSON');
                    err.reason = 'protocol';
                    throw err;
                });
            });
    }

    // Resolves to {schedule, staff}: the staff in the page's EvConfig.staffList
    // shape, or null when the reply has none. The caller decides whether the read
    // is still current before it takes the staff (takeStaff). A read with no
    // answer in READ_MS is abandoned (aborted where the browser can) and rejects.
    var READ_MS = 30000;
    function fetchList() {
        var ctl = typeof win.AbortController === 'function' ? new win.AbortController() : null;
        var timer = 0;
        var limit = new Promise(function (resolve, reject) {
            timer = win.setTimeout(function () {
                if (ctl) { try { ctl.abort(); } catch (e) { /* the limit still rejects */ } }
                reject(new Error('no answer'));
            }, READ_MS);
        });
        var init = { headers: XHR, credentials: 'same-origin' };
        if (ctl) init.signal = ctl.signal;
        var read = win.fetch(endpoint('schedule_list'), init)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (!data || data.status !== 0 || !Array.isArray(data.schedule)) {
                    throw new Error((data && data.error) || 'The schedule could not be read');
                }
                var staff = Array.isArray(data.staff) ? data.staff.map(function (s) {
                    return {
                        MundaneId: +s.MundaneId || 0,
                        Persona: String(s.Persona || ''),
                        RoleName: String(s.RoleName || ''),
                        LeadCategories: Array.isArray(s.LeadCategories) ? s.LeadCategories.slice() : []
                    };
                }) : null;
                return { schedule: data.schedule, staff: staff };
            });
        return Promise.race([read, limit]).then(function (v) {
            win.clearTimeout(timer);
            return v;
        }, function (err) {
            win.clearTimeout(timer);
            throw err;
        });
    }

    // The staff come with the schedule: category-lead previews use the current list.
    function takeStaff(staff) {
        if (staff && win.EvConfig) win.EvConfig.staffList = staff;
    }

    // The schedule modal's lead search (revised.js, beside evSchedLeads), same
    // endpoints and scope: the event's kingdom first, then everyone else.
    // Resolves to [{MundaneId, Persona, Detail: 'K:P'}].
    function searchPlayers(term) {
        var c = cfg(), kid = c.kingdomId || 0;
        var json = function (url) {
            return win.fetch(url, { headers: XHR, credentials: 'same-origin' }).then(function (r) { return r.json(); });
        };
        var shape = function (list) {
            return (Array.isArray(list) ? list : []).map(function (p) {
                return { MundaneId: +p.MundaneId, Persona: String(p.Persona || ''), Detail: p.KAbbr && p.PAbbr ? p.KAbbr + ':' + p.PAbbr : '' };
            });
        };
        var q = encodeURIComponent(term);
        if (!kid) {
            return json(c.httpService + 'Search/SearchService.php?Action=Search%2FPlayer&type=all&search=' + q + '&limit=10').then(shape);
        }
        var base = c.uir + 'KingdomAjax/playersearch/' + kid + '&q=' + q;
        return json(base + '&scope=own').then(function (own) {
            own = shape(own);
            if (own.length >= 5) return own;
            return json(base + '&scope=exclude').then(function (other) {
                return own.concat(shape(other).slice(0, 10 - own.length));
            });
        });
    }

    function staffList() { return cfg().staffList || []; }

    // ---- the parse context ----

    // An item's board day and its start and end in that day's minutes.
    function timing(item) {
        var segs = C.segments(item);
        if (!segs.length) return null;
        var day = segs[0].day, start = segs[0].startMin;
        var e = C.parseNaive(item.EndTime);
        var end = e ? C.diffDays(day, e.day) * 1440 + e.min : start;
        return { day: day, start: start, end: Math.max(end, start) };
    }

    // The latest quick-line add still in the schedule (an undone add falls back
    // to the one before). A refresh renames cids, so ids are remembered too.
    function lastAdded(items) {
        for (var i = state.recent.length - 1; i >= 0; i--) {
            var r = state.recent[i], hit = null;
            for (var k = 0; k < items.length && !hit; k++) {
                if (items[k].cid === r.cid || (r.id && +items[k].EventScheduleId === r.id)) hit = items[k];
            }
            if (hit) {
                if (+hit.EventScheduleId) r.id = +hit.EventScheduleId;
                return hit;
            }
        }
        return null;
    }

    // Locations already used, most used first, each spelled as first written.
    function locationsOf(items) {
        var seen = {}, out = [];
        items.forEach(function (it) {
            var loc = String(it.Location || '').trim();
            if (!loc) return;
            var key = loc.toLowerCase().replace(/\s+/g, ' ');
            if (!seen[key]) { seen[key] = { name: loc, n: 0 }; out.push(seen[key]); }
            seen[key].n++;
        });
        return out.sort(function (a, b) { return (b.n - a.n) || a.name.localeCompare(b.name); })
            .map(function (o) { return o.name; });
    }

    // The items, their board days and their locations, read again only when the
    // items change (the store's version, or the view's list when not editing):
    // the quick line asks for its context on every keystroke.
    function boardFacts() {
        var c = cfg(), store = state.store;
        var es = c.eventStart || '', ee = c.eventEnd || '';
        var ver = store && typeof store.version === 'function' ? store.version() : null;
        var src = store ? null : V().getItems();
        var f = state.facts;
        if (f && f.store === store && f.es === es && f.ee === ee &&
            (store ? ver !== null && f.ver === ver : f.src === src)) return f;
        var items = store ? store.items() : (src || []);
        state.facts = f = {
            store: store, ver: ver, src: src, es: es, ee: ee, items: items,
            days: C.buildBoard(items, { eventStart: es, eventEnd: ee }).days.map(function (d) { return d.day; }),
            eventDays: C.eventDays(es, ee),
            locations: locationsOf(items)
        };
        return f;
    }

    function entryContext(overrides) {
        var c = cfg();
        var f = boardFacts(), items = f.items, days = f.days.slice();
        var last = lastAdded(items), t = last && timing(last);
        var day = state.day && days.indexOf(state.day) >= 0 ? state.day : (days[0] || null);
        var ctx = {
            days: days,
            // The event's own days: a weekday it does not have reads as the nearest, outside it.
            eventDays: f.eventDays.slice(),
            day: day,
            // After switching days ('sun' alone) an untimed line starts that day fresh.
            lastEnd: t && t.day === day ? { day: t.day, min: t.end } : null,
            lastDuration: t && t.end > t.start ? t.end - t.start : 60,
            locations: f.locations.slice(),
            canFeast: !!c.canManageFeast
        };
        return Object.assign(ctx, overrides || {});
    }

    // The item a parse describes, as the store and the board take it; its leads
    // are the entry's lead chips.
    function itemFrom(r, leads) {
        var it = {
            Title: r.title, StartTime: r.start, EndTime: r.end,
            Category: r.category || 'Other', SecondaryCategory: r.secondaryCategory || '',
            Location: r.location || '', Description: r.description || '', Leads: leads || []
        };
        if (r.cost != null && cfg().canManageFeast) it.Cost = r.cost;
        return it;
    }

    // ---- the mode bar ----

    function helpHtml() {
        var row = function (k, v) { return '<tr><th scope="row">' + k + '</th><td>' + v + '</td></tr>'; };
        var code = function (s) { return '<code>' + esc(s) + '</code>'; };
        var kbd = function (s) { return '<kbd>' + esc(s) + '</kbd>'; };
        return '<div class="evb-help-title">Type an item on one line</div>' +
            '<table class="evb-help-table"><tbody>' +
            row('Day', code('sat') + ' ' + code('saturday') + ' ' + code('11/21') + ' first; a day alone switches the day') +
            row('Time', code('9:30-10:15') + ' ' + code('9a-12p') + ' ' + code('3-4') + ' ' + code('10 to 1')) +
            row('Start', code('3p') + ' ' + code('15:00') + ' ' + code('noon') + ': same length as the last item') +
            row('Length', code('45m') + ' ' + code('1h30m') + ' ' + code('+45') + ': starts where the last item ended') +
            row('Next', code('then') + ': right after the last item') +
            row('Category', code('#bg') + ' ' + code('#class') + ' or ' + code('bg:') + ' first; a second ' + code('#') + ' adds a stripe') +
            row('Place', code('at Main Field')) +
            row('Notes', code('"Bring water"')) +
            row('Cost', code('$8') + ' on a feast item') +
            '</tbody></table>' +
            '<div class="evb-help-title">Keys</div>' +
            '<table class="evb-help-table"><tbody>' +
            row(kbd('Enter'), 'add the item') +
            row(kbd('Esc'), 'clear the line') +
            row(kbd('/'), 'jump to the line') +
            row(kbd('↑') + kbd('↓') + ' ' + kbd('Tab'), 'choose a suggestion') +
            row(kbd(MOD + ' Z'), 'undo') +
            row(kbd('Shift ' + MOD + ' Z'), 'redo') +
            '</tbody></table>' +
            '<div class="evb-help-title">On the board</div>' +
            '<table class="evb-help-table"><tbody>' +
            row('Click', 'an empty time to type an item there; drag for its length') +
            row(kbd('Enter'), 'add it and start the next one after it') +
            row(kbd('Tab'), 'add it and start one alongside') +
            row(kbd('Esc'), 'stop') +
            row('Drag', 'a block to move it; a selected block\'s top or bottom edge to resize it') +
            row(kbd('↑') + kbd('↓'), 'move the selected item 15 minutes (with ' + kbd('Alt') + ', 5)') +
            row(kbd('Shift ↑') + kbd('Shift ↓'), 'move only its end') +
            row(kbd('←') + kbd('→'), 'move it a day') +
            row(kbd('Shift Enter'), 'open the selected item’s Details (location, leads, description)') +
            row(kbd('Shift') + ' click', 'add a block to the selection, or take it out') +
            row(kbd(MOD + ' A'), 'select every block') +
            row(kbd(MOD + ' D'), 'duplicate the selection just after it') +
            row(kbd(MOD + ' C') + ' ' + kbd(MOD + ' V'), 'copy the selection; paste it at the cursor') +
            row('Drag', 'the selected block\'s corner square down to repeat it through the day') +
            '</tbody></table>' +
            '<div class="evb-help-title">With nothing selected</div>' +
            '<table class="evb-help-table"><tbody>' +
            row(kbd('↑') + kbd('↓') + kbd('←') + kbd('→'), 'move the cursor (15 minutes, a day)') +
            row('Type', 'start an item at the cursor; ' + kbd('Enter') + ' opens it empty') +
            '</tbody></table>' +
            '<div class="evb-help-title">Many at once</div>' +
            '<table class="evb-help-table"><tbody>' +
            row('Paste', 'several lines (a post, last year’s notes) on the board or in the line: they show dashed until you press Add all') +
            row(kbd('Esc'), 'discard that preview') +
            row('More', 'a skeleton of the usual items, a past event’s schedule, or this schedule as text') +
            row('<i class="fas fa-exclamation-triangle evb-help-warn" aria-label="Warning"></i>', 'on a block: a lead or a place booked twice at once') +
            '</tbody></table>';
    }

    function headHtml() {
        return '<div class="evb-modebar" role="toolbar" aria-label="Schedule editing">' +
            '<span class="evb-modebar-title"><i class="fas fa-pencil-alt" aria-hidden="true"></i> Editing schedule</span>' +
            '<span class="evb-save" data-state="saved"><span class="evb-save-dot" aria-hidden="true"></span>' +
            '<span class="evb-save-state" role="status" aria-live="polite">All changes saved</span>' +
            '<button type="button" class="evb-save-retry" hidden>Retry</button></span>' +
            '<span class="evb-modebar-actions">' +
            '<button type="button" class="evb-modebar-btn evb-undo" aria-label="Undo" data-tip="Undo (' + MOD + '+Z)" disabled>' +
            '<i class="fas fa-undo" aria-hidden="true"></i><span class="evb-modebar-label">Undo</span></button>' +
            '<button type="button" class="evb-modebar-btn evb-redo" aria-label="Redo" data-tip="Redo (Shift+' + MOD + '+Z)" disabled>' +
            '<i class="fas fa-redo" aria-hidden="true"></i><span class="evb-modebar-label">Redo</span></button>' +
            '<span class="evb-help-wrap"><button type="button" class="evb-modebar-btn evb-help" aria-label="Typing help"' +
            ' aria-expanded="false" aria-controls="evb-help-pop">?</button>' +
            '<div class="evb-help-pop" id="evb-help-pop" role="region" aria-label="Typing help" hidden>' + helpHtml() + '</div></span>' +
            '<button type="button" class="ev-submit-btn evb-done">Done</button>' +
            '</span></div>' +
            '<div class="evb-quickline"></div>' +
            '<div class="evb-notice" role="alert" hidden><div class="evb-notice-rows"></div>' +
            '<button type="button" class="evb-notice-close" aria-label="Dismiss">&times;</button></div>';
    }

    function q(sel) { return state.head && state.head.querySelector(sel); }

    var SAVE_TEXT = { saving: 'Saving…', saved: 'All changes saved' };
    // Offline, by the store's reason; Retry stays offered in every case.
    var OFFLINE_TEXT = {
        network: 'Couldn’t reach the server',
        server: 'The server had a problem. Press Retry in a moment.',
        'signed-out': 'You’re signed out. Sign in in another tab, then press Retry.',
        forbidden: 'You no longer have permission to edit this schedule.',
        protocol: 'The server sent an unexpected reply.'
    };

    // A request the server refused as a whole names its reason in status.message.
    function offlineText(status) {
        var msg = status.reason === 'server' && typeof status.message === 'string' ? status.message.trim() : '';
        if (!msg) return OFFLINE_TEXT[status.reason] || OFFLINE_TEXT.network;
        if (!/[.!?]$/.test(msg)) msg += '.';
        return 'The server had a problem: ' + msg + ' Press Retry in a moment.';
    }

    function showStatus() {
        if (!state.store || !state.head) return;
        var status = state.store.status(), st = status.state;
        q('.evb-save').setAttribute('data-state', st);
        q('.evb-save-state').textContent = st === 'offline' ? offlineText(status) : SAVE_TEXT[st];
        q('.evb-save-retry').hidden = st !== 'offline';
        if (st === 'saved' && state.refreshOwed) refresh({ own: state.owedOwn });
    }

    function guardUnload(on) {
        if (on === state.unloadBound) return;
        state.unloadBound = on;
        if (on) win.addEventListener('beforeunload', onBeforeUnload);
        else win.removeEventListener('beforeunload', onBeforeUnload);
    }

    function showHistory() {
        if (!state.store || !state.head) return;
        // Done is leaving: nothing may change, so neither looks available until
        // leaving has ended (the mode bar gone, or Edit mode kept).
        var busy = !!state.leaving || state.exiting;
        q('.evb-undo').disabled = busy || !state.store.canUndo();
        q('.evb-redo').disabled = busy || !state.store.canRedo();
    }

    // A row in the notice; restoreText adds a button that puts a rejected line
    // (with its hand-picked restoreLeads as chips) back in the quick line (used
    // when the line held other text). opts.tone 'info' is news, not a problem:
    // the notice is drawn plain while it holds only such rows.
    function addNotice(message, restoreText, restoreLeads, opts) {
        var box = q('.evb-notice');
        if (!box) return;
        var row = doc.createElement('div');
        row.className = 'evb-notice-row' + (opts && opts.tone === 'info' ? ' evb-notice-row-info' : '');
        row.appendChild(doc.createTextNode(message));
        if (restoreText) {
            var b = doc.createElement('button');
            b.type = 'button';
            b.className = 'evb-notice-restore';
            b.textContent = 'Put it back in the line';
            b.evbRestoreText = restoreText;
            b.evbRestoreLeads = restoreLeads || [];
            b.addEventListener('click', function () {
                state.entry.setText(restoreText, { leads: b.evbRestoreLeads });
                state.entry.focus();
                row.parentNode.removeChild(row);
                if (!box.querySelector('.evb-notice-row')) box.hidden = true;
                noticeTone(box);
            });
            row.appendChild(doc.createTextNode(' '));
            row.appendChild(b);
        }
        box.querySelector('.evb-notice-rows').appendChild(row);
        box.hidden = false;
        noticeTone(box);
        return row;
    }

    // "Schedule updated" is one row at most: a later re-read that finds changes
    // replaces it, with the count of both while the earlier one is still showing.
    function noticeUpdated(n) {
        var rows = q('.evb-notice-rows'), prev = state.updated;
        if (prev && rows && prev.row.parentNode === rows) {
            n += prev.n;
            rows.removeChild(prev.row);
        }
        var row = addNotice('Schedule updated: ' + n + (n === 1 ? ' change' : ' changes') + ' made by someone else.',
            null, null, { tone: 'info' });
        state.updated = row ? { row: row, n: n } : null;
    }

    function noticeTone(box) {
        box.classList.toggle('evb-notice-info', !box.querySelector('.evb-notice-row:not(.evb-notice-row-info)'));
    }

    function clearNotice() {
        var box = q('.evb-notice');
        if (!box) return;
        box.querySelector('.evb-notice-rows').innerHTML = '';
        box.hidden = true;
    }

    function setHelp(open) {
        var pop = q('.evb-help-pop'), btn = q('.evb-help');
        if (!pop) return;
        pop.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    // ---- store events ----

    function onChange() {
        state.changeSeq++;
        V().setItems(state.store.items());
        showHistory();
        // The context moved (an undo, a refresh): the preview follows it.
        if (!state.committing && state.entry && state.entry.getText().trim()) state.entry.reparse();
    }

    function handPicked(leads) { return (leads || []).filter(function (l) { return !l.Auto; }); }

    function onRejected(r) {
        var title = (r.item && r.item.Title) || 'an item';
        var msg = String(r.message || 'The server refused it').replace(/[.\s]+$/, '');
        var restore = null, leads = null;
        if (r.kind === 'add' && r.item) {
            // The leads come back as chips, not as '@' text: the category fills
            // its own again, and the hand-picked ones are kept.
            var text = C.formatEntry(Object.assign({}, r.item, { Leads: [] }), { withDay: true });
            leads = handPicked(r.item.Leads);
            // Someone typing elsewhere (a block on the board) keeps the keyboard.
            var a = doc.activeElement;
            var typingElsewhere = isTextField(a) && a !== state.entry.input;
            if (!state.entry.getText().trim() && !typingElsewhere) {
                state.entry.setText(text, { leads: leads });
                state.entry.focus();
            } else {
                restore = text;
            }
        }
        // When the store undid later additions too, one sentence covers them all.
        var more = Number(r.dropped) > 0 ? Math.floor(Number(r.dropped)) : 0;
        if (more) {
            addNotice('Couldn’t save “' + title + '” or ' + more + ' more: ' + msg + '. They were undone.', restore, leads);
        } else {
            addNotice('Couldn’t save “' + title + '”: ' + msg + '. It was undone.', restore, leads);
        }
    }

    // The store's safety net refused a change (see normalize): it was not made.
    // One row per message while it shows (a group gesture may be refused for each item).
    function onRefused(r) {
        var msg = String((r && r.message) || '').trim();
        var rows = q('.evb-notice-rows');
        if (!msg || !rows) return;
        if (Array.prototype.some.call(rows.children, function (row) { return row.textContent === msg; })) return;
        addNotice(msg);
    }

    // The server's limits on an item's times, checked before anything is applied
    // or sent (EvBoardCore.timesRefusal against the event's hardWindow).
    function normalize(next, prev) {
        var msg = C.timesRefusal(next, prev, C.hardWindow(cfg().eventStart, cfg().eventEnd));
        return msg ? { refuse: msg } : { item: next };
    }

    // Someone else deleted an item this person changed, or changed it first.
    function onSuperseded(r) {
        var title = r.title || 'An item';
        addNotice(r.kind === 'gone'
            ? '"' + title + '" was removed by someone else.'
            : '"' + title + '" was changed by someone else. Their version is shown; your change was not applied.');
    }

    // ---- the quick line ----

    function setDefaultDay(day) {
        state.day = day;
        if (state.entry) {
            state.entry.input.placeholder = C.dayLabel(day) + ': type a time and a title, e.g. 9:30-10:15 bg: squatch ball';
        }
        // A board showing one day (a phone, or a chosen day) follows the line.
        if (V().getDay() && V().getDay() !== day) V().setDay(day);
    }

    // A bulk preview (evb-bulk.js) owns the ghosts while it shows.
    function bulkOpen() { return !!(win.EvBoardBulk && win.EvBoardBulk.isOpen()); }

    function onParse(r) {
        if (state.committing || bulkOpen()) return;
        V().setGhosts(r && r.ok ? [itemFrom(r, state.entry && state.entry.getLeads())] : []);
    }

    // Add a valid parse as an item with the given leads; the quick line's day and
    // "then" follow it. Null once Done is leaving (the store is about to go) or
    // when the store refused it (its notice says why). An item on a day outside
    // the event's dates is added, and the notice says so.
    function addParsed(r, leads) {
        if (state.leaving) return null;
        var cid;
        state.committing = true;
        try {
            cid = state.store.add(itemFrom(r, leads));
            if (cid) state.recent.push({ cid: cid, id: 0 });
        } finally {
            state.committing = false;
        }
        if (!cid) return null;
        setDefaultDay(r.day);
        if (r.outside && r.day) {
            addNotice('"' + r.title + '" is on ' + C.dayDateLabel(r.day) + ', outside the event\'s dates.');
        }
        return cid;
    }

    // False keeps the line (a refused add stays to be fixed).
    function onCommit(r) {
        if (state.leaving) return false;
        if (!r.ok) {
            if (!r.setDay) return false;
            setDefaultDay(r.setDay);
            return true;
        }
        V().setGhosts([]);
        if (!addParsed(r, state.entry.getLeads())) {
            onParse(r);
            return false;
        }
        return true;
    }

    // ---- hooks for the other Edit-mode surfaces ----

    function editContext() {
        return {
            store: state.store,
            root: doc.getElementById('evb-root'),
            head: state.head,
            quickLine: state.entry,
            entryContext: entryContext,
            add: addParsed,
            getStaff: staffList,
            searchPlayers: searchPlayers,
            addNotice: addNotice,
            // Done is in progress: nothing more may be added or opened.
            isLeaving: function () { return !!state.leaving; },
            // Board keys stay out of text fields and page modals.
            isTextField: isTextField,
            modalOpen: modalOpen,
            boardShown: boardShown
        };
    }

    // Runs each hook; true unless one returned false.
    function runHooks(list) {
        var ctx = editContext(), ok = true;
        list.slice().forEach(function (fn) {
            try { if (fn(ctx) === false) ok = false; } catch (e) { if (win.console) win.console.error(e); }
        });
        return ok;
    }

    // ---- page-level handlers (bound while editing) ----

    function isTextField(t) {
        if (!t || t.nodeType !== 1) return false;
        if (t.isContentEditable || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT') return true;
        return t.tagName === 'INPUT' && !/^(button|submit|reset|checkbox|radio|range|color|file|image)$/i.test(t.type || '');
    }

    // A page modal (the schedule modal, a confirm) owns the keyboard while it is open.
    function modalOpen() {
        var sm = doc.getElementById('ev-schedule-modal');
        if (sm && win.getComputedStyle(sm).display !== 'none') return true;
        return !!doc.querySelector('.ev-modal-overlay.ev-modal-open, .ev-img-overlay.ev-open, #ev-signin-link-overlay.ev-open, ' +
            '#ork-confirm-overlay.ork-confirm-open, [id$="-overlay"].kn-open, [id$="-overlay"].pn-open, [id$="-modal"].kn-open');
    }

    // Board keys act only while the Schedule tab is on screen showing the board.
    function boardShown() {
        var p = panel();
        if (!p || !p.isConnected || win.getComputedStyle(p).display === 'none' || !p.getClientRects().length) return false;
        return V().getView() === 'board';
    }

    // Nothing is undone or redone under a drag in progress (evb-drag.js).
    function dragging() { return !!(win.EvBoardDrag && win.EvBoardDrag.isActive && win.EvBoardDrag.isActive()); }
    function undo() { if (state.store && !state.leaving && !dragging()) state.store.undo(); }
    function redo() { if (state.store && !state.leaving && !dragging()) state.store.redo(); }

    function onKeyDown(e) {
        if (!state.editing || modalOpen() || !boardShown()) return;
        var t = e.target;
        if (e.key === 'Escape' && q('.evb-help-pop') && !q('.evb-help-pop').hidden) {
            // This Esc closes the help and does nothing else (the selection stays).
            e.preventDefault();
            setHelp(false);
            q('.evb-help').focus();
            return;
        }
        if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey && !isTextField(t)) {
            e.preventDefault();
            state.entry.focus();
            return;
        }
        if ((e.metaKey || e.ctrlKey) && !e.altKey && String(e.key).toLowerCase() === 'z') {
            // Text fields keep their own undo; an empty quick line, or a new block on
            // the board not yet typed in, has none, so it undoes the board. A rename
            // emptied by the person keeps the field's own undo.
            var freshBlock = !!(t.closest && t.closest('.evb-block-editing')) &&
                !!(win.EvBoardType && win.EvBoardType.isFresh && win.EvBoardType.isFresh());
            var emptyLine = state.entry && (t === state.entry.input || freshBlock) && !t.value;
            if (isTextField(t) && !emptyLine) return;
            e.preventDefault();
            if (e.shiftKey) redo(); else undo();
        }
    }

    function onDocClick(e) {
        var wrap = q('.evb-help-wrap');
        if (wrap && !wrap.contains(e.target)) setHelp(false);
    }

    // Bound for as long as Edit mode is on. It asks while anything would be lost:
    // unsaved changes, text in the quick line or in a block being typed, or a
    // refused line still waiting behind "Put it back in the line".
    function onBeforeUnload(e) {
        if (!state.store) return undefined;
        var st = state.store.status();
        var box = q('.evb-notice');
        var ask = st.pending > 0 || st.state === 'offline' ||
            !!(state.entry && state.entry.getText().trim()) ||
            !!(win.EvBoardType && win.EvBoardType.hasText && win.EvBoardType.hasText()) ||
            !!(box && !box.hidden && box.querySelector('.evb-notice-restore'));
        if (ask) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
        return undefined;
    }

    // ---- refresh ----

    // Re-read the schedule when everything here is saved and merge it into the
    // store (store.sync: Undo, Redo, cids and so the selection all survive). A
    // change made meanwhile wins over the answer, and the read is made again once
    // that change is saved (the schedule modal writes through its own endpoints,
    // and its change must still reach the board). Asked for while changes are
    // unsaved, it runs as soon as they are saved. Resolves once the board shows
    // the merge: a read replaced by a later one resolves with the later one, and
    // a deferred one once it has run; at once when it does not refresh (Edit mode
    // off or ended). opts.own: the person's own save (the Details form), so the
    // merge is not news from someone else.
    function refresh(opts) {
        var own = !!(opts && opts.own === true);
        if (!state.editing || !state.store) return Promise.resolve();
        if (state.store.status().state !== 'saved') {
            state.owedOwn = state.refreshOwed ? state.owedOwn && own : own;
            state.refreshOwed = true;
            return new Promise(function (res) { state.owedWaiters.push(res); });
        }
        state.refreshOwed = false;
        state.owedOwn = false;
        // A read this one replaces passes on its own-ness: the person's own save is never news.
        if (state.reading && state.readingOwn) own = true;
        var seq = ++state.refreshSeq, at = state.changeSeq, store = state.store;
        state.reading = seq;
        state.readingOwn = own;
        var ended = function () { if (state.reading === seq) state.reading = 0; };
        var replaced = function () { return state.editing && state.store === store && seq !== state.refreshSeq; };
        var p = fetchList().then(function (read) {
            if (replaced()) return state.readDone;
            if (!state.editing || state.store !== store) return undefined;
            ended();
            if (at !== state.changeSeq) return refresh({ own: own });
            // Only a read that is still current may set the staff: a late one would bring back an old list.
            takeStaff(read.staff);
            // The change event this fires re-renders the board before sync returns.
            var n = store.sync(read.schedule);
            var total = n.added + n.changed + n.removed;
            if (total && !own) noticeUpdated(total);
            return undefined;
        }, function () {
            if (replaced()) return state.readDone;
            ended(); // keep what is here; the next re-read tries again
            return undefined;
        });
        state.readDone = p;
        settleOwed(p);
        return p;
    }

    // Refreshes deferred while changes were unsaved resolve with the read that ran
    // for them, or at once (undefined) when Edit mode ends first.
    function settleOwed(p) {
        state.owedWaiters.splice(0).forEach(function (res) { res(p); });
    }

    // The automatic re-reads (focus, the page shown again, every minute): only
    // while editing, on a visible page, with nothing queued, in flight or offline.
    function autoRefresh() {
        if (!state.editing || !state.store || state.leaving || state.exiting || state.reading) return;
        if (doc.visibilityState === 'hidden') return;
        if (state.store.status().state !== 'saved') return;
        refresh();
    }

    function onFocus() { autoRefresh(); }
    function onVisibility() { if (doc.visibilityState === 'visible') autoRefresh(); }

    // ---- enter and exit ----

    function panel() { return doc.getElementById('ev-tab-schedule'); }

    function setEditButtonsBusy(busy) {
        doc.querySelectorAll('.evb-edit-btn, .evb-build-btn, .evb-past-btn').forEach(function (b) {
            b.disabled = busy;
            b.setAttribute('aria-busy', busy ? 'true' : 'false');
        });
    }

    function mount(items) {
        var max = cfg().scheduleBatchMax;
        var store = win.EvBoardStore.create({
            items: items, send: send, saveMeal: !!cfg().canManageFeast, normalize: normalize,
            maxOps: typeof max === 'number' && max > 0 && max % 1 === 0 ? max : undefined
        });
        state.store = api.store = store;
        state.recent = [];
        state.editing = true;
        state.refreshOwed = false;
        state.owedOwn = false;
        state.reading = 0;
        state.facts = null;

        var head = doc.createElement('div');
        head.className = 'evb-edit-head';
        head.innerHTML = headHtml();
        var root = doc.getElementById('evb-root');
        root.parentNode.insertBefore(head, root);
        state.head = head;

        state.entry = win.EvBoardEntry.create(q('.evb-quickline'), {
            getContext: function () { return entryContext(); },
            onParse: onParse,
            onCommit: onCommit,
            getStaff: staffList,
            searchPlayers: searchPlayers,
            placeholder: PLACEHOLDER
        });
        state.entry.input.setAttribute('aria-label', 'Add a schedule item');

        state.offs = [
            store.on('change', onChange),
            store.on('status', showStatus),
            store.on('rejected', onRejected),
            store.on('superseded', onSuperseded),
            store.on('refused', onRefused)
        ];

        // Undo and Redo keep focus where it was (usually the quick line).
        q('.evb-modebar').addEventListener('mousedown', function (e) {
            if (e.target.closest('.evb-undo, .evb-redo')) e.preventDefault();
        });
        q('.evb-undo').addEventListener('click', undo);
        q('.evb-redo').addEventListener('click', redo);
        q('.evb-save-retry').addEventListener('click', function () { store.retry(); });
        q('.evb-help').addEventListener('click', function () { setHelp(q('.evb-help-pop').hidden); });
        q('.evb-done').addEventListener('click', function () { exit(); });
        q('.evb-notice-close').addEventListener('click', function () { clearNotice(); state.entry.focus(); });

        panel().classList.add('evb-edit-mode');
        V().setView('board', { persist: false });
        V().setItems(store.items());
        V().setEditing(true);
        var shown = V().getDay();
        state.day = null;
        var ctx = entryContext();
        setDefaultDay(shown || ctx.day);
        state.entry.input.placeholder = PLACEHOLDER;
        showStatus();
        showHistory();

        doc.addEventListener('keydown', onKeyDown);
        doc.addEventListener('click', onDocClick);
        win.addEventListener('focus', onFocus);
        doc.addEventListener('visibilitychange', onVisibility);
        state.pollTimer = win.setInterval(autoRefresh, POLL_MS);
        guardUnload(true);
        state.entry.focus();
        runHooks(hooks.enter);
    }

    function unmount() {
        runHooks(hooks.exit);
        state.offs.forEach(function (off) { off(); });
        state.offs = [];
        doc.removeEventListener('keydown', onKeyDown);
        doc.removeEventListener('click', onDocClick);
        win.removeEventListener('focus', onFocus);
        doc.removeEventListener('visibilitychange', onVisibility);
        if (state.pollTimer) { win.clearInterval(state.pollTimer); state.pollTimer = 0; }
        guardUnload(false);
        state.refreshOwed = false;
        state.owedOwn = false;
        state.reading = 0;
        state.readDone = null;
        state.updated = null;
        state.facts = null;
        if (state.entry) state.entry.destroy();
        if (state.head && state.head.parentNode) state.head.parentNode.removeChild(state.head);
        state.entry = state.head = null;
        state.store = api.store = null;
        state.editing = false;
        panel().classList.remove('evb-edit-mode');
        V().setEditing(false);
        settleOwed(undefined); // a refresh deferred until saved will not run now
    }

    function enter() {
        if (state.editing || state.opening || !cfg().canManageSchedule) return Promise.resolve();
        state.opening = true;
        setEditButtonsBusy(true);
        var failed = doc.querySelector('.evb-open-failed');
        if (failed) failed.parentNode.removeChild(failed);
        return fetchList().then(function (read) {
            state.opening = false;
            setEditButtonsBusy(false);
            takeStaff(read.staff);
            mount(read.schedule);
        }, function (err) {
            state.opening = false;
            setEditButtonsBusy(false);
            openFailed((err && err.message) || 'no answer');
        });
    }

    // A dismissable notice above the board when Edit mode could not start.
    function openFailed(reason) {
        var root = doc.getElementById('evb-root');
        var old = doc.querySelector('.evb-open-failed');
        if (old) old.parentNode.removeChild(old);
        var box = doc.createElement('div');
        box.className = 'evb-notice evb-open-failed';
        box.setAttribute('role', 'alert');
        box.innerHTML = '<div class="evb-notice-rows"><div class="evb-notice-row"></div></div>' +
            '<button type="button" class="evb-notice-close" aria-label="Dismiss">&times;</button>';
        box.querySelector('.evb-notice-row').textContent = 'Couldn’t open Edit mode (' + reason + '). Try again.';
        box.querySelector('.evb-notice-close').addEventListener('click', function () { box.parentNode.removeChild(box); });
        root.parentNode.insertBefore(box, root);
    }

    // The page once the store is idle; it waits again if something was sent meanwhile.
    function settledPage(store) {
        return store.idle().then(function () {
            if (store.status().state === 'offline') throw new Error('offline');
            // A refused add put its line back while saving: stay so it is not lost.
            if (state.entry.getText().trim()) throw new Error('kept');
            return win.fetch(win.location.href, { headers: XHR, credentials: 'same-origin' }).then(function (r) {
                if (!r.ok) throw new Error('page');
                return r.text();
            });
        }).then(function (html) {
            return store.status().pending === 0 ? html : settledPage(store);
        });
    }

    function exit() {
        if (!state.editing) return Promise.resolve();
        if (state.leaving) return state.leaving;
        if (!runHooks(hooks.beforeExit)) return Promise.resolve();
        // Nothing typed is dropped: a complete line is added first, a line with no
        // title stays, and a line that only names a day applies nothing.
        if (state.entry.getText().trim()) {
            state.entry.settleQuery();
            var r = state.entry.result();
            if (r && !r.ok && r.setDay) {
                state.entry.clear();
            } else if (!r || !r.ok) {
                addNotice('The line has no title yet. Add one and press Enter, or press Esc to clear it, then Done.');
                state.entry.focus();
                return Promise.resolve();
            } else if (!onCommit(r)) {
                // Refused (its notice says why): the line stays to be fixed.
                state.entry.focus();
                return Promise.resolve();
            } else {
                state.entry.clear();
            }
        }
        // A refused line still waiting in the notice comes back to the line first.
        var restore = q('.evb-notice-restore');
        if (restore) {
            state.entry.setText(restore.evbRestoreText, { leads: restore.evbRestoreLeads });
            var row = restore.parentNode;
            row.removeChild(restore);
            row.appendChild(doc.createTextNode('Its line is back in the quick line: fix it and press Enter, or Esc to drop it, then Done.'));
            state.entry.focus();
            return Promise.resolve();
        }
        var done = q('.evb-done');
        done.disabled = true;
        state.exiting = true;
        showHistory();
        state.entry.input.readOnly = true;
        var store = state.store;
        state.leaving = settledPage(store).then(function (html) {
            var items = V().swapFromPage(html);
            if (!items) throw new Error('page');
            state.leaving = null;
            unmount();
            state.exiting = false;
            V().setItems(items);
            var btn = doc.querySelector('.evb-edit-btn');
            if (btn) btn.focus({ preventScroll: true });
        }).catch(function (err) {
            if (!state.head || (err && err.message === 'kept')) return;
            addNotice(err && err.message === 'offline'
                ? 'Still in Edit mode: some changes have not reached the server. Press Retry, then Done.'
                : 'Your changes are saved, but the page could not be refreshed. Press Done to try again.');
        }).then(function () {
            state.leaving = null;
            state.exiting = false;
            if (done) done.disabled = false;
            if (state.entry) state.entry.input.readOnly = false;
            showHistory();
        });
        return state.leaving;
    }

    function init() {
        if (!cfg().canManageSchedule || !doc.getElementById('evb-root')) return;
        // New staff or new category leads: the line being typed reads them at once.
        if (typeof win.evOnStaffChanged === 'function') {
            win.evOnStaffChanged(function () {
                if (state.entry && state.entry.getText().trim()) state.entry.reparse();
            });
        }
        // From the List view it opens the board first, as Build the schedule does.
        doc.querySelectorAll('.evb-edit-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                if (V().getView() !== 'board') {
                    V().setView('board', { persist: true });
                    if (typeof win.evWriteHash === 'function') win.evWriteHash('ev-tab-schedule');
                }
                enter();
            });
        });
        doc.querySelectorAll('.evb-build-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                V().setView('board', { persist: true });
                if (typeof win.evWriteHash === 'function') win.evWriteHash('ev-tab-schedule');
                enter();
            });
        });
    }

    api.init = init;
    api.enter = enter;
    api.exit = exit;
    api.isEditing = function () { return state.editing; };
    // A Mac (navigator.platform), where Option with a key types a character.
    api.isMac = function () { return IS_MAC; };
    api.entryContext = entryContext;
    api.refresh = refresh;
    // The Details form saved this row (its reply's schedule): the store takes it at
    // once, so an edit made before the next read sends this row's Modified as `seen`.
    api.noteSaved = function (row) {
        if (!state.editing || !state.store || !row || typeof row !== 'object') return;
        state.store.syncRow(row);
    };
    api.onEnter = function (fn) { hooks.enter.push(fn); };
    api.onBeforeExit = function (fn) { hooks.beforeExit.push(fn); };
    api.onExit = function (fn) { hooks.exit.push(fn); };
    return api;
});
