/*
  survey-credit.js — the Bonus credit modal (sharing-and-credits spec §3.6).
  Shared by the survey list and the builder. Configured by
  window.SvCreditConfig = {uir, csrf}; opened with
  SvCredit.open({surveyId, grantor, title, onChange}); onChange(on) runs after
  "Turn on credit" or "Turn off credit" succeeds for the grantor's own org
  (the panel's automatic reconcile posts credits owed under existing switches,
  possibly another org's, and changes no one's on/off state). SvCredit.status(
  surveyId, grantor) reads the state quietly for a host page's label. No native
  dialogs; focus is trapped while open and restored on close.
*/
(function () {
    'use strict';

    var CFG = window.SvCreditConfig || {};
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

    var ov, body, enableBtn, disableBtn, closeBtn;
    var current = null;      // {surveyId, grantor, title, onChange}
    var data = null;         // last credit_status payload
    var lastFocus = null;
    var reconciled = false;  // one automatic reconcile per open

    function $(id) { return document.getElementById(id); }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function longDate(ymd) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
        return m ? MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1] : '';
    }

    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    /* A web request posts a bounded batch; the hourly sweep posts the rest. */
    function moreText(remaining) {
        return (remaining | 0) > 0 ? ' ' + (remaining | 0) + ' more will post within the hour.' : '';
    }

    function post(action, fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch(CFG.uir + 'SurveyAjax/' + action, {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': CFG.csrf || '' }
        })
            .then(function (r) { return r.json(); })
            .catch(function () { return { status: 1, error: 'Could not reach the server. Try again.' }; });
    }

    function errorHtml(j) {
        if (j && j.csrf) {
            return '<div class="sv-notice sv-notice-error" role="alert">Your security token expired. ' +
                '<a href="" class="sv-notice-link" data-sv-credit-reload>Reload the page</a> and try again.</div>';
        }
        return '<div class="sv-notice sv-notice-error" role="alert">' + esc((j && j.error) || 'Something went wrong.') + '</div>';
    }

    function configLine(c) {
        var when = c.enabled
            ? 'on since ' + esc(longDate(c.enabled_at))
            : 'on from ' + esc(longDate(c.enabled_at)) + ' to ' + esc(longDate(c.disabled_at)) + ', now off';
        return '<li' + (c.enabled ? '' : ' class="sv-credit-off"') + '><strong>' + esc(c.grantor_name) + '</strong>: ' + when +
            ' (' + plural(c.granted | 0, 'credit', 'credits') + ')</li>';
    }

    function warningText() {
        var p = data.mine.preview || { eligible_now: 0, no_notice: 0 };
        var t = plural(p.eligible_now | 0, 'player who already chose Any ORK Data will', 'players who already chose Any ORK Data will') +
            ' get a credit now. ';
        if (data.survey_status === 'open' || data.survey_status === 'draft') {
            t += 'While this survey is open, everyone who completes it with Any ORK Data and is covered by this credit gets one automatically. ';
        }
        t += 'The credit counts toward class levels only, dated the day they answered. You can turn it off later; credits already given stay unless an officer removes them one at a time.';
        // Spec D1: a credit is dated and visible, so nobody gets one who chose
        // Any ORK Data before the survey said anything about credits.
        if ((p.no_notice | 0) > 0) {
            t += ' ' + plural(p.no_notice | 0, 'player', 'players') +
                ' chose Any ORK Data before this survey said anything about credits, so they won’t get one.';
        }
        return t;
    }

    function categoryLine() {
        if (data.category) {
            var where = data.category.scope_type === 'ork' ? 'an Amtgard-wide credit' : esc(data.category.scope_name) + '’s credit';
            return '<p class="sv-credit-sub">Credit category: <strong>' + esc(data.category.name) + '</strong> (' + where +
                (data.category.archived ? ', archived' : '') + '). Officers can also grant or remove it by hand under Bonus Credits.</p>';
        }
        return '<p class="sv-credit-sub">Turning credit on creates the category <strong>' + esc(data.category_name) +
            '</strong> under Bonus Credits, worth 1 credit per player.</p>';
    }

    function render(extraHtml) {
        var html = extraHtml || '';
        var mine = data.mine;

        html += '<p class="sv-credit-lead"><strong>' + esc(current.title || data.survey_title) + '</strong></p>';
        html += categoryLine();
        if (data.configs.length) {
            html += '<p class="sv-credit-sub">This survey’s credit switches</p><ul class="sv-credit-list">' +
                data.configs.map(configLine).join('') + '</ul>';
        }
        if ((data.held | 0) > 0) {
            html += '<p class="sv-credit-sub">' + esc(plural(data.held | 0, 'credit is', 'credits are')) +
                ' on hold for banned or suspended players and will post once the sanction lifts.</p>';
        }

        enableBtn.hidden = true;
        enableBtn.disabled = true;
        disableBtn.hidden = true;
        disableBtn.disabled = true;

        if (!mine) {
            if (!data.configs.length) {
                html += '<p>No bonus credit yet. Kingdoms and parks turn it on for their own players from their survey list.</p>';
            }
        } else if (mine.enabled) {
            html += '<p>Credit is on for <strong>' + esc(mine.name) + '</strong>. Turning it off stops credit for responses submitted after that; ' +
                'anyone who answered while it was on still gets theirs.</p>';
            disableBtn.hidden = false;
            disableBtn.disabled = !mine.can_disable;
        } else if (!data.gate_enabled || !mine.can_enable) {
            html += '<div class="sv-notice sv-notice-warn">' + esc(mine.blocked_reason) + '</div>';
        } else {
            if (mine.covered_by) {
                html += '<p class="sv-credit-sub">Your players are already covered by ' + esc(mine.covered_by.name) +
                    '’s credit. Turning one on here only reaches players it does not cover.</p>';
            }
            html += '<p class="sv-credit-warning" id="sv-credit-warning">Give <strong>' + esc(mine.name) + '</strong> players the credit. ' + esc(warningText()) + '</p>';
            enableBtn.hidden = false;
            enableBtn.disabled = false;
        }
        body.innerHTML = html;
    }

    /* A re-render can take away the control that had focus (Turn on credit is
       disabled while it posts, then hidden once credit is on), which drops
       focus to <body> behind an aria-modal dialog. Put it back inside: on the
       new status message when there is one (so it is read out), else Close. */
    function keepFocusInside() {
        if (!ov || !ov.classList.contains('sv-open') || ov.contains(document.activeElement)) { return; }
        var note = body.querySelector('.sv-notice');
        if (note) {
            note.setAttribute('tabindex', '-1');
            note.focus();
        } else {
            closeBtn.focus();
        }
    }

    function load(extraHtml) {
        var forSurvey = current.surveyId;
        return post('credit_status', { SurveyId: forSurvey, Grantor: current.grantor || '' }).then(function (j) {
            // The modal may have been closed, or reopened for another survey, while this was in flight.
            if (!current || current.surveyId !== forSurvey) { return; }
            if (!j || j.status !== 0) { body.innerHTML = errorHtml(j); keepFocusInside(); return; }
            data = j.credit;
            render(extraHtml);
            keepFocusInside();
            if ((data.pending | 0) > 0 && !reconciled) {
                reconciled = true;
                var cur = current;
                post('credit_reconcile', { SurveyId: cur.surveyId, Grantor: cur.grantor || '' }).then(function (r) {
                    // No onChange here: the credits posted may be another org's,
                    // and this org's switch is exactly as on (or off) as it was.
                    if (r && r.status === 0 && (r.granted | 0) > 0 && current === cur) {
                        load('<div class="sv-notice" role="status">Posted ' + plural(r.granted | 0, 'owed credit', 'owed credits') + '.' +
                            esc(moreText(r.remaining)) + '</div>');
                    }
                });
            }
        });
    }

    function enable() {
        var cur = current;
        enableBtn.disabled = true;
        enableBtn.classList.add('sv-is-busy');
        post('credit_enable', { SurveyId: cur.surveyId, Grantor: cur.grantor || '' }).then(function (j) {
            enableBtn.classList.remove('sv-is-busy');
            if (!j || j.status !== 0) {
                if (current !== cur) { return; }
                body.insertAdjacentHTML('afterbegin', errorHtml(j));
                enableBtn.disabled = false;
                keepFocusInside();
                return;
            }
            var msg = (j.already ? 'Credit was already on. ' : 'Credit is on. ') + 'Posted ' + plural(j.granted | 0, 'credit', 'credits') + '.';
            if ((j.pending | 0) > 0) { msg += ' ' + plural(j.pending | 0, 'credit is', 'credits are') + ' still pending and will be retried.'; }
            if ((j.skipped | 0) > 0) { msg += ' ' + plural(j.skipped | 0, 'respondent is', 'respondents are') + ' outside this credit’s reach (their Kingdom declines outside credits or has not adopted it).'; }
            msg += moreText(j.remaining);
            if (current === cur) { load('<div class="sv-notice" role="status">' + esc(msg) + '</div>'); }
            if (cur.onChange) { cur.onChange(true); }
        });
    }

    function disable() {
        var cur = current;
        disableBtn.disabled = true;
        disableBtn.classList.add('sv-is-busy');
        post('credit_disable', { SurveyId: cur.surveyId, Grantor: cur.grantor || '' }).then(function (j) {
            disableBtn.classList.remove('sv-is-busy');
            if (!j || j.status !== 0) {
                if (current !== cur) { return; }
                body.insertAdjacentHTML('afterbegin', errorHtml(j));
                disableBtn.disabled = false;
                keepFocusInside();
                return;
            }
            if (current === cur) { load('<div class="sv-notice" role="status">Credit is off. Credits already given stay.</div>'); }
            if (cur.onChange) { cur.onChange(false); }
        });
    }

    function focusables() {
        return Array.prototype.filter.call(ov.querySelectorAll(FOCUSABLE), function (el) { return el.offsetParent !== null; });
    }

    function close() {
        ov.classList.remove('sv-open');
        document.removeEventListener('keydown', onKey, true);
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        current = null;
        data = null;
    }

    function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab') { return; }
        var f = focusables();
        if (!f.length) { return; }
        var a = document.activeElement;
        if (f.indexOf(a) === -1) {
            // Focus on something that is not in the tab order: the status
            // message keepFocusInside() parked it on, or <body> if it slipped
            // out. Step to the neighbouring control inside the dialog, and
            // wrap round rather than let the browser walk the page behind.
            e.preventDefault();
            var next = null, k;
            if (ov.contains(a)) {
                if (e.shiftKey) {
                    for (k = f.length - 1; k >= 0 && !next; k--) { if (a.compareDocumentPosition(f[k]) & Node.DOCUMENT_POSITION_PRECEDING) { next = f[k]; } }
                } else {
                    for (k = 0; k < f.length && !next; k++) { if (a.compareDocumentPosition(f[k]) & Node.DOCUMENT_POSITION_FOLLOWING) { next = f[k]; } }
                }
            }
            (next || (e.shiftKey ? f[f.length - 1] : f[0])).focus();
            return;
        }
        if (e.shiftKey && a === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }

    function open(opts) {
        CFG = window.SvCreditConfig || CFG;
        ov = $('sv-credit-overlay');
        body = $('sv-credit-body');
        enableBtn = $('sv-credit-enable');
        disableBtn = $('sv-credit-disable');
        closeBtn = $('sv-credit-close');
        if (!ov) { return; }
        current = opts;
        reconciled = false;
        lastFocus = document.activeElement;
        body.innerHTML = 'Loading…';
        enableBtn.hidden = true;
        disableBtn.hidden = true;
        ov.classList.add('sv-open');
        document.addEventListener('keydown', onKey, true);
        closeBtn.focus();
        load('');
    }

    // Capture phase, so the backdrop click reaches close() before a host page's
    // own generic `.sv-overlay` backdrop handler (Survey_index.tpl binds one to
    // every overlay) removes .sv-open and leaves this modal's key trap attached.
    document.addEventListener('click', function (e) {
        if (!ov || !ov.classList.contains('sv-open')) { return; }
        if (e.target === ov || e.target.closest('#sv-credit-close')) { close(); }
        else if (e.target.closest('#sv-credit-enable')) { enable(); }
        else if (e.target.closest('#sv-credit-disable')) { disable(); }
        else if (e.target.closest('[data-sv-credit-reload]')) { e.preventDefault(); window.location.reload(); }
    }, true);

    /* Read-only credit state for a host page's own label (the builder's card
       button). Resolves to the credit_status payload, or null on any failure —
       a quiet read, so nothing is shown when it cannot be fetched. */
    function status(surveyId, grantor) {
        CFG = window.SvCreditConfig || CFG;
        return post('credit_status', { SurveyId: surveyId, Grantor: grantor || '' }).then(function (j) {
            return (j && j.status === 0) ? j.credit : null;
        });
    }

    window.SvCredit = { open: open, status: status };
}());
