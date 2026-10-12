'use strict';
/* Runs badgeHtml() of survey-results.js under node, lifted out of the file's
   IIFE with the helpers it calls, and prints the "n = X of Y" tip for each
   question type as JSON for tests/Unit/SurveyResultsBadgeScriptTest.php. */
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var src = fs.readFileSync(path.join(__dirname, '../../../orkui/template/default/script/survey-results.js'), 'utf8');

/* `function name(...) { ... }`, brace for brace; strings and comments skipped. */
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

var textTypes = /var TEXT_TYPES\s*=\s*(\{[^}]*\});/.exec(src);
if (!textTypes) { throw new Error('no TEXT_TYPES'); }

var box = {};
vm.createContext(box);
vm.runInContext(
    'var TEXT_TYPES = ' + textTypes[1] + ';' +
    'var state = { payload: { summary: { responses: 61, min_cell: 5 } } };' +
    // esc() has quotes inside its regex literals, which lift() cannot read past.
    'function esc(s) { return String(s).replace(/&/g, "&amp;").replace(/"/g, "&quot;"); }' +
    ['plural', 'minCell', 'noResponses', 'badgeHtml'].map(lift).join('\n'),
    box);

var out = {};
['pairwise', 'ranking', 'rating', 'single', 'multi', 'short_text'].forEach(function (type) {
    var html = vm.runInContext('badgeHtml({ type: ' + JSON.stringify(type) + ', n: 59, reached: 61, agg: {} })', box);
    var m = /data-tip="([^"]*)"/.exec(html);
    out[type] = m ? m[1] : null;
});

process.stdout.write(JSON.stringify(out) + '\n');
