/*
  Runs orkui/template/default/script/survey-credit.js against a minimal DOM
  stub and scripted SurveyAjax answers, then prints one JSON line of what the
  host page saw. Driven by tests/Unit/SurveyCreditPanelScriptTest.php:

    node survey-credit-harness.js <scenario>

  scenario "reconcile": credit_status reports pending credits and
  credit_reconcile posts one (another org's owed credit).
  scenario "enable": the viewer turns credits on.
*/
'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var scenario = process.argv[2] || 'reconcile';
var calls = [];

function el(id) {
    return {
        id: id, hidden: false, disabled: false, innerHTML: '', textContent: '', checked: false,
        classList: {
            set: {},
            add: function (c) { this.set[c] = true; },
            remove: function (c) { delete this.set[c]; },
            contains: function (c) { return !!this.set[c]; }
        },
        focus: function () {}, setAttribute: function () {}, insertAdjacentHTML: function () {},
        contains: function () { return true; },
        querySelector: function () { return null; },
        querySelectorAll: function () { return []; }
    };
}
var els = { 'sv-credit-overlay': el('sv-credit-overlay'), 'sv-credit-body': el('sv-credit-body'),
    'sv-credit-enable': el('sv-credit-enable'), 'sv-credit-close': el('sv-credit-close') };
var listeners = {};
var document = {
    activeElement: null,
    getElementById: function (id) { return els[id] || null; },
    addEventListener: function (t, fn) { (listeners[t] = listeners[t] || []).push(fn); },
    removeEventListener: function () {}
};

function status(pending, on) {
    return { status: 0, credit: {
        survey_title: 'S', survey_status: 'open', event_name: 'Survey Credit - S', start_date: '2026-09-01',
        gate_enabled: true, configs: [], pending: pending,
        mine: { grantor_type: 'kingdom', grantor_id: 1, name: 'K', config_id: on ? 9 : null, can_enable: !on,
            blocked_reason: '', covered_by: null, preview: on ? null : { home_park: { eligible_now: 0, no_home_park: 0 }, event: { eligible_now: 0, no_home_park: 0 } } }
    } };
}
var enabled = false;
function answer(action) {
    calls.push(action);
    if (action === 'credit_status') { return status(scenario === 'reconcile' && calls.length === 1 ? 1 : 0, enabled); }
    if (action === 'credit_reconcile') { return { status: 0, granted: 1, skipped_no_park: 0, pending: 0 }; }
    if (action === 'credit_enable') { enabled = true; return { status: 0, granted: 0, skipped_no_park: 0, pending: 0 }; }
    return { status: 1, error: 'unexpected ' + action };
}

var sandbox = {
    window: { SvCreditConfig: { uir: '/', csrf: 'x' } },
    document: document,
    Node: { DOCUMENT_POSITION_PRECEDING: 2, DOCUMENT_POSITION_FOLLOWING: 4 },
    FormData: function () { this.append = function () {}; },
    fetch: function (url) {
        var action = String(url).split('SurveyAjax/')[1];
        return Promise.resolve({ json: function () { return answer(action); } });
    },
    console: console
};
sandbox.window.document = document;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(process.env.SV_CREDIT_JS || path.join(__dirname, '../../../orkui/template/default/script/survey-credit.js'), 'utf8'), sandbox);

var changes = 0;
sandbox.window.SvCredit.open({ surveyId: 1, grantor: 'Kingdom/1', title: 'S', onChange: function () { changes++; } });

function settle(n) { return n ? new Promise(function (r) { setImmediate(r); }).then(function () { return settle(n - 1); }) : Promise.resolve(); }

settle(20).then(function () {
    if (scenario === 'enable') {
        // Pick a mode and tick the acknowledgement, then press Turn on credits.
        els['sv-credit-body'].querySelector = function (sel) { return sel.indexOf('sv-credit-mode') !== -1 ? { value: 'home_park' } : null; };
        els['sv-credit-ack'] = el('sv-credit-ack');
        els['sv-credit-ack'].checked = true;
        var target = { closest: function (s) { return s === '#sv-credit-enable' ? {} : null; } };
        els['sv-credit-overlay'].classList.add('sv-open');
        listeners.click.forEach(function (fn) { fn({ target: target, preventDefault: function () {} }); });
    }
    return settle(20);
}).then(function () {
    process.stdout.write(JSON.stringify({ calls: calls, onChange: changes }) + '\n');
});
