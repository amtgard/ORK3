/*
 * The new-player tour: a short walk through the person's own revised profile
 * (Playernew_index.tpl). Loaded on every page from default.theme, right after
 * ork-tour.js, because it also offers the welcome modal site-wide to anyone
 * logged in who has not taken or turned down the tour yet.
 */
(function (w, d) {
    'use strict';
    var T = w.OrkTour;
    if (!T) { return; }

    var ID = 'new-player';

    function state() { return w.OrkTourState || {}; }
    function loggedIn() { var s = state(); return !!(s.loggedIn && s.userId); }

    // On the revised player profile, and it is the viewer's own.
    function onOwnProfile() {
        var pc = w.PnConfig;
        if (!pc || typeof pc !== 'object') { return false; }
        var uid = Number(state().userId) || 0;
        return uid > 0 && Number(pc.playerId) === uid;
    }

    function tab(name) { return '.pn-tab-nav li[data-tab="' + name + '"]'; }

    // Show a tab's panel the way the page does: a real click on its tab.
    function openTab(name) {
        return function () {
            var li = d.querySelector(tab(name));
            if (li && !li.classList.contains('pn-tab-active')) { li.click(); }
        };
    }

    // Each tab step opens its tab as the tour reaches it, so the panel below matches the popover.
    function tabStep(name, title, description) {
        return {
            element: tab(name),
            onHighlightStarted: openTab(name),
            popover: { title: title, description: description, side: 'bottom', align: 'center' }
        };
    }

    T.register(ID, {
        title: 'Learn About Your Profile',
        version: 1,
        audience: loggedIn,
        match: onOwnProfile,
        steps: [
            {
                popover: {
                    title: 'Welcome to your profile',
                    description: 'This is your page in the ORK, the Online Record Keeper where Amtgard tracks your attendance, awards, and class levels. In about a minute, we\'ll show you what each part of it does.'
                }
            },
            {
                element: '#pn-hero',
                popover: {
                    title: 'Who you are in Amtgard',
                    description: 'Your persona (the name you play under), your home park, and your kingdom. The badges show whether your waiver is on file, whether your dues are paid, whether you can vote, and any office you hold.',
                    side: 'bottom',
                    align: 'start'
                }
            },
            {
                element: '#pn-design-btn',
                popover: {
                    title: 'Design My Profile',
                    description: 'Make this page your own. Write a short bio and your persona\'s story, choose your colors, style how your name appears, and frame your photo.',
                    side: 'bottom',
                    align: 'end'
                }
            },
            tabStep('myamtgard', 'My Amtgard',
                'Your personal dashboard, visible only to you. It shows how long you\'ve played, your last 26 weeks of attendance, your newest awards and titles, your class progress, any offices you hold, and upcoming events. You can also set feast preferences so event cooks know about your dietary needs.'),
            tabStep('about', 'About',
                'The public introduction to your persona, along with milestones from your time in Amtgard. Once you write a bio or story in Design My Profile, this is the first tab other players see when they visit.'),
            tabStep('awards', 'Awards',
                'Awards are how Amtgard recognizes your skill and service, usually given at court by your Monarch. Each one is listed with who gave it and where. Many awards are ladders with ranks, and your progress up each ladder shows here too.'),
            tabStep('titles', 'Titles',
                'Titles are honors such as noble ranks. Offices are positions you\'ve held, like Monarch, Regent, Champion, or Prime Minister. Each is listed with the date you received it.'),
            tabStep('attendance', 'Attendance',
                'Every time you sign in at a park day or event, it\'s recorded here with the class you played. Your attendance earns class credits and counts toward things like being eligible to vote.'),
            tabStep('recommendations', 'Recommendations',
                'Any player can recommend someone for an award. Recommendations made for you that haven\'t been given yet appear here. Some kingdoms keep these private, so you may not see this tab.'),
            tabStep('history', 'Notes',
                'Records carried over from older versions of the ORK, often awards or titles written down as text. If something here is missing from your Awards or Titles tabs, ask your park\'s Prime Minister to add it.'),
            tabStep('classes', 'Class Levels',
                'Each time you play, you choose a class, such as Warrior, Healer, or Wizard. Every sign-in earns a credit in the class you played, and as credits add up your level in that class rises. Bonus credits your park grants you appear here too.'),
            {
                popover: {
                    title: 'You\'re all set',
                    description: 'That\'s everything on your profile. To see this again, open Resources (the book icon in the top bar) on your profile and choose Learn About Your Profile. Welcome to Amtgard!'
                }
            }
        ],
        config: {
            // Put the page back on its usual first tab when the tour ends.
            onDestroyed: function () { openTab('myamtgard')(); }
        }
    });

    // ---------- the site-wide welcome offer ----------

    // Not inside frames, and only when "Learn About Your Profile" has somewhere to go.
    if (!loggedIn() || w.self !== w.top) { return; }
    if (!state().profileUrl && !onOwnProfile()) { return; }

    T.offer(ID, {
        title: 'Welcome to the ORK',
        body: 'The ORK is where Amtgard keeps your attendance, awards, and class levels. Take a minute to learn what\'s on your profile and how to make it your own.',
        acceptText: 'Learn About Your Profile',
        declineNote: 'Whenever you\'re ready, open Resources (the book icon in the top bar) on your profile and choose Learn About Your Profile.',
        onAccept: function () {
            if (onOwnProfile()) {
                T.start(ID);
                return;
            }
            var url = state().profileUrl;
            if (!url) { return; }
            w.location.href = url + (url.indexOf('?') === -1 ? '?' : '&') + 'ork_tour=' + ID;
        }
    });
})(window, document);
