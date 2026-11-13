/*
 * The park profile tour: a walk through the revised park profile
 * (Parknew_index.tpl). Loaded by that template only, after ork-tour.js.
 * Anyone can start it from the Resources menu; logged-in people are offered
 * it once, the first time they open any park profile.
 */
(function (w, d) {
    'use strict';
    var T = w.OrkTour;
    if (!T) { return; }

    var ID = 'park-profile';

    function state() { return w.OrkTourState || {}; }
    function loggedIn() { var s = state(); return !!(s.loggedIn && s.userId); }
    function cfg() { return (w.PkConfig && typeof w.PkConfig === 'object') ? w.PkConfig : {}; }

    // On the revised park profile.
    function onParkProfile() {
        return !!(w.PkConfig && typeof w.PkConfig === 'object' && d.getElementById('pk-hero'));
    }

    function tab(name) { return '.pk-tab-nav li[data-pktab="' + name + '"]'; }

    // Show a tab's panel the way the page does: a real click on its tab.
    function openTab(name) {
        return function () {
            var li = d.querySelector(tab(name));
            if (li && !li.classList.contains('pk-tab-active')) { li.click(); }
        };
    }

    // Each tab step opens its tab as the tour reaches it, so the panel below matches the popover.
    function tabStep(name, title, description, extra) {
        var step = {
            element: tab(name),
            onHighlightStarted: openTab(name),
            popover: { title: title, description: description, side: 'bottom', align: 'center' }
        };
        if (extra && extra.when) { step.when = extra.when; }
        return step;
    }

    // A sidebar card, found by something only that card contains.
    function sidebarCard(inner) {
        return function () {
            var n = d.querySelector('.pk-sidebar ' + inner);
            return n ? n.closest('.pk-card') : null;
        };
    }

    function has(sel) { return !!d.querySelector(sel); }

    // The hero buttons depend on what the viewer may do, so describe the ones that are there.
    function heroActionsText() {
        var parts = [];
        if (has('.pk-hero-actions [onclick^="pkOpenAttendanceModal"]')) {
            parts.push('Enter Attendance records who played on a park day');
        }
        if (has('.pk-hero-actions [onclick^="bcOpen"]')) {
            parts.push('Bonus Credits grants extra class credits');
        }
        if (has('.pk-hero-actions [onclick^="pkOpenAwardModal"]')) {
            parts.push('Enter Awards records awards given at court');
        }
        if (has('.pk-hero-actions [onclick^="pkOpenAdminModal"]')) {
            parts.push('Admin edits the park\'s details');
        }
        var list = parts.length ? ' ' + parts.join('; ') + '.' : '';
        return 'These buttons appear because you help run this park.' + list + ' Other players don\'t see them.';
    }

    function adminText() {
        var t = 'Tools for the park\'s officers, visible only to people who help run it.';
        if (has('#pk-tab-admin .kn-report-cols')) {
            t += ' Create or move player records, check suspensions, and set who can help manage the park.';
        }
        if (has('#pk-cp-section')) {
            t += ' The Court Planner is where you line up awards before a court gives them.';
        }
        return t;
    }

    function reportsText() {
        var t = 'Ready-made lists about this park: the player roster, attendance over different stretches of time, event attendance, and awards given here.';
        if (!cfg().loggedIn) { t += ' Log in to see the full list.'; }
        return t;
    }

    function recsText() {
        var t = 'Players can recommend each other for awards, and the officers who give awards use these recommendations when planning court.';
        if (cfg().loggedIn) {
            t += ' Use Recommend an Award to suggest someone.';
        }
        t += ' Some kingdoms keep recommendations private; there you\'ll only see the ones you made.';
        return t;
    }

    T.register(ID, {
        title: 'Learn About Park Profiles',
        version: 1,
        match: onParkProfile,
        steps: function () {
            return [
                {
                    popover: {
                        title: 'Welcome to a park',
                        description: 'A park is a local Amtgard group that meets to play, usually every week. Every park belongs to a kingdom, a larger region made up of many parks. This page shows what this park is, when it meets, and who plays here.'
                    }
                },
                {
                    element: '#pk-hero',
                    popover: {
                        title: 'The park at a glance',
                        description: 'The park\'s name, its heraldry (its coat of arms), and the kingdom it belongs to; click the kingdom to visit its page. The badge shows the park\'s type, such as Shire, Barony, or Duchy, which usually reflects how large it has grown, along with where it is.',
                        side: 'bottom',
                        align: 'start'
                    }
                },
                {
                    element: '.pk-hero-actions',
                    when: function () { var a = d.querySelector('.pk-hero-actions'); return !!(a && a.children.length); },
                    popover: {
                        title: 'Officer shortcuts',
                        description: heroActionsText(),
                        side: 'bottom',
                        align: 'end'
                    }
                },
                {
                    element: '.pk-stats-row',
                    popover: {
                        title: 'Park numbers',
                        description: 'When the park meets next, how many people have played here in the past six months, average turnout per week and per month, and how many events are coming up. Some cards are links: click one to jump to that part of the page.',
                        side: 'bottom',
                        align: 'center'
                    }
                },
                {
                    element: sidebarCard('.pk-officer-list'),
                    popover: {
                        title: 'Officers',
                        description: 'The people who run the park, chosen locally. The Monarch leads it, the Regent champions arts and crafts, the Prime Minister keeps the records, including who signed in, the Champion oversees fighting, and the GMR (Guildmaster of Reeves) handles the rules. Click a name to see their profile.'
                    }
                },
                {
                    element: sidebarCard('.ork-pin-map'),
                    popover: {
                        title: 'Where it is',
                        description: 'A map of where this park usually plays.'
                    }
                },
                {
                    element: sidebarCard('.pk-link-list'),
                    popover: {
                        title: 'Quick Links',
                        description: 'Shortcuts to search this park\'s players, see the companies and households (groups of players who band together) based here, and visit the park\'s own website or map if it has them.'
                    }
                },
                tabStep('about', 'About',
                    'The park\'s own description, directions to where it plays, and its schedule. Each regular meeting is a park day; signing in at one earns you a credit in the class you played, and those credits are how your class levels grow.'),
                tabStep('events', 'Events',
                    'Events and calendar items this park is hosting, with their next dates. Switch between a list, a calendar, and a map, and turn on Park Days to see regular meetings alongside them.'),
                tabStep('players', 'Players',
                    'Everyone who has played at this park, with their sign-ins over the past six months, their last visit, and the class they last played. Search for someone, or click a name to open their profile.'),
                tabStep('heraldry', 'Hall of Arms',
                    'The heraldry of players who have signed in here in the past year. Heraldry is a player\'s personal coat of arms; you can add your own from your profile.'),
                tabStep('reports', 'Reports', reportsText()),
                tabStep('recommendations', 'Recommendations', recsText()),
                tabStep('admin', 'Admin Tasks', adminText(), {
                    // The tab can be listed for someone who has no panel behind it; only tour it when there is one.
                    when: function () { return !!d.getElementById('pk-tab-admin'); }
                }),
                {
                    popover: {
                        title: 'That\'s a park',
                        description: 'Every park profile in the ORK works the same way. To see this again, open Resources (the book icon in the top bar) on any park\'s page and choose Learn About Park Profiles.'
                    }
                }
            ];
        },
        config: {
            // Put the page back on its usual first tab when the tour ends.
            onDestroyed: function () { openTab('about')(); }
        }
    });

    // ---------- the first-visit offer ----------

    // Offered once per person, the first time they open any park profile.
    if (!loggedIn() || w.self !== w.top) { return; }
    if (!onParkProfile()) { return; }

    T.offer(ID, {
        title: 'Learn About Park Profiles',
        body: 'Parks are where Amtgard players meet to play each week. Take a minute to see what a park\'s page tells you: when it meets, who runs it, and who plays there.',
        acceptText: 'Learn About Park Profiles',
        declineNote: 'Whenever you\'re ready, open Resources (the book icon in the top bar) on any park\'s page and choose Learn About Park Profiles.',
        priority: 10,
        once: true
    });
})(window, document);
