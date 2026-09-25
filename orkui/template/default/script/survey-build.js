/* ==========================================================================
   survey-build.js — the survey builder (spec §7 "Builder").

   One IIFE, named functions, no framework. Configured by window.SvConfig,
   emitted by Survey_build.tpl:

     SvConfig = {
       uir:       'index.php?Route=',
       csrf:      '<64 hex>',   // sent as X-CSRF-Token on every SurveyAjax POST
       surveyId:  999020,
       survey:    { survey:{…}, pages:[…], questions:[…], images:[…], locked:bool }
     }

   `survey` arrives already in the SurveyAjax/get WIRE shape (lowercase
   `options` / `url`), so the bootstrap payload and a later re-fetch are the
   same object and there is exactly one shape in this file.

   THE CANVAS IS THE EDITOR
   -----------------------
   There is no palette and no inspector. One centred column of page cards:

     .svb-page        a page: inline title + description, then its items
       .svb-items     the sortable list
         .svb-item    one question / section / image
       .svb-addbtn    "+ Add Element" under the last item of the page
     .svb-pagediv     "+ Add page" after the last page

   A card that is NOT selected draws itself with SvRender in 'preview' mode —
   exactly what a respondent will see. Clicking it selects it and swaps the
   body for inline editors: a borderless prompt textarea, option rows with a
   label input and a remove button, "+ Add option" links, matrix rows and
   columns, scale end labels, text limits — plus a footer toolbar holding the
   type picker, Required, Duplicate, Delete and a ⋯ menu (show-if, randomize,
   select limits, require-all-rows, rank-all, image).

   Survey-level settings (welcome/thanks copy, audience, schedule, data gate,
   banner, accent…) live in the LEFT SIDEBAR (.rp-sidebar) as a stack of
   collapsible .rp-filter-card sections, per spec §7 "Builder".

   Everything a type can be configured with comes from FIELD_DEFS, which
   mirrors spec §4 key for key. `where` decides whether a key is edited on the
   card itself ('inline') or under the ⋯ menu ('more').

   Saving: every field change goes through save(), which coalesces edits to the
   same target for 400 ms and drives the .svb-savestate pill. Structural
   actions (add / delete / duplicate / reorder / retype) post immediately and
   splice the rows they get back into local state; the full SurveyAjax/get
   re-fetch is kept for resyncing after an error.

   NO LOST KEYSTROKES. A value the server would refuse (a blank prompt, a
   blank title, a cleared option label) is HELD: it is never sent, the field
   carries an inline aria-invalid hint and the pill reads "Not saved" until a
   real value arrives. A refused save shows its error next to the field and
   never repaints the canvas. Any canvas repaint puts the focused field, its
   text and its caret back (snapFocus / restoreSnap), and the leave-page
   prompt fires while anything is pending, held, refused or in flight.

   Locked (`opened_at IS NOT NULL`): structural controls are disabled with a
   data-tip explaining why; prompts, help text, option labels and every survey
   setting stay editable — the same split the domain enforces.
   ========================================================================== */

