/* ORKPLE II shell: builds the Apple II monitor, runs warm-up, hosts the terminal,
   and hands the page back untouched on exit. Loaded lazily by orkple-floppy.js. */
(function () {
    'use strict';
    var O = window.Orkple = window.Orkple || {};
    var st = null;

    function reduced() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }
    function el(tag, cls, parent) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (parent) { parent.appendChild(e); }
        return e;
    }
    function clearCrt() {
        if (O._page) { O._page.clear(); } else { document.documentElement.classList.remove('orkple-crt-off', 'orkple-crt-on'); }
    }
    /* SOUND: one setting for the speaker button and the DOS SOUND command, kept per browser. */
    var SOUND_KEY = 'orkple.sound';
    function soundSaved() {
        try { return window.localStorage.getItem(SOUND_KEY) !== 'off'; } catch (e) { return true; }
    }
    // Run a registry of undo steps newest first; one failing step never stops the rest.
    function unwind(list) {
        while (list.length) {
            try { list.pop()(); } catch (e) { if (window.console) { console.error('ORKPLE cleanup step failed', e); } }
        }
    }

    O.boot = function () {
        if (st) { return; }
        var html = document.documentElement;
        var body = document.body;
        var rm = reduced();
        var saved = O._page && O._page.scroll;
        if (O._page) { O._page.scroll = null; }
        var s = st = {
            x: saved ? saved.x : window.pageXOffset,
            y: saved ? saved.y : window.pageYOffset,
            exiting: false,
            timers: [],
            term: null,
            sound: soundSaved(),
            undo: [],      // page changes, undone in finish() (or at once if boot throws)
            offs: []       // listeners, removed as soon as shutdown starts
        };
        try {
            build(s, html, body, rm);
        } catch (err) {
            /* A throw part-way through boot must not leave the page hidden and inert. */
            s.exiting = true;
            s.timers.forEach(clearTimeout);
            if (s.term) { try { s.term.destroy(); } catch (e) { /* already gone */ } }
            unwind(s.offs);
            unwind(s.undo);
            st = null;
            throw err;     // orkple-floppy.js maybeBoot powers the page back on and frees the disk
        }
    };

    function build(s, html, body, rm) {

        var ov = el('div');
        ov.id = 'orkple';
        ov.setAttribute('role', 'dialog');
        ov.setAttribute('aria-modal', 'true');
        ov.setAttribute('aria-label', 'ORKPLE II');
        var bevel = el('div', 'ok-bevel', ov);
        var glass = el('div', 'ok-glass', bevel);
        var screen = el('div', 'ok-screen', glass);
        var canvas = el('canvas', 'ok-canvas', screen);
        canvas.width = 560;
        canvas.height = 384;
        canvas.setAttribute('aria-hidden', 'true');
        el('div', 'ok-fx ok-scan', glass);
        el('div', 'ok-fx ok-vig', glass);
        el('div', 'ok-fx ok-refl', glass);
        el('div', 'ok-fx ok-flick', glass);
        el('div', 'ok-fx ok-flash', glass);
        var input = el('input', 'ok-input', glass);
        input.type = 'text';
        input.setAttribute('autocapitalize', 'characters');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('autocorrect', 'off');
        input.setAttribute('spellcheck', 'false');
        input.setAttribute('aria-label', 'ORKPLE II command line');
        var live = el('div', 'ok-sr', glass);
        live.setAttribute('role', 'log');
        live.setAttribute('aria-live', 'polite');
        live.setAttribute('aria-relevant', 'additions');
        var foot = el('div', 'ok-foot', ov);
        el('p', 'ok-badge', foot).textContent = 'ORKPLE II';
        el('div', 'ok-vents', foot).setAttribute('aria-hidden', 'true');
        var speaker = el('button', 'ok-power ok-sound', foot);
        speaker.type = 'button';
        speaker.setAttribute('aria-label', 'Sound');
        el('span', 'ok-spk', speaker).setAttribute('aria-hidden', 'true');
        el('span', '', speaker).textContent = 'Sound';
        var power = el('button', 'ok-power', foot);
        power.type = 'button';
        power.setAttribute('aria-label', 'Power off');
        el('span', 'ok-led', power).setAttribute('aria-hidden', 'true');
        el('span', '', power).textContent = 'Power';

        /* Order matters: hide the page and drop the body transform in the same
           synchronous block, before the fixed overlay joins the body. */
        html.classList.add('orkple-on');
        s.undo.push(function () { html.classList.remove('orkple-on'); });
        clearCrt();
        var kids = body.children;
        var inerted = [];
        s.undo.push(function () { inerted.forEach(function (n) { n.inert = false; }); });
        for (var i = 0; i < kids.length; i++) {
            if (!kids[i].inert) { kids[i].inert = true; inerted.push(kids[i]); }
        }
        body.appendChild(ov);
        s.undo.push(function () { if (ov.parentNode) { ov.parentNode.removeChild(ov); } });
        s.ov = ov;
        s.input = input;
        s.power = power;

        function setSound(on) {
            s.sound = !!on;
            speaker.setAttribute('aria-pressed', s.sound ? 'true' : 'false');
            try { window.localStorage.setItem(SOUND_KEY, s.sound ? 'on' : 'off'); } catch (e) { /* private window: this visit only */ }
            if (!s.sound && s.term && s.term.hush) { s.term.hush(); }
            return s.sound;
        }
        speaker.setAttribute('aria-pressed', s.sound ? 'true' : 'false');

        /* Keep the monitor above the on-screen keyboard. */
        var vv = window.visualViewport;
        function fit() {
            if (!vv) { return; }
            ov.style.setProperty('--ok-w', vv.width + 'px');
            ov.style.setProperty('--ok-h', vv.height + 'px');
            ov.style.setProperty('--ok-top', vv.offsetTop + 'px');
            ov.style.setProperty('--ok-left', vv.offsetLeft + 'px');
        }
        fit();
        function on(target, type, fn, capture) {
            target.addEventListener(type, fn, capture);
            s.offs.push(function () { target.removeEventListener(type, fn, capture); });
        }
        if (vv) { on(vv, 'resize', fit); on(vv, 'scroll', fit); }

        // The BIOS screen disables the input; Power is then the one control, so it takes focus.
        function focusInput() {
            if (s.exiting) { return; }
            var t = input.disabled ? power : input;
            try { t.focus({ preventScroll: true }); } catch (e) { t.focus(); }
        }
        function onKey(e) {
            if (e.key === 'Escape' || e.key === 'Esc') {
                e.preventDefault();
                e.stopImmediatePropagation();   // the closing ESC must not also close the page's own popovers
                O.shutdown();
            }
        }
        // Keys typed inside the monitor stop at its edge (after the terminal has them), so game keys never reach page shortcuts.
        function onOwnKey(e) { e.stopImmediatePropagation(); }
        function onBlur(e) {
            if (e.relatedTarget === power || e.relatedTarget === speaker) { return; }
            setTimeout(focusInput, 0);
        }
        function onGlass() { focusInput(); }
        function onPower() { O.shutdown(); }
        function onSpeaker(e) {
            setSound(!s.sound);
            // A pointer click hands the keyboard back to the screen; Enter/Space (detail 0) keeps focus on the switch.
            if (e && e.detail > 0) { focusInput(); }
        }
        on(document, 'keydown', onKey, true);
        on(ov, 'keydown', onOwnKey);
        on(ov, 'keypress', onOwnKey);
        on(ov, 'keyup', onOwnKey);
        on(input, 'blur', onBlur);
        on(glass, 'click', onGlass);
        on(power, 'click', onPower);
        on(speaker, 'click', onSpeaker);
        s.teardown = function () { unwind(s.offs); };

        focusInput();

        try {
            s.term = window.OrkpleTerminal.mount({
                canvas: canvas,
                input: input,
                live: live,
                power: power,
                sound: { on: function () { return s.sound; }, set: setSound },
                service: O.service,
                ui: O.ui,
                user: O.user,
                onExit: function () { O.shutdown(); },
                reducedMotion: rm
            });
        } catch (err) {
            if (window.console) { console.error('ORKPLE terminal failed to mount', err); }
            O.shutdown();
            return;
        }

        function startTerm() {
            if (s.exiting || !s.term) { return; }
            try { s.term.start(); } catch (err2) {
                if (window.console) { console.error('ORKPLE terminal failed to start', err2); }
                O.shutdown();
            }
        }
        if (rm) {
            startTerm();
        } else {
            ov.classList.add('ok-warm');
            s.timers.push(setTimeout(startTerm, 1040));
        }
    }

    O.shutdown = function () {
        var s = st;
        if (!s || s.exiting) { return; }
        s.exiting = true;
        s.timers.forEach(clearTimeout);
        s.timers = [];
        if (s.term) { try { s.term.destroy(); } catch (e) { /* already gone */ } }
        s.term = null;
        s.teardown();
        try { s.input.blur(); } catch (e2) { /* ignore */ }
        s.ov.classList.remove('ok-warm');
        s.ov.classList.add('ok-off');
        setTimeout(function () { finish(s); }, reduced() ? 160 : 940);
    };

    function finish(s) {
        unwind(s.undo);
        clearCrt();
        window.scrollTo({ top: s.y, left: s.x, behavior: 'instant' });
        var op = O._page && O._page.opener;
        var fl = op && op.isConnected ? op : document.getElementById('orkple-floppy');
        if (fl) { try { fl.focus({ preventScroll: true }); } catch (e) { fl.focus(); } }
        st = null;
        if (O._page) { O._page.powerOn(); O._page.release(); }
    }
})();
