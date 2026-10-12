'use strict';
/* Loads survey-render.js under node with a small hand-made DOM stub and
   drives the pairwise widget through its document click / keydown handlers.
   Prints JSON for SurveyPairwiseInputScriptTest: how many picks each input
   sequence records (a held arrow key, a double click with reduced motion),
   whether a re-render replaces the question's live state rather than adding
   another entry, and whether a builder preview keeps any live state. */
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var src = fs.readFileSync(path.join(__dirname, '../../../orkui/template/default/script/survey-render.js'), 'utf8');

var listeners = {};
var timers = [];
var reduced = false;

function node(extra) {
    var attrs = {}, kids = {}, n;
    n = {
        hidden: false,
        textContent: '',
        style: {},
        id: '',
        className: '',
        classes: {},
        up: {},                      // closest(selector) -> node
        getAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
        setAttribute: function (k, v) { attrs[k] = String(v); },
        removeAttribute: function (k) { delete attrs[k]; },
        hasAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k); },
        focus: function () { sandbox.document.activeElement = n; },
        dispatchEvent: function () { return true; },
        appendChild: function () {},
        classList: { contains: function (c) { return !!n.classes[c]; } },
        closest: function (sel) { return n.up[sel] || null; },
        querySelector: function (sel) { return kids[sel] || (kids[sel] = node()); },
        querySelectorAll: function () { return []; }
    };
    Object.keys(extra || {}).forEach(function (k) { n[k] = extra[k]; });
    return n;
}

var sandbox = {
    Event: function (type) { this.type = type; },
    matchMedia: function () { return { matches: reduced }; },
    setTimeout: function (fn) { timers.push(fn); return timers.length; },
    clearTimeout: function () {},
    document: {
        body: null,
        activeElement: null,
        addEventListener: function (type, fn) { listeners[type] = fn; },
        getElementById: function () { return null; },
        createElement: function () { return node(); }
    }
};
sandbox.document.body = node();
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(src, sandbox);
var R = sandbox.SvRender;

function runTimers() { var t = timers; timers = []; t.forEach(function (fn) { fn(); }); }

var Q = {
    question_id: 7, type: 'pairwise', required: 0,
    options: [
        { option_id: 1, role: 'choice', label: 'Red' },
        { option_id: 2, role: 'choice', label: 'Green' },
        { option_id: 3, role: 'choice', label: 'Blue' },
        { option_id: 4, role: 'choice', label: 'Gold' }
    ]
};

/** Render one question and wire stub nodes to its data-pw key. */
function mount(mode) {
    var html = R.question(Q, undefined, mode);
    var key = (/data-pw="([^"]+)"/.exec(html) || [])[1];
    var root = node(), pw = node(), stage = node(), a = node(), b = node(), tie = node();
    pw.setAttribute('data-pw', key);
    pw.up['.sv-pw'] = pw;
    if (mode === 'preview') {
        root.classes['sv-q-preview'] = true;
        pw.up['.sv-q-preview'] = root;
    }
    root.querySelector = function (sel) { return sel === '.sv-pw' ? pw : null; };
    pw.querySelector('.sv-pw-stage');
    [[a, 'a'], [b, 'b'], [tie, 'tie']].forEach(function (p) {
        p[0].setAttribute('data-pw-pick', p[1]);
        p[0].up['[data-pw-pick], .sv-pw-undo'] = p[0];
        p[0].up['.sv-pw-stage'] = stage;
        p[0].up['.sv-pw'] = pw;
        p[0].up['.sv-q-preview'] = pw.up['.sv-q-preview'] || null;
    });
    return { html: html, key: key, root: root, pw: pw, a: a, b: b, tie: tie };
}

function key(target, k, repeat) {
    var ev = { target: target, key: k, repeat: !!repeat, prevented: false,
               preventDefault: function () { ev.prevented = true; } };
    listeners.keydown(ev);
    return ev;
}
function click(target) {
    listeners.click({ target: target, preventDefault: function () {} });
}
function picks(m) { var v = R.read(m.root, Q); return v ? v.length : 0; }

var out = {};

/* A held arrow key: the first press counts, auto-repeats never do (even after
   the busy window has passed), and the repeat still does not scroll the page. */
reduced = false;
var m1 = mount('take');
key(m1.a, 'ArrowLeft');
runTimers();
var rep = key(m1.a, 'ArrowLeft', true);
runTimers();
key(m1.a, 'ArrowRight', true);
runTimers();
out.heldKey = { picks: picks(m1), repeatPrevented: rep.prevented };
key(m1.a, 'ArrowDown');
runTimers();
out.heldKey.afterFreshPress = picks(m1);

/* Reduced motion: no flash, but a double click still records one pick. */
reduced = true;
var m2 = mount('take');
click(m2.a);
click(m2.a);
out.reducedDouble = picks(m2);
runTimers();
click(m2.b);
out.reducedAfterWindow = picks(m2);

/* Motion on: the flash window already swallows a double click. */
reduced = false;
var m3 = mount('take');
click(m3.b);
click(m3.b);
out.motionDouble = picks(m3);

/* A re-render of the same question (the runner redraws the page on every
   Next / Back) reuses its data-pw key, so the fresh state replaces the old
   entry instead of adding one: the old mount now reads the fresh, empty state.
   A different question never shares the key. */
reduced = true;
var r1 = mount('take');
click(r1.a);
runTimers();
var r1Picks = picks(r1);
var r2 = mount('take');
var Q2 = JSON.parse(JSON.stringify(Q));
Q2.question_id = 8;
var otherKey = (/data-pw="([^"]+)"/.exec(R.question(Q2, undefined, 'take')) || [])[1];
out.rerender = {
    firstPicks: r1Picks,
    sameKey: r1.key === r2.key,
    oldMountReads: picks(r1),
    otherQuestionKeyDiffers: !!otherKey && otherKey !== r1.key
};
reduced = false;

/* A builder preview keeps no live state: write() has nothing to fill, so a
   read comes back empty, and the markup still shows the first authored matchup. */
var m4 = mount('preview');
R.write(m4.root, Q, [{ a: 1, b: 2, w: 1 }]);
out.previewRead = R.read(m4.root, Q) === undefined ? null : R.read(m4.root, Q);
out.previewShowsFirstPair = m4.html.indexOf('>Red<') !== -1 && m4.html.indexOf('>Green<') !== -1;
click(m4.a);
key(m4.a, 'ArrowLeft');
out.previewPicks = picks(m4);

process.stdout.write(JSON.stringify(out));
