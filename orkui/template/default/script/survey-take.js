/* ==========================================================================
   survey-take.js — the survey RUNNER (spec §7 "Runner", §2 consent copy).

   One IIFE, configured by window.SvConfig emitted by Survey_take.tpl:

       { uir: 'index.php?Route=', surveyId: 123, preview: false, canManage: false,
         viewer: 42, definition: {<definition payload>} | null,
         csrf: '<session token>' }

   Every SurveyAjax POST mutation (draft_save, submit) sends csrf as the
   X-CSRF-Token header; a {csrf:true} reply gets an inline Reload notice.

   It owns exactly one DOM subtree (#sv-stage) and never builds question markup
   itself — every question comes from SvRender (survey-render.js), so the
   builder canvas and this page cannot drift apart.

   Screen model
   ------------
   The survey is a flat list of screens, recomputed after every answer because
   show-if can add or remove whole pages:

       welcome?  ->  visible pages  ->  final (consent / submit)  ->  thanks

   `final` exists when the survey has a data gate, or when a manager is in
   preview mode (that is where "Submit as test" lives). Otherwise the last
   page's forward button says "Submit" and posts straight away.

   Show-if parity
   --------------
   svIsShown() / svSelects() below are a line-by-line port of
   SurveyTypes::isShown() / ::selects() (system/lib/ork3/class.SurveyTypes.php)
   and satisfy the same truth table as
   tests/Unit/SurveyTypesTest.php::testIsShownQuestionLevel(). Like the server's
   SurveyResponse::validateSubmission(), visibility is evaluated against the
   answers of questions that are THEMSELVES visible, accumulated in survey
   order — so a condition can never depend on a hidden question. If you change
   one side, change the other.
   ========================================================================== */

