/**
 * The hand picker, shared by every Scrollery surface that offers one.
 *
 * A "hand" is a typeface pair for a certificate — a display face for the recipient
 * and the order line, a text companion for everything in .Body. The vocabulary is
 * system/lib/ork3/data/scroll-recipes.php ('hands'); the faces are declared in
 * style/scrollery.css section 1a and selected by [data-scroll-face] in 1a-ii.
 *
 * WHY THIS IS A FILE AND NOT FOUR COPIES.
 *
 * Four surfaces need the same control and they need it to mean the same thing:
 *
 *   Scrollery_render.tpl   the preview/print page — swaps the attribute in place
 *   Scrollery_create.tpl   the composer — swaps it on the preview stage
 *   Scrollery_mine.tpl     a list of View/Print links — decorates their URLs
 *   Scrollery_bulk.tpl     a print run — decorates the run URL
 *
 * The first two re-letter something already on the page; the last two hand the
 * choice to a page that has not loaded yet. What they share is the vocabulary, the
 * remembered preference and the URL rule, and those are what live here.
 *
 * The preference is per BROWSER, not per award. A hand is how one officer likes
 * their certificates to read, and it is deliberately not stored on the award: the
 * same grant reprinted next year by a different herald should print in THAT
 * herald's hand, not in one somebody chose in 2026.
 */
