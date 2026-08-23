#!/usr/bin/env php
<?php

/**
 * check-css-duplication.php — the CMS CSS DRY gate.
 *
 * stylelint can see a duplicate SELECTOR (`no-duplicate-selectors`) and a duplicate
 * PROPERTY inside one block, but it has no rule for the defect that actually
 * accumulates here: N different selectors carrying a byte-identical declaration
 * BODY, i.e. one component copied N times under N class prefixes. That is what
 * Task 2.3 and the F2 follow-up spent their time collapsing, and until this script
 * existed nothing stopped it growing back.
 *
 * What it reports: groups of rules that share an identical declaration body AND an
 * identical at-rule context. The at-rule context matters — two rules with the same
 * body inside two different `@media` blocks are NOT duplicates and cannot be
 * collapsed onto one selector list. An earlier pass got that wrong; this script is
 * the reason it cannot happen quietly again.
 *
 * Two budgets:
 *   MAX_GROUPS_2PLUS  bodies with >= 2 declarations. The real DRY signal: a copied
 *                     component almost always shares more than one declaration.
 *   MAX_GROUPS_ANY    every duplicate body, single-declaration coincidences
 *                     included (two unrelated rules that both say `display:flex`).
 *                     Noisier, so it gets its own, larger budget.
 *
 * A RATCHET, NOT A FREEZE — enforced in BOTH directions.
 *   observed >  budget   FAIL. New duplication. Collapse it, or justify it and
 *                        raise the constant in the same commit.
 *   observed <  budget   FAIL, with a different message and a different remedy:
 *                        duplication IMPROVED and the budget has to be tightened
 *                        so the improvement is permanent.
 *   observed == budget   pass.
 *
 * The second case is the whole point of this file's rewrite. The budgets are set
 * EQUAL to the observed counts and only ever moved by hand, so a gate that failed
 * upward alone was a freeze: collapse a group today, the count drops, the gate
 * stays green, and the slack sits there for the next commit to spend on a fresh
 * copy — with the gate green the whole time. Duplication could never improve on
 * paper, because nothing ever lowered the number. Making slack itself a failure
 * captures every improvement the moment it happens.
 *
 * Usage:
 *   bin/check-css-duplication.php               # the CMS CSS set; enforce both budgets
 *   bin/check-css-duplication.php --verbose     # ... and list every group with locations
 *   bin/check-css-duplication.php --report      # list + counts, never fails (exit 0)
 *   bin/check-css-duplication.php --rebaseline  # pin the observed counts (see below)
 *   bin/check-css-duplication.php --min-decls=3 # only bodies with >= 3 declarations
 *   bin/check-css-duplication.php --files a.css b.css
 *
 * RE-BASELINING IS ONE COMMAND:
 *
 *     npm run lint:css:dupes:rebaseline
 *
 * It rewrites the two constants below to the observed counts and prints the
 * before/after. It LOWERS them freely; it refuses to RAISE either one unless you
 * also pass --allow-raise, because raising a budget is how duplication gets
 * laundered through a gate and should cost a deliberate keystroke and a sentence
 * in the commit message. Either way the edit lands in your working tree — commit
 * it with the change that moved the number, never on its own.
 *
 * ESCAPE HATCH, mirroring the layering gate's ORK3_ALLOW_LAYER_VIOLATION=1:
 *
 *     CSS_DUP_ALLOW_SLACK=1 npm run lint:css
 *
 * forgives "observed below budget" for one run, for a work-in-progress branch
 * that is mid-cleanup and not ready to pin a floor. It forgives ONLY that
 * direction — it can never let duplication rise.
 */

