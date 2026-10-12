'use strict';
/* Runs the pairwise editor functions of survey-build.js under node. The file
   is one big IIFE that needs a whole page, so this lifts the named functions
   it tests out of the source (the real code, byte for byte) and runs them
   against stubbed neighbours. Prints one JSON object for
   tests/Unit/SurveyBuildPairwiseScriptTest.php:

     hold     a keystroke that makes a duplicate line cancels the save the
              previous keystroke queued, and S goes back to the saved list
     fixed    fixing the duplicate saves the list as normal
     redraw   redrawing the open card puts a held list's reason back
     paste    a clipboard line break at either end is kept */
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var src = fs.readFileSync(path.join(__dirname, '../../../orkui/template/default/script/survey-build.js'), 'utf8');

/* The text of `function name(...) { ... }`, matched brace for brace. Strings
   and comments are skipped; none of the lifted functions has a brace inside a
   regex literal. */
function lift(name) {
    var at = src.search(new RegExp('\\n\\s*function ' + name + '\\s*\\('));
    var i, depth = 0, ch, q, started = false;
    if (at < 0) { throw new Error('no function ' + name); }
    at = src.indexOf('function ' + name, at);
    for (i = at; i < src.length; i++) {
        ch = src[i];
        if (ch === '/' && src[i + 1] === '/') { i = src.indexOf('\n', i); continue; }
        if (ch === '/' && src[i + 1] === '*') { i = src.indexOf('*/', i + 2) + 1; continue; }
        if (ch === '\'' || ch === '"') {
            q = ch;
            for (i++; i < src.length && src[i] !== q; i++) { if (src[i] === '\\') { i++; } }
            continue;
        }
        if (ch === '{') { depth++; started = true; }
        if (ch === '}') { depth--; if (started && depth === 0) { return src.slice(at, i + 1); } }
    }
    throw new Error('unbalanced ' + name);
}

function sandbox() {
    var timers = [], seq = 0;
    var box = {
        posts: [], marks: [], drops: [],
        window: {
            setTimeout: function (fn) { timers.push({ id: ++seq, fn: fn }); return seq; },
            clearTimeout: function (id) { timers = timers.filter(function (t) { return t.id !== id; }); },
            clipboardData: null
        },
        runTimers: function () { var due = timers; timers = []; due.forEach(function (t) { t.fn(); }); }
    };
    vm.createContext(box);
    vm.runInContext(
        'var S = { locked: false }, sel = 0, SAVE_MS = 400;' +
        'var pending = {}, held = {}, failed = {}, pwBase = {}, optBusy = {};' +
        'var Q = null, AREA = null, CARD = {};' +
        'function questionById() { return Q; }' +
        'function cardEl() { return CARD; }' +
        'function el(s) { return s === ".svb-pw-lines" ? AREA : null; }' +
        'function els() { return []; }' +
        'function locOf() { return { selector: ".svb-pw-lines" }; }' +
        'function locate(loc) { return loc ? AREA : null; }' +
        'function fieldError(node, msg) { marks.push(msg || ""); }' +
        'function refreshPill() {}' +
        'function saveFailed() {}' +
        'function refreshCard() {}' +
        'function autoGrow() {}' +
        'function fire() {}' +
        'function markBlankRows() { return true; }' +
        'function dropPairwiseHold(id) { drops.push(id); }' +
        'var inflight = 0, idleWaiters = [], ASYNC = null;' +
        'function post(action, fields, onOk) { posts.push({ action: action, fields: JSON.parse(JSON.stringify(fields)) });' +
        '  return ASYNC ? ASYNC(fields, onOk) : undefined; }' +
        ['pairwiseLines', 'optionsOf', 'save', 'flush', 'hasKeys', 'whenIdle', 'drainIdle', 'optionSetSent',
         'commitPairwise', 'syncMarks', 'onCanvasPaste'].map(lift).join('\n'),
        box);
    return box;
}

var LABELS = ['Spring war', 'Tournament of champions', 'Quest weekend', 'Fall feast', 'Camping campaign',
              'Fighter practice weekend', 'Arts & Sciences faire', 'Newcomer demo day', 'Youth day'];

function pairwiseQuestion(box) {
    var q = { question_id: 666, type: 'pairwise', options: LABELS.map(function (l, i) {
        return { option_id: 1000 + i, question_id: 666, role: 'choice', sort_order: i, label: l };
    }) };
    vm.runInContext('Q = ' + JSON.stringify(q) + '; AREA = { value: "" }; sel = 666;', box);
    return box;
}

function labelsOf(box) { return vm.runInContext('Q.options.map(function (o) { return o.label; })', box); }

var out = {};

/* hold: "\nFall feas" queues a save; the "t" that makes it a duplicate of
   "Fall feast" must cancel it before its debounce fires. */
