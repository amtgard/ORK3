/*
 * EvBoardBulk: the Event Schedule Board's fast starts in Edit mode.
 *
 * The bulk preview: several lines of text (pasted on the board or into the
 * quick line, or typed in the "Paste lines of text" dialog) are
 * read with EvBoardCore.parseLines and drawn as ghosts, with the bulk bar
 * (.evb-bulkbar) under the mode bar: "N items ready", Add all and Discard, and
 * notes of the lines not read, the leads left off and the items outside the
 * event's days. Nothing is saved until Add all, which adds every item in one
 * store.batch (one save, one undo step); Discard or Esc drops them. While a
 * preview shows, the quick line is disabled and its own ghost stands down, and
 * another preview (a paste of several lines, or Paste lines of text) or
 * Done is refused with "Add or discard the preview first." Done never discards.
 *
 * The More menu (.evb-more-btn, in the mode bar before Undo): Paste lines of
 * text, Start from a past event (.evb-copy-dialog, which copies a
 * past occurrence's schedule into this one through EventAjax/schedule_copy_into
 * and adopts the new rows as one undo step; the dialog cannot be closed while a
 * copy is on its way, and a copy that succeeds is always adopted while the same
 * Edit mode is on) and Copy schedule as text.
 *
 * Conflict badges (.evb-conflict): blocks named by EvBoardCore.conflicts get a
 * small warning in the top-right corner after every render, in Edit mode only.
 *
 * Nothing here changes the schedule while Done is leaving.
 * Attached through EvBoardEdit.onEnter / onBeforeExit / onExit; pasted text
 * arrives through EvBoardKeys.onForeignPaste.
 *   EvBoardBulk.isOpen() -> true while a preview shows (its ghosts are its own)
 *   EvBoardBulk.init()   binds the empty state's "Start from a past event"
 */
