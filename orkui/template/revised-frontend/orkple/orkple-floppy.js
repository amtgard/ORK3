/* ORKPLE II easter egg: floppy launcher and page-level CRT power-off.
   Loaded deferred on every page. Lazily pulls orkple.css, orkple-terminal.js
   and orkple.js on first click while the page plays its CRT-off animation. */
(function () {
    'use strict';
    if (window.self !== window.top) { return; }
    var script = document.currentScript;
    if (!script || !script.src || (window.Orkple && window.Orkple.insert)) { return; }
    var base = script.src.slice(0, script.src.lastIndexOf('/') + 1);
    var ver = script.getAttribute('data-v') || '';
    var html = document.documentElement;
    var body = document.body;
    var busy = false;

    function reduced() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }
    function url(name) {
        return base + name + (ver ? '?v=' + encodeURIComponent(ver) : '');
    }

    /* Shared with orkple.js, which merges its own methods into window.Orkple. */
    var page = {
        /* Remove every trace of the page-level CRT classes and inline styles. */
        clear: function () {
            html.classList.remove('orkple-crt-off', 'orkple-crt-on');
            body.style.removeProperty('transform-origin');
            body.style.removeProperty('--ok-ly');
            body.style.removeProperty('--ok-lx');
            html.style.removeProperty('--ok-doc-h');
            if (!html.getAttribute('style')) { html.removeAttribute('style'); }
            if (!body.getAttribute('style')) { body.removeAttribute('style'); }
        },
        /* Aim the collapse at the viewport center and size the "line" to ~2px. */
        aim: function () {
            var r = body.getBoundingClientRect();
            /* A scaled body shrinks the document's scrollable area and the browser
               would clamp scrollY. Pin the height for the length of the effect. */
            html.style.setProperty('--ok-doc-h', html.scrollHeight + 'px');
            body.style.transformOrigin = (window.innerWidth / 2 - r.left) + 'px ' + (window.innerHeight / 2 - r.top) + 'px';
            body.style.setProperty('--ok-ly', Math.min(1, 2 / Math.max(r.height, 1)).toFixed(6));
            body.style.setProperty('--ok-lx', Math.min(1, 4 / Math.max(r.width, 1)).toFixed(6));
        },
        /* Quick power-on of the real page, then clean up after itself. */
        powerOn: function () {
            page.aim();
            html.classList.remove('orkple-crt-off');
            html.classList.add('orkple-crt-on');
            setTimeout(page.clear, reduced() ? 170 : 760);
        },
        release: function () {
            busy = false;
            if (btn) { btn.removeAttribute('aria-disabled'); }
        }
    };
    var O = window.Orkple = window.Orkple || {};
    O._page = page;
    /* Where the terminal's disk reads go: the JSON service and the UI route prefix. */
    O.service = script.getAttribute('data-service') || '';
    O.ui = script.getAttribute('data-ui') || '';
    /* The signed-in player's id (0 when logged out); never a token. */
    O.user = script.getAttribute('data-user') || '0';

    /* ---- Floppy button: data-floppy="0" hides it; Orkple.insert() and
       [data-orkple-insert] links (the ORK 0.1 release notes) still work ---- */
    var btn = null;
    if (script.getAttribute('data-floppy') !== '0') {
        btn = document.createElement('button');
        btn.id = 'orkple-floppy';
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Insert ORKPLE II disk');
        btn.innerHTML =
            '<svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">' +
            '<rect class="of-jacket" x="3" y="3" width="94" height="94" rx="5" fill="#1d1d21"/>' +
            '<rect x="6" y="6" width="88" height="5" rx="2" fill="#2e2e34"/>' +
            '<rect x="3" y="58" width="6" height="12" fill="#07070a"/>' +
            '<rect x="14" y="8" width="72" height="26" rx="2" fill="#f1e9d0"/>' +
            '<rect x="14" y="8" width="72" height="5" fill="#b3342a"/>' +
            '<text x="50" y="29" text-anchor="middle" font-family="Helvetica,Arial,sans-serif" font-weight="800" font-size="16" letter-spacing="2" fill="#2b2b2b">ORK</text>' +
            '<circle cx="50" cy="54" r="17" fill="#2c2c32"/>' +
            '<circle cx="50" cy="54" r="12" fill="#07070a"/>' +
            '<circle cx="50" cy="54" r="12" fill="none" stroke="#cfc7ad" stroke-width="1.6"/>' +
            '<circle cx="66" cy="43" r="2.4" fill="#07070a"/>' +
            '<rect x="43" y="73" width="14" height="22" rx="7" fill="#07070a"/>' +
            '</svg>';
        body.appendChild(btn);
        btn.addEventListener('click', insert);
    }
    O.insert = insert;
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('[data-orkple-insert]') : null;
        if (!a) { return; }
        e.preventDefault();
        insert();
    });

    /* ---- Insert the disk: load + play CRT-off in parallel, then boot ---- */
    function insert() {
        if (busy || document.documentElement.classList.contains('orkple-on')) { return; }
        busy = true;
        if (btn) { btn.setAttribute('aria-disabled', 'true'); }
        /* Whatever started the disk gets focus back on exit (orkple.js finish). */
        var ae = document.activeElement;
        page.opener = ae && ae !== body && ae !== html ? ae : btn;
        var sy = window.pageYOffset, sx = window.pageXOffset;
        page.scroll = { x: sx, y: sy };
        var animDone = false, loadDone = false, failed = false, timeout = null;

        function fail() {
            if (failed) { return; }
            failed = true;
            clearTimeout(timeout);
            page.powerOn();
            window.scrollTo({ top: sy, left: sx, behavior: 'instant' });
            busy = false;
            if (btn) { btn.removeAttribute('aria-disabled'); }
        }
        function maybeBoot() {
            if (failed || !animDone || !loadDone) { return; }
            clearTimeout(timeout);
            try {
                window.Orkple.boot();
            } catch (e) {
                if (window.console) { console.error('ORKPLE boot failed', e); }
                fail();
            }
        }

        page.aim();
        html.classList.add('orkple-crt-off');
        setTimeout(function () { animDone = true; maybeBoot(); }, reduced() ? 160 : 940);

        var ready = typeof O.boot === 'function' && window.OrkpleTerminal && document.getElementById('orkple-css');
        if (ready) {
            loadDone = true;
            return;
        }
        timeout = setTimeout(function () { cleanupLoaders(); fail(); }, 15000);
        var pending = 3, els = [];
        function cleanupLoaders() {
            els.forEach(function (el) { if (el.parentNode) { el.parentNode.removeChild(el); } });
        }
        function one() {
            pending -= 1;
            if (pending === 0 && !failed) {
                if (typeof O.boot !== 'function' || !window.OrkpleTerminal) { cleanupLoaders(); fail(); return; }
                loadDone = true;
                maybeBoot();
            }
        }
        function bad() { cleanupLoaders(); fail(); }
        var css = document.createElement('link');
        css.rel = 'stylesheet';
        css.id = 'orkple-css';
        css.href = url('orkple.css');
        css.onload = one;
        css.onerror = bad;
        els.push(css);
        ['orkple-terminal.js', 'orkple.js'].forEach(function (name) {
            var s = document.createElement('script');
            s.async = false;
            s.src = url(name);
            s.onload = one;
            s.onerror = bad;
            els.push(s);
        });
        els.forEach(function (el) { (el.tagName === 'LINK' ? document.head : document.body).appendChild(el); });
    }
})();
