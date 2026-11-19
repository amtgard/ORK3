/*
 * The qualification-test tours: how a kingdom officer (usually the GMR, the
 * Guildmaster of Reeves, or the Prime Minister) builds the Reeve's Test and
 * the Corpora Test. Three tours, one per page, because seen-state is per id and
 * each page is its own job:
 *
 *   qualtest-configure  QualTest/manage/{kingdom}         settings for both tests
 *   qualtest-builder    QualTest/questions/{kingdom}/{t}  the question bank and its versions
 *   qualtest-question   QualTest/question/...             writing or editing one question
 *
 * All three carry the same title, so "Resources -> Learn About Building
 * Qualification Tests" works on whichever of the pages you are on.
 *
 * Loaded on every page from default.theme because it also puts a "new" dot on
 * the entry links (kingdom profile -> Admin Tasks -> Tests, and the links between
 * the three pages) and points them at the right tour until it is taken.
 *
 * The tours only highlight. They never press Save, Publish, Archive, or any
 * other button, and never open a modal.
 */
(function (w, d) {
    'use strict';
    var T = w.OrkTour;
    if (!T) { return; }

    var CONFIGURE = 'qualtest-configure';
    var BUILDER = 'qualtest-builder';
    var QUESTION = 'qualtest-question';
    var TITLE = 'Learn About Building Qualification Tests';

    function state() { return w.OrkTourState || {}; }
    function loggedIn() { var s = state(); return !!(s.loggedIn && s.userId); }

    // ---------- which page ----------

    function onConfigure() {
        return !!(d.querySelector('.qt-page .qt-config-grid') && d.getElementById('qt-managers-card'));
    }
    function onQuestions() {
        return !!d.querySelector('.qt-page .qt-versions');
    }
    function onEditor() {
        return !!(d.querySelector('.qt-page') && d.getElementById('qt-question-form'));
    }

    // The questions page names its test in the header; the editor carries it in a hidden field.
    function isCorpora() {
        var f = d.querySelector('#qt-question-form input[name="TestType"]');
        if (f) { return f.value === 'corpora'; }
        var h = d.querySelector('.qt-page .rp-header-title');
        return !!(h && /corpora/i.test(h.textContent));
    }
    function testName() { return isCorpora() ? 'Corpora Test' : 'Reeve\'s Test'; }
    function aboutTest() {
        return isCorpora()
            ? 'The Corpora Test checks that a player knows your kingdom\'s Corpora, the kingdom\'s own governing document.'
            : 'The Reeve\'s Test checks that a player knows the Amtgard Rules of Play well enough to referee as a reeve.';
    }
    function versionLabel() { return isCorpora() ? 'Corpora version' : 'Rules of Play version'; }

    // ---------- element helpers ----------

    // Laid out on screen: not display:none, not [hidden], not in a collapsed parent.
    function shown(el) { return !!(el && el.getClientRects().length); }

    function visible(sel) {
        return function () { var el = d.querySelector(sel); return shown(el) ? el : null; };
    }

    // The first visible match.
    function firstShown(sel) {
        return function () {
            var list = d.querySelectorAll(sel);
            for (var i = 0; i < list.length; i++) { if (shown(list[i])) { return list[i]; } }
            return null;
        };
    }

    // A control inside one test's settings card, lifted to its row.
    function inForm(type, sel, rowSel) {
        return function () {
            var el = d.querySelector('form.qt-config-form[data-type="' + type + '"] ' + sel);
            if (el && rowSel) { el = el.closest(rowSel); }
            return shown(el) ? el : null;
        };
    }
    function card(type) {
        return function () {
            var f = d.querySelector('form.qt-config-form[data-type="' + type + '"]');
            var c = f && f.closest('.qt-config-card');
            return shown(c) ? c : null;
        };
    }
    function inCard(type, sel) {
        return function () {
            var c = card(type)();
            var el = c && c.querySelector(sel);
            return shown(el) ? el : null;
        };
    }

    // The first row of the active-questions table, as DataTables has drawn it.
    function firstRow() { return d.querySelector('#qt-active-table tbody tr[id^="qrow-"]'); }
    function rowCell(n) {
        return function () {
            var r = firstRow();
            var el = r && r.querySelector('td:nth-child(' + n + ')');
            return shown(el) ? el : null;
        };
    }
    function rowCtl(sel) {
        return function () {
            var r = firstRow();
            var el = r && r.querySelector(sel);
            return shown(el) ? el : null;
        };
    }

    function step(element, title, description, side, align, extra) {
        var s = {
            element: element,
            popover: { title: title, description: description, side: side || 'bottom', align: align || 'start' }
        };
        if (extra) { for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { s[k] = extra[k]; } } }
        return s;
    }

    function centered(title, description) {
        return { popover: { title: title, description: description } };
    }

    function finish(what) {
        return centered('You\'re ready',
            what + ' To see this tour again, open Resources (the book icon in the top bar) on this page and choose ' + TITLE + '.');
    }

    // ---------- Configure Tests ----------

    function configureSteps() {
        return [
            centered('Configure Tests',
                'This page holds the settings for your kingdom\'s two qualification tests. ' +
                'The Reeve\'s Test checks that a player knows the Amtgard Rules of Play well enough to referee as a reeve. ' +
                'The Corpora Test checks that a player knows your kingdom\'s Corpora, its own governing document. ' +
                'Players take both from their profile, and a pass is recorded on their profile as a qualification.'),

            step(card('reeve'), 'One card per test',
                'This card holds the Reeve\'s Test settings. The Corpora Test has a matching card beside it (below it on a narrow screen). Each card is saved separately.',
                'right', 'start'),
            step(firstShown('.qt-config-grid .qt-unsaved-warning'), 'Never saved',
                'Nothing has been saved for this test yet, so the values below it are only defaults. The test still runs on them. Press its Save Settings button once, even if the defaults look right.'),
            step(inCard('reeve', '.qt-mini-stat'), 'Active questions',
                'How many active (not archived) questions this test has in its bank, across all versions.'),

            // ---- scoring ----
            step(inForm('reeve', 'input[name="QuestionCount"]', '.qt-form-row'), 'Questions per test',
                'How many questions each player is asked. They are drawn at random from the published version, so players get different mixes. A version needs at least this many questions before it can be published, so keep the bank comfortably larger.'),
            step(inForm('reeve', 'input[name="PassPercent"]', '.qt-form-row'), 'Pass % required',
                'The score a player needs to pass. A pass records the qualification on the player\'s profile. A fail is kept in their test history but records nothing.'),
            step(inForm('reeve', '.qt-validity-row'), 'Validity',
                'How long a pass lasts. <b>Days from passing</b> gives each player their own window, counted from the day they passed. <b>Until date</b> makes every pass expire on the same day. If you line that date up with an officer changeover, add a week or two so the next officer has time to set up new tests.'),
            step(inForm('reeve', 'input[name="MaxRetakes"]', '.qt-form-row'), 'Max retakes',
                'Limits how many times a player can retake the test. 0 means unlimited. A player who reaches the limit cannot start again until their count is reset.'),

            // ---- player experience ----
            step(inForm('reeve', 'input[name="ShowCorrectOnIncorrect"]', '.qt-switch-row'), 'Display correct answer on incorrect',
                'When on, a player who picks a wrong answer sees the correct one highlighted in green. When off, only their wrong choice is marked, and reviewing a past attempt does not reveal the answers either.'),
            step(inForm('reeve', 'textarea[name="Instructions"]', '.qt-form-row'), 'Instructions',
                'Optional text shown as the first card when a player starts the test. Use it to say whether they may look things up, or whom to ask with questions. Line breaks are kept.'),

            // ---- version label and sharing ----
            step(inForm('reeve', '.qt-derived-value', '.qt-form-row'), 'Rules of Play version',
                'Read-only here. It shows the rules version of the live test, which players see as a footer on every question and which is stamped on each attempt. You set it on the version itself, on the questions page, and a version cannot be published without one.'),
            step(inForm('reeve', 'input[name="ShareQuestions"]', '.qt-switch-row'), 'Opt-in to share questions',
                'Reeve\'s Test only. Turn it on and save to share your live Reeve\'s questions with other kingdoms that have also opted in. In return you get an Add from Library button on the questions page for copying theirs. Nothing is shared until you save.'),
            step(inForm('corpora', '.qt-derived-value', '.qt-form-row'), 'Based on',
                'The Corpora card has the same kind of label: which edition of your Corpora the live test is based on. Players see it as a footer on every question. Like the rules version, you set it on the version on the questions page. The Corpora Test has no sharing switch, because each kingdom\'s Corpora is its own.',
                'left', 'start'),

            // ---- actions ----
            step(inForm('reeve', '.qt-save-btn', '.qt-save-row'), 'Save Settings',
                'Saves this card\'s settings. It only saves this test; the other card has its own button.', 'top', 'start'),
            step(inCard('reeve', '.qt-link-btn-primary'), 'View Questions',
                'Opens this test\'s question bank, where you write questions and publish versions of the test.', 'top', 'start'),
            step(inCard('reeve', '.qt-link-btn-ghost'), 'Add Question',
                'Goes straight to a blank question for this test.', 'top', 'start'),
            step(inCard('reeve', '.qt-reset-retakes-btn'), 'Reset Retakes',
                'Clears the retake count for every player on this test, after you confirm. Useful after you rewrite questions, so players who used up their tries can take the new test. It cannot be undone.', 'top', 'start'),

            // ---- test managers ----
            step(visible('#qt-managers-card'), 'Test Managers',
                'The Monarch, Regent, Prime Minister, and GMR (Guildmaster of Reeves) can already manage these tests. Anyone you add here can too (settings, questions, and versions for both tests) without any other kingdom powers.',
                'top', 'start'),
            step(function () {
                var b = d.getElementById('qt-add-manager-btn');
                var row = b && b.parentNode;
                return shown(row) ? row : null;
            }, 'Add a manager',
                'Type at least two letters of a player\'s name, pick them from the list, then press Add Manager.', 'top', 'start'),
            step(firstShown('#qt-manager-list .qt-rm-manager-btn'), 'Remove a manager',
                'Removes that player as a test manager, after you confirm.', 'left', 'center'),

            finish('Two things have to be true before players can take a test: a version has to be published on the questions page, and the test has to be switched on for the kingdom. Only the monarchy can switch it on, from the Kingdom page\'s Admin button (the cog) and then Configuration.')
        ];
    }

    // ---------- Question bank ----------

    function liveRow() { return d.querySelector('.qt-versions > .qt-ver-row:not(.qt-ver-draftrow)'); }

    function liveStatus() {
        var r = liveRow();
        if (r && r.querySelector('.qt-ver-live')) {
            return '<b>Live</b>: players can be asked this version\'s questions right now.';
        }
        if (r && r.querySelector('.qt-ver-warn')) {
            return '<b>Published (test off)</b>: this version is ready, but the test is switched off for the kingdom, so nobody can take it yet.';
        }
        return '<b>Not live</b>: nobody can take the test yet. Either nothing has been published, or the live version has fewer questions than each test draws.';
    }

    function questionsSteps() {
        var name = testName();
        var label = versionLabel();
        var labelExample = isCorpora() ? 'for example the Corpora edition and year' : 'for example the version number on the Rules of Play';
        return [
            centered(name + ' Questions',
                'This is the question bank for your kingdom\'s ' + name + '. ' + aboutTest() + ' ' +
                'You write questions here, gather them into a version, and publish that version to make it the test players take.'),

            step(visible('.qt-page .rp-context'), 'The test at a glance',
                'How many active questions you have, how many each player is asked, the score needed to pass, and how long a pass lasts. The settings come from Configure Tests.'),
            step(visible('.qt-page > .qt-unsaved-warning'), 'Settings never saved',
                'This test\'s settings have never been saved, so it runs on defaults. Open Configure Tests and press Save Settings to make them yours.'),
            step(visible('.qt-notlive-warning'), 'The test is switched off',
                'Nobody can take this test, not even a published version, until it is switched on for the kingdom. Only the monarchy can do that, from the Kingdom page\'s Admin button (the cog) and then Configuration. You can keep writing and publishing in the meantime.'),

            // ---- versions ----
            step(function () { var r = liveRow(); return shown(r) ? r : null; }, 'Versions',
                'Players are only ever asked questions from the published version. ' + liveStatus() + ' A test is live when a version is published, it has at least as many questions as each test draws, and the test is switched on.'),
            step(firstShown('.qt-versions .qt-ver-rename'), 'Rename a version',
                'Versions are numbered for you (Version 1, Version 2). Click the pencil to give one a name that will still make sense later, such as the season it ran. Players see the name in their test history.',
                'bottom', 'center'),
            step(firstShown('.qt-versions .qt-ver-editlabel'), label,
                'The ' + label + ' of the live test, shown to players as a footer on every question and stamped on each attempt. Click the pencil to fix a typo. Past attempts keep the label they were taken under.',
                'bottom', 'center'),
            step(visible('#qt-newdraft-btn'), 'Start next version',
                'When the rules or the Corpora change, start a new version here. It copies the live questions into a draft. New questions go into the draft, and players keep taking the current test unchanged until you publish.',
                'bottom', 'end'),
            step(visible('.qt-ver-draftrow'), 'The draft',
                'The version you are building. Players never see a draft. If nothing is live yet, this is your first version; saving your first question creates it for you.'),
            step(visible('#qt-draft-version'), 'The draft\'s ' + label,
                'Which ' + (isCorpora() ? 'Corpora' : 'Rules of Play') + ' this draft is based on, ' + labelExample + '. It is required before you can publish, and it saves as you type.'),
            step(visible('#qt-publish-btn'), 'Publish',
                'Makes the draft the live test after you confirm, and keeps the old one as a previous version. It stays greyed out until the draft has a ' + label + ' and at least as many questions as each test draws; point at it to see what is missing.',
                'bottom', 'end'),
            step(visible('#qt-discard-btn'), 'Discard',
                'Throws the draft away after you confirm. The questions themselves are kept; any that were only in the draft are marked Unused.',
                'bottom', 'end'),
            step(visible('.qt-ver-past-wrap > summary'), 'Previous versions',
                'Every version you have published before, kept for reference. Click to open the list, then View to read one back. Old versions cannot be edited.'),

            // ---- adding questions ----
            step(visible('.qt-page .rp-header-actions a[href*="QualTest/question/create/"]'), 'Add Question',
                'Write one question at a time: the question, its answer choices, and which answers are correct. New questions go into the draft when there is one, otherwise straight into the live version.',
                'bottom', 'end'),
            step(visible('#qt-bulkimport-btn'), 'Bulk Import',
                'Paste a batch of questions as plain text: a blank line between questions, the question on the first line, then one answer per line with a * in front of each correct answer. You see a preview before anything is saved, up to 200 questions at a time.',
                'bottom', 'end'),
            step(visible('#qt-library-btn'), 'Add from Library',
                'Browse Reeve\'s questions that other kingdoms share and add a copy of any you want. Your copy is your own: editing it does not change theirs. This button is here because your kingdom has opted in to sharing.',
                'bottom', 'end'),

            // ---- the question list ----
            step(visible('.qt-page .rp-empty-state'), 'No questions yet',
                'Add the first question here, or use Bulk Import to paste a whole batch.'),
            step(visible('#qt-active-table_filter'), 'Search',
                'Type a word to filter the list to the questions and answers that contain it.', 'bottom', 'end'),
            step(visible('#qt-active-table thead tr'), 'Sorting',
                'Click a column heading to sort by it. The list starts with the newest questions first. There is no question order to set: each player gets a random draw, and the answers are shuffled too.',
                'bottom', 'center'),
            step(rowCell(2), 'Version badges',
                '<b>Live</b>: the question is in the published test. <b>Draft</b>: it is in the draft. <b>Unused</b>: it is in no version and nobody is asked it. A globe badge marks a copy from the shared library. <b>multi</b> marks a select-all-that-apply question.',
                'bottom', 'start'),
            step(rowCtl('.qt-draft-toggle'), 'Add to or remove from the draft',
                'Choose which questions the next version includes. Removing a question from the draft does not archive it; it stays in the live test until you publish.',
                'bottom', 'start'),
            step(rowCell(3), 'Answers',
                'How many answer choices the question has, with the correct answer underneath. A red "no correct!" badge means no answer is marked correct; edit the question to fix it.',
                'bottom', 'center'),
            step(rowCell(4), '% Success',
                'How often players get this question right: green is 81% or more, yellow 61 to 80%, red 60% or less. A very low rate can point to a confusing or wrong question.',
                'bottom', 'center'),
            step(rowCtl('.qt-action-btn-edit'), 'Edit',
                'Change the wording, the answers, or which answer is correct. If the question is in the live test, players see the change on their next attempt.',
                'left', 'center'),
            step(rowCtl('.qt-action-btn-archive'), 'Archive',
                'Takes the question out of use after you confirm, so it is no longer asked. Nothing is deleted: it moves to Archived Questions and can be restored.',
                'left', 'center'),
            step(rowCtl('.qt-reset-btn'), 'Reset Stats',
                'Sets the question\'s success rate back to zero after you confirm. Useful after rewording a question, so old answers stop counting.',
                'left', 'center'),
            step(rowCtl('.qt-dup-btn'), 'Duplicate',
                'Makes a copy, after you confirm, starting with "Copy of" and with the same answers, as a starting point for a similar question. The copy is not in any version until you add it to a draft.',
                'left', 'center'),
            step(firstShown('#qt-active-table .qt-report-flag-btn'), 'Player reports',
                'Players flagged this question as badly worded, marked wrong, out of date, or something else. Click to read the reasons, then edit it, archive it, or clear the flags.',
                'left', 'center'),
            step(rowCtl('.qt-active-cb'), 'Select several',
                'Tick questions to archive several at once: a bar appears at the bottom of the screen with Archive Selected. The box in the heading selects every question on this page of the list.',
                'right', 'center'),
            step(visible('#qt-archived-toggle'), 'Archived Questions',
                'Archived questions are kept here, folded away. Click to open the list; each one has a Restore button to put it back into use.',
                'top', 'start'),

            // ---- sidebar ----
            step(firstShown('.qt-page .rp-sidebar .qt-preview-open'), 'Preview',
                'Draws a sample test from that version, the way a player would get it, with the correct answers marked. Draw Again picks a new random set. Nothing is recorded.',
                'right', 'start'),
            step(firstShown('.qt-page .rp-sidebar a[href*="QualTest/export/"]'), 'Export',
                'Downloads the version\'s questions as a text file in the same format Bulk Import reads, so you can review or edit them in any text editor and paste them back.',
                'right', 'start'),

            finish('The usual order for a new test: save its settings on Configure Tests, write or import questions, fill in the ' + label + ' on the draft, then publish. If the test is still switched off, ask the monarchy to turn it on.')
        ];
    }

    // ---------- One question ----------

    function editorSteps() {
        var editing = !!d.querySelector('#qt-question-form input[name="QuestionId"]:not([value="0"])');
        var name = testName();
        return [
            centered(editing ? 'Editing a question' : 'Writing a question',
                'Every question on the ' + name + ' needs its wording, at least two answer choices, and at least one answer marked correct. ' +
                'Players see the answers in a random order, so the order you type them in does not matter.'),

            step(visible('.qt-live-edit-warning'), 'This question is live',
                'Saving changes it for players right away, which is right for fixing a mistake. To rework it for a future version instead, add a new question to the draft and remove this one from the draft.'),
            step(visible('.qt-draft-target-note'), 'Where it goes',
                'New questions go into the draft named here, and stay out of the live test until you publish it.'),

            step(visible('#qt-question-text'), 'Question Text',
                'Write the question as players will see it. ' +
                (isCorpora()
                    ? 'Base it on the current edition of your kingdom\'s Corpora.'
                    : 'Base it on the current Amtgard Rules of Play.')),
            step(function () {
                var r = d.getElementById('qt-mode-single');
                var f = r && r.closest('.qt-field');
                return shown(f) ? f : null;
            }, 'Answer Mode',
                '<b>Single correct answer</b>: players pick one. <b>Multiple correct</b>: players see checkboxes and must tick every correct answer and none of the wrong ones. A multiple-correct question may have just one right answer.'),
            step(visible('.qt-quick-answers'), 'Quick Answers',
                'Fills the choices with True / False or Yes / No in one click, replacing what is in the list.'),
            step(firstShown('#qt-answers-list .qt-answer-row input[type="radio"], #qt-answers-list .qt-answer-row input[type="checkbox"]'), 'Mark the correct answer',
                'Select the button beside the correct answer. In multiple-correct mode these become checkboxes, so you can tick each correct answer.',
                'right', 'center'),
            step(firstShown('#qt-answers-list .qt-answer-row input[type="text"]'), 'Answer choices',
                'Type one choice per box. Empty boxes are skipped when you save.'),
            step(firstShown('#qt-answers-list .qt-remove-btn'), 'Remove a choice',
                'Removes that choice. A question always keeps at least two.', 'left', 'center'),
            step(visible('#qt-add-answer'), 'Add another answer',
                'Adds one more choice to the list.'),
            step(visible('#qt-question-form .qt-submit-btn[data-action="return"]'), 'Save and Return',
                'Saves the question and takes you back to the question list.', 'top', 'start'),
            step(visible('#qt-question-form .qt-submit-btn[data-action="another"]'), 'Save and Add Another',
                'Saves the question and opens a blank one, for writing several in a row.', 'top', 'start'),
            step(visible('#qt-question-form .qt-cancel-btn'), 'Cancel',
                'Goes back to the question list without saving.', 'top', 'start'),

            finish('Nothing is saved until you press a Save button.')
        ];
    }

    T.register(CONFIGURE, {
        title: TITLE,
        version: 1,
        audience: loggedIn,
        match: onConfigure,
        steps: configureSteps
    });
    T.register(BUILDER, {
        title: TITLE,
        version: 1,
        audience: loggedIn,
        match: onQuestions,
        steps: questionsSteps
    });
    T.register(QUESTION, {
        title: TITLE,
        version: 1,
        audience: loggedIn,
        match: onEditor,
        steps: editorSteps
    });

    // ---------- "new" dots on the links that lead to each page ----------

    // Not inside frames; logged out never sees these links anyway.
    if (!loggedIn() || w.self !== w.top) { return; }

    // Each entry is a set of links and the tour waiting at the other end.
    // The kingdom Admin Tasks -> Tests links render only for people who can manage
    // tests (CanManageKingdom || CanManageTests), so the dots reach the right people.
    var LINKS = [
        { sel: '#kn-tab-admin a[href*="QualTest/manage/"]', id: CONFIGURE },
        { sel: '#kn-tab-admin a[href*="QualTest/questions/"]', id: BUILDER },
        // Configure Tests: each card's View Questions and Add Question buttons.
        { sel: '.qt-link-row a.qt-link-btn-primary[href*="QualTest/questions/"]', id: BUILDER },
        { sel: '.qt-link-row a.qt-link-btn-ghost[href*="QualTest/question/create/"]', id: QUESTION },
        // Question bank: Add Question (header and empty state), and the way back to the settings.
        { sel: '.qt-page .rp-header-actions a[href*="QualTest/question/create/"], .qt-page .rp-empty-state a[href*="QualTest/question/create/"]', id: QUESTION },
        { sel: '.qt-page .qt-versions ~ .rp-body a.qt-nav-link[href*="QualTest/manage/"]', id: CONFIGURE }
    ];

    // The Admin Tasks tab itself, so the dots are noticed from the tab bar.
    var TAB = '.kn-tab-nav li[data-kntab="admin"]';

    function pointAt(links, id) {
        Array.prototype.forEach.call(links, function (a) {
            var href = a.getAttribute('href');
            if (!href || /[?&]ork_tour=/.test(href)) { return; }
            var hash = '';
            var h = href.indexOf('#');
            if (h !== -1) { hash = href.slice(h); href = href.slice(0, h); }
            a.setAttribute('href', href + (href.indexOf('?') === -1 ? '?' : '&') + 'ork_tour=' + id + hash);
        });
    }

    function mark() {
        LINKS.forEach(function (l) {
            if (T.seen(l.id)) { return; }
            var links = d.querySelectorAll(l.sel);
            if (!links.length) { return; }
            T.dot(l.sel, l.id);
            pointAt(links, l.id);
            if (l.sel.indexOf('#kn-tab-admin') === 0) { T.dot(TAB, l.id); }
        });
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', mark);
    } else {
        mark();
    }
})(window, document);
