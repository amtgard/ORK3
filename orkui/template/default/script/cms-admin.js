/* ==========================================================================
 * cms-admin.js — shared CMS admin helpers.
 *
 * Single source of truth for the three helpers that used to be copy-pasted,
 * near-verbatim, across every CMS admin surface (Cms_index / posts / media /
 * nav / sites / dashboard / theme + cms/_block_editor.tpl):
 *
 *   CmsAdmin.toast(msg, kind)        transient status toast
 *   CmsAdmin.undoableToast(msg, fn)  toast with an inline Undo button (soft deletes)
 *   CmsAdmin.modal.open(el)          add .cms-open
 *   CmsAdmin.modal.close(el)         remove .cms-open  (backdrop / [data-close-modal] / Esc handled here)
 *   CmsAdmin.post(endpoint, params)  urlencoded POST → JSON, appends window.CMS_SCOPE
 *                                    and the X-CSRF-Token (window.CMS_CSRF) header
 *   CmsAdmin.installOverflowMenus()  arm the row-action (⋯) dropdown controller
 *   CmsAdmin.bulkSelect(tableSel)    bulk-select plumbing for a DataTables list
 *   CmsAdmin.autosave(opts)          debounced autosave timer (editor hosts)
 *   CmsAdmin.guardUnsaved(isDirty)   beforeunload guard (editor hosts)
 *   CmsAdmin.previewPane(opts)       in-context preview pane (editor hosts)
 *
 * Loaded ONCE from cms/_shell_top.tpl, which also emits window.CMS_UIR /
 * CMS_SCOPE / CMS_CSRF. Keeping the CSRF-header contract in a single place is
 * what prevents the header from drifting between copies (the root cause of the
 * earlier preview-token bug).
 * ========================================================================== */