(function (window, document) {
    'use strict';

    var CFG = window.SvConfig || {};
    var R = window.SvRender;

    var SURVEY_ID = parseInt(CFG.surveyId, 10) || 0;
    var IS_PREVIEW = CFG.preview === true;
    var UIR = String(CFG.uir || 'index.php?Route=');
    var CSRF = String(CFG.csrf || '');
    // Preview keeps its own mirror: a manager's abandoned preview must never
    // pre-fill their real run of the same survey (and vice versa).
    // Keyed and stamped by player too: on a shared device the next player must
    // never inherit (and submit) the last one's answers and consent (#6).
    var VIEWER = parseInt(CFG.viewer, 10) || 0;
    var SS_PREFIX = 'sv:answers:';
    var SS_OWN = SS_PREFIX + VIEWER + ':';
    var SS_KEY = SS_OWN + SURVEY_ID + (IS_PREVIEW ? ':preview' : '');
    var MIRROR_DELAY = 300;   // ms — sessionStorage write debounce (#25)
    var DRAFT_DELAY = 1500;   // ms — server draft autosave debounce (#26)
    // Pairwise picks land every 1.5-2.5s for 36-78 matchups, so the 1500ms
    // debounce never coalesced anything: nearly every pick became its own POST
    // of the whole answer map. A longer window while the respondent is picking
    // turns a burst into one save; the payload and the contract are unchanged.
    var DRAFT_DELAY_PAIRWISE = 5000;
    var DRAFT_MAX_WAIT = 12000;   // ms — an answer never sits unsent longer than this

    // Ineligibility reasons come from SurveyResponse::eligibility().
    var REASONS = {
        not_open_yet:      'This survey is not open yet. Check back soon.',
        closed:            'This survey has closed. Thank you for your interest.',
        completed:         'You have already completed this survey. Thank you!',
        inactive:          'This survey is open to active players only.',
        scope:             'This survey is not available to your kingdom.',
        tenure:            'This survey is open to players who have been playing longer.',
        recent_attendance: 'This survey is open to players who have attended recently.',
        event_attendance:  'This survey is open to players who attended the event it asks about.',
        banned:            'This survey is not available on your account.'
    };
    var REASON_FALLBACK = 'This survey is not available to you right now.';

    // Fixed consent copy — spec §2. Do not reword; it is not per-survey editable.
    // The option names are the survey owner's; only the explanations name who
    // will see the answers (review #3).
    var CONSENT_HEADING = 'Help us understand these results';
    var CONSENT_INTRO = 'Your answers are recorded either way. Choose what the ORK may attach to them:';
    // Shown on the first screen whenever the survey has a data gate, so nobody
    // answers a sensitive question assuming their name is already attached.
    var GATE_NOTE = 'At the end you\'ll choose whether your answers are linked to your profile, ' +
        'kept to your kingdom and years played, or fully anonymous.';
    // Fixed copy — spec §3.7. Do not reword.
    var CREDIT_NOTE = 'This survey gives an attendance credit, which will appear on your public attendance record. ' +
        'It is only given when you choose ';
    // Every other data gate: a kingdom or park may turn credits on after this
    // respondent answers, and a backfill then credits only those who were told
    // (spec D1), so the gate always says one or the other.
    var CREDIT_MAYBE = 'This survey may later give an attendance credit, which would appear on your public attendance record. ' +
        'It is only given when you choose ';
    var CREDIT_CHIP = 'Earns an attendance credit';

    /** The three consent options, with the full option naming who runs the survey. */
    function consentOptions(s) {
        var label = String((s && s.scope_label) || '').trim();
        var who;
        if (String((s && s.scope_type) || '') === 'ork') {
            who = 'The ORK administrators who run this survey, now and in future administrations, ' +
                'will see my name beside my answers, including in exported spreadsheets.';
        } else if (String((s && s.manager_label) || '').trim()) {
            // The server names the whole management chain (park -> kingdom ->
            // parent kingdom), exactly who canManage lets see names and CSVs.
            who = String(s.manager_label).trim() +
                ' who run this survey, now and in future reigns, ' +
                'will see my name beside my answers, including in exported spreadsheets.';
        } else {
            // Kingdom names often carry their own article ("The Kingdom of …"):
            // never print "The The Kingdom of … officers".
            who = (/^the\s/i.test(label) ? label : 'The ' + (label || 'survey\'s')) +
                ' officers and ORK administrators who run this survey, now and in future reigns, ' +
                'will see my name beside my answers, including in exported spreadsheets.';
        }
        return [
            { value: 'full', title: 'Any ORK Data', desc: 'Link my answers to my ORK profile. ' + who },
            {
                value: 'partial',
                title: 'My Kingdom and How Long I\'ve Been Playing',
                desc: 'Record only my kingdom and a years-played range, such as 3–5 years. No name, no profile link.'
            },
            { value: 'anonymous', title: 'Anonymous Only', desc: 'Record nothing about me.' }
        ];
    }

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];

    // ---------------------------------------------------------------- state

    var def = null;          // {survey, pages, draft, eligible, reason}
    var answers = {};        // { question_id: raw value }  (spec §6 shape)
    var screens = [];        // see "Screen model" above
    var idx = 0;
    var startedAt = Date.now();
    var consent = null;      // 'full' | 'partial' | 'anonymous'
    var creditNoticeShown = false;   // the data gate showed a credit line (sent as CreditNotice)
    var submitting = false;
    var resumed = false;     // show the "picked up where you left off" note once
    var serverErrors = null; // { question_id: message } pending paint after a jump
    var finished = false;    // the thank-you screen is up: no more saves
    var mirrorTimer = null;  // pending debounced sessionStorage write
    var draftTimer = null;   // pending debounced server draft save
    var draftSeq = 0;        // only the newest draft_save reply paints the status
    var deferredSince = 0;   // when the pending save was first asked for (DRAFT_MAX_WAIT)
    var saveState = '';      // '' | 'saved' | 'failed' — what the status line shows

    var stage, titleEl, metaEl, progressEl, barEl, progressTextEl, liveEl, politeEl, saveEl;
    // True while the assertive region still holds a "check your answers" message.
    var liveIsValidation = false;

    // -------------------------------------------------------------- helpers

    function esc(s) { return R.escape(s); }

    /** Builder-authored HTML (welcome, thanks, page description) before innerHTML. */
    function safe(html) { return R.sanitize ? R.sanitize(html) : String(html || ''); }

    function el(id) { return document.getElementById(id); }

    function announce(msg) {
        liveIsValidation = false;
        if (liveEl) { liveEl.textContent = String(msg || ''); }
    }

    function announceValidation(msg) {
        announce(msg);
        liveIsValidation = true;
    }

    /** Once no flagged question is left on the page, drop the stale validation message. */
    function clearStaleValidation() {
        if (liveIsValidation && stage && !stage.querySelector('.sv-q-invalid')) { announce(''); }
    }

    /** Non-urgent news (a question appeared below) — never interrupts. */
    function announcePolite(msg) {
        if (!politeEl) { return; }
        // Clear first so the same sentence twice in a row is still spoken.
        politeEl.textContent = '';
        window.setTimeout(function () { politeEl.textContent = String(msg || ''); }, 50);
    }

    function hasOwn(o, k) { return Object.prototype.hasOwnProperty.call(o, k); }

    function resumeAllowed() {
        var s = (def && def.survey) || {};
        return parseInt(s.allow_resume, 10) === 1;
    }

    function post(action, fields) {
        var fd = new FormData();
        var k;
        for (k in fields) {
            if (Object.prototype.hasOwnProperty.call(fields, k)) { fd.append(k, fields[k]); }
        }
        return window.fetch(UIR + 'SurveyAjax/' + action, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': CSRF }
        }).then(function (res) { return res.json(); });
    }

    /** Where "Log in again" goes: the login page, returning to this survey. */
    function loginUrl() {
        return UIR + 'Login/login&return=' +
            encodeURIComponent('Survey/take/' + SURVEY_ID + (IS_PREVIEW ? '/preview' : ''));
    }

    function loginLinkHtml() {
        return '<a class="sv-notice-link" href="' + esc(loginUrl()) + '">Log in again</a>';
    }

    function reloadLinkHtml() {
        return '<a class="sv-notice-link" href="' + esc(window.location.href) + '">Reload the page</a>';
    }

    /* The session's CSRF token no longer matches (a new login in another tab,
       or a very old page). The answers are rescued into this tab first, so the
       Reload link the notice offers really does keep them. */
    function csrfNoticeHtml() {
        return 'Your security token expired. ' + reloadLinkHtml() +
            ' and try again — your answers are kept in this tab.';
    }

    function showCsrfNotice() {
        var n = el('sv-csrf-notice');
        mirrorWrite(true);
        if (!n) {
            n = document.createElement('div');
            n.id = 'sv-csrf-notice';
            n.className = 'sv-notice sv-notice-error';
            n.setAttribute('role', 'alert');
            stage.parentNode.insertBefore(n, stage);
        }
        n.innerHTML = '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ' + csrfNoticeHtml();
    }

    function settingsOf(q) {
        var s = q && q.settings;
        if (typeof s === 'string') {
            try { s = JSON.parse(s); } catch (e) { s = null; }
        }
        return (s && typeof s === 'object') ? s : {};
    }

    function toInt(v) {
        var n = parseInt(v, 10);
        return isFinite(n) ? n : 0;
    }

    /** PHP is_numeric(), near enough: numeric strings with optional exponent. */
    function isNumericLike(v) {
        if (typeof v === 'number') { return isFinite(v); }
        if (typeof v !== 'string') { return false; }
        return /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(v);
    }

    // ------------------------------------------- show-if (SurveyTypes port)

    /** Port of SurveyTypes::selects(). */
    function svSelects(value, optionId) {
        var i, k;
        if (value === null || value === undefined || typeof value === 'boolean') { return false; }
        if (Array.isArray(value)) {
            for (i = 0; i < value.length; i++) {
                if (svSelects(value[i], optionId)) { return true; }
            }
            return false;
        }
        if (typeof value === 'object') {
            if (Object.prototype.hasOwnProperty.call(value, 'option_id')) {
                return svSelects(value.option_id, optionId);
            }
            for (k in value) {
                if (Object.prototype.hasOwnProperty.call(value, k) && svSelects(value[k], optionId)) { return true; }
            }
            return false;
        }
        if (!isNumericLike(value)) { return false; }
        return parseInt(value, 10) === optionId;
    }

    /** Port of SurveyTypes::isShown(). $answers keys are question ids. */
    function svIsShown(item, shownAnswers) {
        var qid = item && item.show_if_question_id !== undefined && item.show_if_question_id !== null
            ? toInt(item.show_if_question_id) : 0;
        var oid = item && item.show_if_option_id !== undefined && item.show_if_option_id !== null
            ? toInt(item.show_if_option_id) : 0;
        if (qid <= 0 || oid <= 0) { return true; }
        if (!Object.prototype.hasOwnProperty.call(shownAnswers, qid)) { return false; }
        return svSelects(shownAnswers[qid], oid);
    }

    /**
     * Walk the survey in order and return the pages (and, per page, the
     * questions) a respondent can currently see. Mirrors the accumulation in
     * SurveyResponse::validateSubmission(): only visible answers feed show-if.
     */
    function visibleModel() {
        var shown = {};
        var out = [];
        var pages = (def && def.pages) || [];
        var i, j, page, qs, q, qid;

        for (i = 0; i < pages.length; i++) {
            page = pages[i];
            if (!svIsShown(page, shown)) { continue; }
            qs = [];
            for (j = 0; j < (page.questions || []).length; j++) {
                q = page.questions[j];
                if (!svIsShown(q, shown)) { continue; }
                qs.push(q);
                qid = toInt(q.question_id);
                shown[qid] = Object.prototype.hasOwnProperty.call(answers, qid) ? answers[qid] : null;
            }
            out.push({ page: page, questions: qs });
        }
        return out;
    }

    /** Answers of currently visible questions only — what we send and draft. */
    function visibleAnswers() {
        var model = visibleModel();
        var out = {};
        var i, j, qid;
        for (i = 0; i < model.length; i++) {
            for (j = 0; j < model[i].questions.length; j++) {
                qid = toInt(model[i].questions[j].question_id);
                if (Object.prototype.hasOwnProperty.call(answers, qid) && answers[qid] !== undefined) {
                    out[qid] = answers[qid];
                }
            }
        }
        return out;
    }

    // --------------------------------------------------------- screen model

    function buildScreens() {
        var s = (def && def.survey) || {};
        var list = [];
        var model = visibleModel();
        var i;

        if (s.welcome_html || s.welcome_image_url) {
            list.push({ kind: 'welcome' });
        }
        for (i = 0; i < model.length; i++) {
            list.push({ kind: 'page', page: model[i].page, questions: model[i].questions });
        }
        if (parseInt(s.data_gate_enabled, 10) === 1 || IS_PREVIEW) {
            list.push({ kind: 'final' });
        }
        if (!list.length) {
            list.push({ kind: 'final' });
        }
        return list;
    }

    function screenIndexOfQuestion(qid) {
        var i, j;
        for (i = 0; i < screens.length; i++) {
            if (screens[i].kind !== 'page') { continue; }
            for (j = 0; j < screens[i].questions.length; j++) {
                if (toInt(screens[i].questions[j].question_id) === toInt(qid)) { return i; }
            }
        }
        return -1;
    }

    function isLastAnswerScreen(i) { return i >= screens.length - 1; }

    // ------------------------------------------------------- local storage

    /* The in-tab mirror (sessionStorage) restores a reload instantly. It
       follows allow_resume like the server draft does: a "one sitting" survey
       keeps nothing across a reload (#25). The one exception is a RESCUE write
       (`force`): a send refused because the session or its token expired
       stores a one-shot copy so "Log in again" / "Reload" loses nothing; boot
       applies it once and drops it. */
    function mirrorWrite(force) {
        var rescue;
        if (mirrorTimer) { window.clearTimeout(mirrorTimer); mirrorTimer = null; }
        if (finished) { return; }
        rescue = !!force && !resumeAllowed();
        if (!force && !resumeAllowed()) { return; }
        try {
            window.sessionStorage.setItem(SS_KEY, JSON.stringify({
                owner: VIEWER,
                answers: answers,
                idx: idx,
                startedAt: startedAt,
                consent: consent,
                rescue: rescue
            }));
        } catch (e) { /* private mode, quota — the server draft is the real store */ }
    }

    /** Debounced mirror write: collect() stays synchronous, the stringify does not. */
    function mirrorSave() {
        if (!resumeAllowed() || finished) { return; }
        if (mirrorTimer) { window.clearTimeout(mirrorTimer); }
        mirrorTimer = window.setTimeout(function () { mirrorTimer = null; mirrorWrite(false); }, MIRROR_DELAY);
    }

    /** Write a pending debounced mirror now (page turn, tab hidden). */
    function mirrorFlush() {
        if (mirrorTimer) { mirrorWrite(false); }
    }

    function mirrorLoad() {
        try {
            var raw = window.sessionStorage.getItem(SS_KEY);
            if (!raw) { return null; }
            var v = JSON.parse(raw);
            if (!v || typeof v !== 'object') { return null; }
            if (v.owner !== VIEWER) { mirrorClear(); return null; }
            return v;
        } catch (e) { return null; }
    }

    /** Drop every other player's mirror left in this tab (shared device, #6). */
    function mirrorPurgeOthers() {
        var ss, i, k, drop = [];
        try {
            ss = window.sessionStorage;
            for (i = 0; i < ss.length; i++) {
                k = ss.key(i);
                if (k && k.indexOf(SS_PREFIX) === 0 && k.indexOf(SS_OWN) !== 0) { drop.push(k); }
            }
            for (i = 0; i < drop.length; i++) { ss.removeItem(drop[i]); }
        } catch (e) { /* storage blocked — nothing to purge */ }
    }

    function mirrorClear() {
        if (mirrorTimer) { window.clearTimeout(mirrorTimer); mirrorTimer = null; }
        try { window.sessionStorage.removeItem(SS_KEY); } catch (e) { /* ignore */ }
    }

    // ------------------------------------------------------------ validation

    /**
     * Client-side required check. The server (SurveyTypes::validateAnswer) is
     * still the authority; this only saves a round trip, so the wording is
     * copied from there rather than invented.
     */
    function validateQuestion(q, value) {
        var type = String(q.type || '');
        var s = settingsOf(q);
        var required = parseInt(q.required, 10) === 1;
        var missing = value === undefined || value === null || value === '' ||
            (Array.isArray(value) && value.length === 0);
        var count, min, max, rows, answeredRows, k, plan;

        if (!R.isAnswerable(type)) { return null; }

        /* Pairwise owns its empty case too: a required one names the gate, as
           SurveyTypes::validatePairwise() does, rather than "required". */
        if (type === 'pairwise') {
            plan = q.pairwise || R.pairwisePlan((q.options || []).filter(function (o) {
                return String(o.role || 'choice') === 'choice';
            }).length);
            count = Array.isArray(value) ? value.length : 0;
            return required && count < plan.gate ? R.pairwiseGateMessage(plan) : null;
        }

        if (missing) {
            if (!required) { return null; }
            // An untouched ranking reads as unanswered (survey-render.js), so
            // say how to answer it rather than the generic line.
            return type === 'ranking'
                ? 'Put these in order, or choose "Keep this order".'
                : 'This question is required.';
        }

        if (type === 'multi') {
            count = Array.isArray(value) ? value.length : 1;
            min = required ? Math.max(1, toInt(s.min_select)) : toInt(s.min_select);
            max = toInt(s.max_select);
            if (min > 0 && count < min) {
                return 'Please select at least ' + min + ' ' + (min === 1 ? 'option' : 'options') + '.';
            }
            if (max > 0 && count > max) {
                return 'Please select at most ' + max + ' ' + (max === 1 ? 'option' : 'options') + '.';
            }
            return null;
        }

        if (type === 'matrix') {
            rows = [];
            for (k = 0; k < (q.options || []).length; k++) {
                if (String(q.options[k].role) === 'row') { rows.push(toInt(q.options[k].option_id)); }
            }
            answeredRows = 0;
            for (k = 0; k < rows.length; k++) {
                if (Object.prototype.hasOwnProperty.call(value, rows[k])) { answeredRows++; }
            }
            // Both grid rules bind a REQUIRED grid only, as on the server
            // (SurveyTypes::validateMatrix): an optional grid may be left partly blank.
            if (required && s.require_all_rows && answeredRows < rows.length) { return 'Please answer every row.'; }
            if (required && answeredRows < 1) { return 'Please answer at least one row.'; }
            return null;
        }

        return null;
    }

    // -------------------------------------------------------------- reading

    /**
     * Pull every VISIBLE question on screen into `answers`. A question hidden
     * by show-if keeps its DOM (and its last answer, in case it comes back) but
     * is never read; visibleAnswers() keeps it out of anything sent.
     *
     * `only` is a .sv-q element: read just that question. Typing re-read every
     * question on the page — a 5x5 matrix's 25 radios and a ranking — on every
     * character, and only the question that fired can have changed. Page turns,
     * show-if rebuilds and submit still call collect() with no argument.
     */
    function collect(only) {
        var scr = screens[idx];
        var i, root, q, v, qid;
        if (!scr || scr.kind !== 'page') { return; }
        for (i = 0; i < scr.questions.length; i++) {
            q = scr.questions[i];
            qid = toInt(q.question_id);
            root = stage.querySelector('.sv-q[data-qid="' + qid + '"]');
            if (!root || root.hidden) { continue; }
            if (only && root !== only) { continue; }
            v = R.read(root, q);
            if (v === undefined) {
                delete answers[qid];
            } else {
                answers[qid] = v;
            }
        }
        mirrorSave();
    }

    // ------------------------------------------------------------- rendering

    /* "Page 2 of 6" / "Welcome" / "Almost done" — the progress caption, also
       spoken on every page turn whether or not the bar is shown. */
    /** {pageNo, pageScreens}: the current question page's number and the page count. */
    function pagePosition() {
        var pageScreens = 0, pageNo = 0, i;
        for (i = 0; i < screens.length; i++) {
            if (screens[i].kind !== 'page') { continue; }
            pageScreens++;
            if (i <= idx) { pageNo = pageScreens; }
        }
        return { pageNo: pageNo, pageScreens: pageScreens };
    }

    function screenLabel() {
        var pos;
        if (!screens.length || !screens[idx]) { return ''; }
        pos = pagePosition();
        if (screens[idx].kind === 'page' && pos.pageScreens > 0) { return 'Page ' + pos.pageNo + ' of ' + pos.pageScreens; }
        if (screens[idx].kind === 'welcome') { return 'Welcome'; }
        return 'Almost done';
    }

    /* The bar uses the SAME numbers as the caption (#22): welcome 0%, the final
       screen 100%, and question page k of n at k/n in between — so "Page 1 of 3"
       never sits over a 25% bar. */
    function progressPct() {
        var scr = screens[idx];
        var pos;
        if (!scr || scr.kind === 'welcome') { return 0; }
        if (scr.kind !== 'page') { return 100; }
        pos = pagePosition();
        return pos.pageScreens > 0 ? Math.round((pos.pageNo / pos.pageScreens) * 100) : 0;
    }

    /** "September 30, 2026" (+ " at 5:00 PM" unless it closes at the end of the day). */
    function closeLabel(v, ts) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(v || ''));
        var mon, out, h, mi, d;
        if (!m) { return ''; }
        // A timed close is an instant: show it in the viewer's own clock
        // (close_at is the server's wall time with no zone). An end-of-day
        // close stays the nominal date the builder picked.
        if (typeof ts === 'number' && isFinite(ts) && m[4] !== undefined && !(m[4] === '23' && m[5] === '59')) {
            d = new Date(ts * 1000);
            h = d.getHours();
            mi = ('0' + d.getMinutes()).slice(-2);
            return MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear() +
                ' at ' + (h % 12 === 0 ? 12 : h % 12) + ':' + mi + ' ' + (h < 12 ? 'AM' : 'PM');
        }
        mon = parseInt(m[2], 10) - 1;
        if (mon < 0 || mon > 11) { return ''; }
        out = MONTHS[mon] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
        if (m[4] !== undefined) {
            h = parseInt(m[4], 10);
            mi = m[5];
            if (!(h === 23 && mi === '59')) {
                out += ' at ' + (h % 12 === 0 ? 12 : h % 12) + ':' + mi + ' ' + (h < 12 ? 'AM' : 'PM');
            }
        }
        return out;
    }

    /** "12 questions · 3 pages", counted over what show-if currently shows. */
    function lengthLabel() {
        var model = visibleModel();
        var n = 0, i, j;
        for (i = 0; i < model.length; i++) {
            for (j = 0; j < model[i].questions.length; j++) {
                if (R.isAnswerable(String(model[i].questions[j].type || ''))) { n++; }
            }
        }
        return n + (n === 1 ? ' question' : ' questions') + ' · ' +
            model.length + (model.length === 1 ? ' page' : ' pages');
    }

    /** The survey's length and close date, as caption parts. */
    function metaParts() {
        var s = (def && def.survey) || {};
        var parts = [lengthLabel()];
        var closes = closeLabel(s.close_at, s.close_ts);
        if (closes) { parts.push('Closes ' + closes); }
        return parts;
    }

    function headerPaint() {
        var s = (def && def.survey) || {};
        var showProgress = parseInt(s.show_progress, 10) === 1;
        var pct, label;

        titleEl.textContent = s.title || 'Survey';
        document.title = (s.title || 'Survey') + ' — ORK';

        if (metaEl) {
            metaEl.textContent = metaParts().join(' · ');
            metaEl.hidden = false;
        }

        if (!showProgress || !screens.length || !screens[idx]) {
            progressEl.hidden = true;
            progressTextEl.hidden = true;
            return;
        }

        pct = progressPct();
        if (pct < 0) { pct = 0; }
        if (pct > 100) { pct = 100; }

        label = screenLabel();

        progressEl.hidden = false;
        progressTextEl.hidden = false;
        // scaleX, not width: headerPaint() runs on every answer, and a layout
        // pass per keystroke/pick is the one thing the header must not cost.
        barEl.style.transform = 'scaleX(' + (pct / 100) + ')';
        progressEl.setAttribute('role', 'progressbar');
        progressEl.setAttribute('aria-valuemin', '0');
        progressEl.setAttribute('aria-valuemax', '100');
        progressEl.setAttribute('aria-valuenow', String(pct));
        progressEl.setAttribute('aria-label', label);
        progressTextEl.textContent = label;
    }

    /** #rgb / #rgba / #rrggbb / #rrggbbaa -> [r, g, b] (alpha ignored). */
    function accentRgb(hex) {
        var h = String(hex || '').replace(/^#/, '');
        var n;
        if (h.length === 3 || h.length === 4) {
            h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2);
        }
        h = h.slice(0, 6);
        if (!/^[0-9a-fA-F]{6}$/.test(h)) { return null; }
        n = parseInt(h, 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    /** Perceived luminance, 0 (black) to 1 (white). */
    function accentLum(rgb) {
        return (0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255;
    }

    function accentMix(rgb, target, amt) {
        var out = [], i;
        for (i = 0; i < 3; i++) { out.push(Math.round(rgb[i] + (target - rgb[i]) * amt)); }
        return out;
    }

    function accentHex(rgb) {
        var s = '#', i, p;
        for (i = 0; i < 3; i++) {
            p = rgb[i].toString(16);
            s += p.length === 1 ? '0' + p : p;
        }
        return s;
    }

    /* A custom accent has to work as BOTH a fill (primary button, progress
       bar) and a foreground (ghost button, selected choice border, thanks
       heading) against the CURRENT theme's card, so it cannot be applied raw:
       a navy accent all but disappears on the dark card and a lemon one on the
       light card. Nudge it into range for the active theme, then hand the
       paired text colour to --sv-accent-contrast — survey.css declares that
       token but nothing else ever sets it, and in dark mode the stylesheet
       prints near-black text on the primary button. */
    function accentPaint() {
        var s = (def && def.survey) || {};
        var root = el('sv-root');
        var dark, rgb, guard = 0;

        if (!root || !s.accent_color || !/^#[0-9a-fA-F]{3,8}$/.test(String(s.accent_color))) { return; }
        rgb = accentRgb(s.accent_color);
        if (!rgb) { return; }

        dark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (dark) {
            while (accentLum(rgb) < 0.6 && guard++ < 12) { rgb = accentMix(rgb, 255, 0.25); }
        } else {
            while (accentLum(rgb) > 0.5 && guard++ < 12) { rgb = accentMix(rgb, 0, 0.2); }
        }

        root.style.setProperty('--sv-accent', accentHex(rgb));
        root.style.setProperty('--sv-accent-contrast', accentLum(rgb) > 0.55 ? '#1a202c' : '#ffffff');
        root.style.setProperty('--sv-accent-soft',
            'rgba(' + rgb[0] + ', ' + rgb[1] + ', ' + rgb[2] + ', ' + (dark ? 0.16 : 0.1) + ')');
    }

    function resumeNote() {
        if (!resumed) { return ''; }
        resumed = false;
        return '<div class="sv-notice"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> ' +
            'We picked up where you left off.</div>';
    }

    function actionsHtml(backLabel, nextLabel, nextClass) {
        var html = '<div class="sv-actions">';
        if (backLabel) {
            html += '<button type="button" class="sv-btn" data-sv-act="back">' +
                '<i class="fas fa-chevron-left" aria-hidden="true"></i> ' + esc(backLabel) + '</button>';
        }
        html += '<button type="button" class="sv-btn sv-btn-primary sv-actions-end ' + (nextClass || '') +
            '" data-sv-act="next" data-label="' + esc(nextLabel) + '">' + esc(nextLabel) +
            ' <i class="fas fa-chevron-right" aria-hidden="true"></i></button>';
        html += '</div>';
        return html;
    }

    function gateOn() {
        return parseInt(((def && def.survey) || {}).data_gate_enabled, 10) === 1;
    }

    function gateNoteHtml(cls) {
        // Without a data gate there is no credit, and credit_available is
        // false server-side anyway — the chip only ever rides along here.
        var chip = (def && def.survey && def.survey.credit_available)
            ? '<span class="sv-chip sv-chip-credit"><i class="fas fa-award" aria-hidden="true"></i> ' + esc(CREDIT_CHIP) + '</span> '
            : '';
        return chip + '<p class="' + cls + '"><i class="fas fa-user-shield" aria-hidden="true"></i> ' + esc(GATE_NOTE) + '</p>';
    }

    function renderWelcome() {
        var s = def.survey;
        var html = resumeNote() + '<section class="sv-card">';
        var parts = metaParts();
        var i;
        if (s.welcome_image_url) {
            html += '<div class="sv-q-image"><img class="sv-q-image-img" src="' + esc(s.welcome_image_url) + '" alt=""></div>';
        }
        html += '<div class="sv-intro">' + (s.welcome_html ? safe(s.welcome_html) : '<p>' + esc(s.description || '') + '</p>') + '</div>';
        html += '<ul class="sv-welcome-meta">';
        for (i = 0; i < parts.length; i++) {
            html += '<li><i class="fas ' + (i === 0 ? 'fa-list-check' : 'fa-calendar-xmark') + '" aria-hidden="true"></i> ' +
                esc(parts[i]) + '</li>';
        }
        html += '</ul>';
        if (gateOn()) { html += gateNoteHtml('sv-gate-note'); }
        html += '</section>';
        html += actionsHtml('', 'Start');
        return html;
    }

    function nextLabelFor(i) { return isLastAnswerScreen(i) ? 'Submit' : 'Next'; }

    /**
     * Every question of the page is rendered ONCE; the ones show-if currently
     * hides carry the `hidden` attribute. A same-page show-if answer then only
     * toggles attributes (syncVisibility) instead of rebuilding the stage — no
     * scroll jump, no lost focus or caret (#16).
     */
    function renderPage(scr) {
        var page = scr.page;
        var all = page.questions || [];
        var shown = {};
        var html = resumeNote();
        var i, qid;

        for (i = 0; i < scr.questions.length; i++) { shown[toInt(scr.questions[i].question_id)] = true; }

        // No welcome screen: the data-gate heads-up goes above the first page.
        if (gateOn() && idx === 0 && (!screens[0] || screens[0].kind === 'page')) {
            html += '<div class="sv-notice sv-gate-notice">' + gateNoteHtml('sv-gate-note-inline') + '</div>';
        }

        html += '<section class="sv-page" data-page="' + toInt(page.page_id) + '">';
        if (page.title) { html += '<h2 class="sv-page-title">' + esc(page.title) + '</h2>'; }
        if (page.description_html) { html += '<div class="sv-intro sv-page-desc">' + safe(page.description_html) + '</div>'; }

        html += '<div class="sv-notice sv-page-empty"' + (scr.questions.length ? ' hidden' : '') + '>' +
            'There is nothing to answer on this page.</div>';
        for (i = 0; i < all.length; i++) {
            qid = toInt(all[i].question_id);
            html += R.question(all[i], answers[qid], 'take')
                .replace(/^<div class="sv-q /, shown[qid] ? '<div class="sv-q ' : '<div hidden class="sv-q ');
        }
        html += '</section>';
        html += actionsHtml(idx > 0 ? 'Back' : '', nextLabelFor(idx));
        return html;
    }

    /**
     * Apply the current show-if result to the rendered page in place: toggle
     * each question root's `hidden`, relabel Next/Submit if pages came or went,
     * and politely say when something new appeared below.
     */
    function syncVisibility() {
        var scr = screens[idx];
        var shown = {};
        var roots, i, qid, want, added = 0, empty, btn, label;
        if (!scr || scr.kind !== 'page') { return; }

        for (i = 0; i < scr.questions.length; i++) { shown[toInt(scr.questions[i].question_id)] = true; }
        roots = stage.querySelectorAll('.sv-page > .sv-q[data-qid]');
        for (i = 0; i < roots.length; i++) {
            qid = toInt(roots[i].getAttribute('data-qid'));
            want = !shown[qid];
            if (roots[i].hidden === want) { continue; }
            if (!want && R.isAnswerable(roots[i].getAttribute('data-type'))) { added++; }
            roots[i].hidden = want;
            if (want) { R.setError(roots[i], null); }
        }
        clearStaleValidation();

        empty = stage.querySelector('.sv-page-empty');
        if (empty) { empty.hidden = scr.questions.length > 0; }

        btn = stage.querySelector('[data-sv-act="next"]');
        label = nextLabelFor(idx);
        if (btn && btn.getAttribute('data-label') !== label) {
            btn.setAttribute('data-label', label);
            btn.innerHTML = esc(label) + ' <i class="fas fa-chevron-right" aria-hidden="true"></i>';
        }

        headerPaint();
        if (added) {
            announcePolite(added === 1 ? '1 more question added below.' : added + ' more questions added below.');
        }
    }

    function renderFinal() {
        var s = def.survey;
        var gate = parseInt(s.data_gate_enabled, 10) === 1;
        /* A draft can resume straight onto this screen (last page answered,
           Next pressed, then the tab closed), so the resume note belongs here
           too - not only on the welcome and page renderers. */
        var html = resumeNote() + '<section class="sv-card sv-final">';
        var opts = consentOptions(s);
        var i, o;

        if (gate) {
            html += '<h2 class="sv-card-title">' + esc(CONSENT_HEADING) + '</h2>';
            html += '<p class="sv-intro">' + esc(CONSENT_INTRO) + '</p>';
            // Required, and tied to its error box so a screen reader hears the
            // error with the group when submit() focuses the first radio (#20).
            html += '<div class="sv-consent" role="radiogroup" aria-label="' + esc(CONSENT_HEADING) + '"' +
                ' aria-required="true" aria-describedby="sv-final-error">';
            for (i = 0; i < opts.length; i++) {
                o = opts[i];
                // Rendered exactly as spec §2 writes the bullet: bold label,
                // em dash, sentence. Do not split it into two lines — the
                // sentence continues the label and reads wrong on its own.
                html += '<label class="sv-consent-opt">' +
                    '<input type="radio" class="sv-consent-input" name="sv-consent" value="' + o.value + '"' +
                    (consent === o.value ? ' checked' : '') + '>' +
                    '<span class="sv-consent-copy">' +
                    '<span class="sv-consent-title">' + esc(o.title) + '</span>' +
                    ' — ' +
                    '<span class="sv-consent-desc">' + esc(o.desc) + '</span></span></label>';
            }
            html += '</div>';
            // One <span> around the text: .sv-credit-note is a flex row, so bare
            // text and the <strong> would otherwise become separate flex items.
            html += '<p class="sv-credit-note"><i class="fas fa-award" aria-hidden="true"></i><span>' +
                esc(s.credit_available ? CREDIT_NOTE : CREDIT_MAYBE) + '<strong>Any ORK Data</strong>.</span></p>';
            creditNoticeShown = true;
        } else {
            html += '<h2 class="sv-card-title">Ready to send</h2>';
            html += '<p class="sv-intro">Your answers are recorded anonymously. Nothing about you is stored with them.</p>';
        }

        if (IS_PREVIEW) {
            html += '<label class="sv-check"><input type="checkbox" class="sv-check-input" id="sv-test">' +
                '<span>Submit as a test response (saved, flagged as a test, excluded from reporting by default)</span></label>';
            html += '<div class="sv-notice sv-notice-warn">Preview: leave the box unchecked and nothing at all is saved.</div>';
        }

        html += '<div class="sv-q-error" id="sv-final-error" role="alert" hidden></div>';
        html += '</section>';
        html += actionsHtml(idx > 0 ? 'Back' : '', 'Submit');
        return html;
    }

    /* The one way onward from a screen the runner cannot continue past — the
       thank-you, and equally "already completed" / "closed" / "not eligible",
       which otherwise left the browser Back button as the only exit. */
    function homeActionHtml() {
        return '<div class="sv-actions"><a class="sv-btn sv-btn-primary" href="' +
            esc(UIR) + 'Player/index">Back to My Amtgard</a></div>';
    }

    function renderThanks(html, credit, announceText) {
        var out = '<section class="sv-card sv-thanks">';
        finished = true;
        if (draftTimer) { window.clearTimeout(draftTimer); draftTimer = null; }
        draftSeq++;           // a draft reply still in flight must not repaint the status
        setSaveStatus('');
        out += '<h2 class="sv-card-title"><i class="fas fa-circle-check" aria-hidden="true"></i> Thank you</h2>';
        if (def && def.survey && def.survey.thanks_image_url) {
            out += '<div class="sv-q-image"><img class="sv-q-image-img" src="' + esc(def.survey.thanks_image_url) + '" alt=""></div>';
        }
        out += '<div class="sv-intro">' + (html ? safe(html) : '<p>Your response has been recorded.</p>') + '</div>';
        if (credit === 'granted') {
            out += '<p class="sv-credit-note"><i class="fas fa-award" aria-hidden="true"></i><span>Your attendance credit has been added.</span></p>';
        } else if (credit === 'pending') {
            out += '<p class="sv-credit-note"><i class="fas fa-award" aria-hidden="true"></i><span>Your attendance credit will be added shortly.</span></p>';
        }
        out += '</section>';
        out += homeActionHtml();
        stage.innerHTML = out;
        progressEl.hidden = true;
        progressTextEl.hidden = true;
        if (metaEl) { metaEl.hidden = true; }
        // The submit button just vanished from under the user's focus.
        focusScreenStart();
        announce(announceText || 'Thank you. Your response has been recorded.');
        window.scrollTo(0, 0);
    }

    /** Replace the stage with one notice. `extraHtml` is trusted markup (a link). */
    function renderNotice(msg, variant, extraHtml) {
        stage.innerHTML = '<div class="sv-notice ' + (variant || '') + '">' + esc(msg) +
            (extraHtml ? ' ' + extraHtml : '') + '</div>';
        progressEl.hidden = true;
        progressTextEl.hidden = true;
        if (metaEl) { metaEl.hidden = true; }
        announce(msg);
    }

    function paintServerErrors() {
        var scr = screens[idx];
        var first = null;
        var i, q, qid, root, msg;
        if (!serverErrors || !scr || scr.kind !== 'page') { return; }
        for (i = 0; i < scr.questions.length; i++) {
            q = scr.questions[i];
            qid = toInt(q.question_id);
            msg = serverErrors[qid] || serverErrors[String(qid)];
            if (!msg) { continue; }
            root = stage.querySelector('.sv-q[data-qid="' + qid + '"]');
            if (!root || root.hidden) { continue; }
            R.setError(root, msg);
            if (!first) { first = root; }
        }
        serverErrors = null;
        if (first) {
            focusQuestion(first);
            announceValidation('Please check your answers.');
        }
    }

    function render() {
        var scr = screens[idx];
        if (!scr) { return; }

        if (scr.kind === 'welcome') {
            stage.innerHTML = renderWelcome();
        } else if (scr.kind === 'page') {
            stage.innerHTML = renderPage(scr);
        } else {
            stage.innerHTML = renderFinal();
        }

        wireRankings();
        if (R.enhanceDates) { R.enhanceDates(stage); }
        headerPaint();
        paintServerErrors();
        window.scrollTo(0, 0);
    }

    /* SortableJS is dead weight on the great majority of surveys, so it is not
       in the page: it is fetched — same pinned version and SRI hash the template
       used to carry — the first time a screen actually holds a ranking, and the
       ▲▼ buttons carry the question if it never lands. */
    var SORTABLE_SRC = 'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js';
    var SORTABLE_SRI = 'sha512-TelkP3PCMJv+viMWynjKcvLsQzx6dJHvIGhfqzFtZKgAjKM1YPqcwzzDEoTc/BHjf43PcPzTQOjuTr4YdE8lNQ==';
    var sortableAsked = false;

    function loadSortable() {
        var s;
        if (sortableAsked || window.Sortable) { return; }
        sortableAsked = true;
        s = document.createElement('script');
        s.src = SORTABLE_SRC;
        s.integrity = SORTABLE_SRI;
        s.crossOrigin = 'anonymous';
        s.referrerPolicy = 'no-referrer';
        s.async = true;
        // Whatever is on screen when it arrives gets its drag wired; a failure
        // leaves .sv-nodrag in place and nothing else changes.
        s.onload = function () { wireRankings(); };
        document.head.appendChild(s);
    }

    /* Drag for every ranking on the page (SortableJS, fetched on demand above;
       the ▲▼ buttons work without it). A drop renumbers the badges, marks the
       list answered, and fires the same bubbling `change` the arrows do. */
    function wireRankings() {
        var lists = stage.querySelectorAll('.sv-rank');
        var i;
        stage.classList.toggle('sv-nodrag', !window.Sortable);
        if (lists.length && !window.Sortable) { loadSortable(); }
        for (i = 0; i < lists.length; i++) {
            R.reindexRank(lists[i]);
            if (!window.Sortable || lists[i].getAttribute('data-sortable') === '1') { continue; }
            lists[i].setAttribute('data-sortable', '1');
            window.Sortable.create(lists[i], {
                handle: '.sv-rank-handle',
                draggable: '.sv-rank-item',
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function (evt) {
                    var ol = evt.to || evt.from;
                    var ev;
                    R.reindexRank(ol);
                    R.touchRank(ol);
                    try {
                        ev = new Event('change', { bubbles: true });
                    } catch (e) {
                        ev = document.createEvent('Event');
                        ev.initEvent('change', true, false);
                    }
                    ol.dispatchEvent(ev);
                }
            });
        }
    }

    /* render() throws away the whole stage, and with it the Next/Back button
       that had focus — leaving a keyboard user back at <body> and tabbing down
       through the site chrome again on every page turn. Send focus to the top
       of the new screen instead. Only deliberate navigation does this: a
       show-if redraw must not yank focus out of the field being typed in. */
    function focusScreenStart() {
        var target = stage.querySelector('.sv-page-title, .sv-card-title, .sv-notice:not([hidden])') ||
            stage.firstElementChild;
        if (!target) { return; }
        if (!target.hasAttribute('tabindex')) { target.setAttribute('tabindex', '-1'); }
        try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
    }

    /** render() plus the focus move and announcement a page turn owes the user. */
    function renderNav() {
        var label, title;
        render();
        focusScreenStart();
        label = screenLabel();
        title = stage.querySelector('.sv-page-title, .sv-card-title');
        announce(label + (title && title.textContent ? ': ' + title.textContent : ''));
    }

    function focusQuestion(root) {
        var control = root.querySelector('input:not([type="hidden"]), select, textarea, button');
        if (control && control.focus) {
            try { control.focus({ preventScroll: true }); } catch (e) { control.focus(); }
        }
        if (root.scrollIntoView) { root.scrollIntoView({ block: 'center' }); }
    }

    // ---------------------------------------------------------------- flow

    /* The quiet save line in the header. It is itself a polite live region,
       and it is only rewritten when its state changes, so a screen reader
       hears "Saved" once rather than after every pause in typing. */
    function setSaveStatus(state) {
        if (!saveEl || state === saveState) { return; }
        saveState = state;
        saveEl.classList.toggle('sv-save-failed', state === 'failed');
        if (state === 'saved') {
            saveEl.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> Saved';
        } else if (state === 'failed') {
            saveEl.innerHTML = '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ' +
                'Couldn\'t save — kept in this tab';
        } else {
            saveEl.textContent = '';
        }
    }

    function draftSave() {
        var mySeq;
        if (draftTimer) { window.clearTimeout(draftTimer); draftTimer = null; }
        deferredSince = 0;
        if (IS_PREVIEW || finished || !resumeAllowed()) { return; }
        mySeq = ++draftSeq;
        post('draft_save', {
            SurveyId: SURVEY_ID,
            Answers: JSON.stringify(visibleAnswers()),
            PageIndex: idx
        }).then(function (r) {
            if (mySeq !== draftSeq) { return; }
            if (r && r.status === 0) {
                setSaveStatus('saved');
                return;
            }
            // Whatever went wrong, make "kept in this tab" true right now.
            if (r && r.csrf) { showCsrfNotice(); } else { mirrorWrite(true); }
            setSaveStatus('failed');
        })['catch'](function () {
            // A failed draft must never block the runner — it only says so.
            if (mySeq !== draftSeq) { return; }
            mirrorWrite(true);
            setSaveStatus('failed');
        });
    }

    /* On a resumable survey the server draft follows the player as they
       answer (#26), not only on page turns, so switching device mid-page
       loses nothing. */
    function draftSaveSoon(delay) {
        if (IS_PREVIEW || finished || !resumeAllowed()) { return; }
        // A pairwise run asks for a longer delay so a burst of matchup picks
        // coalesces into one save. Re-arming on every pick would defer the save
        // for ever, though — picks land faster than the delay — so DRAFT_MAX_WAIT
        // caps how long an answer may sit unsent: switching device mid-page
        // still loses at most that window, not the whole question.
        if (deferredSince === 0) { deferredSince = Date.now(); }
        if (delay > 0 && Date.now() - deferredSince >= DRAFT_MAX_WAIT) { delay = 0; }
        if (draftTimer) { window.clearTimeout(draftTimer); }
        draftTimer = window.setTimeout(function () { draftTimer = null; draftSave(); },
            delay > 0 ? delay : DRAFT_DELAY);
    }

    function validateCurrentPage() {
        var scr = screens[idx];
        var first = null;
        var i, q, qid, root, msg;
        if (!scr || scr.kind !== 'page') { return true; }

        for (i = 0; i < scr.questions.length; i++) {
            q = scr.questions[i];
            qid = toInt(q.question_id);
            root = stage.querySelector('.sv-q[data-qid="' + qid + '"]');
            if (!root || root.hidden) { continue; }
            msg = validateQuestion(q, answers[qid]);
            R.setError(root, msg);
            if (msg && !first) { first = root; }
        }

        if (first) {
            focusQuestion(first);
            announceValidation('Please check the highlighted question before continuing.');
            return false;
        }
        return true;
    }

    /** Once an answer fixes a flagged question, take the flag down (never add one while typing). */
    function clearFixedError(target) {
        var scr = screens[idx];
        var root = target && target.closest ? target.closest('.sv-q') : null;
        var qid, i;
        if (!root || !root.classList.contains('sv-q-invalid') || !scr || scr.kind !== 'page') { return; }
        qid = toInt(root.getAttribute('data-qid'));
        for (i = 0; i < scr.questions.length; i++) {
            if (toInt(scr.questions[i].question_id) === qid) {
                if (!validateQuestion(scr.questions[i], answers[qid])) {
                    R.setError(root, null);
                    clearStaleValidation();
                }
                return;
            }
        }
    }

    function goNext() {
        collect();
        rebuildScreens();
        if (!validateCurrentPage()) { return; }
        if (isLastAnswerScreen(idx)) {
            draftSave();
            submit();
            return;
        }
        idx++;
        // AFTER the advance, never before: the draft's PageIndex is where the
        // respondent resumes, so saving it first parks them a page behind.
        draftSave();
        mirrorWrite(false);
        renderNav();
    }

    function goBack() {
        collect();
        rebuildScreens();
        if (idx > 0) { idx--; }
        draftSave();
        mirrorWrite(false);
        renderNav();
    }

    /** Recompute screens after an answer change, keeping the current screen. */
    function rebuildScreens() {
        var current = screens[idx];
        var next = buildScreens();
        var i;

        if (current && current.kind === 'page') {
            for (i = 0; i < next.length; i++) {
                if (next[i].kind === 'page' && next[i].page.page_id === current.page.page_id) {
                    screens = next;
                    idx = i;
                    return;
                }
            }
            // The current page vanished (its show-if source changed) — land on
            // the nearest following screen instead of falling off the end.
            screens = next;
            idx = Math.min(idx, screens.length - 1);
            return;
        }

        screens = next;
        if (idx > screens.length - 1) { idx = screens.length - 1; }
        if (idx < 0) { idx = 0; }
    }

    function submit() {
        var s = def.survey;
        var gate = parseInt(s.data_gate_enabled, 10) === 1;
        var errEl = el('sv-final-error');
        var testBox = el('sv-test');
        var isTest = !!(testBox && testBox.checked);
        var btn = stage.querySelector('[data-sv-act="next"]');
        var group, radio;

        if (submitting) { return; }

        if (gate && screens[idx] && screens[idx].kind === 'final') {
            if (!consent) {
                if (errEl) {
                    errEl.textContent = 'Please choose what the ORK may record with your answers.';
                    errEl.hidden = false;
                }
                // Focus the choice itself: the group's aria-describedby reads the
                // error with it, and the role="alert" box covers everyone else.
                group = stage.querySelector('.sv-consent');
                radio = stage.querySelector('.sv-consent-input');
                if (group) { group.setAttribute('aria-invalid', 'true'); }
                if (radio) {
                    try { radio.focus({ preventScroll: true }); } catch (e) { radio.focus(); }
                    if (group && group.scrollIntoView) { group.scrollIntoView({ block: 'center' }); }
                }
                return;
            }
        }

        if (IS_PREVIEW && !isTest) {
            mirrorClear();
            renderThanks('<p>Preview complete — nothing was saved.</p>' +
                (s.thanks_html || ''), 'none', 'Preview complete. Nothing was saved.');
            return;
        }

        submitting = true;
        if (draftTimer) { window.clearTimeout(draftTimer); draftTimer = null; }
        if (btn) { btn.classList.add('sv-is-busy'); btn.disabled = true; }

        post('submit', {
            SurveyId: SURVEY_ID,
            Answers: JSON.stringify(visibleAnswers()),
            Consent: gate ? (consent || 'anonymous') : 'anonymous',
            DurationSeconds: Math.max(0, Math.round((Date.now() - startedAt) / 1000)),
            IsTest: isTest ? 1 : 0,
            // Only a respondent the gate told about credits is ever given one (D1).
            CreditNotice: (gate && creditNoticeShown) ? 1 : 0
        }).then(function (r) {
            submitting = false;
            if (btn) { btn.classList.remove('sv-is-busy'); btn.disabled = false; }

            if (r && r.status === 0) {
                mirrorClear();
                // The site banner promoting this survey is stale once it is answered.
                var banner = document.getElementById('ork-survey-banner');
                if (banner && +banner.getAttribute('data-survey-id') === +SURVEY_ID) { banner.remove(); }
                renderThanks(r.thanks_html || s.thanks_html || '', r.credit || 'none');
                return;
            }
            if (r && r.status === 5) {
                // Keep the screen, rescue the answers AND the consent choice
                // into this tab, and send them to log in and come back (#24).
                mirrorWrite(true);
                failFinal('Your session expired.', loginLinkHtml() + ' — your answers are kept in this tab.');
                return;
            }
            if (r && r.csrf) {
                mirrorWrite(true);
                failFinal('', csrfNoticeHtml());
                return;
            }
            if (r && r.status === 1 && r.errors) {
                showSubmitErrors(r.errors);
                return;
            }
            failFinal((r && r.error) || 'Your response could not be saved. Please try again.');
        })['catch'](function () {
            submitting = false;
            if (btn) { btn.classList.remove('sv-is-busy'); btn.disabled = false; }
            failFinal('Your response could not be saved — check your connection and try again.');
        });
    }

    /**
     * Report a submit failure WITHOUT throwing the screen away: the answers a
     * player just typed are still in the DOM and must survive a failed send.
     * `extraHtml` is trusted markup built here (a login or reload link).
     */
    function failFinal(msg, extraHtml) {
        var errEl = el('sv-final-error');
        var actions;
        if (!errEl) {
            // No consent screen (the last page's button submits): put the
            // notice right above Submit, where the player's eyes and thumb
            // are, not at the top of a long page they cannot see.
            errEl = document.createElement('div');
            errEl.className = 'sv-notice sv-notice-error';
            errEl.setAttribute('role', 'alert');
            errEl.id = 'sv-final-error';
            actions = stage.querySelector('.sv-actions');
            stage.insertBefore(errEl, actions && actions.parentNode === stage ? actions : stage.firstChild);
        }
        errEl.innerHTML = esc(msg) + (extraHtml ? (msg ? ' ' : '') + extraHtml : '');
        errEl.hidden = false;
        if (errEl.scrollIntoView) { errEl.scrollIntoView({ block: 'nearest' }); }
        announce(errEl.textContent);
    }

    /** Jump to the page holding the first server-reported error and show them all. */
    function showSubmitErrors(errors) {
        var target = -1;
        var qid, at;
        for (qid in errors) {
            if (!Object.prototype.hasOwnProperty.call(errors, qid)) { continue; }
            at = screenIndexOfQuestion(qid);
            if (at >= 0 && (target < 0 || at < target)) { target = at; }
        }
        serverErrors = errors;
        if (target < 0) {
            serverErrors = null;
            failFinal('Some answers could not be accepted. Please review the survey and try again.');
            return;
        }
        idx = target;
        render();
    }

    // ------------------------------------------------------------- listeners

    /** The .sv-q an event came from, or null. */
    function qRootOf(target) {
        return target && target.closest ? target.closest('.sv-q') : null;
    }

    function onStageClick(e) {
        var btn = e.target.closest ? e.target.closest('[data-sv-act]') : null;
        if (!btn) { return; }
        var act = btn.getAttribute('data-sv-act');
        if (act === 'next') { goNext(); }
        if (act === 'back') { goBack(); }
    }

    function onStageChange(e) {
        var scr = screens[idx];
        var qRoot = qRootOf(e.target);
        var pageId, errEl, group;

        if (e.target && e.target.name === 'sv-consent') {
            consent = e.target.value;
            errEl = el('sv-final-error');
            if (errEl) { errEl.hidden = true; }
            group = stage.querySelector('.sv-consent');
            if (group) { group.removeAttribute('aria-invalid'); }
            mirrorSave();
            return;
        }
        if (!scr || scr.kind !== 'page') { return; }

        pageId = scr.page.page_id;
        collect();
        rebuildScreens();

        scr = screens[idx];
        if (!scr || scr.kind !== 'page' || scr.page.page_id !== pageId) {
            // The page itself was hidden by this answer: a real screen change.
            render();
        } else {
            // Same page: toggle what show-if hides in place (#16).
            syncVisibility();
            clearFixedError(e.target);
        }
        draftSaveSoon(qRoot && qRoot.getAttribute('data-type') === 'pairwise' ? DRAFT_DELAY_PAIRWISE : 0);
    }

    function onStageInput(e) {
        var scr = screens[idx];
        if (!scr || scr.kind !== 'page') { return; }
        collect(qRootOf(e.target));
        clearFixedError(e.target);
        draftSaveSoon();
    }

    // ------------------------------------------------------------------ boot

    /** A client timestamp worth trusting: in the past and inside a sane window. */
    function saneStart(ms) {
        return typeof ms === 'number' && isFinite(ms) && ms > 0 && ms <= Date.now() &&
            (Date.now() - ms) < 30 * 24 * 3600 * 1000;
    }

    function applyDraft() {
        var mirror = mirrorLoad();
        var draft = def.draft;
        var mirroredIdx = false;
        var useMirror, startMs, k, qk;

        // The mirror follows allow_resume (#25); a rescue copy written when a
        // send was refused is honoured once even on a one-sitting survey.
        useMirror = !!(mirror && (resumeAllowed() || mirror.rescue === true));
        if (mirror && !useMirror) { mirrorClear(); }

        if (useMirror && mirror.answers && typeof mirror.answers === 'object') {
            answers = mirror.answers;
            if (typeof mirror.idx === 'number') { idx = mirror.idx; mirroredIdx = true; }
            if (mirror.consent === 'full' || mirror.consent === 'partial' || mirror.consent === 'anonymous') {
                consent = mirror.consent;
            }
            if (saneStart(mirror.startedAt)) { startedAt = mirror.startedAt; }
            for (k in answers) {
                if (hasOwn(answers, k)) { resumed = true; break; }
            }
            if (mirror.rescue === true && !resumeAllowed()) { mirrorClear(); }
        }

        if (draft && draft.answers && typeof draft.answers === 'object') {
            // The mirror is written as the player types; the draft only every
            // DRAFT_DELAY and on page turns. So an answer this tab already
            // holds is the fresher one: the draft only fills in the questions
            // the mirror lacks (#23).
            for (k in draft.answers) {
                if (!hasOwn(draft.answers, k)) { continue; }
                qk = toInt(k);
                if (!hasOwn(answers, qk)) { answers[qk] = draft.answers[k]; }
            }
            // The server draft is the durable record of the answers, but this
            // tab's own mirror is the fresher record of where they were.
            if (!mirroredIdx && typeof draft.page_index === 'number') { idx = draft.page_index; }
            resumed = true;
        }
        if (draft && typeof draft.started_ts === 'number') {
            // Epoch seconds from the server: started_at itself is the server's
            // zone-less wall time, which the browser would misread as local.
            startMs = draft.started_ts * 1000;
            // The earliest trustworthy start wins, so a reload never shortens
            // the recorded duration.
            if (saneStart(startMs) && startMs < startedAt) { startedAt = startMs; }
        }
    }

    function boot() {
        stage = el('sv-stage');
        titleEl = el('sv-title');
        metaEl = el('sv-meta');
        progressEl = el('sv-progress');
        barEl = el('sv-progress-bar');
        progressTextEl = el('sv-progress-text');
        liveEl = el('sv-live');
        politeEl = el('sv-live-polite');
        saveEl = el('sv-save-status');

        if (!stage || !R) { return; }

        mirrorPurgeOthers();

        stage.addEventListener('click', onStageClick);
        stage.addEventListener('change', onStageChange);
        stage.addEventListener('input', onStageInput);

        // A debounced mirror write still pending when the tab goes away.
        window.addEventListener('pagehide', mirrorFlush);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') { mirrorFlush(); }
        });

        /* Re-derive the accent when the theme toggle stamps html[data-theme]:
           the same accent_color needs a different treatment per theme. */
        if (window.MutationObserver) {
            new window.MutationObserver(function () { accentPaint(); })
                .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        }

        // The page embeds the definition (no round trip on a weak signal); POST
        // for it only when the embed is absent — any server-side failure.
        var embedded = CFG.definition && typeof CFG.definition === 'object' ? CFG.definition : null;
        CFG.definition = null;
        (embedded ? Promise.resolve(embedded)
            : post('definition', { SurveyId: SURVEY_ID, Preview: IS_PREVIEW ? 1 : 0 })).then(function (r) {
            if (!r) {
                renderNotice('This survey could not be loaded.', 'sv-notice-error');
                return;
            }
            if (r.status === 5) {
                renderNotice('Your session expired.', 'sv-notice-warn', loginLinkHtml() +
                    (mirrorLoad() ? ' — your answers are kept in this tab.' : ' to continue.'));
                return;
            }
            if (r.status === 3) {
                renderNotice(r.error || 'You do not have permission to take this survey.', 'sv-notice-error');
                return;
            }
            if (r.status !== 0) {
                renderNotice(r.error || 'This survey could not be loaded.', 'sv-notice-error');
                return;
            }

            def = r;
            accentPaint();
            titleEl.textContent = (def.survey && def.survey.title) || 'Survey';

            if (!def.eligible) {
                // Nothing typed on a shared event laptop outlives a closed door.
                mirrorClear();
                renderNotice(REASONS[def.reason] || REASON_FALLBACK, 'sv-notice-warn', homeActionHtml());
                return;
            }

            applyDraft();
            screens = buildScreens();
            if (idx > screens.length - 1) { idx = screens.length - 1; }
            if (idx < 0) { idx = 0; }
            render();
        })['catch'](function () {
            renderNotice('This survey could not be loaded — check your connection and try again.', 'sv-notice-error');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}(window, document));
