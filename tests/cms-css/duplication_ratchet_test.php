#!/usr/bin/env php
<?php

/**
 * duplication_ratchet_test.php — the CMS CSS duplication ratchet, both directions.
 *
 * bin/check-css-duplication.php fails in BOTH directions, and that two-sidedness
 * is the property this test exists to pin. Failing only when duplication ROSE
 * would be a freeze, not a ratchet: the budget is set equal to the observed
 * count, so a real cleanup would lower the count, the gate would stay green, and
 * the slack would sit there for the next commit to spend on a fresh copy — the
 * improvement captured only if a human remembered to edit a constant, with
 * nothing to remind them.
 *
 * What is pinned:
 *   observed >  budget  -> exit 1, "DUPLICATION ROSE"
 *   observed <  budget  -> exit 1, "DUPLICATION FELL"  (and CSS_DUP_ALLOW_SLACK=1 forgives)
 *   observed == budget  -> exit 0
 *   --rebaseline lowers freely, refuses to raise without --allow-raise
 *   CSS_DUP_ALLOW_SLACK=1 never forgives the ROSE direction
 *
 * How: the script derives its stylesheet set from CSS_GLOBS relative to its own
 * parent directory, so the test builds a throwaway tree
 *
 *     <tmp>/bin/check-css-duplication.php
 *     <tmp>/orkui/template/default/frontdoor/css/probe.css
 *
 * copies the real script into it, and drives that copy. The real repo is never
 * touched — including by --rebaseline, which rewrites the copy's own constants.
 *
 * No DB, no app, no PHPUnit. Run it directly:  php tests/cms-css/duplication_ratchet_test.php
 */

$root   = dirname(__DIR__, 2);
$script = $root . '/bin/check-css-duplication.php';

$pass = 0;
$fail = 0;

function ok($cond, $what, $detail = '')
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  $what\n";
        return true;
    }
    $fail++;
    echo "  FAIL  $what\n";
    if ($detail !== '') {
        foreach (explode("\n", rtrim($detail)) as $line) {
            echo "          | $line\n";
        }
    }
    return false;
}

function rmtreeR($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) ? rmtreeR($p) : unlink($p);
    }
    rmdir($dir);
}

/**
 * Build an isolated copy of the gate over a stylesheet we control.
 *
 * @param  string $css        stylesheet body placed at frontdoor/css/probe.css
 * @param  int    $budget2    value to pin MAX_GROUPS_2PLUS at
 * @param  int    $budgetAny  value to pin MAX_GROUPS_ANY at
 * @return array{dir:string, script:string}
 */
function sandbox($css, $budget2, $budgetAny)
{
    global $script;
    $dir = sys_get_temp_dir() . '/ork3-dupratchet-' . bin2hex(random_bytes(6));
    mkdir($dir . '/bin', 0777, true);
    mkdir($dir . '/orkui/template/default/frontdoor/css', 0777, true);
    mkdir($dir . '/orkui/template/default/cms/css', 0777, true);

    $src = (string) file_get_contents($script);
    $src = preg_replace('/^([ \t]*const[ \t]+MAX_GROUPS_2PLUS[ \t]*=[ \t]*)\d+/m', '${1}' . $budget2, $src, 1);
    $src = preg_replace('/^([ \t]*const[ \t]+MAX_GROUPS_ANY[ \t]*=[ \t]*)\d+/m', '${1}' . $budgetAny, $src, 1);
    file_put_contents($dir . '/bin/check-css-duplication.php', $src);
    file_put_contents($dir . '/orkui/template/default/frontdoor/css/probe.css', $css);

    return array('dir' => $dir, 'script' => $dir . '/bin/check-css-duplication.php');
}

/** @return array{code:int, out:string} */
function runGate($sandbox, $args = '', array $env = array())
{
    // `env` rather than a bare NAME=value prefix: escapeshellarg() would quote the
    // NAME too, and `'FOO'=1 php …` is a command sh cannot find, not an assignment.
    $prefix = $env ? 'env ' : '';
    foreach ($env as $k => $v) {
        $prefix .= preg_replace('/[^A-Z0-9_]/', '', $k) . '=' . escapeshellarg($v) . ' ';
    }
    $cmd = $prefix . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($sandbox['script']) . ' ' . $args . ' 2>&1';
    exec($cmd, $lines, $code);
    return array('code' => $code, 'out' => implode("\n", $lines));
}

function constantOf($sandbox, $name)
{
    $src = (string) file_get_contents($sandbox['script']);
    return preg_match('/^[ \t]*const[ \t]+' . $name . '[ \t]*=[ \t]*(\d+)/m', $src, $m) ? (int) $m[1] : -1;
}

