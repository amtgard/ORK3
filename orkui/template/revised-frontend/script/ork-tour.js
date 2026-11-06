/*
 * OrkTour: the ORK's wrapper around driver.js (MIT, vendored in
 * script/driverjs; refresh with `npm run copy-driverjs`).
 *
 * This file is small and loads on every page. The driver.js library loads
 * only when something actually runs: a tour, a hint set, or a checklist.
 *
 *   OrkTour.register('park-profile', {
 *       title:       'Park profile basics',
 *       version:     1,                       // bump to show it again to people who saw it
 *       kind:        'tour',                  // 'tour' (always in the menu) | 'feature' (a one-off intro)
 *       activeFrom:  '2026-11-01',            // optional YYYY-MM-DD window, inclusive; outside it the
 *       activeUntil: '2026-12-31',            //   tour is hidden from the menu and never auto-starts
 *       audience:    function () { return true; },  // WHO it is for (match() is WHICH PAGE)
 *       match:       function () { return !!document.querySelector('.pk-hero'); },
 *       autoStart:   false,                   // true = run once per person the first time it matches
 *                                             //   (a 'feature' defaults to true)
 *       steps: [                              // or a function returning the array
 *           { element: '.pk-hero', popover: { title: 'Your park', description: '...' } },
 *           { element: '#pk-tab-recs', when: function () { return PkConfig.canManage; },
 *             popover: { title: 'Recommendations', description: '...' } }
 *       ],
 *       config: {}                            // any driver.js option, overrides the ORK defaults
 *   });
 *
 *   OrkTour.start('park-profile');                 // returns a Promise for the driver instance
 *   OrkTour.offer('park-profile', { title, body, onAccept });  // welcome modal; Promise for the choice
 *   OrkTour.highlight('#some-el', { title, description });
 *   OrkTour.hints({ hints: [...] });               // returns a Promise for driver.js Hints
 *   OrkTour.checklist({ id, items: [...] });       // returns a Promise for driver.js Checklist
 *
 * Every tour that is available on the page gets a link in the nav Resources menu.
 * A link carrying `ork_tour=<id>` in its query string starts that tour on load.
 *
 * "Seen" state: for a logged-in person it lives in the database. default.theme
 * emits window.OrkTourState (loggedIn, quiet, userId, profileUrl, saveUrl, progress)
 * before this file; finishing or closing a tour updates that map and POSTs to
 * saveUrl. Logged out (or no OrkTourState at all) falls back to localStorage
 * (key ork_tour_seen).
 */
