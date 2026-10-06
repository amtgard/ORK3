/* ==========================================================================
   survey-render.js — the survey module's SHARED question renderer.

   One renderer, three consumers: the runner (Survey_take), the builder canvas
   (Survey_build) and anything else that must show a question exactly as a
   respondent sees it. Nobody else builds question markup by hand.

   Public API (window.SvRender):

     question(q, state, mode) -> HTML string
         q     : a question object as it arrives from SurveyAjax/definition
                 (respondent view) or SurveyAjax/get (builder view):
                 { question_id, type, prompt, help_html | help_md, image_url,
                   image{url, small_url, width, height, small_width, small_height},
                   required, settings{}, options[{option_id, role, label,
                   value_num, is_other}] }
         state : the current raw answer (spec §6 "Answers JSON shape") or
                 undefined / null for "unanswered".
         mode  : 'take' (default) or 'preview'. Preview adds .sv-q-preview to
                 the root and tabindex="-1" to every control, so the builder
                 canvas shows a real, non-interactive question.
         Returns the root element's outerHTML as a string:
             <div class="sv-q sv-q-<type>" data-qid="…" data-type="…">
         For 'section' and 'image' it returns block(q).

     read(rootEl, q) -> raw value | undefined
         The exact inverse of question(). Returns undefined when the question
         has not been answered (see "read() contract" below).

     write(rootEl, q, value) -> void
         Restores a raw value into already-rendered markup (draft resume).
         Passing undefined / null clears the question.

     setError(rootEl, message | null) -> void
         Renders or clears <div class="sv-q-error" role="alert"> as the last
         child of the root, and toggles .sv-q-invalid on the root.

     enhanceDates(scopeEl) -> void
         Attaches Flatpickr (readable altInput, ISO value underneath) to every
         date input under scopeEl, when Flatpickr is loaded; a no-op otherwise.

     block(q) -> HTML string
         Renders the presentational types 'section' and 'image'.

     escape(s) -> string           HTML-escape a value for text/attribute use.
     sanitize(html) -> string      DOMPurify.sanitize() plus the survey image
                                   allowlist; escaped text when DOMPurify is
                                   missing (fails closed). md() is an alias.
     isAnswerable(type) -> bool    Mirrors SurveyTypes::ANSWERABLE.
     reindexRank(listEl) -> void   Renumber a .sv-rank list's position badges
                                   and aria-disable its end buttons.
     touchRank(listEl) -> void     Mark a ranking answered (drag onEnd calls it).

     pairwisePlan(optionCount) -> {possible, small, band_pcts, tiers, gate}
                                   Mirrors SurveyTypes::pairwisePlan(), key for
                                   key. Pinned to the PHP by
                                   SurveyPairwisePlanScriptTest.
     pairwiseGateMessage(plan) -> string
                                   What a required pairwise question says
                                   below its gate.
     pairwiseStage(plan, doneCount, required) -> {level, message, complete}
                                   Where a respondent stands (spec §6): bar
                                   colour, message under the bar, done flag.
     pairwiseQueue(ids, done, rand|null) -> [{a, b}, …]
                                   Matchups still to judge: every unordered
                                   pair of ids not in done, shuffled with rand
                                   (null keeps authored order — the builder
                                   preview).
     pairKey(a, b) -> 'min:max'   Canonical key for an unordered option pair.
     PW_SMALL_MAX, PW_BANDS, PW_TIER_MESSAGES, PW_DONE_MESSAGE,
     PW_OPTIONAL_MESSAGE          The pairwise constants pairwisePlan() and
                                   pairwiseStage() read, mirroring
                                   SurveyTypes::PAIRWISE_SMALL_MAX /
                                   PAIRWISE_BANDS and the pairwise spec §6 copy.

   --------------------------------------------------------------------------
   HTML STRUCTURE PER TYPE  (style against this; do not guess)
   --------------------------------------------------------------------------

   Every question, whatever the type, is:

     <div class="sv-q sv-q-TYPE [sv-q-preview]" data-qid="12" data-type="TYPE"
          data-required="0|1">
       <div class="sv-q-prompt" id="sv-p-12">
         Prompt text<span class="sv-q-required" aria-hidden="true">*</span>
         <span class="sv-visually-hidden"> (required)</span>       (required only)
       </div>
       <div class="sv-q-help" id="sv-h-12-N">…markdown HTML…</div> (optional)
       <div class="sv-q-image"><img class="sv-q-image-img" …></div> (optional)
       <div class="sv-q-body"> …type-specific, described below… </div>
       <div class="sv-q-error" role="alert" id="sv-e-12-N" hidden></div>
     </div>

   The .sv-q-error element is always present but starts `hidden`; setError()
   only toggles it (and aria-invalid on the control). Nothing outside
   .sv-q-body varies by type. Every type's control — or, for the composite
   types, its group wrapper — carries aria-describedby="<help id> <hint id>
   <error id>" (help and hint only when present; a hint is the
   <div class="sv-choice-hint" id="sv-hint-12-N"> a multi, ranking or
   pairwise shows)
   and, when the question is required, aria-required="true".

   single / yesno  ─ radio list
     <div class="sv-choices" role="radiogroup" aria-labelledby="sv-p-12">
       <div class="sv-choice-wrap">
         <label class="sv-choice">
           <input type="radio" class="sv-choice-input" name="svqN_12" value="101">
           <span class="sv-choice-label">Yes</span>
         </label>
       </div>
       <div class="sv-choice-wrap sv-choice-wrap-other">     (is_other option)
         <label class="sv-choice"> …radio, value="105"… </label>
         <input type="text" class="sv-input sv-other-input" data-other-for="105"
                maxlength="255" placeholder="Please specify">
       </div>
     </div>
     yesno is identical; the root carries .sv-q-yesno and .sv-choices also
     carries .sv-choices-inline so the two options may sit side by side.

   multi  ─ checkbox list
     Same markup as `single` with type="checkbox" on .sv-choice-input and
     role="group" on .sv-choices. A `max_select`/`min_select` hint, when the
     settings ask for one, is a <div class="sv-choice-hint"> before the list.

   dropdown  ─ native select
     <select class="sv-select" name="svqN_12">
       <option value="">— Select —</option>
       <option value="101">Label</option>
       <option value="105" class="sv-opt-other">Other…</option>
     </select>
     <input type="text" class="sv-input sv-other-input" data-other-for="105" …>

   rating / nps  ─ radio button row (no JS needed to select)
     <div class="sv-scale-wrap">
       <div class="sv-scale [sv-scale-nps] [sv-scale-star|sv-scale-number]"
            role="radiogroup" aria-labelledby="sv-p-12">
         <label class="sv-scale-opt">
           <input type="radio" class="sv-scale-input" name="svqN_12" value="1">
           <span class="sv-scale-face">
             <i class="fas fa-star" aria-hidden="true"></i>  (icon:'star' only)
             <span class="sv-scale-num">1</span>
           </span>
         </label>
         …one .sv-scale-opt per value, ascending…
       </div>
       <div class="sv-scale-ends">                       (when labels are set)
         <span class="sv-scale-end sv-scale-end-min">Not likely</span>
         <span class="sv-scale-end sv-scale-end-max">Very likely</span>
       </div>
       <button type="button" class="sv-scale-clear">Clear</button>  (optional*)
     </div>
     * only when the question is not required — radios cannot be un-checked, so
       the renderer's own delegated handler clears them.
     Star fills are pure CSS: an option is "lit" when it is checked or a LATER
     sibling is checked (`:has(~ .sv-scale-opt .sv-scale-input:checked)`), so
     .sv-scale-opt elements MUST stay direct siblings inside .sv-scale.

   matrix  ─ table, one radio group per row
     <div class="sv-matrix-wrap">
       <table class="sv-matrix">
         <thead><tr>
           <th class="sv-matrix-corner"></th>
           <th class="sv-matrix-col" scope="col">Column label</th>…
         </tr></thead>
         <tbody>
           <tr class="sv-matrix-row" data-row="201">
             <th class="sv-matrix-rowlabel" scope="row">Row label</th>
             <td class="sv-matrix-cell" data-col="301">
               <label class="sv-matrix-opt">
                 <input type="radio" class="sv-matrix-input" name="svqN_12_r201"
                        value="301" aria-label="Row label: Column label">
                 <span class="sv-matrix-echo">Column label</span>
               </label>
             </td>…
           </tr>…
         </tbody>
       </table>
     </div>
     .sv-matrix-echo repeats the column label in every cell. It is hidden on
     wide screens and revealed at ≤700 px, where survey.css collapses <thead>
     and turns each row into a stacked card.

   ranking  ─ ordered list; the DOM order IS the answer, once touched
     <div class="sv-choice-hint" id="sv-hint-12-N">Use the arrows or drag…</div>
     <div class="sv-rank-group" role="group" aria-labelledby="sv-p-12">
     <ol class="sv-rank" data-qid="12" [data-touched="1"]>
       <li class="sv-rank-item" data-option="101">
         <span class="sv-rank-handle" aria-hidden="true"><i class="fas fa-grip-vertical"></i></span>
         <span class="sv-rank-pos">1</span>
         <span class="sv-rank-label">Label</span>
         <span class="sv-rank-btns">
           <button type="button" class="sv-rank-btn sv-rank-up"   data-tip="Move up"   aria-label="Move Label up"><i class="fas fa-chevron-up"></i></button>
           <button type="button" class="sv-rank-btn sv-rank-down" data-tip="Move down" aria-label="Move Label down"><i class="fas fa-chevron-down"></i></button>
         </span>
       </li>…
     </ol>
     <button type="button" class="sv-btn sv-rank-keep">Keep this order</button>
                                                  (required only; hidden once touched)
     </div>
     The ▲▼ buttons are handled by this file (one delegated listener) and fire
     a bubbling `change` from the <ol> afterwards, so autosave just listens for
     `change` on the form. The first item's ▲ and the last item's ▼ carry
     aria-disabled="true". The runner attaches SortableJS to .sv-rank (handle:
     .sv-rank-handle) and calls reindexRank(ol) + touchRank(ol) from its onEnd.

   pairwise ─ two options at a time, a Tie between, a progress bar under
     <div class="sv-choice-hint" id="sv-hint-12-N">Pick the one you prefer…</div>
     <div class="sv-pw" data-pw="pwq12" role="group" aria-labelledby="sv-p-12" …>
       <div class="sv-pw-stage" [data-chose="a|b|tie"] [hidden when complete]>
         <button type="button" class="sv-pw-pick sv-pw-a" data-pw-pick="a"><span class="sv-pw-label">Hawk</span></button>
         <button type="button" class="sv-pw-tie" data-pw-pick="tie">Tie</button>
         <button type="button" class="sv-pw-pick sv-pw-b" data-pw-pick="b"><span class="sv-pw-label">Owl</span></button>
       </div>
       <p class="sv-pw-done" tabindex="-1" [hidden until complete]>…100% message…</p>
       <div class="sv-pw-progress">
         <div class="sv-pw-bar" role="progressbar" aria-valuemin="0" aria-valuemax="M"
              aria-valuenow="k" aria-valuetext="k of M matchups" data-level="0-4">
           <span class="sv-pw-fill" style="width:…%"></span>
           <span class="sv-pw-tick" style="left:…%"></span> ×4   (sets over 30 only)
         </div>
         <div class="sv-pw-meta"><span class="sv-pw-count">12 of 66 matchups</span>
           <button type="button" class="sv-pw-undo" [hidden]>Undo</button></div>
         <p class="sv-pw-msg" aria-live="polite">stage message</p>
       </div>
     </div>
     The widget's state (plan, answered list, queue) lives in this file, keyed
     by data-pw (one entry per question id, so a re-render replaces it); the
     buttons are repainted in place. A pick, a Tie or an Undo
     (one delegated click listener; ← / → / ↓ while focus is on the stage)
     fires a bubbling `change` from .sv-pw, so autosave just listens for it.
     Preview renders the first two options in authored order and records
     nothing.

   short_text   <input type="text" class="sv-input" maxlength=… placeholder=…>
   paragraph    <textarea class="sv-textarea" rows="4" maxlength=… …></textarea>
   number       <div class="sv-number-wrap">
                  <input type="number" class="sv-input sv-input-number"
                         inputmode="decimal" min max step>
                  <span class="sv-unit">points</span>        (settings.unit)
                </div>
   date         <input type="date" class="sv-input sv-input-date" min max>

   section (block)
     <div class="sv-q sv-q-section sv-block" data-qid="12" data-type="section">
       <h3 class="sv-block-title">Heading</h3>
       <div class="sv-block-body">…markdown HTML…</div>
       <div class="sv-q-image">…</div>                            (optional)
     </div>

   image (block)
     <div class="sv-q sv-q-image sv-block" data-qid="12" data-type="image">
       <figure class="sv-figure">
         <img class="sv-figure-img" src="…" alt="">
         <figcaption class="sv-figure-caption">caption</figcaption>
       </figure>
     </div>

   --------------------------------------------------------------------------
   read() CONTRACT — what comes back per type (spec §6 "Answers JSON shape")
   --------------------------------------------------------------------------
     single/dropdown/yesno : Number option_id, or {option_id, other:"text"}
                             when the chosen option has is_other.
     multi                 : [option_id, …], with {option_id, other} in place
                             of any is_other entry. undefined when empty.
     rating / nps          : Number. undefined when nothing is checked.
     number                : Number (the raw string when it will not parse).
     date / short_text /
     paragraph             : String, trimmed. undefined when ''.
     matrix                : { row_option_id: column_option_id } for ANSWERED
                             rows only. undefined when no row is answered.
     ranking               : [option_id, …] in list order once the respondent
                             has touched the list (an arrow, a drag, or "Keep
                             this order"); undefined until then, because the
                             order it arrived in is not a vote.
     pairwise              : [{a, b, w}, …] in answer order (a = left id,
                             b = right id, w = winner id, 0 = tie);
                             undefined before the first pick.
     section / image       : undefined, always.

   Round trip: for every answerable type, read(el, q) after
   write(el, q, v) equals v (numbers stay numbers, ids stay ids).
   ========================================================================== */

