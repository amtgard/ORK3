/* Award Explorer — Help guide dialog.
 *
 * Markup: #ae-help in Reports_awardexplorer.tpl (static prose), opened by
 * #ae-help-open. The criteria reference (#ae-help-ref) is generated here from
 * window.AE.registry — the same registry the rule picker reads — so it cannot
 * drift from the builder. Registry text only ever reaches the DOM through
 * textContent.
 *
 * Population Explorer has its own, larger version of this dialog
 * (populationexplorer-help.js). The two are not shared: that one is wired to
 * #pe-* ids and window.PE, and generalising a dialog on a report that shipped
 * yesterday is not worth the risk. If a third explorer appears, extract it then
 * rather than writing this a third time.
 */
(function () {
    'use strict';

    var AE = window.AE;
    var overlay = document.getElementById('ae-help');
    var opener = document.getElementById('ae-help-open');
    if (!AE || !AE.registry || !overlay || !opener) { return; }

    var body = document.getElementById('ae-help-body');
    var title = document.getElementById('ae-help-title');
    var closeBtn = document.getElementById('ae-help-close');
    // A <body> child, so no ancestor's transform or stacking context can trap the
    // fixed overlay — the same trap the award picker's dropdown hits.
    document.body.appendChild(overlay);

    /* ── criteria reference, generated ──────────────────────── */
    (function buildReference() {
        var dl = document.getElementById('ae-help-ref');
        if (!dl) { return; }
        var crit = AE.registry.criteria || {};
        var groups = {};
        Object.keys(crit).forEach(function (id) {
            // Hidden criteria are not offered in the builder, so documenting them
            // would only describe filters nobody can add.
            if (crit[id].hidden) { return; }
            var g = crit[id].group || 'Other';
            (groups[g] = groups[g] || []).push(id);
        });
        Object.keys(groups).forEach(function (g) {
            var h = document.createElement('dt');
            h.className = 'ae-help-refgroup';
            h.textContent = g;
            dl.appendChild(h);
            groups[g].forEach(function (id) {
                var dt = document.createElement('dt');
                dt.textContent = crit[id].label;
                var dd = document.createElement('dd');
                dd.textContent = crit[id].note || '';
                dl.appendChild(dt);
                dl.appendChild(dd);
            });
        });
    })();

    /* ── dialog ────────────────────────────── */
    var isOpen = false;

    function tabbable() {
        return Array.prototype.filter.call(
            overlay.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (el) { return el.offsetParent !== null || el === body; }
        );
    }

    function open() {
        if (isOpen) { return; }
        isOpen = true;
        var root = document.documentElement;
        // A classic scrollbar takes space: pad its width back while locked so nothing
        // shifts. Padding, not scrollbar-gutter — a stable gutter narrows the viewport
        // and the fixed backdrop would leave that strip undimmed.
        root.style.setProperty('--ae-help-gutter', Math.max(0, window.innerWidth - root.clientWidth) + 'px');
        overlay.hidden = false;
        root.classList.add('ae-help-lock');
        opener.setAttribute('aria-expanded', 'true');
        body.scrollTop = 0;
        void overlay.offsetWidth;            // start the fade from the hidden state
        overlay.classList.add('is-open');
        // The title, not the body: focusing the scroll container draws a ring around
        // the whole guide, which reads as an error state.
        title.focus();
        document.addEventListener('keydown', onKey, true);
    }

    function close() {
        if (!isOpen) { return; }
        isOpen = false;
        overlay.classList.remove('is-open');
        overlay.hidden = true;
        document.documentElement.classList.remove('ae-help-lock');
        document.documentElement.style.removeProperty('--ae-help-gutter');
        opener.setAttribute('aria-expanded', 'false');
        document.removeEventListener('keydown', onKey, true);
        opener.focus();
    }

    function onKey(e) {
        if (!isOpen) { return; }
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab') { return; }
        // Trap focus. The list is read on every press, so generated content counts.
        var items = tabbable();
        if (!items.length) { return; }
        var first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    // Contents: scroll the dialog body to the section rather than letting the anchor
    // jump, which would leave #ae-h-... sitting in the page's URL.
    var toc = overlay.querySelector('.ae-help-toc');
    if (toc) {
        toc.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a[href^="#"]') : null;
            if (!a) { return; }
            e.preventDefault();
            var t = document.getElementById(a.getAttribute('href').slice(1));
            if (!t || !body.contains(t)) { return; }
            body.scrollTop += t.getBoundingClientRect().top - body.getBoundingClientRect().top - 12;
            try { t.focus({ preventScroll: true }); } catch (err) { t.focus(); }
        });
    }

    opener.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) { close(); }   // backdrop only, never the box
    });
})();