// A stylesheet with exactly two duplicate groups:
//   1 group with >= 2 declarations  (.a / .b)
//   1 more single-declaration group (.c / .d)  -> ANY = 2, 2PLUS = 1
const BASE_CSS = <<<'CSS'
.a { color: #fff; background: #000; }
.b { color: #fff; background: #000; }
.c { display: flex; }
.d { display: flex; }
.solo { margin: 4px; }
CSS;

echo "CMS CSS duplication ratchet\n\n";

// ---------------------------------------------------------------------------
echo "1. The three verdicts\n";
// ---------------------------------------------------------------------------
$s = sandbox(BASE_CSS, 1, 2);
$r = runGate($s);
ok($r['code'] === 0, 'observed == budget passes', $r['out']);
ok(strpos($r['out'], 'ratchet held') !== false, 'and says the ratchet HELD', $r['out']);
rmtreeR($s['dir']);

// Budget one BELOW observed: duplication rose.
$s = sandbox(BASE_CSS, 1, 1);
$r = runGate($s);
ok($r['code'] === 1, 'observed > budget fails', $r['out']);
ok(strpos($r['out'], 'DUPLICATION ROSE') !== false, 'and names the direction: ROSE', $r['out']);
ok(strpos($r['out'], 'DUPLICATION FELL') === false, 'and does not also claim it FELL', $r['out']);
rmtreeR($s['dir']);

// Budget one ABOVE observed: duplication fell and nobody pinned it. THE NEW BEHAVIOUR.
$s = sandbox(BASE_CSS, 1, 3);
$r = runGate($s);
ok($r['code'] === 1, 'observed < budget FAILS — a freeze would have passed here', $r['out']);
ok(strpos($r['out'], 'DUPLICATION FELL') !== false, 'and names the direction: FELL', $r['out']);
ok(strpos($r['out'], 'DUPLICATION ROSE') === false, 'and does not also claim it ROSE', $r['out']);
ok(strpos($r['out'], 'lint:css:dupes:rebaseline') !== false, 'and prints the one re-baselining command', $r['out']);
ok(
    preg_match('/check-css-duplication\.php:\d+/', $r['out']) === 1
    && strpos($r['out'], '+ const MAX_GROUPS_ANY   = 2;') !== false,
    'and prints the exact file:line and the exact replacement line',
    $r['out']
);
rmtreeR($s['dir']);

// Both budgets wrong in opposite directions: both are reported.
$s = sandbox(BASE_CSS, 0, 3);
$r = runGate($s);
ok($r['code'] === 1, 'one budget up and one down still fails', $r['out']);
ok(
    strpos($r['out'], 'DUPLICATION ROSE') !== false && strpos($r['out'], 'DUPLICATION FELL') !== false,
    'and reports BOTH directions rather than the first one it hits',
    $r['out']
);
rmtreeR($s['dir']);

// ---------------------------------------------------------------------------
echo "\n2. The escape hatch is one-directional\n";
// ---------------------------------------------------------------------------
$s = sandbox(BASE_CSS, 1, 3);
$r = runGate($s, '', array('CSS_DUP_ALLOW_SLACK' => '1'));
ok($r['code'] === 0, 'CSS_DUP_ALLOW_SLACK=1 forgives the FELL direction', $r['out']);
ok(strpos($r['out'], 'slack allowed') !== false, 'and says so out loud rather than printing plain OK', $r['out']);
rmtreeR($s['dir']);

$s = sandbox(BASE_CSS, 1, 1);
$r = runGate($s, '', array('CSS_DUP_ALLOW_SLACK' => '1'));
ok($r['code'] === 1, 'CSS_DUP_ALLOW_SLACK=1 does NOT forgive the ROSE direction', $r['out']);
rmtreeR($s['dir']);

$s = sandbox(BASE_CSS, 0, 3);
$r = runGate($s, '', array('CSS_DUP_ALLOW_SLACK' => '1'));
ok($r['code'] === 1, 'nor a mixed rose+fell run', $r['out']);
rmtreeR($s['dir']);

// Any other value is not the hatch.
$s = sandbox(BASE_CSS, 1, 3);
$r = runGate($s, '', array('CSS_DUP_ALLOW_SLACK' => 'yes'));
ok($r['code'] === 1, 'CSS_DUP_ALLOW_SLACK must be exactly "1"', $r['out']);
rmtreeR($s['dir']);

// ---------------------------------------------------------------------------
echo "\n3. --rebaseline\n";
// ---------------------------------------------------------------------------
$s = sandbox(BASE_CSS, 1, 3);
$r = runGate($s, '--rebaseline');
ok($r['code'] === 0, '--rebaseline succeeds when it only has to LOWER', $r['out']);
ok(constantOf($s, 'MAX_GROUPS_ANY') === 2, 'and writes the observed count into the constant', $r['out']);
ok(constantOf($s, 'MAX_GROUPS_2PLUS') === 1, 'leaving the already-correct budget alone', $r['out']);
$r = runGate($s);
ok($r['code'] === 0, 'after which the gate passes', $r['out']);
rmtreeR($s['dir']);

$s = sandbox(BASE_CSS, 1, 1);
$r = runGate($s, '--rebaseline');
ok($r['code'] === 1, '--rebaseline REFUSES to raise a budget on its own', $r['out']);
ok(constantOf($s, 'MAX_GROUPS_ANY') === 1, 'and leaves the constant untouched', $r['out']);
ok(strpos($r['out'], '--allow-raise') !== false, 'pointing at the deliberate flag instead', $r['out']);
$r = runGate($s, '--rebaseline --allow-raise');
ok($r['code'] === 0, '--rebaseline --allow-raise does raise it', $r['out']);
ok(constantOf($s, 'MAX_GROUPS_ANY') === 2, 'and the constant now matches the observed count', $r['out']);
rmtreeR($s['dir']);

$s = sandbox(BASE_CSS, 1, 2);
$r = runGate($s, '--rebaseline');
ok($r['code'] === 0 && strpos($r['out'], 'nothing to do') !== false, '--rebaseline on an already-pinned tree is a no-op', $r['out']);
rmtreeR($s['dir']);

$s = sandbox(BASE_CSS, 1, 3);
$r = runGate($s, '--rebaseline --min-decls=2');
ok($r['code'] === 2, '--rebaseline refuses a PARTIAL run — the budgets describe the whole set', $r['out']);
ok(constantOf($s, 'MAX_GROUPS_ANY') === 3, 'and changes nothing', $r['out']);
rmtreeR($s['dir']);

// ---------------------------------------------------------------------------
echo "\n4. Informational modes still never fail\n";
// ---------------------------------------------------------------------------
$s = sandbox(BASE_CSS, 0, 0);
foreach (array('--report', '--min-decls=2', '--files ' . escapeshellarg($s['dir'] . '/orkui/template/default/frontdoor/css/probe.css')) as $args) {
    $r = runGate($s, $args);
    ok($r['code'] === 0, "$args exits 0 even with both budgets blown", $r['out']);
}
rmtreeR($s['dir']);

// ---------------------------------------------------------------------------
echo "\n5. What the ratchet counts has not changed\n";
// ---------------------------------------------------------------------------
// Same body in two DIFFERENT at-rule contexts is not a duplicate; in the SAME
// one it is. This is the property the whole gate rests on, so it is re-pinned
// here beside the new two-sided logic.
$diffMedia = <<<'CSS'
@media (max-width: 700px) { .a { color: #fff; background: #000; } }
@media (max-width: 900px) { .b { color: #fff; background: #000; } }
CSS;
$s = sandbox($diffMedia, 0, 0);
$r = runGate($s);
ok($r['code'] === 0, 'identical bodies in DIFFERENT @media blocks are not a group', $r['out']);
rmtreeR($s['dir']);

$sameMedia = <<<'CSS'
@media (max-width: 700px) { .a { color: #fff; background: #000; } }
@media (max-width: 700px) { .b { color: #fff; background: #000; } }
CSS;
$s = sandbox($sameMedia, 0, 0);
$r = runGate($s);
ok($r['code'] === 1 && strpos($r['out'], 'DUPLICATION ROSE') !== false, 'identical bodies in the SAME @media block are', $r['out']);
rmtreeR($s['dir']);

// A collapsed group is one rule, and the ratchet sees the drop.
$collapsed = <<<'CSS'
.a, .b { color: #fff; background: #000; }
.c { display: flex; }
.d { display: flex; }
.solo { margin: 4px; }
CSS;
$s = sandbox($collapsed, 1, 2);
$r = runGate($s);
ok(
    $r['code'] === 1 && strpos($r['out'], 'DUPLICATION FELL') !== false,
    'collapsing a group by SELECTOR GROUPING trips the tighten-me failure',
    $r['out']
);
$r = runGate($s, '--rebaseline');
ok(
    $r['code'] === 0 && constantOf($s, 'MAX_GROUPS_2PLUS') === 0 && constantOf($s, 'MAX_GROUPS_ANY') === 1,
    'and one re-baseline pins the improvement permanently',
    $r['out']
);
rmtreeR($s['dir']);

// ---------------------------------------------------------------------------
echo "\n6. The repo's own pinned budgets are exact\n";
// ---------------------------------------------------------------------------
$cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1';
exec($cmd, $liveLines, $liveCode);
ok(
    $liveCode === 0,
    'bin/check-css-duplication.php passes on the real tree (neither risen nor fallen)',
    implode("\n", $liveLines)
);

echo "\n";
printf("%d PASS, %d FAIL\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