(function (window, document) {
    'use strict';

    if (window.SvRender) { return; }

    var ANSWERABLE = [
        'single', 'multi', 'dropdown', 'yesno', 'rating', 'nps', 'matrix', 'ranking', 'pairwise',
        'short_text', 'paragraph', 'number', 'date'
    ];
    var BLOCKS = ['section', 'image'];
    var OTHER_MAX = 255;          // SurveyTypes::OTHER_MAX_LENGTH
    var NPS_MIN = 0;
    var NPS_MAX = 10;

    /* Pairwise (pairwise spec §2): mirrors SurveyTypes::PAIRWISE_SMALL_MAX and
       PAIRWISE_BANDS the way OTHER_MAX mirrors OTHER_MAX_LENGTH.
       SurveyPairwisePlanScriptTest pins this copy to the PHP. */
    var PW_SMALL_MAX = 30;
    var PW_BANDS = [
        [105,  [30, 40, 50, 60]],
        [200,  [20, 30, 40, 50]],
        [300,  [10, 20, 30, 40]],
        [null, [10, 15, 20, 25]]
    ];
    var PW_TIER_MESSAGES = [
        'This is a great start. You can move on, but you can make our survey better by doing a few more matchups!',
        'Even better! You can keep going for better results or continue.',
        'Awesome! This is a great sample. Feel free to keep ranking or continue on.',
        'Fantastic! You\'ve given us a great sample size, so you can keep going or continue on. Your choice!'
    ];
    var PW_DONE_MESSAGE = 'Whoa, you ranked them all! Incredible job, we thank you!';
    var PW_OPTIONAL_MESSAGE = 'Every matchup helps. Do as many as you like.';

    var seq = 0;                  // makes radio group names unique per render

    // ---------------------------------------------------------------- helpers

    function escapeHtml(s) {
        if (s === null || s === undefined) { return ''; }
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function isAnswerable(type) { return ANSWERABLE.indexOf(String(type)) !== -1; }
    function isBlock(type) { return BLOCKS.indexOf(String(type)) !== -1; }

    function num(v, fallback) {
        var n = Number(v);
        return isFinite(n) ? n : fallback;
    }

    function settingsOf(q) {
        var s = q && q.settings;
        if (typeof s === 'string') {
            try { s = JSON.parse(s); } catch (e) { s = null; }
        }
        return (s && typeof s === 'object') ? s : {};
    }

    /** Options of one role, in the order the server sent them. */
    function optionsOf(q, role) {
        var out = [], list = (q && q.options) || [], i, o;
        role = role || 'choice';
        for (i = 0; i < list.length; i++) {
            o = list[i];
            if (!o) { continue; }
            if (String(o.role || 'choice') === role) { out.push(o); }
        }
        return out;
    }

    function optionById(q, id) {
        var list = (q && q.options) || [], i;
        id = parseInt(id, 10);
        for (i = 0; i < list.length; i++) {
            if (list[i] && parseInt(list[i].option_id, 10) === id) { return list[i]; }
        }
        return null;
    }

    function isOther(opt) { return !!(opt && (opt.is_other === 1 || opt.is_other === true || opt.is_other === '1')); }

    /* Builder-authored HTML (help, section bodies, welcome/thanks copy) arrives
       as Parsedown safe-mode output, sanitised again by DOMPurify (both the
       runner and the builder load it) before it reaches innerHTML. Allowlist:
       an <img> keeps src only when it is one of this module's uploads on this
       origin, so survey copy cannot carry a remote tracking pixel; links get
       rel=noopener noreferrer + referrerpolicy=no-referrer. Fails CLOSED: with
       no DOMPurify (CDN blocked) the HTML is shown as escaped text. */
    var SURVEY_IMG_PATH = /\/assets\/survey(?:-test)?\/[A-Za-z0-9_-]+\.[A-Za-z0-9]{1,5}$/;
    var purifyHooked = false;

    function isSurveyImageSrc(src) {
        var u;
        try { u = new URL(String(src || ''), window.location.href); } catch (e) { return false; }
        // Host, not origin: stored URLs may be http:// on an https page (config
        // builds HTTP_ASSETS with a fixed scheme), which the browser upgrades.
        return (u.protocol === 'https:' || u.protocol === 'http:') &&
               u.host === window.location.host && !u.search && !u.hash &&
               SURVEY_IMG_PATH.test(u.pathname);
    }

    function hookPurify(P) {
        if (purifyHooked || typeof P.addHook !== 'function') { return; }
        purifyHooked = true;
        P.addHook('uponSanitizeAttribute', function (node, data) {
            if (node.nodeName === 'IMG' && (data.attrName === 'src' || data.attrName === 'srcset') &&
                !(data.attrName === 'src' && isSurveyImageSrc(data.attrValue))) {
                data.keepAttr = false;
            }
        });
        P.addHook('afterSanitizeAttributes', function (node) {
            if (node.nodeName === 'A') {
                node.setAttribute('rel', 'noopener noreferrer');
                node.setAttribute('referrerpolicy', 'no-referrer');
            }
        });
    }

    function sanitize(html) {
        var s = (html === null || html === undefined) ? '' : String(html);
        if (s === '') { return ''; }
        var P = window.DOMPurify;
        if (P && typeof P.sanitize === 'function' && typeof P.addHook === 'function') {
            hookPurify(P);
            return String(P.sanitize(s));
        }
        return escapeHtml(s);
    }

    /** Help HTML: prefer server-rendered help_html, fall back to escaped help_md. */
    function helpHtml(q) {
        if (q && typeof q.help_html === 'string' && q.help_html !== '') { return sanitize(q.help_html); }
        if (q && typeof q.help_md === 'string' && q.help_md !== '') {
            return '<p>' + escapeHtml(q.help_md).replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br>') + '</p>';
        }
        return '';
    }

    /* True on a touch screen. Guarded: the unit harnesses run this file under
       node with a DOM stub that has no matchMedia. */
    function coarsePointer() {
        try {
            return !!(typeof window !== 'undefined' && window.matchMedia &&
                      window.matchMedia('(pointer: coarse)').matches);
        } catch (e) {
            return false;
        }
    }

    /* One illustration's <img>. The server sends q.image = {url, small_url,
       width, height, small_width, small_height}; when it wrote a phone
       rendition we offer both in srcset, and when it did not (every image
       uploaded before renditions existed) we emit the master alone — never a
       srcset entry that would 404. Intrinsic width/height keep a late image
       from reflowing the page below it. */
    function imageTag(q, className, alt) {
        var info = (q && q.image) || null;
        var src = (info && info.url) || (q && q.image_url) || '';
        var w = info ? (parseInt(info.width, 10) || 0) : 0;
        var h = info ? (parseInt(info.height, 10) || 0) : 0;
        var sw = info ? (parseInt(info.small_width, 10) || 0) : 0;
        var html;
        if (!src) { return ''; }
        html = '<img class="' + className + '" src="' + escapeHtml(src) + '"';
        if (info && info.small_url && sw > 0 && w > sw) {
            html += ' srcset="' + escapeHtml(info.small_url) + ' ' + sw + 'w, ' +
                    escapeHtml(info.url) + ' ' + w + 'w"' +
                    ' sizes="(max-width: 680px) 100vw, 640px"';
        }
        if (w > 0 && h > 0) { html += ' width="' + w + '" height="' + h + '"'; }
        return html + ' loading="lazy" decoding="async" alt="' + escapeHtml(alt || '') + '">';
    }

    /** Split a raw choice value into {id, other}. Accepts 5, "5", {option_id:5, other:"x"}. */
    function splitChoice(value) {
        if (value === null || value === undefined || value === '') { return null; }
        if (typeof value === 'object') {
            if (!('option_id' in value)) { return null; }
            var id = parseInt(value.option_id, 10);
            if (!isFinite(id)) { return null; }
            return { id: id, other: value.other === undefined || value.other === null ? '' : String(value.other) };
        }
        var n = parseInt(value, 10);
        return isFinite(n) ? { id: n, other: '' } : null;
    }

    function attrIf(name, value) {
        return (value === null || value === undefined || value === '') ? '' : ' ' + name + '="' + escapeHtml(value) + '"';
    }

    // ---------------------------------------------------------- pairwise core

    /** SurveyTypes::pairwisePlan(), key for key (integer ceilings, never floats). */
    function pairwisePlan(optionCount) {
        var n = Math.max(0, parseInt(optionCount, 10) || 0);
        var possible = n < 2 ? 0 : n * (n - 1) / 2;
        var pcts = [], tiers = [], i;
        if (possible <= PW_SMALL_MAX) {
            return { possible: possible, small: true, band_pcts: [], tiers: [], gate: possible };
        }
        for (i = 0; i < PW_BANDS.length; i++) {
            if (PW_BANDS[i][0] === null || possible <= PW_BANDS[i][0]) { pcts = PW_BANDS[i][1].slice(); break; }
        }
        for (i = 0; i < pcts.length; i++) { tiers.push(Math.floor((pcts[i] * possible + 99) / 100)); }
        return { possible: possible, small: false, band_pcts: pcts, tiers: tiers, gate: tiers[0] };
    }

    /** SurveyTypes::pairwiseGateMessage(): what a required question says below its gate. */
    function pairwiseGateMessage(plan) {
        return plan.small
            ? 'Please finish all ' + plan.possible + ' matchups to continue.'
            : 'Please complete at least ' + plan.gate + ' matchups to continue.';
    }

    /**
     * Where a respondent stands (spec §6). level 0-4 picks the bar colour;
     * message is the line under the bar ('' for none); complete once every
     * matchup is judged. Small sets colour by thirds, larger ones by tier.
     */
    function pairwiseStage(plan, doneCount, required) {
        var k = Math.max(0, parseInt(doneCount, 10) || 0), level = 0, i, left;
        if (plan.possible > 0 && k >= plan.possible) {
            return { level: 4, message: PW_DONE_MESSAGE, complete: true };
        }
        if (plan.small) {
            level = k * 3 >= plan.possible * 2 ? 2 : (k * 3 >= plan.possible ? 1 : 0);
            return { level: level, message: required ? 'Finish all ' + plan.possible + ' matchups to continue.' : '', complete: false };
        }
        for (i = 0; i < plan.tiers.length; i++) { if (k >= plan.tiers[i]) { level = i + 1; } }
        if (level > 0) { return { level: level, message: PW_TIER_MESSAGES[level - 1], complete: false }; }
        left = plan.gate - k;
        return {
            level: 0,
            message: required
                ? left + (left === 1 ? ' more matchup' : ' more matchups') + ' to go before you can continue.'
                : PW_OPTIONAL_MESSAGE,
            complete: false
        };
    }

    function pairKey(a, b) {
        a = parseInt(a, 10); b = parseInt(b, 10);
        return a < b ? a + ':' + b : b + ':' + a;
    }

    function sharesOption(m, n) { return m.a === n.a || m.a === n.b || m.b === n.a || m.b === n.b; }

    /**
     * The matchups still to judge (spec §4): every unordered pair of `ids` not
     * already in `done`, Fisher-Yates shuffled with a coin toss for sides, then
     * one greedy pass that swaps a later pair forward when the next one would
     * repeat an option from the matchup before it. rand = null keeps authored
     * order and sides (the builder preview).
     */
    function pairwiseQueue(ids, done, rand) {
        var seen = {}, out = [], i, j, k, t, prev;
        (done || []).forEach(function (m) { seen[pairKey(m.a, m.b)] = true; });
        for (i = 0; i < ids.length; i++) {
            for (j = i + 1; j < ids.length; j++) {
                if (!seen[pairKey(ids[i], ids[j])]) { out.push({ a: ids[i], b: ids[j] }); }
            }
        }
        if (!rand) { return out; }
        for (i = out.length - 1; i > 0; i--) {
            j = Math.floor(rand() * (i + 1));
            t = out[i]; out[i] = out[j]; out[j] = t;
        }
        for (i = 0; i < out.length; i++) {
            if (rand() < 0.5) { t = out[i].a; out[i].a = out[i].b; out[i].b = t; }
        }
        prev = (done && done.length) ? done[done.length - 1] : null;
        for (i = 0; i < out.length; i++) {
            if (prev && sharesOption(out[i], prev)) {
                for (k = i + 1; k < out.length; k++) {
                    if (!sharesOption(out[k], prev)) { t = out[i]; out[i] = out[k]; out[k] = t; break; }
                }
            }
            prev = out[i];
        }
        return out;
    }

    // ------------------------------------------------------------- type bodies

    /* The instruction line a question shows above its control, or ''. It gets
       an id and joins the control's aria-describedby (with the help text and
       the error), so a screen reader hears the selection limit too. */
    function hintFor(q, type) {
        var s, minSel, maxSel;
        if (type === 'pairwise') {
            // The author's own help text already says what to do: one instruction
            // line, not two near-identical ones. And a touch device has no arrow
            // keys to hear about.
            if (helpHtml(q)) { return ''; }
            return 'Pick the one you prefer, or call it a tie.' +
                   (coarsePointer() ? '' : ' The arrow keys work too.');
        }
        if (type === 'ranking') { return 'Use the arrows or drag to put these in order.'; }
        if (type !== 'multi') { return ''; }
        s = settingsOf(q);
        minSel = num(s.min_select, 0);
        maxSel = num(s.max_select, 0);
        if (minSel > 1 && maxSel > 0) { return 'Choose between ' + minSel + ' and ' + maxSel + '.'; }
        if (maxSel > 0) { return 'Choose up to ' + maxSel + '.'; }
        if (minSel > 1) { return 'Choose at least ' + minSel + '.'; }
        return '';
    }

    function bodyChoices(q, state, ctx) {
        var multi = q.type === 'multi';
        var opts = optionsOf(q, 'choice');
        var selected = {};        // option_id -> other text ('' when none)
        var i, o, id, checked, oid, html = '';

        if (multi) {
            var arr = Array.isArray(state) ? state : (state === undefined || state === null || state === '' ? [] : [state]);
            for (i = 0; i < arr.length; i++) {
                var c = splitChoice(arr[i]);
                if (c) { selected[c.id] = c.other; }
            }
        } else {
            var one = splitChoice(state);
            if (one) { selected[one.id] = one.other; }
        }

        var inline = (q.type === 'yesno') ? ' sv-choices-inline' : '';
        html += ctx.hint ? '<div class="sv-choice-hint" id="' + ctx.hintId + '">' + escapeHtml(ctx.hint) + '</div>' : '';
        html += '<div class="sv-choices' + inline + '" role="' + (multi ? 'group' : 'radiogroup') +
                '" aria-labelledby="' + ctx.promptId + '"' + ctx.req + ctx.desc + '>';

        for (i = 0; i < opts.length; i++) {
            o = opts[i];
            id = parseInt(o.option_id, 10);
            oid = isOther(o);
            checked = Object.prototype.hasOwnProperty.call(selected, id);
            html += '<div class="sv-choice-wrap' + (oid ? ' sv-choice-wrap-other' : '') + '">';
            html += '<label class="sv-choice">';
            html += '<input type="' + (multi ? 'checkbox' : 'radio') + '" class="sv-choice-input" name="' +
                    ctx.name + '" value="' + id + '"' + (checked ? ' checked' : '') + ctx.tab + '>';
            html += '<span class="sv-choice-label">' + escapeHtml(o.label) + '</span>';
            html += '</label>';
            if (oid) {
                html += '<input type="text" class="sv-input sv-other-input" data-other-for="' + id +
                        '" maxlength="' + OTHER_MAX + '" placeholder="Please specify"' +
                        attrIf('value', checked ? selected[id] : '') + ctx.tab + '>';
            }
            html += '</div>';
        }
        html += '</div>';
        return html;
    }

    function bodyDropdown(q, state, ctx) {
        var opts = optionsOf(q, 'choice');
        var sel = splitChoice(state);
        var i, o, id, html = '';

        html += '<select class="sv-select" name="' + ctx.name + '"' + ctx.req + ctx.desc + ctx.tab + '>';
        html += '<option value="">— Select —</option>';
        for (i = 0; i < opts.length; i++) {
            o = opts[i];
            id = parseInt(o.option_id, 10);
            html += '<option value="' + id + '"' + (isOther(o) ? ' class="sv-opt-other"' : '') +
                    (sel && sel.id === id ? ' selected' : '') + '>' + escapeHtml(o.label) + '</option>';
        }
        html += '</select>';

        for (i = 0; i < opts.length; i++) {
            o = opts[i];
            if (!isOther(o)) { continue; }
            id = parseInt(o.option_id, 10);
            html += '<input type="text" class="sv-input sv-other-input" data-other-for="' + id +
                    '" maxlength="' + OTHER_MAX + '" placeholder="Please specify"' +
                    attrIf('value', sel && sel.id === id ? sel.other : '') + ctx.tab + '>';
        }
        return html;
    }

    function bodyScale(q, state, ctx) {
        var s = settingsOf(q);
        var nps = q.type === 'nps';
        var min = nps ? NPS_MIN : num(s.min, 1);
        var max = nps ? NPS_MAX : num(s.max, 5);
        var icon = nps ? 'number' : (s.icon === 'number' ? 'number' : 'star');
        var minLabel = nps ? (s.min_label === undefined ? 'Not likely' : s.min_label) : (s.min_label || '');
        var maxLabel = nps ? (s.max_label === undefined ? 'Very likely' : s.max_label) : (s.max_label || '');
        var cur = (state === null || state === undefined || state === '') ? null : num(state, null);
        var v, html = '';

        if (max < min) { max = min; }
        if (max - min > 100) { max = min + 100; }   // defensive: never render a runaway row

        // The end labels are the only thing that gives the numbers meaning, so
        // they are described by the group AND folded into the extreme options'
        // accessible names — a bare "0"/"10" tells a screen reader nothing.
        var endsId = ctx.promptId + '-ends';
        var hasEnds = !!(minLabel || maxLabel);

        html += '<div class="sv-scale-wrap">';
        html += '<div class="sv-scale' + (nps ? ' sv-scale-nps' : '') + ' sv-scale-' + icon +
                '" role="radiogroup" aria-labelledby="' + ctx.promptId + '"' + ctx.req +
                ' aria-describedby="' + (hasEnds ? endsId + ' ' : '') + ctx.descIds + '">';
        for (v = min; v <= max; v++) {
            var vLabel = v + ' of ' + max;
            if (v === min && minLabel) { vLabel += ', ' + minLabel; }
            if (v === max && maxLabel) { vLabel += ', ' + maxLabel; }
            html += '<label class="sv-scale-opt">';
            html += '<input type="radio" class="sv-scale-input" name="' + ctx.name + '" value="' + v + '"' +
                    (cur !== null && cur === v ? ' checked' : '') + ' aria-label="' + escapeHtml(vLabel) + '"' + ctx.tab + '>';
            html += '<span class="sv-scale-face">';
            if (icon === 'star') { html += '<i class="fas fa-star" aria-hidden="true"></i>'; }
            html += '<span class="sv-scale-num">' + v + '</span>';
            html += '</span></label>';
        }
        html += '</div>';
        if (hasEnds) {
            html += '<div class="sv-scale-ends" id="' + endsId + '">' +
                    '<span class="sv-scale-end sv-scale-end-min">' + escapeHtml(minLabel) + '</span>' +
                    '<span class="sv-scale-end sv-scale-end-max">' + escapeHtml(maxLabel) + '</span></div>';
        }
        if (!ctx.required) {
            html += '<button type="button" class="sv-scale-clear"' + ctx.tab + '>Clear</button>';
        }
        html += '</div>';
        return html;
    }

    function bodyMatrix(q, state, ctx) {
        var rows = optionsOf(q, 'row');
        var cols = optionsOf(q, 'column');
        var picked = {};
        var r, c, rowId, colId, key, html = '';

        if (state && typeof state === 'object' && !Array.isArray(state)) {
            for (key in state) {
                if (!Object.prototype.hasOwnProperty.call(state, key)) { continue; }
                var pv = parseInt(state[key], 10);
                if (isFinite(pv)) { picked[parseInt(key, 10)] = pv; }
            }
        }

        html += '<div class="sv-matrix-wrap" role="group" aria-labelledby="' + ctx.promptId + '"' +
                ctx.req + ctx.desc + '><table class="sv-matrix"><thead><tr>';
        html += '<th class="sv-matrix-corner"></th>';
        for (c = 0; c < cols.length; c++) {
            html += '<th class="sv-matrix-col" scope="col">' + escapeHtml(cols[c].label) + '</th>';
        }
        html += '</tr></thead><tbody>';
        for (r = 0; r < rows.length; r++) {
            rowId = parseInt(rows[r].option_id, 10);
            html += '<tr class="sv-matrix-row" data-row="' + rowId + '">';
            html += '<th class="sv-matrix-rowlabel" scope="row">' + escapeHtml(rows[r].label) + '</th>';
            for (c = 0; c < cols.length; c++) {
                colId = parseInt(cols[c].option_id, 10);
                html += '<td class="sv-matrix-cell" data-col="' + colId + '">';
                html += '<label class="sv-matrix-opt">';
                html += '<input type="radio" class="sv-matrix-input" name="' + ctx.name + '_r' + rowId +
                        '" value="' + colId + '"' + (picked[rowId] === colId ? ' checked' : '') +
                        ' aria-label="' + escapeHtml(rows[r].label + ': ' + cols[c].label) + '"' + ctx.tab + '>';
                html += '<span class="sv-matrix-echo">' + escapeHtml(cols[c].label) + '</span>';
                html += '</label></td>';
            }
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        return html;
    }

    function bodyRanking(q, state, ctx) {
        var opts = optionsOf(q, 'choice');
        var order = [], seen = {}, i, id, o, html = '';

        if (Array.isArray(state)) {
            for (i = 0; i < state.length; i++) {
                var c = splitChoice(state[i]);
                if (c && optionById(q, c.id) && !seen[c.id]) { seen[c.id] = true; order.push(optionById(q, c.id)); }
            }
        }
        for (i = 0; i < opts.length; i++) {
            id = parseInt(opts[i].option_id, 10);
            if (!seen[id]) { seen[id] = true; order.push(opts[i]); }
        }

        // An order the respondent never touched is NOT an answer: it is just
        // the order the list arrived in. data-touched is set by an arrow press,
        // a drag, or "Keep this order"; a stored answer (draft resume) means the
        // list was touched in an earlier sitting.
        var touched = Array.isArray(state) && state.length > 0;

        html += ctx.hint ? '<div class="sv-choice-hint" id="' + ctx.hintId + '">' + escapeHtml(ctx.hint) + '</div>' : '';
        // The group attributes go on a wrapper, not on the <ol>: role="group"
        // on the list itself would strip its list semantics ("list, 6 items"),
        // and aria-required is not valid on a list role.
        html += '<div class="sv-rank-group" role="group" aria-labelledby="' + ctx.promptId + '"' +
                ctx.req + ctx.desc + '>';
        html += '<ol class="sv-rank" data-qid="' + ctx.qid + '"' + (touched ? ' data-touched="1"' : '') + '>';
        for (i = 0; i < order.length; i++) {
            o = order[i];
            var first = i === 0;
            var last = i === order.length - 1;
            // Every item gets the SAME "Move up"/"Move down" name unless the
            // item's own label is folded in — a screen reader's button list is
            // otherwise N indistinguishable pairs.
            var moveUp = escapeHtml('Move ' + o.label + ' up');
            var moveDn = escapeHtml('Move ' + o.label + ' down');
            html += '<li class="sv-rank-item' + (first ? ' sv-rank-first' : '') + (last ? ' sv-rank-last' : '') +
                    '" data-option="' + parseInt(o.option_id, 10) + '" data-label="' + escapeHtml(o.label) + '">';
            html += '<span class="sv-rank-handle" aria-hidden="true"><i class="fas fa-grip-vertical"></i></span>';
            html += '<span class="sv-rank-pos">' + (i + 1) + '</span>';
            html += '<span class="sv-rank-label">' + escapeHtml(o.label) + '</span>';
            // aria-disabled, not disabled: the end buttons stay focusable, so
            // focus is not dropped when a move lands an item at either end.
            html += '<span class="sv-rank-btns">' +
                    '<button type="button" class="sv-rank-btn sv-rank-up" data-tip="Move up" aria-label="' + moveUp + '"' +
                    (first ? ' aria-disabled="true"' : '') + ctx.tab + '><i class="fas fa-chevron-up" aria-hidden="true"></i></button>' +
                    '<button type="button" class="sv-rank-btn sv-rank-down" data-tip="Move down" aria-label="' + moveDn + '"' +
                    (last ? ' aria-disabled="true"' : '') + ctx.tab + '><i class="fas fa-chevron-down" aria-hidden="true"></i></button>' +
                    '</span>';
            html += '</li>';
        }
        html += '</ol>';
        // A required ranking must be touched to count, so a respondent who
        // already agrees with the order needs a way to say so.
        if (ctx.required) {
            html += '<button type="button" class="sv-btn sv-rank-keep"' + (touched ? ' hidden' : '') + ctx.tab + '>' +
                    '<i class="fas fa-check" aria-hidden="true"></i> Keep this order</button>';
        }
        html += '</div>';
        return html;
    }

    // ------------------------------------------------------------- pairwise

    var PW = {};                  // data-pw key ('pwq' + question id) -> the live state of that pairwise question
    var PW_FLASH_MS = 140;        // how long the picked side stays lit before the next matchup

    function pwIds(q) {
        return optionsOf(q, 'choice').map(function (o) { return parseInt(o.option_id, 10); });
    }

    /** A restored answer, cleaned: known ids, a != b, w in {a, b, 0}, each pair once, order kept. */
    function pwClean(value, ids) {
        var known = {}, seen = {}, out = [];
        ids.forEach(function (id) { known[id] = true; });
        (Array.isArray(value) ? value : []).forEach(function (m) {
            var a, b, w, k;
            if (!m || typeof m !== 'object') { return; }
            a = parseInt(m.a, 10); b = parseInt(m.b, 10); w = parseInt(m.w, 10);
            if (!known[a] || !known[b] || a === b || !(w === a || w === b || w === 0)) { return; }
            k = pairKey(a, b);
            if (seen[k]) { return; }
            seen[k] = true;
            out.push({ a: a, b: b, w: w });
        });
        return out;
    }

    function pwState(el) { return el ? (PW[el.getAttribute('data-pw')] || null) : null; }

    /** Everything the widget shows, derived from its state (the string render and repaints share it). */
    function pwView(st) {
        var k = st.done.length, m = st.queue[0] || null;
        var stage = pairwiseStage(st.plan, k, st.required);
        return {
            labelA: m ? st.labels[m.a] : '',
            labelB: m ? st.labels[m.b] : '',
            pct: st.plan.possible ? Math.min(100, k / st.plan.possible * 100) : 0,
            count: k + ' of ' + st.plan.possible + ' matchups',
            level: stage.level,
            message: m ? stage.message : '',
            complete: !m
        };
    }

    function bodyPairwise(q, state, ctx) {
        var ids = pwIds(q), labels = {}, st, v, i, html;
        // Keyed by question id, not the per-render name: the runner redraws the
        // page on every Next / Back, and a fresh render must replace the old entry.
        var key = ctx.preview ? ctx.name : 'pwq' + ctx.qid;
        optionsOf(q, 'choice').forEach(function (o) { labels[parseInt(o.option_id, 10)] = String(o.label || ''); });
        st = {
            plan: (q.pairwise && typeof q.pairwise === 'object') ? q.pairwise : pairwisePlan(ids.length),
            required: ctx.required,
            labels: labels,
            done: pwClean(state, ids),
            queue: [],
            busy: false
        };
        st.queue = pairwiseQueue(ids, st.done, ctx.preview ? null : Math.random);
        // A preview only draws its first matchup and never takes input, so its
        // state is not kept: the builder redraws previews all session long.
        if (!ctx.preview) { PW[key] = st; }
        v = pwView(st);

        html = ctx.hint ? '<div class="sv-choice-hint" id="' + ctx.hintId + '">' + escapeHtml(ctx.hint) + '</div>' : '';
        html += '<div class="sv-pw" data-pw="' + key + '" role="group" aria-labelledby="' + ctx.promptId + '"' +
                ctx.req + ctx.desc + '>';
        html += '<div class="sv-pw-stage"' + (v.complete ? ' hidden' : '') + '>';
        html += '<button type="button" class="sv-pw-pick sv-pw-a" data-pw-pick="a"' + ctx.tab + '>' +
                '<span class="sv-pw-label">' + escapeHtml(v.labelA) + '</span></button>';
        html += '<button type="button" class="sv-pw-tie" data-pw-pick="tie"' + ctx.tab + '>Tie</button>';
        html += '<button type="button" class="sv-pw-pick sv-pw-b" data-pw-pick="b"' + ctx.tab + '>' +
                '<span class="sv-pw-label">' + escapeHtml(v.labelB) + '</span></button>';
        html += '</div>';
        html += '<p class="sv-pw-done" tabindex="-1"' + (v.complete ? '' : ' hidden') + '>' +
                '<i class="fas fa-trophy" aria-hidden="true"></i> ' + escapeHtml(PW_DONE_MESSAGE) + '</p>';
        html += '<div class="sv-pw-progress">';
        html += '<div class="sv-pw-bar" role="progressbar" aria-label="Matchups done" aria-valuemin="0" aria-valuemax="' +
                st.plan.possible + '" aria-valuenow="' + st.done.length + '" aria-valuetext="' + escapeHtml(v.count) +
                '" data-level="' + v.level + '">';
        html += '<span class="sv-pw-fill" style="width:' + v.pct.toFixed(2) + '%"></span>';
        for (i = 0; i < st.plan.tiers.length; i++) {
            html += '<span class="sv-pw-tick" aria-hidden="true" style="left:' +
                    (st.plan.tiers[i] / st.plan.possible * 100).toFixed(2) + '%"></span>';
        }
        html += '</div>';
        html += '<div class="sv-pw-meta"><span class="sv-pw-count" aria-hidden="true">' + escapeHtml(v.count) + '</span>' +
                '<button type="button" class="sv-pw-undo"' + (st.done.length ? '' : ' hidden') + ctx.tab + '>' +
                '<i class="fas fa-rotate-left" aria-hidden="true"></i> Undo</button></div>';
        html += '<p class="sv-pw-msg" aria-live="polite">' + escapeHtml(v.message) + '</p>';
        html += '</div></div>';
        return html;
    }

    /** Repaint in place: the buttons stay put, so focus never drops to <body> mid-question. */
    function pwPaint(el, st, moveFocus) {
        var v = pwView(st);
        var stage = el.querySelector('.sv-pw-stage');
        var done = el.querySelector('.sv-pw-done');
        var bar = el.querySelector('.sv-pw-bar');
        var undo = el.querySelector('.sv-pw-undo');
        var msg = el.querySelector('.sv-pw-msg');
        var active = document.activeElement;
        var wasHidden = stage.hidden;

        el.querySelector('.sv-pw-a .sv-pw-label').textContent = v.labelA;
        el.querySelector('.sv-pw-b .sv-pw-label').textContent = v.labelB;
        stage.hidden = v.complete;
        done.hidden = !v.complete;
        el.querySelector('.sv-pw-fill').style.width = v.pct.toFixed(2) + '%';
        bar.setAttribute('aria-valuenow', String(st.done.length));
        bar.setAttribute('aria-valuetext', v.count);
        bar.setAttribute('data-level', String(v.level));
        el.querySelector('.sv-pw-count').textContent = v.count;
        undo.hidden = st.done.length === 0;
        if (msg.textContent !== v.message) { msg.textContent = v.message; }

        if (!moveFocus) { return; }
        if (v.complete) {
            done.focus();
            return;
        }
        if (wasHidden || active === undo && undo.hidden) { el.querySelector('.sv-pw-a').focus(); }
        // The focused button's label changed under it; say the new matchup (the module's one polite region).
        rankLive(v.labelA + ' or ' + v.labelB + '?');
    }

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function pairwisePick(el, side) {
        var st = pwState(el), m, stage;
        if (!st || st.busy || !st.queue.length) { return; }
        m = st.queue.shift();
        st.done.push({ a: m.a, b: m.b, w: side === 'a' ? m.a : (side === 'b' ? m.b : 0) });
        fireChange(el);                       // recorded now, whatever the animation does
        // The busy window holds on both paths, so a double click never lands
        // on the matchup that just appeared; reduced motion only drops the flash.
        st.busy = true;
        if (reducedMotion()) {
            pwPaint(el, st, true);
            window.setTimeout(function () { st.busy = false; }, PW_FLASH_MS);
            return;
        }
        stage = el.querySelector('.sv-pw-stage');
        stage.setAttribute('data-chose', side);
        window.setTimeout(function () {
            st.busy = false;
            stage.removeAttribute('data-chose');
            pwPaint(el, st, true);
        }, PW_FLASH_MS);
    }

    function pairwiseUndo(el) {
        var st = pwState(el), last;
        if (!st || st.busy || !st.done.length) { return; }
        last = st.done.pop();
        st.queue.unshift({ a: last.a, b: last.b });
        fireChange(el);
        pwPaint(el, st, true);
    }

    function readPairwise(root) {
        var st = pwState(root.querySelector('.sv-pw'));
        if (!st || !st.done.length) { return undefined; }
        return st.done.map(function (m) { return { a: m.a, b: m.b, w: m.w }; });
    }

    function writePairwise(root, q, value) {
        var el = root.querySelector('.sv-pw'), st = pwState(el), ids = pwIds(q);
        if (!st) { return; }
        st.done = pwClean(value, ids);
        st.queue = pairwiseQueue(ids, st.done, root.classList.contains('sv-q-preview') ? null : Math.random);
        pwPaint(el, st, false);
    }

    function bodyText(q, state, ctx) {
        var s = settingsOf(q);
        var v = (state === null || state === undefined) ? '' : String(state);
        if (q.type === 'paragraph') {
            return '<textarea class="sv-textarea" rows="4"' +
                   attrIf('maxlength', num(s.max_length, 4000)) +
                   attrIf('placeholder', s.placeholder || '') +
                   ' aria-labelledby="' + ctx.promptId + '"' + ctx.req + ctx.desc + ctx.tab + '>' + escapeHtml(v) + '</textarea>';
        }
        return '<input type="text" class="sv-input"' +
               attrIf('maxlength', num(s.max_length, 200)) +
               attrIf('placeholder', s.placeholder || '') +
               attrIf('value', v) +
               ' aria-labelledby="' + ctx.promptId + '"' + ctx.req + ctx.desc + ctx.tab + '>';
    }

    function bodyNumber(q, state, ctx) {
        var s = settingsOf(q);
        var v = (state === null || state === undefined) ? '' : String(state);
        var html = '<div class="sv-number-wrap"><input type="number" inputmode="decimal" class="sv-input sv-input-number"' +
                   (s.min === null || s.min === undefined || s.min === '' ? '' : attrIf('min', s.min)) +
                   (s.max === null || s.max === undefined || s.max === '' ? '' : attrIf('max', s.max)) +
                   attrIf('step', s.step === null || s.step === undefined || s.step === '' ? 1 : s.step) +
                   attrIf('value', v) +
                   ' aria-labelledby="' + ctx.promptId + '"' + ctx.req + ctx.desc + ctx.tab + '>';
        if (s.unit) { html += '<span class="sv-unit">' + escapeHtml(s.unit) + '</span>'; }
        html += '</div>';
        return html;
    }

    function bodyDate(q, state, ctx) {
        var s = settingsOf(q);
        var v = (state === null || state === undefined) ? '' : String(state);
        return '<input type="date" class="sv-input sv-input-date"' +
               (s.min ? attrIf('min', s.min) : '') +
               (s.max ? attrIf('max', s.max) : '') +
               attrIf('value', v) +
               ' aria-labelledby="' + ctx.promptId + '"' + ctx.req + ctx.desc + ctx.tab + '>';
    }

    /*
     * Date questions render a native <input type="date"> (the fallback when
     * Flatpickr is absent, e.g. the builder preview). enhanceDates() swaps each
     * one on a desktop browser for a Flatpickr altInput that shows a readable
     * date ("September 25, 2026") while the original input — now hidden — keeps
     * the ISO Y-m-d value that readDate() returns. On touch devices Flatpickr
     * would substitute a native picker anyway, so the native input is kept as is.
     */
    var FP_MOBILE_UA = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i;
    var FP_COPY_ATTRS = ['aria-labelledby', 'aria-describedby', 'aria-required', 'aria-invalid', 'tabindex'];

    function enhanceDates(scope) {
        var inputs, i, el, j, alt;
        if (!scope || typeof window.flatpickr !== 'function') { return; }
        if (FP_MOBILE_UA.test(navigator.userAgent || '')) { return; }
        inputs = scope.querySelectorAll('input.sv-input-date');
        for (i = 0; i < inputs.length; i++) {
            el = inputs[i];
            if (el._flatpickr) { continue; }
            window.flatpickr(el, {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'F j, Y',
                altInputClass: 'sv-input sv-input-date-alt',
                allowInput: true,
                disableMobile: true,
                minDate: el.getAttribute('min') || null,
                maxDate: el.getAttribute('max') || null
            });
            alt = el._flatpickr && el._flatpickr.altInput;
            if (!alt) { continue; }
            // The visible field carries the name, description and error wiring
            // the native input had.
            for (j = 0; j < FP_COPY_ATTRS.length; j++) {
                if (el.hasAttribute(FP_COPY_ATTRS[j])) {
                    alt.setAttribute(FP_COPY_ATTRS[j], el.getAttribute(FP_COPY_ATTRS[j]));
                }
            }
            alt.setAttribute('autocomplete', 'off');
            alt.setAttribute('placeholder', 'Month day, year');
        }
    }

    // --------------------------------------------------------------- rendering

    function block(q) {
        q = q || {};
        var type = String(q.type || 'section');
        var qid = parseInt(q.question_id, 10) || 0;
        var help = helpHtml(q);
        var s = settingsOf(q);
        var html = '<div class="sv-q sv-q-' + escapeHtml(type) + ' sv-block" data-qid="' + qid +
                   '" data-type="' + escapeHtml(type) + '" data-required="0">';

        if (type === 'image') {
            html += '<figure class="sv-figure">';
            if (q.image_url) {
                html += imageTag(q, 'sv-figure-img', q.prompt || '');
            } else {
                html += '<div class="sv-figure-empty">No image selected</div>';
            }
            if (s.caption) { html += '<figcaption class="sv-figure-caption">' + escapeHtml(s.caption) + '</figcaption>'; }
            html += '</figure>';
        } else {
            if (q.prompt) { html += '<h3 class="sv-block-title">' + escapeHtml(q.prompt) + '</h3>'; }
            if (help) { html += '<div class="sv-block-body">' + help + '</div>'; }
            if (q.image_url) {
                html += '<div class="sv-q-image">' + imageTag(q, 'sv-q-image-img', '') + '</div>';
            }
        }
        html += '</div>';
        return html;
    }

    function question(q, state, mode) {
        q = q || {};
        var type = String(q.type || '');
        if (isBlock(type)) { return block(q); }

        var qid = parseInt(q.question_id, 10) || 0;
        var preview = mode === 'preview';
        var required = !!(q.required === 1 || q.required === true || q.required === '1');
        var ctx = {
            qid: qid,
            name: 'svq' + (++seq) + '_' + qid,
            promptId: 'sv-p-' + qid + '-' + seq,
            tab: preview ? ' tabindex="-1"' : '',
            preview: preview,
            required: required
        };
        // Requiredness, the help text, the instruction hint and the validation
        // message must all be exposed programmatically, not only drawn near the
        // control: every body renderer stamps ctx.req + ctx.desc onto its
        // control (or, for the composite types, onto the group wrapper), and
        // ctx.desc points at help + hint + error, in reading order.
        var help = helpHtml(q);
        ctx.hint = hintFor(q, type);
        ctx.helpId = help ? 'sv-h-' + qid + '-' + seq : '';
        ctx.hintId = ctx.hint ? 'sv-hint-' + qid + '-' + seq : '';
        ctx.errId = 'sv-e-' + qid + '-' + seq;
        ctx.req = required ? ' aria-required="true"' : '';
        ctx.descIds = [ctx.helpId, ctx.hintId, ctx.errId].filter(function (x) { return x !== ''; }).join(' ');
        ctx.desc = ' aria-describedby="' + ctx.descIds + '"';

        var body;
        switch (type) {
            case 'single':
            case 'yesno':
            case 'multi':     body = bodyChoices(q, state, ctx); break;
            case 'dropdown':  body = bodyDropdown(q, state, ctx); break;
            case 'rating':
            case 'nps':       body = bodyScale(q, state, ctx); break;
            case 'matrix':    body = bodyMatrix(q, state, ctx); break;
            case 'ranking':   body = bodyRanking(q, state, ctx); break;
            case 'pairwise':  body = bodyPairwise(q, state, ctx); break;
            case 'short_text':
            case 'paragraph': body = bodyText(q, state, ctx); break;
            case 'number':    body = bodyNumber(q, state, ctx); break;
            case 'date':      body = bodyDate(q, state, ctx); break;
            default:
                body = '<div class="sv-notice">Unsupported question type.</div>';
        }

        var html = '<div class="sv-q sv-q-' + escapeHtml(type || 'unknown') + (preview ? ' sv-q-preview' : '') +
                   '" data-qid="' + qid + '" data-type="' + escapeHtml(type) + '" data-required="' + (required ? 1 : 0) + '">';
        html += '<div class="sv-q-prompt" id="' + ctx.promptId + '">' + escapeHtml(q.prompt || '') +
                (required ? '<span class="sv-q-required" aria-hidden="true">*</span>' +
                            '<span class="sv-visually-hidden"> (required)</span>' : '') + '</div>';
        if (help) { html += '<div class="sv-q-help" id="' + ctx.helpId + '">' + help + '</div>'; }
        if (q.image_url) {
            html += '<div class="sv-q-image">' + imageTag(q, 'sv-q-image-img', '') + '</div>';
        }
        html += '<div class="sv-q-body">' + body + '</div>';
        html += '<div class="sv-q-error" role="alert" id="' + ctx.errId + '" hidden></div>';
        html += '</div>';
        return html;
    }

    // ------------------------------------------------------------------- read

    function otherTextFor(root, id) {
        var el = root.querySelector('.sv-other-input[data-other-for="' + id + '"]');
        return el ? String(el.value || '') : '';
    }

    function readChoice(root, q, multi) {
        var inputs = root.querySelectorAll('.sv-choice-input'), i, id, opt, out = [];
        for (i = 0; i < inputs.length; i++) {
            if (!inputs[i].checked) { continue; }
            id = parseInt(inputs[i].value, 10);
            opt = optionById(q, id);
            out.push(isOther(opt) ? { option_id: id, other: otherTextFor(root, id) } : id);
        }
        if (!out.length) { return undefined; }
        return multi ? out : out[0];
    }

    function readDropdown(root, q) {
        var sel = root.querySelector('.sv-select');
        if (!sel || sel.value === '') { return undefined; }
        var id = parseInt(sel.value, 10);
        if (!isFinite(id)) { return undefined; }
        var opt = optionById(q, id);
        return isOther(opt) ? { option_id: id, other: otherTextFor(root, id) } : id;
    }

    function readScale(root) {
        var inputs = root.querySelectorAll('.sv-scale-input'), i;
        for (i = 0; i < inputs.length; i++) {
            if (inputs[i].checked) { return Number(inputs[i].value); }
        }
        return undefined;
    }

    function readMatrix(root) {
        var rows = root.querySelectorAll('.sv-matrix-row'), i, checked, out = {}, any = false;
        for (i = 0; i < rows.length; i++) {
            checked = rows[i].querySelector('.sv-matrix-input:checked');
            if (!checked) { continue; }
            out[parseInt(rows[i].getAttribute('data-row'), 10)] = parseInt(checked.value, 10);
            any = true;
        }
        return any ? out : undefined;
    }

    function readRanking(root) {
        var ol = root.querySelector('.sv-rank');
        // Untouched = unanswered: the arrival order is not the respondent's vote.
        if (!ol || !ol.hasAttribute('data-touched')) { return undefined; }
        var items = ol.querySelectorAll('.sv-rank-item'), i, out = [];
        for (i = 0; i < items.length; i++) {
            out.push(parseInt(items[i].getAttribute('data-option'), 10));
        }
        return out.length ? out : undefined;
    }

    function readText(root, q) {
        var el = root.querySelector(q.type === 'paragraph' ? '.sv-textarea' : '.sv-input');
        if (!el) { return undefined; }
        var v = String(el.value || '').trim();
        return v === '' ? undefined : v;
    }

    function readNumber(root) {
        var el = root.querySelector('.sv-input-number');
        if (!el) { return undefined; }
        var v = String(el.value || '').trim();
        if (v === '') { return undefined; }
        var n = Number(v);
        return isFinite(n) ? n : v;
    }

    function readDate(root) {
        var el = root.querySelector('.sv-input-date');
        if (!el) { return undefined; }
        var v = String(el.value || '').trim();
        return v === '' ? undefined : v;
    }

    function read(root, q) {
        if (!root || !q) { return undefined; }
        var type = String(q.type || root.getAttribute('data-type') || '');
        if (!isAnswerable(type)) { return undefined; }
        switch (type) {
            case 'single':
            case 'yesno':     return readChoice(root, q, false);
            case 'multi':     return readChoice(root, q, true);
            case 'dropdown':  return readDropdown(root, q);
            case 'rating':
            case 'nps':       return readScale(root);
            case 'matrix':    return readMatrix(root);
            case 'ranking':   return readRanking(root);
            case 'pairwise':  return readPairwise(root);
            case 'short_text':
            case 'paragraph': return readText(root, q);
            case 'number':    return readNumber(root);
            case 'date':      return readDate(root);
        }
        return undefined;
    }

    // ------------------------------------------------------------------ write

    function clearOthers(root) {
        var others = root.querySelectorAll('.sv-other-input'), i;
        for (i = 0; i < others.length; i++) { others[i].value = ''; }
    }

    function writeChoice(root, q, value, multi) {
        var inputs = root.querySelectorAll('.sv-choice-input'), i, id;
        var picked = {};
        var list = multi
            ? (Array.isArray(value) ? value : (value === undefined || value === null || value === '' ? [] : [value]))
            : [value];

        for (i = 0; i < list.length; i++) {
            var c = splitChoice(list[i]);
            if (c) { picked[c.id] = c.other; }
        }
        clearOthers(root);
        for (i = 0; i < inputs.length; i++) {
            id = parseInt(inputs[i].value, 10);
            inputs[i].checked = Object.prototype.hasOwnProperty.call(picked, id);
            if (inputs[i].checked && picked[id]) {
                var box = root.querySelector('.sv-other-input[data-other-for="' + id + '"]');
                if (box) { box.value = picked[id]; }
            }
        }
    }

    function writeDropdown(root, q, value) {
        var sel = root.querySelector('.sv-select');
        if (!sel) { return; }
        var c = splitChoice(value);
        clearOthers(root);
        sel.value = c ? String(c.id) : '';
        if (c && c.other) {
            var box = root.querySelector('.sv-other-input[data-other-for="' + c.id + '"]');
            if (box) { box.value = c.other; }
        }
    }

    function writeScale(root, value) {
        var inputs = root.querySelectorAll('.sv-scale-input'), i;
        var v = (value === null || value === undefined || value === '') ? null : String(num(value, ''));
        for (i = 0; i < inputs.length; i++) { inputs[i].checked = (v !== null && inputs[i].value === v); }
    }

    function writeMatrix(root, value) {
        var rows = root.querySelectorAll('.sv-matrix-row'), i, j, rowId, want, inputs;
        var map = (value && typeof value === 'object' && !Array.isArray(value)) ? value : {};
        for (i = 0; i < rows.length; i++) {
            rowId = parseInt(rows[i].getAttribute('data-row'), 10);
            want = Object.prototype.hasOwnProperty.call(map, rowId) ? String(parseInt(map[rowId], 10)) : null;
            inputs = rows[i].querySelectorAll('.sv-matrix-input');
            for (j = 0; j < inputs.length; j++) { inputs[j].checked = (want !== null && inputs[j].value === want); }
        }
    }

    function writeRanking(root, value) {
        var ol = root.querySelector('.sv-rank');
        if (!ol) { return; }
        if (!Array.isArray(value) || !value.length) {
            untouchRank(ol);
            return;
        }
        var i, id, item;
        for (i = 0; i < value.length; i++) {
            var c = splitChoice(value[i]);
            if (!c) { continue; }
            id = c.id;
            item = ol.querySelector('.sv-rank-item[data-option="' + id + '"]');
            if (item) { ol.appendChild(item); }     // append in requested order
        }
        reindexRank(ol);
        touchRank(ol);
    }

    function write(root, q, value) {
        if (!root || !q) { return; }
        var type = String(q.type || root.getAttribute('data-type') || '');
        var el;
        switch (type) {
            case 'single':
            case 'yesno':     writeChoice(root, q, value, false); break;
            case 'multi':     writeChoice(root, q, value, true); break;
            case 'dropdown':  writeDropdown(root, q, value); break;
            case 'rating':
            case 'nps':       writeScale(root, value); break;
            case 'matrix':    writeMatrix(root, value); break;
            case 'ranking':   writeRanking(root, value); break;
            case 'pairwise':  writePairwise(root, q, value); break;
            case 'short_text':
            case 'number':
            case 'date':
                el = root.querySelector('.sv-input');
                if (el && el._flatpickr) {
                    if (value === null || value === undefined || value === '') { el._flatpickr.clear(false); } else { el._flatpickr.setDate(String(value), false); }
                } else if (el) { el.value = (value === null || value === undefined) ? '' : String(value); }
                break;
            case 'paragraph':
                el = root.querySelector('.sv-textarea');
                if (el) { el.value = (value === null || value === undefined) ? '' : String(value); }
                break;
        }
    }

    // --------------------------------------------------------------- setError

    // The control (or group wrapper) that carries aria-describedby to the error
    // box, so aria-invalid lands on the same element the reader is focused in.
    var INVALID_TARGETS = '.sv-choices, .sv-select, .sv-scale, .sv-matrix-wrap, .sv-rank-group, .sv-pw, .sv-input:not(.sv-other-input), .sv-textarea';

    function setError(root, message) {
        if (!root) { return; }
        var box = root.querySelector('.sv-q-error');
        if (!box) {
            box = document.createElement('div');
            box.className = 'sv-q-error';
            box.setAttribute('role', 'alert');
            root.appendChild(box);
        }
        var targets = root.querySelectorAll(INVALID_TARGETS), i;
        if (message === null || message === undefined || message === '') {
            box.textContent = '';
            box.hidden = true;
            root.classList.remove('sv-q-invalid');
            for (i = 0; i < targets.length; i++) { targets[i].removeAttribute('aria-invalid'); }
        } else {
            box.textContent = String(message);
            box.hidden = false;
            root.classList.add('sv-q-invalid');
            for (i = 0; i < targets.length; i++) { targets[i].setAttribute('aria-invalid', 'true'); }
        }
    }

    // ------------------------------------------------------- ranking controls

    function setDisabled(btn, off) {
        if (!btn) { return; }
        if (off) { btn.setAttribute('aria-disabled', 'true'); } else { btn.removeAttribute('aria-disabled'); }
    }

    function reindexRank(ol) {
        if (!ol) { return; }
        var items = ol.querySelectorAll('.sv-rank-item'), i, pos, first, last;
        for (i = 0; i < items.length; i++) {
            first = i === 0;
            last = i === items.length - 1;
            pos = items[i].querySelector('.sv-rank-pos');
            if (pos) { pos.textContent = String(i + 1); }
            items[i].classList.toggle('sv-rank-first', first);
            items[i].classList.toggle('sv-rank-last', last);
            setDisabled(items[i].querySelector('.sv-rank-up'), first);
            setDisabled(items[i].querySelector('.sv-rank-down'), last);
        }
    }

    /** Mark a ranking as answered: from here on read() returns its order. */
    function touchRank(ol) {
        if (!ol) { return; }
        ol.setAttribute('data-touched', '1');
        var group = ol.closest ? ol.closest('.sv-rank-group') : null;
        var keep = group ? group.querySelector('.sv-rank-keep') : null;
        if (keep) { keep.hidden = true; }
    }

    function untouchRank(ol) {
        if (!ol) { return; }
        ol.removeAttribute('data-touched');
        var group = ol.closest ? ol.closest('.sv-rank-group') : null;
        var keep = group ? group.querySelector('.sv-rank-keep') : null;
        if (keep) { keep.hidden = false; }
    }

    function rankLive(msg) {
        var live = document.getElementById('sv-rank-live');
        if (!live) {
            live = document.createElement('div');
            live.id = 'sv-rank-live';
            live.className = 'sv-visually-hidden';
            live.setAttribute('role', 'status');
            live.setAttribute('aria-live', 'polite');
            document.body.appendChild(live);
        }
        live.textContent = msg;
    }

    // A reorder only rewrites a position badge, which is not part of any
    // control's name — without this the refocused button just says "Move X up"
    // again and the user has no idea whether anything moved.
    function announceRank(item, ol) {
        if (!item || !ol) { return; }
        var items = ol.querySelectorAll('.sv-rank-item');
        var pos = Array.prototype.indexOf.call(items, item) + 1;
        var label = item.getAttribute('data-label') || '';
        rankLive(label + ', position ' + pos + ' of ' + items.length + '.');
    }

    function fireChange(el) {
        var ev;
        try {
            ev = new Event('change', { bubbles: true });
        } catch (e) {
            ev = document.createEvent('Event');
            ev.initEvent('change', true, false);
        }
        el.dispatchEvent(ev);
    }

    function onDocClick(e) {
        var t = e.target;
        if (!t || !t.closest) { return; }

        var pwBtn = t.closest('[data-pw-pick], .sv-pw-undo');
        if (pwBtn) {
            var pw = pwBtn.closest('.sv-pw');
            if (pw && !pw.closest('.sv-q-preview')) {
                if (pwBtn.classList.contains('sv-pw-undo')) {
                    pairwiseUndo(pw);
                } else {
                    pairwisePick(pw, pwBtn.getAttribute('data-pw-pick'));
                }
            }
            e.preventDefault();
            return;
        }

        var rankBtn = t.closest('.sv-rank-up, .sv-rank-down');
        if (rankBtn) {
            var item = rankBtn.closest('.sv-rank-item');
            var ol = rankBtn.closest('.sv-rank');
            // An end button (aria-disabled) moves nothing and answers nothing.
            if (item && ol && !ol.closest('.sv-q-preview') && rankBtn.getAttribute('aria-disabled') !== 'true') {
                if (rankBtn.classList.contains('sv-rank-up')) {
                    if (item.previousElementSibling) { ol.insertBefore(item, item.previousElementSibling); }
                } else if (item.nextElementSibling) {
                    ol.insertBefore(item.nextElementSibling, item);
                }
                reindexRank(ol);
                touchRank(ol);
                fireChange(ol);
                rankBtn.focus();
                announceRank(item, ol);
            }
            e.preventDefault();
            return;
        }

        var keep = t.closest('.sv-rank-keep');
        if (keep) {
            var group = keep.closest('.sv-rank-group');
            var list = group ? group.querySelector('.sv-rank') : null;
            if (list && !keep.closest('.sv-q-preview')) {
                touchRank(list);
                // The button just hid itself from under focus; land on the list
                // it answered for rather than dropping the user at <body>.
                list.setAttribute('tabindex', '-1');
                try { list.focus({ preventScroll: true }); } catch (err) { list.focus(); }
                fireChange(list);
                rankLive('Order kept.');
            }
            e.preventDefault();
            return;
        }

        var clear = t.closest('.sv-scale-clear');
        if (clear) {
            var wrap = clear.closest('.sv-scale-wrap');
            if (wrap && !clear.closest('.sv-q-preview')) {
                var inputs = wrap.querySelectorAll('.sv-scale-input'), i;
                for (i = 0; i < inputs.length; i++) { inputs[i].checked = false; }
                fireChange(wrap);
            }
            e.preventDefault();
        }
    }

    /* ← picks left, → picks right, ↓ ties: only while focus is on the matchup
       itself, so arrow keys never get taken from a text field or the page. */
    function onDocKeydown(e) {
        var t = e.target, side, pw;
        if (!t || !t.closest || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
        if (!t.closest('.sv-pw-stage')) { return; }
        side = e.key === 'ArrowLeft' ? 'a' : (e.key === 'ArrowRight' ? 'b' : (e.key === 'ArrowDown' ? 'tie' : null));
        if (!side) { return; }
        pw = t.closest('.sv-pw');
        if (!pw || pw.closest('.sv-q-preview')) { return; }
        e.preventDefault();
        // A held key auto-repeats; only a fresh press picks, so no matchup is
        // decided before the respondent has read it.
        if (e.repeat) { return; }
        pairwisePick(pw, side);
    }

    document.addEventListener('click', onDocClick, false);
    document.addEventListener('keydown', onDocKeydown, false);

    // ------------------------------------------------------------------ export

    window.SvRender = {
        question: question,
        block: block,
        read: read,
        write: write,
        setError: setError,
        enhanceDates: enhanceDates,
        escape: escapeHtml,
        md: sanitize,
        sanitize: sanitize,
        isAnswerable: isAnswerable,
        reindexRank: reindexRank,
        touchRank: touchRank,
        ANSWERABLE: ANSWERABLE,
        OTHER_MAX: OTHER_MAX,
        pairwisePlan: pairwisePlan,
        pairwiseGateMessage: pairwiseGateMessage,
        pairwiseStage: pairwiseStage,
        pairwiseQueue: pairwiseQueue,
        pairKey: pairKey,
        PW_SMALL_MAX: PW_SMALL_MAX,
        PW_BANDS: PW_BANDS,
        PW_TIER_MESSAGES: PW_TIER_MESSAGES,
        PW_DONE_MESSAGE: PW_DONE_MESSAGE,
        PW_OPTIONAL_MESSAGE: PW_OPTIONAL_MESSAGE
    };
}(window, document));