(function (w, d) {
    'use strict';
    if (w.OrkTour) { return; }

    var SEEN_KEY = 'ork_tour_seen';     // localStorage, logged-out fallback
    var LATER_KEY = 'ork_tour_later';   // sessionStorage, "Maybe later" on an offer
    var tours = {};
    var order = [];
    var active = null;
    var loading = {};
    var links = {};

    // Work out where this file lives so vendor paths follow the deployment.
    var self = d.currentScript || (function () {
        var s = d.getElementsByTagName('script');
        for (var i = s.length - 1; i >= 0; i--) { if (/ork-tour\.js/.test(s[i].src)) { return s[i]; } }
        return null;
    })();
    var selfSrc = self ? self.src : '';
    var ROOT = selfSrc.replace(/script\/ork-tour\.js.*$/, '');   // .../revised-frontend/
    var BUST = (selfSrc.match(/[?&]v=([^&]+)/) || [])[1] || '';
    var VENDOR = ROOT + 'script/driverjs/';
    var THEME_CSS = ROOT + 'style/ork-tour.css';

    function withBust(url) { return BUST ? url + '?v=' + BUST : url; }

    // ---------- asset loading ----------

    // The theme sheet must come after the vendor sheets in <head> so it wins
    // ties. The offer modal can load the theme first, so vendor sheets that
    // arrive later are slotted in before it rather than appended.
    function loadCss(href) {
        if (loading[href]) { return loading[href]; }
        loading[href] = new Promise(function (resolve) {
            var l = d.createElement('link');
            l.rel = 'stylesheet';
            l.href = withBust(href);
            l.onload = l.onerror = function () { resolve(); };
            links[href] = l;
            var themeLink = href !== THEME_CSS ? links[THEME_CSS] : null;
            if (themeLink && themeLink.parentNode) {
                themeLink.parentNode.insertBefore(l, themeLink);
            } else {
                d.head.appendChild(l);
            }
        });
        return loading[href];
    }

    function loadJs(src, ready) {
        if (ready()) { return Promise.resolve(); }
        if (loading[src]) { return loading[src]; }
        loading[src] = new Promise(function (resolve, reject) {
            var s = d.createElement('script');
            s.src = withBust(src);
            s.onload = function () { ready() ? resolve() : reject(new Error('OrkTour: ' + src + ' loaded without its global')); };
            s.onerror = function () { delete loading[src]; reject(new Error('OrkTour: could not load ' + src)); };
            d.head.appendChild(s);
        });
        return loading[src];
    }

    function hasDriver() { return !!(w.driver && w.driver.js && w.driver.js.driver); }
    function hasHints() { return !!(w.driverHints && w.driverHints.hints); }
    function hasChecklist() { return !!(w.driverChecklist && w.driverChecklist.checklist); }

    function theme(vendorSheets) {
        return Promise.all(vendorSheets.map(loadCss).concat([loadCss(THEME_CSS)]));
    }

    var modules = {
        driver: function () {
            return Promise.all([theme([VENDOR + 'driver.css']), loadJs(VENDOR + 'driver.js.iife.js', hasDriver)]);
        },
        hints: function () {
            return Promise.all([theme([VENDOR + 'hints.css']), loadJs(VENDOR + 'hints.iife.js', hasHints)]);
        },
        // The checklist IIFE reads the driver global when it runs, so driver loads first.
        checklist: function () {
            return modules.driver().then(function () {
                return Promise.all([theme([VENDOR + 'checklist.css']), loadJs(VENDOR + 'checklist.iife.js', hasChecklist)]);
            });
        }
    };

    function load(name) {
        return modules[name] ? modules[name]() : Promise.reject(new Error('OrkTour: unknown module ' + name));
    }

    // ---------- seen state ----------

    function pageState() {
        var s = w.OrkTourState;
        return (s && typeof s === 'object') ? s : null;
    }
    function loggedIn() {
        var s = pageState();
        return !!(s && s.loggedIn && s.userId && s.saveUrl);
    }
    // Server says this page must stay uninterrupted (login, self-registration, QR sign-in).
    function quiet() {
        var s = pageState();
        return !!(s && s.quiet);
    }
    function today() {
        var n = new Date();
        var p = function (x) { return (x < 10 ? '0' : '') + x; };
        return n.getFullYear() + '-' + p(n.getMonth() + 1) + '-' + p(n.getDate());
    }

    function readLocal() {
        try { return JSON.parse(w.localStorage.getItem(SEEN_KEY) || '{}') || {}; } catch (e) { return {}; }
    }
    function writeLocal(map) {
        try { w.localStorage.setItem(SEEN_KEY, JSON.stringify(map)); } catch (e) { /* private mode: tours just repeat */ }
    }
    // The progress map for whoever is looking: the database copy when logged in.
    function progressMap() {
        if (loggedIn()) {
            var s = pageState();
            if (!s.progress || typeof s.progress !== 'object' || Array.isArray(s.progress)) { s.progress = {}; }
            return s.progress;
        }
        return readLocal();
    }

    function seen(id) {
        var t = tours[id];
        var v = progressMap()[id];
        return !!(t && v && (parseInt(v.v, 10) || 0) >= t.version);
    }

    // reason 'done' = the last step was reached (status completed); anything
    // else is dismissed. lastStep is the zero-based index of the furthest step shown.
    function markSeen(id, reason, lastStep) {
        var t = tours[id];
        if (!t) { return; }
        var status = reason === 'done' ? 'completed' : 'dismissed';
        var step = Math.max(0, parseInt(lastStep, 10) || 0);
        var entry = { v: t.version, status: status, at: today() };
        if (loggedIn()) {
            var s = pageState();
            progressMap()[id] = entry;
            save(s.saveUrl, { tour_id: id, version: t.version, status: status, last_step: step });
        } else {
            var map = readLocal();
            map[id] = entry;
            writeLocal(map);
        }
        clearDots(id);
    }

    // Fire-and-forget; keepalive lets it finish if the page is navigating away.
    function save(url, fields) {
        if (!w.fetch) { return; }
        var body = Object.keys(fields).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(fields[k]);
        }).join('&');
        try {
            w.fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body
            }).catch(function () { /* next page load simply offers it again */ });
        } catch (e) { /* ignore */ }
    }

    // Clears local state only (this page's copy and localStorage); a saved
    // database row is untouched.
    function resetSeen(id) {
        var stored = null;
        try { stored = w.localStorage.getItem(SEEN_KEY); } catch (e) { /* ignore */ }
        if (stored !== null) {
            var map = readLocal();
            if (id) { delete map[id]; } else { map = {}; }
            writeLocal(map);
        }
        var s = pageState();
        if (s && s.progress && typeof s.progress === 'object') {
            if (id) { delete s.progress[id]; } else { s.progress = {}; }
        }
    }

    // ---------- driver config ----------

    function isDark() { return d.documentElement.getAttribute('data-theme') === 'dark'; }
    // Arrow keys only help where there is a keyboard; touch-only devices skip the hint.
    function hasKeyboardPointer() { return !!(w.matchMedia && w.matchMedia('(hover: hover) and (pointer: fine)').matches); }

    // The first step of a multi-step tour says how to move through it from the keyboard.
    function addKeyHint(popover, o) {
        if (!popover || !popover.footer || !o || !o.driver) { return; }
        if (o.driver.getActiveIndex() !== 0 || !o.driver.hasNextStep() || !hasKeyboardPointer()) { return; }
        var hint = d.createElement('div');
        hint.className = 'ork-tour-keyhint';
        hint.textContent = 'Hint: Use the right arrow key to go forward through the tour, left to go back.';
        popover.footer.parentNode.insertBefore(hint, popover.footer);
    }

    function reducedMotion() { return !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches); }

    // A small keycap on the nav buttons, as a reminder that the arrow keys work too.
    function keycap(arrow) { return '<span class="ork-tour-key" aria-hidden="true">' + arrow + '</span>'; }

    function defaults() {
        var keys = hasKeyboardPointer();
        return {
            animate: !reducedMotion(),
            smoothScroll: !reducedMotion(),
            showProgress: true,
            progressText: '{{current}} of {{total}}',
            nextBtnText: keys ? 'Next ' + keycap('&rarr;') : 'Next',
            prevBtnText: keys ? keycap('&larr;') + ' Back' : 'Back',
            doneBtnText: keys ? 'Done ' + keycap('&rarr;') : 'Done',
            closeBtnLabel: 'Close tour',
            popoverClass: 'ork-tour',
            overlayColor: isDark() ? '#000' : '#1a202c',
            overlayOpacity: isDark() ? 0.72 : 0.55,
            stagePadding: 6,
            stageRadius: 8,
            allowKeyboardControl: true,
            // A stray click on the dimmed page must not end a tour; the X, Escape, and Done do.
            overlayClickBehavior: 'none',
            skipMissingElement: true
        };
    }

    function assign(target) {
        for (var i = 1; i < arguments.length; i++) {
            var src = arguments[i];
            if (!src) { continue; }
            for (var k in src) { if (Object.prototype.hasOwnProperty.call(src, k)) { target[k] = src[k]; } }
        }
        return target;
    }

    // A step is present if it has no element (a centered step), or its
    // element is on the page now, or it asked to wait for one to appear.
    function present(s) {
        if (!('element' in s) || s.waitForElement) { return true; }
        var el = s.element;
        if (typeof el === 'function') { try { el = el(); } catch (e) { return false; } }
        if (typeof el === 'string') { try { el = d.querySelector(el); } catch (e) { return false; } }
        return !!el;
    }

    // Resolve the step list now: run a steps() function, drop steps whose
    // when() says no or whose element is not on this page (so the progress count
    // stays honest), and strip `when` before driver.js sees it.
    function resolveSteps(t) {
        var raw = typeof t.steps === 'function' ? t.steps() : t.steps;
        return (raw || []).filter(function (s) {
            if (!s) { return false; }
            if (typeof s.when === 'function') {
                try { if (!s.when()) { return false; } } catch (e) { return false; }
            }
            return present(s);
        }).map(function (s) {
            var copy = assign({}, s);
            delete copy.when;
            return copy;
        });
    }

    function closeNavMenus() {
        var open = d.querySelectorAll('.nav-dropdown-wrap.open');
        for (var i = 0; i < open.length; i++) { open[i].classList.remove('open'); }
    }

    // ---------- public: tours ----------

    var DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

    function register(id, def) {
        if (!id || !def) { return; }
        if (!tours[id]) { order.push(id); }
        var kind = def.kind === 'feature' ? 'feature' : 'tour';
        tours[id] = {
            id: id,
            title: def.title || id,
            version: parseInt(def.version, 10) || 1,
            kind: kind,
            activeFrom: DATE_RE.test(def.activeFrom || '') ? def.activeFrom : '',
            activeUntil: DATE_RE.test(def.activeUntil || '') ? def.activeUntil : '',
            audience: typeof def.audience === 'function' ? def.audience : null,
            steps: def.steps || [],
            match: typeof def.match === 'function' ? def.match : function () { return true; },
            autoStart: 'autoStart' in def ? !!def.autoStart : kind === 'feature',
            config: def.config || {},
            onDone: def.onDone || null
        };
        renderNav();
    }

    function matches(t) {
        try { return !!t.match(); } catch (e) { return false; }
    }
    // Inside the activeFrom/activeUntil window (dates inclusive; none = always).
    function inWindow(t) {
        var now = today();
        if (t.activeFrom && now < t.activeFrom) { return false; }
        if (t.activeUntil && now > t.activeUntil) { return false; }
        return true;
    }
    function forAudience(t) {
        if (!t.audience) { return true; }
        try { return !!t.audience(); } catch (e) { return false; }
    }
    // Right person, right time: everything but the page check.
    function eligible(t) { return !!t && inWindow(t) && forAudience(t); }
    // Eligible and on a page it covers: listed in the menu, may auto-start.
    function available(t) { return eligible(t) && matches(t); }

    function list() {
        return order.map(function (id) { return tours[id]; }).filter(available);
    }

    function start(id, opts) {
        var t = tours[id];
        if (!t) { return Promise.reject(new Error('OrkTour: no tour named ' + id)); }
        opts = opts || {};
        closeNavMenus();
        closeOffer();
        starting++;
        return load('driver').then(function () {
            starting--;
        }, function (err) {
            starting--;
            throw err;
        }).then(function () {
            var steps = resolveSteps(t);
            if (!steps.length) { return null; }
            if (active && active.isActive()) { active.destroy(); }

            var reachedEnd = false;
            var furthest = 0;
            var userConfig = t.config || {};
            var cfg = assign(defaults(), userConfig, {
                steps: steps,
                onPopoverRender: function (popover, o) {
                    addKeyHint(popover, o);
                    if (userConfig.onPopoverRender) { userConfig.onPopoverRender(popover, o); }
                },
                onHighlighted: function (el, step, o) {
                    var i = o.driver.getActiveIndex();
                    if (typeof i === 'number' && i > furthest) { furthest = i; }
                    if (o.driver.isLastStep()) { reachedEnd = true; }
                    if (userConfig.onHighlighted) { userConfig.onHighlighted(el, step, o); }
                },
                onDestroyed: function (el, step, o) {
                    markSeen(id, reachedEnd ? 'done' : 'closed', furthest);
                    active = null;
                    if (userConfig.onDestroyed) { userConfig.onDestroyed(el, step, o); }
                    if (reachedEnd && t.onDone) { t.onDone(); }
                }
            });
            active = w.driver.js.driver(cfg);
            active.drive(opts.step || 0);
            return active;
        });
    }

    function highlight(element, popover, config) {
        return load('driver').then(function () {
            if (active && active.isActive()) { active.destroy(); }
            active = w.driver.js.driver(assign(defaults(), { showProgress: false }, config || {}));
            active.highlight({ element: element, popover: popover || {} });
            return active;
        });
    }

    // Starts still loading driver.js count as active, so an offer never lands on top of a tour.
    var starting = 0;
    function tourActive() { return starting > 0 || !!(active && active.isActive && active.isActive()); }

    // ---------- public: hints and checklists ----------

    function hints(config) {
        return load('hints').then(function () {
            var h = w.driverHints.hints(assign({ popoverClass: 'ork-tour', buttonText: 'Got it' }, config || {}));
            h.show();
            return h;
        });
    }

    function checklist(config) {
        return load('checklist').then(function () {
            var c = w.driverChecklist.checklist(assign({ className: 'ork-tour-checklist' }, config || {}));
            c.show();
            return c;
        });
    }

    // ---------- public: the welcome offer modal ----------

    function readLater() {
        try { return JSON.parse(w.sessionStorage.getItem(LATER_KEY) || '{}') || {}; } catch (e) { return {}; }
    }
    function setLater(id, version) {
        var map = readLater();
        map[id] = version;
        try { w.sessionStorage.setItem(LATER_KEY, JSON.stringify(map)); } catch (e) { /* ignore */ }
    }
    function laterThisSession(t) { return (parseInt(readLater()[t.id], 10) || 0) >= t.version; }

    function whatsNewOpen() { return !!d.querySelector('.wn-overlay.wn-open'); }

    // Resolves once What's New is closed (or right away if it is not open).
    function afterWhatsNew() {
        var ov = d.querySelector('.wn-overlay.wn-open');
        if (!ov) { return Promise.resolve(); }
        return new Promise(function (resolve) {
            if (!w.MutationObserver) { return; }   // never resolves: the offer waits for another page
            var mo = new w.MutationObserver(function () {
                if (!ov.classList.contains('wn-open')) { mo.disconnect(); setTimeout(resolve, 250); }
            });
            mo.observe(ov, { attributes: true, attributeFilter: ['class'] });
        });
    }

    var offerEl = null;
    var offerCleanup = null;

    function closeOffer() {
        if (offerCleanup) { offerCleanup(); }
    }

    function el(tag, cls, text) {
        var n = d.createElement(tag);
        if (cls) { n.className = cls; }
        if (text != null) { n.textContent = text; }
        return n;
    }

    /*
     * OrkTour.offer(id, opts) shows a modal inviting the person to take a tour.
     * Resolves with 'accepted' | 'later' | 'declined' | 'closed' (once-offers), or 'skipped' when it did not show
     * (already seen, "Maybe later" this session, wrong audience or window, a tour running,
     * another offer open). It waits for page boot and for the What's New modal to close.
     *
     *   opts.title, opts.body       heading and one or two plain sentences
     *   opts.icon                   FontAwesome class for the badge (default 'fas fa-route')
     *   opts.acceptText             default 'Start the tour'
     *   opts.laterText              default 'Maybe later'
     *   opts.declineText            default 'No thanks'
     *   opts.declineNote            shown after "No thanks": where to find the tour later
     *   opts.onAccept()             default: OrkTour.start(id)
     *   opts.priority               when several offers want the same page load, the highest wins (default 0)
     *   opts.once                   offered only the first time it is ever shown: recorded as soon as it opens,
     *                               no "Maybe later", and the X / Escape simply close it
     */
    // Offers asked for during one page load are batched, and at most one is shown:
    // the eligible one with the highest opts.priority (ties go to the first asked).
    // A page's own tour (priority 10) outranks the site-wide welcome (priority 0).
    var pendingOffers = [];
    var pickScheduled = false;

    function offer(id, opts) {
        opts = opts || {};
        var t = tours[id];
        if (!t) { return Promise.resolve('skipped'); }
        return new Promise(function (resolve) {
            pendingOffers.push({ t: t, opts: opts, resolve: resolve, priority: Number(opts.priority) || 0, n: pendingOffers.length });
            if (!pickScheduled) {
                pickScheduled = true;
                ready.then(afterWhatsNew).then(pickOffer);
            }
        });
    }

    function pickOffer() {
        pickScheduled = false;
        var batch = pendingOffers;
        pendingOffers = [];
        function skip(o) { o.resolve('skipped'); }
        // Offers are for signed-in people only, and never on auth or check-in pages.
        if (!loggedIn() || quiet() || offerEl || tourActive() || whatsNewOpen()) { batch.forEach(skip); return; }
        var ok = batch.filter(function (o) { return eligible(o.t) && !seen(o.t.id) && !laterThisSession(o.t); });
        batch.forEach(function (o) { if (ok.indexOf(o) === -1) { skip(o); } });
        if (!ok.length) { return; }
        ok.sort(function (x, y) { return (y.priority - x.priority) || (x.n - y.n); });
        var chosen = ok[0];
        ok.slice(1).forEach(skip);
        loadCss(THEME_CSS).then(function () {
            if (offerEl || tourActive()) { skip(chosen); return; }
            showOffer(chosen.t, chosen.opts).then(chosen.resolve);
        });
    }

    function showOffer(t, opts) {
        return new Promise(function (resolve) {
            var uid = 'ork-tour-offer-' + t.id.replace(/[^a-z0-9_-]/gi, '');
            var returnFocus = d.activeElement;

            var overlay = el('div', 'ork-tour-offer-overlay');
            var box = el('div', 'ork-tour-offer');
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            box.setAttribute('aria-labelledby', uid + '-title');
            box.setAttribute('aria-describedby', uid + '-body');

            var close = el('button', 'ork-tour-offer-close');
            close.type = 'button';
            close.setAttribute('aria-label', 'Close');
            close.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';

            var badge = el('div', 'ork-tour-offer-badge');
            badge.setAttribute('aria-hidden', 'true');
            badge.appendChild(el('i', opts.icon || 'fas fa-route'));

            var title = el('h2', 'ork-tour-offer-title', opts.title || t.title);
            title.id = uid + '-title';
            var body = el('p', 'ork-tour-offer-body', opts.body || '');
            body.id = uid + '-body';

            var actions = el('div', 'ork-tour-offer-actions');
            var accept = el('button', 'ork-tour-offer-btn ork-tour-offer-primary', opts.acceptText || 'Start the tour');
            accept.type = 'button';
            var later = el('button', 'ork-tour-offer-btn ork-tour-offer-secondary', opts.laterText || 'Maybe later');
            later.type = 'button';
            var decline = el('button', 'ork-tour-offer-link', opts.declineText || 'No thanks');
            decline.type = 'button';
            actions.appendChild(accept);
            if (!opts.once) { actions.appendChild(later); }

            box.appendChild(close);
            box.appendChild(badge);
            box.appendChild(title);
            box.appendChild(body);
            box.appendChild(actions);
            box.appendChild(decline);
            overlay.appendChild(box);

            var settled = false;
            function finish(choice) {
                if (settled) { return; }
                settled = true;
                d.removeEventListener('keydown', onKey, true);
                if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
                offerEl = null;
                offerCleanup = null;
                if (returnFocus && typeof returnFocus.focus === 'function' && d.contains(returnFocus)) {
                    try { returnFocus.focus({ preventScroll: true }); } catch (e) { returnFocus.focus(); }
                }
                resolve(choice);
            }

            // A once-offer was already recorded when it opened, so closing it just closes it.
            function maybeLater() {
                if (opts.once) { finish('closed'); return; }
                setLater(t.id, t.version);
                finish('later');
            }

            function focusables() {
                return Array.prototype.filter.call(
                    box.querySelectorAll('button, a[href], [tabindex]:not([tabindex="-1"])'),
                    function (n) { return !n.disabled && n.offsetParent !== null; }
                );
            }

            function onKey(e) {
                if (e.key === 'Escape' || e.key === 'Esc') {
                    e.preventDefault();
                    e.stopPropagation();
                    if (box.classList.contains('ork-tour-offer-ack')) { finish('declined'); } else { maybeLater(); }
                    return;
                }
                if (e.key === 'Tab') {
                    var f = focusables();
                    if (!f.length) { return; }
                    var first = f[0], last = f[f.length - 1];
                    if (!box.contains(d.activeElement)) { e.preventDefault(); first.focus(); }
                    else if (e.shiftKey && d.activeElement === first) { e.preventDefault(); last.focus(); }
                    else if (!e.shiftKey && d.activeElement === last) { e.preventDefault(); first.focus(); }
                }
            }

            accept.addEventListener('click', function () {
                finish('accepted');
                var go = typeof opts.onAccept === 'function' ? opts.onAccept : function () { return start(t.id); };
                try { go(); } catch (e) { /* the page's own handler failed; nothing to undo */ }
            });
            later.addEventListener('click', maybeLater);
            close.addEventListener('click', function () {
                if (box.classList.contains('ork-tour-offer-ack')) { finish('declined'); } else { maybeLater(); }
            });
            // Clicking the backdrop does nothing: the choice has to be one of the buttons, the X, or Escape.

            // "No thanks": record it, then say where the tour lives before closing.
            decline.addEventListener('click', function () {
                markSeen(t.id, 'dismissed', 0);
                box.classList.add('ork-tour-offer-ack');
                title.textContent = 'No problem';
                body.textContent = opts.declineNote ||
                    'If you change your mind, open Resources (the book icon in the top bar) and choose the tour.';
                actions.innerHTML = '';
                var ok = el('button', 'ork-tour-offer-btn ork-tour-offer-primary', 'Got it');
                ok.type = 'button';
                ok.addEventListener('click', function () { finish('declined'); });
                actions.appendChild(ok);
                if (decline.parentNode) { decline.parentNode.removeChild(decline); }
                ok.focus();
            });

            // once: the first sight is the one time. Record it now; taking the tour overwrites it.
            if (opts.once) { markSeen(t.id, 'dismissed', 0); }
            offerEl = overlay;
            offerCleanup = function () { finish('skipped'); };
            d.addEventListener('keydown', onKey, true);
            d.body.appendChild(overlay);
            // Next frame so the open transition runs.
            (w.requestAnimationFrame || setTimeout)(function () { overlay.classList.add('ork-tour-offer-open'); });
            accept.focus();
        });
    }

    // ---------- "new" dots: a pulsing marker on a button until its tour is taken ----------

    /*
     * OrkTour.dot(selector, tourId) marks every element matching selector with a small
     * pulsing red dot while tourId has not been taken (completed or dismissed). The dot
     * goes away as soon as the tour is recorded, on this page and every later one.
     * The tour must be registered first; its audience rule and date window apply.
     */
    function dot(selector, id) {
        var t = tours[id];
        if (!t || !forAudience(t) || !inWindow(t) || seen(id)) { return 0; }
        var els;
        try { els = d.querySelectorAll(selector); } catch (e) { return 0; }
        if (!els.length) { return 0; }
        loadCss(THEME_CSS);
        Array.prototype.forEach.call(els, function (el) {
            if (el.querySelector('.ork-tour-dot[data-ork-tour-dot="' + id + '"]')) { return; }
            var s = d.createElement('span');
            s.className = 'ork-tour-dot' + dotPlacement(el);
            s.setAttribute('data-ork-tour-dot', id);
            s.setAttribute('aria-hidden', 'true');
            el.classList.add('ork-tour-dotted');
            el.appendChild(s);
        });
        return els.length;
    }

    // Where the dot sits: just after the text of an inline link; tucked inside the
    // padding when a scrolling ancestor (a tab bar) would clip it; else the corner.
    function dotPlacement(el) {
        var cs = w.getComputedStyle(el);
        if (cs.display === 'inline') { return ' ork-tour-dot-inline'; }
        for (var p = el.parentElement, i = 0; p && i < 3; p = p.parentElement, i++) {
            var pcs = w.getComputedStyle(p);
            if (pcs.overflowX !== 'visible' || pcs.overflowY !== 'visible') { return ' ork-tour-dot-inset'; }
        }
        return '';
    }

    function clearDots(id) {
        var dots = d.querySelectorAll('.ork-tour-dot[data-ork-tour-dot="' + id + '"]');
        Array.prototype.forEach.call(dots, function (s) {
            var host = s.parentNode;
            s.parentNode.removeChild(s);
            if (host && !host.querySelector('.ork-tour-dot')) { host.classList.remove('ork-tour-dotted'); }
        });
    }

    // ---------- nav: "Tour this page" links in the Resources menu ----------

    function renderNav() {
        var slot = d.getElementById('nav-tour-links');
        if (!slot) { return; }
        var shown = list();
        slot.innerHTML = '';
        slot.hidden = !shown.length;
        if (shown.length) {
            var hr = d.createElement('div');
            hr.className = 'nav-dropdown-divider';
            slot.appendChild(hr);
        }
        shown.forEach(function (t) {
            var a = d.createElement('a');
            a.href = '#';
            a.setAttribute('data-ork-tour', t.id);
            var i = d.createElement('i');
            i.className = t.kind === 'feature' ? 'fas fa-lightbulb' : 'fas fa-route';
            a.appendChild(i);
            a.appendChild(d.createTextNode(' ' + t.title));
            slot.appendChild(a);
        });
    }

    d.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('[data-ork-tour]');
        if (!a) { return; }
        e.preventDefault();
        start(a.getAttribute('data-ork-tour'));
    });

    // ---------- page load: ?ork_tour=<id>, then the first unseen autoStart tour ----------

    // Drop ork_tour from the address so a reload does not replay the tour.
    // Textual, so the rest of the query (Route=Player/profile/1) keeps its slashes.
    function stripParam() {
        try {
            var q = w.location.search.replace(/^\?/, '').split('&').filter(function (p) {
                return p !== '' && !/^ork_tour(=|$)/.test(p);
            }).join('&');
            w.history.replaceState(w.history.state, '', w.location.pathname + (q ? '?' + q : '') + w.location.hash);
        } catch (e) { /* old browser: harmless */ }
    }

    var markReady;
    var ready = new Promise(function (resolve) { markReady = resolve; });

    function boot() {
        renderNav();
        var wanted = null;
        try { wanted = new URLSearchParams(w.location.search).get('ork_tour'); } catch (e) { /* old browser */ }
        var started = null;
        if (quiet()) {
            // Nothing starts by itself here; the Resources menu still works.
        } else if (wanted && tours[wanted] && available(tours[wanted])) {
            stripParam();
            started = start(wanted);
        } else if (!whatsNewOpen()) {
            // The What's New modal gets the first look; tours wait for another page load.
            var next = list().filter(function (t) { return t.autoStart && !seen(t.id); })[0];
            if (next) { started = start(next.id); }
        }
        // Offers wait until any tour this load starts is on screen.
        (started || Promise.resolve()).then(markReady, markReady);
    }

    if (d.readyState === 'complete') {
        setTimeout(boot, 0);
    } else {
        // After load, so page scripts have registered their tours and laid out the DOM.
        w.addEventListener('load', function () { setTimeout(boot, 300); });
    }

    w.OrkTour = {
        register: register,
        start: start,
        offer: offer,
        list: list,
        highlight: highlight,
        hints: hints,
        checklist: checklist,
        load: load,
        seen: seen,
        markSeen: markSeen,
        reset: resetSeen,
        active: function () { return active; },
        dot: dot,
        boot: boot
    };
})(window, document);
