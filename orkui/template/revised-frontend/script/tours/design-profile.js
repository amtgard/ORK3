/*
 * The design-profile tour: a walk through the Design My Profile modal on the
 * person's own revised profile (Playernew_index.tpl, #pn-design-overlay).
 * Loaded on every page from default.theme, right after ork-tour.js.
 *
 * - Starts by itself the first time the person opens the designer on their own
 *   profile (never for an admin editing someone else's design with the same button).
 * - Listed in Resources on the person's own profile. The open designer covers the
 *   nav, so the menu link is used with the designer closed; the tour opens it.
 * - Each tab step clicks its tab so the panel matches the popover. Nothing is saved
 *   and no field is changed; when the tour ends the designer goes back to Welcome.
 */
(function (w, d) {
    'use strict';
    var T = w.OrkTour;
    if (!T) { return; }

    var ID = 'design-profile';
    var OVERLAY = 'pn-design-overlay';

    function state() { return w.OrkTourState || {}; }
    function loggedIn() { var s = state(); return !!(s.loggedIn && s.userId); }

    // On the revised player profile, and it is the viewer's own.
    function onOwnProfile() {
        var pc = w.PnConfig;
        if (!pc || typeof pc !== 'object') { return false; }
        var uid = Number(state().userId) || 0;
        return uid > 0 && Number(pc.playerId) === uid;
    }

    function overlay() { return d.getElementById(OVERLAY); }
    function designerOpen() { var o = overlay(); return !!(o && o.classList.contains('pn-open')); }
    function hasDesigner() { return !!(overlay() && typeof w.pnOpenDesignModal === 'function'); }

    // True from the moment this tour starts until it is destroyed.
    var running = false;
    // Set when the tour starts itself on the designer's first open (not from Resources).
    var autoStarted = false;

    function tab(name) { return '#' + OVERLAY + ' .pn-design-tab[data-panel="' + name + '"]'; }

    // Show a panel the way the page does: a real click on its tab.
    function openPanel(name) {
        return function () {
            var b = d.querySelector(tab(name));
            if (b && !b.classList.contains('pn-active')) { b.click(); }
        };
    }

    // The nearest wrapper of an element, so a step can light up a label with its control.
    function around(id, sel) {
        return function () {
            var e = d.getElementById(id);
            return e ? (sel ? e.closest(sel) : e) : null;
        };
    }

    function tabStep(name, title, description) {
        return {
            element: tab(name),
            onHighlightStarted: openPanel(name),
            popover: { title: title, description: description, side: 'bottom', align: 'center' }
        };
    }

    // A control inside a panel. Back can land here from another tab, so it reopens its panel too.
    function fieldStep(panel, element, title, description) {
        return {
            element: element,
            onHighlightStarted: openPanel(panel),
            popover: { title: title, description: description }
        };
    }

    function steps() {
        return [
            {
                // Started from Resources with the designer closed: open it first. A self-start
                // whose designer was closed again while driver.js loaded just ends.
                onHighlightStarted: function () {
                    running = true;
                    if (designerOpen()) { return; }
                    if (autoStarted) {
                        setTimeout(function () { var a = T.active(); if (a && a.isActive && a.isActive()) { a.destroy(); } }, 0);
                        return;
                    }
                    if (typeof w.pnOpenDesignModal === 'function') { w.pnOpenDesignModal(); }
                },
                popover: {
                    title: 'Design your profile',
                    description: 'This is where you make your profile look and read the way you want. We\'ll walk through each tab so you know what everything does. Nothing changes until you click Save Changes.'
                }
            },
            tabStep('welcome', 'Welcome',
                'A quick overview of the designer, with a few tips. Click any of its cards to jump straight to that tab.'),

            tabStep('about', 'About',
                'Write about yourself and your persona, the character you play in Amtgard. Everything on this tab is public, so anyone who visits your profile can read it.'),
            fieldStep('about', around('pn-design-about-persona', '.pn-design-field'), 'About you',
                'A short introduction: who you are, what you play, and where to find you. It supports Markdown for bold text, links, and lists. Use Preview to see how it will look, and Quick Add to drop in a list of links to where people can find you online.'),
            fieldStep('about', around('pn-design-about-story', '.pn-design-field'), 'My Story',
                'Your persona\'s backstory, told however you like. Once you write a bio or a story, the About tab is the first thing visitors see on your profile.'),
            fieldStep('about', around('pn-design-show-beltline', '.pn-design-field'), 'Show My Beltline',
                'Your beltline is your peerage relationships: the peer you\'re belted to, and anyone you\'ve belted yourself. When this is checked, they\'re listed on your About tab.'),
            fieldStep('about', around('pn-design-show-feast-prefs', '.pn-design-field'), 'Show My Feast Preferences',
                'Your diet, restrictions, and allergens, which you set on the My Amtgard tab. This is off by default; turn it on only if you\'re comfortable making them public.'),

            tabStep('colors', 'Colors',
                'Choose the colors of your profile: the banner behind your name, and an accent color for tabs, links, and stat cards.'),
            fieldStep('colors', '#pn-color-hero-preview', 'Preview',
                'A small copy of the banner at the top of your profile. It changes as you try colors, so experiment freely before you save.'),
            fieldStep('colors', '#pn-color-presets', 'Presets',
                'One click sets a matching banner and accent color. The Gradient Presets just below blend two colors across the banner.'),
            fieldStep('colors', '#pn-amtpride-section', 'Amtpride Nameplate',
                'Open this to use a pride flag as the background behind your name. While a flag is active it takes the place of your other colors, and you can switch back at any time.'),
            fieldStep('colors', around('pn-color-primary', '.pn-color-row'), 'Custom colors',
                'Pick any color, or type a hex code such as #2c5282. Primary is the banner background; Accent colors your tabs, links, and stat cards. Just below, check Enable gradient and choose a second color to blend the banner.'),
            fieldStep('colors', '#' + OVERLAY + ' .pn-overlay-options', 'Heraldry Overlay Strength',
                'Heraldry is your persona\'s coat of arms, the image shown faintly behind your name. These buttons set how strongly it shows through your colors. Reset to Default, below them, puts all your colors back to the standard blue.'),

            tabStep('name', 'Name',
                'Style how your persona name appears at the top of your profile.'),
            fieldStep('name', '#' + OVERLAY + ' .pn-name-parts', 'Build your display name',
                'Add a prefix or suffix from the titles you\'ve earned, or choose Other to type your own; only use titles you actually hold. Core Name is your persona name itself, and the line below shows the finished result.'),
            fieldStep('name', around('pn-design-pronunciation', '.pn-design-field'), 'Pronunciation Guide',
                'If your persona name is tricky to say, spell out how it sounds. It appears in parentheses under your name.'),
            fieldStep('name', around('pn-font-picker', '.pn-design-field'), 'Name Font',
                'Pick a decorative font for your name. People who use simple or reading-friendly fonts will see the standard font instead. The Name shadow option below adds an outline that helps your name stand out on light or busy backgrounds.'),
            fieldStep('name', function () {
                var e = d.getElementById('pn-design-show-first');
                var f = e && e.closest('.pn-design-field');
                return f ? f.parentNode : null;
            }, 'Your real name and email',
                'Your mundane name is your real-world name. Your kingdom\'s leadership and ORK administrators can always see it; these let any logged-in player see your first name, last name, or email too. If they\'re grayed out, your account restricts who can see them.'),

            tabStep('focus', 'Photo Focus',
                'Your photo appears in small circles around the ORK. Here you choose which part of it stays in view, usually your face. If you haven\'t uploaded a photo yet, add one first and come back.'),
            // Only when the person has a photo; without one the panel just asks for an upload.
            fieldStep('focus', '#' + OVERLAY + ' .pn-focus-canvas-wrap', 'Set the focus',
                'Move the circle over the part of your photo you want shown, and resize it to zoom in or out.'),

            tabStep('milestones', 'Milestones',
                'Milestones are highlights of your time in Amtgard, like your first sign-in, titles, and offices, shown as a timeline on your About tab. The ORK adds most of them for you.'),
            fieldStep('milestones', function () {
                var e = d.getElementById('pn-ms-compact');
                var l = e && e.closest('label');
                return l ? l.parentNode : null;
            }, 'How they\'re shown',
                'Compact Milestones turns the large timeline into a short list in the sidebar. Show newest first puts your most recent milestones at the top.'),
            fieldStep('milestones', '#pn-ms-toggles', 'Which ones appear',
                'Uncheck any kind of milestone you\'d rather not show. All of them are on to start.'),
            fieldStep('milestones', '#' + OVERLAY + ' .pn-ms-custom-wrap', 'Your own milestones',
                'Add moments the ORK can\'t know about, with a description, a date, and an icon. Unlike the rest of the designer, a custom milestone is saved the moment you click Add, so make sure it\'s accurate.'),

            // Knights only: the tab is not on the page otherwise, so the step drops out.
            tabStep('icons', 'Icons',
                'This tab appears because you\'re a knight. Choose whether your profile shows the standard white belt next to your name, the belts of your own knighthoods, or no belt at all.'),

            {
                element: '#' + OVERLAY + ' .pn-modal-footer',
                popover: {
                    title: 'Save or cancel',
                    description: 'Save Changes saves what you changed on every tab and reloads your profile so you can see the result. Cancel, or the X at the top, closes the designer without saving.',
                    side: 'top',
                    align: 'end'
                }
            },
            {
                popover: {
                    title: 'You\'re ready',
                    description: 'Have fun making your profile your own. To see this again, open Resources (the book icon in the top bar) on your profile and choose Learn About Designing Your Profile.'
                }
            }
        ];
    }

    T.register(ID, {
        title: 'Learn About Designing Your Profile',
        version: 1,
        audience: loggedIn,
        match: function () { return onOwnProfile() && hasDesigner(); },
        steps: steps,
        config: {
            onDestroyed: function () {
                running = false;
                autoStarted = false;
                // Back to the first tab, scrolled to the top. Field values are left alone.
                openPanel('welcome')();
                var o = overlay();
                var body = o && o.querySelector('.pn-acct-modal-body');
                if (body) { body.scrollTop = 0; }
            }
        }
    });

    if (!loggedIn() || !onOwnProfile() || !hasDesigner()) { return; }

    // A pulsing dot on Design My Profile until the tour is taken. Own profile only:
    // an admin sees the same button as "Edit Profile Design" on someone else's page.
    T.dot('#pn-design-btn', ID);

    // ---------- keys while the tour runs ----------

    // Escape ends the tour (driver.js listens for keyup). Stop the keydown before the
    // designer's own document listener sees it, so the designer stays open.
    d.addEventListener('keydown', function (e) {
        if (!running || (e.key !== 'Escape' && e.keyCode !== 27)) { return; }
        e.stopPropagation();
    }, true);

    // Arrow keys in a text field move the cursor; they should not also turn the page
    // of the tour. Registered before driver.js adds its window keyup listener, so
    // stopImmediatePropagation here keeps the arrow from reaching it.
    function typing(t) {
        if (!t || !t.tagName) { return false; }
        if (t.isContentEditable) { return true; }
        var tag = t.tagName.toLowerCase();
        if (tag === 'textarea' || tag === 'select') { return true; }
        if (tag !== 'input') { return false; }
        return !/^(checkbox|radio|button|submit|reset|color|range|file|image|hidden)$/i.test(t.type || 'text');
    }
    w.addEventListener('keyup', function (e) {
        if (!running || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) { return; }
        if (typing(e.target)) { e.stopImmediatePropagation(); }
    }, false);

    // ---------- first open: start the tour; closing the designer ends it ----------

    function tourBusy() {
        var a = T.active();
        return running || !!(a && a.isActive && a.isActive());
    }

    var wasOpen = designerOpen();
    var openTimer = null;
    function onOverlayChange() {
        var open = designerOpen();
        if (open === wasOpen) { return; }
        wasOpen = open;
        clearTimeout(openTimer);
        if (!open) {
            // Cancel or the X were clicked mid-tour: the tour has nothing left to show.
            if (running) {
                var a = T.active();
                if (a && a.isActive && a.isActive()) { a.destroy(); }
            }
            return;
        }
        if (tourBusy() || T.seen(ID)) { return; }
        // Let the open transition (0.2s) finish so the first highlights land on a settled modal.
        openTimer = setTimeout(function () {
            if (designerOpen() && !tourBusy() && !T.seen(ID)) {
                autoStarted = true;
                T.start(ID).then(function (drv) { if (!drv) { autoStarted = false; } }, function () { autoStarted = false; });
            }
        }, 400);
    }

    if (w.MutationObserver) {
        new w.MutationObserver(onOverlayChange).observe(overlay(), { attributes: true, attributeFilter: ['class'] });
    }
})(window, document);
