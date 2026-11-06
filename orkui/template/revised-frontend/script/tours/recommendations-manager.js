/*
 * The Recommendations Manager tour: a walk through every filter, row action,
 * and bulk action on Recommendations_manage.tpl, for an officer opening it for
 * the first time. Loaded on every page from default.theme, right after
 * new-player.js, because it also puts a "new" dot on the Manage Recs buttons on
 * the kingdom and park profiles (and points them at the tour) until it is taken.
 *
 * The tour only highlights. It never clicks an action, a filter, or a sort
 * header. The one thing it touches is a single row's select checkbox, ticked so
 * the bulk bar appears and unticked again when the tour ends.
 */
(function (w, d) {
    'use strict';
    var T = w.OrkTour;
    if (!T) { return; }

    var ID = 'recommendations-manager';

    function state() { return w.OrkTourState || {}; }
    function loggedIn() { var s = state(); return !!(s.loggedIn && s.userId); }

    function onManager() {
        return !!(d.getElementById('rm-filterbar') && d.getElementById('rm-grid'));
    }
    function kingdomScope() {
        return !!(w.RmConfig && w.RmConfig.context === 'kingdom');
    }

    // Laid out on screen: not display:none, not [hidden], not in a collapsed parent.
    function shown(el) { return !!(el && el.getClientRects().length); }

    function rows() { return d.querySelectorAll('#rm-tbody tr.rm-row'); }
    function firstRow() { return d.querySelector('#rm-tbody tr.rm-row'); }
    function hasRows() { return !!firstRow(); }
    function hasLiveRow() { var r = firstRow(); return !!(r && r.getAttribute('data-dismissed') !== '1'); }

    // A control on the first row, only if it is actually visible (on narrow
    // screens snooze, pass down, and dismiss sit behind the "..." button).
    function rowAct(cls) {
        return function () {
            var r = firstRow();
            var el = r && r.querySelector('.' + cls);
            return shown(el) ? el : null;
        };
    }

    // The first visible match among loaded rows: the reason expander only shows
    // when the reason is cut off, and the support chip only when someone seconded.
    function firstShown(sel) {
        return function () {
            var list = d.querySelectorAll('#rm-tbody tr.rm-row ' + sel);
            for (var i = 0; i < list.length; i++) { if (shown(list[i])) { return list[i]; } }
            return null;
        };
    }

    // A filter-bar or header control, only if it is visible.
    function visible(sel) {
        return function () { var el = d.querySelector(sel); return shown(el) ? el : null; };
    }

    // ---------- the one row the tour selects ----------

    var tourSelected = null;

    // A real click, so the page's own handler counts it and shows the bulk bar.
    function selectFirstRow() {
        var r = firstRow();
        var cb = r && r.querySelector('.rm-rowsel');
        if (!cb) { return; }
        if (!cb.checked) {
            cb.click();
            if (cb.checked) { tourSelected = cb; }
        }
    }

    function unselectFirstRow() {
        var cb = tourSelected;
        tourSelected = null;
        if (cb && d.contains(cb) && cb.checked) { cb.click(); }
    }

    // Bulk buttons sit in a [hidden] bar at tour start, so they are found by
    // existence, not visibility. They exist only when there is a row to select.
    function bulk(cls) {
        return function () { return d.querySelector('#rm-bulkbar .' + cls); };
    }

    function step(element, title, description, side, align, extra) {
        var s = {
            element: element,
            popover: { title: title, description: description, side: side || 'bottom', align: align || 'start' }
        };
        if (extra) { for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { s[k] = extra[k]; } } }
        return s;
    }

    function bulkStep(cls, title, description) {
        return step(bulk(cls), title, description, 'top', 'center', {
            when: hasLiveRow,
            onHighlightStarted: selectFirstRow
        });
    }

    T.register(ID, {
        title: 'Learn About the Recommendations Manager',
        version: 1,
        audience: loggedIn,
        match: onManager,
        steps: function () {
            var where = kingdomScope() ? 'kingdom' : 'park';
            return [
                {
                    popover: {
                        title: 'The Recommendations Manager',
                        description: 'Any player can recommend someone for an award. This page is where officers (the Monarch, Regent, Prime Minister, and others with authority here) review every pending recommendation for the ' + where + ', decide what to give, and plan it for court.'
                    }
                },
                step('.rp-scope-chip', 'Whose recommendations these are',
                    'This shows which ' + where + ' you are managing. ' +
                    (kingdomScope()
                        ? 'At the kingdom level you see recommendations for players in every park in the kingdom.'
                        : 'At the park level you see recommendations for players whose home park is this one.') +
                    ' Click it to go back to the profile.'),

                // ---- filters ----
                step(visible('#rm-search'), 'Search',
                    'Type part of a player\'s name to show only that player\'s recommendations. It searches the recipient, not the person who wrote the recommendation.'),
                step(visible('#rm-filter-elig'), 'Which recommendations to show',
                    '<b>Open Recs</b> (the default): everything the player has not received yet. ' +
                    '<b>Below Rec\'d</b>: ladder awards where the player does not yet hold the recommended rank. ' +
                    '<b>Non-Ladder</b>: awards without ranks, such as titles and orders given once. ' +
                    '<b>At or Above Rec\'d</b>: the player already holds this award at that rank or higher, so these are usually safe to dismiss. ' +
                    '<b>All</b>: every pending recommendation. ' +
                    '<b>Snoozed</b>: only the ones set aside until the next reign.'),
                step(visible('#rm-filter-court'), 'Court status',
                    'Filter by whether a recommendation is already planned for a court. Choose Not on a court to find ones you still need to schedule, On any court for ones already planned, or a specific court to see its list.'),
                step(visible('#rm-filter-park'), 'Park',
                    'Show only recommendations for players whose home park is the one you pick.'),
                step(visible('#rm-filter-passlocal'), 'Passed to local',
                    kingdomScope()
                        ? 'Show only recommendations you have passed down to a park, meaning you have given that park\'s officers the authority to give the award themselves.'
                        : 'Show only recommendations the kingdom has passed down to this park. Passing down gives the park the authority to give an award above its usual level.'),
                step(visible('#rm-filter-dismissed'), 'Show dismissed',
                    'Dismissed recommendations are kept, not deleted. Turn this on to see them alongside the rest and restore any that were dismissed by mistake.'),
                step(visible('#rm-export'), 'Export CSV',
                    'Download everything that matches your current filters as a spreadsheet, including rows you have not scrolled down to yet.', 'bottom', 'end'),

                // ---- the grid ----
                {
                    when: function () { return !hasRows(); },
                    popover: {
                        title: 'Nothing to review yet',
                        description: 'There are no recommendations matching these filters right now. When there are, each one appears as a row here with buttons to grant it, plan it for court, snooze it, or dismiss it.'
                    }
                },
                step(visible('#rm-grid thead tr'), 'Sorting',
                    'Click Recipient, Award, Rank, Recommended, or Support to sort by that column. Click it again to reverse the order. The list starts with the newest recommendations first.', 'bottom', 'center', { when: hasRows }),
                step(visible('#rm-selall'), 'Select all',
                    'Selects every row loaded so far, so you can act on all of them at once with the bulk buttons. More rows load as you scroll, and only loaded rows are selected.', 'bottom', 'start', { when: hasRows }),
                step(firstShown('.rm-col-reason .rm-expand-members'), 'Read the full reason',
                    'Click the arrow to open every recommendation for this award: who wrote it, when, and why. When several people recommend the same award, they are grouped into one row.', 'left', 'center'),
                step(firstShown('.rm-supp-chip'), 'Support',
                    'The number of other players backing this award, counting extra recommenders and anyone who seconded it. Click it to see who they are and what they said.', 'left', 'center'),

                // ---- row actions (first row) ----
                step(rowAct('rm-act-grant'), 'Grant now',
                    'Opens a form to give the award, filled in from the recommendation. Check the rank, date, who it was given by (you, by default), and where, then confirm. Nothing is recorded until you press Grant Award.', 'left', 'center'),
                step(rowAct('rm-act-court'), 'Add to court',
                    'Adds this award to a court plan, so it is ready to give at your next court. Pick an existing court or create a new one. This does not grant the award yet.', 'left', 'center'),
                step(rowAct('rm-act-snooze'), 'Snooze',
                    'Sets the recommendation aside until the Monarch or Regent changes, then it comes back on its own. Use it for awards that should wait for the next reign. Click it again on a snoozed row to bring it back now.', 'left', 'center'),
                step(rowAct('rm-act-passlocal'), 'Pass down',
                    'Hands this recommendation to the player\'s park. It gives the park\'s officers the authority to give this award even though it is above the level a park usually gives. Click it again to take it back.', 'left', 'center'),
                step(rowAct('rm-act-monarchy'), 'Monarchy Only',
                    'Hides the recommendation from everyone but officers, for example so the recipient cannot see it and the award stays a surprise. Click it again to make it visible.', 'left', 'center'),
                step(rowAct('rm-act-dismiss'), 'Dismiss',
                    'Removes the recommendation from the pending list, for example when it was already given or you do not plan to give it. You are asked to confirm, and it can be restored later with Show dismissed.', 'left', 'center'),
                step(rowAct('rm-act-more'), 'More actions',
                    'On a small screen, Snooze, Pass down, and Dismiss are tucked behind this button, with a short note on what each one does.', 'left', 'center'),
                step(rowAct('rm-act-undelete'), 'Restore',
                    'This row was dismissed. Restore returns it to the pending list.', 'left', 'center'),

                // ---- selection and bulk bar ----
                step(function () { var r = firstRow(); return r && r.querySelector('.rm-rowsel'); }, 'Select rows',
                    'Tick the box on any row to select it. Hold Shift while ticking a second box to select every row in between. We have selected this one for you so you can see the bulk buttons.', 'right', 'center', {
                        when: hasLiveRow,
                        onHighlightStarted: selectFirstRow
                    }),
                bulkStep('rm-bulk-court', 'Add to Court',
                    'Adds every selected recommendation to one court plan at once.'),
                bulkStep('rm-bulk-snooze', 'Snooze',
                    'Sets all the selected recommendations aside until the Monarch or Regent changes.'),
                bulkStep('rm-bulk-passlocal', 'Pass down',
                    'Passes all the selected recommendations down to each player\'s park, giving the park authority to give them.'),
                bulkStep('rm-bulk-monarchy', 'Monarchy Only',
                    'Hides all the selected recommendations from everyone but officers.'),
                bulkStep('rm-bulk-unmonarchy', 'Unhide',
                    'Makes all the selected recommendations visible again after Monarchy Only.'),
                bulkStep('rm-bulk-dismiss', 'Dismiss',
                    'Dismisses all the selected recommendations after you confirm. They are kept and can be restored.'),
                bulkStep('rm-bulk-undelete', 'Restore',
                    'Returns dismissed recommendations to the pending list. It only works on dismissed rows, so it stays greyed out until Show dismissed is on and you select one.'),
                bulkStep('rm-bulk-clear', 'Clear',
                    'Unselects everything and hides this bar.'),

                {
                    popover: {
                        title: 'You\'re ready',
                        description: 'That\'s the whole Recommendations Manager. To see this again, open Resources (the book icon in the top bar) on this page and choose Learn About the Recommendations Manager.'
                    }
                }
            ];
        },
        config: {
            // Leave the page as it was found: untick the row the tour selected.
            onDestroyed: unselectFirstRow
        }
    });

    // ---------- the "new" dot on the Manage Recs buttons ----------

    // Not inside frames; logged out never sees these buttons anyway.
    if (!loggedIn() || w.self !== w.top) { return; }

    var BTN = '.kn-manage-recs, .pk-manage-recs';
    var observer = null;
    var stopEvent = null;

    function stop() {
        if (observer) { observer.disconnect(); observer = null; }
        if (stopEvent) { stopEvent(); stopEvent = null; }
    }

    // Dot the buttons and make following one start the tour on arrival.
    function mark() {
        if (T.seen(ID)) { stop(); return; }
        var btns = d.querySelectorAll(BTN);
        if (!btns.length) { return; }
        T.dot(BTN, ID);
        Array.prototype.forEach.call(btns, function (a) {
            var href = a.getAttribute('href');
            if (!href || /[?&]ork_tour=/.test(href)) { return; }
            a.setAttribute('href', href + (href.indexOf('?') === -1 ? '?' : '&') + 'ork_tour=' + ID);
        });
    }

    function init() {
        mark();
        // The kingdom Recommendations tab is fetched the first time it is opened
        // (revised.js knLazyLoadRecs), so its button arrives later. Watch for it.
        // The panel is rendered once (a failed load retries into the same host),
        // so watching stops as soon as its button has been handled.
        var host = d.getElementById('kn-recs-lazy');
        if (host && !host.querySelector(BTN) && !T.seen(ID)) {
            var found = function () {
                if (!host.querySelector(BTN)) { return; }
                mark();
                stop();
            };
            d.addEventListener('kn:recs-loaded', found);
            stopEvent = function () { d.removeEventListener('kn:recs-loaded', found); };
            if (w.MutationObserver) {
                observer = new w.MutationObserver(found);
                observer.observe(host, { childList: true });
            }
        }
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
