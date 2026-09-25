/*
  survey-credit.js — the Attendance credit modal (sharing-and-credits spec §3.6).
  Shared by the survey list and the builder. Configured by
  window.SvCreditConfig = {uir, csrf}; opened with
  SvCredit.open({surveyId, grantor, title, onChange}); onChange runs only after
  "Turn on credits" succeeds, i.e. once the grantor's own credit is on (the
  panel's automatic reconcile posts credits owed under existing configs,
  possibly another org's, and changes no one's on/off state). SvCredit.status(surveyId,
  grantor) reads the state quietly for a host page's label. No native dialogs;
  focus is trapped while open and restored on close. Configs are permanent,
  so the only mutation is "Turn on credits", gated by an explicit checkbox.
*/
(function () {
    'use strict';

    var CFG = window.SvCreditConfig || {};
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var MODE_LABEL = {
        home_park: 'At the player’s home park, on the day they took the survey',
        event: null   // built from the status payload: event name + start date
    };

    var ov, body, enableBtn, closeBtn;
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
        var where;
        if (c.mode === 'event') {
            where = c.event_id
                ? 'at the event <a href="' + esc(CFG.uir + 'Event/detail/' + c.event_id + '/' + c.event_calendardetail_id) + '">' + esc(c.event_label) + '</a>'
                : 'at an event created when the survey opens';
        } else {
            where = 'at players’ home parks';
        }
        return '<li><strong>' + esc(c.grantor_name) + '</strong>: ' + where + ', since ' + esc(longDate(c.enabled_at)) +
            ' (' + plural(c.granted | 0, 'credit', 'credits') + ')</li>';
    }

    function warningText(mode) {
        var p = (data.mine.preview || {})[mode] || { eligible_now: 0, no_home_park: 0 };
        var t = plural(p.eligible_now | 0, 'player who already chose Any ORK Data will', 'players who already chose Any ORK Data will') +
            ' get a credit now. ';
        if (data.survey_status === 'open' || data.survey_status === 'draft') {
            t += 'While this survey is open, everyone who completes it with Any ORK Data and is covered by this credit will get one automatically. ';
        }
        t += 'This can’t be turned off or changed, and credits already given stay on players’ records.';
        if (mode === 'home_park' && (p.no_home_park | 0) > 0) {
            t += ' ' + plural(p.no_home_park | 0, 'player', 'players') + ' can’t be credited at a home park because they have none.';
        }
        // Spec D1: a credit is public, so nobody gets one who chose Any ORK
        // Data before the survey said anything about credits.
        if ((p.no_notice | 0) > 0) {
            t += ' ' + plural(p.no_notice | 0, 'player', 'players') +
                ' chose Any ORK Data before this survey said anything about credits, so they won’t get one.';
        }
        return t;
    }

    function render(extraHtml) {
        var html = extraHtml || '';
        var mine = data.mine;
        var evDate = data.start_date ? longDate(data.start_date) : 'the day this survey opens';

        html += '<p class="sv-credit-lead"><strong>' + esc(current.title || data.survey_title) + '</strong></p>';
        if (data.configs.length) {
            html += '<p class="sv-credit-sub">This survey’s credits</p><ul class="sv-credit-list">' +
                data.configs.map(configLine).join('') + '</ul>';
        }
        if ((data.held | 0) > 0) {
            html += '<p class="sv-credit-sub">' + esc(plural(data.held | 0, 'credit is', 'credits are')) +
                ' on hold for banned or suspended players and will post once the sanction lifts.</p>';
        }

        enableBtn.hidden = true;
        enableBtn.disabled = true;

        if (!mine) {
            if (!data.configs.length) {
                html += '<p>No attendance credits yet. Kingdoms and parks turn them on for their own players from their survey list.</p>';
            }
        } else if (mine.config_id) {
            html += '<p>Credits are on for <strong>' + esc(mine.name) + '</strong>.</p>';
        } else if (!data.gate_enabled || !mine.can_enable) {
            html += '<div class="sv-notice sv-notice-warn">' + esc(mine.blocked_reason) + '</div>';
        } else {
            if (mine.covered_by) {
                html += '<p class="sv-credit-sub">Your players are already covered by ' + esc(mine.covered_by.name) +
                    '’s credit. Turning one on here only reaches players it does not cover.</p>';
            }
            html += '<fieldset class="sv-credit-modes"><legend>Give <strong>' + esc(mine.name) + '</strong> players the credit</legend>' +
                '<label class="sv-credit-mode"><input type="radio" name="sv-credit-mode" value="home_park"> <span>' + esc(MODE_LABEL.home_park) + '</span></label>' +
                '<label class="sv-credit-mode"><input type="radio" name="sv-credit-mode" value="event"> <span>At a new event “' +
                esc(data.event_name) + '” on ' + esc(evDate) + '</span></label></fieldset>' +
                '<p class="sv-credit-warning" id="sv-credit-warning" hidden></p>' +
                '<label class="sv-check sv-credit-confirm"><input type="checkbox" class="sv-check-input" id="sv-credit-ack"> ' +
                '<span>I understand this can’t be undone</span></label>';
            enableBtn.hidden = false;
        }
        body.innerHTML = html;
    }

    function selectedMode() {
        var r = body.querySelector('input[name="sv-credit-mode"]:checked');
        return r ? r.value : '';
    }

    function syncEnable() {
        var mode = selectedMode();
        var ack = $('sv-credit-ack');
        var warn = $('sv-credit-warning');
        if (warn) {
            warn.hidden = !mode;
            warn.textContent = mode ? warningText(mode) : '';
        }
        enableBtn.disabled = !(mode && ack && ack.checked);
    }

    /* A re-render can take away the control that had focus (Turn on credits is
       disabled while it posts, then hidden once credits are on), which drops
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
                    // and this org's credit is exactly as on (or off) as it was.
                    if (r && r.status === 0 && (r.granted | 0) > 0 && current === cur) {
                        load('<div class="sv-notice" role="status">Posted ' + plural(r.granted | 0, 'owed credit', 'owed credits') + '.' +
                            esc(moreText(r.remaining)) + '</div>');
                    }
                });
            }
        });
    }

    function enable() {
        var mode = selectedMode();
        if (!mode || !$('sv-credit-ack') || !$('sv-credit-ack').checked) { return; }
        var cur = current;
        enableBtn.disabled = true;
        enableBtn.classList.add('sv-is-busy');
        post('credit_enable', { SurveyId: cur.surveyId, Grantor: cur.grantor || '', Mode: mode, Confirm: 1 }).then(function (j) {
            enableBtn.classList.remove('sv-is-busy');
            if (!j || j.status !== 0) {
                if (current !== cur) { return; }
                body.insertAdjacentHTML('afterbegin', errorHtml(j));
                syncEnable();
                keepFocusInside();
                return;
            }
            var msg = (j.already ? 'Credits were already on. ' : 'Credits are on. ') + 'Posted ' + plural(j.granted | 0, 'credit', 'credits') + '.';
            if ((j.pending | 0) > 0) { msg += ' ' + plural(j.pending | 0, 'credit is', 'credits are') + ' still pending and will be retried.'; }
            msg += moreText(j.remaining);
            if (current === cur) { load('<div class="sv-notice" role="status">' + esc(msg) + '</div>'); }
            if (cur.onChange) { cur.onChange(); }
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
        closeBtn = $('sv-credit-close');
        if (!ov) { return; }
        current = opts;
        reconciled = false;
        lastFocus = document.activeElement;
        body.innerHTML = 'Loading…';
        enableBtn.hidden = true;
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
        else if (e.target.closest('[data-sv-credit-reload]')) { e.preventDefault(); window.location.reload(); }
    }, true);
    document.addEventListener('change', function (e) {
        if (ov && ov.classList.contains('sv-open') && (e.target.name === 'sv-credit-mode' || e.target.id === 'sv-credit-ack')) { syncEnable(); }
    });

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