(function (window, document) {
    'use strict';

    var CFG       = window.SvConfig || {};
    var UIR       = CFG.uir || 'index.php?Route=';
    var CSRF      = String(CFG.csrf || '');
    var SURVEY_ID = parseInt(CFG.surveyId, 10) || 0;
    var SAVE_MS   = 400;
    var NARROW    = 900;

    var LOCK_TIP = 'Locked: this survey has been opened. Wording stays editable; structure does not.';

    /* ---------------------------------------------------------------- state */

    var S = {
        survey:    {},
        pages:     [],
        questions: [],
        images:    [],
        locked:    false
    };

    var sel       = 0;      // selected question_id, 0 = nothing selected
    var scopes    = null;   // SurveyAjax/scopes, lazy
    var managerLabel = '';  // SurveyAjax/scopes manager_label: who manages this survey
    var creditOn  = false;  // the owner's attendance credit is on (credit_status mine.config_id)
    var pending   = {};     // debounce buckets, keyed
    var optBusy   = {};     // option_set key -> { again }: its save is on the wire
    var inflight  = 0;     // every request on the wire
    var inflightWrites = 0; // ...of which change something (not READ_ACTIONS)
    var retyping  = {};     // question_id -> type a retype is on the wire for
    var held      = {};     // save key -> { msg, loc }: a value deliberately NOT sent (blank)
    var pwBase    = {};     // question_id -> pairwise options before the save waiting in its debounce
    var reloadingOnPurpose = false; // the author followed a notice's own "Reload the page" link
    var failed    = {};     // save key -> { msg, loc }: a change the server refused / never got
    var idleWaiters = [];   // run once nothing is on the wire (see whenIdle)
    var events    = null;   // SurveyAjax/event_options, lazy; null = not loaded yet
    var eventsFailed = false;
    var fileJob   = null;   // what the file input is for
    var sortables = [];     // page + item lists
    var optSorts  = [];     // option rows inside the selected card
    var helpOpen  = {};     // question_id -> the help editor is showing
    var modalOpener  = null; // what to hand focus back to when the modal closes

    /* The data-gate wording the runner shows (survey-take.js CONSENT_*, spec
       §2). Kept here read-only for the Privacy section so a builder can see
       exactly what they are asking; the runner remains the single place it is
       authored, so any change there is repeated here word for word. The "full"
       option names who will see the respondent's name: every officer level that
       manages a park or kingdom survey (the server's manager_label), the ORK
       administrators for an ORK-wide one (consentOptions). */
    var CONSENT_COPY = {
        intro:   'Your answers are recorded either way. Choose what the ORK may attach to them:',
        notice:  'At the end you\'ll choose whether your answers are linked to your profile, kept to your kingdom and years played, or fully anonymous.',
        full:    'Link my answers to my ORK profile. The {scope} officers and ORK administrators who run this survey, now and in future reigns, will see my name beside my answers, including in exported spreadsheets.',
        // {managers} is the server's manager_label, the whole management chain.
        fullChain: 'Link my answers to my ORK profile. {managers} who run this survey, now and in future reigns, will see my name beside my answers, including in exported spreadsheets.',
        fullOrk: 'Link my answers to my ORK profile. The ORK administrators who run this survey, now and in future administrations, will see my name beside my answers, including in exported spreadsheets.',
        partial: 'Record only my kingdom and a years-played range, such as 3–5 years. No name, no profile link.',
        anon:    'Record nothing about me.',
        credit:  'This survey gives an attendance credit, which will appear on your public attendance record. It is only given when you choose Any ORK Data.',
        creditMaybe: 'This survey may later give an attendance credit, which would appear on your public attendance record. It is only given when you choose Any ORK Data.'
    };

    /* ------------------------------------------------------------- catalogue */

    var TYPE_META = {
        single:     { label: 'Multiple choice', icon: 'fa-circle-dot',        hint: 'One answer from a list.' },
        multi:      { label: 'Checkboxes',      icon: 'fa-square-check',      hint: 'Any number of answers.' },
        dropdown:   { label: 'Dropdown',        icon: 'fa-caret-square-down', hint: 'One answer from a menu.' },
        yesno:      { label: 'Yes / No',        icon: 'fa-toggle-on',         hint: 'Two fixed options; labels are editable.' },
        rating:     { label: 'Rating',          icon: 'fa-star',              hint: 'A star or number scale.' },
        nps:        { label: 'NPS 0–10',        icon: 'fa-gauge-high',        hint: 'Net promoter score, fixed 0–10.' },
        matrix:     { label: 'Matrix',          icon: 'fa-table-cells',       hint: 'Rows scored against shared columns.' },
        ranking:    { label: 'Ranking',         icon: 'fa-arrow-down-1-9',    hint: 'Put the options in order.' },
        pairwise:   { label: 'Pairwise',        icon: 'fa-code-compare',      hint: 'Pick the better of two, many times.' },
        short_text: { label: 'Short text',      icon: 'fa-i-cursor',          hint: 'One line of text.' },
        paragraph:  { label: 'Paragraph',       icon: 'fa-align-left',        hint: 'A longer written answer.' },
        number:     { label: 'Number',          icon: 'fa-hashtag',           hint: 'A numeric answer.' },
        date:       { label: 'Date',            icon: 'fa-calendar-day',      hint: 'A calendar date.' },
        section:    { label: 'Section',         icon: 'fa-heading',           hint: 'A heading and blurb; records nothing.' },
        image:      { label: 'Image',           icon: 'fa-image',             hint: 'An illustration; records nothing.' }
    };

    /**
     * WHAT a type is comes from the server, not from here: the type list and
     * its order, which types a show_if rule may read, and which option roles a
     * type owns are fetched once from SurveyAjax/types (SurveyTypes, the one
     * catalogue the builder, the runner and the aggregator share). This file
     * keeps only the presentation of a type — its label, icon, hint and the
     * editor chrome for a role — so adding or reordering a type server-side
     * needs no change here beyond a TYPE_META entry.
     */

    /** Every element the footer Type picker offers, in server order. */
    var TYPE_ORDER = [];

    /** The type "+ Add Element" starts from. */
    var STARTER_TYPE = 'single';

    /** Types a show_if condition may depend on. */
    var SHOW_IF_SOURCES = [];

    /** type -> [spec], built from the server's option roles at boot. */
    var OPTION_ROLES = {};

    /** Editor chrome for one option role, whichever type owns it. */
    var ROLE_UI = {
        choice: { label: 'Options', min: 2, other: true },
        row:    { label: 'Rows',    min: 1, other: false },
        column: { label: 'Columns', min: 2, other: false, weight: true }
    };

    /** Per-type departures from ROLE_UI (yes/no labels are fixed at two; ranking and
        pairwise take no "Other"; pairwise needs at least three options). */
    var ROLE_UI_BY_TYPE = {
        yesno:    { choice: { label: 'Labels', other: false, fixed: true } },
        ranking:  { choice: { other: false } },
        pairwise: { choice: { other: false, min: 3 } }
    };

    /**
     * Adopt the server catalogue: {types, show_if_sources, option_roles}.
     * Every role spec is ROLE_UI merged with any per-type override, so the
     * server decides WHICH roles exist and this file decides how they look.
     */
    function applyCatalog(cat) {
        var roles = (cat && cat.option_roles) || {};
        TYPE_ORDER      = ((cat && cat.types) || []).slice();
        SHOW_IF_SOURCES = ((cat && cat.show_if_sources) || []).slice();
        OPTION_ROLES    = {};

        Object.keys(roles).forEach(function (type) {
            OPTION_ROLES[type] = (roles[type] || []).map(function (role) {
                var spec = { role: role, label: role, min: 1, other: false };
                [ROLE_UI[role], (ROLE_UI_BY_TYPE[type] || {})[role]].forEach(function (src) {
                    Object.keys(src || {}).forEach(function (k) { spec[k] = src[k]; });
                });
                return spec;
            });
        });
    }

    /**
     * Per-type settings controls — spec §4, key for key.
     * kind:  bool | int | number | text | select | date
     *   int    always sends a number; blank falls back to `def`.
     *   number sends '' when blank, which the domain stores as NULL.
     * where: 'inline' sits in the card's type editor, 'more' under the ⋯ menu.
     */
    var FIELD_DEFS = {
        single: [
            { key: 'randomize', kind: 'bool', where: 'more', def: false,
              label: 'Shuffle the options for each respondent' }
        ],
        multi: [
            { key: 'randomize', kind: 'bool', where: 'more', def: false,
              label: 'Shuffle the options for each respondent' },
            { key: 'min_select', kind: 'int', where: 'more', label: 'Fewest answers', def: 0, min: 0, max: 50,
              hint: '0 means no minimum.' },
            { key: 'max_select', kind: 'int', where: 'more', label: 'Most answers', def: 0, min: 0, max: 50,
              hint: '0 means no cap.' }
        ],
        dropdown: [],
        yesno:    [],
        rating: [
            // 'hidden' renders nowhere but is still carried into every save, so a
            // key the builder does not expose never silently resets to its default.
            { key: 'min', kind: 'int', where: 'hidden', def: 1 },
            { key: 'max', kind: 'select', where: 'inline', label: 'Points', def: 5,
              options: [['3', '3 points'], ['4', '4 points'], ['5', '5 points'], ['6', '6 points'],
                        ['7', '7 points'], ['8', '8 points'], ['9', '9 points'], ['10', '10 points']] },
            { key: 'icon', kind: 'select', where: 'inline', label: 'Style', def: 'star',
              options: [['star', 'Stars'], ['number', 'Numbers']] },
            { key: 'min_label', kind: 'text', where: 'inline', label: 'Label at the low end', def: '',
              max: 80, placeholder: 'e.g. Poor' },
            { key: 'max_label', kind: 'text', where: 'inline', label: 'Label at the high end', def: '',
              max: 80, placeholder: 'e.g. Excellent' }
        ],
        nps: [
            { key: 'min_label', kind: 'text', where: 'inline', label: 'Label at 0', def: 'Not likely', max: 80 },
            { key: 'max_label', kind: 'text', where: 'inline', label: 'Label at 10', def: 'Very likely', max: 80 }
        ],
        matrix: [
            { key: 'require_all_rows', kind: 'bool', where: 'more', def: false,
              label: 'Every row must be answered' }
        ],
        ranking: [
            { key: 'rank_all', kind: 'bool', where: 'more', def: true,
              label: 'Every option must be ranked' }
        ],
        pairwise: [],
        short_text: [
            { key: 'max_length', kind: 'int', where: 'inline', label: 'Maximum characters', def: 200, min: 1, max: 255 },
            { key: 'placeholder', kind: 'text', where: 'inline', label: 'Placeholder', def: '', max: 120 }
        ],
        paragraph: [
            { key: 'max_length', kind: 'int', where: 'inline', label: 'Maximum characters', def: 4000, min: 1, max: 65535 },
            { key: 'placeholder', kind: 'text', where: 'inline', label: 'Placeholder', def: '', max: 120 }
        ],
        number: [
            { key: 'min', kind: 'number', where: 'inline', label: 'Smallest allowed', def: null, hint: 'Blank = no limit.' },
            { key: 'max', kind: 'number', where: 'inline', label: 'Largest allowed', def: null, hint: 'Blank = no limit.' },
            { key: 'step', kind: 'number', where: 'inline', label: 'Step', def: 1 },
            { key: 'unit', kind: 'text', where: 'inline', label: 'Unit', def: '', max: 24, placeholder: 'e.g. points' }
        ],
        date: [
            { key: 'min', kind: 'date', where: 'inline', label: 'Earliest date', def: null },
            { key: 'max', kind: 'date', where: 'inline', label: 'Latest date', def: null }
        ],
        section: [],
        image: [
            { key: 'caption', kind: 'text', where: 'inline', label: 'Caption', def: '', max: 200 }
        ]
    };

    /* -------------------------------------------------------------- helpers */

    function esc(s) { return window.SvRender ? SvRender.escape(s) : String(s === null || s === undefined ? '' : s); }

    function $(id) { return document.getElementById(id); }

    function el(sel2, root) { return (root || document).querySelector(sel2); }

    function els(sel2, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel2));
    }

    function num(v, fallback) {
        var n = Number(v);
        return isFinite(n) ? n : fallback;
    }

    function truthy(v) { return v === 1 || v === true || v === '1'; }

    function mdHtml(src) {
        if (src === null || src === undefined || src === '') { return ''; }
        if (window.marked && window.DOMPurify) {
            try { return window.DOMPurify.sanitize(window.marked.parse(String(src))); } catch (e) { /* fall through */ }
        }
        return '<p>' + esc(src).replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br>') + '</p>';
    }

    function imageUrl(imageId) {
        var id = parseInt(imageId, 10), i;
        if (!id) { return ''; }
        for (i = 0; i < S.images.length; i++) {
            if (parseInt(S.images[i].image_id, 10) === id) { return S.images[i].url || ''; }
        }
        return '';
    }

    function questionById(id) {
        var qid = parseInt(id, 10), i;
        for (i = 0; i < S.questions.length; i++) {
            if (parseInt(S.questions[i].question_id, 10) === qid) { return S.questions[i]; }
        }
        return null;
    }

    function pageById(id) {
        var pid = parseInt(id, 10), i;
        for (i = 0; i < S.pages.length; i++) {
            if (parseInt(S.pages[i].page_id, 10) === pid) { return S.pages[i]; }
        }
        return null;
    }

    function questionsOfPage(pageId) {
        var pid = parseInt(pageId, 10), out = [], i;
        for (i = 0; i < S.questions.length; i++) {
            if (parseInt(S.questions[i].page_id, 10) === pid) { out.push(S.questions[i]); }
        }
        return out;
    }

    /** Flat survey order: questions grouped by page in page order. */
    function orderedQuestions() {
        var out = [], i;
        for (i = 0; i < S.pages.length; i++) {
            out = out.concat(questionsOfPage(S.pages[i].page_id));
        }
        return out;
    }

    function questionIndex(questionId) {
        var all = orderedQuestions(), i;
        for (i = 0; i < all.length; i++) {
            if (parseInt(all[i].question_id, 10) === parseInt(questionId, 10)) { return i; }
        }
        return all.length;
    }

    function optionsOf(q, role) {
        var out = [], list = (q && q.options) || [], i;
        for (i = 0; i < list.length; i++) {
            if (String(list[i].role || 'choice') === role) { out.push(list[i]); }
        }
        return out;
    }

    function settingOf(q, def) {
        var settings = (q && q.settings) || {};
        return Object.prototype.hasOwnProperty.call(settings, def.key) ? settings[def.key] : def.def;
    }

    function cardEl(questionId) {
        return el('.svb-item[data-qid="' + parseInt(questionId, 10) + '"]');
    }

    function narrow() { return window.innerWidth < NARROW; }

    /** The disabled attribute + a tip explaining the lock, for structural controls. */
    function lockAttr() {
        return S.locked ? ' disabled data-tip="' + esc(LOCK_TIP) + '"' : '';
    }

    /* ----------------------------------------------------------- networking */

    function hasKeys(o) { return Object.keys(o).length > 0; }

    function setSaveState(what) {
        var pill = $('svb-savestate');
        if (!pill) { return; }
        pill.className = 'svb-savestate svb-savestate-' + what;
        pill.textContent = what === 'saving' ? 'Saving…' : (what === 'error' ? 'Not saved' : 'Saved');
    }

    /**
     * The pill says what is true right now: "Saving…" while an edit waits in
     * its debounce or is on the wire, "Not saved" while any field is held
     * (blank) or refused, "Saved" only when neither is the case.
     */
    function refreshPill() {
        if (inflightWrites > 0 || hasKeys(pending) || hasKeys(optBusy)) { setSaveState('saving'); return; }
        setSaveState(hasKeys(held) || hasKeys(failed) ? 'error' : 'saved');
    }

    /** Where a page reload leaves the author: this page, without any #hash. */
    function reloadHref() {
        return String(window.location.href).split('#')[0];
    }

    /**
     * The page-level notice. `link` is an optional {href, text} rendered after
     * the message. Error notices pin under the site bar (survey-build.css) and
     * every notice carries its own Dismiss button.
     */
    function notice(message, kind, link) {
        var box = $('svb-notice');
        if (!box) { return; }
        if (!message) {
            box.hidden = true;
            box.innerHTML = '';
            return;
        }
        box.className = 'sv-notice svb-notice-slot' +
                        (kind === 'error' ? ' sv-notice-error' : (kind === 'warn' ? ' sv-notice-warn' : ''));
        box.innerHTML = '<span class="svb-notice-text">' + esc(message) +
                        (link ? ' <a class="sv-notice-link svb-notice-link" href="' + esc(link.href) + '">' + esc(link.text) + '</a>' : '') +
                        '</span>' +
                        '<button type="button" class="svb-notice-close" aria-label="Dismiss this message" data-tip="Dismiss">' +
                        '<i class="fas fa-xmark" aria-hidden="true"></i></button>';
        box.hidden = false;
    }

    /** A refused CSRF token: nothing more can save until the page reloads. */
    function csrfNotice() {
        // The server's own text already says "Reload the page and try again.";
        // printing it before a link that reads the same doubled the phrase.
        notice('Your security token expired. Anything typed in the field you are editing may not have saved.',
               'error', { href: reloadHref(), text: 'Reload the page' });
    }

    /** SurveyAjax actions that change nothing (a failure loses no work). */
    var READ_ACTIONS = { get: 1, types: 1, scopes: 1, help: 1, event_options: 1 };

    /** Run fn once no request is on the wire (flush() first to include debounced edits). */
    function whenIdle(fn) {
        if (inflight === 0 && !hasKeys(optBusy)) { fn(); return; }
        idleWaiters.push(fn);
    }

    function drainIdle() {
        var list;
        if (inflight !== 0 || hasKeys(optBusy) || !idleWaiters.length) { return; }
        list = idleWaiters;
        idleWaiters = [];
        list.forEach(function (fn) { fn(); });
    }

    /** Record a lost change. Keyed saves remember where to show it again. */
    function markFailed(key, loc, action, msg) {
        if (key) {
            failed[key] = { msg: msg, loc: loc || (failed[key] && failed[key].loc) || null };
        } else if (!READ_ACTIONS[action]) {
            failed._ = { msg: msg, loc: null };
        }
    }

    /**
     * POST one SurveyAjax action. `fields` is a plain object or a FormData.
     * onOk receives the decoded payload; failures raise a notice and, unless
     * onFail says otherwise, re-sync from the server so the UI never drifts.
     *
     * opts: { key: save bucket (failure bookkeeping), node: the field the edit
     * came from (a refusal is shown beside it), keepalive: survive unload,
     * noLoss: a refusal loses no edit (e.g. Open survey refused by
     * validation), so it does not turn the pill to "Not saved" }.
     * Every request carries X-CSRF-Token; a csrf:true refusal shows a notice
     * with a Reload link and re-syncs nothing (nothing else would save either).
     */
    function post(action, fields, onOk, onFail, opts) {
        var body, key, node, keepalive, write, done = false, init;
        opts      = opts || {};
        key       = opts.key || '';
        node      = opts.node || null;
        keepalive = !!opts.keepalive;
        write     = !READ_ACTIONS[action];

        if (fields instanceof window.FormData) {
            body = fields;
        } else {
            body = new window.FormData();
            Object.keys(fields || {}).forEach(function (k) {
                var v = fields[k];
                body.append(k, (v === null || v === undefined) ? '' : v);
            });
        }

        function finish() {
            if (done) { return; }
            done = true;
            inflight--;
            if (write) { inflightWrites--; }
        }

        inflight++;
        if (write) { inflightWrites++; }
        refreshPill();

        init = {
            method:      'POST',
            body:        body,
            credentials: 'same-origin',
            headers:     { 'X-CSRF-Token': CSRF }
        };
        if (keepalive) { init.keepalive = true; }

        return window.fetch(UIR + 'SurveyAjax/' + action, init).then(function (r) {
            return r.json();
        }).then(function (data) {
            var msg;
            finish();
            if (data && parseInt(data.status, 10) === 0) {
                if (key) {
                    delete failed[key];
                    if (node && !held[key]) { fieldError(node, ''); }
                } else if (!READ_ACTIONS[action] || action === 'get') {
                    delete failed._;
                }
                if (onOk) { onOk(data); }
                refreshPill();
                drainIdle();
                return data;
            }
            msg = (data && data.error) || 'That change could not be saved.';
            if (!opts.noLoss) { markFailed(key, node ? locOf(node) : null, action, msg); }
            refreshPill();
            if (data && data.csrf) {
                csrfNotice();
            } else if (data && parseInt(data.status, 10) === 5) {
                notice('Your session expired — log in again to keep editing.', 'error');
            } else if (onFail) {
                onFail(data || {});
            } else {
                notice(msg, 'error');
                reload();
            }
            drainIdle();
            return data;
        })['catch'](function (err) {
            // `done` already set means the request succeeded and a handler
            // threw: that is a bug to see in the console, not a lost change.
            if (done) {
                if (window.console) { window.console.error(err); }
                refreshPill();
                drainIdle();
                return null;
            }
            finish();
            if (!opts.noLoss) { markFailed(key, node ? locOf(node) : null, action, 'The ORK could not be reached.'); }
            refreshPill();
            // A read that fails loses no edit, so it does not claim one.
            notice(write ? 'The ORK could not be reached. Your last change was not saved.'
                         : 'The ORK could not be reached. Check your connection and reload the page.', 'error');
            drainIdle();
            return null;
        });
    }

    /** A debounced save the server refused: say so beside the field, never repaint. */
    function saveFailed(node, data) {
        var msg = (data && data.error) || 'That change could not be saved.';
        if (node && document.contains(node)) {
            fieldError(node, msg);
        } else {
            notice(msg, 'error');
        }
    }

    /**
     * Coalesce repeated edits to the same target. `key` identifies the target
     * (e.g. 'q:12:Prompt'), so typing in a prompt fires one request, not one
     * per keystroke, while two different questions never share a bucket.
     * opts.node is the field the edit came from: a refusal is drawn beside it.
     */
    function save(key, action, fields, onOk, opts) {
        var p = pending[key];
        if (!p) { p = pending[key] = { action: action, fields: {}, timer: null }; }
        p.action = action;
        p.onOk   = onOk;
        p.node   = (opts && opts.node) || p.node || null;
        p.onFailExtra = (opts && opts.onFail) || p.onFailExtra || null;
        p.onSent = (opts && opts.onSent) || p.onSent || null;
        p.onFail = function (data) {
            saveFailed(p.node, data);
            if (p.onFailExtra) { p.onFailExtra(data); }
        };
        Object.keys(fields).forEach(function (k) { p.fields[k] = fields[k]; });

        if (p.timer) { window.clearTimeout(p.timer); }
        p.timer = window.setTimeout(function () {
            var req;
            delete pending[key];
            req = post(p.action, p.fields, p.onOk, p.onFail, { key: key, node: p.node });
            if (p.onSent) { p.onSent(req); }
        }, SAVE_MS);
        refreshPill();
    }

    /**
     * Send every debounced edit now. keepalive (leave-page / tab hidden) lets
     * the requests outlive the page, which a plain fetch does not.
     */
    function flush(keepalive) {
        Object.keys(pending).forEach(function (key) {
            var p = pending[key];
            var req;
            if (p.timer) { window.clearTimeout(p.timer); }
            delete pending[key];
            req = post(p.action, p.fields, p.onOk, p.onFail, { key: key, node: p.node, keepalive: !!keepalive });
            if (p.onSent) { p.onSent(req); }
        });
    }

    /**
     * option_set replaces a role's whole list, and a new option learns its id
     * only from the reply. So one such save per key is on the wire at a time
     * (#29): an edit made meanwhile only records how to redo itself, and once
     * the reply has written the real ids back — or the save failed — that
     * rebuilds the payload from the current state and sends it.
     */
    function optionSetSent(key) {
        return function (req) {
            if (!req || !req.then) { return; }
            optBusy[key] = { again: null };
            req.then(function () {
                var b = optBusy[key];
                delete optBusy[key];
                // Idle waiters (retype, duplicate, reload…) must see the
                // resend, so send it now and let them wait for its reply.
                if (b && b.again) {
                    b.again();
                    if (idleWaiters.length && hasKeys(pending)) { flush(); }
                }
                refreshPill();
                drainIdle();
            });
        };
    }

    /** Forget every queued, held or refused edit of a question that no longer exists. */
    function dropEditsFor(questionId) {
        var qid = parseInt(questionId, 10);
        var mine = new RegExp('^(q|qset|opts):' + qid + '(:|$)');
        Object.keys(pending).forEach(function (key) {
            if (!mine.test(key)) { return; }
            if (pending[key].timer) { window.clearTimeout(pending[key].timer); }
            delete pending[key];
        });
        Object.keys(held).forEach(function (key) { if (mine.test(key)) { delete held[key]; } });
        Object.keys(failed).forEach(function (key) { if (mine.test(key)) { delete failed[key]; } });
        refreshPill();
    }

    function adopt(data) {
        S.survey    = data.survey || {};
        S.pages     = data.pages || [];
        S.questions = data.questions || [];
        S.images    = data.images || [];
        S.locked    = !!data.locked;
    }

    /**
     * Full re-sync from the server — the error path. Debounced edits are sent
     * first and the fetch waits until they land, so the snapshot it adopts
     * already holds them; the repaint then puts the focused field back.
     */
    function reload(after) {
        flush();
        whenIdle(function () {
            post('get', { SurveyId: SURVEY_ID }, function (data) {
                adopt(data);
                renderAll();
                if (after) { after(); }
            });
        });
    }

    /* ------------------------------------------- held / refused field marks */

    var errSeq = 0;

    function isControl(node) {
        return /^(INPUT|TEXTAREA|SELECT)$/.test(node.tagName || '');
    }

    /** Where a field's hint goes: inside its option row, under the prompt row, or at the end of its field. */
    function placeHint(node, hint) {
        var row  = node.closest('.svb-optrow');
        var top  = node.closest('.svb-card-top');
        var head = node.closest('.rp-header-icon-title');
        var ph   = node.closest('.svb-page-head');
        var fld  = node.closest('.svb-field');
        if (row)  { row.appendChild(hint); return; }
        if (top)  { top.parentNode.insertBefore(hint, top.nextSibling); return; }
        if (head) { head.parentNode.insertBefore(hint, head.nextSibling); return; }
        if (ph)   { ph.appendChild(hint); return; }
        if (fld)  { fld.appendChild(hint); return; }
        if (node.classList.contains('svb-opts')) { node.appendChild(hint); return; }
        node.parentNode.insertBefore(hint, node.nextSibling);
    }

    /**
     * Mark one field with a message right beside it, or clear the mark.
     * `neutral` draws a plain note (no aria-invalid) instead of an error.
     */
    function fieldError(node, message, neutral) {
        var id, hint, host;
        if (!node || !node.getAttribute) { return; }
        id   = node.getAttribute('data-err-id');
        hint = id ? document.getElementById(id) : null;
        host = node.closest('.svb-optrow') || node.closest('.svb-page-head');

        if (!message) {
            if (hint && hint.parentNode) { hint.parentNode.removeChild(hint); }
            node.removeAttribute('data-err-id');
            node.removeAttribute('aria-invalid');
            if (id && node.getAttribute('aria-describedby') === id) { node.removeAttribute('aria-describedby'); }
            if (host) { host.classList.remove('svb-has-err'); }
            return;
        }
        if (!hint) {
            id   = 'svb-err-' + (++errSeq);
            hint = document.createElement('span');
            hint.id = id;
            node.setAttribute('data-err-id', id);
            placeHint(node, hint);
        }
        hint.className   = 'svb-field-err' + (neutral ? ' svb-field-note' : '');
        hint.textContent = message;
        if (isControl(node)) {
            if (neutral) { node.removeAttribute('aria-invalid'); } else { node.setAttribute('aria-invalid', 'true'); }
            node.setAttribute('aria-describedby', id);
        }
        if (host) { host.classList.add('svb-has-err'); }
    }

    /** Hold a blank value instead of sending it: inline hint, pill "Not saved". */
    function holdBlank(key, node, msg) {
        // A debounced edit already queued for this field carries the last
        // NON-blank text the author typed, so it is left to send; only the
        // blank itself is held back.
        held[key] = { msg: msg, loc: locOf(node) };
        fieldError(node, msg);
        refreshPill();
    }

    function releaseHold(key, node) {
        if (!held[key]) { return; }
        delete held[key];
        if (node) { fieldError(node, ''); }
        refreshPill();
    }

    /**
     * After any repaint, put every held / refused mark back on its field. A
     * held blank whose field now shows real text (the repaint drew the saved
     * value) is released: nothing unsaved is on screen any more.
     */
    function syncMarks() {
        Object.keys(held).forEach(function (key) {
            var h = held[key], node = locate(h.loc);
            if (h.pw) {
                // The pairwise textarea was redrawn with the held text
                // (pairwiseEditorHtml); gone means the card closed.
                if (node) { fieldError(node, h.msg); } else { dropPairwiseHold(key.split(':')[1]); }
                return;
            }
            if (key.indexOf('opts:') === 0) {
                if (!node || !markBlankRows(node)) { delete held[key]; }
                return;
            }
            if (!node || String(node.value || '').trim() !== '') { delete held[key]; return; }
            fieldError(node, h.msg);
        });
        Object.keys(failed).forEach(function (key) {
            var f = failed[key], node = f.loc ? locate(f.loc) : null;
            if (node && !node.getAttribute('data-err-id')) { fieldError(node, f.msg); }
        });
        refreshPill();
    }

    /* ------------------------------------------------ focus across repaints */

    /**
     * A stable address for a field that survives the canvas being rebuilt:
     * the card or page it sits in plus a selector inside it. Header and
     * sidebar fields are addressed by id / data-sv-field.
     */
    function locOf(node) {
        var canvas = $('svb-canvas'), settings = $('svb-settings');
        var item, page, row, oid, sel2 = null, extra = '';
        if (!node || !node.getAttribute) { return null; }
        if (node.id === 'svb-title') { return { id: 'svb-title' }; }
        if (settings && settings.contains(node)) {
            if (node.hasAttribute('data-sv-field')) {
                return { root: 'settings', selector: '[data-sv-field="' + node.getAttribute('data-sv-field') + '"]' };
            }
            return node.id ? { id: node.id } : null;
        }
        if (!canvas || !canvas.contains(node)) { return node.id ? { id: node.id } : null; }

        item = node.closest('.svb-item');
        page = node.closest('.svb-page');

        if (node.hasAttribute('data-q-field')) {
            sel2 = '[data-q-field="' + node.getAttribute('data-q-field') + '"]';
        } else if (node.hasAttribute('data-p-field')) {
            sel2 = '[data-p-field="' + node.getAttribute('data-p-field') + '"]';
        } else if (node.hasAttribute('data-q-setting')) {
            sel2 = '[data-q-setting="' + node.getAttribute('data-q-setting') + '"]';
        } else if (node.classList.contains('svb-optlabel') || node.classList.contains('svb-optweight')) {
            row = node.closest('.svb-optrow');
            oid = row ? (parseInt(row.getAttribute('data-oid'), 10) || 0) : 0;
            if (!oid) { return null; }   // a brand-new row has no address yet
            sel2 = '.svb-optrow[data-oid="' + oid + '"] .' +
                   (node.classList.contains('svb-optlabel') ? 'svb-optlabel' : 'svb-optweight');
        } else if (node.classList.contains('svb-pw-lines')) {
            sel2 = '.svb-pw-lines';
        } else if (node.classList.contains('svb-opts')) {
            sel2 = '.svb-opts[data-role="' + node.getAttribute('data-role') + '"]';
        } else if (node.classList.contains('svb-typesel')) {
            sel2 = '.svb-typesel';
        } else if (node.hasAttribute('data-act')) {
            ['data-page', 'data-after', 'data-qid'].forEach(function (a) {
                if (node.hasAttribute(a)) { extra += '[' + a + '="' + node.getAttribute(a) + '"]'; }
            });
            sel2 = '[data-act="' + node.getAttribute('data-act') + '"]' + extra;
        } else if (item && node === item) {
            sel2 = '';
        } else {
            return null;
        }
        return {
            qid:      item ? parseInt(item.getAttribute('data-qid'), 10) : 0,
            pid:      page ? parseInt(page.getAttribute('data-page'), 10) : 0,
            selector: sel2
        };
    }

    function locate(loc) {
        var root;
        if (!loc) { return null; }
        if (loc.id) { return $(loc.id); }
        if (loc.root === 'settings') { return el(loc.selector, $('svb-settings')); }
        root = loc.qid ? cardEl(loc.qid)
                       : (loc.pid ? el('.svb-page[data-page="' + loc.pid + '"]') : null);
        if (!root) { return null; }
        return loc.selector ? el(loc.selector, root) : root;
    }

    /** What has the keyboard inside the canvas, with its text and caret. */
    function snapFocus() {
        var a = document.activeElement, canvas = $('svb-canvas'), snap, loc;
        if (!a || !canvas || a === canvas || !canvas.contains(a)) { return null; }
        loc = locOf(a);
        if (!loc) { return null; }
        snap = { loc: loc, value: null, start: null, end: null };
        if (isControl(a) && a.type !== 'checkbox' && a.type !== 'radio' && a.type !== 'file') {
            snap.value = a.value;
            try { snap.start = a.selectionStart; snap.end = a.selectionEnd; } catch (e) { /* not a text control */ }
        }
        return snap;
    }

    /** Put the keyboard, the typed text and the caret back after a repaint. */
    function restoreSnap(snap) {
        var node;
        if (!snap) { return; }
        node = locate(snap.loc);
        if (!node || !node.focus) { return; }
        if (snap.value !== null && !node.disabled && node.value !== snap.value &&
                (node.tagName !== 'SELECT' || els('option', node).some(function (o) { return o.value === snap.value; }))) {
            node.value = snap.value;
            if (node.classList.contains('svb-autogrow')) { autoGrow(node); }
        }
        node.focus({ preventScroll: true });
        if (snap.start !== null && node.setSelectionRange) {
            try { node.setSelectionRange(snap.start, snap.end); } catch (e) { /* not a text control */ }
        }
    }

    function replaceQuestion(fresh) {
        var i;
        for (i = 0; i < S.questions.length; i++) {
            if (parseInt(S.questions[i].question_id, 10) === parseInt(fresh.question_id, 10)) {
                S.questions[i] = fresh;
                return;
            }
        }
    }

    /* -------------------------------------------------- shared markup pieces */

    /** A copy of the question with the derived fields SvRender wants. */
    function forRender(q) {
        var c = {}, k;
        for (k in q) { if (Object.prototype.hasOwnProperty.call(q, k)) { c[k] = q[k]; } }
        c.help_html = mdHtml(q.help_md);
        c.image_url = imageUrl(q.image_id);
        return c;
    }

    /**
     * The answer area of a question exactly as SvRender draws it, without the
     * prompt (the editor supplies its own). Used by the types whose control is
     * shown, not edited: rating, nps, text, number, date.
     */
    function previewBody(q, dropSelector) {
        var tmp = document.createElement('div');
        var body;
        tmp.innerHTML = SvRender.question(forRender(q), undefined, 'preview');
        body = el('.sv-q-body', tmp);
        if (!body) { return ''; }
        if (dropSelector) {
            els(dropSelector, body).forEach(function (n) { n.parentNode.removeChild(n); });
        }
        return '<div class="svb-preview-lock">' + body.innerHTML + '</div>';
    }

    function typeIcon(type) {
        return (TYPE_META[type] || {}).icon || 'fa-question';
    }

    function showIfChip(item) {
        var srcId = parseInt(item.show_if_question_id, 10) || 0;
        var optId = parseInt(item.show_if_option_id, 10) || 0;
        var src, opts, i, label = '';
        if (!srcId || !optId) { return ''; }
        src = questionById(srcId);
        if (src) {
            opts = optionsOf(src, 'choice');
            for (i = 0; i < opts.length; i++) {
                if (parseInt(opts[i].option_id, 10) === optId) { label = opts[i].label; break; }
            }
        }
        return '<span class="svb-chip svb-chip-showif" data-tip="Shown only when the earlier question is answered this way">' +
               '<i class="fas fa-code-branch" aria-hidden="true"></i> ' +
               esc(label ? 'Shown when “' + label + '”' : 'Conditional') + '</span>';
    }

    /** The closed card's one-glyph version of showIfChip (spec §7 Density). */
    function showIfFlag(item) {
        if (!(parseInt(item.show_if_question_id, 10) || 0) ||
                !(parseInt(item.show_if_option_id, 10) || 0)) { return ''; }
        return '<span class="svb-flag" data-tip="Shown only when an earlier question is answered a certain way">' +
               '<i class="fas fa-code-branch" aria-hidden="true"></i>' +
               '<span class="sv-visually-hidden">Conditional</span></span>';
    }

    function iconBtn(act, icon, tip, extraClass, disabled, dataAttrs) {
        return '<button type="button" class="svb-icon-btn ' + (extraClass || '') + '" data-act="' + act + '"' +
               // Only the lock earns the lock tip: a button at its floor (one
               // page, the minimum options) keeps its own words.
               (dataAttrs || '') + ' data-tip="' + esc(disabled && S.locked ? LOCK_TIP : tip) + '" aria-label="' + esc(tip) + '"' +
               (disabled ? ' disabled' : '') + '><i class="fas ' + icon + '" aria-hidden="true"></i></button>';
    }

    /**
     * A reorder step button. Unlike iconBtn it keeps its own tip when it is
     * disabled at the end of a list, so "Move up" on the first item does not
     * claim the survey is locked.
     */
    function moveBtn(act, dir, tip, disabled, dataAttrs) {
        return '<button type="button" class="svb-icon-btn svb-move-btn" data-act="' + act + '"' +
               (dataAttrs || '') + ' data-tip="' + esc(S.locked ? LOCK_TIP : tip) + '" aria-label="' + esc(tip) + '"' +
               (disabled ? ' disabled' : '') + '><i class="fas fa-chevron-' + (dir < 0 ? 'up' : 'down') +
               '" aria-hidden="true"></i></button>';
    }

    /**
     * One labelled control. With an id the label points at it; without one the
     * label WRAPS the control, so nothing here is ever an unlabelled input.
     */
    function fieldRow(inner, label, hint, forId) {
        var body;
        if (!label) {
            body = inner;
        } else if (forId) {
            body = '<label class="svb-label" for="' + forId + '">' + esc(label) + '</label>' + inner;
        } else {
            body = '<label class="svb-label svb-label-wrap"><span>' + esc(label) + '</span>' + inner + '</label>';
        }
        return '<div class="svb-field">' + body +
               (hint ? '<p class="svb-hint">' + esc(hint) + '</p>' : '') + '</div>';
    }

    function textInput(attrs, value, extra) {
        return '<input type="' + (attrs.type || 'text') + '" class="sv-input" ' + (extra || '') +
               (attrs.id ? ' id="' + attrs.id + '"' : '') +
               (attrs.max ? ' maxlength="' + attrs.max + '"' : '') +
               (attrs.placeholder ? ' placeholder="' + esc(attrs.placeholder) + '"' : '') +
               (attrs.min !== undefined && attrs.min !== null ? ' min="' + attrs.min + '"' : '') +
               (attrs.hi !== undefined && attrs.hi !== null ? ' max="' + attrs.hi + '"' : '') +
               (attrs.step ? ' step="' + attrs.step + '"' : '') +
               (attrs.disabled ? ' disabled data-tip="' + esc(LOCK_TIP) + '"' : '') +
               ' value="' + esc(value === null || value === undefined ? '' : value) + '">';
    }

    function checkRow(label, checked, extra, disabled, hint) {
        return '<div class="svb-field svb-field-check">' +
               '<label class="svb-check">' +
               '<input type="checkbox" ' + extra + (checked ? ' checked' : '') +
               (disabled ? ' disabled data-tip="' + esc(LOCK_TIP) + '"' : '') + '>' +
               '<span>' + esc(label) + '</span></label>' +
               (hint ? '<p class="svb-hint">' + esc(hint) + '</p>' : '') +
               '</div>';
    }

    function selectRow(label, options, value, extra, disabled, hint) {
        var html = '<select class="sv-select" ' + extra +
                   (disabled ? ' disabled data-tip="' + esc(LOCK_TIP) + '"' : '') + '>';
        options.forEach(function (o) {
            html += '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(value) ? ' selected' : '') + '>' +
                    esc(o[1]) + '</option>';
        });
        html += '</select>';
        return fieldRow(html, label, hint);
    }

    /** A labelled radio group; every radio carries `extra` (its data-sv-field), and change saves the checked one. */
    function radioRow(label, name, options, value, extra, hint) {
        var html = '<fieldset class="svb-field svb-radios"><legend class="svb-label">' + esc(label) + '</legend>';
        options.forEach(function (o) {
            html += '<label class="svb-check svb-radio"><input type="radio" name="' + esc(name) + '" value="' + esc(o[0]) + '" ' + extra +
                    (String(o[0]) === String(value) ? ' checked' : '') + '><span>' + esc(o[1]) + '</span></label>';
        });
        html += (hint ? '<p class="svb-hint">' + esc(hint) + '</p>' : '') + '</fieldset>';
        return html;
    }

    /** A markdown textarea with the B / I / • / link / image toolbar and a live preview. */
    function mdEditor(id, label, value, dataAttr, hint) {
        var html = '<div class="svb-field svb-md" data-md="' + id + '">';
        html += label ? '<label class="svb-label" for="' + id + '">' + esc(label) + '</label>' : '';
        html += '<div class="svb-md-tools" role="toolbar" aria-label="' + esc(label || 'Markdown') + ' formatting">';
        html += '<button type="button" class="svb-md-btn" data-md-cmd="bold" data-md-for="' + id + '" data-tip="Bold" aria-label="Bold"><i class="fas fa-bold" aria-hidden="true"></i></button>';
        html += '<button type="button" class="svb-md-btn" data-md-cmd="italic" data-md-for="' + id + '" data-tip="Italic" aria-label="Italic"><i class="fas fa-italic" aria-hidden="true"></i></button>';
        html += '<button type="button" class="svb-md-btn" data-md-cmd="list" data-md-for="' + id + '" data-tip="Bulleted list" aria-label="Bulleted list"><i class="fas fa-list-ul" aria-hidden="true"></i></button>';
        html += '<button type="button" class="svb-md-btn" data-md-cmd="link" data-md-for="' + id + '" data-tip="Link" aria-label="Link"><i class="fas fa-link" aria-hidden="true"></i></button>';
        html += '<button type="button" class="svb-md-btn" data-md-cmd="image" data-md-for="' + id + '" data-tip="Upload and insert an image" aria-label="Insert image"><i class="fas fa-image" aria-hidden="true"></i></button>';
        html += '</div>';
        html += '<textarea class="sv-textarea svb-md-input" id="' + id + '" rows="3" ' + dataAttr + '>' + esc(value || '') + '</textarea>';
        html += '<div class="svb-md-preview" data-md-preview="' + id + '">' + mdPreviewHtml(value) + '</div>';
        // First paint is the local guess; swap in the server's rendering once the editor is in the DOM.
        if (value && !Object.prototype.hasOwnProperty.call(mdServerCache, String(value))) {
            window.setTimeout(function () { fetchMdPreview(id); }, 0);
        }
        if (hint) { html += '<p class="svb-hint">' + esc(hint) + '</p>'; }
        html += '</div>';
        return html;
    }

    function imagePicker(label, imageId, job, hint) {
        var url = imageUrl(imageId);
        var html = '<div class="svb-field"><span class="svb-label">' + esc(label) + '</span>';
        html += '<div class="svb-imgpick">';
        if (url) {
            html += '<img class="svb-imgpick-thumb" src="' + esc(url) + '" alt="">';
        } else {
            html += '<span class="svb-imgpick-empty">No image</span>';
        }
        html += '<span class="svb-imgpick-btns">';
        html += '<button type="button" class="sv-btn sv-btn-ghost" data-upload="' + esc(job) + '">' +
                '<i class="fas fa-upload" aria-hidden="true"></i> ' + (url ? 'Replace' : 'Upload') + '</button>';
        if (url) {
            html += '<button type="button" class="sv-btn" data-imgclear="' + esc(job) + '">Remove</button>';
        }
        html += '</span></div>';
        if (hint) { html += '<p class="svb-hint">' + esc(hint) + '</p>'; }
        html += '</div>';
        return html;
    }

    /**
     * Sources a show-if may point at: a choice question earlier in survey
     * order that is not itself conditional. Mirrors Survey::showIfProblem().
     */
    function showIfSources(beforeIndex) {
        var all = orderedQuestions(), out = [], i, q;
        for (i = 0; i < all.length && i < beforeIndex; i++) {
            q = all[i];
            if (SHOW_IF_SOURCES.indexOf(String(q.type)) === -1) { continue; }
            if (parseInt(q.show_if_question_id, 10) > 0) { continue; }
            if (!optionsOf(q, 'choice').length) { continue; }
            out.push(q);
        }
        return out;
    }

    function showIfEditor(item, beforeIndex, prefix) {
        var sources = showIfSources(beforeIndex);
        var srcId   = parseInt(item.show_if_question_id, 10) || 0;
        var optId   = parseInt(item.show_if_option_id, 10) || 0;
        var src     = srcId ? questionById(srcId) : null;
        var html    = '<div class="svb-showif">';
        var qOpts   = [['0', '— Always shown —']];
        var oOpts   = [];

        sources.forEach(function (q) {
            qOpts.push([String(q.question_id), (q.prompt || 'Untitled question').slice(0, 60)]);
        });

        if (!sources.length) {
            html += '<p class="svb-hint">Add a choice question earlier in the survey to make this conditional.</p>';
        }
        html += selectRow('Show only when', qOpts, srcId,
            'data-' + prefix + '-field="ShowIfQuestionId"', S.locked || !sources.length);

        if (srcId && src) {
            optionsOf(src, 'choice').forEach(function (o) {
                oOpts.push([String(o.option_id), o.label]);
            });
            html += selectRow('…is answered', oOpts, optId,
                'data-' + prefix + '-field="ShowIfOptionId"', S.locked);
        }
        html += '</div>';
        return html;
    }

    /* --------------------------------------------------------------- canvas */

    /**
     * Rebuild the whole canvas from S. Whatever field held the keyboard gets
     * it back afterwards — with the text on screen (which may be ahead of S
     * while an edit is still in its debounce, or held because it is blank) and
     * the caret where it was — and every held / refused mark is redrawn.
     */
    function renderCanvas() {
        var canvas = $('svb-canvas');
        var html = '', i, j, page, qs, snap;
        if (!canvas) { return; }
        snap = snapFocus();

        for (i = 0; i < S.pages.length; i++) {
            page = S.pages[i];
            qs   = questionsOfPage(page.page_id);

            html += '<section class="svb-page" data-page="' + parseInt(page.page_id, 10) + '">';
            html += renderPageHead(page, i, qs.length);
            html += '<div class="svb-items" data-page="' + parseInt(page.page_id, 10) + '">';
            for (j = 0; j < qs.length; j++) {
                html += cardHtml(qs[j]);
                html += addInlineHtml(page.page_id, qs[j].question_id);
            }
            if (!qs.length) {
                html += '<p class="svb-page-empty">Nothing on this page yet.</p>';
            }
            html += '</div>';
            html += '<div class="svb-page-foot">' +
                    '<button type="button" class="svb-addbtn" data-act="add-element" data-page="' +
                    parseInt(page.page_id, 10) + '"' + lockAttr() + '>' +
                    '<i class="fas fa-plus" aria-hidden="true"></i> Add Element</button></div>';
            html += '</section>';
        }

        html += '<div class="svb-pagediv">' +
                '<button type="button" class="svb-link svb-addpage" data-act="page-add"' + lockAttr() + '>' +
                '<i class="fas fa-file-circle-plus" aria-hidden="true"></i> Add page</button></div>';

        canvas.innerHTML = html;
        els('.svb-autogrow', canvas).forEach(autoGrow);
        // See refreshCard(): preview controls leave the tab order too.
        els('.svb-q-preview :is(input, select, textarea, button, a)', canvas).forEach(function (c) { c.tabIndex = -1; });
        wireSortables();
        wireOptionSortables();
        renderToc();
        restoreSnap(snap);
        syncMarks();
    }

    /* ------------------------------------------------------------- outline */

    /* The right-hand .svb-toc is a table of contents for the canvas: one row
       per element, in canvas order, showing only its type icon and its title.
       Nothing else — no counts, no required dots — so the eye reads it as a
       list of names, not a second copy of the cards.

       It is rebuilt by renderCanvas(), which every structural change already
       goes through (add, delete, duplicate, retype, drag, keyboard move, page
       add / delete / reorder). While someone is TYPING a prompt, heading,
       caption or page title, only that one row's text is written — rebuilding
       the list under the pointer would make it jump. */

    /** question_id -> the card is inside the spy's band right now. */
    var tocSeen  = {};
    var tocObs   = null;   // IntersectionObserver over the card roots
    var tocTimer = null;   // the live-typing debounce

    var TOC_MS = 150;

    function oneLine(s) {
        return String(s === null || s === undefined ? '' : s).replace(/\s+/g, ' ').trim();
    }

    /** The single line an element shows in the outline. */
    function tocTitle(q) {
        var text;
        if (!q) { return ''; }
        if (q.type === 'image') {
            // An image block's own label is its caption; the prompt is its alt
            // text, which is the next best thing to show when there is no caption.
            text = oneLine(settingOf(q, { key: 'caption', def: '' })) || oneLine(q.prompt);
            return text || 'Image';
        }
        return oneLine(q.prompt) || 'Untitled question';
    }

    /** "Page 2" on its own, or with the page's title when one is set. */
    function tocPageLabel(page, index) {
        var title = oneLine(page.title);
        return 'Page ' + (index + 1) + (title ? ' · ' + title : '');
    }

    function tocRowHtml(q) {
        var qid  = parseInt(q.question_id, 10);
        var text = tocTitle(q);
        return '<a class="svb-toc-item" href="#svb-item-' + qid + '" data-qid="' + qid +
               '" data-tip="' + esc(text) + '">' +
               '<i class="fas ' + esc(typeIcon(q.type)) + ' svb-toc-icon" aria-hidden="true"></i>' +
               '<span class="svb-toc-text">' + esc(text) + '</span></a>';
    }

    function renderToc() {
        var nav = $('svb-toc');
        var html = '', total = 0, label, page, qs, i, j;
        if (!nav) { return; }

        for (i = 0; i < S.pages.length; i++) { total += questionsOfPage(S.pages[i].page_id).length; }

        html += '<div class="svb-toc-card">';
        html += '<div class="svb-toc-head"><i class="fas fa-list-ul" aria-hidden="true"></i> Outline</div>';
        html += '<div class="svb-toc-body">';

        if (!total) {
            html += '<p class="svb-toc-empty">No questions yet</p>';
        } else {
            for (i = 0; i < S.pages.length; i++) {
                page  = S.pages[i];
                qs    = questionsOfPage(page.page_id);
                label = tocPageLabel(page, i);
                html += '<div class="svb-toc-page" data-page="' + parseInt(page.page_id, 10) +
                        '" data-tip="' + esc(label) + '">' + esc(label) + '</div>';
                if (!qs.length) {
                    html += '<p class="svb-toc-none">Nothing here yet</p>';
                    continue;
                }
                for (j = 0; j < qs.length; j++) { html += tocRowHtml(qs[j]); }
            }
        }

        html += '</div></div>';
        nav.innerHTML = html;

        markToc();
        wireTocSpy();
    }

    /** The selected card's row carries aria-current; the spy paints the rest. */
    function markToc() {
        var nav = $('svb-toc');
        if (!nav) { return; }
        els('.svb-toc-item', nav).forEach(function (a) {
            if (sel && parseInt(a.getAttribute('data-qid'), 10) === sel) {
                a.setAttribute('aria-current', 'true');
            } else {
                a.removeAttribute('aria-current');
            }
        });
        markTocNear();
    }

    /**
     * Scroll spy. When nothing is selected — or the selected card has scrolled
     * out of the band — the topmost card in view gets the quieter .svb-toc-near
     * mark, so the outline still says where you are.
     */
    function markTocNear() {
        var nav = $('svb-toc');
        var all, top = 0, i, qid;
        if (!nav) { return; }
        all = orderedQuestions();
        for (i = 0; i < all.length; i++) {
            qid = parseInt(all[i].question_id, 10);
            if (tocSeen[qid]) { top = qid; break; }
        }
        if (sel && tocSeen[sel]) { top = 0; }
        els('.svb-toc-item', nav).forEach(function (a) {
            var id = parseInt(a.getAttribute('data-qid'), 10);
            a.classList.toggle('svb-toc-near', !!top && id === top && id !== sel);
        });
    }

    function wireTocSpy() {
        var canvas = $('svb-canvas');
        if (tocObs) { tocObs.disconnect(); tocObs = null; }
        tocSeen = {};
        if (!canvas || !$('svb-toc') || !window.IntersectionObserver) { return; }
        // The band starts just under the sticky site nav and stops at the
        // halfway line, so "topmost in view" means the card you are reading.
        tocObs = new window.IntersectionObserver(onTocIntersect, {
            rootMargin: '-60px 0px -50% 0px',
            threshold:  0
        });
        els('.svb-item', canvas).forEach(function (n) { tocObs.observe(n); });
    }

    function onTocIntersect(entries) {
        entries.forEach(function (entry) {
            var qid = parseInt(entry.target.getAttribute('data-qid'), 10);
            if (!qid) { return; }
            if (entry.isIntersecting) { tocSeen[qid] = true; } else { delete tocSeen[qid]; }
        });
        markTocNear();
    }

    /**
     * Live text only. Called on every keystroke in a prompt, heading, caption
     * or page title; after a short pause it writes that ONE row's text and
     * leaves the rest of the list exactly where the pointer left it.
     */
    function tocLiveText() {
        if (tocTimer) { window.clearTimeout(tocTimer); }
        tocTimer = window.setTimeout(function () {
            var nav = $('svb-toc'), i, page, label, row, text, node;
            tocTimer = null;
            if (!nav) { return; }

            for (i = 0; i < S.questions.length; i++) {
                row = el('.svb-toc-item[data-qid="' + parseInt(S.questions[i].question_id, 10) + '"]', nav);
                if (!row) { continue; }
                text = tocTitle(S.questions[i]);
                node = el('.svb-toc-text', row);
                if (node && node.textContent !== text) {
                    node.textContent = text;
                    row.setAttribute('data-tip', text);
                }
            }
            for (i = 0; i < S.pages.length; i++) {
                page  = S.pages[i];
                row   = el('.svb-toc-page[data-page="' + parseInt(page.page_id, 10) + '"]', nav);
                label = tocPageLabel(page, i);
                if (row && row.textContent !== label) {
                    row.textContent = label;
                    row.setAttribute('data-tip', label);
                }
            }
        }, TOC_MS);
    }

    /** A row is a real anchor, so Enter arrives here as a click too. */
    function onTocClick(e) {
        var a = e.target.closest ? e.target.closest('.svb-toc-item') : null;
        var qid, card;
        if (!a) { return; }
        e.preventDefault();
        qid  = parseInt(a.getAttribute('data-qid'), 10);
        card = cardEl(qid);
        if (card && card.scrollIntoView) { card.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
        // false: open the card for editing without pulling the caret out of
        // wherever the keyboard user was.
        select(qid, false);
        markToc();
    }

    function renderPageHead(page, index, count) {
        var pid  = parseInt(page.page_id, 10);
        var html = '<header class="svb-page-head">';

        // The grip is pointer-only decoration, so it stays out of the tab order;
        // the two step buttons beside it are the keyboard path (WCAG 2.1.1).
        html += '<span class="svb-page-handle' + (S.locked ? ' svb-handle-locked' : '') + '" data-tip="' +
                esc(S.locked ? LOCK_TIP : 'Drag to reorder this page') + '" aria-hidden="true">' +
                '<i class="fas fa-grip-vertical"></i></span>';
        html += moveBtn('page-up', -1, 'Move this page up', S.locked || index === 0,
                        ' data-page="' + pid + '"');
        html += moveBtn('page-down', 1, 'Move this page down', S.locked || index >= S.pages.length - 1,
                        ' data-page="' + pid + '"');
        html += '<span class="svb-page-num">Page ' + (index + 1) + ' of ' + S.pages.length + '</span>';
        html += '<input type="text" class="svb-page-title" maxlength="200" placeholder="Page title (optional)" ' +
                'aria-label="Page ' + (index + 1) + ' title" data-p-field="Title" value="' + esc(page.title || '') + '">';
        html += showIfChip(page);
        html += '<span class="svb-page-count">' + count + (count === 1 ? ' item' : ' items') + '</span>';
        html += '<span class="svb-page-actions">';
        html += iconBtn('page-more', 'fa-ellipsis', 'Page description and skip logic', '', false,
                        ' data-page="' + pid + '" aria-expanded="false" aria-controls="svb-pmore-' + pid + '"');
        html += iconBtn('page-delete', 'fa-trash', 'Delete this page', 'svb-icon-danger',
                        S.locked || S.pages.length < 2, ' data-page="' + pid + '"');
        html += '</span>';
        html += '</header>';

        html += '<div class="svb-page-more" id="svb-pmore-' + pid + '" data-page="' + pid + '" hidden>';
        html += mdEditor('svb-pdesc-' + pid, 'Page introduction (markdown)', page.description_md,
                         'data-p-field="DescriptionMd"');
        html += showIfEditor(page, firstQuestionIndexOfPage(page.page_id), 'p');
        html += '</div>';

        return html;
    }

    function firstQuestionIndexOfPage(pageId) {
        var all = orderedQuestions(), i;
        for (i = 0; i < all.length; i++) {
            if (parseInt(all[i].page_id, 10) === parseInt(pageId, 10)) { return i; }
        }
        return all.length;
    }

    /** The small "+" that appears between cards on hover or next to the selected card. */
    function addInlineHtml(pageId, afterQuestionId) {
        return '<div class="svb-addinline">' +
               '<button type="button" class="svb-addinline-btn" data-act="add-element" data-page="' +
               parseInt(pageId, 10) + '" data-after="' + parseInt(afterQuestionId, 10) + '"' + lockAttr() +
               ' aria-label="Add an element here"><i class="fas fa-plus" aria-hidden="true"></i></button></div>';
    }

    /* ------------------------------------------------------------ one card */

    function cardHtml(q) {
        var qid      = parseInt(q.question_id, 10);
        var selected = qid === sel;
        var pos      = questionIndex(qid);
        var total    = orderedQuestions().length;
        // The id is the outline's anchor target (.svb-toc-item href), so it has
        // to survive every redraw of the card, selected or not.
        var html = '<article class="svb-item' + (selected ? ' svb-selected svb-editing' : '') +
                   '" id="svb-item-' + qid + '" data-qid="' + qid + '" data-type="' + esc(q.type) +
                   '" tabindex="0">';

        /* The grip strip (spec §7 Density): a 16px band at the top of every
           card holding the centred ⋮⋮ handle and, at its right, the keyboard
           reorder pair. It is transparent until the card is hovered, focused
           or selected, so an unselected card reads as pure preview — but the
           handle is always in the DOM, because SortableJS drags by it and the
           two step buttons are the keyboard path (WCAG 2.1.1). */
        html += '<div class="svb-grip">';
        html += '<span class="svb-handle" data-tip="' + esc(S.locked ? LOCK_TIP : 'Drag to reorder') + '" aria-hidden="true">' +
                '<i class="fas fa-grip-vertical"></i></span>';
        html += '<span class="svb-grip-keys">';
        html += moveBtn('q-up', -1, 'Move this element up', S.locked || pos === 0, ' data-qid="' + qid + '"');
        html += moveBtn('q-down', 1, 'Move this element down', S.locked || pos >= total - 1, ' data-qid="' + qid + '"');
        html += '</span>';
        html += '</div>';

        /* A conditional card still has to say so when it is closed: the type
           badge and Required chip are gone (the preview already renders the
           required asterisk, and the type is obvious from the control), but
           skip logic is invisible in a preview. One 16px flag, no extra row. */
        if (!selected) { html += showIfFlag(q); }

        html += '<div class="svb-item-body">';
        /* An unselected card is a PREVIEW: its controls are drawn by SvRender,
           whose touch sizing only applies inside .sv-root, so they arrived here
           full-size, live and focusable. The wrapper is what survey-build.css
           kills pointer events on; the two tabIndex sweeps that take them out
           of the tab order run in renderCanvas() and refreshCard(), so a third
           insertion path has to sweep too — the card itself stays selectable. */
        html += selected
            ? editCardHtml(q)
            : '<div class="svb-q-preview">' + SvRender.question(forRender(q), undefined, 'preview') + '</div>';
        html += '</div>';

        html += '</article>';
        return html;
    }

    /** The in-place editor for the selected card. */
    function editCardHtml(q) {
        var qid  = parseInt(q.question_id, 10);
        var html = '<div class="svb-edit">';

        /* Top row (spec §7 Density): the filled prompt field, the small image
           button beside it, and the Type picker at the top-right. The picker
           is still the native <select> the change handler and retype() path
           expect — the icon and caret are painted around it in CSS, so no
           behaviour moves into a custom widget. */
        html += '<div class="svb-card-top">';
        html += '<textarea class="svb-prompt svb-autogrow" rows="1" data-q-field="Prompt" ' +
                'aria-label="' + (q.type === 'section' ? 'Section heading' : 'Question') + '" placeholder="' +
                (q.type === 'section' ? 'Section heading' : 'Question') + '">' + esc(q.prompt || '') + '</textarea>';
        if (q.type !== 'image') {
            html += '<button type="button" class="svb-icon-btn svb-top-img" data-upload="question-image" ' +
                    'data-tip="Add a picture above the answers" aria-label="Add a picture above the answers">' +
                    '<i class="fas fa-image" aria-hidden="true"></i></button>';
        }
        html += typePickerHtml(q);
        html += '</div>';

        html += helpSlotHtml(q);
        if (q.type !== 'image' && imageUrl(q.image_id)) {
            html += '<div class="svb-cardimg">' +
                    '<img class="svb-cardimg-thumb" src="' + esc(imageUrl(q.image_id)) + '" alt="">' +
                    '<button type="button" class="svb-link" data-upload="question-image">Replace</button>' +
                    '<button type="button" class="svb-link svb-link-danger" data-imgclear="question-image">Remove</button>' +
                    '</div>';
        }

        html += '<div class="svb-typeedit">' + typeEditorHtml(q) + '</div>';
        html += moreMenuHtml(q);
        html += footerHtml(q, qid);

        html += '</div>';
        return html;
    }

    function helpSlotHtml(q) {
        var qid = parseInt(q.question_id, 10);
        var has = (q.help_md !== null && q.help_md !== undefined && String(q.help_md) !== '') || helpOpen[qid];
        var label = q.type === 'section' ? 'Body text (markdown)' : 'Help text (markdown)';

        if (q.type === 'section') { has = true; }
        if (!has) {
            return '<button type="button" class="svb-link svb-help-add" data-act="help-add">' +
                   '<i class="fas fa-plus" aria-hidden="true"></i> Add help text</button>';
        }
        return mdEditor('svb-help-' + qid, label, q.help_md, 'data-q-field="HelpMd"');
    }

    /* --------------------------------------------------- per-type editors */

    function typeEditorHtml(q) {
        switch (q.type) {
            case 'single':
            case 'multi':
            case 'dropdown':
            case 'yesno':
            case 'ranking':
                return optionRowsHtml(q, specFor(q, 'choice'));
            case 'pairwise':
                return pairwiseEditorHtml(q);
            case 'matrix':
                return matrixEditorHtml(q);
            case 'rating':
            case 'nps':
                return scaleEditorHtml(q);
            case 'short_text':
            case 'paragraph':
            case 'number':
            case 'date':
                return previewBody(q) + inlineSettingsHtml(q);
            case 'section':
                return '';
            case 'image':
                return imagePicker('Image', q.image_id, 'question-image', 'An image block needs a picture.') +
                       inlineSettingsHtml(q);
            default:
                return previewBody(q);
        }
    }

    /** The control glyph that makes an option row look like the real thing. */
    function optionGlyph(type, index) {
        if (type === 'multi') { return '<span class="svb-glyph svb-glyph-box" aria-hidden="true"></span>'; }
        if (type === 'ranking' || type === 'dropdown') {
            return '<span class="svb-glyph svb-glyph-num" aria-hidden="true">' + (index + 1) + '</span>';
        }
        return '<span class="svb-glyph svb-glyph-dot" aria-hidden="true"></span>';
    }

    /**
     * One editable option row. The single source of this markup: rows drawn on
     * render and rows appended by "+ Add option" come out of the same function.
     */
    function optionRowHtml(q, spec, o, index, count) {
        var isOther = truthy(o.is_other);
        var noun    = spec.role === 'choice' ? 'Option' : (spec.role === 'row' ? 'Row' : 'Column');
        /* .svb-opt is the density class (spec §7: 32px rows); .svb-optrow stays
           the behaviour hook every handler and SortableJS already binds to. */
        var html    = '<div class="svb-optrow svb-opt' + (spec.weight ? ' svb-optrow-col' : '') +
                      '" data-oid="' + (parseInt(o.option_id, 10) || 0) + '" data-other="' + (isOther ? 1 : 0) + '">';

        /* Order matters to the eye, not to the handlers (everything is found
           by data-act): the glyph and the label sit hard left like a real
           choice, and the grip, the reorder pair and the × live in a quiet
           gutter on the right. They keep their space when hidden so the row
           never jumps under the pointer. */
        if (spec.glyph) { html += optionGlyph(q.type, index); }
        html += '<input type="text" class="sv-input svb-optlabel" maxlength="255" value="' + esc(o.label || '') +
                '" placeholder="' + noun + '" aria-label="' + noun + ' ' + (index + 1) + '">';
        if (isOther) {
            html += '<span class="svb-other-tag" data-tip="Respondents type their own answer here">Other</span>';
        }
        if (spec.weight) {
            html += '<input type="number" class="sv-input svb-optweight" step="any" inputmode="decimal" placeholder="wt" ' +
                    'data-tip="Optional weight — set one on every column to get a weighted mean" ' +
                    'aria-label="Weight for ' + noun + ' ' + (index + 1) + '" value="' +
                    esc(o.value_num === null || o.value_num === undefined ? '' : o.value_num) + '"' + lockAttr() + '>';
        }
        if (!spec.fixed) {
            html += '<span class="svb-opthandle" data-tip="' + esc(S.locked ? LOCK_TIP : 'Drag to reorder') +
                    '" aria-hidden="true"><i class="fas fa-grip-vertical"></i></span>';
            html += moveBtn('opt-up', -1, 'Move this ' + noun.toLowerCase() + ' up',
                            S.locked || index === 0, '');
            html += moveBtn('opt-down', 1, 'Move this ' + noun.toLowerCase() + ' down',
                            S.locked || index >= count - 1, '');
        }
        html += iconBtn('opt-remove', 'fa-xmark',
                        spec.fixed ? 'A yes/no question keeps exactly two options' : ('Remove this ' + noun.toLowerCase()),
                        'svb-icon-danger', S.locked || !!spec.fixed || count <= spec.min, '');
        html += '</div>';
        return html;
    }

    /** A whole role: its rows plus the "+ Add …" links. */
    function optionGroupHtml(q, spec, caption, listClass) {
        var opts = optionsOf(q, spec.role);
        var noun = spec.role === 'choice' ? 'option' : spec.role;
        var html = '<div class="svb-opts" data-role="' + spec.role + '" data-min="' + spec.min +
                   '" data-fixed="' + (spec.fixed ? 1 : 0) + '">';
        var i;

        if (caption) { html += '<span class="svb-mx-caption">' + esc(caption) + '</span>'; }
        html += '<div class="svb-optlist' + (listClass ? ' ' + listClass : '') + '">';
        for (i = 0; i < opts.length; i++) {
            html += optionRowHtml(q, spec, opts[i], i, opts.length);
        }
        html += '</div>';

        /* The last row of the list reads as one sentence — "Add option or add
           'Other'" — with the two verbs as accent links, sitting on the same
           32px grid as the rows above it (spec §7 Density). */
        if (!spec.fixed) {
            html += '<div class="svb-optadd svb-opt">';
            if (spec.glyph) { html += '<span class="svb-glyph svb-glyph-ghost" aria-hidden="true"></span>'; }
            html += '<span class="svb-optadd-line">';
            html += '<button type="button" class="svb-optadd-link" data-act="opt-add"' + lockAttr() + '>Add ' +
                    esc(noun) + '</button>';
            if (spec.other && !hasOther(q)) {
                html += '<span class="svb-optadd-or"> or </span>';
                html += '<button type="button" class="svb-optadd-link" data-act="opt-add-other"' + lockAttr() +
                        ' data-tip="A write-in row respondents fill in themselves">add “Other”</button>';
            }
            html += '</span>';
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    function optionRowsHtml(q, spec) {
        return optionGroupHtml(q, optionRowsHtmlSpec(spec), null, null);
    }

    function hasOther(q) {
        var opts = optionsOf(q, 'choice'), i;
        for (i = 0; i < opts.length; i++) {
            if (truthy(opts[i].is_other)) { return true; }
        }
        return false;
    }

    /** Columns across the top (scrolling strip), rows down the left. */
    function matrixEditorHtml(q) {
        var html = '<div class="svb-mx">';
        html += optionGroupHtml(q, specFor(q, 'column'), 'Columns', 'svb-mx-colstrip');
        html += optionGroupHtml(q, specFor(q, 'row'), 'Rows', null);
        html += '</div>';
        return html;
    }

    /* ------------------------------------------------------- pairwise editor */

    /** One option per line: trimmed, list bullets stripped, blanks dropped, 255 chars max. */
    function pairwiseLines(text) {
        return String(text || '').split(/\r\n|\r|\n/).map(function (line) {
            return line.replace(/^\s*[-*•]\s+/, '').trim().slice(0, 255);
        }).filter(function (line) { return line !== ''; });
    }

    function pairwiseReadout(n) {
        var p = SvRender.pairwisePlan(n);
        if (n < 3) { return 'Add at least 3 options, one per line.'; }
        return n + ' options → ' + p.possible.toLocaleString() + ' matchups · required respondents do ' +
               (p.small ? 'all ' + p.possible : 'at least ' + p.gate.toLocaleString());
    }

    function pairwiseWarning(n) {
        return n > 30
            ? 'Over 30 options makes for a long question: ' + n + ' options is ' +
              SvRender.pairwisePlan(n).possible.toLocaleString() + ' matchups.'
            : '';
    }

    /**
     * Pairwise options are one textarea, one per line (pairwise spec §5), so a
     * list can be pasted in one go. The readout under it recomputes the plan
     * live; the (?) explains the gate and the bands with this question's numbers.
     */
    function pairwiseEditorHtml(q) {
        var qid   = parseInt(q.question_id, 10);
        var hold  = held['opts:' + qid + ':choice'];
        // A held list (a duplicate, too few lines, a locked count change) is in
        // neither S nor the server, so a redraw puts the author's text back.
        var text  = hold && typeof hold.text === 'string' ? hold.text
                  : optionsOf(q, 'choice').map(function (o) { return String(o.label || ''); }).join('\n');
        var n     = pairwiseLines(text).length;
        var id    = 'svb-pw-lines-' + qid;
        var warn  = pairwiseWarning(n);
        var html  = '<div class="svb-field svb-pw">';
        html += '<label class="svb-label" for="' + id + '">Options, one per line</label>';
        html += '<textarea class="sv-textarea svb-pw-lines svb-autogrow" id="' + id + '" rows="' +
                Math.min(14, Math.max(4, n + 1)) + '" spellcheck="true">' + esc(text) + '</textarea>';
        html += '<div class="svb-pw-foot">';
        html += '<p class="svb-hint svb-pw-readout" aria-live="polite">' + esc(pairwiseReadout(n)) + '</p>';
        html += '<button type="button" class="svb-icon-btn svb-pw-help" data-act="pw-help" ' +
                'data-tip="How pairwise questions work" aria-label="How pairwise questions work">' +
                '<i class="fas fa-circle-question" aria-hidden="true"></i></button>';
        html += '</div>';
        html += '<p class="svb-pw-warn"' + (warn ? '' : ' hidden') + '>' +
                '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i> <span>' + esc(warn) + '</span></p>';
        if (S.locked) { html += '<p class="svb-hint">Wording fixes only, while the survey is open.</p>'; }
        html += '</div>';
        return html;
    }

    function paintPairwiseReadout(area) {
        var wrap = area.closest('.svb-pw'), n = pairwiseLines(area.value).length, warn, text;
        if (!wrap) { return; }
        el('.svb-pw-readout', wrap).textContent = pairwiseReadout(n);
        warn = el('.svb-pw-warn', wrap);
        text = pairwiseWarning(n);
        el('span', warn).textContent = text;
        warn.hidden = text === '';
    }

    /**
     * Save the textarea through option_set (replace-all). Unlocked, a line that
     * matches a saved label reuses that option's id, so reordering or inserting
     * lines keeps ids; locked, line i is option i and only wording may change.
     * A duplicate, too few lines, or a locked line-count change is held inline
     * and the pill reads "Not saved" until it is fixed. The hold keeps the
     * textarea's text, so a redraw of the open card puts it back, and closing
     * the card says the last saved options were kept (dropPairwiseHold).
     */
    function commitPairwise(questionId, keep) {
        var card  = cardEl(questionId);
        var area  = (card ? el('.svb-pw-lines', card) : null) || keep || null;
        var q     = questionById(questionId);
        var key   = 'opts:' + questionId + ':choice';
        var seen  = {}, byLabel = {}, used = {}, dup = null, problem = '', lines, saved, list;
        if (!area || !q) { return; }

        lines = pairwiseLines(area.value);
        saved = optionsOf(q, 'choice');
        lines.forEach(function (l) {
            var k = l.toLowerCase();
            if (seen[k] && dup === null) { dup = l; }
            seen[k] = true;
        });
        if (dup !== null) {
            problem = '“' + dup + '” is listed twice.';
        } else if (lines.length < 3) {
            problem = 'A pairwise question needs at least 3 options.';
        } else if (S.locked && lines.length !== saved.length) {
            problem = 'While the survey is open you can fix wording, but not add or remove options.';
        }
        if (problem) {
            // The save an earlier keystroke queued carries this line half
            // typed ("Fall feas" on the way to a duplicate "Fall feast"). It
            // must not go out either (spec §5: nothing saves until fixed), and
            // S goes back to the list the last sent save had.
            if (pending[key]) {
                window.clearTimeout(pending[key].timer);
                delete pending[key];
                if (pwBase[questionId]) { q.options = pwBase[questionId]; }
            }
            if (optBusy[key]) { optBusy[key].again = null; }
            delete pwBase[questionId];
            held[key] = { msg: problem, loc: locOf(area), text: area.value, pw: true };
            fieldError(area, problem);
            refreshPill();
            return;
        }
        delete held[key];
        fieldError(area, '');

        if (S.locked) {
            list = lines.map(function (l, i) {
                return { option_id: parseInt(saved[i].option_id, 10), label: l, value_num: null, is_other: 0 };
            });
        } else {
            saved.forEach(function (o) {
                var k = String(o.label || '').trim();
                var oid = parseInt(o.option_id, 10) || 0;
                if (oid && !Object.prototype.hasOwnProperty.call(byLabel, k)) { byLabel[k] = oid; }
            });
            list = lines.map(function (l) {
                var oid = Object.prototype.hasOwnProperty.call(byLabel, l) ? byLabel[l] : 0;
                if (oid && used[oid]) { oid = 0; }
                if (oid) { used[oid] = true; }
                return { option_id: oid, label: l, value_num: null, is_other: 0 };
            });
        }

        // S follows the textarea at once, so a preview drawn before the reply
        // shows what was typed. The list it replaces is kept while this save
        // waits in its debounce, so a hold can take the queued save back.
        if (!pending[key]) { pwBase[questionId] = q.options; }
        q.options = list.map(function (o, i) {
            return { option_id: o.option_id, question_id: q.question_id, role: 'choice', sort_order: i,
                     label: o.label, value_num: null, is_other: 0 };
        });

        // The last list is still on the wire: its reply carries the new
        // options' ids, and this list is rebuilt against them then (#29).
        if (optBusy[key]) {
            optBusy[key].again = function () { commitPairwise(questionId, area); };
            refreshPill();
            return;
        }
        save(key, 'option_set', {
            QuestionId: questionId,
            Role:       'choice',
            Options:    JSON.stringify(list)
        }, function (data) {
            var cur = questionById(questionId);
            if (!cur) { return; }
            cur.options = data.options || [];
            // A newer save already queued would fall back to this, the saved list.
            if (pending[key]) { pwBase[questionId] = cur.options; }
            if (parseInt(questionId, 10) !== sel) { refreshCard(questionId); }
        }, { node: area, onSent: optionSetSent(key) });
        refreshPill();
    }

    /**
     * Let go of a held pairwise list whose textarea is going away. Its text was
     * never sent, so the card falls back to the last saved options, and the
     * author is told why (the same way a held blank prompt is).
     */
    function dropPairwiseHold(questionId) {
        var key = 'opts:' + parseInt(questionId, 10) + ':choice';
        var h   = held[key];
        if (!h || !h.pw) { return; }
        delete held[key];
        notice('Those pairwise options were not saved: ' + h.msg + ' The last saved options were kept.', 'warn');
    }

    /** The (?) explanation (pairwise spec §5 Help), built from this question's plan. */
    function pairwiseHelpHtml(n) {
        var plan = SvRender.pairwisePlan(n), bands = SvRender.PW_BANDS, lo = 31, rows = '', sentence, stages, i;

        if (n < 3) {
            sentence = 'Add at least 3 options to see this question\'s numbers.';
        } else if (plan.small) {
            sentence = 'Your ' + n + ' options make ' + plan.possible + ' matchups. Respondents see a plain progress bar, ' +
                       'and a required question asks for all ' + plan.possible + '.';
        } else {
            sentence = 'Your ' + n + ' options make ' + plan.possible.toLocaleString() + ' matchups. Required respondents do at least ' +
                       plan.gate + ' (' + plan.band_pcts[0] + '%), then see encouragement at ' +
                       plan.tiers[1] + ', ' + plan.tiers[2] + ' and ' + plan.tiers[3] + '.';
        }

        rows += '<tr' + (n >= 3 && plan.small ? ' class="svb-pw-here"' : '') + '><th scope="row">30 or fewer</th>' +
                '<td colspan="4">A plain bar; required means every matchup</td></tr>';
        for (i = 0; i < bands.length; i++) {
            var hi = bands[i][0];
            var here = n >= 3 && !plan.small && plan.possible >= lo && (hi === null || plan.possible <= hi);
            rows += '<tr' + (here ? ' class="svb-pw-here"' : '') + '><th scope="row">' +
                    (hi === null ? lo + ' or more' : lo + '–' + hi) + '</th>' +
                    bands[i][1].map(function (p) { return '<td>' + p + '%</td>'; }).join('') + '</tr>';
            lo = (hi || 0) + 1;
        }

        // The exact line a required respondent sees at zero picks, so the count is this question's own.
        var firstRequired = n < 3
            ? 'A count of the matchups left before they can continue'
            : '“' + esc(SvRender.pairwiseStage(plan, 0, true).message) + '”';
        stages = '<li data-level="0"><span class="svb-pw-swatch" aria-hidden="true"></span><span><strong>Before the first mark:</strong> ' +
                 firstRequired + ' (required) or “' + esc(SvRender.PW_OPTIONAL_MESSAGE) + '” (optional)</span></li>';
        SvRender.PW_TIER_MESSAGES.forEach(function (m, idx) {
            stages += '<li data-level="' + (idx + 1) + '"><span class="svb-pw-swatch" aria-hidden="true"></span><span>“' + esc(m) + '”</span></li>';
        });
        stages += '<li data-level="4"><span class="svb-pw-swatch" aria-hidden="true"></span><span><strong>At 100%:</strong> “' +
                  esc(SvRender.PW_DONE_MESSAGE) + '”</span></li>';

        return '<div class="svb-pw-helpbody sv-scope">' +
            '<p>Respondents see two options at a time and pick the one they prefer, or call it a tie. A win scores 1 point, ' +
            'a tie ½ to each, a loss 0. Results rank the options by <strong>win %</strong>: points divided by the matchups ' +
            'the option appeared in.</p>' +
            '<p>Every respondent gets their own random order of matchups, with sides picked at random, so no option is ' +
            'favored by where it sits in your list.</p>' +
            '<h3>How many they\'re asked to do</h3>' +
            '<p>' + esc(sentence) + '</p>' +
            '<div class="svb-pw-tablewrap"><table class="svb-pw-bands"><thead><tr><th scope="col">Matchups</th>' +
            '<th scope="col">Can continue</th><th scope="col">Even better</th><th scope="col">Awesome</th>' +
            '<th scope="col">Fantastic</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
            '<h3>Required or optional</h3>' +
            '<p>A <strong>required</strong> pairwise question keeps Next locked until the respondent reaches “Can continue”. ' +
            'With 30 matchups or fewer, that means every one. An <strong>optional</strong> question can be skipped, and any ' +
            'matchups a respondent does still count.</p>' +
            '<h3>What respondents see</h3>' +
            '<ol class="svb-pw-stages">' + stages + '</ol>' +
            '<p class="svb-hint">Keep it to 30 options or fewer when you can. Past that, each respondent covers a small ' +
            'slice of the matchups, so the ranking needs more respondents to settle.</p>' +
            '</div>';
    }

    /** The spec object for one role of a question's type. */
    function specFor(q, role) {
        var list = OPTION_ROLES[q.type] || [], i;
        for (i = 0; i < list.length; i++) {
            if (list[i].role === role) { return list[i]; }
        }
        return { role: role, label: role, min: 0 };
    }

    /**
     * rating / nps: the real scale, with its end labels editable underneath and
     * (rating only) the points and star/number pickers on the settings line.
     */
    function scaleEditorHtml(q) {
        var defs = FIELD_DEFS[q.type] || [];
        // The renderer's own end labels and Clear button are replaced by the
        // editable inputs below, so they are dropped from the preview.
        var html = previewBody(q, '.sv-scale-ends, .sv-scale-clear');
        var ends = [], line = [];

        defs.forEach(function (def) {
            if (def.where !== 'inline') { return; }
            if (def.key === 'min_label' || def.key === 'max_label') { ends.push(def); return; }
            line.push(def);
        });

        if (ends.length) {
            html += '<div class="svb-scale-ends">';
            ends.forEach(function (def) {
                html += '<label class="svb-endlabel svb-endlabel-' + (def.key === 'min_label' ? 'min' : 'max') + '">' +
                        '<span class="sv-visually-hidden">' + esc(def.label) + '</span>' +
                        textInput({ max: def.max, placeholder: def.placeholder || def.label },
                                  settingOf(q, def),
                                  'data-q-setting="' + def.key + '" data-kind="text"') +
                        '</label>';
            });
            html += '</div>';
        }
        if (line.length) {
            html += '<div class="svb-settingline">';
            line.forEach(function (def) { html += settingField(q, def); });
            html += '</div>';
        }
        return html;
    }

    /** The compact settings line under a text / number / date preview. */
    function inlineSettingsHtml(q) {
        var defs = (FIELD_DEFS[q.type] || []).filter(function (d) { return d.where === 'inline'; });
        var html = '';
        if (!defs.length) { return ''; }
        html += '<div class="svb-settingline">';
        defs.forEach(function (def) { html += settingField(q, def); });
        html += '</div>';
        return html;
    }

    function settingField(q, def) {
        var value = settingOf(q, def);
        var attr  = 'data-q-setting="' + def.key + '" data-kind="' + def.kind + '"';

        switch (def.kind) {
            case 'bool':
                return checkRow(def.label, truthy(value) || value === true, attr, S.locked, def.hint);
            case 'select':
                return selectRow(def.label, def.options, value, attr, S.locked, def.hint);
            case 'int':
                return fieldRow(textInput({ type: 'number', min: def.min, hi: def.max, step: 1, disabled: S.locked },
                                          value, attr + ' inputmode="numeric"'), def.label, def.hint);
            case 'number':
                return fieldRow(textInput({ type: 'number', step: 'any', disabled: S.locked },
                                          value === null || value === undefined ? '' : value,
                                          attr + ' inputmode="decimal"'), def.label, def.hint);
            case 'date':
                return fieldRow(textInput({ type: 'date', disabled: S.locked },
                                          value === null || value === undefined ? '' : value, attr),
                                def.label, def.hint);
            default:
                return fieldRow(textInput({ max: def.max, placeholder: def.placeholder, disabled: S.locked },
                                          value === null || value === undefined ? '' : value, attr),
                                def.label, def.hint);
        }
    }

    /* ------------------------------------------------------- ⋯ menu + footer */

    function moreMenuHtml(q) {
        var defs = (FIELD_DEFS[q.type] || []).filter(function (d) { return d.where === 'more'; });
        var html = '<div class="svb-more" id="svb-more-' + parseInt(q.question_id, 10) + '" hidden>';

        if (defs.length) {
            html += '<h4 class="svb-sub">Behaviour</h4>';
            defs.forEach(function (def) { html += settingField(q, def); });
        }

        html += '<h4 class="svb-sub">Skip logic</h4>';
        html += showIfEditor(q, questionIndex(q.question_id), 'q');

        if (q.type !== 'image') {
            html += '<h4 class="svb-sub">Illustration</h4>';
            html += imagePicker('Picture above the answers', q.image_id, 'question-image');
        }

        html += '</div>';
        return html;
    }

    /**
     * The Type picker that sits at the top-right of the open card. It is a
     * plain <select class="svb-typesel"> with the type's icon and a caret
     * drawn behind it by survey-build.css, so it reads as "icon + label +
     * caret" at 34px. A retype is COMMITTED, not fired per change event:
     * a pick with the pointer (or a phone's picker) commits at once, but a
     * keyboard walk through the closed select — which on Windows fires
     * `change` at every arrow press — only commits on Enter or on leaving
     * the control (commitType). A retype that would lose options or skip
     * rules asks first.
     */
    function typePickerHtml(q) {
        var html = '<span class="svb-typepick">';
        var i, t;

        html += '<i class="fas ' + typeIcon(q.type) + ' svb-typepick-icon" aria-hidden="true"></i>';
        html += '<select class="svb-typesel" aria-label="Element type"' + lockAttr() + '>';
        for (i = 0; i < TYPE_ORDER.length; i++) {
            t = TYPE_ORDER[i];
            html += '<option value="' + t + '"' + (t === q.type ? ' selected' : '') + '>' +
                    esc((TYPE_META[t] || {}).label || t) + '</option>';
        }
        html += '</select>';
        html += '</span>';
        return html;
    }

    /**
     * The slim card footer (spec §7 Density): 36px, right-aligned, duplicate
     * and delete as icon buttons, a hairline divider, Required as a switch,
     * then the ⋯ menu. The Type picker left this row for the card top.
     */
    function footerHtml(q, qid) {
        var html = '<div class="svb-card-foot">';

        html += iconBtn('q-duplicate', 'fa-clone', 'Duplicate this element', '', S.locked, ' data-qid="' + qid + '"');
        html += iconBtn('q-delete', 'fa-trash', 'Delete this element', 'svb-icon-danger', S.locked, ' data-qid="' + qid + '"');

        if (SvRender.isAnswerable(q.type)) {
            html += '<span class="svb-foot-div" aria-hidden="true"></span>';
            html += '<label class="svb-switchwrap"><span class="svb-switchlabel">Required</span>' +
                    '<span class="svb-switch"><input type="checkbox" data-q-field="Required"' +
                    (truthy(q.required) ? ' checked' : '') + lockAttr() +
                    '><span class="svb-switch-track" aria-hidden="true"></span></span></label>';
        }

        html += '<span class="svb-foot-div" aria-hidden="true"></span>';
        html += iconBtn('more-toggle', 'fa-ellipsis', 'More settings', '', false,
                        ' aria-expanded="false" aria-controls="svb-more-' + qid + '"');
        html += '</div>';
        return html;
    }

    /* ------------------------------------------------------------ selection */

    function select(id, focusPrompt) {
        var prev = sel;
        var next = parseInt(id, 10) || 0;
        // Re-selecting the open card would redraw it under the user's caret.
        if (next === prev && !focusPrompt) { return; }
        if (prev && prev !== next) { settleCard(prev); }
        sel = next;
        if (prev && prev !== sel) { refreshCard(prev); }
        if (sel) { refreshCard(sel, focusPrompt); }
        wireOptionSortables();
        // refreshCard swaps the card's DOM node, so the spy has to be pointed
        // at the new one before the outline can say where we are.
        wireTocSpy();
        markToc();
        syncMarks();
    }

    /**
     * A card is closing: nothing of it may stay held. Option rows need no
     * work (a blank new row was never sent, and a cleared existing row was
     * sent with its last label). A held blank prompt and a held pairwise
     * option list are let go — the preview redraws the last saved wording or
     * options, and the author is told why.
     */
    function settleCard(questionId) {
        var qid = parseInt(questionId, 10);
        var q   = questionById(qid);
        var key = 'q:' + qid + ':Prompt';
        if (held[key]) {
            delete held[key];
            notice((q && q.type === 'section' ? 'A section needs a heading' : 'A question needs a prompt') +
                   ', so its last saved wording was kept.', 'warn');
        }
        dropPairwiseHold(qid);
        Object.keys(held).forEach(function (k) {
            if (k.indexOf('opts:' + qid + ':') === 0) { delete held[k]; }
        });
        refreshPill();
    }

    /** Open the ⋯ menu of a card and focus one control inside it. */
    function openMore(questionId, focusSelector) {
        var card = cardEl(questionId);
        var more = card ? el('.svb-more', card) : null;
        var btn  = card ? el('[data-act="more-toggle"]', card) : null;
        var node;
        if (!more) { return; }
        more.hidden = false;
        if (btn) { btn.classList.add('svb-icon-on'); btn.setAttribute('aria-expanded', 'true'); }
        node = focusSelector ? el(focusSelector, more) : null;
        if (node) { node.focus(); }
    }

    /** Open a page's ⋯ panel again after a re-render. */
    function openPageMore(pageId, focusSelector) {
        var panel = el('.svb-page-more[data-page="' + parseInt(pageId, 10) + '"]');
        var btn   = el('[data-act="page-more"][data-page="' + parseInt(pageId, 10) + '"]');
        var node;
        if (!panel) { return; }
        panel.hidden = false;
        if (btn) { btn.classList.add('svb-icon-on'); btn.setAttribute('aria-expanded', 'true'); }
        node = focusSelector ? el(focusSelector, panel) : null;
        if (node) { node.focus(); }
    }

    /**
     * Redraw one page's header and ⋯ panel in place (its skip-logic chip
     * changed) — never the items under it, and never while it has the focus.
     */
    function refreshPageHead(pageId) {
        var pid  = parseInt(pageId, 10);
        var sec  = el('.svb-page[data-page="' + pid + '"]');
        var page = pageById(pid);
        var head = sec ? el('.svb-page-head', sec) : null;
        var more = sec ? el('.svb-page-more', sec) : null;
        var tmp, wasOpen;
        if (!sec || !page || !head || !more) { return; }
        if (head.contains(document.activeElement) || more.contains(document.activeElement)) { return; }
        wasOpen = !more.hidden;
        tmp = document.createElement('div');
        tmp.innerHTML = renderPageHead(page, S.pages.indexOf(page), questionsOfPage(pid).length);
        sec.replaceChild(tmp.children[0], head);
        sec.replaceChild(tmp.children[0], more);
        if (wasOpen) { openPageMore(pid); }
    }

    /** Redraw one card in place. Never called on a card the user is typing into. */
    function refreshCard(questionId, focusPrompt) {
        var node = cardEl(questionId);
        var q    = questionById(questionId);
        var fresh, area;
        if (!node) { return; }
        if (!q) { node.parentNode.removeChild(node); return; }

        node.insertAdjacentHTML('beforebegin', cardHtml(q));
        fresh = node.previousElementSibling;
        node.parentNode.removeChild(node);

        els('.svb-autogrow', fresh).forEach(autoGrow);
        // A preview's controls are dead to the pointer in CSS; this is the
        // keyboard half, so Tab walks the cards and not ~70 inert checkboxes.
        els('.svb-q-preview :is(input, select, textarea, button, a)', fresh).forEach(function (c) { c.tabIndex = -1; });
        if (focusPrompt) {
            area = el('.svb-prompt', fresh);
            if (area) { area.focus(); area.setSelectionRange(area.value.length, area.value.length); }
        }
        // The open card's fields were redrawn (+ Add help text, an image, a
        // show-if): a held list or refused field gets its reason back beside it.
        if (parseInt(questionId, 10) === sel) { syncMarks(); }
    }

    /** Redraw only the type-specific editor of the selected card (settings changed). */
    function refreshTypeEditor(q, focusKey) {
        var card = cardEl(q.question_id);
        var box  = card ? el('.svb-typeedit', card) : null;
        var node;
        if (!box) { return; }
        box.innerHTML = typeEditorHtml(q);
        wireOptionSortables();
        if (focusKey) {
            node = el('[data-q-setting="' + focusKey + '"]', card);
            if (node) { node.focus(); }
        }
    }

    function scrollToSelection() {
        var node = cardEl(sel);
        if (node && node.scrollIntoView) { node.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }

    /* ---------------------------------------------------------- header bits */

    /**
     * An <input> gives no ellipsis, so on a narrow header a long title simply
     * ends mid-word with no sign there is more. The shared data-tip tooltip
     * (survey-tip.js) puts the whole string one hover or long-press away. (The visually-hidden <label>
     * stays the accessible name — the value is not a label.)
     */
    function mirrorTitleTip(node) {
        if (!node) { return; }
        node.removeAttribute('title');
        node.setAttribute('data-tip', String(node.value || ''));
    }

    function renderHeader() {
        var s       = S.survey || {};
        var status  = String(s.status || 'draft');
        var pill    = $('svb-statuspill');
        var title   = $('svb-title');
        var openBtn = $('svb-openclose');
        var lockBar = $('svb-lockbar');

        // Never over the author's own text: not while they are in the box, not
        // while a newer title waits in its debounce, not while a blank is held.
        if (title && document.activeElement !== title && !pending['survey:Title'] && !held['survey:Title']) {
            title.value = s.title || '';
        }
        mirrorTitleTip(title);
        if (pill) {
            pill.className = 'svb-status-pill svb-status-' + status;
            pill.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        }
        if (openBtn) {
            if (status === 'open') {
                openBtn.innerHTML = '<i class="fas fa-lock" aria-hidden="true"></i> Close survey';
                openBtn.setAttribute('data-target', 'closed');
            } else if (status === 'closed' || status === 'archived') {
                openBtn.innerHTML = '<i class="fas fa-lock-open" aria-hidden="true"></i> Reopen survey';
                openBtn.setAttribute('data-target', 'open');
            } else {
                openBtn.innerHTML = '<i class="fas fa-paper-plane" aria-hidden="true"></i> Open survey';
                openBtn.setAttribute('data-target', 'open');
            }
        }
        if (lockBar) { lockBar.hidden = !S.locked; }
    }

    function renderAll() {
        renderHeader();
        renderCanvas();
        renderSettings();
    }

    /* ----------------------------------------------------- settings sidebar */

    /* The survey's settings are the page's left .rp-sidebar, not a drawer: a
       stack of .rp-filter-card sections whose header is a button that folds the
       body away. Which ones are open is remembered per survey, and under 900px
       reports.css stacks the sidebar ABOVE the canvas, so everything starts
       collapsed there to keep the questions reachable. */

    var SECTION_KEY = 'sv-build-sections-' + SURVEY_ID;
    var NARROW_MQ   = '(max-width: 900px)';

    function isNarrow() {
        return !!(window.matchMedia && window.matchMedia(NARROW_MQ).matches);
    }

    /* Desktop and narrow are different layouts with different right answers —
       under 900px the sidebar sits ON TOP of the questions — so each keeps its
       own record. Sharing one would let a desktop expansion bury the canvas on
       a phone, which is exactly what the collapsed-by-default rule prevents. */
    function stateKey(id) {
        return (isNarrow() ? 'n:' : 'd:') + id;
    }

    /** { 'd:sectionId': true|false }. Absent = the section's default. */
    function sectionState() {
        var raw;
        try {
            raw = window.localStorage.getItem(SECTION_KEY);
        } catch (e) {
            return {};
        }
        if (!raw) { return {}; }
        try {
            raw = JSON.parse(raw);
        } catch (e2) {
            return {};
        }
        return (raw && typeof raw === 'object') ? raw : {};
    }

    function rememberSection(id, open) {
        var st = sectionState();
        st[stateKey(id)] = !!open;
        try {
            window.localStorage.setItem(SECTION_KEY, JSON.stringify(st));
        } catch (e) { /* private browsing: the sidebar still works, it just forgets */ }
    }

    /** Basics and About on desktop. Under 900px the settings are their own
        view (the Questions | Settings switch), so they no longer bury the
        canvas: Basics opens there too, and About stays folded. */
    function sectionOpen(id) {
        var st  = sectionState();
        var key = stateKey(id);
        if (Object.prototype.hasOwnProperty.call(st, key)) { return !!st[key]; }
        return id === 'basics' || (id === 'about' && !isNarrow());
    }

    /** One collapsible .rp-filter-card. `icon` is a FontAwesome class;
        `bodyClass` is an optional extra class for the body (rp-about-body). */
    function section(id, title, icon, body, bodyClass) {
        var open = sectionOpen(id);
        return '<div class="rp-filter-card svb-sec" data-sec="' + id + '">' +
               '<button type="button" class="rp-filter-card-header svb-sec-head" data-sec-toggle="' + id + '"' +
               ' aria-expanded="' + (open ? 'true' : 'false') + '" aria-controls="svb-sec-' + id + '">' +
               '<i class="fas ' + icon + '" aria-hidden="true"></i>' +
               '<span class="svb-sec-title">' + esc(title) + '</span>' +
               '<i class="fas fa-chevron-down svb-sec-caret" aria-hidden="true"></i>' +
               '</button>' +
               '<div class="rp-filter-card-body svb-sec-body' + (bodyClass ? ' ' + bodyClass : '') +
               '" id="svb-sec-' + id + '"' +
               (open ? '' : ' hidden') + '>' + body + '</div>' +
               '</div>';
    }

    function toggleSection(id) {
        var card = el('.svb-sec[data-sec="' + id + '"]');
        var head = card ? el('.svb-sec-head', card) : null;
        var body = card ? el('.svb-sec-body', card) : null;
        var open;
        if (!head || !body) { return; }
        open = head.getAttribute('aria-expanded') !== 'true';
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
        body.hidden = !open;
        rememberSection(id, open);
    }

    function renderSettings() {
        var box = $('svb-settings');
        var s   = S.survey || {};
        var html = '';
        var body, kingdomList;
        if (!box) { return; }

        /* The sidebar is always on screen now, so a structural renderAll() can
           land while someone is mid-word in the description or the welcome
           markdown. Repainting would eat the caret and the un-flushed keystroke,
           so leave the sidebar alone whenever it holds the focus — the next
           render after they tab out picks the new state up. */
        if (box.contains(document.activeElement)) { return; }

        /* Basics — the title lives in the header, inline-editable, so the only
           thing left here is the description every list and the banner reuse. */
        html += section('basics', 'Basics', 'fa-circle-info',
            fieldRow('<textarea class="sv-textarea" id="svb-f-desc" rows="3" maxlength="500" data-sv-field="Description">' +
                     esc(s.description || '') + '</textarea>',
                     'Short description',
                     'Plain text. Shown in lists, the Available Surveys widget and the banner.',
                     'svb-f-desc'));

        /* Screens */
        body  = mdEditor('svb-f-welcome', 'Welcome text (markdown)', s.welcome_md, 'data-sv-field="WelcomeMd"',
                         'Leave blank to send respondents straight to page 1.');
        body += imagePicker('Welcome image', s.welcome_image_id, 'survey-welcome');
        body += '<hr class="svb-sec-rule">';
        body += mdEditor('svb-f-thanks', 'Thank-you text (markdown)', s.thanks_md, 'data-sv-field="ThanksMd"',
                         'Leave blank for the default thank-you.');
        body += imagePicker('Thank-you image', s.thanks_image_id, 'survey-thanks');
        html += section('screens', 'Screens', 'fa-window-maximize', body);

        /* Audience */
        /* NOT .rp-scope-chip: that chip is authored for the dark navy .rp-header
           (translucent white on navy) and is unreadable on the light sidebar.
           The sidebar gets its own chip on the page's own surface tokens. */
        body  = '<div class="svb-field"><span class="svb-label">Scope</span>' +
                '<span class="svb-scope-chip"><i class="fas ' + esc(scopeIcon(s.scope_type)) + '" aria-hidden="true"></i> ' +
                esc(scopeName(s)) + '</span></div>';
        if (String(s.scope_type) === 'ork') {
            kingdomList = (scopes || []).filter(function (x) { return x.scope_type === 'kingdom'; });
            body += fieldRow(kingdomPicker(s, kingdomList), 'Kingdoms', 'Select none to invite every kingdom.');
        }
        /* The account flag is "not retired", not "has played lately" — the
           label says exactly that, and the attendance rule below is the one
           that looks at sign-ins. */
        body += checkRow('Exclude retired accounts', truthy(s.audience_active_only), 'data-sv-field="AudienceActiveOnly"', false,
                         'Leaves out accounts marked retired. It does not look at attendance; use the rule below for that.');
        body += fieldRow(textInput({ id: 'svb-f-recent', type: 'number', min: 0, hi: 120, step: 1 },
                                   parseInt(s.audience_recent_months, 10) || 0,
                                   'data-sv-field="AudienceRecentMonths" inputmode="numeric"'),
                         'Attended in the last … months',
                         '0 = any. Otherwise a player needs a sign-in ' +
                         (String(s.scope_type) === 'ork' ? 'anywhere' : 'in this ' + (String(s.scope_type) === 'park' ? 'park' : 'kingdom')) +
                         ' within that many months (up to 120).', 'svb-f-recent');
        body += fieldRow(eventPickerHtml(s), 'Attended an event',
                         String(s.scope_type) === 'ork'
                             ? 'Only players signed in at this event can answer, wherever they play.'
                             : 'Only players signed in at this event can answer — and that includes visitors from other parks and kingdoms, ' +
                               'who are otherwise outside this survey\'s scope.', 'svb-f-event');
        body += fieldRow(textInput({ id: 'svb-f-tenure', type: 'number', min: 0, hi: 1200, step: 1 },
                                   s.audience_min_tenure_months, 'data-sv-field="AudienceMinTenureMonths" inputmode="numeric"'),
                         'Minimum months played', '0 lets everyone in scope answer.', 'svb-f-tenure');
        html += section('audience', 'Audience', 'fa-users', body);

        /* Schedule. Both fields are Flatpickr pickers with altInput on, so the
           box a builder reads says "September 12, 2026 at 06:00 PM" in the
           viewer's own clock (seeded from open_ts / close_ts). A save sends
           the picked instant, which the server stores as its wall time
           (empty clears the date). initSchedulePickers() attaches them after
           this HTML lands. */
        body  = fieldRow(dateField('svb-f-openat', 'OpenAt', s.open_at, 'No opening date set.', s.open_ts),
                         'Opens', 'A survey never opens by itself — this only stops it being taken early.', 'svb-f-openat');
        body += fieldRow(dateField('svb-f-closeat', 'CloseAt', s.close_at, 'No closing date set.', s.close_ts),
                         'Closes', null, 'svb-f-closeat');
        html += section('schedule', 'Schedule', 'fa-calendar-days', body);

        /* Privacy — the consent wording is fixed, and shown here so the builder
           knows exactly what the respondent is agreeing to. */
        body  = checkRow('Ask the data-gate consent question before submitting',
                         truthy(s.data_gate_enabled), 'data-sv-field="DataGateEnabled"', false,
                         'Turn this off and every response is stored anonymously.');
        body += consentQuote(s);
        if (s.scope_type === 'ork' || s.scope_type === 'kingdom') {
            var down = s.scope_type === 'ork' ? 'kingdoms' : 'parks';
            var each = s.scope_type === 'ork' ? 'Each kingdom sees its own players' : 'Each park sees its own players';
            /* Radios, not a select: in the sidebar a closed select cut the
               chosen option short ("Each park sees its own pl…"), so the saved
               setting could not be read without opening it. */
            body += radioRow('Share results with ' + down, 'svb-results-share',
                [['none', 'Don’t share'], ['scoped', each], ['all', 'Every ' + down.replace(/s$/, '') + ' sees all results']],
                s.results_share || 'none', 'data-sv-field="ResultsShare"',
                'Shared ' + down + ' see charts and stats only — never names, individual responses or the spreadsheet.');
            /* When the shared levels get them (sharing spec §2). Disabled while
               nothing is shared; onSettingsInput re-enables it live. */
            body += radioRow('When shared ' + down + ' see results', 'svb-results-timing',
                [['ongoing', 'Ongoing — as results come in'], ['after_close', 'After close — 24 hours after the survey ends']],
                s.results_share_timing || 'after_close',
                'data-sv-field="ResultsShareTiming"' + ((s.results_share || 'none') === 'none' ? ' disabled' : ''),
                'The survey ends when you close it or its closing date passes, whichever comes first. Ongoing shared results update in batches of at least 5 responses. Your own results are always live.');
        }
        html += section('privacy', 'Privacy', 'fa-user-shield', body);

        /* Attendance credit (sharing-and-credits spec §3.6). The card only opens
           the shared modal; the owner acts for its own org, and an ORK survey's
           owner just reads what kingdoms and parks have turned on. */
        body  = '<p class="svb-hint">Give respondents who choose Any ORK Data an attendance credit — at their home park, or at a generated “Survey Credit” event. Once on, it can’t be turned off.</p>';
        body += '<button type="button" class="sv-btn" id="svb-credit-open"><i class="fas fa-award" aria-hidden="true"></i> ' +
                '<span id="svb-credit-label">' + esc(creditButtonLabel()) + '</span></button>';
        html += section('credit', 'Attendance credit', 'fa-award', body);

        /* Promotion */
        body  = checkRow('Promote with a site banner', truthy(s.show_banner), 'data-sv-field="ShowBanner"', false,
                         'Shows a dismissible strip at the top of every page for everyone in scope.');
        body += fieldRow('<div class="svb-sharelink">' +
                         '<input type="text" class="sv-input" id="svb-f-share" readonly value="' + esc(shareLink()) + '">' +
                         '<button type="button" class="sv-btn" data-act="share-copy" data-tip="Copy the share link" aria-label="Copy the share link">' +
                         '<i class="fas fa-link" aria-hidden="true"></i></button></div>',
                         'Share link', null, 'svb-f-share');
        html += section('promotion', 'Promotion', 'fa-bullhorn', body);

        /* Experience */
        body  = checkRow('Show a progress bar', truthy(s.show_progress), 'data-sv-field="ShowProgress"', false);
        body += checkRow('Let respondents resume a part-finished survey', truthy(s.allow_resume), 'data-sv-field="AllowResume"', false);
        body += fieldRow('<div class="svb-color"><input type="color" class="svb-color-input" id="svb-f-accent" data-sv-field="AccentColor" value="' +
                         esc(s.accent_color || '#2c5282') + '">' +
                         '<button type="button" class="sv-btn" data-act="accent-clear">Use the ORK default</button></div>',
                         'Accent colour', null, 'svb-f-accent');
        html += section('experience', 'Experience', 'fa-wand-magic-sparkles', body);

        /* About This Tool — the standard sidebar closer on every rp-* page.
           It folds like every section above it: under 900px the sidebar sits
           ON TOP of the canvas, and ~250px of static prose between the
           settings and the first question is 250px of scrolling before a
           builder reaches their own survey. Open by default on desktop,
           collapsed by default once it is in the way. */
        body  = '<p>Click any card on the canvas to edit it where it sits. <strong>Add Element</strong> starts a new question; its Type menu turns it into any of the twelve question types, a section or an image.</p>' +
                '<p>Every change saves itself — the pill beside the header actions says when.</p>' +
                '<p>Opening a survey <strong>locks its structure</strong>: questions, options and pages stop moving so the answers stay comparable. Wording stays editable for good.</p>' +
                '<p><button type="button" class="svb-link" data-act="help">Open the full guide</button></p>';
        html += section('about', 'About This Tool', 'fa-book-open', body, 'rp-about-body');

        destroySchedulePickers();
        box.innerHTML = html;
        initSchedulePickers();
        if (eventsFailed) { fillEventPicker(); }
        syncMarks();
    }

    /**
     * The three consent options exactly as the runner words them for this
     * survey. {scope} is the scope's name; a name that already starts with
     * "The" ("The Kingdom of …") replaces the copy's own "The" instead of
     * doubling it.
     */
    function consentOptions(s) {
        // Until `scopes` lands there is no real name: read as the runner does
        // with no label ("The survey's officers…"), not "The Kingdom officers…".
        var name = scopes === null ? 'survey\'s' : scopeName(s);
        var full = String(s.scope_type) === 'ork'
            ? CONSENT_COPY.fullOrk
            : managerLabel
            ? CONSENT_COPY.fullChain.replace('{managers}', managerLabel)
            : (/^the\s/i.test(name)
                ? CONSENT_COPY.full.replace('The {scope}', name)
                : CONSENT_COPY.full.replace('{scope}', name));
        return [
            ['Any ORK Data', full],
            ['My Kingdom and How Long I\'ve Been Playing', CONSENT_COPY.partial],
            ['Anonymous Only', CONSENT_COPY.anon]
        ];
    }

    /* The consent wording is fixed in the runner (survey-take.js) and repeated
       here read-only, so a builder can see exactly what they are asking. */
    function consentQuote(s) {
        var opts = consentOptions(s), i, html;
        html = '<div class="svb-consent-quote"><span class="svb-label">Before the first question, respondents read</span>' +
               '<p class="svb-consent-lead">“' + esc(CONSENT_COPY.notice) + '”</p>' +
               '<span class="svb-label">At the end they are asked</span>' +
               '<p class="svb-consent-lead">' + esc(CONSENT_COPY.intro) + '</p><ul>';
        for (i = 0; i < opts.length; i++) {
            html += '<li><strong>' + esc(opts[i][0]) + '</strong> — ' + esc(opts[i][1]) + '</li>';
        }
        /* The credit line is conditional, so it gets its own label like the two
           parts above rather than sitting under the last option as if it were
           part of it. */
        return html + '</ul><span class="svb-label svb-consent-credit-label">Shown when this survey gives the respondent a credit</span>' +
               '<p class="svb-consent-lead">' + esc(CONSENT_COPY.credit) + '</p>' +
               '<span class="svb-label svb-consent-credit-label">Shown otherwise (only respondents told about credits are ever given one)</span>' +
               '<p class="svb-consent-lead">' + esc(CONSENT_COPY.creditMaybe) + '</p></div>';
    }

    function scopeIcon(type) {
        if (String(type) === 'park') { return 'fa-campground'; }
        if (String(type) === 'ork') { return 'fa-globe'; }
        return 'fa-crown';
    }

    /** The scope's real name once `scopes` has landed, its type word until then. */
    function scopeName(s) {
        var i, sc;
        for (i = 0; i < (scopes || []).length; i++) {
            sc = scopes[i];
            if (sc.scope_type === s.scope_type && parseInt(sc.scope_id, 10) === parseInt(s.scope_id, 10)) {
                return sc.name;
            }
        }
        if (String(s.scope_type) === 'park') { return 'Park'; }
        if (String(s.scope_type) === 'ork') { return 'All of Amtgard'; }
        return 'Kingdom';
    }

    function shareLink() {
        var btn = $('svb-copylink');
        return btn ? (btn.getAttribute('data-link') || '') : '';
    }

    function kingdomPicker(s, list) {
        var chosen = {};
        var raw = s.audience_kingdom_ids;
        var html = '', i;
        if (typeof raw === 'string' && raw !== '') {
            try { raw = JSON.parse(raw); } catch (e) { raw = []; }
        }
        if (Array.isArray(raw)) { raw.forEach(function (id) { chosen[parseInt(id, 10)] = true; }); }

        if (!list.length) { return '<p class="svb-hint">Loading kingdoms…</p>'; }
        html += '<select class="sv-select svb-multi" id="svb-f-kingdoms" multiple size="8" data-sv-field="AudienceKingdomIds">';
        for (i = 0; i < list.length; i++) {
            html += '<option value="' + parseInt(list[i].scope_id, 10) + '"' +
                    (chosen[parseInt(list[i].scope_id, 10)] ? ' selected' : '') + '>' + esc(list[i].name) + '</option>';
        }
        html += '</select>';
        return html;
    }

    /**
     * The "attended an event" audience picker. The empty option means no
     * event rule (anyone in scope); the list is SurveyAjax/event_options,
     * which always includes the saved occurrence even when it is old.
     */
    function eventPickerHtml(s) {
        var cur  = parseInt(s.audience_event_calendardetail_id, 10) || 0;
        var html = '<select class="sv-select" id="svb-f-event" data-sv-field="AudienceEventCalendardetailId"' +
                   (events === null && !eventsFailed ? ' disabled' : '') + '>';
        html += eventOptionsHtml(cur);
        return html + '</select>';
    }

    function eventOptionsHtml(cur) {
        var html = '<option value=""' + (cur ? '' : ' selected') + '>Any player in scope</option>';
        var seen = false;
        if (events === null) {
            return html + (cur ? '<option value="' + cur + '" selected>' +
                   (eventsFailed ? 'The saved event (the list could not load)' : 'Loading events…') + '</option>' : '');
        }
        events.forEach(function (ev) {
            var id = parseInt(ev.event_calendardetail_id, 10) || 0;
            if (!id) { return; }
            if (id === cur) { seen = true; }
            html += '<option value="' + id + '"' + (id === cur ? ' selected' : '') + '>' + esc(ev.label) + '</option>';
        });
        if (cur && !seen) { html += '<option value="' + cur + '" selected>The saved event</option>'; }
        return html;
    }

    /** Fill the picker in place once the list lands — never a sidebar repaint. */
    function fillEventPicker() {
        var node = $('svb-f-event');
        var cur  = parseInt((S.survey || {}).audience_event_calendardetail_id, 10) || 0;
        if (!node) { return; }
        if (document.activeElement === node) { return; }
        node.innerHTML = eventOptionsHtml(cur);
        node.disabled  = events === null && !eventsFailed;
        fieldError(node, eventsFailed ? 'The event list could not load. Reload the page to choose an event.' : '', true);
    }

    function loadEvents() {
        function failed() {
            events = null;
            eventsFailed = true;
            fillEventPicker();
        }
        post('event_options', { SurveyId: SURVEY_ID }, function (data) {
            events = data.events || [];
            eventsFailed = false;
            fillEventPicker();
        }, failed).then(function () {
            // A network error, an expired token or session resolve without
            // reaching onFail — the picker must not sit on "Loading events…".
            if (events === null && !eventsFailed) { failed(); }
        });
    }

    /* --------------------------------------------------------- date helpers */

    /* A raw <input type="datetime-local"> shows "2026-09-12T18:00", which is
       not how this project writes a date to a human (see the Flatpickr
       altInput/altFormat pairing every other date field in the app uses).
       Flatpickr paints a readable twin over the real input; what is saved is
       the picked instant (dateFieldValue), never the viewer-local string. */

    var FP_SQL    = 'Y-m-d H:i:S';
    var FP_PRETTY = 'F j, Y \\a\\t h:i K';
    var schedFps  = [];

    /** The real (Flatpickr-backed) datetime input for a schedule field, plus
        the clear button that replaces the native datetime-local one. ts is the
        stored wall time as an instant (open_ts / close_ts): the picker is
        seeded from it so it shows the viewer's own clock, like the runner. */
    function dateField(id, field, value, placeholder, ts) {
        return '<div class="svb-daterow">' +
               '<input type="text" class="sv-input svb-date" id="' + id + '"' +
               ' data-sv-field="' + field + '" autocomplete="off"' +
               (typeof ts === 'number' && isFinite(ts) ? ' data-ts="' + ts + '"' : '') +
               ' placeholder="' + esc(placeholder) + '"' +
               ' value="' + esc(value ? String(value) : '') + '">' +
               '<button type="button" class="svb-link svb-date-clear" data-act="date-clear"' +
               ' data-field="' + field + '"' +
               (value ? '' : ' disabled') + '>' +
               '<i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>' +
               '</div>';
    }

    /** Enable / disable the Clear button beside a schedule input. */
    function dateClearSync(input, hasValue) {
        var row = input && input.closest ? input.closest('.svb-daterow') : null;
        var clr = row ? row.querySelector('.svb-date-clear') : null;
        if (clr) { clr.disabled = !hasValue; }
    }

    /** What a schedule input sends: the picked instant (epoch seconds), '' when
        empty; the raw string only without Flatpickr (the server takes both). */
    function dateFieldValue(node) {
        var fp = node._flatpickr, d;
        if (!fp) { return node.value; }
        d = fp.selectedDates && fp.selectedDates[0];
        return d ? String(Math.floor(d.getTime() / 1000)) : '';
    }

    /* Flatpickr hangs its calendar off document.body, so a sidebar repaint
       that only replaced innerHTML would leak one calendar per render. */
    function destroySchedulePickers() {
        var i;
        for (i = 0; i < schedFps.length; i++) {
            try { schedFps[i].destroy(); } catch (e) { /* already gone */ }
        }
        schedFps = [];
    }

    function initSchedulePickers() {
        var ids = ['svb-f-openat', 'svb-f-closeat'], i, node, fp, ts;
        if (typeof window.flatpickr !== 'function') { return; }
        for (i = 0; i < ids.length; i++) {
            node = $(ids[i]);
            if (!node) { continue; }
            ts = parseInt(node.getAttribute('data-ts') || '', 10);
            fp = window.flatpickr(node, {
                /* The instant, not the server's zone-less string: Flatpickr
                   would read that as the viewer's local time. */
                defaultDate:   isFinite(ts) ? new Date(ts * 1000) : null,
                enableTime:    true,
                dateFormat:    FP_SQL,
                altInput:      true,
                altFormat:     FP_PRETTY,
                altInputClass: 'sv-input svb-date svb-date-alt',
                time_24hr:     false,
                allowInput:    false,
                /* The calendar hangs off <body>, outside this module's markup.
                   The class is how survey-build.css reaches it to give the day
                   cells and the month arrows a 44px tap target on touch without
                   resizing every other ORK date field on the site. */
                onReady: function (dates, str, inst) {
                    if (inst.calendarContainer) { inst.calendarContainer.classList.add('svb-fp'); }
                },
                /* The Clear button follows the value as it changes, not on
                   the next sidebar repaint. */
                onChange: function (dates, str, inst) {
                    dateClearSync(inst.input, !!dates.length);
                }
            });
            /* Flatpickr copies the placeholder onto the alt input at build
               time only, so restate it for the empty state. */
            if (fp.altInput) { fp.altInput.placeholder = node.placeholder; }
            schedFps.push(fp);
        }
    }

    /* ------------------------------------------------------------- markdown */

    function mdCommand(cmd, area) {
        var start = area.selectionStart, end = area.selectionEnd;
        var text  = area.value;
        var picked = text.slice(start, end);
        var before, after, insert, caret;

        switch (cmd) {
            case 'bold':   insert = '**' + (picked || 'bold text') + '**'; break;
            case 'italic': insert = '_' + (picked || 'italic text') + '_'; break;
            case 'list':
                insert = (picked || 'First item').split('\n').map(function (line) {
                    return line.replace(/^(\s*[-*]\s*)?/, '- ');
                }).join('\n');
                break;
            case 'link':   insert = '[' + (picked || 'link text') + '](https://)'; break;
            default:       return;
        }

        before = text.slice(0, start);
        after  = text.slice(end);
        area.value = before + insert + after;
        caret = before.length + insert.length;
        area.focus();
        area.setSelectionRange(caret, caret);
        fire(area, 'input');
    }

    function insertAtCursor(area, snippet) {
        var start = area.selectionStart, end = area.selectionEnd;
        var text = area.value;
        area.value = text.slice(0, start) + snippet + text.slice(end);
        area.focus();
        area.setSelectionRange(start + snippet.length, start + snippet.length);
        fire(area, 'input');
    }

    function fire(node, type) {
        var ev;
        try {
            ev = new Event(type, { bubbles: true });
        } catch (e) {
            ev = document.createEvent('Event');
            ev.initEvent(type, true, false);
        }
        node.dispatchEvent(ev);
    }

    /* The preview draws with the respondent's renderer (Survey::renderMarkdown
       via SurveyAjax/preview_md), not marked, so what the author sees is what
       the runner shows. Debounced per editor; only the latest request for an
       editor may paint, and the box keeps its current content while waiting
       (or when a request fails). */
    var mdServerCache = {};  // markdown source -> server HTML
    var mdPreviewSeq  = {};  // editor id -> sequence number of its latest request
    var mdPreviewTimer = {}; // editor id -> debounce timer

    function mdPreviewHtml(src) {
        src = (src === null || src === undefined) ? '' : String(src);
        return Object.prototype.hasOwnProperty.call(mdServerCache, src) ? mdServerCache[src] : mdHtml(src);
    }

    function fetchMdPreview(id) {
        var area = $(id), box = el('[data-md-preview="' + id + '"]');
        var src, seq, body;
        if (!area || !box) { return; }
        src = String(area.value || '');
        seq = mdPreviewSeq[id] = (mdPreviewSeq[id] || 0) + 1;
        if (src === '' || Object.prototype.hasOwnProperty.call(mdServerCache, src)) {
            box.innerHTML = src === '' ? '' : mdServerCache[src];
            return;
        }
        body = new window.FormData();
        body.append('Md', src);
        window.fetch(UIR + 'SurveyAjax/preview_md', {
            method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF }
        }).then(function (r) { return r.json(); }).then(function (data) {
            var b;
            if (!data || parseInt(data.status, 10) !== 0) { return; }
            mdServerCache[src] = String(data.html || '');
            if (mdPreviewSeq[id] !== seq) { return; }  // a newer request owns this preview
            b = el('[data-md-preview="' + id + '"]');
            if (b) { b.innerHTML = mdServerCache[src]; }
        })['catch'](function () { /* keep what the preview shows now */ });
    }

    function refreshMdPreview(area) {
        var id = area.id;
        window.clearTimeout(mdPreviewTimer[id]);
        mdPreviewTimer[id] = window.setTimeout(function () { fetchMdPreview(id); }, 350);
    }

    function autoGrow(area) {
        if (!area) { return; }
        area.style.height = 'auto';
        area.style.height = (area.scrollHeight + 2) + 'px';
    }

    /* ------------------------------------------------------------- settings */

    /** Rebuild the whole settings object for a question from its card and save it.
        `node` is the control that changed: a refusal is shown beside it. */
    function saveSettings(q, node) {
        var defs = FIELD_DEFS[q.type] || [];
        var card = cardEl(q.question_id);
        var out  = {};

        defs.forEach(function (def) {
            var node = card ? el('[data-q-setting="' + def.key + '"]', card) : null;
            var raw;
            if (!node) {
                out[def.key] = (q.settings && Object.prototype.hasOwnProperty.call(q.settings, def.key))
                    ? q.settings[def.key] : def.def;
                return;
            }
            switch (def.kind) {
                case 'bool':
                    out[def.key] = !!node.checked;
                    break;
                case 'int':
                    raw = String(node.value).trim();
                    out[def.key] = raw === '' ? def.def : Math.round(num(raw, def.def));
                    break;
                case 'select':
                    out[def.key] = def.options && /^\d+$/.test(String(node.value))
                        ? Math.round(num(node.value, def.def)) : String(node.value);
                    break;
                case 'number':
                    raw = String(node.value).trim();
                    out[def.key] = raw === '' ? '' : num(raw, def.def === null ? '' : def.def);
                    break;
                default:
                    out[def.key] = String(node.value);
            }
        });

        q.settings = out;
        save('qset:' + q.question_id, 'question_update', {
            QuestionId: q.question_id,
            Settings:   JSON.stringify(out)
        }, function (data) {
            // By id, not the captured q: a re-sync may have replaced the object.
            var fresh = data.question, cur = fresh ? questionById(fresh.question_id) : null;
            if (cur) { cur.settings = fresh.settings; }
        }, { node: node });
    }

    /* --------------------------------------------------------------- images */

    function startUpload(job) {
        var input = $('svb-file');
        if (!input) { return; }
        fileJob = job;
        input.value = '';
        input.click();
    }

    /* The server takes 2 MB (Survey::IMAGE_MAX_BYTES, also PHP's
       upload_max_filesize); aim a little under it. */
    var IMAGE_UPLOAD_MAX = 2097152 - 65536;

    function onFileChosen() {
        var input = $('svb-file');
        var file, isPng, job = fileJob;
        if (!input || !input.files || !input.files.length || !job) { return; }
        file = input.files[0];
        fileJob = null;

        function send(blob, name) {
            var fd = new window.FormData();
            fd.append('SurveyId', SURVEY_ID);
            if (name) { fd.append('Image', blob, name); } else { fd.append('Image', blob); }
            post('image_upload', fd, function (data) {
                S.images.push({ image_id: data.image_id, url: data.url, width: data.width, height: data.height });
                applyUpload(job, data);
            }, function (data) {
                notice((data && data.error) || 'That image could not be uploaded.', 'error');
            });
        }

        /* Too big to upload: shrink it first with the helper heraldry and
           player photos use (orkui.js), rather than refuse it. */
        if (file.size > IMAGE_UPLOAD_MAX && typeof window.resizeImageToLimit === 'function') {
            isPng = file.type === 'image/png';
            notice('Resizing the image to fit\u2026');
            window.resizeImageToLimit(file, IMAGE_UPLOAD_MAX, function (blob) {
                notice('');
                send(blob, String(file.name || 'image').replace(/\.[^.]*$/, '') + (isPng ? '.png' : '.jpg'));
            }, function (msg) {
                notice(msg || 'That image could not be resized.', 'error');
            }, isPng);
            return;
        }
        send(file);
    }

    function applyUpload(job, data) {
        var q, area;
        if (job === 'question-image') {
            q = questionById(sel);
            if (!q) { return; }
            q.image_id = data.image_id;
            post('question_update', { QuestionId: q.question_id, ImageId: data.image_id }, function () {
                refreshCard(q.question_id);
            });
        } else if (job === 'survey-welcome' || job === 'survey-thanks') {
            post('update', job === 'survey-welcome'
                ? { SurveyId: SURVEY_ID, WelcomeImageId: data.image_id }
                : { SurveyId: SURVEY_ID, ThanksImageId: data.image_id }, function (r) {
                S.survey = r.survey || S.survey;
                renderSettings();
                refreshImagePicker(job);
            });
        } else if (job.indexOf('md:') === 0) {
            area = $(job.slice(3));
            if (area) { insertAtCursor(area, '\n![](' + data.url + ')\n'); }
        }
    }

    /* renderSettings() leaves the sidebar alone while it holds focus, and it
       does right after Upload / Remove is clicked. Swap just this image slot
       (thumbnail + Remove) so the change shows now; focus moves to the slot's
       Upload / Replace button since the one clicked may be gone. */
    function refreshImagePicker(job) {
        var s = S.survey || {};
        var btn = el('#svb-settings [data-upload="' + job + '"]');
        var field = btn ? btn.closest('.svb-field') : null;
        var hadFocus, wrap, next;
        if (!field) { return; }
        hadFocus = field.contains(document.activeElement);
        wrap = document.createElement('div');
        wrap.innerHTML = job === 'survey-welcome'
            ? imagePicker('Welcome image', s.welcome_image_id, 'survey-welcome')
            : imagePicker('Thank-you image', s.thanks_image_id, 'survey-thanks');
        next = wrap.firstChild;
        field.parentNode.replaceChild(next, field);
        if (hadFocus) {
            btn = next.querySelector('[data-upload]');
            if (btn) { btn.focus(); }
        }
    }

    function clearImage(job) {
        var q;
        if (job === 'question-image') {
            q = questionById(sel);
            if (!q) { return; }
            q.image_id = null;
            post('question_update', { QuestionId: q.question_id, ImageId: 0 }, function () {
                refreshCard(q.question_id);
            });
        } else if (job === 'survey-welcome' || job === 'survey-thanks') {
            post('update', job === 'survey-welcome'
                ? { SurveyId: SURVEY_ID, WelcomeImageId: 0 }
                : { SurveyId: SURVEY_ID, ThanksImageId: 0 }, function (r) {
                S.survey = r.survey || S.survey;
                renderSettings();
                refreshImagePicker(job);
            });
        }
    }

    /* ------------------------------------------------------- confirm strip */

    /* The strip is a sticky role="alertdialog" (Survey_build.tpl) labelled by
       its own text, so it is announced with its question and stays in view
       wherever the author is scrolled. It never scrolls the page to itself
       (preventScroll), and every way out hands the keyboard back: Cancel and
       Escape to whatever opened it, Confirm to the opener too unless the
       action moves focus somewhere better (a delete focuses the neighbour). */

    var confirmAction = null;
    var confirmOpener = null;
    var confirmCancel = null;

    function confirmOpen() {
        var bar = $('svb-confirm');
        return !!(bar && !bar.hidden);
    }

    /**
     * opts.onCancel runs on Cancel / Escape (e.g. put the type picker back).
     * opts.opener is where focus goes back to when the focused element is not
     * the opener — a strip raised from a blur, where activeElement is <body>.
     * It may be a function, resolved on close, so a repainted field is found.
     */
    function askConfirm(text, buttonLabel, danger, action, opts) {
        var bar  = $('svb-confirm');
        var copy = $('svb-confirm-text');
        var yes  = $('svb-confirm-yes');
        if (!bar) { return; }
        if (confirmOpen() && confirmCancel) { confirmCancel(); }
        confirmAction = action;
        confirmCancel = (opts && opts.onCancel) || null;
        if (!confirmOpen()) { confirmOpener = (opts && opts.opener) || document.activeElement; }
        copy.textContent = text;
        yes.textContent = buttonLabel;
        yes.className = 'sv-btn ' + (danger ? 'svb-danger' : 'sv-btn-primary');
        bar.hidden = false;
        yes.focus({ preventScroll: true });
    }

    /** Close the strip. cancelled = Cancel / Escape: undo and restore focus. */
    function hideConfirm(cancelled) {
        var bar    = $('svb-confirm');
        var opener = confirmOpener;
        var cancel = confirmCancel;
        confirmAction = null;
        confirmOpener = null;
        confirmCancel = null;
        if (bar) { bar.hidden = true; }
        if (cancelled && cancel) { cancel(); }
        if (typeof opener === 'function') { opener = opener(); }
        if (opener && opener.focus && document.contains(opener) && opener !== document.body) {
            opener.focus();
        }
    }

    /* --------------------------------------------------------------- modal */

    function openModal(title, html) {
        var m = $('svb-modal');
        if (!m) { return; }
        if (m.hidden) { modalOpener = document.activeElement; }
        el('.svb-modal-title', m).textContent = title;
        el('.svb-modal-body', m).innerHTML = html;
        m.hidden = false;
        el('.svb-modal-close', m).focus();
    }

    function closeModal() {
        var m = $('svb-modal');
        if (m) { m.hidden = true; }
        restoreFocus('modal');
    }

    /* ------------------------------------------------- dialog focus handling */

    /**
     * aria-modal="true" is only true if Tab cannot walk out of the panel, and a
     * dialog that closes must hand the keyboard back to whatever opened it.
     */
    function restoreFocus() {
        var node = modalOpener;
        modalOpener = null;
        if (node && node.focus && document.contains(node)) { node.focus(); }
    }

    function focusablesIn(root) {
        return els('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]),' +
                   ' textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', root)
            .filter(function (n) { return n.offsetParent !== null; });
    }

    /** The open dialog panel, if one is open. */
    function openPanel() {
        var m = $('svb-modal');
        if (m && !m.hidden) { return el('.svb-modal-panel', m); }
        return null;
    }

    function trapTab(e, panel) {
        var list = focusablesIn(panel);
        var first, last, at;
        if (!list.length) { e.preventDefault(); return; }
        first = list[0];
        last  = list[list.length - 1];
        at    = document.activeElement;
        if (!panel.contains(at)) {
            e.preventDefault();
            (e.shiftKey ? last : first).focus();
            return;
        }
        if (e.shiftKey && at === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && at === last) { e.preventDefault(); first.focus(); }
    }

    /* ------------------------------------------------------------ sortables */

    function wireSortables() {
        var canvas = $('svb-canvas');
        if (!window.Sortable || !canvas) { return; }

        sortables.forEach(function (s) { try { s.destroy(); } catch (e) { /* gone already */ } });
        sortables = [];
        if (S.locked) { return; }

        els('.svb-items', canvas).forEach(function (list) {
            sortables.push(window.Sortable.create(list, {
                group:      'sv-questions',
                handle:     '.svb-handle',
                draggable:  '.svb-item',
                animation:  140,
                ghostClass: 'svb-ghost',
                onEnd:      onQuestionDrop
            }));
        });

        sortables.push(window.Sortable.create(canvas, {
            handle:     '.svb-page-handle',
            draggable:  '.svb-page',
            animation:  140,
            ghostClass: 'svb-ghost',
            onEnd:      onPageDrop
        }));
    }

    /** Option rows inside the selected card drag too (spec §7 "on option rows"). */
    function wireOptionSortables() {
        var card = sel ? cardEl(sel) : null;

        optSorts.forEach(function (s) { try { s.destroy(); } catch (e) { /* gone already */ } });
        optSorts = [];
        if (!window.Sortable || !card || S.locked) { return; }

        els('.svb-opts', card).forEach(function (wrap) {
            var host = el('.svb-optlist', wrap);
            if (!host || wrap.getAttribute('data-fixed') === '1') { return; }
            optSorts.push(window.Sortable.create(host, {
                handle:     '.svb-opthandle',
                draggable:  '.svb-optrow',
                animation:  120,
                ghostClass: 'svb-ghost',
                onEnd:      function () { commitOptions(sel, wrap.getAttribute('data-role')); }
            }));
        });
    }

    function onQuestionDrop(evt) {
        var toPage   = parseInt(evt.to.getAttribute('data-page'), 10);
        var fromPage = parseInt(evt.from.getAttribute('data-page'), 10);
        var qid      = parseInt(evt.item.getAttribute('data-qid'), 10);
        var ids      = els('.svb-item', evt.to).map(function (n) {
            return parseInt(n.getAttribute('data-qid'), 10);
        });

        if (toPage === fromPage) {
            reorderLocal(toPage, ids);
            renderCanvas();
            post('question_reorder', { PageId: toPage, QuestionIds: JSON.stringify(ids) }, null);
            return;
        }

        // Index from the DOM, not evt.newIndex: the list also holds the between-card
        // add buttons, which would shift Sortable's own count. questionMove renumbers
        // the target page itself, so one call is enough. The move is applied
        // locally at once; a refusal falls back to the full re-sync.
        moveLocal(qid, toPage, ids);
        renderCanvas();
        post('question_move', {
            QuestionId: qid,
            PageId:     toPage,
            Index:      Math.max(0, ids.indexOf(qid))
        }, null);
    }

    /** Put a question on another page, in the given order of that page's ids. */
    function moveLocal(questionId, pageId, orderedIds) {
        var q = questionById(questionId);
        if (!q) { return; }
        q.page_id = pageId;
        reorderLocal(pageId, orderedIds);
    }

    function reorderLocal(pageId, ids) {
        var rest = [], mine = {}, ordered = [], i, q;
        for (i = 0; i < S.questions.length; i++) {
            q = S.questions[i];
            if (parseInt(q.page_id, 10) === pageId) { mine[parseInt(q.question_id, 10)] = q; }
            else { rest.push(q); }
        }
        ids.forEach(function (id) { if (mine[id]) { ordered.push(mine[id]); } });
        S.questions = rest.concat(ordered);
    }

    function onPageDrop() {
        var ids = els('.svb-page', $('svb-canvas')).map(function (n) {
            return parseInt(n.getAttribute('data-page'), 10);
        });
        var byId = {}, i;
        for (i = 0; i < S.pages.length; i++) { byId[parseInt(S.pages[i].page_id, 10)] = S.pages[i]; }
        S.pages = ids.map(function (id) { return byId[id]; }).filter(Boolean);
        renderCanvas();
        post('page_reorder', { SurveyId: SURVEY_ID, PageIds: JSON.stringify(ids) }, null);
    }

    /* -------------------------------------------------- keyboard reordering */

    /**
     * The keyboard equivalent of a question drag. Within a page it is a swap;
     * at the top or bottom of a page it hops to the neighbouring page, which is
     * what dragging across the gap does.
     */
    function moveQuestion(questionId, dir) {
        var q = questionById(questionId);
        var list, idx, pageIdx, target, ids, i;
        if (!q || S.locked) { return; }

        list    = questionsOfPage(q.page_id);
        pageIdx = -1;
        idx     = -1;
        for (i = 0; i < list.length; i++) {
            if (parseInt(list[i].question_id, 10) === parseInt(questionId, 10)) { idx = i; }
        }
        for (i = 0; i < S.pages.length; i++) {
            if (parseInt(S.pages[i].page_id, 10) === parseInt(q.page_id, 10)) { pageIdx = i; }
        }
        if (idx < 0) { return; }

        if (idx + dir >= 0 && idx + dir < list.length) {
            list.splice(idx + dir, 0, list.splice(idx, 1)[0]);
            ids = list.map(function (n) { return parseInt(n.question_id, 10); });
            reorderLocal(parseInt(q.page_id, 10), ids);
            if (!stepCardInPlace(questionId, dir)) { renderCanvas(); }
            focusMove(questionId, dir);
            post('question_reorder', { PageId: parseInt(q.page_id, 10), QuestionIds: JSON.stringify(ids) }, null);
            return;
        }

        target = S.pages[pageIdx + dir];
        if (!target) { return; }
        ids = questionsOfPage(target.page_id).map(function (n) { return parseInt(n.question_id, 10); });
        post('question_move', {
            QuestionId: questionId,
            PageId:     parseInt(target.page_id, 10),
            Index:      dir < 0 ? ids.length : 0
        }, null);
        // Applied locally at once (the domain renumbers the same way); a
        // refusal falls back to the full re-sync.
        if (dir < 0) { ids.push(parseInt(questionId, 10)); } else { ids.unshift(parseInt(questionId, 10)); }
        moveLocal(questionId, parseInt(target.page_id, 10), ids);
        renderCanvas();
        focusMove(questionId, dir);
    }

    /**
     * One step up or down, done by moving the card's own nodes instead of
     * rebuilding the canvas. On touch ▲/▼ IS the reorder affordance, and a full
     * renderCanvas() threw away and rebuilt every page, every card and every
     * live preview control in the survey for one tap.
     *
     * The canvas lays each element out as [card][its "+ add here" marker], and
     * the marker is keyed to the card it follows, so the two travel together.
     *
     * Returns false — take the full repaint — when a card is open: the inline
     * editor's "show if" list is built from positions in the survey, so moving
     * anything can change what it may offer.
     */
    function stepCardInPlace(questionId, dir) {
        var card = cardEl(questionId);
        var mark, host, sibCard, sibMark;
        if (sel || !card) { return false; }

        mark = card.nextElementSibling;
        if (!mark || !mark.classList.contains('svb-addinline')) { return false; }
        host = card.parentNode;

        if (dir < 0) {
            sibMark = card.previousElementSibling;
            sibCard = sibMark ? sibMark.previousElementSibling : null;
        } else {
            sibCard = mark.nextElementSibling;
            sibMark = sibCard ? sibCard.nextElementSibling : null;
        }
        if (!sibCard || !sibCard.classList.contains('svb-item') ||
                !sibMark || !sibMark.classList.contains('svb-addinline')) { return false; }

        // Lift the OTHER pair over this one; this card keeps its DOM node, so
        // focus, scroll position and its preview controls all survive.
        if (dir < 0) {
            host.insertBefore(sibCard, mark.nextSibling);
            host.insertBefore(sibMark, sibCard.nextSibling);
        } else {
            host.insertBefore(sibCard, card);
            host.insertBefore(sibMark, card);
        }

        refreshMoveStates();
        renderToc();
        return true;
    }

    /** After an in-place step: only the two ends of the survey disable ▲/▼. */
    function refreshMoveStates() {
        var canvas = $('svb-canvas');
        var total  = orderedQuestions().length;
        if (!canvas) { return; }
        els('.svb-item', canvas).forEach(function (node) {
            var pos  = questionIndex(parseInt(node.getAttribute('data-qid'), 10));
            var up   = el('[data-act="q-up"]', node);
            var down = el('[data-act="q-down"]', node);
            if (up)   { up.disabled   = !!S.locked || pos === 0; }
            if (down) { down.disabled = !!S.locked || pos >= total - 1; }
        });
    }

    /** Keep the keyboard on the control the author just used. */
    function focusMove(questionId, dir) {
        var card = cardEl(questionId);
        var btn  = card ? el('[data-act="' + (dir < 0 ? 'q-up' : 'q-down') + '"]', card) : null;
        if (!card) { return; }
        if (btn && !btn.disabled) { btn.focus(); } else { card.focus(); }
        if (card.scrollIntoView) { card.scrollIntoView({ block: 'nearest' }); }
    }

    function movePage(pageId, dir) {
        var i, at = -1, swap, ids, btn, head;
        if (S.locked) { return; }
        for (i = 0; i < S.pages.length; i++) {
            if (parseInt(S.pages[i].page_id, 10) === parseInt(pageId, 10)) { at = i; }
        }
        if (at < 0 || at + dir < 0 || at + dir >= S.pages.length) { return; }

        swap = S.pages[at];
        S.pages[at] = S.pages[at + dir];
        S.pages[at + dir] = swap;
        ids = S.pages.map(function (p) { return parseInt(p.page_id, 10); });
        renderCanvas();

        head = el('.svb-page[data-page="' + parseInt(pageId, 10) + '"]');
        btn  = head ? el('[data-act="' + (dir < 0 ? 'page-up' : 'page-down') + '"]', head) : null;
        if (btn && !btn.disabled) { btn.focus(); }
        if (head && head.scrollIntoView) { head.scrollIntoView({ block: 'nearest' }); }

        post('page_reorder', { SurveyId: SURVEY_ID, PageIds: JSON.stringify(ids) }, null);
    }

    /** Keyboard option reorder — the same commit the option drag makes. */
    function moveOptionRow(q, wrap, row, dir) {
        var host = row.parentNode;
        var sib  = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
        var btn;
        if (S.locked || !sib || !sib.classList.contains('svb-optrow')) { return; }
        if (dir < 0) { host.insertBefore(row, sib); } else { host.insertBefore(sib, row); }
        refreshOptionRowStates(wrap);
        commitOptions(q.question_id, wrap.getAttribute('data-role'));
        btn = el('[data-act="' + (dir < 0 ? 'opt-up' : 'opt-down') + '"]', row);
        if (btn && !btn.disabled) { btn.focus(); }
    }

    /* ----------------------------------------------------------- structure */

    /* Every structural action below applies the rows its reply carries to S
       and repaints the canvas locally — no SurveyAjax/get. The full re-fetch
       is only the error path (post()'s default onFail → reload()). */

    /**
     * Put a question row into S.questions right after `afterId`, or after the
     * last question of its page. S.questions order IS the in-page order.
     */
    function placeQuestion(fresh, afterId) {
        var qid = parseInt(fresh.question_id, 10);
        var pid = parseInt(fresh.page_id, 10);
        var at = -1, i;
        S.questions = S.questions.filter(function (x) { return parseInt(x.question_id, 10) !== qid; });
        for (i = 0; i < S.questions.length; i++) {
            if (afterId && parseInt(S.questions[i].question_id, 10) === parseInt(afterId, 10)) { at = i; break; }
            if (!afterId && parseInt(S.questions[i].page_id, 10) === pid) { at = i; }
        }
        if (at < 0) { S.questions.push(fresh); } else { S.questions.splice(at + 1, 0, fresh); }
    }

    /**
     * Mirror the domain's show-if cleanup: every question and page whose
     * condition reads `sourceId` lets go when `keep` says its option is gone.
     * Each one that changed is redrawn in place (never the card being edited).
     */
    function pruneShowIf(sourceId, keep) {
        var src = parseInt(sourceId, 10);
        S.questions.forEach(function (x) {
            if (parseInt(x.show_if_question_id, 10) !== src || keep(parseInt(x.show_if_option_id, 10) || 0)) { return; }
            x.show_if_question_id = null;
            x.show_if_option_id   = null;
            if (parseInt(x.question_id, 10) !== sel) { refreshCard(x.question_id); }
        });
        S.pages.forEach(function (p) {
            if (parseInt(p.show_if_question_id, 10) !== src || keep(parseInt(p.show_if_option_id, 10) || 0)) { return; }
            p.show_if_question_id = null;
            p.show_if_option_id   = null;
            refreshPageHead(p.page_id);
        });
    }

    /** Option ids a question still has, as a lookup. */
    function optionIdSet(q) {
        var set = {};
        ((q && q.options) || []).forEach(function (o) { set[parseInt(o.option_id, 10)] = true; });
        return set;
    }

    /** Questions + pages whose skip rule reads this option (or, oid 0, this question at all). */
    function showIfDependents(sourceId, test) {
        var src = parseInt(sourceId, 10), n = 0;
        S.questions.concat(S.pages).forEach(function (x) {
            if (parseInt(x.show_if_question_id, 10) === src && test(parseInt(x.show_if_option_id, 10) || 0)) { n++; }
        });
        return n;
    }

    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    function joinAnd(list) {
        if (list.length < 2) { return list.join(''); }
        return list.slice(0, -1).join(', ') + ' and ' + list[list.length - 1];
    }

    /**
     * What a retype would destroy, worked out from S exactly the way
     * Survey::retypeQuestion does it: options of a role the new type does not
     * own are deleted, Yes / No keeps and renames the first two choices, "Other"
     * turns into a plain option outside single / multi / dropdown, and every
     * skip rule that reads a deleted option — or this question at all, when the
     * new type cannot be a show-if source — is cleared. '' = nothing is lost.
     */
    function retypeLoss(q, newType) {
        var roles  = (OPTION_ROLES[newType] || []).map(function (s) { return s.role; });
        var nouns  = { choice: ['option', 'options'], row: ['row', 'rows'], column: ['column', 'columns'] };
        var lost   = {}, counts = {}, parts = [], extra = [], rules, renamed = false, otherLost = false;
        var label  = (TYPE_META[newType] || {}).label || newType;
        var keepsOther = ['single', 'multi', 'dropdown'].indexOf(newType) !== -1;

        Object.keys(nouns).forEach(function (role) {
            optionsOf(q, role).forEach(function (o, i) {
                var oid = parseInt(o.option_id, 10);
                if (roles.indexOf(role) === -1 || (newType === 'yesno' && role === 'choice' && i >= 2)) {
                    lost[oid] = true;
                    counts[role] = (counts[role] || 0) + 1;
                    return;
                }
                if (newType === 'yesno' && role === 'choice' && q.type !== 'yesno' &&
                        String(o.label).trim().toLowerCase() !== (i === 0 ? 'yes' : 'no')) {
                    renamed = true;
                }
                if (truthy(o.is_other) && !keepsOther) { otherLost = true; }
            });
        });

        Object.keys(counts).forEach(function (role) {
            parts.push(plural(counts[role], nouns[role][0], nouns[role][1]));
        });
        rules = SHOW_IF_SOURCES.indexOf(newType) === -1
            ? showIfDependents(q.question_id, function () { return true; })
            : showIfDependents(q.question_id, function (oid) { return !!lost[oid]; });
        if (rules) { parts.push(plural(rules, 'skip rule', 'skip rules')); }

        if (renamed)   { extra.push('Its first two options are renamed Yes and No.'); }
        if (otherLost) { extra.push('The “Other” write-in becomes a plain option.'); }
        if (!parts.length && !extra.length) { return ''; }

        return 'Switching to ' + label + (parts.length ? ' removes ' + joinAnd(parts) + '.' : '.') +
               (extra.length ? ' ' + extra.join(' ') : '');
    }

    /**
     * Commit the Type picker's value. Nothing happens when it still shows the
     * question's type; a retype that loses anything asks first, and Cancel
     * puts the picker back. `opener` (a node or a function returning one) is
     * where Cancel / Escape sends focus when the commit came from a blur.
     */
    var retypeAsk = null;   // {qid, type} while the strip is asking about a retype

    function commitType(selEl, opener) {
        var card = selEl.closest('.svb-item');
        var q    = card ? questionById(card.getAttribute('data-qid')) : null;
        var next = String(selEl.value);
        var loss;
        fieldError(selEl, '');
        if (!q || next === String(q.type) || S.locked) { return; }
        // The same retype already on the wire (a pick, then a blur before the
        // reply) must not go out twice.
        if (retyping[parseInt(q.question_id, 10)] === next) { return; }
        if (retypeAsk && retypeAsk.qid === parseInt(q.question_id, 10) && retypeAsk.type === next && confirmOpen()) {
            return;
        }
        loss = retypeLoss(q, next);
        if (!loss) { retype(q, next); return; }
        retypeAsk = { qid: parseInt(q.question_id, 10), type: next };
        askConfirm(loss, 'Switch type', true, function () {
            retypeAsk = null;
            retype(q, next);
        }, {
            opener: opener || null,
            onCancel: function () {
                var pick = cardEl(q.question_id) ? el('.svb-typesel', cardEl(q.question_id)) : null;
                retypeAsk = null;
                if (pick) { pick.value = String(q.type); fieldError(pick, ''); }
            }
        });
    }

    /** "+ Add Element" — a starter single-choice card, selected, prompt focused. */
    function addElement(pageId, afterQuestionId) {
        if (!pageId) { notice('This survey has no pages yet.', 'error'); return; }
        post('question_add', {
            SurveyId:        SURVEY_ID,
            PageId:          pageId,
            Type:            STARTER_TYPE,
            AfterQuestionId: afterQuestionId || ''
        }, function (data) {
            var fresh = data.question;
            if (!fresh) { reload(); return; }
            if (sel) { settleCard(sel); }
            placeQuestion(fresh, afterQuestionId || 0);
            sel = parseInt(fresh.question_id, 10);
            renderCanvas();
            focusPrompt(sel);
            scrollToSelection();
        });
    }

    /**
     * Retype in place. The domain keeps the prompt and reuses whatever options
     * fit; the reply is the retyped row, and the skip rules the domain cleared
     * are cleared here the same way. Debounced edits to this card are sent
     * first — one arriving after the retype would hit the new type.
     */
    function retype(q, newType) {
        var qid = parseInt(q.question_id, 10);
        retyping[qid] = String(newType);
        flush();
        whenIdle(function () {
            post('question_update', { QuestionId: qid, Type: newType }, function (data) {
                var fresh = data.question, now, pick;
                if (!fresh) { reload(); return; }
                replaceQuestion(fresh);
                now = questionById(qid);
                if (SHOW_IF_SOURCES.indexOf(String(newType)) === -1) {
                    pruneShowIf(qid, function () { return false; });
                } else {
                    pruneShowIf(qid, (function (ids) { return function (oid) { return !!ids[oid]; }; }(optionIdSet(now))));
                }
                dropPairwiseHold(qid);
                Object.keys(held).forEach(function (k) { if (k.indexOf('opts:' + qid + ':') === 0) { delete held[k]; } });
                renderCanvas();
                // Keep the keyboard on the picker the author just used — but
                // only if the repaint left it nowhere; an author who has
                // already moved on to another field keeps that field.
                pick = cardEl(qid) ? el('.svb-typesel', cardEl(qid)) : null;
                if (pick && (!document.activeElement || document.activeElement === document.body)) {
                    pick.focus({ preventScroll: true });
                }
            }).then(function () {
                // Settled either way (a refusal re-syncs through post()).
                delete retyping[qid];
            });
        });
    }

    function focusPrompt(questionId) {
        var card = cardEl(questionId);
        var area = card ? el('.svb-prompt', card) : null;
        if (area) { area.focus(); area.setSelectionRange(area.value.length, area.value.length); }
    }

    /**
     * Duplicate = one question_duplicate call: the domain copies the prompt,
     * help, illustration, required, settings, skip rule and every option in a
     * single transaction and replies with the copy plus the page's new order.
     * Debounced edits to the original are sent first so the copy has them.
     */
    function duplicateQuestion(q) {
        var qid = parseInt(q.question_id, 10);
        flush();
        whenIdle(function () {
            post('question_duplicate', { QuestionId: qid }, function (data) {
                var fresh = data.question, pid;
                if (!fresh) { reload(); return; }
                pid = parseInt(fresh.page_id, 10);
                placeQuestion(fresh, qid);
                if (data.order && data.order.length) {
                    reorderLocal(pid, data.order.map(function (n) { return parseInt(n, 10); }));
                }
                if (sel) { settleCard(sel); }
                sel = parseInt(fresh.question_id, 10);
                renderCanvas();
                focusPrompt(sel);
                scrollToSelection();
            });
        });
    }

    /**
     * Delete one question locally after the domain confirms, clear the skip
     * rules that read it (the domain does the same), and put the keyboard on
     * the next card, else the previous one, else the page's Add Element.
     */
    function deleteQuestion(questionId) {
        var qid   = parseInt(questionId, 10);
        var q     = questionById(qid);
        var order = orderedQuestions();
        var pid   = q ? parseInt(q.page_id, 10) : 0;
        var idx   = -1, next = 0, i;
        for (i = 0; i < order.length; i++) {
            if (parseInt(order[i].question_id, 10) === qid) { idx = i; break; }
        }
        if (idx >= 0) {
            next = order[idx + 1] ? parseInt(order[idx + 1].question_id, 10)
                                  : (order[idx - 1] ? parseInt(order[idx - 1].question_id, 10) : 0);
        }
        dropEditsFor(qid);
        post('question_delete', { QuestionId: qid }, function () {
            var card, add;
            if (sel === qid) { sel = 0; }
            S.questions = S.questions.filter(function (x) { return parseInt(x.question_id, 10) !== qid; });
            pruneShowIf(qid, function () { return false; });
            renderCanvas();
            card = next ? cardEl(next) : null;
            if (card) {
                card.focus({ preventScroll: true });
                if (card.scrollIntoView) { card.scrollIntoView({ block: 'nearest' }); }
                return;
            }
            add = el('.svb-page[data-page="' + pid + '"] .svb-addbtn') || el('.svb-addbtn');
            if (add) { add.focus({ preventScroll: true }); }
        });
    }

    /* ------------------------------------------------------------- options */

    /** The last label S has for an option (what a cleared row falls back to). */
    function savedLabel(q, role, oid) {
        var list = optionsOf(q, role), i;
        for (i = 0; i < list.length; i++) {
            if (parseInt(list[i].option_id, 10) === oid) { return String(list[i].label || ''); }
        }
        return '';
    }

    /**
     * Flag every EXISTING option row whose label has been cleared. Returns how
     * many there are (0 = nothing held). A brand-new blank row is not flagged:
     * it was never sent, holds nothing back, and goes away on blur.
     */
    function markBlankRows(wrap) {
        var n = 0;
        els('.svb-optrow', wrap).forEach(function (row) {
            var input = el('.svb-optlabel', row);
            var oid   = parseInt(row.getAttribute('data-oid'), 10) || 0;
            if (!input) { return; }
            if (oid && String(input.value || '').trim() === '') {
                n++;
                fieldError(input, 'Give this option a label to save it.');
            } else if (input.getAttribute('aria-invalid') === 'true') {
                fieldError(input, '');
            }
        });
        return n;
    }

    /**
     * Read one role's rows straight out of the selected card and replace-all.
     * The reply carries the real option ids, which are written back onto the
     * rows that were sent (never a re-render) so the caret and focus survive —
     * and so the next commit updates those options instead of recreating them.
     *
     * Blank labels never block the rest (#11). The domain deletes every option
     * missing from the payload, so:
     *   - a NEW row with no label yet is simply left out (nothing to lose);
     *   - an EXISTING row whose label was cleared is sent with its last saved
     *     label, so its id — and any answers or skip rule on it — survives;
     *     that row is flagged inline and the pill reads "Not saved" until it
     *     gets a label again.
     * Every other relabel, reorder and removal commits as usual.
     */
    function commitOptions(questionId, role, keep) {
        var card = cardEl(questionId);
        var wrap = (card ? el('.svb-opts[data-role="' + role + '"]', card) : null) || keep || null;
        var q    = questionById(questionId);
        var list = [], sent = [], other = [], key;
        if (!wrap || !q) { return; }

        els('.svb-optrow', wrap).forEach(function (row) {
            var labelEl  = el('.svb-optlabel', row);
            var weightEl = el('.svb-optweight', row);
            var oid      = parseInt(row.getAttribute('data-oid'), 10) || 0;
            var label    = labelEl ? String(labelEl.value || '').trim() : '';
            if (label === '') {
                if (!oid) { return; }
                label = savedLabel(q, role, oid);
                if (label === '') { return; }
            }
            sent.push(row);
            list.push({
                option_id: oid,
                label:     label,
                value_num: weightEl && String(weightEl.value).trim() !== '' ? Number(weightEl.value) : null,
                is_other:  row.getAttribute('data-other') === '1' ? 1 : 0
            });
        });

        key = 'opts:' + questionId + ':' + role;
        if (markBlankRows(wrap)) {
            held[key] = { msg: '', loc: locOf(wrap) };
        } else {
            delete held[key];
        }

        // S follows the card at once, so a preview drawn before the reply
        // (the card closing) shows what was typed, not the old labels.
        (q.options || []).forEach(function (o) { if (String(o.role || 'choice') !== role) { other.push(o); } });
        q.options = other.concat(list.map(function (o, i) {
            return {
                option_id: o.option_id, question_id: q.question_id, role: role, sort_order: i,
                label: o.label, value_num: o.value_num, is_other: o.is_other
            };
        }));

        // The last list is still on the wire: its reply writes the new rows'
        // ids back, and these rows are re-read and sent then (#29).
        if (optBusy[key]) {
            optBusy[key].again = function () { commitOptions(questionId, role, wrap); };
            refreshPill();
            return;
        }
        save(key, 'option_set', {
            QuestionId: questionId,
            Role:       role,
            Options:    JSON.stringify(list)
        }, function (data) {
            var cur  = questionById(questionId);
            var opts = data.options || [];
            var kept = [];
            if (!cur) { return; }
            (cur.options || []).forEach(function (o) { if (String(o.role || 'choice') !== role) { kept.push(o); } });
            cur.options = kept.concat(opts);
            // A closed card's rows are kept too: an edit waiting on this reply
            // is re-read from them (#29).
            sent.forEach(function (row, i) {
                if (opts[i]) { row.setAttribute('data-oid', parseInt(opts[i].option_id, 10)); }
            });
            if (document.contains(wrap)) { refreshOptionRowStates(wrap); }
            // The domain cleared every skip rule that read a removed option.
            pruneShowIf(questionId, (function (ids) { return function (oid) { return !!ids[oid]; }; }(optionIdSet(cur))));
            // A card that closed while this was in flight shows the saved rows.
            if (parseInt(questionId, 10) !== sel) { refreshCard(questionId); }
        }, { node: wrap, onSent: optionSetSent(key) });
        refreshPill();
    }

    /**
     * Recompute each row's remove / move states, its number glyph and its
     * "Option N" name after rows were added, removed or reordered.
     */
    function refreshOptionRowStates(wrap) {
        var rows = els('.svb-optrow', wrap);
        var min  = parseInt(wrap.getAttribute('data-min'), 10) || 0;
        rows.forEach(function (row, i) {
            var rm    = el('[data-act="opt-remove"]', row);
            var up    = el('[data-act="opt-up"]', row);
            var dn    = el('[data-act="opt-down"]', row);
            var glyph = el('.svb-glyph-num', row);
            var input = el('.svb-optlabel', row);
            var wt    = el('.svb-optweight', row);
            var name  = input ? String(input.getAttribute('aria-label') || '').replace(/\s+\d+$/, '') : '';
            var wname = wt ? String(wt.getAttribute('aria-label') || '').replace(/\s+\d+$/, '') : '';
            if (rm) { rm.disabled = S.locked || rows.length <= min || wrap.getAttribute('data-fixed') === '1'; }
            if (up) { up.disabled = S.locked || i === 0; }
            if (dn) { dn.disabled = S.locked || i === rows.length - 1; }
            if (glyph) { glyph.textContent = String(i + 1); }
            if (input && name) { input.setAttribute('aria-label', name + ' ' + (i + 1)); }
            if (wt && wname) { wt.setAttribute('aria-label', wname + ' ' + (i + 1)); }
        });
    }

    /**
     * Add a new option row and (by default) focus it. opts: { label, after
     * (insert after this row instead of at the end), focus: false }.
     */
    function addOptionRow(q, wrap, isOther, opts) {
        var role = wrap.getAttribute('data-role');
        var host = el('.svb-optlist', wrap);
        var spec = specFor(q, role);
        var count, html, row, input;
        opts = opts || {};
        if (!host || !q) { return null; }

        count = els('.svb-optrow', host).length;
        if (role === 'choice' && q.type !== 'matrix') { spec = optionRowsHtmlSpec(spec); }
        html = optionRowHtml(q, spec, {
            option_id: 0,
            label:     opts.label !== undefined ? opts.label : (isOther ? 'Other' : ''),
            is_other:  isOther ? 1 : 0,
            value_num: null
        }, count, count + 1);

        if (opts.after && opts.after.parentNode === host) {
            opts.after.insertAdjacentHTML('afterend', html);
            row = opts.after.nextElementSibling;
        } else {
            host.insertAdjacentHTML('beforeend', html);
            row = host.lastElementChild;
        }

        refreshOptionRowStates(wrap);
        input = row ? el('.svb-optlabel', row) : null;
        if (input && opts.focus !== false) { input.focus(); input.select(); }
        return row;
    }

    /**
     * Remove one option row. An existing option some skip rule reads asks
     * first (the domain would clear those rules with it); the keyboard then
     * lands on the neighbouring row, or on "Add option" when none is left.
     */
    function removeOptionRow(q, wrap, row, preferPrev) {
        var oid   = parseInt(row.getAttribute('data-oid'), 10) || 0;
        var input = el('.svb-optlabel', row);
        var label = (input && String(input.value).trim()) || savedLabel(q, wrap.getAttribute('data-role'), oid) || 'this option';
        var deps  = oid ? showIfDependents(q.question_id, function (x) { return x === oid; }) : 0;

        function go() {
            var prev = row.previousElementSibling, next = row.nextElementSibling;
            var land = preferPrev ? (prev || next) : (next || prev);
            var target;
            if (!row.parentNode) { return; }
            row.parentNode.removeChild(row);
            refreshOptionRowStates(wrap);
            commitOptions(q.question_id, wrap.getAttribute('data-role'));
            target = land && land.classList.contains('svb-optrow') ? el('.svb-optlabel', land) : el('[data-act="opt-add"]', wrap);
            if (target) {
                target.focus();
                if (target.setSelectionRange && target.value !== undefined) {
                    try { target.setSelectionRange(target.value.length, target.value.length); } catch (e) { /* not text */ }
                }
            }
        }

        if (!deps) { go(); return; }
        askConfirm('Removing “' + label + '” also removes ' + plural(deps, 'skip rule', 'skip rules') +
                   ' that ' + (deps === 1 ? 'depends' : 'depend') + ' on it.', 'Remove option', true, go);
    }

    /** A choice spec with the control glyph turned on (see optionRowsHtml). */
    function optionRowsHtmlSpec(spec) {
        var copy = {}, k;
        for (k in spec) { if (Object.prototype.hasOwnProperty.call(spec, k)) { copy[k] = spec[k]; } }
        copy.glyph = true;
        return copy;
    }

    /* --------------------------------------------------------------- events */

    function onCanvasClick(e) {
        var btn  = e.target.closest ? e.target.closest('[data-act], [data-upload], [data-imgclear], [data-md-cmd]') : null;
        var item;

        if (btn) {
            if (btn.disabled) { e.preventDefault(); return; }
            e.preventDefault();
            e.stopPropagation();
            handleAct(btn);
            return;
        }
        // A click inside the editor must not bounce the selection around.
        if (e.target.closest && e.target.closest('.svb-edit')) { return; }
        item = e.target.closest ? e.target.closest('.svb-item') : null;
        if (item) {
            e.preventDefault();
            select(item.getAttribute('data-qid'));
            return;
        }
        /* A click on empty canvas lets go of the open card, like Escape —
           but never off a control, a drag handle, the confirm strip, or the
           end of a text drag-select. */
        if (!sel || confirmOpen() || !e.target.closest) { return; }
        if (e.target.closest('input, textarea, select, button, a, label, [contenteditable="true"], ' +
                             '[data-tip], .svb-page-handle, .svb-handle, #svb-confirm')) { return; }
        if (window.getSelection && String(window.getSelection() || '') !== '') { return; }
        flush();
        select(0);
    }

    function handleAct(btn) {
        var act  = btn.getAttribute('data-act');
        var q    = questionById(sel);
        var qid, pid, wrap, row, more, cmd, area, job, fields;

        if (btn.hasAttribute('data-md-cmd')) {
            cmd  = btn.getAttribute('data-md-cmd');
            area = $(btn.getAttribute('data-md-for'));
            if (!area) { return; }
            if (cmd === 'image') { startUpload('md:' + area.id); return; }
            mdCommand(cmd, area);
            return;
        }
        if (btn.hasAttribute('data-upload')) { startUpload(btn.getAttribute('data-upload')); return; }
        if (btn.hasAttribute('data-imgclear')) {
            job = btn.getAttribute('data-imgclear');
            clearImage(job);
            return;
        }

        switch (act) {
            case 'add-element':
                addElement(parseInt(btn.getAttribute('data-page'), 10),
                           parseInt(btn.getAttribute('data-after'), 10) || 0);
                break;

            case 'help-add':
                if (!q) { return; }
                helpOpen[q.question_id] = true;
                refreshCard(q.question_id);
                area = el('.svb-md-input', cardEl(q.question_id));
                if (area) { area.focus(); }
                break;

            case 'pw-help':
                if (!q) { return; }
                area = el('.svb-pw-lines', cardEl(q.question_id));
                openModal('How pairwise questions work',
                          pairwiseHelpHtml(area ? pairwiseLines(area.value).length : optionsOf(q, 'choice').length));
                break;

            case 'more-toggle':
                more = el('.svb-more', btn.closest('.svb-edit'));
                if (more) {
                    more.hidden = !more.hidden;
                    btn.classList.toggle('svb-icon-on', !more.hidden);
                    btn.setAttribute('aria-expanded', more.hidden ? 'false' : 'true');
                }
                break;

            case 'opt-add':
            case 'opt-add-other':
                if (!q) { return; }
                wrap = btn.closest('.svb-opts');
                if (!wrap) { return; }
                addOptionRow(q, wrap, act === 'opt-add-other');
                if (act === 'opt-add-other') { commitOptions(q.question_id, wrap.getAttribute('data-role')); }
                break;

            case 'opt-remove':
                if (!q) { return; }
                row  = btn.closest('.svb-optrow');
                wrap = btn.closest('.svb-opts');
                if (!row || !wrap) { return; }
                removeOptionRow(q, wrap, row, false);
                break;

            case 'q-up':
            case 'q-down':
                moveQuestion(parseInt(btn.getAttribute('data-qid'), 10), act === 'q-up' ? -1 : 1);
                break;

            case 'opt-up':
            case 'opt-down':
                if (!q) { return; }
                row  = btn.closest('.svb-optrow');
                wrap = btn.closest('.svb-opts');
                if (!row || !wrap) { return; }
                moveOptionRow(q, wrap, row, act === 'opt-up' ? -1 : 1);
                break;

            case 'q-duplicate':
                qid = parseInt(btn.getAttribute('data-qid'), 10);
                if (questionById(qid)) { duplicateQuestion(questionById(qid)); }
                break;

            case 'q-delete':
                qid = parseInt(btn.getAttribute('data-qid'), 10);
                askConfirm('Delete “' + (tocTitle(questionById(qid)) || 'this element') +
                           '” and everything on it?', 'Delete', true, function () {
                    deleteQuestion(qid);
                });
                break;

            case 'page-more':
                pid  = parseInt(btn.getAttribute('data-page'), 10);
                more = el('.svb-page-more[data-page="' + pid + '"]');
                if (more) {
                    more.hidden = !more.hidden;
                    btn.classList.toggle('svb-icon-on', !more.hidden);
                    btn.setAttribute('aria-expanded', more.hidden ? 'false' : 'true');
                }
                break;

            case 'page-delete':
                pid = parseInt(btn.getAttribute('data-page'), 10);
                askConfirm('Delete this page? Any questions on it move to the page before.', 'Delete page', true, function () {
                    var at = -1, i, land;
                    for (i = 0; i < S.pages.length; i++) {
                        if (parseInt(S.pages[i].page_id, 10) === pid) { at = i; }
                    }
                    land = S.pages[at - 1] || S.pages[at + 1];
                    if (!land) { return; }
                    // A title edit still queued for this page goes out first,
                    // or it would land on a deleted page and read as lost.
                    flush();
                    whenIdle(function () {
                        post('page_delete', { PageId: pid }, function () {
                            var to = parseInt(land.page_id, 10), moved = [], title;
                            // Mirror Survey::pageDelete — the page's questions
                            // follow the target page's own, in their order —
                            // so no full re-read is needed (#15).
                            S.questions = S.questions.filter(function (x) {
                                if (parseInt(x.page_id, 10) !== pid) { return true; }
                                x.page_id = to;
                                moved.push(x);
                                return false;
                            }).concat(moved);
                            S.pages = S.pages.filter(function (p) { return parseInt(p.page_id, 10) !== pid; });
                            renderCanvas();
                            title = el('.svb-page[data-page="' + to + '"] .svb-page-title');
                            if (title) { title.focus(); }
                        });
                    });
                });
                break;

            case 'page-up':
            case 'page-down':
                movePage(parseInt(btn.getAttribute('data-page'), 10), act === 'page-up' ? -1 : 1);
                break;

            case 'page-add':
                post('page_add', { SurveyId: SURVEY_ID }, function (data) {
                    var title;
                    if (!data.page) { reload(); return; }
                    S.pages.push(data.page);
                    renderCanvas();
                    title = el('.svb-page[data-page="' + parseInt(data.page.page_id, 10) + '"] .svb-page-title');
                    if (title) {
                        title.focus({ preventScroll: true });
                        if (title.scrollIntoView) { title.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                    }
                });
                break;

            case 'share-copy':
                copyLink(shareLink());
                break;

            case 'help':
                openHelp();
                break;

            case 'date-clear':
                fields = {};
                fields.SurveyId = SURVEY_ID;
                fields[btn.getAttribute('data-field')] = '';
                /* Empty the picker here: renderSettings() below skips the
                   sidebar while it holds focus, and it does after this click.
                   clear(false) skips onChange, so no second save goes out. */
                (function (inp) {
                    if (inp && inp._flatpickr) { inp._flatpickr.clear(false); } else if (inp) { inp.value = ''; }
                }(el('.svb-date[data-sv-field="' + btn.getAttribute('data-field') + '"]')));
                btn.disabled = true;
                /* A pick still waiting in its debounce would land after this
                   and put the date back. */
                if (pending['survey:' + btn.getAttribute('data-field')]) {
                    window.clearTimeout(pending['survey:' + btn.getAttribute('data-field')].timer);
                    delete pending['survey:' + btn.getAttribute('data-field')];
                    refreshPill();
                }
                post('update', fields, function (r) {
                    S.survey = r.survey || S.survey;
                    renderSettings();
                });
                break;

            case 'accent-clear':
                S.survey.accent_color = null;
                post('update', { SurveyId: SURVEY_ID, AccentColor: '' }, function (r) {
                    S.survey = r.survey || S.survey;
                    renderSettings();
                });
                break;

            default:
                break;
        }
    }

    function onCanvasInput(e) {
        var t = e.target;
        var q = questionById(sel);
        var page, key;

        if (t.classList && t.classList.contains('svb-autogrow')) { autoGrow(t); }
        if (t.classList && t.classList.contains('svb-md-input')) { refreshMdPreview(t); }

        if (t.hasAttribute('data-q-field') && q) {
            key = t.getAttribute('data-q-field');
            saveQuestionField(q, key, t);
            if (key === 'Prompt') { tocLiveText(); }
            return;
        }
        if (t.hasAttribute('data-p-field')) {
            page = pageOfNode(t);
            if (page) { savePageField(page, t.getAttribute('data-p-field'), t); }
            if (t.getAttribute('data-p-field') === 'Title') { tocLiveText(); }
            return;
        }
        if (t.hasAttribute('data-q-setting') && q) {
            saveSettings(q, t);
            if (t.getAttribute('data-q-setting') === 'caption') { tocLiveText(); }
            return;
        }
        if (t.classList && t.classList.contains('svb-pw-lines') && q) {
            paintPairwiseReadout(t);
            commitPairwise(q.question_id);
            return;
        }
        if (t.classList && (t.classList.contains('svb-optlabel') || t.classList.contains('svb-optweight')) && q) {
            commitOptions(q.question_id, t.closest('.svb-opts').getAttribute('data-role'));
        }
    }

    /* The type picker commits on a pointer pick at once, but a keyboard walk
       (arrow / letter keys on the closed select) only on Enter or blur. */
    var typeKeyNav = false;

    function onCanvasChange(e) {
        var t = e.target;
        var q = questionById(sel);
        var kind;

        if (t.classList && t.classList.contains('svb-typesel')) {
            if (!typeKeyNav) { commitType(t); return; }
            if (q && String(t.value) !== String(q.type)) {
                fieldError(t, 'Press Enter to switch to ' + ((TYPE_META[t.value] || {}).label || t.value) +
                              ', or Escape to keep ' + ((TYPE_META[q.type] || {}).label || q.type) + '.', true);
            } else {
                fieldError(t, '');
            }
            return;
        }
        if (t.hasAttribute('data-q-setting') && q) {
            kind = t.getAttribute('data-kind');
            saveSettings(q, t);
            // A picker that changes what the respondent sees redraws the preview.
            if (kind === 'select') { refreshTypeEditor(q, t.getAttribute('data-q-setting')); }
            return;
        }
        /* Text boxes already saved on 'input'; their blur 'change' would send
           the same value again. */
        if ((t.tagName === 'TEXTAREA') ||
            (t.tagName === 'INPUT' && !/^(checkbox|radio|file|color|range|date|datetime-local|time|month|week)$/.test(t.type))) {
            return;
        }
        onCanvasInput(e);
    }

    function onCanvasPointerDown(e) {
        if (e.target.classList && e.target.classList.contains('svb-typesel')) { typeKeyNav = false; }
    }

    /**
     * Leaving a field. The type picker commits a keyboard-walked value; a
     * still-blank option row lets go — a new one is dropped, a cleared
     * existing one gets its saved label back — and the rest is committed.
     */
    function onCanvasFocusOut(e) {
        var t = e.target, to = e.relatedTarget, row, wrap, item, q, oid, role, min;
        // A field that left because a repaint removed it is not the author leaving it.
        if (!t.classList || !document.contains(t)) { return; }
        if (t.classList.contains('svb-typesel')) {
            typeKeyNav = false;
            if (!(to && $('svb-confirm') && $('svb-confirm').contains(to))) {
                // activeElement is <body> mid-blur, so name the opener: the
                // field the author was heading to, else this card's picker.
                q = questionById((t.closest('.svb-item') || t).getAttribute('data-qid'));
                commitType(t, function () {
                    var card = q ? cardEl(q.question_id) : null;
                    if (to && document.contains(to) && !to.disabled && to !== document.body) { return to; }
                    return card ? el('.svb-typesel', card) : null;
                });
            }
            return;
        }
        if (!t.classList.contains('svb-optlabel') || String(t.value || '').trim() !== '' || S.locked) { return; }
        row  = t.closest('.svb-optrow');
        wrap = t.closest('.svb-opts');
        item = t.closest('.svb-item');
        if (!row || !wrap || !item || !row.parentNode) { return; }
        if (to && row.contains(to)) { return; }   // on its way to this row's own buttons
        q    = questionById(item.getAttribute('data-qid'));
        if (!q) { return; }
        oid  = parseInt(row.getAttribute('data-oid'), 10) || 0;
        role = wrap.getAttribute('data-role');
        min  = parseInt(wrap.getAttribute('data-min'), 10) || 0;
        if (oid) {
            t.value = savedLabel(q, role, oid);
            fieldError(t, '');
        } else if (els('.svb-optrow', wrap).length > min && wrap.getAttribute('data-fixed') !== '1') {
            row.parentNode.removeChild(row);
            refreshOptionRowStates(wrap);
        }
        commitOptions(q.question_id, role);
    }

    /**
     * Pasting a multi-line list into an option label makes one row per line
     * (bullets stripped, blanks skipped): the first line lands in this row, the
     * rest become new rows right after it, and the role commits once.
     */
    var PASTE_MAX = 100;

    function onCanvasPaste(e) {
        var t = e.target, q = questionById(sel), wrap, row, cb, text, lines, first, start, end, after, last;
        /* A pasted list lands clean: bullets stripped and blank lines dropped,
           the same rule the option-row paste uses. */
        if (t.classList && t.classList.contains('svb-pw-lines') && q) {
            cb   = e.clipboardData || window.clipboardData;
            text = cb ? String(cb.getData('text') || '') : '';
            if (!/[\r\n]/.test(text) && !/^\s*[-*•]\s+/.test(text)) { return; }
            e.preventDefault();
            lines = pairwiseLines(text).join('\n');
            start = typeof t.selectionStart === 'number' ? t.selectionStart : t.value.length;
            end   = typeof t.selectionEnd === 'number' ? t.selectionEnd : t.value.length;
            // A line break at either end of the clipboard is kept, as a native
            // paste would: pasting "\nHarvest games" after "Youth day" adds a
            // line instead of making "Youth dayHarvest games".
            if (/^[ \t]*[\r\n]/.test(text) && start > 0 && !/[\r\n]$/.test(t.value.slice(0, start))) {
                lines = '\n' + lines;
            }
            if (/[\r\n][ \t]*$/.test(text) && end < t.value.length && !/^[\r\n]/.test(t.value.slice(end)) &&
                lines !== '' && lines !== '\n') {
                lines += '\n';
            }
            t.value = t.value.slice(0, start) + lines + t.value.slice(end);
            try { t.setSelectionRange(start + lines.length, start + lines.length); } catch (err) { /* not text */ }
            autoGrow(t);
            fire(t, 'input');
            return;
        }
        if (!t.classList || !t.classList.contains('svb-optlabel') || !q || S.locked) { return; }
        wrap = t.closest('.svb-opts');
        row  = t.closest('.svb-optrow');
        if (!wrap || !row || wrap.getAttribute('data-fixed') === '1') { return; }
        cb   = e.clipboardData || window.clipboardData;
        text = cb ? String(cb.getData('text') || '') : '';
        if (!/[\r\n]/.test(text)) { return; }

        lines = text.split(/\r\n|\r|\n/).map(function (line) {
            return line.replace(/^\s*[-*•]\s+/, '').trim().slice(0, 255);
        }).filter(function (line) { return line !== ''; });
        if (!lines.length) { return; }
        e.preventDefault();
        if (lines.length > PASTE_MAX) {
            notice('Only the first ' + PASTE_MAX + ' lines were added as options.', 'warn');
            lines = lines.slice(0, PASTE_MAX);
        }

        first = lines.shift();
        start = typeof t.selectionStart === 'number' ? t.selectionStart : t.value.length;
        end   = typeof t.selectionEnd === 'number' ? t.selectionEnd : t.value.length;
        t.value = (t.value.slice(0, start) + first + t.value.slice(end)).slice(0, 255);
        fieldError(t, '');

        after = row;
        last  = t;
        lines.forEach(function (label) {
            after = addOptionRow(q, wrap, false, { label: label, after: after, focus: false }) || after;
            last  = el('.svb-optlabel', after) || last;
        });
        refreshOptionRowStates(wrap);
        commitOptions(q.question_id, wrap.getAttribute('data-role'));
        last.focus();
        try { last.setSelectionRange(last.value.length, last.value.length); } catch (err) { /* not text */ }
    }

    function pageOfNode(node) {
        var host = node.closest ? node.closest('[data-page]') : null;
        return host ? pageById(host.getAttribute('data-page')) : null;
    }

    /**
     * Enter in an option label commits and opens the next row; Backspace in an
     * empty one removes it. Escape drops the selection.
     */
    function onCanvasKeydown(e) {
        var t = e.target;
        var q = questionById(sel);
        var wrap, row, prev, item, closing, card;

        if (t.classList && t.classList.contains('svb-typesel')) {
            if (e.key === 'Enter') {
                e.preventDefault();
                typeKeyNav = false;
                commitType(t);
                return;
            }
            if (e.key === 'Escape' && q && String(t.value) !== String(q.type)) {
                // Put the picker back; the card stays open.
                e.preventDefault();
                e.stopPropagation();
                t.value = String(q.type);
                fieldError(t, '');
                typeKeyNav = false;
                return;
            }
            if (/^(Arrow|Page|Home|End)/.test(e.key) || e.key.length === 1) { typeKeyNav = true; }
        }

        // Escape closes the open card and hands the keyboard to it (never to
        // <body>). While the confirm strip is asking, Escape belongs to it.
        if (e.key === 'Escape' && sel && !confirmOpen()) {
            e.preventDefault();
            closing = sel;
            flush();
            select(0);
            card = cardEl(closing);
            if (card) { card.focus({ preventScroll: true }); }
            return;
        }

        if (t.classList && t.classList.contains('svb-optlabel') && q) {
            wrap = t.closest('.svb-opts');
            row  = t.closest('.svb-optrow');
            if (e.key === 'Enter') {
                e.preventDefault();
                if (wrap.getAttribute('data-fixed') === '1' || S.locked) { return; }
                // A blank row does not spawn another blank row.
                if (String(t.value).trim() === '') { return; }
                commitOptions(q.question_id, wrap.getAttribute('data-role'));
                addOptionRow(q, wrap, false, { after: row });
                return;
            }
            if (e.key === 'Backspace' && String(t.value) === '' && !S.locked &&
                    wrap.getAttribute('data-fixed') !== '1') {
                prev = row.previousElementSibling;
                if (prev && prev.classList.contains('svb-optrow') &&
                        els('.svb-optrow', wrap).length > (parseInt(wrap.getAttribute('data-min'), 10) || 0)) {
                    e.preventDefault();
                    removeOptionRow(q, wrap, row, true);
                }
            }
            return;
        }

        item = t.closest ? t.closest('.svb-item') : null;
        if (item && t === item && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            select(item.getAttribute('data-qid'), true);
        }
    }

    /* ------------------------------------------------------------- persistence */

    /** The question columns each data-q-field writes (see saveQuestionField). */
    var FIELD_COLUMNS = {
        Prompt:           ['prompt'],
        HelpMd:           ['help_md'],
        Required:         ['required'],
        ShowIfQuestionId: ['show_if_question_id', 'show_if_option_id'],
        ShowIfOptionId:   ['show_if_question_id', 'show_if_option_id']
    };

    function saveQuestionField(q, key, node) {
        var fields = { QuestionId: q.question_id };
        var bucket = 'q:' + q.question_id + ':' + key;
        var qid    = parseInt(q.question_id, 10);
        if (node.type === 'checkbox') {
            fields[key] = node.checked ? 1 : 0;
            if (key === 'Required') { q.required = node.checked ? 1 : 0; }
        } else if (key === 'ShowIfQuestionId') {
            fields.ShowIfQuestionId = node.value;
            fields.ShowIfOptionId   = firstOptionOf(node.value);
        } else if (key === 'ShowIfOptionId') {
            fields.ShowIfQuestionId = q.show_if_question_id || 0;
            fields.ShowIfOptionId   = node.value;
        } else {
            // A blank prompt is refused by the domain, so it is never sent:
            // hold it with an inline hint until the next real character, and
            // keep S (the preview, the outline) on the last real wording.
            if (key === 'Prompt' && String(node.value).trim() === '') {
                holdBlank(bucket, node, q.type === 'section'
                    ? 'A section needs a heading. Type one to save.'
                    : 'A question needs a prompt. Type one to save.');
                return;
            }
            if (key === 'Prompt') { releaseHold(bucket, node); }
            fields[key] = node.value;
            if (key === 'Prompt') { q.prompt = node.value; }
            if (key === 'HelpMd') { q.help_md = node.value; }
        }

        save(bucket, 'question_update', fields, function (data) {
            var fresh = data.question, cur = questionById(qid);
            // Take back only the columns this save wrote. Replacing the whole
            // row would roll S back over the card's other edits still in
            // their own debounce (options, settings), and a newer keystroke
            // queued for this same field is newer than this reply.
            if (fresh && cur && !pending[bucket] && !held[bucket]) {
                (FIELD_COLUMNS[key] || []).forEach(function (c) { cur[c] = fresh[c]; });
            }
            if (key === 'ShowIfQuestionId' || key === 'ShowIfOptionId') {
                // The condition changed which controls the menu needs, and the
                // card's chip; redraw, then put the menu back where it was.
                refreshCard(qid);
                wireOptionSortables();
                openMore(qid, '[data-q-field="' + key + '"]');
            }
        }, { node: node });
    }

    function savePageField(page, key, node) {
        var fields = { PageId: page.page_id };
        if (key === 'ShowIfQuestionId') {
            fields.ShowIfQuestionId = node.value;
            fields.ShowIfOptionId   = firstOptionOf(node.value);
        } else if (key === 'ShowIfOptionId') {
            fields.ShowIfQuestionId = page.show_if_question_id || 0;
            fields.ShowIfOptionId   = node.value;
        } else {
            fields[key] = node.value;
            if (key === 'Title') { page.title = node.value; }
            if (key === 'DescriptionMd') { page.description_md = node.value; }
        }

        var bucket = 'p:' + page.page_id + ':' + key;
        var cols   = key === 'Title' ? ['title'] : (key === 'DescriptionMd' ? ['description_md']
                   : ['show_if_question_id', 'show_if_option_id']);
        save(bucket, 'page_update', fields, function (data) {
            var fresh = data.page, cur = fresh ? pageById(fresh.page_id) : null;
            // Only the columns this save wrote, and not while a newer
            // keystroke for the same field is still queued.
            if (fresh && cur && !pending[bucket]) {
                cols.forEach(function (c) { cur[c] = fresh[c]; });
            }
            if (key === 'ShowIfQuestionId' || key === 'ShowIfOptionId') {
                renderCanvas();
                openPageMore(page.page_id, '[data-p-field="' + key + '"]');
            }
        }, { node: node });
    }

    function firstOptionOf(questionId) {
        var q = questionById(questionId);
        var opts = q ? optionsOf(q, 'choice') : [];
        return opts.length ? parseInt(opts[0].option_id, 10) : 0;
    }

    function saveSurveyField(key, node) {
        var fields = { SurveyId: SURVEY_ID };
        var value;

        if (node.type === 'checkbox') {
            value = node.checked ? 1 : 0;
        } else if (key === 'OpenAt' || key === 'CloseAt') {
            value = dateFieldValue(node);
        } else if (key === 'AudienceKingdomIds') {
            value = JSON.stringify(els('option', node).filter(function (o) { return o.selected; })
                .map(function (o) { return parseInt(o.value, 10); }));
        } else {
            value = node.value;
        }

        fields[key] = value;
        if (key === 'Title') { S.survey.title = node.value; }

        save('survey:' + key, 'update', fields, function (data) {
            S.survey = data.survey || S.survey;
            renderHeader();
            if (key === 'OpenAt' || key === 'CloseAt') { dateClearSync(node, value !== ''); }
        }, { node: node, onFail: function () {
            if (node.type !== 'checkbox') { return; }
            /* A refused toggle (e.g. the data-gate lock while credits are on)
               goes back to the server's value, so nothing is left unsaved:
               keep the inline reason, but clear the "Not saved" pill and the
               leave-page prompt that post() armed for this key. */
            node.checked = !node.checked;
            delete failed['survey:' + key];
            refreshPill();
        } });
    }

    function onSettingsInput(e) {
        var t = e.target, key;
        if (t.classList && t.classList.contains('svb-md-input')) { refreshMdPreview(t); }
        if (!t.hasAttribute('data-sv-field')) { return; }
        key = t.getAttribute('data-sv-field');
        saveSurveyField(key, t);
        if (key === 'ResultsShare') {
            Array.prototype.forEach.call(document.querySelectorAll('input[name="svb-results-timing"]'), function (r) {
                r.disabled = t.value === 'none';
            });
        }
    }

    /* ------------------------------------------------- attendance credit card */

    /** The survey's owner acts for its own org; an ORK survey's owner only reads. */
    function creditGrantor() {
        var s = S.survey || {};
        return s.scope_type === 'ork' ? '' : (s.scope_type === 'kingdom' ? 'Kingdom/' : 'Park/') + s.scope_id;
    }

    /** The card button says whether the owner's credit is already on (creditOn, from credit_status). */
    function creditButtonLabel() {
        if ((S.survey || {}).scope_type === 'ork') { return 'See credits'; }
        return creditOn ? 'View attendance credit' : 'Set up attendance credit';
    }

    /** Ask the server once on load, and again whenever the modal changes something. */
    function loadCreditState() {
        if (!window.SvCredit || !window.SvCredit.status || (S.survey || {}).scope_type === 'ork') { return; }
        window.SvCredit.status(SURVEY_ID, creditGrantor()).then(function (c) {
            var label;
            if (!c) { return; }
            creditOn = !!(c.mine && c.mine.config_id);
            label = $('svb-credit-label');
            if (label) { label.textContent = creditButtonLabel(); }
        });
    }

    function onSettingsClick(e) {
        var head = e.target.closest ? e.target.closest('[data-sec-toggle]') : null;
        var btn;
        if (head) {
            e.preventDefault();
            toggleSection(head.getAttribute('data-sec-toggle'));
            return;
        }
        if (e.target.closest('#svb-credit-open') && window.SvCredit) {
            window.SvCredit.open({
                surveyId: SURVEY_ID,
                grantor: creditGrantor(),
                title: S.survey.title,
                // Called only once this org's credit is on (survey-credit.js), and
                // the modal re-reads credit_status itself, so no second fetch here.
                onChange: function () {
                    var label = $('svb-credit-label');
                    creditOn = true;
                    if (label) { label.textContent = creditButtonLabel(); }
                }
            });
            return;
        }
        btn = e.target.closest ? e.target.closest('[data-act], [data-upload], [data-imgclear], [data-md-cmd]') : null;
        if (!btn || btn.disabled) { return; }
        e.preventDefault();
        handleAct(btn);
    }

    /* ------------------------------------------------------- status changes */

    function requestStatus(target) {
        var s = S.survey || {};
        flush();
        if (target === 'open' && !s.opened_at) {
            askConfirm('Open this survey? Its questions, options and pages lock once it opens — wording stays editable.',
                       'Open survey', false, function () { whenIdle(function () { setStatus('open'); }); });
        } else if (target === 'open') {
            askConfirm('Reopen this survey to respondents?', 'Reopen survey', false, function () { whenIdle(function () { setStatus('open'); }); });
        } else {
            askConfirm('Close this survey? Nobody will be able to answer it until you reopen it.',
                       'Close survey', false, function () { whenIdle(function () { setStatus('closed'); }); });
        }
    }

    function setStatus(status) {
        hideConfirm();
        post('set_status', { SurveyId: SURVEY_ID, Status: status }, function (data) {
            S.survey = data.survey || S.survey;
            S.locked = !!(S.survey.opened_at);
            notice('');
            renderAll();
        }, function (data) {
            showOpenErrors(data);
        }, { noLoss: true });
    }

    function showOpenErrors(data) {
        var errors = (data && data.errors) || {};
        var ids = Object.keys(errors);
        var first;
        // Errors are drawn onto the SvRender preview, so drop the open editor
        // first — and under 900px make sure the canvas is the view on screen.
        if (sel) { settleCard(sel); }
        sel = 0;
        setView('questions');
        renderCanvas();
        notice((data && data.error) || 'This survey is not ready to open yet.', 'error');
        ids.forEach(function (qid) {
            var node = el('.svb-item[data-qid="' + parseInt(qid, 10) + '"] .sv-q');
            if (node) { SvRender.setError(node, errors[qid]); }
        });
        if (ids.length) {
            first = cardEl(parseInt(ids[0], 10));
            if (first && first.scrollIntoView) { first.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        }
    }

    /* -------------------------------------------------------- header events */

    function wireHeader() {
        var title = $('svb-title');

        // A blank title is refused by the domain, so it is held, never sent:
        // inline hint + "Not saved" until the next real character (#7).
        if (title) {
            title.addEventListener('input', function () {
                mirrorTitleTip(title);
                if (String(title.value).trim() === '') {
                    holdBlank('survey:Title', title, 'A survey needs a title. Type one to save.');
                    return;
                }
                releaseHold('survey:Title', title);
                S.survey.title = title.value;
                save('survey:Title', 'update', { SurveyId: SURVEY_ID, Title: title.value }, function (data) {
                    S.survey = data.survey || S.survey;
                }, { node: title });
            });
        }

        wireNarrowChrome();

        // Every notice carries its own Dismiss button.
        on($('svb-notice'), 'click', function (e) {
            var x = e.target.closest ? e.target.closest('.svb-notice-close') : null;
            /* The notice itself told them to reload (a refused CSRF token), so
               the leave-page prompt would only argue with it. */
            if (e.target.closest && e.target.closest('.svb-notice-link')) { reloadingOnPurpose = true; }
            if (!x) { return; }
            e.preventDefault();
            notice('');
        });

        // Preview opens a new tab that reads the saved survey: send whatever
        // is still in its debounce first so the preview shows the last edit.
        on($('svb-preview'), 'click', function () {
            if (hasKeys(pending)) { flush(); }
        });

        on($('svb-openclose'), 'click', function (e) {
            e.preventDefault();
            requestStatus(this.getAttribute('data-target') || 'open');
        });

        on($('svb-copylink'), 'click', function (e) {
            e.preventDefault();
            copyLink(this.getAttribute('data-link') || '');
        });

        on($('svb-help'), 'click', function (e) {
            e.preventDefault();
            openHelp();
        });

        on($('svb-confirm-yes'), 'click', function (e) {
            var action = confirmAction;
            e.preventDefault();
            hideConfirm();
            if (action) { action(); }
        });
        on($('svb-confirm-no'), 'click', function (e) { e.preventDefault(); hideConfirm(true); });

        on($('svb-file'), 'change', onFileChosen);

        els('.svb-modal-close, .svb-modal-backdrop').forEach(function (n) {
            n.addEventListener('click', function (e) { e.preventDefault(); closeModal(); });
        });

        document.addEventListener('keydown', function (e) {
            var panel;
            if (e.key === 'Tab') {
                panel = openPanel();
                if (panel) { trapTab(e, panel); }
                return;
            }
            if (e.key !== 'Escape') { return; }
            var m = $('svb-modal');
            if (m && !m.hidden) { closeModal(); return; }
            if (confirmOpen()) { e.preventDefault(); hideConfirm(true); return; }
            if (overflowOpen()) { e.preventDefault(); setOverflow(false, true); }
        });
    }

    /** The builder's help guide in the modal. */
    function openHelp() {
        post('help', { Doc: 'surveys' }, function (data) {
            openModal('Building surveys', data.html || '');
        });
    }

    /* ------------------------------------------------ narrow-width chrome (#13) */

    /* Under 900px (survey-build.css) the canvas and the settings sidebar are
       two views behind a Questions | Settings switch, so the first question
       is not buried under seven settings sections; and Results, Copy link and
       Help fold into one "More" disclosure. Above 900px both controls are
       display:none and the page is unchanged. */

    function setView(view) {
        var root = $('svb-root');
        view = view === 'settings' ? 'settings' : 'questions';
        if (!root) { return; }
        root.setAttribute('data-svb-view', view);
        els('.svb-viewseg-btn').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-view') === view ? 'true' : 'false');
        });
    }

    function overflowOpen() {
        var menu = $('svb-overflow-menu');
        return !!(menu && !menu.hidden);
    }

    /** Open or close the More menu; `refocus` hands the keyboard back to its button. */
    function setOverflow(open, refocus) {
        var btn  = $('svb-overflow-btn');
        var menu = $('svb-overflow-menu');
        var first;
        if (!btn || !menu) { return; }
        menu.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            alignOverflow();
            first = el('.svb-overflow-item', menu);
            if (first) { first.focus(); }
        } else if (refocus) {
            btn.focus();
        }
    }

    /**
     * Keep the open More menu inside the viewport. It hangs from the button's
     * right edge by default; when the header wraps More to the left of its row
     * (phones) that would push the menu past the left edge, so it flips to hang
     * from the button's left edge instead (data-align="start" in the CSS).
     */
    function alignOverflow() {
        var menu = $('svb-overflow-menu');
        var gutter = 8;
        var r;
        if (!menu || menu.hidden) { return; }
        menu.removeAttribute('data-align');
        r = menu.getBoundingClientRect();
        if (r.left < gutter) { menu.setAttribute('data-align', 'start'); }
    }

    function wireNarrowChrome() {
        var btn  = $('svb-overflow-btn');
        var menu = $('svb-overflow-menu');

        // A rotate or resize can move the button to the other end of its row.
        window.addEventListener('resize', alignOverflow, false);

        els('.svb-viewseg-btn').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                setView(b.getAttribute('data-view'));
            });
        });

        on(btn, 'click', function (e) {
            e.preventDefault();
            setOverflow(!overflowOpen());
        });

        on(menu, 'click', function (e) {
            var item = e.target.closest ? e.target.closest('[data-overflow]') : null;
            var what;
            if (!item) { return; }   // the Results link just navigates
            e.preventDefault();
            what = item.getAttribute('data-overflow');
            // Close first and put the keyboard on the button, so the help
            // modal hands focus back to something that is still on screen.
            setOverflow(false, true);
            if (what === 'copy') { copyLink(shareLink()); }
            if (what === 'help') { openHelp(); }
        });

        // A click anywhere else, or focus leaving the menu, closes it.
        document.addEventListener('click', function (e) {
            var box = btn ? btn.closest('.svb-overflow') : null;
            if (overflowOpen() && box && !box.contains(e.target)) { setOverflow(false); }
        }, false);
        on(menu, 'focusout', function (e) {
            var box = btn ? btn.closest('.svb-overflow') : null;
            if (overflowOpen() && box && e.relatedTarget && !box.contains(e.relatedTarget)) { setOverflow(false); }
        });
    }

    /** Clipboard with a spoken fallback — never a native prompt. */
    function copyLink(link) {
        if (!link) { return; }
        if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
            window.navigator.clipboard.writeText(link).then(function () {
                notice('Share link copied to the clipboard.', 'ok');
            })['catch'](function () { notice('Copy this share link: ' + link, 'warn'); });
        } else {
            notice('Copy this share link: ' + link, 'warn');
        }
    }

    function on(node, type, fn) {
        if (node) { node.addEventListener(type, fn, false); }
    }

    /* ----------------------------------------------------------------- init */

    function init() {
        var canvas   = $('svb-canvas');
        var settings = $('svb-settings');
        var toc      = $('svb-toc');

        if (!canvas || !window.SvRender) { return; }

        adopt(CFG.survey || {});
        wireHeader();

        // The question catalogue is the server's (SurveyTypes), so nothing is
        // drawn until it lands — a card cannot offer a type list, an option
        // editor or a show-if source without it.
        post('types', {}, function (data) {
            applyCatalog(data.catalog || {});
            renderAll();
        }, function (data) {
            notice((data && data.error) || 'The builder could not load its question catalogue. Reload the page to try again.', 'error');
        });

        canvas.addEventListener('click', onCanvasClick, false);
        canvas.addEventListener('input', onCanvasInput, false);
        canvas.addEventListener('change', onCanvasChange, false);
        canvas.addEventListener('keydown', onCanvasKeydown, false);
        canvas.addEventListener('paste', onCanvasPaste, false);
        canvas.addEventListener('focusout', onCanvasFocusOut, false);
        canvas.addEventListener('pointerdown', onCanvasPointerDown, false);

        if (toc) { toc.addEventListener('click', onTocClick, false); }

        if (settings) {
            settings.addEventListener('input', onSettingsInput, false);
            settings.addEventListener('change', onSettingsInput, false);
            settings.addEventListener('click', onSettingsClick, false);
        }

        // Scope name for the header chip, and the kingdom list for an ork-scoped audience.
        post('scopes', { SurveyId: SURVEY_ID }, function (data) {
            var chip = $('svb-scopename'), i, sc;
            scopes = data.scopes || [];
            managerLabel = String(data.manager_label || '').trim();
            for (i = 0; i < scopes.length; i++) {
                sc = scopes[i];
                if (sc.scope_type === S.survey.scope_type && parseInt(sc.scope_id, 10) === parseInt(S.survey.scope_id, 10)) {
                    if (chip) { chip.textContent = sc.name; }
                    break;
                }
            }
            renderSettings();
        });

        // The "Attended an event" audience picker's list (#12).
        loadEvents();

        // Whether the Attendance credit card's button reads "Set up" or "View".
        loadCreditState();

        /* Leaving the page (#10). Debounced edits go out as keepalive requests,
           which outlive the page where a plain fetch is cancelled. If anything
           was still unsent, on the wire, held (blank) or refused, the browser's
           own leave-page prompt asks first — a browser prompt, not a JS dialog. */
        window.addEventListener('beforeunload', function (e) {
            var dirty = hasKeys(pending) || inflightWrites > 0 || hasKeys(optBusy) || hasKeys(held) || hasKeys(failed);
            if (hasKeys(pending)) { flush(true); }
            if (!dirty || reloadingOnPurpose) { return undefined; }
            e.preventDefault();
            e.returnValue = '';
            return '';
        }, false);

        // A phone backgrounding the tab may never come back to fire the timer.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden' && hasKeys(pending)) { flush(true); }
        }, false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, false);
    } else {
        init();
    }
}(window, document));
