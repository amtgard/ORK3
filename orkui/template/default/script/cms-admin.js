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
            // Clicking a menu item (or anywhere else) closes any open menu.
            if (!e.target.closest('.cms-overflow-menu')) { closeAllOverflow(null); }
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
     *   url()         -> the (cache-busted) preview URL to load
     *   ready()       -> true once the entity has an id, so preview is possible
     *   notReadyMsg   -> toast text when the author opens it before saving
     */
    function previewPane(opts) {
        opts = opts || {};
        var toggle     = document.getElementById('cmsPreviewToggle');
        var pane       = document.getElementById('cmsPreviewPane');
        var iframe     = document.getElementById('cmsPreviewIframe');
        var wrap       = document.getElementById('cmsPreviewFrameWrap');
        var closeBtn   = document.getElementById('cmsPreviewClose');
        var refreshBtn = document.getElementById('cmsPreviewRefresh');
        var grid       = document.getElementById('cmsEditorGrid');
        var loaded     = false;

        function ready() { return typeof opts.ready === 'function' ? !!opts.ready() : true; }
        function isOpen() { return !!(pane && pane.classList.contains('cms-preview-open')); }

        function load() {
            if (!ready() || !iframe) { return; }
            iframe.src = opts.url();
            loaded = true;
        }
        // Only reload when the pane is open (or already loaded) — avoids fetching a
        // preview the editor never opened.
        function refresh() {
            if (!ready() || !iframe) { return; }
            if (isOpen() || loaded) { load(); }
        }
        function open() {
            if (!ready()) { toast(opts.notReadyMsg || 'Save first to preview.', 'error'); return; }
            if (pane) { pane.classList.add('cms-preview-open'); pane.setAttribute('aria-hidden', 'false'); }
            if (grid) { grid.classList.add('cms-preview-active'); }
            if (toggle) { toggle.classList.add('cms-btn-active'); }
            if (!loaded) { load(); }
        }
        function close() {
            if (pane) { pane.classList.remove('cms-preview-open'); pane.setAttribute('aria-hidden', 'true'); }
            if (grid) { grid.classList.remove('cms-preview-active'); }
            if (toggle) { toggle.classList.remove('cms-btn-active'); }
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

        return { load: load, refresh: refresh, open: open, close: close, isOpen: isOpen };
    }

    window.CmsAdmin = {
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
