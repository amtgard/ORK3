'use strict';
/* Loads survey-render.js under node with a bare window/document stub and
   prints JSON for SurveyPairwisePlanScriptTest: the JS plan for every option
   count 0..60, queue properties, and stage outcomes. */
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var src = fs.readFileSync(path.join(__dirname, '../../../orkui/template/default/script/survey-render.js'), 'utf8');
var sandbox = { document: { addEventListener: function () {} } };
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(src, sandbox);
var R = sandbox.SvRender;

/* Deterministic PRNG so the queue checks never flake. */
function mulberry32(a) {
    return function () {
        a |= 0; a = a + 0x6D2B79F5 | 0;
        var t = Math.imul(a ^ a >>> 15, 1 | a);
        t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
        return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
}
function shares(m, n) { return m.a === n.a || m.a === n.b || m.b === n.a || m.b === n.b; }

var out = { plans: {}, queue: {}, stage: {} };
for (var n = 0; n <= 60; n++) { out.plans[n] = R.pairwisePlan(n); }

var ids = [11, 12, 13, 14, 15, 16, 17, 18];
var q = R.pairwiseQueue(ids, [], mulberry32(7));
var keys = {};
q.forEach(function (m) { keys[R.pairKey(m.a, m.b)] = true; });
out.queue.count = q.length;
out.queue.unique = Object.keys(keys).length;
out.queue.flipped = q.some(function (m) { return m.a > m.b; });

var done = [{ a: 12, b: 11, w: 12 }, { a: 13, b: 18, w: 0 }];
var q2 = R.pairwiseQueue(ids, done, mulberry32(9));
out.queue.afterDone = q2.length;
out.queue.containsDone = q2.some(function (m) { var k = R.pairKey(m.a, m.b); return k === '11:12' || k === '13:18'; });

var links = 0, repeats = 0;
for (var seed = 1; seed <= 50; seed++) {
    var qs = R.pairwiseQueue(ids, [], mulberry32(seed));
    for (var i = 1; i < qs.length; i++) { links++; if (shares(qs[i], qs[i - 1])) { repeats++; } }
}
out.queue.repeatRate = repeats / links;
out.queue.preview = R.pairwiseQueue([1, 2, 3], [], null);

var small = R.pairwisePlan(6);    // 15 matchups
var big = R.pairwisePlan(12);     // 66: tiers 20, 27, 33, 40
out.stage.smallReq0 = R.pairwiseStage(small, 0, true);
out.stage.smallOpt5 = R.pairwiseStage(small, 5, false);
out.stage.smallOpt10 = R.pairwiseStage(small, 10, false);
out.stage.small15 = R.pairwiseStage(small, 15, true);
out.stage.bigReq19 = R.pairwiseStage(big, 19, true);
out.stage.bigOpt0 = R.pairwiseStage(big, 0, false);
out.stage.big20 = R.pairwiseStage(big, 20, true);
out.stage.big27 = R.pairwiseStage(big, 27, true);
out.stage.big33 = R.pairwiseStage(big, 33, false);
out.stage.big40 = R.pairwiseStage(big, 40, false);
out.stage.big66 = R.pairwiseStage(big, 66, false);
out.gate = [R.pairwiseGateMessage(small), R.pairwiseGateMessage(big)];

process.stdout.write(JSON.stringify(out));
