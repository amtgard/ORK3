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
 * Two ratchets, both enforced:
 *   MAX_GROUPS_2PLUS  bodies with >= 2 declarations. The real DRY signal: a copied
 *                     component almost always shares more than one declaration.
 *   MAX_GROUPS_ANY    every duplicate body, single-declaration coincidences
 *                     included (two unrelated rules that both say `display:flex`).
 *                     Noisier, so it gets its own, larger budget.
 * Either number growing fails the run. Neither shrinking does — tighten the
 * constant when you collapse a group, and the ratchet holds the new floor.
 *
 * Usage:
 *   bin/check-css-duplication.php               # the CMS CSS set; enforce both budgets
 *   bin/check-css-duplication.php --verbose     # ... and list every group with locations
 *   bin/check-css-duplication.php --report      # list + counts, never fails (exit 0)
 *   bin/check-css-duplication.php --min-decls=3 # only bodies with >= 3 declarations
 *   bin/check-css-duplication.php --files a.css b.css
 *
 * RE-BASELINING. When you deliberately add duplication (or add a stylesheet that
 * arrives with some), run with --report, take the two printed counts, and edit the
 * two constants below — in the same commit, with the reason in the commit message.
 * Lowering them after a cleanup needs no ceremony. Do not raise them to get a
 * commit through; that is what the numbers are for.
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
const MAX_GROUPS_2PLUS = 26;
const MAX_GROUPS_ANY   = 91;

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

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------
$verbose  = false;
$report   = false;
$minDecls = 1;
$files    = array();
$mode     = 'all';

$argvRest = array_slice($argv, 1);
for ($i = 0; $i < count($argvRest); $i++) {
    $a = $argvRest[$i];
    if ($a === '--verbose' || $a === '-v') {
        $verbose = true;
    } elseif ($a === '--report') {
        $report  = true;
        $verbose = true;
    } elseif ($a === '--all') {
        $mode = 'all';
    } elseif (strpos($a, '--min-decls=') === 0) {
        $minDecls = max(1, (int) substr($a, strlen('--min-decls=')));
    } elseif ($a === '--files') {
        $mode  = 'files';
        $files = array_slice($argvRest, $i + 1);
        break;
    } elseif ($a === '--help' || $a === '-h') {
        echo "usage: bin/check-css-duplication.php [--all|--files a.css …] [--verbose|--report] [--min-decls=N]\n";
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

if ($report || $mode === 'files' || $minDecls !== 1) {
    // Ad-hoc / partial runs are informational: the budgets describe the whole set.
    exit(0);
}

$fail = false;
if ($dup2Plus > MAX_GROUPS_2PLUS) {
    printf(
        "FAIL  duplicate groups with >= 2 declarations: %d, budget %d.\n",
        $dup2Plus,
        MAX_GROUPS_2PLUS
    );
    $fail = true;
}
if ($dupAny > MAX_GROUPS_ANY) {
    printf(
        "FAIL  duplicate groups (any size): %d, budget %d.\n",
        $dupAny,
        MAX_GROUPS_ANY
    );
    $fail = true;
}

if ($fail) {
    echo "\n";
    echo "Collapse the new group by SELECTOR GROUPING — put the selectors on one rule at\n";
    echo "the position of the FIRST member. Do not rename classes: block templates and\n";
    echo "authored pages depend on the existing names. Two placement traps:\n";
    echo "  - members in different at-rule contexts cannot share a rule at all;\n";
    echo "  - the grouped rule must still sit AFTER any base rule it overrides at the\n";
    echo "    same specificity, which is not always the first member's position.\n";
    echo "Run with --verbose to see every group and where its copies live.\n";
    echo "If the duplication is deliberate, say why in a comment on the rule and raise\n";
    echo "the constant in this file in the same commit.\n";
    exit(1);
}

printf("OK    within budget (%d / %d groups with >= 2 declarations, %d / %d overall)\n", $dup2Plus, MAX_GROUPS_2PLUS, $dupAny, MAX_GROUPS_ANY);
exit(0);