// ---------------------------------------------------------------------------
// The ratchet. See RE-BASELINING above before touching either number.
// Last set: 2026-08-22, after F4 lifted the OGRE admin templates' inline <style>
// blocks into cms-admin.css (22 -> 26 and 78 -> 90).
//
// This is a COVERAGE re-baseline, not a duplication re-baseline. Not one
// duplicate body was authored: 185 lines of CSS that had always been byte-
// identical to rules in cms-admin.css were sitting inside <style> elements in
// Cms_sites.tpl / Cms_media.tpl / Cms_nav.tpl / cms/_block_editor.tpl, where no
// stylesheet analyser could see them. Moving them into the stylesheet is what
// made the pre-existing duplication visible; the ratchet is being told what the
// directory actually contained all along. The four newly VISIBLE >= 2-decl
// groups, all of them cms-admin.css against itself:
//   font-weight:600;color:var(--ork-text)                    .cms-table .cms-pg-title / .cms-sites-org / .cms-nav-label
//   background:var(--ork-badge-green-bg);color:...-green-text  .cms-badge-published / .cms-site-badge-published
//   background:var(--ork-badge-gray-bg);color:...-gray-text    .cms-badge-draft / .cms-site-badge-unbuilt
//   font-size:12.5px;color:var(--ork-text-muted)             .cms-quick-text span / .cms-sites-count
// (A fifth existing group, the gold :hover link body, gained a third member:
// a.cms-sites-slug:hover.) They are collapsible by selector grouping and are
// the obvious next cleanup — deliberately NOT done here, because collapsing
// them means moving rules across ~2,000 lines of a file this task was scoped
// to leave working, and F4's contract was no rendering change.
// ---------------------------------------------------------------------------
//
// 2026-08-22, P1 (authored body-copy links): ANY 90 -> 91, 2PLUS unchanged at 26.
// One new group, one declaration wide:
//   color:var(--pk-link, var(--fd-accent))
//     frontdoor.css   html[data-theme="dark"] .fd-body-text a
//     orkshell-interop.css  html[data-theme="dark"] #theme_container .fd-body-text a
// It is deliberate and NOT collapsible by selector grouping, which is the only
// collapse this gate accepts: a selector list lives in exactly one file, and
// these two selectors cannot share one. frontdoor.css is public-tier and may not
// name #theme_container (C1 in bin/check-css-boundaries.sh); orkshell-interop.css
// is never loaded by a standalone org site, which still needs the declaration.
// The copies therefore serve disjoint tiers — the ORK-outranks-CMS armour the
// interop file exists to hold, the same shape cms-admin.css already carries for
// .cms-btn-primary. Both rules carry a comment saying so.
// ---------------------------------------------------------------------------
//
// 2026-08-22, P3 (ratchet tightens as well as holds): ANY 91 -> 90, 2PLUS
// unchanged at 26. The first TIGHTENING step, and the first one this gate would
// have demanded on its own. The largest duplicate body in the CMS CSS was seven
// copies of `color:var(--cms-gold,#f0b429)` scattered across ~2,300 lines of
// cms-admin.css; they are now one grouped rule under a "Gold accent text"
// comment, placed at the position of .cms-editbar-hint-dirty — the one member
// whose cascade position is load-bearing, because it overrides .cms-editbar-hint
// at EQUAL specificity and only source order makes it win.
//
// Equivalence was proved, not assumed. Specificity is unchanged by a move, so
// the only way a move can change a rendered value on any DOM is by flipping a
// cascade pair that shares a property at equal specificity in the same at-rule
// context. All 54,778 such ordered pairs in the file were enumerated before and
// after; exactly 4 flipped, and all 4 are between class pairs that never appear
// together on one element in any of the 4,203 distinct class attributes the repo
// emits (.cms-rail-icon vs .cms-crumb / .cms-icon-danger, .cms-dash-livelink vs
// .kn-ac-item / .te-btn-ghost). Resolving every property for 4,558 modelled
// elements gave a byte-identical 215,133-line snapshot before and after.
//
// The next-largest group, 6 copies of `display:flex`, is NOT collapsible: four
// live in cms-admin.css and two in blocks.css, and a selector list lives in
// exactly one file, so no grouping can take that group below two members.
// ---------------------------------------------------------------------------
const MAX_GROUPS_2PLUS = 26;
const MAX_GROUPS_ANY   = 89;

// The CMS CSS set — the same glob pair `npm run lint:css` passes to stylelint.
const CSS_GLOBS = array(
    'orkui/template/default/frontdoor/css/*.css',
    'orkui/template/default/cms/css/*.css',
);