(function () {
    'use strict';

    /* ---- toast ----
     * Every CMS surface renders exactly one `.cms-toast` element. The element
     * *id* varies by page (cmsToast / cmsSitesToast / teToast), so we resolve it
     * by class — one helper then serves every surface. */
    var toastTimer = null;
    function toast(msg, kind) {
        var el = document.querySelector('.cms-toast');
        if (!el) { return; }
        el.textContent = msg;
        el.className = 'cms-toast cms-show' + (kind ? ' cms-toast-' + kind : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.className = 'cms-toast'; }, 3200);
    }

    /* ---- undoable toast ----
     * Deletes on the list surfaces are SOFT (deleted_at), so the row can be
     * brought back. This variant renders an inline Undo button and dwells longer
     * than a plain toast. Styled inline so it needs no extra CSS class.
     * Shares `toastTimer` with toast() above — a later plain toast therefore
     * cancels this one's dwell instead of racing it (the per-page copies this
     * replaced each kept their OWN timer, so the two could fight). */
    function undoableToast(msg, undoFn) {
        var el = document.querySelector('.cms-toast');
        if (!el) { return; }
        el.innerHTML = '';
        var span = document.createElement('span');
        span.textContent = msg + ' ';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'Undo';
        btn.style.cssText = 'background:none;border:none;color:inherit;text-decoration:underline;cursor:pointer;font:inherit;padding:0;margin-left:4px;';
        btn.addEventListener('click', function () {
            clearTimeout(toastTimer);
            el.className = 'cms-toast';
            if (typeof undoFn === 'function') { undoFn(); }
        });
        el.appendChild(span);
        el.appendChild(btn);
        el.className = 'cms-toast cms-show cms-toast-ok';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.className = 'cms-toast'; }, 7000);
    }

    /* ---- modal controller ----
     * open/close toggle `.cms-open`. A single document-level delegate closes on
     * [data-close-modal] clicks, backdrop clicks, and Esc — installed once. */
    var FOCUSABLE = 'a[href],area[href],button:not([disabled]),input:not([disabled]),'
        + 'select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"]),'
        + 'iframe,object,embed,[contenteditable="true"]';

    // Visible, focusable descendants in DOM order.
    function focusables(el) {
        return Array.prototype.filter.call(el.querySelectorAll(FOCUSABLE), function (n) {
            return n.offsetWidth > 0 || n.offsetHeight > 0 || n.getClientRects().length > 0;
        });
    }

    // opts.preferTextInput — defer focus one tick and prefer the first text input.
    // OPT-IN on purpose: the block editor's private modal helper did this (its
    // add-block chooser relies on landing in the search field), and folding the two
    // controllers together must not silently change focus behaviour for the modals
    // that were already using this one. Without the flag this is byte-for-byte the
    // original: synchronous focus on the first focusable, else the dialog itself.
    function openModal(el, opts) {
        if (!el) { return; }
        // Remember the opener so focus can be restored on close.
        el._cmsOpener = document.activeElement;
        el.classList.add('cms-open');
        if (opts && opts.preferTextInput) {
            // Focus on a short tick so the overlay is laid out (and its contents
            // therefore focusable) before a target is chosen.
            setTimeout(function () {
                var f = focusables(el);
                var target = null;
                for (var i = 0; i < f.length; i++) {
                    if (f[i].tagName === 'INPUT' && (!f[i].type || f[i].type === 'text')) {
                        target = f[i];
                        break;
                    }
                }
                target = target || f[0];
                if (target) {
                    try { target.focus(); } catch (e) {}
                    return;
                }
                if (!el.hasAttribute('tabindex')) { el.setAttribute('tabindex', '-1'); }
                el.focus();
            }, 30);
            return;
        }
        var f = focusables(el);
        if (f.length) {
            f[0].focus();
        } else {
            // No focusable content — park focus on the dialog container itself.
            if (!el.hasAttribute('tabindex')) { el.setAttribute('tabindex', '-1'); }
            el.focus();
        }
    }
    function closeModal(el) {
        if (!el) { return; }
        el.classList.remove('cms-open');
        var opener = el._cmsOpener;
        el._cmsOpener = null;
        if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
            opener.focus();
        }
    }

    document.addEventListener('click', function (e) {
        var closer = e.target.closest('[data-close-modal]');
        if (closer) { closeModal(closer.closest('.cms-modal-overlay')); return; }
        if (e.target.classList && e.target.classList.contains('cms-modal-overlay')) {
            closeModal(e.target);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.cms-modal-overlay.cms-open').forEach(closeModal);
            return;
        }
        // Trap Tab / Shift+Tab within the topmost open overlay.
        if (e.key === 'Tab') {
            var open = document.querySelectorAll('.cms-modal-overlay.cms-open');
            if (!open.length) { return; }
            var overlay = open[open.length - 1];
            var f = focusables(overlay);
            if (!f.length) {
                // Nothing tabbable — keep focus pinned to the dialog container.
                e.preventDefault();
                if (!overlay.hasAttribute('tabindex')) { overlay.setAttribute('tabindex', '-1'); }
                overlay.focus();
                return;
            }
            var first = f[0], last = f[f.length - 1], active = document.activeElement;
            if (e.shiftKey) {
                if (active === first || !overlay.contains(active)) {
                    e.preventDefault();
                    last.focus();
                }
            } else if (active === last || !overlay.contains(active)) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    /* ---- urlencoded POST → JSON ----
     * Appends the active scope selector (window.CMS_SCOPE) and the CSRF header
     * (window.CMS_CSRF) that CmsAjax mutations require. */
    function post(endpoint, params) {
        var base = (window.CMS_UIR || '') + 'CmsAjax/';
        var body = new URLSearchParams();
        params = params || {};
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(base + endpoint + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : ''), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': (window.CMS_CSRF || '') },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    /* ==================================================================
     * Row-action overflow menu (⋯) — lightweight dropdown, keyboard-reachable.
     *
     * Opt-in (installOverflowMenus()) rather than always-on: only the list
     * surfaces render `[data-overflow-toggle]`, and the scroll listener below
     * is capture-phase, so there is no reason to arm it everywhere.
     * ================================================================== */
    var overflowInstalled = false;

    function closeAllOverflow(except) {
        document.querySelectorAll('.cms-overflow.cms-open').forEach(function (o) {
            if (o !== except) {
                o.classList.remove('cms-open');
                var b = o.querySelector('[data-overflow-toggle]');
                if (b) { b.setAttribute('aria-expanded', 'false'); }
            }
        });
    }

    // Menu is position:fixed (escapes the table-wrap overflow:hidden clip); anchor it
    // to the trigger and flip upward when it would run past the viewport bottom.
    function positionOverflowMenu(toggle, menu) {
        var r = toggle.getBoundingClientRect();
        menu.style.visibility = 'hidden';
        menu.style.display = 'block';
        var mh = menu.offsetHeight, mw = menu.offsetWidth;
        var left = r.right - mw;                // right-align to the trigger
        if (left < 6) { left = 6; }
        if (left + mw > window.innerWidth - 6) { left = window.innerWidth - 6 - mw; }
        var top = r.bottom + 4;
        if (top + mh > window.innerHeight - 6 && r.top - 4 - mh > 6) {
            top = r.top - 4 - mh;              // flip upward
        }
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
        menu.style.display = '';
        menu.style.visibility = '';
    }

    function installOverflowMenus() {
        if (overflowInstalled) { return; }
        overflowInstalled = true;
        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('[data-overflow-toggle]');
            if (toggle) {
                var wrap = toggle.closest('.cms-overflow');
                var willOpen = !wrap.classList.contains('cms-open');
                closeAllOverflow(wrap);
                wrap.classList.toggle('cms-open', willOpen);
                toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if (willOpen) {
                    var menu = wrap.querySelector('.cms-overflow-menu');
                    if (menu) { positionOverflowMenu(toggle, menu); }
                }
                return;
            }
            // Clicking a real menu item, or anywhere outside a menu, closes any open menu.
            // A click on some other control hosted inside a menu is deliberately left alone.
            if (e.target.closest('.cms-overflow-item') || !e.target.closest('.cms-overflow-menu')) {
                closeAllOverflow(null);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeAllOverflow(null); }
        });
        // A fixed-positioned menu would strand on scroll/resize — just close it.
        // (It closes; it does NOT re-anchor.)
        window.addEventListener('scroll', function () { closeAllOverflow(null); }, true);
        window.addEventListener('resize', function () { closeAllOverflow(null); });
    }

    /* ==================================================================
     * Bulk select plumbing for the DataTables list surfaces.
     *
     * The only thing that differs between them is the table selector, so the
     * caller passes it and gets the four helpers back. Element ids are shared
     * (cmsBulkBar / cmsBulkCount / cmsCheckAll) and resolved lazily so the
     * helper is order-independent relative to the markup.
     * ================================================================== */
    function bulkSelect(tableSelector) {
        var bulkBar = document.getElementById('cmsBulkBar');
        var bulkCount = document.getElementById('cmsBulkCount');
        var checkAll = document.getElementById('cmsCheckAll');

        // Only checkboxes present in the live DOM tbody (= current DataTables page).
        function visibleRowChecks() {
            return Array.prototype.slice.call(
                document.querySelectorAll(tableSelector + ' tbody .cms-row-check')
            );
        }
        function checkedIds() {
            return visibleRowChecks().filter(function (c) { return c.checked; })
                .map(function (c) { return c.value; });
        }
        function refreshBulkBar() {
            if (!bulkBar) { return; }
            var n = checkedIds().length;
            if (bulkCount) { bulkCount.innerHTML = '<i class="fas fa-check-square"></i>' + n + ' selected'; }
            bulkBar.classList.toggle('cms-open', n > 0);
        }
        function syncSelectAll() {
            if (!checkAll) { return; }
            var boxes = visibleRowChecks();
            var checked = boxes.filter(function (c) { return c.checked; }).length;
            checkAll.checked = boxes.length > 0 && checked === boxes.length;
            checkAll.indeterminate = checked > 0 && checked < boxes.length;
        }
        if (checkAll) {
            checkAll.addEventListener('change', function () {
                visibleRowChecks().forEach(function (c) { c.checked = checkAll.checked; });
                refreshBulkBar();
            });
        }
        // Delegate per-row checkbox changes (rows can re-render on DataTables redraw).
        document.addEventListener('change', function (e) {
            if (e.target.classList && e.target.classList.contains('cms-row-check')) {
                syncSelectAll();
                refreshBulkBar();
            }
        });

        return {
            visibleRowChecks: visibleRowChecks,
            checkedIds: checkedIds,
            refreshBulkBar: refreshBulkBar,
            syncSelectAll: syncSelectAll
        };
    }

    /* ==================================================================
     * Editor-host helpers — Cms_edit.tpl (pages) + Cms_editpost.tpl (posts).
     *
     * NOUN-FREE plumbing only. Each host keeps its own meta form, save params,
     * response handling and markup: those are genuinely disjoint (a page has
     * type/meta_description/reserved-slug rules and renders its meta into the
     * RAIL; a post has excerpt/hero/tags and renders meta inline).
     * ================================================================== */

    /* Debounced autosave. schedule() restarts the timer, cancel() stops it.
     * `enabled` is consulted at schedule time so a host can veto — the page
     * editor never autosaves an already-published page, because a save there
     * goes live instantly and must be deliberate. */
    function autosave(opts) {
        opts = opts || {};
        var delay = opts.delay || 3000;
        var timer = null;
        function cancel() { clearTimeout(timer); }
        function schedule() {
            cancel();
            if (typeof opts.enabled === 'function' && !opts.enabled()) { return; }
            timer = setTimeout(function () {
                if (typeof opts.save === 'function') { opts.save(); }
            }, delay);
        }
        return { schedule: schedule, cancel: cancel };
    }

    /* Warn on unload while the host reports unsaved changes. */
    function guardUnsaved(isDirty) {
        window.addEventListener('beforeunload', function (e) {
            if (typeof isDirty === 'function' && isDirty()) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    /* In-context preview pane: iframe load/refresh, open/close, and the
     * Desktop/Mobile device-width buttons. Element ids are shared by both hosts.
     * opts:
     *   url()         -> the (cache-busted) preview URL to load  (SAVED state)
     *   live()        -> Promise<html> for the editor's CURRENT, UNSAVED state
     *                    (E2). When present it REPLACES url(): the pane renders
     *                    what the author is typing rather than the last save.
     *   liveDelay     -> debounce, ms, for schedule() (default 600)
     *   ready()       -> true once previewing is possible at all
     *   notReadyMsg   -> toast text when the author opens it before that
     *   openPrefKey   -> localStorage key remembering "pane open?" per browser
     */
    function previewPane(opts) {
        opts = opts || {};
        var toggle     = document.getElementById('cmsPreviewToggle');
        var pane       = document.getElementById('cmsPreviewPane');
        var iframe     = document.getElementById('cmsPreviewIframe');
        var wrap       = document.getElementById('cmsPreviewFrameWrap');
        var noteEl     = document.getElementById('cmsPreviewNote');
        var closeBtn   = document.getElementById('cmsPreviewClose');
        var refreshBtn = document.getElementById('cmsPreviewRefresh');
        var grid       = document.getElementById('cmsEditorGrid');
        var loaded     = false;
        var liveFn     = (typeof opts.live === 'function') ? opts.live : null;
        var liveSeq    = 0;      // guards against an older response landing last
        var liveTimer  = null;

        function ready() { return typeof opts.ready === 'function' ? !!opts.ready() : true; }
        function isOpen() { return !!(pane && pane.classList.contains('cms-preview-open')); }

        /* A status line inside the pane. The preview must never be able to block
         * editing, so a failure is reported HERE and the form is left alone —
         * no toast storm, no disabled buttons, and the last good frame stays on
         * screen rather than being blanked. */
        function setNote(msg, kind) {
            if (!noteEl) { return; }
            noteEl.textContent = msg || '';
            noteEl.className = 'cms-preview-note' + (kind ? ' cms-preview-note-' + kind : '');
            noteEl.style.display = msg ? '' : 'none';
        }

        /* Carrying the reader's scroll offset across a reload. Without it every
         * debounced update yanks the author back to the top of the page they
         * were looking at, which on a sixteen-block page makes the live preview
         * unusable.
         *
         * The frame is sandboxed WITHOUT allow-same-origin (see the iframe in
         * Cms_edit.tpl), so it has a null origin and the parent can no longer
         * read iframe.contentWindow.scrollY or call scrollTo on it — those now
         * throw SecurityError. The offset therefore travels the only way a null
         * origin allows: the frame reports its own scroll by postMessage, and
         * the next document is built carrying the offset to restore. Same
         * behaviour, no same-origin reach.
         *
         * The listener is deliberately paranoid: it is the one thing inside the
         * admin page that the sandboxed document can talk to, so it accepts a
         * message only from THIS frame, only in the expected shape, and does
         * nothing with it but remember a number of pixels. */
        var frameY = 0;
        function onFrameScrollMessage(e) {
            if (!iframe || !e || e.source !== iframe.contentWindow) { return; }
            var y = e.data && e.data.cmsPreviewScrollY;
            if (typeof y !== 'number' || !isFinite(y)) { return; }
            frameY = y > 0 ? (y | 0) : 0;
        }
        window.addEventListener('message', onFrameScrollMessage);

        /* The parent-controlled tail appended to every preview document: restore
         * the offset, then keep reporting it. Appended AFTER the rendered page,
         * so nothing an author writes can reach around it. */
        function scrollBridge(y) {
            return '<script>(function(){var y=' + (y > 0 ? (y | 0) : 0) + ',p=0;'
                + 'function r(){try{window.scrollTo(0,y);}catch(e){}}'
                + 'if(y){r();window.addEventListener("load",r);}'
                + 'window.addEventListener("scroll",function(){if(p){return;}p=1;'
                + 'requestAnimationFrame(function(){p=0;'
                + 'try{parent.postMessage({cmsPreviewScrollY:window.scrollY||0},"*");}catch(e){}});'
                + '},{passive:true});})();<\/script>';
        }

        function loadLive() {
            var seq = ++liveSeq;
            var y = frameY;
            setNote(loaded ? 'Updating\u2026' : 'Building preview\u2026');
            // Promise.resolve().then(liveFn) rather than liveFn(): a host callback
            // that throws SYNCHRONOUSLY must land in the .catch below like any
            // other failure, not escape as an uncaught TypeError that leaves the
            // pane stuck on "Updating…".
            Promise.resolve().then(liveFn).then(function (html) {
                if (seq !== liveSeq || !iframe) { return; }   // superseded by a newer edit
                // srcdoc, not src: a POST cannot be an iframe URL, and a fresh
                // document (rather than an injected fragment) is what lets the
                // public stylesheets cascade and frontdoor.js — a parse-time
                // IIFE with no re-init hook — actually run for each preview.
                // The scroll bridge rides along on the end; `y` was read before
                // the request went out, so a slow render cannot lose the offset.
                iframe.srcdoc = html + scrollBridge(y);
                loaded = true;
                setNote('');
            }).catch(function (err) {
                if (seq !== liveSeq) { return; }
                setNote((err && err.message)
                    || 'Preview could not update. Your edits are safe \u2014 they are still in the form.', 'error');
            });
        }

        function load() {
            if (!ready() || !iframe) { return; }
            if (liveFn) { loadLive(); return; }
            iframe.src = opts.url();
            loaded = true;
        }
        // Only reload when the pane is open (or, in the saved-URL mode, already
        // loaded) — avoids fetching a preview the editor never opened, and in
        // live mode avoids re-rendering into a pane nobody is looking at.
        function refresh() {
            if (!ready() || !iframe) { return; }
            if (isOpen() || (!liveFn && loaded)) { load(); }
        }
        /* Debounced refresh, for a host that wants the preview to follow typing.
         * No-ops when there is nothing on screen to update, so an unopened pane
         * costs nothing per keystroke. */
        function schedule() {
            // Closed pane -> nothing on screen to update, so no request. (open()
            // re-renders on the way in, so reopening never shows stale content.)
            if (!liveFn || !ready() || !isOpen()) { return; }
            if (liveTimer) { clearTimeout(liveTimer); }
            liveTimer = setTimeout(function () { liveTimer = null; load(); },
                (typeof opts.liveDelay === 'number') ? opts.liveDelay : 600);
        }
        function rememberOpen(isOpenNow) {
            if (!opts.openPrefKey) { return; }
            try { localStorage.setItem(opts.openPrefKey, isOpenNow ? '1' : '0'); } catch (e) {}
        }
        function open(remember) {
            if (!ready()) { toast(opts.notReadyMsg || 'Save first to preview.', 'error'); return; }
            if (pane) { pane.classList.add('cms-preview-open'); pane.setAttribute('aria-hidden', 'false'); }
            if (grid) { grid.classList.add('cms-preview-active'); }
            if (toggle) { toggle.classList.add('cms-btn-active'); }
            if (remember !== false) { rememberOpen(true); }
            // Live mode always re-renders on open: the pane may have been closed
            // through a dozen edits, and schedule() deliberately skipped them.
            if (liveFn || !loaded) { load(); }
        }
        function close(remember) {
            if (pane) { pane.classList.remove('cms-preview-open'); pane.setAttribute('aria-hidden', 'true'); }
            if (grid) { grid.classList.remove('cms-preview-active'); }
            if (toggle) { toggle.classList.remove('cms-btn-active'); }
            if (remember !== false) { rememberOpen(false); }
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                if (toggle.disabled) { return; }
                if (isOpen()) { close(); } else { open(); }
            });
        }
        if (closeBtn) { closeBtn.addEventListener('click', close); }
        if (refreshBtn) { refreshBtn.addEventListener('click', function () { load(); }); }

        // Desktop / Mobile device-width toggle.
        Array.prototype.forEach.call(document.querySelectorAll('.cms-devbtn'), function (btn) {
            btn.addEventListener('click', function () {
                var dev = btn.getAttribute('data-device') || 'desktop';
                Array.prototype.forEach.call(document.querySelectorAll('.cms-devbtn'), function (b) {
                    b.classList.toggle('cms-devbtn-active', b === btn);
                });
                if (wrap) { wrap.setAttribute('data-device', dev); }
            });
        });

        /* The Theme page's shape, which this review holds up as the model: on a
         * window wide enough for two columns the rendered result is PRESENT, not
         * something the author has to go and ask for. 1100px is the editor's own
         * two-column threshold (see the cms-editor-haspreview media query): the
         * blocks column needs ~430px before its two-up field rows collapse, the
         * pane declares minmax(360px, .95fr), and the rail takes 220-258px.
         * Below that the pane is a toggle that stacks under the form.
         * An author who closes it keeps it closed, per browser. */
        if (liveFn && opts.openPrefKey && window.matchMedia
            && window.matchMedia('(min-width: 1100px)').matches) {
            var pref = null;
            try { pref = localStorage.getItem(opts.openPrefKey); } catch (e) {}
            // Deferred a tick ON PURPOSE. Both hosts construct the pane BEFORE
            // they boot the block engine, so opening synchronously would post an
            // empty block list and paint a blank page the author then has to
            // trigger a refresh out of. A macrotask lets the rest of the host
            // script — CmsBlockEditor.init() included — finish first.
            if (pref !== '0') {
                setTimeout(function () { if (ready()) { open(false); } }, 0);
            }
        }

        return {
            load: load, refresh: refresh, schedule: schedule,
            open: open, close: close, isOpen: isOpen
        };
    }

    /* Swap a broken <img> for a real placeholder.
     *
     * The browser's broken-image glyph is the problem this exists to solve: it
     * looks the same whether the file is genuinely gone from the media library
     * (and therefore broken on the PUBLIC site too) or merely failed to reach
     * this one request. The placeholder says "missing" and keeps the element's
     * footprint, so nothing below it jumps.
     *
     * Lifted out of Cms_media.tpl (#95), which now delegates here, so the block
     * editor's image fields get the same treatment instead of a second copy.
     *
     *   img   the <img> that errored
     *   cls   the size/shape class of the thumbnail it replaces
     *   opts  {icon, tip} — icon defaults to fa-image; tip becomes data-tip
     * Returns the placeholder node, or null when one was already applied.
     */
    function thumbFallback(img, cls, opts) {
        if (!img || img.dataset.fbApplied) { return null; }
        img.dataset.fbApplied = '1';
        opts = opts || {};
        var ph = document.createElement('div');
        ph.className = (cls || 'cms-media-thumb') + ' cms-empty-thumb cms-missing-thumb';
        var icon = document.createElement('i');
        icon.className = 'fas ' + (opts.icon || 'fa-image');
        icon.setAttribute('aria-hidden', 'true');
        ph.appendChild(icon);
        if (opts.tip) { ph.setAttribute('data-tip', opts.tip); }
        if (img.parentNode) { img.parentNode.replaceChild(ph, img); }
        return ph;
    }

    window.CmsAdmin = {
        thumbFallback: thumbFallback,
        toast: toast,
        undoableToast: undoableToast,
        post: post,
        modal: { open: openModal, close: closeModal },
        installOverflowMenus: installOverflowMenus,
        bulkSelect: bulkSelect,
        autosave: autosave,
        guardUnsaved: guardUnsaved,
        previewPane: previewPane
    };
})();