(function (global, doc) {
    'use strict';

    var KEY = 'sc-face';

    /* Filled from the server's own list by init(), so this file never restates the
       vocabulary. Until then everything falls back to the default, which is the
       behaviour a surface that forgets to call init() should get. */
    var keys = [];
    var fallback = 'chancery';

    function known(face) {
        return !!face && keys.indexOf(face) !== -1;
    }

    /* The remembered hand, or the default. Never throws: localStorage is absent in
       some embeddings and throws outright in a browser set to block site data, and a
       page that dies over a font preference would be a far worse bug than one that
       prints in Chancery. */
    function get() {
        try {
            var v = global.localStorage.getItem(KEY);
            return known(v) ? v : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function set(face) {
        if (!known(face)) { return fallback; }
        try { global.localStorage.setItem(KEY, face); } catch (e) { /* see get() */ }
        return face;
    }

    /*
     * Put `face` into a URL's query string.
     *
     * Edited as TEXT rather than through URLSearchParams, which re-serialises every
     * parameter it did not touch: `Route=Scrollery/render&awards=1,2` came back as
     * `Route=Scrollery%2Frender&awards=1%2C2`. PHP decodes that to the same values,
     * so it worked — but these URLs get copied into tickets and mail, and a route
     * that reads as %2F is one nobody can check at a glance. Only `face` is ours.
     */
    function setParam(url, param, value) {
        var hash = '';
        var h = url.indexOf('#');
        if (h !== -1) { hash = url.slice(h); url = url.slice(0, h); }

        var re = new RegExp('([?&]' + param + '=)[^&]*');
        var out = re.test(url)
            ? url.replace(re, '$1' + value)
            : url + (url.indexOf('?') === -1 ? '?' : '&') + param + '=' + value;

        return out + hash;
    }

    function decorate(url, face) {
        return known(face) ? setParam(url, 'face', face) : url;
    }

    /*
     * Draw the closed <select> in the face it is showing.
     *
     * The open list is a specimen sheet — each <option> carries its own font-family
     * from the template — but a closed <select> renders the SELECTED option in the
     * select's font, not the option's, so without this the one hand the officer has
     * actually chosen is the only one they cannot see. Read off the option rather
     * than from a second copy of the vocabulary here.
     *
     * Chrome and Firefox honour font-family on an <option>; Safari and the mobile
     * pickers do not. This is the same deal: it either works or the label still reads
     * as the font's own name.
     */
    function familyOf(option) {
        var fam = option && option.getAttribute('data-face-family');
        return fam ? "'" + fam + "', serif" : '';
    }

    function paint(select) {
        var opt = select && select.selectedOptions && select.selectedOptions[0];
        var fam = familyOf(opt);
        /* One face, the chosen one. The other nine wait for loadSpecimens(). An option
           with NO family (the signature picker's "Match the lettering") clears it, so
           moving back to it does not leave the previous face on the closed control. */
        select.style.fontFamily = fam;
    }

    /*
     * Pull in the faces the picker is a specimen of — once, on first contact.
     *
     * Each <option> names its family in data-face-family and NOT in a style, which is
     * the whole mechanism: a font-family in the markup is a reference, and Chrome
     * fetches every referenced face on page load whatever this file does about it.
     * Measured — 10 of 10 display faces fetched before the officer touched the
     * control. Holding the family as data until first contact is what actually defers
     * them; the JS below is what turns data into a reference.
     *
     * On interaction rather than on load, because the whole set is about 400 KB of
     * woff2 — BlackChancery 22, MedievalSharp 54, Pirata One 13, Cinzel 27, Grenze
     * Gotisch 33, Uncial Antiqua 26, Fondamento 33, EB Garamond 102, Cormorant Unicase
     * 58, Metamorphous 36 — and an officer who never opens the list should not pay for
     * it. `mine` and `bulk` are ordinary CRM pages that happen to carry the control;
     * they are not print surfaces and have no font budget of their own.
     *
     * pointerenter and focus both fire BEFORE the list opens, which is what gives the
     * fetch a head start; pointerdown is the fallback for a direct click with no hover
     * (touch). Whichever lands first wins and the rest are no-ops.
     */
    function loadSpecimens(select, items) {
        if (select.hasAttribute('data-sc-specimens')) { return; }
        select.setAttribute('data-sc-specimens', '1');
        adoptFaces();

        for (var i = 0; i < select.options.length; i++) {
            var fam = familyOf(select.options[i]);
            if (!fam) { continue; }
            /* Both the native <option> (still there, still the value holder, and what
               a browser without the enhancement shows) and the listbox row that
               replaced it on screen. */
            select.options[i].style.fontFamily = fam;
            if (items && items[i]) { items[i].style.fontFamily = fam; }
            if (doc.fonts && doc.fonts.load) {
                /* A size is required by the shorthand this API parses; any size loads
                   the same file. Failures are swallowed — a specimen that cannot be
                   drawn is still a legible label. */
                doc.fonts.load('16px ' + fam).catch(function () {});
            }
        }
    }

    /*
     * Adopt the @font-face rules a page carries in an inert <template>.
     *
     * On FIRST CONTACT, not on load. "Declaring a face costs nothing, only
     * referencing it fetches" is the theory; it is not what Chrome does here.
     * Measured on `mine`: adopting these rules at load time pulled all ten files,
     * 403 KB, before the officer went near the control — the same eager fetch that
     * putting font-family in the markup caused. Adopting on contact holds it at 0.
     *
     * The cost of that is small and worth naming: on `mine` and `bulk` the CLOSED
     * control shows its label in the UI font until the picker is first touched, and
     * in its own face after. The composer and the print surface get these families
     * from scrollery.css, so their closed control is a specimen from the start.
     *
     * Only `mine` and `bulk` carry the template; the composer and the print surface
     * get the same families from scrollery.css and there is nothing here to adopt.
     */
    function adoptFaces() {
        var tpl = doc.getElementById('sc-face-fonts');
        if (!tpl || !tpl.content) { return; }
        doc.head.appendChild(tpl.content.cloneNode(true));
        tpl.remove();
    }

    /* Arm the specimen fetch. Separate from bind() because the render page's
       explicit-&face= path wires its own change handler and never calls bind(). */
    function armSpecimens(select) {
        if (!select) { return; }
        var arm = function () { loadSpecimens(select); };
        select.addEventListener('pointerenter', arm, { once: true });
        select.addEventListener('focus', arm, { once: true });
        select.addEventListener('pointerdown', arm, { once: true });
    }

    /* ----------------------------------------------------------------------
     * enhance(select) — replace the native control with a listbox.
     *
     * A <select> cannot render its options in ten different faces: macOS Chrome
     * and Safari draw the popup as a native AppKit menu and drop font-family on
     * <option> on the floor. That is the entire feature, so the control has to be
     * ours.
     *
     * The <select> is not removed. It stays as the value holder and keeps
     * dispatching `change`, so bind() below, the URL decorators on `mine` and
     * `bulk`, and anything else reading sel.value go on working untouched — the
     * same contract revised.js's awInitPicker() keeps for the award dropdown.
     *
     * Progressive enhancement: if this never runs, the native <select> is still
     * there, still labelled with the font names, still works.
     * -------------------------------------------------------------------- */

    var openMenu = null;

    function closeOpen() {
        if (openMenu) { openMenu(); }
    }

    doc.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeOpen(); }
    });
    doc.addEventListener('pointerdown', function (e) {
        if (openMenu && !e.target.closest('.sc-hand')) { closeOpen(); }
    });

    /* The band of the window, in viewport px, that an absolutely placed child of `el`
       can be seen in: the window itself, narrowed by every ancestor whose overflow
       clips. */
    function visibleRoom(el) {
        var top = 0;
        var bottom = global.innerHeight;
        for (var n = el.parentElement; n && n !== doc.documentElement; n = n.parentElement) {
            if (global.getComputedStyle(n).overflowY === 'visible') { continue; }
            var r = n.getBoundingClientRect();
            top = Math.max(top, r.top);
            bottom = Math.min(bottom, r.bottom);
        }
        return { top: top, bottom: bottom };
    }

    function enhance(select) {
        if (!select || select.hasAttribute('data-sc-enhanced')) { return; }
        if (!doc.createElement('div').closest) { return; }   /* very old browser: keep the <select> */
        select.setAttribute('data-sc-enhanced', '1');

        var wrap = doc.createElement('div');
        wrap.className = 'sc-hand';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.style.display = 'none';

        var id = 'sc-hand-menu-' + Math.random().toString(36).slice(2, 8);

        var trigger = doc.createElement('button');
        trigger.type = 'button';
        trigger.className = 'sc-hand-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        /* The <select> keeps its own <label for>, which no longer points at anything
           focusable, so the accessible name is carried over explicitly. */
        var label = select.id ? doc.querySelector('label[for="' + select.id + '"]') : null;
        if (label) { trigger.setAttribute('aria-label', label.textContent.trim()); }
        trigger.innerHTML = '<span class="sc-hand-label"></span><span class="sc-hand-caret" aria-hidden="true">▾</span>';
        wrap.appendChild(trigger);

        var menu = doc.createElement('ul');
        menu.className = 'sc-hand-menu';
        menu.id = id;
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;
        wrap.appendChild(menu);

        var items = [];
        for (var i = 0; i < select.options.length; i++) {
            var o = select.options[i];
            var li = doc.createElement('li');
            li.className = 'sc-hand-opt';
            li.id = id + '-' + i;
            li.setAttribute('role', 'option');
            li.setAttribute('data-value', o.value);
            /* The family is applied by loadSpecimens(), not here — see its comment on
               why holding it as data until first contact is what defers the fetch. */
            li.setAttribute('data-face-family', o.getAttribute('data-face-family') || '');
            li.innerHTML = '<span class="sc-hand-check" aria-hidden="true"></span><span class="sc-hand-text"></span>';
            li.querySelector('.sc-hand-text').textContent = o.textContent;
            menu.appendChild(li);
            items.push(li);
        }

        var active = Math.max(0, select.selectedIndex);

        function syncLabel() {
            var opt = select.options[select.selectedIndex];
            trigger.querySelector('.sc-hand-label').textContent = opt ? opt.textContent : '';
            var fam = familyOf(opt);
            trigger.querySelector('.sc-hand-label').style.fontFamily = fam;
            for (var i = 0; i < items.length; i++) {
                var on = i === select.selectedIndex;
                items[i].setAttribute('aria-selected', on ? 'true' : 'false');
                items[i].querySelector('.sc-hand-check').textContent = on ? '✓' : '';
            }
        }

        function setActive(i) {
            active = Math.min(items.length - 1, Math.max(0, i));
            for (var n = 0; n < items.length; n++) {
                items[n].classList.toggle('is-active', n === active);
            }
            menu.setAttribute('aria-activedescendant', items[active].id);
            items[active].scrollIntoView({ block: 'nearest' });
        }

        function open() {
            if (!menu.hidden) { return; }
            closeOpen();
            loadSpecimens(select, items);
            menu.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            /* Drop upward when the space below cannot hold the list. "Space" is the room
               the menu can actually be seen in: the window, cut down by any ancestor that
               clips its overflow. The composer's Wording card does, so a picker at the foot
               of the card with a tall window below it must still open upward. */
            var room = visibleRoom(wrap);
            var t = trigger.getBoundingClientRect();
            var below = room.bottom - t.bottom;
            var above = t.top - room.top;
            var need = Math.min(menu.scrollHeight + 16, 340);
            var up = below < need && above > below;
            menu.setAttribute('data-drop', up ? 'up' : 'down');
            /* Neither side holds it all: cap the list to its side, and it scrolls. */
            var side = (up ? above : below) - 12;
            menu.style.maxHeight = side < menu.scrollHeight ? Math.max(120, side) + 'px' : '';
            setActive(select.selectedIndex);
            openMenu = close;
        }

        function close(focusTrigger) {
            if (menu.hidden) { return; }
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            openMenu = null;
            if (focusTrigger === true) { trigger.focus(); }
        }

        function commit(i) {
            var opt = select.options[i];
            if (opt) {
                select.value = opt.value;
                /* The <select> is still the source of truth, so everything downstream
                   hears about this the ordinary way. */
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            close(true);
        }

        trigger.addEventListener('click', function () {
            if (menu.hidden) { open(); } else { close(true); }
        });

        /* The POINTER path. This was lost once already: consolidating the keyboard
           handlers cut a range that happened to contain these two, and commit() became
           reachable only from the keyboard — the list opened, highlighted, and ignored
           every click. Keep them adjacent to the trigger's own handler so the next edit
           to the key handling cannot take them with it.

           Delegated on the menu rather than bound per row: the rows are rebuilt from
           the <select> and a listener each would have to be rebuilt with them. */
        menu.addEventListener('click', function (e) {
            var li = e.target.closest ? e.target.closest('.sc-hand-opt') : null;
            if (li) { commit(items.indexOf(li)); }
        });

        /* Hovering moves the active row, so the pointer and the keyboard agree about
           where "here" is — otherwise arrowing after a hover jumps back to wherever the
           keyboard had left off. */
        menu.addEventListener('mousemove', function (e) {
            var li = e.target.closest ? e.target.closest('.sc-hand-opt') : null;
            if (li) { setActive(items.indexOf(li)); }
        });

        /* ONE key handler, on the trigger. Focus never moves into the list — the
           list is described to assistive tech with aria-activedescendant — so
           everything arrives here and a second listener on the menu would only
           double-handle. */
        var typed = '';
        var typedAt = 0;

        function jump(ch) {
            var now = Date.now();
            typed = (now - typedAt < 900) ? typed + ch.toLowerCase() : ch.toLowerCase();
            typedAt = now;

            /* From the row after the current one, so repeating a letter cycles through
               the faces that share it, as a native select does. */
            for (var n = 1; n <= items.length; n++) {
                var i = (active + n) % items.length;
                if (select.options[i].textContent.toLowerCase().indexOf(typed) === 0) {
                    setActive(i);
                    return;
                }
            }
        }

        trigger.addEventListener('keydown', function (e) {
            var closed = menu.hidden;

            switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                if (closed) { open(); } else { setActive(active + 1); }
                return;
            case 'ArrowUp':
                e.preventDefault();
                if (closed) { open(); } else { setActive(active - 1); }
                return;
            case 'Home':
                if (closed) { return; }
                e.preventDefault(); setActive(0); return;
            case 'End':
                if (closed) { return; }
                e.preventDefault(); setActive(items.length - 1); return;
            case 'Enter':
            case ' ':
                e.preventDefault();
                if (closed) { open(); } else { commit(active); }
                return;
            case 'Escape':
                if (closed) { return; }
                e.preventDefault(); close(true); return;
            case 'Tab':
                close(false);
                return;
            default:
                if (e.key.length === 1 && !e.metaKey && !e.ctrlKey && !e.altKey) {
                    e.preventDefault();
                    if (closed) { open(); }
                    jump(e.key);
                }
            }
        });

        trigger.setAttribute('aria-controls', id);
        select.addEventListener('change', syncLabel);
        syncLabel();
    }

    /* The <select>'s current value, kept in step with the store. */
    function bind(select, onChange) {
        if (!select) { return; }

        var initial = get();
        if (known(initial)) { select.value = initial; }

        armSpecimens(select);
        enhance(select);
        paint(select);

        select.addEventListener('change', function () {
            var face = set(select.value);
            select.value = face;
            paint(select);
            if (typeof onChange === 'function') { onChange(face); }
        });
    }

    /*
     * Re-letter `root` and re-measure it.
     *
     * The re-measure is the part that cannot be skipped. Every size on a certificate
     * was fitted against the previous face's metrics, and the hands differ enough to
     * change the answer — a 100-character persona fits at 1.06em in Chancery and
     * 0.76em in Cinzel, and an order line that sits on one line in Chancery takes two
     * in Cinzel, which is what pushes a long citation past its box.
     *
     * And it waits for the faces first: all of them are font-display:block, so
     * between the swap and the load the text is not merely unstyled, it is INVISIBLE.
     * Measuring there measures nothing.
     */
    function apply(el, face, root) {
        if (!el || !known(face)) { return; }
        el.setAttribute('data-scroll-face', face);

        var af = global.ScrolleryAutofit;
        if (!af) { return; }

        /* document.fonts.ready is not enough on its own: it can already be settled at
           the moment of the attribute swap (a face only starts loading once style
           recalc finds text that uses it), so a fit queued behind it would measure the
           fallback face — and the engine's nowrap lines clip when the real one lands.
           Request every family the chosen hand letters with, then fit. sigApply() below
           is the same fix for the signature. */
        handLast = Promise.all(handFamilies(el, root).map(function (fam) {
            return loadFamily(fam);
        })).then(function () {}, function () {}).then(function () {
            af.fit(root || doc);
            if (af.reportClipping) { af.reportClipping(root || doc); }
        });
    }

    /* The refit the last apply queued, settled. The print page's auto-print gate waits
       on it: a remembered hand is applied in place and was never preloaded. */
    var handLast = Promise.resolve();

    /* The CSS families the hand now in effect letters with: the display face and the
       text companion, read off the custom properties scrollery.css sets per hand. Read
       from the computed style (which forces the recalc the swap needs anyway) rather
       than a second copy of the vocabulary here, so a hand with a third face is picked
       up by adding it to the CSS. */
    function handFamilies(el, root) {
        var out = [];
        var scope = root || doc;
        var t = (scope.querySelector && scope.querySelector('.award-template')) ||
            (el && el.querySelector && el.querySelector('.award-template'));
        if (!t || !global.getComputedStyle) { return out; }
        var cs = global.getComputedStyle(t);
        ['--sc-display-face', '--sc-text-face'].forEach(function (name) {
            var v = (cs.getPropertyValue(name) || '').trim();
            if (v && out.indexOf(v) === -1) { out.push(v); }
        });
        return out;
    }

    /* One family, in the three styles a certificate uses. Failures are swallowed: a face
       that cannot load falls back, and the fit still runs. */
    function loadFamily(fam) {
        if (!(doc.fonts && doc.fonts.load)) { return Promise.resolve(); }
        return Promise.all(['16px ', 'bold 16px ', 'italic 16px '].map(function (spec) {
            return doc.fonts.load(spec + fam).then(function () {}, function () {});
        }));
    }

    /* ----------------------------------------------------------------------
     * ScrolleryLines — the "Reason lines" switch.
     *
     * The six ruled writing rules behind the citation, on or off. Same shape as the
     * hand picker above and for the same reason: it is a per-PRINT presentation
     * choice, remembered per browser, that has to reach a page which has not loaded
     * yet (the print surface is its own document). It is NOT stored on the award.
     *
     * Applies to every order. The rules live in the shared text layer that all
     * seventeen render, so there is nothing order-specific to know here.
     *
     * A checkbox rather than a listbox: two states, and the CRM already has the
     * pattern in the composer's own "the"/"of" boxes.
     * -------------------------------------------------------------------- */
    var LKEY = 'sc-lines';

    function linesOn() {
        try {
            /* Only an explicit '0' turns them off, so a cleared or absent store reads
               as on — the same rule the server applies to the query parameter. */
            return global.localStorage.getItem(LKEY) !== '0';
        } catch (e) {
            return true;
        }
    }

    function setLines(on) {
        try { global.localStorage.setItem(LKEY, on ? '1' : '0'); } catch (e) { /* private mode */ }
        return !!on;
    }

    /* Stamp the state where the CSS can see it. `el` is <body> on the print surface
       and the preview stage in the composer — the same split the hand uses. */
    function applyLines(el, on) {
        if (!el) { return; }
        if (on) { el.removeAttribute('data-scroll-lines'); }
        else { el.setAttribute('data-scroll-lines', '0'); }
    }

    function bindLines(checkbox, onChange) {
        if (!checkbox) { return; }

        checkbox.checked = linesOn();
        checkbox.addEventListener('change', function () {
            var on = setLines(checkbox.checked);
            if (typeof onChange === 'function') { onChange(on); }
        });
    }

    global.ScrolleryLines = {
        KEY: LKEY,
        /* get() -> bool. The remembered state; true unless explicitly turned off. */
        get: linesOn,
        set: setLines,
        /* bind(checkbox, onChange) -> void. Wire a checkbox to the store. */
        bind: bindLines,
        /* apply(el, on) -> void. Stamp data-scroll-lines for the stylesheet. */
        apply: applyLines,
        /* decorate(url, on) -> string. Carry the choice to a page not yet loaded.
           Only ever ADDS lines=0; an omitted parameter already means on, so a URL
           for the default state stays clean. */
        decorate: function (url, on) {
            return on ? url : setParam(url, 'lines', '0');
        }
    };

    /* ----------------------------------------------------------------------
     * ScrolleryHeading — make "Automatic" say what it chose.
     *
     * Automatic lets the typeset engine pick the issuing block's composition: Classic
     * whenever Classic fits, Centered only when a name or place will not. So on most
     * certificates Automatic IS Classic and the control looks like it does nothing.
     * The select therefore carries the answer in its Automatic option —
     * "Automatic (Classic)", "Automatic (Centered)", or "Automatic (mixed)" on a page
     * holding several certificates that disagree.
     *
     * The engine's report is the data-sc-comp attribute it writes on every .Award at
     * the end of every fit. A MutationObserver on that attribute rather than a hook in
     * the fit: the attribute is rewritten on EVERY fit (an identical value is still a
     * mutation record), so editing a name that flips the choice, a hand swap and a
     * bulk page's first pass all land here with no cooperation from the engine, and
     * this file does not have to know which call site ran the fit.
     *
     * While a forced composition is in effect the engine reports the FORCED one, which
     * says nothing about what Automatic would have done, so the label is left on its
     * last resolved text until the select goes back to Automatic.
     *
     * The one thing a forced value can report is that it was NOT honoured: a forced
     * Classic whose place cannot be set on Classic's one line even at the engine's last
     * floor is set Centered instead, and the engine stamps data-sc-fallback="classic" on
     * that .Award (B1 of the combo150 sweep). Left unsaid, the reader chose Classic and
     * got Centered with no reason given, so while Classic is selected its own option
     * names it: "Classic (didn't fit — used Centered)", or "Classic (some used
     * Centered)" on a bulk page where only some certificates fell back.
     * -------------------------------------------------------------------- */
    var COMP_WORD = { classic: 'Classic', centered: 'Centered' };

    /* The label for a set of composition values: one word if they agree, "mixed" if
       not, null when there is nothing to say yet. Pure, so it is unit-tested. */
    function headingLabel(comps) {
        var seen = [];
        for (var i = 0; i < comps.length; i++) {
            if (COMP_WORD[comps[i]] && seen.indexOf(comps[i]) === -1) { seen.push(comps[i]); }
        }
        if (seen.length === 0) { return null; }
        return 'Automatic (' + (seen.length === 1 ? COMP_WORD[seen[0]] : 'mixed') + ')';
    }

    /* The Classic option's label for `fell` fallbacks out of `total` fitted
       certificates: plain when none fell back. Pure, so it is unit-tested. */
    function classicLabel(fell, total) {
        if (!(fell > 0) || !(total > 0)) { return 'Classic'; }
        return fell >= total ? 'Classic (didn\'t fit \u2014 used Centered)' : 'Classic (some used Centered)';
    }

    function bindHeading(select, root) {
        if (!select || !root || typeof global.MutationObserver !== 'function') { return; }

        /* The Classic option's own text, from the server's Headings() list, put back
           whenever there is no fallback to report. */
        var classicOpt = null, classicText = '';
        for (var c = 0; c < select.options.length; c++) {
            if (select.options[c].value === 'classic') { classicOpt = select.options[c]; classicText = classicOpt.textContent; }
        }

        function syncClassic() {
            if (!classicOpt) { return; }
            var text = classicText;
            if (select.value === 'classic') {
                var total = root.querySelectorAll('.Award[data-sc-comp]').length;
                var fell = root.querySelectorAll('.Award[data-sc-comp][data-sc-fallback="classic"]').length;
                if (fell > 0) { text = classicLabel(fell, total); }
            }
            if (classicOpt.textContent !== text) { classicOpt.textContent = text; }
        }

        function sync() {
            syncClassic();
            if (select.value !== 'auto') { return; }
            var comps = [];
            var els = root.querySelectorAll('.Award[data-sc-comp]');
            for (var i = 0; i < els.length; i++) { comps.push(els[i].getAttribute('data-sc-comp')); }
            var text = headingLabel(comps);
            if (text === null) { return; }
            for (var n = 0; n < select.options.length; n++) {
                if (select.options[n].value === 'auto' && select.options[n].textContent !== text) {
                    select.options[n].textContent = text;
                }
            }
        }

        new global.MutationObserver(sync).observe(root, {
            subtree: true, childList: true, attributes: true, attributeFilter: ['data-sc-comp', 'data-sc-fallback']
        });
        select.addEventListener('change', sync);
        sync();
    }

    global.ScrolleryHeading = { label: headingLabel, classicLabel: classicLabel, bind: bindHeading };

    /* ----------------------------------------------------------------------
     * The signature hand — the typeface of the given-by line.
     *
     * The same kind of choice as the hand above and kept beside it for that reason: a
     * per-PRINT presentation preference, remembered per BROWSER ('sc-sig'), carried to
     * the print page as &sig=, and never stored on the award. The vocabulary is
     * scroll-recipes.php ('signatureHands'), the faces and the [data-scroll-sig] rules
     * are style/scrollery-signature.css, and it reuses the hand's listbox
     * (enhance/paint/armSpecimens above) because "each option drawn in its own face" is
     * the whole point of this picker too.
     *
     * Kept as its own small store rather than parameterising the hand's: the hand's
     * get/set/bind are tangled up with the page-level default and the &face= rule that
     * four surfaces depend on, and a signature picker is not worth a regression there.
     * -------------------------------------------------------------------- */
    var SKEY = 'sc-sig';
    var sigKeys = [];
    var sigFallback = 'hand';
    /* The refit the last apply queued, settled. The print page's auto-print gate waits on
       it: a remembered face applied in place was never preloaded, and printing before it
       arrives prints the fallback. */
    var sigLast = Promise.resolve();

    function sigKnown(key) { return !!key && sigKeys.indexOf(key) !== -1; }

    function sigGet() {
        try {
            var v = global.localStorage.getItem(SKEY);
            return sigKnown(v) ? v : sigFallback;
        } catch (e) {
            return sigFallback;       /* blocked storage: see get() */
        }
    }

    function sigSet(key) {
        if (!sigKnown(key)) { return sigFallback; }
        try { global.localStorage.setItem(SKEY, key); } catch (e) { /* see get() */ }
        return key;
    }

    /* The CSS family behind a key, read off the <select>'s own option so this file
       never restates the vocabulary. '' for the default. */
    function sigFamily(select, key) {
        var o = select && select.querySelector('option[value="' + key + '"]');
        return (o && o.getAttribute('data-face-family')) || '';
    }

    /*
     * Re-letter the signature on `el` (the stage or <body>) and re-measure it.
     *
     * The face is requested explicitly BEFORE the fit. document.fonts.ready is not
     * enough on its own: it is already resolved at the moment of the attribute swap
     * (nothing has started loading — a face only loads once style recalc finds text
     * that uses it), so a fit queued behind it would measure the fallback face. The fit
     * has to wait on the one load that this swap is about to cause. Failures are
     * swallowed: a face that cannot load falls back, and the fit still runs.
     */
    function sigApply(el, key, root, select) {
        if (!el || !sigKnown(key)) { return; }
        el.setAttribute('data-scroll-sig', key);

        var af = global.ScrolleryAutofit;
        if (!af) { return; }

        var fam = sigFamily(select, key);
        var loading = (fam && doc.fonts && doc.fonts.load)
            ? doc.fonts.load('16px "' + fam + '"')
            : Promise.resolve();
        sigLast = loading.then(function () {}, function () {}).then(function () {
            af.fit(root || doc);
            if (af.reportClipping) { af.reportClipping(root || doc); }
        });
    }

    function sigBind(select, onChange) {
        if (!select) { return; }

        var initial = sigGet();
        if (sigKnown(initial)) { select.value = initial; }

        armSpecimens(select);
        enhance(select);
        paint(select);

        select.addEventListener('change', function () {
            var key = sigSet(select.value);
            select.value = key;
            paint(select);
            if (typeof onChange === 'function') { onChange(key); }
        });
    }

    global.ScrolleryHand = {
        /* init(keys, defaultFace) — called by each page from Scroll::Hands(). */
        init: function (list, def) {
            keys = Array.isArray(list) ? list.slice() : [];
            if (known(def)) { fallback = def; }
        },
        KEY: KEY,
        known: known,
        get: get,
        set: set,
        bind: bind,
        /* enhance(select) -> void. Swap a <select> for the listbox. Idempotent. */
        enhance: enhance,
        /* armSpecimens(select) -> void. Fetch every option's face on first contact. */
        armSpecimens: armSpecimens,
        /* paint(select) -> void. Draw the closed control in the face it is showing. */
        paint: paint,
        apply: apply,
        /* ready() -> Promise. Settles once the last apply's faces have loaded and the
           page has been fitted in them. Never rejects. */
        ready: function () { return handLast; },
        decorate: decorate,
        /* The signature hand: sig.init(keys, default), get/set/known, bind(select,
           onChange), apply(el, key, root, select), decorate(url, key). */
        sig: {
            KEY: SKEY,
            init: function (list, def) {
                sigKeys = Array.isArray(list) ? list.slice() : [];
                if (sigKnown(def)) { sigFallback = def; }
            },
            known: sigKnown,
            get: sigGet,
            set: sigSet,
            bind: sigBind,
            apply: sigApply,
            /* ready() -> Promise. Settles once the last apply's face has loaded and the
               page has been fitted in it. Never rejects. */
            ready: function () { return sigLast; },
            /* Always names the key, as face does, so a URL says what the preview showed. */
            decorate: function (url, key) { return sigKnown(key) ? setParam(url, 'sig', key) : url; }
        }
    };
}(window, document));