/**
 * Strip CSS comments, string-aware.
 *
 * A naive /\*.*?\*\/ regex is blinded by `content: "/*"` — the quoted opener eats
 * the rest of the file and every rule after it stops being analysed. Walking the
 * text with quote tracking costs nothing and cannot be fooled that way.
 */
function cssStripComments($src)
{
    $out   = '';
    $len   = strlen($src);
    $quote = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $src[$i];
        if ($quote !== '') {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $out .= $src[$i + 1];
                $i++;
            } elseif ($c === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($c === '"' || $c === "'") {
            $quote = $c;
            $out  .= $c;
            continue;
        }
        if ($c === '/' && $i + 1 < $len && $src[$i + 1] === '*') {
            $end = strpos($src, '*/', $i + 2);
            if ($end === false) {
                fwrite(STDERR, "  ! unterminated /* comment — the rest of the file was not analysed\n");
                return $out;
            }
            // Keep the newlines so reported line numbers stay honest.
            $out .= str_repeat("\n", substr_count(substr($src, $i, $end - $i), "\n"));
            $i    = $end + 1;
            continue;
        }
        $out .= $c;
    }
    return $out;
}

/**
 * Parse a stylesheet into rules, tracking at-rule nesting.
 *
 * @return array<int, array{at:string, sel:string, body:string, file:string, line:int}>
 */
