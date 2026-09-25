/* ==========================================================================
   survey-tip.js — the survey module's one data-tip tooltip engine.

   Loaded by Survey_index, Survey_build, Survey_take and Survey_results. Every
   [data-tip] on those pages is shown through ONE position:fixed node on
   <body> (the .sv-tip rule in survey.css): a CSS ::after tip is clipped by
   scrolling table areas and grows their scroll height even while invisible.
   The tip follows hover and keyboard focus, is kept on screen, and borrows
   aria-describedby while it shows (putting back any the control had).

   Disabled controls (the builder's locked structural buttons) fire no mouse
   events in most browsers, so hover is read from a document-level pointermove
   and resolved with document.elementFromPoint(), which still hits a disabled
   button; closest('[data-tip]') then finds its tip.
   ========================================================================== */
(function () {
    'use strict';
    if (window.SvTipReady) { return; }
    window.SvTipReady = true;

    var tipEl = null, tipFor = null;
    // Touch taps show a tip for a moment (see the touch block below).
    var tipTapAt = 0, tipTapTimer = null;

    /* A control whose visible label already says it (the index table's row
       buttons in the card layout) gets no tip: it would just repeat the label. */
    function tipTarget(node) {
        var t = node && node.closest ? node.closest('[data-tip]') : null;
        if (!t || !t.getAttribute('data-tip')) { return null; }
        var lbl = t.querySelector('.sv-row-btn-label');
        return (lbl && lbl.getBoundingClientRect().width > 1) ? null : t;
    }

    function tipShow(target) {
        if (!tipEl) {
            tipEl = document.createElement('div');
            tipEl.className = 'sv-tip';
            tipEl.id = 'sv-tip';
            tipEl.setAttribute('role', 'tooltip');
            document.body.appendChild(tipEl);
        }
        if (tipFor && tipFor !== target) { tipRestore(tipFor); }
        if (tipFor !== target) { target.setAttribute('data-sv-own-desc', target.getAttribute('aria-describedby') || ''); }
        tipFor = target;
        tipEl.textContent = target.getAttribute('data-tip');
        tipEl.hidden = false;
        target.setAttribute('aria-describedby', 'sv-tip');
        var r = target.getBoundingClientRect();
        var w = tipEl.offsetWidth, h = tipEl.offsetHeight;
        // Centred over the control, clamped on-screen; below it when there is
        // no room above.
        var left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
        var top = r.top - h - 8;
        if (top < 8) { top = Math.min(r.bottom + 8, window.innerHeight - h - 8); }
        tipEl.style.left = Math.round(Math.max(8, left)) + 'px';
        tipEl.style.top = Math.round(Math.max(8, top)) + 'px';
    }

    // Put back the description a control had before the tip borrowed it (the
    // index's held-results button describes itself with its wait note).
    function tipRestore(el) {
        var own = el.getAttribute('data-sv-own-desc');
        if (own) { el.setAttribute('aria-describedby', own); } else { el.removeAttribute('aria-describedby'); }
        el.removeAttribute('data-sv-own-desc');
    }

    function tipHide() {
        if (tipTapTimer) { clearTimeout(tipTapTimer); tipTapTimer = null; }
        tipTapAt = 0;
        if (tipFor) { tipRestore(tipFor); }
        tipFor = null;
        if (tipEl) { tipEl.hidden = true; }
    }

    function tipHover(e) {
        if (e.pointerType === 'touch') { return; }
        var node = (document.elementFromPoint && typeof e.clientX === 'number')
            ? document.elementFromPoint(e.clientX, e.clientY) : null;
        var t = tipTarget(node || e.target);
        if (t && (t !== tipFor || (tipEl && tipEl.hidden))) { tipShow(t); } else if (!t && tipFor) { tipHide(); }
    }

    document.addEventListener('mouseover', tipHover);
    // pointermove reaches the document over disabled controls too, and after a
    // scroll hid the tip under a still pointer the next movement restores it.
    document.addEventListener('pointermove', tipHover, { passive: true });
    document.addEventListener('focusin', function (e) {
        var t = tipTarget(e.target);
        if (t) { tipShow(t); } else if (tipFor) { tipHide(); }
    });
    // A tap on a control that cannot take focus (every disabled one, and the
    // chip/flag spans) blurs whatever had focus, and that focusout would kill
    // the tip the same gesture just showed. Leave a freshly tapped tip alone.
    document.addEventListener('focusout', function () {
        if (tipTapAt && Date.now() - tipTapAt < 1000) { return; }
        tipHide();
    });

    /* Touch. There is no hover on a phone and a DISABLED control cannot take
       focus, so without this the locked builder's "Locked: ..." explanations
       and the icon-only action tips have no reading at all. A tap shows the
       tip for its control and lets the tap through unchanged — the control
       still activates — and the tip goes on the next tap elsewhere, on scroll
       or after a few seconds. The point is resolved with elementFromPoint for
       the same reason hover is: disabled controls swallow their own events. */
    function tipTap(x, y) {
        var t = tipTarget(document.elementFromPoint ? document.elementFromPoint(x, y) : null);
        if (!t) { if (tipFor) { tipHide(); } return; }
        tipShow(t);
        tipTapAt = Date.now();
        if (tipTapTimer) { clearTimeout(tipTapTimer); }
        tipTapTimer = setTimeout(tipHide, 4000);
    }
    /* Held ~120ms before showing, and cancelled by a finger that moves: a
       scroll starts with a pointerdown too, and on the locked builder almost
       everything under a thumb carries a tip, so showing immediately blinked a
       tooltip at the start of most scrolls. */
    var tipTapPending = null;
    function tipTapSoon(x, y) {
        if (tipTapPending) { clearTimeout(tipTapPending); }
        tipTapPending = setTimeout(function () { tipTapPending = null; tipTap(x, y); }, 120);
    }
    function tipTapCancel() {
        if (tipTapPending) { clearTimeout(tipTapPending); tipTapPending = null; }
    }
    document.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'touch') { tipTapSoon(e.clientX, e.clientY); }
    }, { passive: true });
    document.addEventListener('pointermove', function (e) {
        if (e.pointerType === 'touch') { tipTapCancel(); }
    }, { passive: true });
    document.addEventListener('touchmove', tipTapCancel, { passive: true });
    // Fallback for browsers without pointer events; harmless alongside them
    // (same point, same target, so the tip just stays put).
    document.addEventListener('touchstart', function (e) {
        var t = e.touches && e.touches[0];
        if (t) { tipTapSoon(t.clientX, t.clientY); }
    }, { passive: true });

    // Clicking a button or link acts (opens a modal, copies a link), so its tip
    // goes; tapping a focusable tip holder (a results badge) keeps its tip.
    // The click that follows a tap-to-show is that same tap, so it leaves the
    // freshly shown tip alone — the timeout and the next tap still clear it.
    document.addEventListener('click', function (e) {
        if (tipTapAt && Date.now() - tipTapAt < 1000) { tipTapAt = 0; return; }
        var t = tipTarget(e.target);
        if (!t || t.closest('button, a, [role="button"]')) { tipHide(); }
    });
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && tipFor) { tipHide(); }
    });
    // Tabbing to an off-screen control scrolls it into view, and that scroll
    // lands after focusin: follow the focused control instead of dropping its
    // tip. A hover tip just hides.
    function tipReflow() {
        if (tipFor && document.activeElement === tipFor && tipTarget(tipFor)) { tipShow(tipFor); } else { tipHide(); }
    }
    window.addEventListener('scroll', tipReflow, true);
    window.addEventListener('resize', tipReflow);
})();
