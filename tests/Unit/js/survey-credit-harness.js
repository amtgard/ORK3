/*
  Runs orkui/template/default/script/survey-credit.js against a minimal DOM
  stub and scripted SurveyAjax answers, then prints one JSON line of what the
  host page saw. Driven by tests/Unit/SurveyCreditPanelScriptTest.php:

    node survey-credit-harness.js <scenario>

  scenario "reconcile": credit_status reports pending credits and
  credit_reconcile posts one (another org's owed credit).
  scenario "enable": the viewer switches credit on.
  scenario "disable": the viewer's credit is on and they switch it off.
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
    'sv-credit-enable': el('sv-credit-enable'), 'sv-credit-disable': el('sv-credit-disable'), 'sv-credit-close': el('sv-credit-close') };
var listeners = {};
var document = {
    activeElement: null,
    getElementById: function (id) { return els[id] || null; },
    addEventListener: function (t, fn) { (listeners[t] = listeners[t] || []).push(fn); },
    removeEventListener: function () {}
};

var enabled = scenario === 'disable';
function status(pending) {
    return { status: 0, credit: {
        survey_title: 'S', survey_status: 'open', gate_enabled: true,
        category: enabled ? { category_id: 5, name: 'Survey Bonus - S', scope_type: 'kingdom', scope_name: 'K', archived: false } : null,
        category_name: 'Survey Bonus - S', configs: [], pending: pending, held: 0,
        mine: { grantor_type: 'kingdom', grantor_id: 1, name: 'K', config_id: enabled ? 9 : null, enabled: enabled,
            can_enable: !enabled, can_disable: enabled, blocked_reason: '', covered_by: null,
            preview: enabled ? null : { eligible_now: 0, no_notice: 0 } }
    } };
}
function answer(action) {
    calls.push(action);
    if (action === 'credit_status') { return status(scenario === 'reconcile' && calls.length === 1 ? 1 : 0); }
    if (action === 'credit_reconcile') { return { status: 0, granted: 1, skipped: 0, pending: 0, remaining: 0 }; }
    if (action === 'credit_enable') { enabled = true; return { status: 0, credit_id: 9, granted: 0, skipped: 0, pending: 0, remaining: 0, already: false }; }
    if (action === 'credit_disable') { enabled = false; return { status: 0, credit_id: 9 }; }
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

var changes = [];
sandbox.window.SvCredit.open({ surveyId: 1, grantor: 'Kingdom/1', title: 'S', onChange: function (on) { changes.push(on); } });

function settle(n) { return n ? new Promise(function (r) { setImmediate(r); }).then(function () { return settle(n - 1); }) : Promise.resolve(); }

function press(id) {
    var target = { closest: function (s) { return s === '#' + id ? {} : null; } };
    els['sv-credit-overlay'].classList.add('sv-open');
    listeners.click.forEach(function (fn) { fn({ target: target, preventDefault: function () {} }); });
}

settle(20).then(function () {
    if (scenario === 'enable') { press('sv-credit-enable'); }
    if (scenario === 'disable') { press('sv-credit-disable'); }
    return settle(20);
}).then(function () {
    process.stdout.write(JSON.stringify({ calls: calls, onChange: changes.length, on: changes,
        enableHidden: els['sv-credit-enable'].hidden, disableHidden: els['sv-credit-disable'].hidden }) + '\n');
});
