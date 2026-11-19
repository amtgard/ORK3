/*
 * The kingdom-profile tour: a walk through the revised kingdom profile
 * (Kingdomnew_index.tpl), loaded only by that template. Anyone can take it
 * from the Resources menu; the welcome offer shows once, the first time a
 * logged-in person opens any kingdom profile, their own or someone else's.
 */
(function (w, d) {
    'use strict';
    var T = w.OrkTour;
    if (!T) { return; }

    var ID = 'kingdom-profile';

    function state() { return w.OrkTourState || {}; }

    // On the revised kingdom profile: its config object and its hero are both here.
    function onKingdomProfile() {
        return !!(w.KnConfig && typeof w.KnConfig === 'object' && d.getElementById('kn-hero'));
    }

    function tab(name) { return '.kn-tab-nav li[data-kntab="' + name + '"]'; }

    // Show a tab's panel the way the page does: a real click on its tab.
    function openTab(name) {
        return function () {
            var li = d.querySelector(tab(name));
            if (li && !li.classList.contains('kn-tab-active')) { li.click(); }
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

    // A sidebar card has no id of its own; find it by the list inside it.
    function sidebarCard(listSelector) {
        return function () {
            var list = d.querySelector('.kn-sidebar ' + listSelector);
            return list ? list.closest('.kn-card') : null;
        };
    }

    // The officer buttons in the hero render only for people allowed to use them.
    function heroButton(fn) { return d.querySelector('.kn-hero-actions [onclick^="' + fn + '"]'); }

    function officerActionsText() {
        var parts = [];
        if (heroButton('knOpenAwardModal')) { parts.push('Enter Awards records an award given to a player, usually at court'); }
        if (heroButton('bcOpen')) { parts.push('Bonus Credits grants players extra class credits'); }
        if (heroButton('knOpenAdminModal')) { parts.push('Admin opens the kingdom\'s settings, such as its details, park titles, and award list'); }
        return 'Because you are an officer here, you also see tools others don\'t. ' + parts.join('. ') + '.';
    }

    T.register(ID, {
        title: 'Learn About Kingdom Profiles',
        version: 1,
        match: onKingdomProfile,
        steps: function () {
            return [
                {
                    popover: {
                        title: 'Welcome to a kingdom',
                        description: 'In Amtgard, a kingdom is a regional group of local chapters, called parks, led by an elected Monarch and other officers. This page shows everything about one kingdom. In about a minute, we\'ll show you what each part does.'
                    }
                },
                {
                    element: '#kn-hero',
                    popover: {
                        title: 'The kingdom at a glance',
                        description: 'The kingdom\'s name and heraldry (its coat of arms), and its current Monarch, the player elected to lead it. The Map button jumps to a map of all its parks.',
                        side: 'bottom',
                        align: 'start'
                    }
                },
                {
                    element: '.kn-hero-actions',
                    when: function () {
                        return !!(heroButton('knOpenAwardModal') || heroButton('bcOpen') || heroButton('knOpenAdminModal'));
                    },
                    popover: {
                        title: 'Officer tools',
                        description: officerActionsText(),
                        side: 'bottom',
                        align: 'end'
                    }
                },
                {
                    element: '.kn-stats-row',
                    popover: {
                        title: 'Kingdom numbers',
                        description: 'How many parks and upcoming events the kingdom has, and how many different players attend each week and each month on average. Click Parks or Events to jump to that list.',
                        side: 'bottom',
                        align: 'center'
                    }
                },
                {
                    element: sidebarCard('.kn-officer-list'),
                    popover: {
                        title: 'Officers',
                        description: 'The people who run the kingdom right now, such as the Monarch, Regent, Champion, and Prime Minister. Click a name to see that player\'s profile.',
                        side: 'right',
                        align: 'start'
                    }
                },
                {
                    element: sidebarCard('.kn-link-list'),
                    popover: {
                        title: 'Quick links',
                        description: 'Shortcuts to search for players in this kingdom, find its events, and browse its companies and households, which are groups of players who band together.',
                        side: 'right',
                        align: 'start'
                    }
                },
                tabStep('parks', 'Parks',
                    'Every park in the kingdom. A park\'s type, such as Shire, Barony, or Duchy, reflects how big it is, and each one shows how many players attend on average each week and month. Click a park to visit its page.'),
                tabStep('events', 'Events',
                    'Upcoming events across the kingdom, from big kingdom gatherings to events hosted by single parks. You can switch to a calendar or map view, filter by event type, RSVP, or subscribe so events show up in your own calendar.'),
                tabStep('map', 'Map',
                    'Every park with a location on file, pinned on a map. Click a pin to see that park\'s directions and description, which is handy for finding the park nearest you.'),
                tabStep('players', 'Players',
                    'Everyone whose home park is in this kingdom, grouped by the year they last signed in. Search by name, or turn on Active only to see people who have played in the past six months.'),
                tabStep('reports', 'Reports',
                    'Lists and statistics for the kingdom, such as rosters, attendance, and award holders like its Knights and Masters. Some reports are shown only to people who are logged in.'),
                tabStep('recommendations', 'Recommendations',
                    'Any player can recommend another for an award, and the Monarch reads these when deciding who to honor at court. Some kingdoms keep recommendations private, so you may only see your own, or no tab at all.'),
                tabStep('admin', 'Admin Tasks',
                    'Tools for the people who run the kingdom. Depending on your role, that can include creating, moving, and merging player records, roles and permissions, the Reeve\'s and Corpora rules tests, and planning courts. Only officers see this tab.'),
                {
                    popover: {
                        title: 'That\'s the tour',
                        description: 'Every kingdom profile works the same way. To see this again, open Resources (the book icon in the top bar) on any kingdom profile and choose Learn About Kingdom Profiles.'
                    }
                }
            ];
        },
        config: {
            // Put the page back on its usual first tab when the tour ends.
            onDestroyed: function () { openTab('parks')(); }
        }
    });

    // ---------- the first-visit offer ----------

    // Once ever, across every kingdom: the tour id is shared and once: true records it on first sight.
    if (!state().loggedIn || w.self !== w.top) { return; }
    if (!onKingdomProfile()) { return; }

    T.offer(ID, {
        title: 'Learn About Kingdom Profiles',
        body: 'This is a kingdom profile, the home page for one of Amtgard\'s regional kingdoms and the parks in it. Take a minute to see what\'s here and how to find your way around.',
        acceptText: 'Learn About Kingdom Profiles',
        declineNote: 'Whenever you\'re ready, open Resources (the book icon in the top bar) on any kingdom profile and choose Learn About Kingdom Profiles.',
        priority: 10,
        once: true
    });
})(window, document);
