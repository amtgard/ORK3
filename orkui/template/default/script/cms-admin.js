/* ==========================================================================
 * cms-admin.js — shared CMS admin helpers.
 *
 * Single source of truth for the three helpers that used to be copy-pasted,
 * near-verbatim, across every CMS admin surface (Cms_index / posts / media /
 * nav / sites / dashboard / theme + cms/_block_editor.tpl):
 *
 *   CmsAdmin.toast(msg, kind)        transient status toast
 *   CmsAdmin.modal.open(el)          add .cms-open
 *   CmsAdmin.modal.close(el)         remove .cms-open  (backdrop / [data-close-modal] / Esc handled here)
 *   CmsAdmin.post(endpoint, params)  urlencoded POST → JSON, appends window.CMS_SCOPE
 *                                    and the X-CSRF-Token (window.CMS_CSRF) header
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

    function openModal(el) {
        if (!el) { return; }
        // Remember the opener so focus can be restored on close.
        el._cmsOpener = document.activeElement;
        el.classList.add('cms-open');
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

    window.CmsAdmin = {
        toast: toast,
        post: post,
        modal: { open: openModal, close: closeModal }
    };
})();
