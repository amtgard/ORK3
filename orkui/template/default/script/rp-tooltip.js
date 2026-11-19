/* =====================================================================
   rp-tooltip — instant tooltips for the shared .rp-* tool-page chrome.

   Any element carrying data-tip gets a tooltip on hover or keyboard
   focus. One shared node, appended to <body> and position:fixed, so it
   escapes the clipping ancestor: .rp-table-area is overflow-x:auto,
   which per CSS makes the vertical axis compute to auto as well — an
   in-flow ::after is clipped above the first row and adds permanent
   scroll overflow below the last one.

   Never use a native title= (see the no-native-dialogs house rule): the
   OS hover delay is exactly what this replaces.
   ===================================================================== */
(function () {
    'use strict';

    if (window.RpTooltip) { return; }   // idempotent: two pages may both include this

    var GAP  = 6;      // px between the button and the tip
    var EDGE = 8;      // px minimum clearance from the viewport edge
    var tip  = null;
    var host = null;   // element the visible tip belongs to

    function node() {
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'rp-tip';
            tip.setAttribute('role', 'tooltip');
            document.body.appendChild(tip);
        }
        return tip;
    }

    function place(el) {
        var t = node();
        var r = el.getBoundingClientRect();
        var w = t.offsetWidth;
        var h = t.offsetHeight;

        // Right-align to the button — these live in a right-hand Actions
        // column, so extending leftward is what keeps them on screen.
        var left = r.right - w;
        if (left < EDGE) { left = EDGE; }
        if (left + w > window.innerWidth - EDGE) { left = Math.max(EDGE, window.innerWidth - EDGE - w); }

        // Prefer below; flip above when the viewport bottom is closer than
        // the tip is tall. Fixed positioning means the card's overflow is
        // no longer what decides this — the viewport is.
        var top = r.bottom + GAP;
        if (top + h > window.innerHeight - EDGE && r.top - GAP - h >= EDGE) {
            top = r.top - GAP - h;
        }

        t.style.left = Math.round(left) + 'px';
        t.style.top  = Math.round(top) + 'px';
    }

    function show(el) {
        var text = el.getAttribute('data-tip');
        if (!text) { return; }
        var t = node();
        host = el;
        t.textContent = text;
        t.style.left = '-9999px';   // measure off-screen before placing
        t.style.top  = '-9999px';
        t.classList.add('rp-tip-on');
        place(el);
    }

    function hide() {
        host = null;
        if (tip) { tip.classList.remove('rp-tip-on'); }
    }

    function target(e) {
        return e.target && e.target.closest ? e.target.closest('[data-tip]') : null;
    }

    document.addEventListener('mouseover', function (e) {
        var el = target(e);
        if (el && el !== host) { show(el); }
        else if (!el && host) { hide(); }
    });
    document.addEventListener('mouseout', function (e) {
        var el = target(e);
        if (el && el === host && !el.contains(e.relatedTarget)) { hide(); }
    });
    document.addEventListener('focusin',  function (e) { var el = target(e); if (el) { show(el); } });
    document.addEventListener('focusout', function ()  { hide(); });
    document.addEventListener('keydown',  function (e) { if (e.key === 'Escape') { hide(); } });

    // The row lives in a scrollable card; a moved button must not leave a
    // stale tip floating over the page.
    window.addEventListener('scroll', function () { if (host) { place(host); } }, true);
    window.addEventListener('resize', hide);

    window.RpTooltip = { show: show, hide: hide, reposition: function () { if (host) { place(host); } } };
})();
