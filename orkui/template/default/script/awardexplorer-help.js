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

    /* ── dialog ─────────────────────────────────────────────── */
    var lastFocus = null;

    function tabbable() {
        return Array.prototype.filter.call(
            overlay.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (el) { return el.offsetParent !== null || el === body; }
        );
    }

    function open() {
        lastFocus = document.activeElement;
        overlay.hidden = false;
        // Clamp the page behind, keeping its scrollbar gutter so nothing shifts.
        document.documentElement.style.overflow = 'hidden';
        document.documentElement.style.scrollbarGutter = 'stable';
        body.scrollTop = 0;
        body.focus();
        document.addEventListener('keydown', onKey, true);
    }

    function close() {
        overlay.hidden = true;
        document.documentElement.style.overflow = '';
        document.documentElement.style.scrollbarGutter = '';
        document.removeEventListener('keydown', onKey, true);
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }

    function onKey(e) {
        if (overlay.hidden) { return; }
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab') { return; }
        // Trap focus. The list is read on every press, so generated content counts.
        var items = tabbable();
        if (!items.length) { return; }
        var first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    opener.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) { close(); }   // backdrop only, never the box
    });
})();