(function (root, factory) {
    root.EvBoardBulk = factory(root.EvBoardCore, root);
})(this, function (C, win) {
    'use strict';

    var doc = win.document;
    var COLLAPSE = 5;           // list entries shown before "Show all"
    var FLASH_MS = 2000;        // how long "Copied" shows
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var XHR = { 'X-Requested-With': 'XMLHttpRequest' };

    var s = {
        ctx: null,
        preview: null,      // {items, unread, unmatchedLeads, outside} while the bar shows
        showAll: {},        // {unread: true, leads: true} lists expanded
        bar: null,
        lockNote: null,
        moreWrap: null,
        dialog: null,       // the open dialog's overlay
        dialogReturn: null, // where focus goes when it closes
        tip: null,          // the floating conflict tip
        flashTimer: 0,
        copySeq: 0,
        copying: false,     // a copy is on its way: the dialog stays open
        copyToken: null,    // {source, token} of a copy whose outcome is unknown, for its retry
        conflictItems: null, // the board items the conflict map was read from
        conflictMap: {},
        offs: []
    };

    function V() { return win.EvBoardView; }
    function Sel() { return win.EvBoardSelect; }
    function E() { return win.EvBoardEdit; }
    function cfg() { return win.EvConfig || {}; }
    function esc(v) { return V().esc(v); }
    function leaving() { return !!(s.ctx && s.ctx.isLeaving()); }
    function typing() { return !!(win.EvBoardType && win.EvBoardType.isOpen()); }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    function endpoint(name) {
        var c = cfg();
        return c.uir + 'EventAjax/' + name + '/' + c.eventId + '/' + c.detailId;
    }

    // Lines with something on them.
    function lineCount(text) {
        return String(text == null ? '' : text).split(/\r\n|\r|\n/).filter(function (l) { return /\S/.test(l); }).length;
    }

    // ---- the preview ----

    // An item as Add all will add it: its hand-picked leads plus its category's
    // leads as auto leads (the rule on every entry path), and a cost only for
    // someone who manages feast.
    function ready(item, staff) {
        var it = Object.assign({}, item);
        it.Category = it.Category || 'Other';
        it.Leads = C.swapAutoLeads(it.Leads, staff, it.Category);
        if (it.Cost == null || !cfg().canManageFeast) delete it.Cost;
        return it;
    }

    var REFUSE_TEXT = 'Add or discard the preview first.';

    // A preview is open and something wants to start another, or to leave: say
    // so once, and put the keyboard on the bar. Nothing in the preview changes.
    function refuse() {
        if (!s.ctx || !s.preview) return;
        var rows = s.ctx.head ? s.ctx.head.querySelectorAll('.evb-notice-row') : [];
        var shown = Array.prototype.some.call(rows, function (r) { return r.textContent === REFUSE_TEXT; });
        if (!shown) s.ctx.addNotice(REFUSE_TEXT, null, null, { tone: 'info' });
        var first = s.bar && s.bar.querySelector('button');
        if (first) first.focus({ preventScroll: true });
    }

    // Read text into a preview. at: {day, min} reads untimed lines from there
    // (the board's cursor); otherwise the quick line's context. A second preview
    // never replaces the first: it is refused.
    function openPreview(text, at) {
        if (!s.ctx || leaving()) return false;
        if (s.preview) { refuse(); return true; }
        var ctx = s.ctx.entryContext();
        if (at) { ctx.day = at.day; ctx.lastEnd = { day: at.day, min: at.min }; }
        var staff = s.ctx.getStaff() || [];
        ctx.staff = staff;
        var r = C.parseLines(text, ctx);
        s.preview = {
            items: r.items.map(function (it) { return ready(it, staff); }),
            unread: r.unread,
            unmatchedLeads: r.unmatchedLeads,
            outside: r.outside
        };
        s.showAll = {};
        lockQuickLine(true);
        V().setGhosts(s.preview.items);
        drawBar();
        var first = s.bar.querySelector(s.preview.items.length ? '.evb-bulk-add' : '.evb-bulk-discard');
        if (first) first.focus({ preventScroll: true });
        return true;
    }

    // Close the preview; nothing in it is saved.
    function closePreview(focusLine) {
        if (!s.preview) return 0;
        var n = s.preview.items.length;
        s.preview = null;
        if (s.bar) { s.bar.hidden = true; s.bar.innerHTML = ''; }
        V().setGhosts([]);
        lockQuickLine(false);
        if (focusLine && s.ctx && s.ctx.quickLine) s.ctx.quickLine.focus();
        return n;
    }

    function addAll() {
        if (!s.preview || !s.ctx || leaving()) return;
        var items = s.preview.items, store = s.ctx.store, added = [];
        closePreview(true);
        if (!items.length) return;
        store.batch(function () {
            items.forEach(function (it) { added.push(store.add(it)); });
        });
        // A refused add is undone by the store, and its notice says so.
        added = added.filter(function (cid) { return !!store.get(cid); });
        if (Sel() && added.length) {
            Sel().select(added);
            Sel().revealItem(added[0]);
        }
    }

    // While a preview shows, the quick line waits (its ghost would replace the preview's).
    function lockQuickLine(on) {
        var ql = s.ctx && s.ctx.quickLine;
        if (!ql) return;
        ql.input.disabled = on;
        ql.el.classList.toggle('evb-entry-locked', on);
        if (on && !s.lockNote) {
            // Under the line, in place of its chips.
            s.lockNote = doc.createElement('div');
            s.lockNote.className = 'evb-bulk-lock';
            s.lockNote.textContent = REFUSE_TEXT;
            ql.el.insertBefore(s.lockNote, ql.el.querySelector('.evb-entry-chips'));
        } else if (!on && s.lockNote) {
            if (s.lockNote.parentNode) s.lockNote.parentNode.removeChild(s.lockNote);
            s.lockNote = null;
            // The line's own preview comes back.
            if (ql.getText().trim()) ql.reparse();
        }
    }

    // A list that folds after COLLAPSE entries behind "Show all".
    function foldList(key, entries) {
        var open = !!s.showAll[key], shown = open ? entries : entries.slice(0, COLLAPSE);
        var more = entries.length > COLLAPSE
            ? ' <button type="button" class="evb-bulk-more" data-evb-fold="' + key + '" aria-expanded="' + open + '">' +
                (open ? 'Show fewer' : 'Show all ' + entries.length) + '</button>'
            : '';
        return { shown: shown, more: more };
    }

    function drawBar() {
        var p = s.preview;
        if (!p || !s.bar) return;
        var n = p.items.length;
        var h = '<div class="evb-bulk-row">' +
            '<span class="evb-bulk-count">' + (n ? esc(plural(n, 'item', 'items')) + ' ready' : 'No items could be read') + '</span>' +
            '<span class="evb-bulk-actions">' +
            (n ? '<button type="button" class="ev-submit-btn evb-bulk-add">Add all</button>' : '') +
            '<button type="button" class="evb-bulk-btn evb-bulk-discard">Discard</button></span></div>';
        if (p.unread.length) {
            var u = foldList('unread', p.unread);
            h += '<div class="evb-bulk-note evb-bulk-unread"><span class="evb-bulk-label">Not read:</span><ul>' +
                u.shown.map(function (x) { return '<li>' + esc('Line ' + x.line + ': ' + x.text) + '</li>'; }).join('') +
                '</ul>' + u.more + '</div>';
        }
        if (p.unmatchedLeads.length) {
            var l = foldList('leads', p.unmatchedLeads);
            h += '<div class="evb-bulk-note evb-bulk-leads">' +
                esc('Not event staff, left off: ' + l.shown.join(', ') + (l.shown.length < p.unmatchedLeads.length ? '…' : '')) +
                l.more + '</div>';
        }
        if (p.outside.length) {
            h += '<div class="evb-bulk-note evb-bulk-outside">' +
                esc(plural(p.outside.length, 'item falls', 'items fall') + ' on days outside this event') + '</div>';
        }
        s.bar.innerHTML = h;
        s.bar.hidden = false;
    }

    function onBarClick(e) {
        var t = e.target.closest ? e.target : null;
        if (!t) return;
        if (t.closest('.evb-bulk-add')) { addAll(); return; }
        if (t.closest('.evb-bulk-discard')) { closePreview(true); return; }
        var fold = t.closest('[data-evb-fold]');
        if (fold) {
            var key = fold.getAttribute('data-evb-fold');
            s.showAll[key] = !s.showAll[key];
            drawBar();
            var again = s.bar.querySelector('[data-evb-fold="' + key + '"]');
            if (again) again.focus();
        }
    }

    // Pasted text that is not a copy of blocks (evb-keys.js): several lines open
    // a preview; one line is left alone (in the quick line it is ordinary typing).
    function onForeignPaste(text, e) {
        if (!s.ctx || leaving() || s.ctx.modalOpen() || lineCount(text) < 2) return false;
        var ql = s.ctx.quickLine && s.ctx.quickLine.input;
        var fromLine = !!ql && (doc.activeElement === ql || (e && e.target === ql));
        var at = !fromLine && win.EvBoardKeys ? win.EvBoardKeys.cursor() : null;
        return openPreview(text, at);
    }

    // ---- the More menu ----

    function moreHtml() {
        var item = function (key, icon, label) {
            return '<button type="button" data-evb-more="' + key + '"><i class="fas ' + icon + '" aria-hidden="true"></i>' +
                '<span class="evb-more-label">' + label + '</span></button>';
        };
        return '<button type="button" class="evb-modebar-btn evb-more-btn" aria-expanded="false" aria-controls="evb-more-menu" data-tip="More ways to add">' +
            '<i class="fas fa-ellipsis-h" aria-hidden="true"></i><span class="evb-modebar-label">More</span></button>' +
            '<div class="evb-more-menu" id="evb-more-menu" hidden>' +
            item('paste-text', 'fa-paste', 'Paste lines of text…') +
            item('past-event', 'fa-history', 'Start from a past event…') +
            item('copy-text', 'fa-copy', 'Copy schedule as text') +
            '</div>';
    }

    function moreBtn() { return s.moreWrap && s.moreWrap.querySelector('.evb-more-btn'); }
    function moreMenu() { return s.moreWrap && s.moreWrap.querySelector('.evb-more-menu'); }
    function menuOpen() { var m = moreMenu(); return !!m && !m.hidden; }

    function setMenu(open, focusButton) {
        var m = moreMenu(), b = moreBtn();
        if (!m) return;
        m.hidden = !open;
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (!open) resetCopyLabel();
        if (open) { var first = m.querySelector('button'); if (first) first.focus(); }
        if (!open && focusButton) b.focus();
    }

    function onMoreClick(e) {
        var t = e.target.closest ? e.target : null;
        if (!t) return;
        if (t.closest('.evb-more-btn')) { setMenu(!menuOpen()); return; }
        var it = t.closest('[data-evb-more]');
        if (!it) return;
        var key = it.getAttribute('data-evb-more');
        if (key === 'copy-text') { copyText(it); return; }
        setMenu(false);
        if (leaving()) return;
        if (s.preview && key === 'paste-text') { refuse(); return; }
        if (key === 'paste-text') openPasteDialog();
        else if (key === 'past-event') openCopyDialog();
    }

    // ---- copy as text ----

    function resetCopyLabel() {
        if (s.flashTimer) { win.clearTimeout(s.flashTimer); s.flashTimer = 0; }
        var b = s.moreWrap && s.moreWrap.querySelector('[data-evb-more="copy-text"] .evb-more-label');
        if (b) b.textContent = 'Copy schedule as text';
    }

    function flash(btn, text) {
        resetCopyLabel();
        var label = btn.querySelector('.evb-more-label');
        label.textContent = text;
        s.flashTimer = win.setTimeout(resetCopyLabel, FLASH_MS);
    }

    // The page's "Copy link" way: the Clipboard API, else a hidden textarea.
    function writeClipboard(text) {
        var fallback = function () {
            var ta = doc.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            var back = doc.activeElement;
            doc.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = doc.execCommand('copy'); } catch (err) { ok = false; }
            doc.body.removeChild(ta);
            if (back && back.focus) back.focus({ preventScroll: true });
            return ok;
        };
        if (win.navigator.clipboard && win.navigator.clipboard.writeText) {
            return win.navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallback(); });
        }
        return Promise.resolve(fallback());
    }

    function copyText(btn) {
        if (!s.ctx) return;
        var text = C.scheduleText(s.ctx.store.items());
        if (!text) { flash(btn, 'Nothing to copy'); return; }
        writeClipboard(text).then(function (ok) { if (btn.isConnected) flash(btn, ok ? 'Copied' : 'Copy failed'); });
    }

    // ---- dialogs (the page's modal overlay, so board keys stand down) ----

    function openDialog(cls, title, bodyHtml) {
        closeDialog(false);
        var ov = doc.createElement('div');
        var id = 'evb-dialog-title-' + cls;
        ov.className = 'ev-modal-overlay evb-dialog ' + cls;
        ov.innerHTML = '<div class="ev-modal evb-dialog-box" role="dialog" aria-modal="true" aria-labelledby="' + id + '">' +
            '<div class="ev-modal-header"><h3 id="' + id + '">' + esc(title) + '</h3>' +
            '<button type="button" class="ev-modal-close evb-dialog-close" aria-label="Close">&times;</button></div>' +
            '<div class="ev-modal-body">' + bodyHtml + '</div></div>';
        doc.body.appendChild(ov);
        s.dialogReturn = doc.activeElement && doc.activeElement !== doc.body ? doc.activeElement : null;
        if (!s.dialogReturn || !doc.contains(s.dialogReturn) || s.moreWrap && s.moreWrap.contains(s.dialogReturn)) s.dialogReturn = moreBtn();
        s.dialog = ov;
        ov.classList.add('ev-modal-open');
        var downOnBackdrop = false;
        ov.addEventListener('mousedown', function (e) { downOnBackdrop = e.target === ov; });
        ov.addEventListener('click', function (e) {
            if (e.target === ov && downOnBackdrop) askClose();
            else if (e.target.closest && e.target.closest('.evb-dialog-close')) askClose();
        });
        ov.addEventListener('keydown', onDialogKey);
        return ov;
    }

    // Esc, the backdrop and ×: nothing closes the dialog while a copy is on its
    // way (its rows would be saved but never reach the board).
    function askClose() {
        if (s.copying) return;
        closeDialog(true);
    }

    function closeDialog(restoreFocus) {
        var ov = s.dialog;
        if (!ov) return;
        s.dialog = null;
        s.copySeq++;
        s.copyToken = null;
        if (ov.parentNode) ov.parentNode.removeChild(ov);
        var back = s.dialogReturn;
        s.dialogReturn = null;
        if (!restoreFocus) return;
        if (back && doc.contains(back) && !back.disabled) back.focus({ preventScroll: true });
        else if (s.ctx && s.ctx.quickLine) s.ctx.quickLine.focus();
    }

    // Esc closes; Tab stays inside.
    function onDialogKey(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            askClose();
            return;
        }
        if (e.key !== 'Tab') return;
        var f = Array.prototype.filter.call(s.dialog.querySelectorAll('button, textarea, [href], input, [tabindex]:not([tabindex="-1"])'),
            function (el) { return !el.disabled && el.getClientRects().length; });
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    // ---- paste lines of text (typed or pasted into a box) ----

    function openPasteDialog() {
        var ov = openDialog('evb-paste-dialog', 'Paste lines of text',
            '<p class="evb-dialog-intro">One item per line, the way you would type it in the line above. A day on its own line starts that day.</p>' +
            '<label class="evb-sr" for="evb-paste-text">Lines of text</label>' +
            '<textarea id="evb-paste-text" class="evb-paste-text" rows="9" spellcheck="false"></textarea>' +
            '<div class="evb-paste-example"><span class="evb-bulk-label">For example</span>' +
            '<pre>Saturday\n9a Troll opens at the Gate\n10-11 class: Intro to Healing\nthen 30m Lunch</pre></div>' +
            '<div class="evb-dialog-actions"><button type="button" class="ev-submit-btn evb-paste-preview">Preview</button></div>');
        var ta = ov.querySelector('.evb-paste-text');
        ov.querySelector('.evb-paste-preview').addEventListener('click', function () {
            if (!ta.value.trim()) { ta.focus(); return; }
            var text = ta.value;
            closeDialog(false);
            if (!openPreview(text, null) && s.ctx) s.ctx.quickLine.focus();
        });
        ta.focus();
    }

    // ---- start from a past event ----

    // 'YYYY-MM-DD' as 'Nov 7, 2025' (no Date from a schedule value).
    function dateText(ymd) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
        return m ? MONTHS[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : '';
    }

    function readJson(r) {
        return r.json().catch(function () { throw new Error('The server sent an unexpected reply.'); });
    }

    function openCopyDialog() {
        if (!s.ctx || leaving()) return;
        openDialog('evb-copy-dialog', 'Start from a past event',
            '<p class="evb-dialog-intro">Copies every item of a past schedule into this one, on the matching days and at the same times. Nothing already here changes.</p>' +
            '<div class="evb-copy-error" role="alert" hidden></div>' +
            '<div class="evb-copy-list" aria-live="polite"></div>');
        loadSources();
    }

    function copyEls() {
        var d = s.dialog;
        return d && d.classList.contains('evb-copy-dialog')
            ? { list: d.querySelector('.evb-copy-list'), error: d.querySelector('.evb-copy-error') } : null;
    }

    function showCopyError(msg) {
        var el = copyEls();
        if (!el) return;
        el.error.textContent = msg;
        el.error.hidden = !msg;
    }

    function loadSources() {
        var el = copyEls();
        if (!el) return;
        var seq = ++s.copySeq;
        showCopyError('');
        el.list.innerHTML = '<div class="evb-copy-status">Loading…</div>';
        win.fetch(endpoint('schedule_copy_sources'), { headers: XHR, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('The list could not be read (HTTP ' + r.status + ').');
                return readJson(r);
            })
            .then(function (data) {
                if (!data || data.status !== 0 || !Array.isArray(data.sources)) {
                    throw new Error((data && data.error) || 'The list could not be read.');
                }
                if (seq === s.copySeq) drawSources(data.sources);
            })
            .catch(function (err) {
                var now = copyEls();
                if (seq !== s.copySeq || !now) return;
                now.list.innerHTML = '<div class="evb-copy-status"></div>' +
                    '<button type="button" class="evb-bulk-btn evb-copy-retry">Try again</button>';
                now.list.querySelector('.evb-copy-status').textContent = (err && err.message) || 'The list could not be read.';
                now.list.querySelector('.evb-copy-retry').addEventListener('click', loadSources);
                now.list.querySelector('.evb-copy-retry').focus();
            });
    }

    function sourceHtml(src) {
        var n = +src.ItemCount || 0;
        return '<li><button type="button" class="evb-copy-source" data-detail-id="' + esc(+src.EventCalendarDetailId) + '"' +
            ' data-name="' + esc(src.Name || '') + '">' +
            '<span class="evb-copy-name">' + esc(src.Name || 'Untitled event') + '</span>' +
            '<span class="evb-copy-meta">' + esc([dateText(src.StartDate), plural(n, 'item', 'items')].filter(Boolean).join(' · ')) +
            '</span></button></li>';
    }

    function drawSources(sources) {
        var el = copyEls();
        if (!el) return;
        if (!sources.length) {
            el.list.innerHTML = '<p class="evb-copy-none">No past schedules to start from yet.</p>';
            return;
        }
        var same = sources.filter(function (x) { return x.SameEvent; });
        var other = sources.filter(function (x) { return !x.SameEvent; });
        var group = function (label, list) {
            return list.length ? '<div class="evb-copy-group" role="heading" aria-level="4">' + label + '</div>' +
                '<ul class="evb-copy-sources">' + list.map(sourceHtml).join('') + '</ul>' : '';
        };
        el.list.innerHTML = group('This event', same) + group('Other events here', other);
        el.list.querySelectorAll('.evb-copy-source').forEach(function (b) {
            b.addEventListener('click', function () { copyFrom(b); });
        });
        var first = el.list.querySelector('.evb-copy-source');
        if (first) first.focus();
    }

    function copyFrom(btn) {
        if (!s.ctx || leaving()) return;
        var el = copyEls();
        if (!el) return;
        var store = s.ctx.store, dialog = s.dialog;
        var name = btn.getAttribute('data-name') || 'that event';
        var buttons = el.list.querySelectorAll('.evb-copy-source');
        var meta = btn.querySelector('.evb-copy-meta'), metaText = meta.textContent;
        var closeBtn = dialog.querySelector('.evb-dialog-close');
        // Until the answer comes, the dialog cannot be closed.
        s.copying = true;
        closeBtn.disabled = true;
        buttons.forEach(function (b) { b.disabled = true; });
        btn.setAttribute('aria-busy', 'true');
        meta.textContent = 'Copying…';
        showCopyError('');
        // One token per press of Copy. A press again for the same source after a copy
        // whose outcome is unknown (no reply, a server error, an unexpected reply)
        // reuses it, so a copy the server did make is not made twice.
        var source = btn.getAttribute('data-detail-id');
        if (!s.copyToken || s.copyToken.source !== source) s.copyToken = { source: source, token: win.EvBoardStore.token() };
        var attempt = s.copyToken;
        var fd = new win.FormData();
        fd.append('SourceDetailId', source);
        fd.append('RequestToken', attempt.token);
        win.fetch(endpoint('schedule_copy_into'), { method: 'POST', body: fd, headers: XHR, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('The schedule could not be copied (HTTP ' + r.status + ').');
                return readJson(r);
            })
            .then(function (data) {
                if (data && typeof data.status === 'number' && data.status !== 0) {
                    // Refused: nothing was copied, so the next press starts afresh. A reply
                    // with no numeric status is unexpected: its outcome is unknown, so the
                    // token is kept for the next press.
                    var refused = new Error(data.error || 'The schedule could not be copied.');
                    refused.refused = true;
                    throw refused;
                }
                if (!data || data.status !== 0 || !Array.isArray(data.schedule)) throw new Error('The schedule could not be copied.');
                if (s.copyToken === attempt) s.copyToken = null;
                s.copying = false;
                if (s.dialog === dialog) closeDialog(true);
                // The rows are saved, so they are always taken while this Edit mode
                // is on; once Done is leaving, the page it reloads shows them.
                if (!s.ctx || s.ctx.store !== store || leaving()) return;
                var rows = data.schedule.map(function (row) { return Object.assign({ Leads: [] }, row); });
                var cids = store.adopt(rows);
                var skipped = +data.skippedFeast || 0;
                s.ctx.addNotice('Added ' + plural(cids.length, 'item', 'items') + ' from ' + name +
                    (skipped ? '; ' + plural(skipped, 'feast item was', 'feast items were') + ' skipped' : ''), null, null, { tone: 'info' });
                if (Sel() && cids.length) {
                    Sel().select(cids);
                    Sel().revealItem(cids[0]);
                }
            })
            .catch(function (err) {
                s.copying = false;
                if (err && err.refused && s.copyToken === attempt) s.copyToken = null;
                if (s.dialog !== dialog) return;
                closeBtn.disabled = false;
                buttons.forEach(function (b) { b.disabled = false; });
                btn.removeAttribute('aria-busy');
                meta.textContent = metaText;
                showCopyError((err && err.message) || 'The schedule could not be copied.');
                btn.focus();
            });
    }

    // ---- conflict badges ----

    // The double bookings, read again only when the board's items change (the
    // view takes a new list from the store on each change; a render for a ghost,
    // a quick-line keystroke, keeps the same list).
    function conflictMap() {
        var items = V().getItems();
        if (items !== s.conflictItems) {
            s.conflictItems = items;
            s.conflictMap = C.conflicts(items || []);
        }
        return s.conflictMap;
    }

    // After every render: a badge on each block of an item that is double-booked.
    function drawConflicts() {
        if (!s.ctx) return;
        var rootEl = s.ctx.root;
        if (!rootEl) return;
        var map = conflictMap();
        rootEl.querySelectorAll('.evb-block[data-cid]').forEach(function (b) {
            var msgs = map[b.getAttribute('data-cid')];
            var badge = b.querySelector(':scope > .evb-conflict');
            // The base is whatever label the view rendered (title, category, time, place,
            // leads...), never rebuilt here. It is remembered in data-evb-label; a label
            // that is neither that base nor the one this code set was rendered anew
            // (or changed in place) and becomes the base.
            var label = b.getAttribute('aria-label') || '';
            var base = b.getAttribute('data-evb-label');
            if (base == null || (label !== base && label !== b.evbConflictLabel)) {
                base = label;
                b.setAttribute('data-evb-label', base);
            }
            if (!msgs || !msgs.length) {
                if (badge) b.removeChild(badge);
                b.classList.remove('evb-has-conflict');
                b.setAttribute('aria-label', base);
                return;
            }
            var text = msgs.join('; ');
            if (!badge) {
                badge = doc.createElement('span');
                badge.className = 'evb-conflict';
                badge.setAttribute('role', 'img');
                badge.innerHTML = '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i>';
                b.appendChild(badge);
            }
            // Attributes are set as text: nothing here is parsed as HTML.
            badge.setAttribute('data-tip', text);
            badge.setAttribute('aria-label', 'Conflict: ' + text);
            b.classList.add('evb-has-conflict');
            // Added to the end of the view's label; its own closing stop is not doubled.
            var stem = base.replace(/[\s.]+$/, '');
            b.evbConflictLabel = (stem ? stem + '. ' : '') + 'Conflict: ' + text;
            b.setAttribute('aria-label', b.evbConflictLabel);
        });
    }

    // The block clips its own overflow, so the badge's tip floats outside it; so
    // does every fill handle's (data-evb-tip, set by evb-select.js).
    function showTip(badge) {
        if (!s.tip) {
            s.tip = doc.createElement('div');
            s.tip.className = 'evb-conflict-tip';
            s.tip.setAttribute('role', 'tooltip');
            doc.body.appendChild(s.tip);
        }
        s.tip.textContent = badge.getAttribute('data-tip') || badge.getAttribute('data-evb-tip') || '';
        s.tip.hidden = false;
        var r = badge.getBoundingClientRect(), w = s.tip.offsetWidth, h = s.tip.offsetHeight;
        var vw = win.innerWidth || doc.documentElement.clientWidth, vh = win.innerHeight || doc.documentElement.clientHeight;
        var left = Math.max(8, Math.min(r.right - w, vw - w - 8));
        var top = r.bottom + 6;
        if (top + h > vh - 8) top = Math.max(8, r.top - 6 - h);
        s.tip.style.left = Math.round(left) + 'px';
        s.tip.style.top = Math.round(top) + 'px';
    }

    function hideTip() { if (s.tip) s.tip.hidden = true; }

    function onRootOver(e) {
        var b = e.target.closest && e.target.closest('.evb-conflict, .evb-fill-handle[data-evb-tip]');
        if (b && !(win.EvBoardDrag && win.EvBoardDrag.isActive())) showTip(b); else hideTip();
    }

    // ---- keys ----

    // Capture phase, ahead of the selection's Esc: Esc closes the menu, else
    // discards a preview (not while typing in a field or on the board, or under a modal).
    function onKeyDown(e) {
        if (e.key !== 'Escape' || e.defaultPrevented || !s.ctx) return;
        if (menuOpen()) {
            e.preventDefault();
            e.stopPropagation();
            setMenu(false, true);
            return;
        }
        if (!s.preview || s.ctx.modalOpen() || typing()) return;
        if (s.ctx.isTextField(e.target)) return;
        e.preventDefault();
        e.stopPropagation();
        closePreview(true);
    }

    function onDocClick(e) {
        if (menuOpen() && s.moreWrap && !s.moreWrap.contains(e.target)) setMenu(false);
    }

    // ---- attach and detach (EvBoardEdit hooks) ----

    function attach(ctx) {
        if (s.ctx) detach();
        if (!ctx.head || !V()) return;
        s.ctx = ctx;
        s.preview = null;
        var on = function (target, type, fn, opt) {
            target.addEventListener(type, fn, opt);
            s.offs.push(function () { target.removeEventListener(type, fn, opt); });
        };

        // The More menu, before Undo.
        var undo = ctx.head.querySelector('.evb-undo');
        s.moreWrap = doc.createElement('span');
        s.moreWrap.className = 'evb-more-wrap';
        s.moreWrap.innerHTML = moreHtml();
        undo.parentNode.insertBefore(s.moreWrap, undo);
        on(s.moreWrap, 'click', onMoreClick);

        // The bulk bar, under the mode bar, the line and its notice.
        s.bar = doc.createElement('div');
        s.bar.className = 'evb-bulkbar';
        s.bar.setAttribute('role', 'region');
        s.bar.setAttribute('aria-label', 'Items to add');
        s.bar.hidden = true;
        ctx.head.appendChild(s.bar);
        on(s.bar, 'click', onBarClick);

        on(doc, 'keydown', onKeyDown, true);
        on(doc, 'click', onDocClick);
        if (ctx.root) {
            on(ctx.root, 'mouseover', onRootOver);
            on(ctx.root, 'mouseleave', hideTip);
            // A press (a repeat starting from the handle) puts the tip away.
            on(ctx.root, 'pointerdown', hideTip);
        }
        on(win, 'scroll', hideTip, true);
        drawConflicts();
    }

    // Done never discards a preview: while one is open, Edit mode stays on and
    // the notice asks for Add all or Discard first.
    function beforeExit() {
        setMenu(false);
        if (s.preview) { refuse(); return false; }
        closeDialog(false);
        return true;
    }

    function detach() {
        if (!s.ctx) return;
        s.copying = false;
        closeDialog(false);
        resetCopyLabel();
        if (s.preview) closePreview(false);
        s.offs.forEach(function (off) { off(); });
        s.offs = [];
        hideTip();
        if (s.moreWrap && s.moreWrap.parentNode) s.moreWrap.parentNode.removeChild(s.moreWrap);
        if (s.bar && s.bar.parentNode) s.bar.parentNode.removeChild(s.bar);
        if (s.ctx.root) {
            s.ctx.root.querySelectorAll('.evb-conflict').forEach(function (b) { b.parentNode.removeChild(b); });
        }
        s.ctx = s.moreWrap = s.bar = s.lockNote = s.conflictItems = null;
        s.conflictMap = {};
    }

    // The empty state's "Start from a past event": Edit mode, then the dialog.
    function init() {
        if (!cfg().canManageSchedule) return;
        doc.querySelectorAll('.evb-past-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                V().setView('board', { persist: true });
                if (typeof win.evWriteHash === 'function') win.evWriteHash('ev-tab-schedule');
                E().enter().then(function () { if (s.ctx && E().isEditing()) openCopyDialog(); });
            });
        });
    }

    // A render replaces the blocks: a tip shown for an old badge goes with it.
    if (V() && V().onRender) V().onRender(function () { hideTip(); if (s.ctx) drawConflicts(); });
    if (win.EvBoardKeys && win.EvBoardKeys.onForeignPaste) win.EvBoardKeys.onForeignPaste(onForeignPaste);
    if (E() && E().onEnter) {
        E().onEnter(attach);
        E().onBeforeExit(beforeExit);
        E().onExit(detach);
    }

    return {
        init: init,
        isOpen: function () { return !!s.preview; }
    };
});