(function () {
    var box = pairwiseQuestion(sandbox());
    vm.runInContext('AREA.value = ' + JSON.stringify(LABELS.join('\n') + '\nFall feas') + '; commitPairwise(666);', box);
    var queued = vm.runInContext('Object.keys(pending)', box);
    var optimistic = labelsOf(box).length;
    vm.runInContext('AREA.value += "t"; commitPairwise(666);', box);
    box.runTimers();
    out.hold = {
        queuedFirst: queued,
        optimisticCount: optimistic,
        posts: box.posts.length,
        pendingAfter: vm.runInContext('Object.keys(pending)', box),
        held: vm.runInContext('held["opts:666:choice"] ? held["opts:666:choice"].msg : null', box),
        labels: labelsOf(box),
        lastMark: box.marks[box.marks.length - 1]
    };

    /* fixed: the author turns the duplicate into a new name; that saves. */
    vm.runInContext('AREA.value = ' + JSON.stringify(LABELS.join('\n') + '\nWinter court') + '; commitPairwise(666);', box);
    box.runTimers();
    var sent = box.posts.length ? JSON.parse(box.posts[0].fields.Options) : [];
    out.fixed = {
        posts: box.posts.length,
        held: vm.runInContext('!!held["opts:666:choice"]', box),
        sentLabels: sent.map(function (o) { return o.label; }),
        keptIds: sent.filter(function (o) { return o.option_id > 0; }).length
    };
}());

/* redraw: syncMarks (which refreshCard now runs for the open card) puts the
   held list's reason back on the redrawn textarea. */
(function () {
    var box = pairwiseQuestion(sandbox());
    vm.runInContext('AREA.value = ' + JSON.stringify(LABELS.join('\n') + '\nYouth day') + '; commitPairwise(666);', box);
    box.marks.length = 0;
    vm.runInContext('AREA = { value: held["opts:666:choice"].text };' + lift('refreshCard').replace(/^function refreshCard/, 'function redraw') +
        ';function cardHtml() { return ""; }' +
        'CARD = { insertAdjacentHTML: function () {}, previousElementSibling: {}, parentNode: { removeChild: function () {} } };' +
        'redraw(666);', box);
    var openMarks = box.marks.slice();
    box.marks.length = 0;
    vm.runInContext('sel = 1; redraw(666);', box);
    out.redraw = { openCard: openMarks, otherCard: box.marks.slice(), drops: box.drops.length };
}());

/* paste: the clipboard's own line breaks at its ends survive the clean-up. */
(function () {
    var box = pairwiseQuestion(sandbox());
    function paste(value, start, end, clip) {
        vm.runInContext('AREA = { value: ' + JSON.stringify(value) + ', selectionStart: ' + start + ', selectionEnd: ' + end + ',' +
            'classList: { contains: function (c) { return c === "svb-pw-lines"; } }, setSelectionRange: function () {} };', box);
        box.clip = clip;
        vm.runInContext('onCanvasPaste({ target: AREA, clipboardData: { getData: function () { return clip; } }, preventDefault: function () {} });', box);
        return vm.runInContext('AREA.value', box);
    }
    var tail = 'Spring war\nYouth day';
    out.paste = {
        leadingAtEnd:   paste(tail, tail.length, tail.length, '\n- Harvest games\n* Winter court\n• Spring feast'),
        trailingAtLine: paste(tail, 11, 11, 'Harvest games\nWinter court\n'),
        leadingAfterBreak: paste('Spring war\n', 11, 11, '\nHarvest games\nWinter court'),
        leadingInEmpty: paste('', 0, 0, '\nHarvest games\nWinter court'),
        midLine:        paste(tail, 6, 6, 'Harvest games\nWinter court')
    };
}());

/* resend: a line edited while an option_set is on the wire waits in
   optBusy; an idle waiter (retype, duplicate, reload) registered meanwhile
   must not run until that edit's resend has gone out, with the reply's real
   ids, and come back. post() here returns a promise the test settles. */
function resendScenario() {
    var box = pairwiseQuestion(sandbox());
    var replies = [], events = [], nextId = 5000;
    box.events = events;
    box.ASYNC = function (fields, onOk) {
        var sent = JSON.parse(fields.Options);
        events.push('post');
        return new Promise(function (resolve) {
            replies.push(function () {
                onOk({ options: sent.map(function (o, i) {
                    return { option_id: o.option_id || nextId++, question_id: 666, role: 'choice', sort_order: i, label: o.label };
                }) });
                resolve({});
            });
        });
    };
    function tick() { return new Promise(function (r) { setTimeout(r, 0); }); }
    vm.runInContext('AREA.value = ' + JSON.stringify(LABELS.join('\n') + '\nWinter court') + '; commitPairwise(666);', box);
    box.runTimers();
    vm.runInContext('AREA.value += "\\nHarvest games"; commitPairwise(666);', box);
    var r = {
        busyWaiting: vm.runInContext('!!(optBusy["opts:666:choice"] && optBusy["opts:666:choice"].again)', box),
        pendingWhileBusy: vm.runInContext('Object.keys(pending)', box)
    };
    vm.runInContext('whenIdle(function () { events.push("idle"); });', box);
    r.idleBeforeReply = events.indexOf('idle') >= 0;
    replies.shift()();
    return tick().then(function () {
        var sent = box.posts.length > 1 ? JSON.parse(box.posts[1].fields.Options) : [];
        r.afterFirstReply = events.slice();
        r.pendingAfterFirstReply = vm.runInContext('Object.keys(pending)', box);
        r.resendLabels = sent.map(function (o) { return o.label; });
        r.resendWinterId = (sent.filter(function (o) { return o.label === 'Winter court'; })[0] || {}).option_id;
        replies.shift()();
        return tick();
    }).then(function () {
        r.afterSecondReply = events.slice();
        r.busyAfter = vm.runInContext('Object.keys(optBusy)', box);
        out.resend = r;
    });
}

resendScenario().then(function () {
    process.stdout.write(JSON.stringify(out) + '\n');
});
