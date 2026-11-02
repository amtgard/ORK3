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
            inerted: [],
            term: null
        };

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
        var foot = el('div', 'ok-foot', ov);
        el('p', 'ok-badge', foot).textContent = 'ORKPLE II';
        el('div', 'ok-vents', foot).setAttribute('aria-hidden', 'true');
        var power = el('button', 'ok-power', foot);
        power.type = 'button';
        power.setAttribute('aria-label', 'Power off');
        el('span', 'ok-led', power).setAttribute('aria-hidden', 'true');
        el('span', '', power).textContent = 'Power';

        /* Order matters: hide the page and drop the body transform in the same
           synchronous block, before the fixed overlay joins the body. */
        html.classList.add('orkple-on');
        clearCrt();
        var kids = body.children;
        for (var i = 0; i < kids.length; i++) {
            if (!kids[i].inert) { kids[i].inert = true; s.inerted.push(kids[i]); }
        }
        body.appendChild(ov);
        s.ov = ov;
        s.input = input;
        s.power = power;

        /* Keep the monitor above the on-screen keyboard. */
        var vv = window.visualViewport;
        function fit() {
            if (!vv) { return; }
            ov.style.setProperty('--ok-h', vv.height + 'px');
            ov.style.setProperty('--ok-top', vv.offsetTop + 'px');
            ov.style.setProperty('--ok-left', vv.offsetLeft + 'px');
        }
        fit();
        if (vv) { vv.addEventListener('resize', fit); vv.addEventListener('scroll', fit); }

        function focusInput() {
            if (!s.exiting) { try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); } }
        }
        function onKey(e) {
            if (e.key === 'Escape' || e.key === 'Esc') {
                e.preventDefault();
                O.shutdown();
            }
        }
        function onBlur(e) {
            if (e.relatedTarget === power) { return; }
            setTimeout(focusInput, 0);
        }
        function onGlass() { focusInput(); }
        function onPower() { O.shutdown(); }
        document.addEventListener('keydown', onKey, true);
        input.addEventListener('blur', onBlur);
        glass.addEventListener('click', onGlass);
        power.addEventListener('click', onPower);
        s.teardown = function () {
            document.removeEventListener('keydown', onKey, true);
            input.removeEventListener('blur', onBlur);
            glass.removeEventListener('click', onGlass);
            power.removeEventListener('click', onPower);
            if (vv) { vv.removeEventListener('resize', fit); vv.removeEventListener('scroll', fit); }
        };

        focusInput();

        try {
            s.term = window.OrkpleTerminal.mount({
                canvas: canvas,
                input: input,
                live: live,
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
    };

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
        var html = document.documentElement;
        if (s.ov.parentNode) { s.ov.parentNode.removeChild(s.ov); }
        s.inerted.forEach(function (n) { n.inert = false; });
        html.classList.remove('orkple-on');
        clearCrt();
        window.scrollTo({ top: s.y, left: s.x, behavior: 'instant' });
        var op = O._page && O._page.opener;
        var fl = op && op.isConnected ? op : document.getElementById('orkple-floppy');
        if (fl) { try { fl.focus({ preventScroll: true }); } catch (e) { fl.focus(); } }
        st = null;
        if (O._page) { O._page.powerOn(); O._page.release(); }
    }
})();
