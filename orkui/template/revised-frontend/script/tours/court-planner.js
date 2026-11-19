/*
 * Court Planner tours. Loaded on every page from default.theme.
 *
 *   court-planner  The Court Planner subsection of Admin Tasks on the revised
 *                  kingdom and park profiles (Kingdomnew_index.tpl,
 *                  Parknew_index.tpl). The subsection is rendered only for people
 *                  allowed to plan courts there, so its absence is the gate.
 *                  Trigger: a "new" dot on the Admin Tasks tab and on the Court
 *                  Planner header while the tour is untaken; the first click on
 *                  that header starts the tour once the section is open.
 *
 *   court-detail   One court's planning page (Court_detail.tpl, Route
 *                  Court/detail/{id}). Triggers: the court list's Open links carry
 *                  ork_tour=court-detail while it is untaken (dotted when there are
 *                  only a few), and a one-time offer on a Draft court, which is
 *                  where a new court lands right after Create Court.
 *
 * Both tours only highlight. They never press a button that saves anything.
 * The only things they touch are display state: the Admin Tasks tab and the
 * Court Planner section on the profile, and one award row's details panel on
 * the court page. Each is put back the way it was when the tour ends.
 */
(function (w, d) {
    'use strict';
    var T = w.OrkTour;
    if (!T) { return; }

    var PLANNER = 'court-planner';
    var DETAIL = 'court-detail';

    function state() { return w.OrkTourState || {}; }
    function loggedIn() { var s = state(); return !!(s.loggedIn && s.userId); }

    // Laid out on screen: not display:none, not [hidden], not in a collapsed parent.
    function shown(el) { return !!(el && el.getClientRects().length); }
    function visible(sel) {
        return function () { var el = d.querySelector(sel); return shown(el) ? el : null; };
    }

    function step(element, title, description, side, align, extra) {
        var s = {
            element: element,
            popover: { title: title, description: description, side: side || 'bottom', align: align || 'start' }
        };
        if (extra) { for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { s[k] = extra[k]; } } }
        return s;
    }

    // =====================================================================
    // 1. court-planner: the subsection on the kingdom / park profile
    // =====================================================================

    // 'kn' on a kingdom profile, 'pk' on a park profile, '' where the section is absent.
    function cpPrefix() {
        if (d.getElementById('kn-cp-section')) { return 'kn'; }
        if (d.getElementById('pk-cp-section')) { return 'pk'; }
        return '';
    }
    function onPlannerSection() { return !!cpPrefix(); }

    function adminLi(p) {
        return d.querySelector(p === 'kn' ? '.kn-tab-nav li[data-kntab="admin"]' : '.pk-tab-nav li[data-pktab="admin"]');
    }
    function activeLi(p) {
        return d.querySelector(p === 'kn' ? '.kn-tab-nav li.kn-tab-active' : '.pk-tab-nav li.pk-tab-active');
    }
    function sectionOpen(p) {
        var sec = d.getElementById(p + '-cp-section');
        return !!(sec && sec.classList.contains(p + '-cp-open'));
    }
    function toggleSection(p) {
        var fn = w[p + 'CpToggleSection'];
        if (typeof fn === 'function') { fn(); }
    }

    // What the tour changed on the way in, so it can be put back on the way out.
    var restoreTab = null;       // the <li> that was active before the tour switched to Admin Tasks
    var closeAfter = false;      // the tour opened the Court Planner section itself

    // Bring the section into view: Admin Tasks tab active, section expanded.
    function prepPlanner(p) {
        restoreTab = null;
        closeAfter = false;
        var li = adminLi(p);
        var panel = d.getElementById(p + '-tab-admin');
        if (li && panel && !shown(panel)) {
            restoreTab = activeLi(p);
            li.click();
        }
        if (!sectionOpen(p)) {
            toggleSection(p);
            closeAfter = sectionOpen(p);
        }
    }

    function restorePlanner() {
        var p = cpPrefix();
        if (p && closeAfter && sectionOpen(p)) { toggleSection(p); }
        if (restoreTab && d.contains(restoreTab) && !restoreTab.classList.contains(p + '-tab-active')) { restoreTab.click(); }
        closeAfter = false;
        restoreTab = null;
    }

    // The first court card's parts (the list is newest first, as the server sends it).
    function firstCard(p, inner) {
        return function () {
            var card = d.querySelector('#' + p + '-cp-body .' + p + '-cp-court-card');
            if (!card) { return null; }
            var el = inner ? card.querySelector(inner) : card;
            return shown(el) ? el : null;
        };
    }

    T.register(PLANNER, {
        title: 'Learn About the Court Planner',
        version: 1,
        audience: loggedIn,
        match: onPlannerSection,
        steps: function () {
            var p = cpPrefix();
            if (!p) { return []; }
            prepPlanner(p);
            var kingdom = p === 'kn';
            var where = kingdom ? 'kingdom' : 'park';
            var body = '#' + p + '-cp-body ';
            return [
                {
                    popover: {
                        title: 'The Court Planner',
                        description: 'Court is the ceremony where the ' + (kingdom ? 'Monarch' : 'park\'s Monarch') + ' gives out awards and titles, with a herald calling each name. The Court Planner lets you build that list ahead of time, run it on the day, and then write every award to the players\' records. Here is where this ' + where + '\'s courts live.'
                    }
                },
                step(visible('#' + p + '-cp-section .' + p + '-cp-header'), 'Court Planner',
                    'Click this heading to show or hide the planner. The number in brackets is how many courts this ' + where + ' has. It opens by itself whenever there is at least one.'),
                step(visible(body + '.' + p + '-cp-toolbar > span'), 'Courts planned',
                    kingdom
                        ? 'How many courts are listed below. These are the kingdom\'s own courts; each park plans its courts from its own profile.'
                        : 'How many courts are listed below. These are this park\'s own courts; kingdom courts are planned from the kingdom profile.'),
                step(visible(body + '.' + p + '-cp-toolbar .' + p + '-btn-primary'), 'Plan a Court',
                    'Starts a new court. Give it a name and, if you like, a date or an upcoming event (picking an event fills in its date). Then choose Run at Court, to mark each award live as it is given, or Lock as Plan, to prepare the order for someone to record later. Create Court takes you straight to the new court\'s page.',
                    'bottom', 'end'),
                step(visible(body + '.' + p + '-cp-empty'), 'No courts yet',
                    'Once you plan a court it appears here as a card with its date, status, and an Open button.', 'top', 'center'),
                step(firstCard(p), 'A court',
                    'Each card is one court: its date on the left, then its name and the event it is linked to, if any. The newest courts are at the top.', 'top', 'start'),
                step(firstCard(p, '.' + p + '-cp-badge'), 'Status',
                    'Every court moves through three steps. <b>Draft</b>: you are still building the list. <b>Published</b>: the list is final and ready for court day. <b>Complete</b>: court is over, its awards are on the players\' records, and it appears on the public Court Report under Reports.',
                    'bottom', 'end'),
                step(firstCard(p, '.' + p + '-cp-badge-mode'), 'Run or Plan',
                    '<b>Run</b> means the awards are marked one by one during the ceremony, from a phone or laptop. <b>Plan</b> means the list is printed for court and someone records what was given afterwards. You can switch after publishing.',
                    'bottom', 'end'),
                step(firstCard(p, '.' + p + '-cp-badge-staged'), 'Staged grants',
                    'Some awards on this court were marked as given but not finalized yet. Nothing reaches a player\'s record until someone opens the court and completes it.',
                    'bottom', 'end'),
                step(firstCard(p, '.' + p + '-cp-badge-count'), 'Awards on the court',
                    'How many awards and titles are on this court, not counting any that were skipped.', 'bottom', 'end'),
                step(firstCard(p, '.' + p + '-cp-btn-link'), 'Open',
                    'Opens the court\'s own page. That is where you add awards from recommendations or by hand, put them in order, publish, print the packet for the herald, give the awards, and complete the court.',
                    'bottom', 'end'),
                {
                    popover: {
                        title: 'You\'re ready',
                        description: 'That\'s the Court Planner. To see this again, open Resources (the book icon in the top bar) on this page and choose Learn About the Court Planner. Each court\'s page has its own tour, Learn About Planning a Court.'
                    }
                }
            ];
        },
        config: {
            onDestroyed: restorePlanner
        }
    });

    // =====================================================================
    // 2. court-detail: one court's planning page
    // =====================================================================

    function onCourtDetail() {
        return !!(d.getElementById('cp-hero') && d.getElementById('cp-award-list'));
    }

    function courtStatus() {
        var b = d.getElementById('cp-status-badge');
        if (!b) { return ''; }
        if (b.classList.contains('cp-badge-complete')) { return 'complete'; }
        if (b.classList.contains('cp-badge-published')) { return 'published'; }
        return 'draft';
    }
    function isDraft() { return courtStatus() === 'draft'; }
    function isPublished() { return courtStatus() === 'published'; }
    function notComplete() { return courtStatus() !== 'complete'; }
    function planMode() {
        var m = d.getElementById('cp-mode-badge');
        return !!(m && m.classList.contains('cp-mode-plan'));
    }
    function kingdomCourt() {
        // The supertitle links the park first on a park court; a kingdom court links only the kingdom.
        return !d.querySelector('#cp-hero .cp-hero-supertitle a[href*="Park/profile/"]');
    }

    function firstRow() { return d.querySelector('#cp-award-list .cp-award-row'); }
    function hasRows() { return !!firstRow(); }

    // A control on the first award row, only if it is visible.
    function rowPart(sel) {
        return function () {
            var r = firstRow();
            var el = r && r.querySelector(sel);
            return shown(el) ? el : null;
        };
    }

    // The first visible match on any row: Grant and Skip show only on rows not
    // yet given, Undo only on given ones, Un-skip only on skipped ones.
    function anyRow(sel) {
        return function () {
            var list = d.querySelectorAll('#cp-award-list .cp-award-row ' + sel);
            for (var i = 0; i < list.length; i++) { if (shown(list[i])) { return list[i]; } }
            return null;
        };
    }

    // ---------- the one row the tour opens ----------

    var openedRow = null;

    function rowId(r) { return r ? r.getAttribute('data-court-award-id') : null; }
    function expandOf(r) { var id = rowId(r); return id ? d.getElementById('cp-aw-expand-' + id) : null; }

    // The page's own toggle, so the chevron flips with it. Opening a row saves nothing.
    function openFirstRow() {
        var r = firstRow();
        var ex = expandOf(r);
        if (!ex || ex.classList.contains('open') || typeof w.cpToggleAward !== 'function') { return; }
        w.cpToggleAward(rowId(r));
        if (ex.classList.contains('open')) { openedRow = r; }
    }

    function closeOpenedRow() {
        var r = openedRow;
        openedRow = null;
        var ex = expandOf(r);
        if (r && d.contains(r) && ex && ex.classList.contains('open') && typeof w.cpToggleAward === 'function') {
            w.cpToggleAward(rowId(r));
        }
    }

    // Parts of the first row's details panel. The panel is closed when the tour
    // starts, so these are found by existence and opened as the step arrives.
    function expandPart(sel) {
        return function () {
            var ex = expandOf(firstRow());
            return ex ? ex.querySelector(sel) : null;
        };
    }
    function expandStep(sel, title, description) {
        return step(expandPart(sel), title, description, 'top', 'start', {
            when: function () { return hasRows() && notComplete(); },
            onHighlightStarted: openFirstRow
        });
    }

    // The first row's pass-to-local control: a checkbox on a park court, a button on a
    // kingdom court (only for awards that came from a recommendation).
    function localText() {
        var ex = expandOf(firstRow());
        if (ex && ex.querySelector('.cp-send-local-btn')) {
            return ' <b>Send to Local Park</b> takes a recommended award off this court and hands it to the player\'s park to give instead.';
        }
        if (ex && ex.querySelector('.cp-ptl-check')) {
            return ' <b>Pass to Local</b> marks an award the kingdom has approved for this park to give.';
        }
        return '';
    }

    function hero(sel) { return visible('#cp-hero ' + sel); }
    function headerAction(sel) { return visible('.cp-section-header ' + sel); }

    function statusText() {
        var st = courtStatus();
        if (st === 'complete') {
            return 'This court is <b>Complete</b>. Its awards are on the players\' records and it is listed on the public Court Report, so it can no longer be changed.';
        }
        if (st === 'published') {
            return 'This court is <b>Published</b>: the list is final and ready for court. ' +
                (planMode()
                    ? 'The <b>Plan</b> badge means it was printed for court and someone records what was given afterwards.'
                    : 'The <b>Run at Court</b> badge means each award is marked as given or skipped during the ceremony.');
        }
        return 'This court is a <b>Draft</b>. You can add, remove, and reorder awards freely. When the list is final, you publish it.';
    }

    T.register(DETAIL, {
        title: 'Learn About Planning a Court',
        version: 1,
        audience: loggedIn,
        match: onCourtDetail,
        steps: function () {
            var draft = isDraft();
            var published = isPublished();
            return [
                {
                    popover: {
                        title: 'Planning a court',
                        description: 'This page is one court: the awards and titles to give, in the order the herald (the person who announces court) will call them. You build the list as a draft, publish it when it is final, give the awards at court, and then complete it to write them to each player\'s record.'
                    }
                },
                step(visible('#cp-hero .cp-hero-info'), 'The court',
                    'The court\'s name, date, and linked event, plus its <b>Recorder</b>, the person responsible for getting its awards recorded (the Prime Minister unless someone else is chosen). The links at the top go back to the ' + (kingdomCourt() ? 'kingdom' : 'park and kingdom') + '.'),
                step(visible('#cp-status-badge'), 'Status', statusText(), 'bottom', 'end'),
                step(hero('[onclick^="cpOpenCourtMetaModal"]'), 'Edit Details',
                    'Change the court\'s name, date, linked event, or Recorder.', 'left', 'center'),
                step(hero('[onclick^="cpAdvanceStatus"]'), draft ? 'Publish' : 'Complete Court',
                    draft
                        ? 'When the list is final, Publish asks how you will run it. <b>Run at Court</b>: grant or skip each award live as the herald calls it, and several officers can follow along together. <b>Lock as Plan</b>: print the packet now and record the grants later. You can switch modes after publishing.'
                        : 'When court is over, this writes every granted award to the players\' records and closes the court. If some awards were neither granted nor skipped, it asks whether to skip them or leave them for your next court. Completing is final, so check the list first.',
                    'left', 'center'),
                step(hero('a[href*="Court/record/"]'), 'Record Court',
                    'For courts run from paper. After court, open this to go through the marked-up Court Record sheet line by line, tick what was given, add any walk-ons, and then complete the court.',
                    'left', 'center'),
                step(hero('[onclick^="cpReturnToPlanning"]'), 'Return to Planning',
                    'Puts the court back to Draft so you can change the list again before court.',
                    'left', 'center'),
                step(visible('#cp-staged-indicator'), 'Grants waiting to be finalized',
                    'Awards marked as given at court are held here, not yet on anyone\'s record. Finalize & Complete writes them to the players\' records and completes the court.',
                    'bottom', 'center'),
                step(visible('#cp-prev-banner'), 'Skipped last time',
                    'Some awards were skipped at your last court and have still not been given. Prepopulate adds them to this court, or close the banner to leave them off.',
                    'bottom', 'center'),
                step(visible('.cp-status-bar'), 'At a glance',
                    'How many awards are on the court, and how many scrolls (the decorated certificates given with an award) and regalia (the physical tokens, such as a belt or medallion) are ready, in progress, or not started.' +
                        (published ? ' During court it also shows how many awards have been given, who else has the court open, and whether your screen is in sync.' : ''),
                    'bottom', 'start'),

                // ---- tools sidebar ----
                step(visible('.cp-sb-sort-btn'), 'Sort by Precedence',
                    'Puts the whole court in the usual order of reading: plain awards first, then ladder awards by rank, then titles, then peerages. Each person\'s awards stay together, placed by their highest honor. You can fine-tune the order afterwards.',
                    'right', 'start'),
                step(visible('#cp-printing-list-btn'), 'Printing List',
                    'Shows only the awards whose scroll is being tracked, with the ones still needing printing first, so whoever prints scrolls can work through them. It is just a view: click it again to see the full list, and turn it off before reordering.',
                    'right', 'start'),
                step(visible('#cp-sidebar-collapse-btn'), 'Tools sidebar',
                    'Hide or show this sidebar to make room for the list. Its About This Tool card sums up the whole workflow if you need a reminder.',
                    'right', 'start'),

                // ---- adding to the court ----
                step(headerAction('[onclick^="cpOpenRecModal"]'), draft ? 'Add from Recommendations' : 'Add from Rec',
                    'Opens the pending recommendations for this ' + (kingdomCourt() ? 'kingdom' : 'park') + ' so you can tick the ones to give at this court. You can see only open ones, all of them, snoozed ones, or ones the player already has.' +
                        (published ? ' After publishing, anything added goes at the end of the list as a walk-on.' : ''),
                    'bottom', 'end'),
                step(headerAction('[onclick="cpOpenAdhocModal(\'award\')"]'), 'Add Award',
                    'Add an award by hand for any player, with or without a recommendation. Search for the player and the award, pick the rank for a ladder award, and add internal notes or a public comment if you like.' +
                        (published ? ' After publishing, it goes at the end of the list as a walk-on.' : ''),
                    'bottom', 'end'),
                step(headerAction('[onclick="cpOpenAdhocModal(\'title\')"]'), 'Add Title',
                    'The same, for titles and peerages, such as a knighthood or a noble title.', 'bottom', 'end'),
                step(headerAction('[onclick^="cpCnOpen"]'), 'Add Note',
                    'Puts a line on the order that is not an award, such as an announcement from the event\'s organizer or an officer changeover, so the herald reads it at the right moment.',
                    'bottom', 'end'),
                step(visible('#cp-script-btn'), 'Court Script',
                    'Opens the printable packet. The <b>Order of Court</b> is what the herald reads from. The <b>Court Record</b> is the sheet marked up during court. The <b>Prep Sheet</b> lists the scrolls and regalia each maker owes. Print one sheet, or all three at once.',
                    'bottom', 'end'),
                step(visible('#cp-density-seg'), 'Row spacing',
                    'Choose how much space each row takes. Compact fits more of a long court on screen.'),

                // ---- the list ----
                step(visible('#cp-award-empty'), 'The order of court',
                    'Nothing is on this court yet. Each award you add appears here as a row, in the order it will be given.', 'top', 'center'),
                step(visible('#cp-list-header'), 'The order of court',
                    'One row for each award or title, in the order the herald will call them. Type shows whether it is a plain award, a ladder award given in ranks, or a title. A star under Flags means it came from a recommendation; a down arrow means it is passed to the player\'s park to give.',
                    'bottom', 'start', { when: hasRows }),
                step(rowPart('.cp-cell-order'), 'Reorder',
                    'Drag a row by its handle, or use the up and down arrows, to change where it falls in the order. The new order saves as you go.',
                    'right', 'center', { when: isDraft }),
                step(rowPart('.cp-cell-scroll .cp-tracking-icon'), 'Scroll',
                    'Click to track this award\'s scroll. It cycles from gray (not tracked) to red (needs making) to green (done).', 'left', 'center'),
                step(rowPart('.cp-cell-regalia .cp-tracking-icon'), 'Regalia',
                    'The same for the regalia, the physical token that goes with the award.', 'left', 'center'),
                step(anyRow('.cp-btn-grant'), 'Grant',
                    'Press Grant when the herald calls this award. It asks you to confirm the rank, who gave it, and the public comment, then marks it as given. It is written to the player\'s record when the court is completed.',
                    'left', 'center'),
                step(anyRow('.cp-btn-skip'), 'Skip',
                    'Use Skip if the award is not given, for example when the player is not there. A skipped award can be put back, and it is offered again at your next court.',
                    'left', 'center'),
                step(anyRow('.cp-btn-undo'), 'Undo',
                    'Takes back a grant that was marked by mistake, as long as the court has not been completed.', 'left', 'center'),
                step(anyRow('.cp-btn-unskip'), 'Un-skip',
                    'Returns a skipped award to the list so it can still be given.', 'left', 'center'),

                // ---- one row's details (opened by the tour) ----
                step(rowPart('.cp-award-row-main'), 'Award details',
                    'Click any row to open its details. We\'ll open this one for you.', 'bottom', 'start', {
                        when: function () { return hasRows() && notComplete(); }
                    }),
                expandStep('.cp-expand-grid', 'Notes and public comment',
                    '<b>Internal Notes</b> are for officers only. The <b>Public Comment</b> is the citation shown on the player\'s record and the public Court Report. When the award came from a recommendation, you can start from the recommendation\'s wording or clear it.' +
                        localText()),
                expandStep('.cp-expand-grid + .cp-expand-grid', 'Scroll and Regalia Makers',
                    'Name the artisans making this award\'s scroll and regalia. They are listed on the Prep Sheet, so they know what they owe, and the herald can thank them at court.'),
                expandStep('[onclick^="cpOpenArtisanModal"]', 'Contributing Artisans',
                    'Credit anyone else who helped make something for this award. Artisans are credited publicly on the Court Report.'),
                expandStep('.cp-expand-actions', 'Save or Remove',
                    'Save keeps your changes to this row. Remove takes the award off the court; it cannot be removed once it is on the player\'s record.'),

                {
                    popover: {
                        title: 'You\'re ready',
                        description: 'That\'s how a court comes together: build it, publish it, give the awards, then complete it. To see this again, open Resources (the book icon in the top bar) on any court\'s page and choose Learn About Planning a Court.'
                    }
                }
            ];
        },
        config: {
            // Leave the page as it was found: close the row the tour opened.
            onDestroyed: closeOpenedRow
        }
    });

    // =====================================================================
    // Triggers. Logged-in only, never inside frames.
    // =====================================================================

    if (!loggedIn() || w.self !== w.top) { return; }

    // ---------- the profile: dots, and the first click on the dotted header ----------

    function initProfile() {
        var p = cpPrefix();
        if (!p || T.seen(PLANNER)) { return; }

        var tabSel = p === 'kn' ? '.kn-tab-nav li[data-kntab="admin"]' : '.pk-tab-nav li[data-pktab="admin"]';
        T.dot(tabSel, PLANNER);
        T.dot('#' + p + '-cp-section .' + p + '-cp-header-title', PLANNER);

        var header = d.querySelector('#' + p + '-cp-section .' + p + '-cp-header');
        if (!header) { return; }
        // The header's own onclick toggles the section first (inline handlers run
        // before listeners added here), so by now it is open or has just closed.
        header.addEventListener('click', function onHeader() {
            if (T.seen(PLANNER)) { header.removeEventListener('click', onHeader); return; }
            var a = T.active();
            if (a && a.isActive && a.isActive()) { return; }
            header.removeEventListener('click', onHeader);
            // The section opens by itself when courts exist, so a first click on the
            // dotted header may have just closed it. Open it again for the tour.
            if (!sectionOpen(p)) { toggleSection(p); }
            setTimeout(function () { T.start(PLANNER); }, 150);
        });
    }

    // ---------- the court list: Open links start the court page tour ----------

    var OPEN_LINKS = '.kn-cp-btn-link, .pk-cp-btn-link, .cp-court-card a.cp-btn-link';
    var DOT_LIMIT = 3;

    function initOpenLinks() {
        if (T.seen(DETAIL)) { return; }
        var links = Array.prototype.filter.call(d.querySelectorAll(OPEN_LINKS), function (a) {
            return /Court\/detail\//.test(a.getAttribute('href') || '');
        });
        if (!links.length) { return; }
        links.forEach(function (a) {
            var href = a.getAttribute('href');
            if (/[?&]ork_tour=/.test(href)) { return; }
            a.setAttribute('href', href + (href.indexOf('?') === -1 ? '?' : '&') + 'ork_tour=' + DETAIL);
        });
        // A dot on every card of a long list is noise; a few is a nudge.
        if (links.length <= DOT_LIMIT) {
            links.forEach(function (a) { a.setAttribute('data-ork-tour-open', '1'); });
            T.dot('[data-ork-tour-open="1"]', DETAIL);
        }
    }

    // ---------- the court page: a one-time offer on a Draft court ----------

    // A Draft is where a new court lands after Create Court, and where a first-time
    // planner starts. A Published court may be mid-ceremony, so it is never interrupted.
    function initDetail() {
        if (!onCourtDetail() || !isDraft()) { return; }
        T.offer(DETAIL, {
            title: 'Learn About Planning a Court',
            body: 'This is where you build a court: the awards to give, in the order the herald will call them. Take a minute to see how to add awards, put them in order, and publish when you\'re ready.',
            acceptText: 'Learn About Planning a Court',
            icon: 'fas fa-gavel',
            declineNote: 'Whenever you\'re ready, open Resources (the book icon in the top bar) on any court\'s page and choose Learn About Planning a Court.',
            priority: 10,
            once: true
        });
    }

    function init() {
        initProfile();
        initOpenLinks();
        initDetail();
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