function cssRules($file)
{
    $src     = cssStripComments((string) file_get_contents($file));
    $rules   = array();
    $stack   = array();
    $buf     = '';
    $len     = strlen($src);
    $line    = 1;
    $bufLine = 1;

    for ($i = 0; $i < $len; $i++) {
        $c = $src[$i];
        if ($c === "\n") {
            $line++;
        }
        if ($c === '{') {
            $prelude = trim(preg_replace('/\s+/', ' ', $buf));
            if ($prelude !== '' && $prelude[0] === '@') {
                $stack[] = $prelude;
                $buf     = '';
                $bufLine = $line;
                continue;
            }
            $depth = 1;
            $body  = '';
            for ($j = $i + 1; $j < $len && $depth > 0; $j++) {
                if ($src[$j] === '{') {
                    $depth++;
                } elseif ($src[$j] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                if ($src[$j] === "\n") {
                    $line++;
                }
                $body .= $src[$j];
            }
            $rules[] = array(
                'at'   => implode(' >> ', $stack),
                'sel'  => $prelude,
                'body' => $body,
                'file' => $file,
                'line' => $bufLine,
            );
            $i       = $j;
            $buf     = '';
            $bufLine = $line;
            continue;
        }
        if ($c === '}') {
            array_pop($stack);
            $buf     = '';
            $bufLine = $line;
            continue;
        }
        if ($c === ';' && trim($buf) !== '' && substr(trim($buf), 0, 1) === '@') {
            $buf     = '';   // @import / @charset statement, no block
            $bufLine = $line;
            continue;
        }
        if (trim($buf) === '' && trim($c) === '') {
            $bufLine = $line;
        }
        $buf .= $c;
    }

    return $rules;
}

/** Canonical declaration body: order preserved, whitespace collapsed, property lowercased. */
function cssNormalizeBody($body)
{
    $out = array();
    foreach (explode(';', $body) as $decl) {
        $decl = trim(preg_replace('/\s+/', ' ', $decl));
        if ($decl === '') {
            continue;
        }
        $colon = strpos($decl, ':');
        if ($colon !== false) {
            $decl = strtolower(trim(substr($decl, 0, $colon))) . ':' . trim(substr($decl, $colon + 1));
        }
        $out[] = $decl;
    }
    return $out;
}

/**
 * Locate a constant's definition in THIS file.
 *
 * The failure message prints the exact line to edit, so it has to FIND the line
 * rather than quote a number that goes stale the first time anyone adds a
 * paragraph to the comment above it.
 *
 * @return array{line:int, text:string}|null
 */
function dupConstantLine($name)
{
    foreach (file(__FILE__) as $i => $text) {
        if (preg_match('/^\s*const\s+' . preg_quote($name, '/') . '\s*=/', $text)) {
            return array('line' => $i + 1, 'text' => rtrim($text, "\r\n"));
        }
    }
    return null;
}

/**
 * Rewrite this file's budget constants in place.
 *
 * Lowers freely; refuses to raise unless $allowRaise, because raising a budget
 * is how new duplication gets laundered through the gate and should never be
 * something a convenience command does on its own.
 *
 * @param  array<string,int> $values  constant name => observed count
 * @param  bool              $allowRaise
 * @return array{changed:array<string,array{0:int,1:int}>, refused:array<string,array{0:int,1:int}>}
 */
function dupRebaseline(array $values, $allowRaise)
{
    $src     = (string) file_get_contents(__FILE__);
    $changed = array();
    $refused = array();
    foreach ($values as $name => $new) {
        $src = preg_replace_callback(
            '/^([ \t]*const[ \t]+' . preg_quote($name, '/') . '[ \t]*=[ \t]*)(\d+)([ \t]*;)/m',
            function ($m) use (&$changed, &$refused, $name, $new, $allowRaise) {
                $old = (int) $m[2];
                if ($old === $new) {
                    return $m[0];
                }
                if ($new > $old && !$allowRaise) {
                    $refused[$name] = array($old, $new);
                    return $m[0];
                }
                $changed[$name] = array($old, $new);
                return $m[1] . $new . $m[3];
            },
            $src,
            1
        );
    }
    if ($changed) {
        file_put_contents(__FILE__, $src);
    }
    return array('changed' => $changed, 'refused' => $refused);
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------
$verbose    = false;
$report     = false;
$rebaseline = false;
$allowRaise = false;
$minDecls   = 1;
$files      = array();
$mode       = 'all';

$argvRest = array_slice($argv, 1);
for ($i = 0; $i < count($argvRest); $i++) {
    $a = $argvRest[$i];
    if ($a === '--verbose' || $a === '-v') {
        $verbose = true;
    } elseif ($a === '--report') {
        $report  = true;
        $verbose = true;
    } elseif ($a === '--rebaseline') {
        $rebaseline = true;
    } elseif ($a === '--allow-raise') {
        $allowRaise = true;
    } elseif ($a === '--all') {
        $mode = 'all';
    } elseif (strpos($a, '--min-decls=') === 0) {
        $minDecls = max(1, (int) substr($a, strlen('--min-decls=')));
    } elseif ($a === '--files') {
        $mode  = 'files';
        $files = array_slice($argvRest, $i + 1);
        break;
    } elseif ($a === '--help' || $a === '-h') {
        echo "usage: bin/check-css-duplication.php [--all|--files a.css …] [--verbose|--report]\n";
        echo "                                     [--min-decls=N] [--rebaseline [--allow-raise]]\n";
        echo "\n";
        echo "  The budgets are a two-sided ratchet: observed above the budget fails (new\n";
        echo "  duplication), observed below it fails too (an improvement nobody pinned).\n";
        echo "  --rebaseline pins the observed counts; it lowers freely and refuses to raise\n";
        echo "  without --allow-raise. CSS_DUP_ALLOW_SLACK=1 forgives the below-budget\n";
        echo "  direction for one run, and only that direction.\n";
        exit(0);
    } else {
        fwrite(STDERR, "unknown argument: $a\n");
        exit(2);
    }
}

$root = dirname(__DIR__);
if ($mode === 'all') {
    foreach (CSS_GLOBS as $glob) {
        foreach (glob($root . '/' . $glob) as $f) {
            $files[] = $f;
        }
    }
}
$files = array_values(array_filter($files, 'is_file'));
if (!$files) {
    fwrite(STDERR, "no stylesheets to check\n");
    exit(2);
}
sort($files);

// ---------------------------------------------------------------------------
// Group by (at-rule context, normalized declaration body)
// ---------------------------------------------------------------------------
$groups = array();
foreach ($files as $f) {
    foreach (cssRules($f) as $r) {
        $decls = cssNormalizeBody($r['body']);
        if (!$decls) {
            continue;
        }
        $groups[$r['at'] . "\x00" . implode(';', $decls)][] = $r + array('n' => count($decls));
    }
}

$dupAny   = 0;
$dup2Plus = 0;
$listed   = array();
foreach ($groups as $key => $rules) {
    if (count($rules) < 2) {
        continue;
    }
    $n = $rules[0]['n'];
    if ($n < $minDecls) {
        continue;
    }
    $dupAny++;
    if ($n >= 2) {
        $dup2Plus++;
    }
    list($at, $body) = explode("\x00", $key, 2);
    $listed[] = array('copies' => count($rules), 'decls' => $n, 'at' => $at, 'body' => $body, 'rules' => $rules);
}
usort($listed, function ($a, $b) {
    return ($b['copies'] <=> $a['copies']) ?: ($b['decls'] <=> $a['decls']);
});

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
printf(
    "CMS CSS duplication: %d stylesheets, %d duplicate declaration-body groups (%d with >= 2 declarations)\n",
    count($files),
    $dupAny,
    $dup2Plus
);

if ($verbose) {
    foreach ($listed as $g) {
        printf(
            "\n  x%d  %s  [%s]\n      %s\n",
            $g['copies'],
            $g['decls'] === 1 ? '(1 declaration)' : '(' . $g['decls'] . ' declarations)',
            $g['at'] !== '' ? $g['at'] : 'top level',
            strlen($g['body']) > 120 ? substr($g['body'], 0, 117) . '...' : $g['body']
        );
        foreach ($g['rules'] as $r) {
            printf("        %s:%d  %s\n", basename($r['file']), $r['line'], $r['sel']);
        }
    }
    echo "\n";
}

$partial = ($mode === 'files' || $minDecls !== 1);

if ($rebaseline && $partial) {
    fwrite(STDERR, "\n--rebaseline needs the WHOLE CMS CSS set — the budgets describe all of it.\n");
    fwrite(STDERR, "Drop --files / --min-decls and run: npm run lint:css:dupes:rebaseline\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// The ratchet. Both budgets are compared in BOTH directions:
//   observed >  budget   duplication ROSE   -> fail, collapse it
//   observed <  budget   duplication FELL   -> fail, pin the improvement
//   observed == budget   held               -> pass
// ---------------------------------------------------------------------------
$budgets = array(
    'MAX_GROUPS_2PLUS' => array(
        'observed' => $dup2Plus,
        'budget'   => MAX_GROUPS_2PLUS,
        'label'    => 'duplicate groups with >= 2 declarations',
    ),
    'MAX_GROUPS_ANY' => array(
        'observed' => $dupAny,
        'budget'   => MAX_GROUPS_ANY,
        'label'    => 'duplicate groups (any size)',
    ),
);

if ($rebaseline) {
    $wanted = array();
    foreach ($budgets as $name => $b) {
        $wanted[$name] = $b['observed'];
    }
    $res = dupRebaseline($wanted, $allowRaise);
    foreach ($res['changed'] as $name => $ch) {
        printf("REBASELINED  %-17s %d -> %d  (%s)\n", $name, $ch[0], $ch[1], $ch[1] < $ch[0] ? 'tightened' : 'RAISED');
    }
    foreach ($res['refused'] as $name => $ch) {
        printf("REFUSED      %-17s %d -> %d  would RAISE the budget.\n", $name, $ch[0], $ch[1]);
    }
    if ($res['refused']) {
        echo "\n";
        echo "Raising a budget is how new duplication gets laundered through this gate, so\n";
        echo "it is not something a convenience command does silently. Collapse the new\n";
        echo "group instead — or, if the duplication is genuinely deliberate, put a comment\n";
        echo "on the rule saying why and re-run:\n";
        echo "\n";
        echo "    php bin/check-css-duplication.php --rebaseline --allow-raise\n";
        echo "\n";
        exit(1);
    }
    if (!$res['changed']) {
        echo "REBASELINE   nothing to do — both budgets already match the observed counts.\n";
        exit(0);
    }
    echo "\n";
    echo "Constants updated in bin/check-css-duplication.php. Commit that edit TOGETHER\n";
    echo "with the change that moved the numbers, and say why in the commit message.\n";
    exit(0);
}

if ($report || $partial) {
    // Ad-hoc / partial runs are informational: the budgets describe the whole set.
    exit(0);
}

$rose = array();
$fell = array();
foreach ($budgets as $name => $b) {
    if ($b['observed'] > $b['budget']) {
        $rose[$name] = $b;
    } elseif ($b['observed'] < $b['budget']) {
        $fell[$name] = $b;
    }
}

foreach ($rose as $name => $b) {
    printf(
        "FAIL  ^ DUPLICATION ROSE   %-40s %d, pinned budget %d  (+%d).\n",
        $b['label'] . ':',
        $b['observed'],
        $b['budget'],
        $b['observed'] - $b['budget']
    );
}
foreach ($fell as $name => $b) {
    printf(
        "FAIL  v DUPLICATION FELL   %-40s %d, pinned budget %d  (%d).\n",
        $b['label'] . ':',
        $b['observed'],
        $b['budget'],
        $b['observed'] - $b['budget']
    );
}

if ($rose) {
    echo "\n";
    echo "Duplication went UP. A copied declaration body is the defect this gate exists\n";
    echo "for, so the budget did not move for you.\n";
    echo "\n";
    echo "Collapse the new group by SELECTOR GROUPING — put the selectors on one rule at\n";
    echo "the position of the FIRST member. Do not rename classes: block templates and\n";
    echo "authored pages depend on the existing names. Two placement traps:\n";
    echo "  - members in different at-rule contexts cannot share a rule at all;\n";
    echo "  - the grouped rule must still sit AFTER any base rule it overrides at the\n";
    echo "    same specificity, which is not always the first member's position.\n";
    echo "Run with --verbose to see every group and where its copies live.\n";
    echo "\n";
    echo "If the duplication is genuinely deliberate — the copies serve tiers that can\n";
    echo "never share a selector list — say so in a comment on BOTH rules and run:\n";
    echo "\n";
    echo "    php bin/check-css-duplication.php --rebaseline --allow-raise\n";
    echo "\n";
    echo "CSS_DUP_ALLOW_SLACK=1 does NOT forgive this direction. It never will.\n";
    echo "\n";
}

if ($fell) {
    if (getenv('CSS_DUP_ALLOW_SLACK') === '1' && !$rose) {
        echo "\n";
        echo "!  CSS_DUP_ALLOW_SLACK=1 — running below the pinned budget without tightening it.\n";
        echo "   Fine mid-cleanup; pin the floor before this branch merges:\n";
        echo "       npm run lint:css:dupes:rebaseline\n";
        printf("OK    slack allowed (%d / %d groups with >= 2 declarations, %d / %d overall)\n", $dup2Plus, MAX_GROUPS_2PLUS, $dupAny, MAX_GROUPS_ANY);
        exit(0);
    }
    echo "\n";
    echo "Duplication went DOWN — that is the GOOD direction, and this is not a complaint\n";
    echo "about your CSS. It is a complaint about the constant. A budget nobody lowers is\n";
    echo "a floor duplication bounces off forever: the slack you just created is slack the\n";
    echo "next commit can spend on a fresh copy with this gate green the whole time.\n";
    echo "Pin the improvement and it can never be given back silently.\n";
    echo "\n";
    echo "One command:\n";
    echo "\n";
    echo "    npm run lint:css:dupes:rebaseline\n";
    echo "\n";
    echo "or edit this file by hand:\n";
    echo "\n";
    foreach ($fell as $name => $b) {
        $at = dupConstantLine($name);
        printf("    bin/check-css-duplication.php:%d\n", $at ? $at['line'] : 0);
        printf("      - %s\n", $at ? ltrim($at['text']) : "const $name = {$b['budget']};");
        printf("      + %s\n", preg_replace('/=\s*\d+\s*;/', '= ' . $b['observed'] . ';', $at ? ltrim($at['text']) : "const $name = {$b['observed']};"));
        echo "\n";
    }
    echo "Mid-cleanup and not ready to pin a floor? For one run only:\n";
    echo "\n";
    echo "    CSS_DUP_ALLOW_SLACK=1 npm run lint:css\n";
    echo "\n";
}

if ($rose || $fell) {
    exit(1);
}

printf("OK    ratchet held (%d / %d groups with >= 2 declarations, %d / %d overall)\n", $dup2Plus, MAX_GROUPS_2PLUS, $dupAny, MAX_GROUPS_ANY);
exit(0);
