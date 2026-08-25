<?php

// tests/cms-fields/allowlist_test.php — run: php tests/cms-fields/allowlist_test.php
//
// E36 / #36 contract test. The front-door block partials
// (orkui/template/default/frontdoor/blocks/*.tpl) are plain PHP rendered via
// extract()+include. A block field value is XSS-safe to echo RAW (no
// htmlspecialchars at the echo) ONLY when it was sanitized at save — i.e. its
// field name is in the single source of truth CmsPage::HTML_FIELDS (body/html,
// run through CmsSanitizer::Clean before storage). Every OTHER block field must
// be escaped before it reaches the browser.
//
// This test recurses the block partials, statically tracks which local variables
// (and array reads) are UNESCAPED block-field values, and asserts that any such
// value emitted through a raw PHP short-echo tag either:
//   (a) is neutralized at the echo (htmlspecialchars/htmlentities/urlencode/
//       json_encode/numeric-cast/count/... ), OR
//   (b) is neutralized/validated upstream at its assignment (the block partials
//       routinely pre-escape into a local, then echo it raw), OR
//   (c) names a field listed in CmsPage::HTML_FIELDS (sanitized at save).
// Any raw-echoed block field that satisfies none of these is a stored-XSS hole:
// the test fails (exit nonzero).
//
// Only block FIELD values are in scope (per the E36 contract): values sourced
// from $blockFields and containers derived from it. Server-data feeds
// ($EventSummary, model reads, etc.) are a different trust boundary and out of
// scope here.

$root = dirname(__DIR__, 2);

$fails = 0;
function check($label, $cond)
{
    global $fails;
    if ($cond) {
        echo "PASS  $label\n";
    } else {
        echo "FAIL  $label\n";
        $fails++;
    }
}

// ---------------------------------------------------------------------------
// Resolve CmsPage::HTML_FIELDS — the single source of truth (E36).
// CmsPage extends the framework CmsBase/Ork3, which are not bootstrapped in a
// bare `php` run, so stub the minimal parents and load the class to read the
// field list by reflection. Prefer the PUBLIC const (the E36 target); fall back
// to the pre-refactor private static, then to a source-parse, so this test is
// robust to the concurrent lib-content shard landing the const before/after it.
// ---------------------------------------------------------------------------
function cmsHtmlFields($root)
{
    $file = $root . '/system/lib/ork3/class.CmsPage.php';

    if (!defined('DB_PREFIX')) {
        define('DB_PREFIX', 'ork_');
    }
    // Minimal framework base so `class CmsBase extends Ork3` loads under a
    // bare `php` run. Declared, not eval'd, and guarded so a second call is a
    // no-op.
    if (!class_exists('Ork3', false)) {
        class Ork3
        {
            public function __construct()
            {
            }
        }
    }
    if (!class_exists('CmsBase', false)) {
        class CmsBase extends Ork3
        {
        }
    }

    $source = 'const';
    if (!class_exists('CmsPage', false)) {
        try {
            require $file;
        } catch (\Throwable $e) {
            // fall through to source-parse below
        }
    }

    if (class_exists('CmsPage', false)) {
        $rc = new ReflectionClass('CmsPage');
        if ($rc->hasConstant('HTML_FIELDS')) {
            return array((array) $rc->getConstant('HTML_FIELDS'), 'const');
        }
        if ($rc->hasProperty('HTML_FIELDS')) {
            $rp = $rc->getProperty('HTML_FIELDS');
            $rp->setAccessible(true);
            return array((array) $rp->getValue(), 'private-static');
        }
    }

    // Last-resort: parse the array literal straight out of the source.
    $src = @file_get_contents($file);
    if ($src !== false && preg_match('/HTML_FIELDS\s*=\s*array\(([^)]*)\)/', $src, $m)) {
        preg_match_all('/\'([a-z_]+)\'/i', $m[1], $mm);
        if (!empty($mm[1])) {
            return array($mm[1], 'source-parse');
        }
    }
    return array(array('body', 'html'), 'hardcoded-fallback');
}

list($HTML_FIELDS, $fieldSource) = cmsHtmlFields($root);
echo "Using CmsPage::HTML_FIELDS via [$fieldSource]: " . implode(', ', $HTML_FIELDS) . "\n\n";
check('HTML_FIELDS resolved and non-empty', is_array($HTML_FIELDS) && count($HTML_FIELDS) > 0);
check('HTML_FIELDS includes body', in_array('body', $HTML_FIELDS, true));
check('HTML_FIELDS includes html', in_array('html', $HTML_FIELDS, true));

// ---------------------------------------------------------------------------
// Static analyzer.
// ---------------------------------------------------------------------------

// A neutralizing/validating construct anywhere in an expression makes its value
// safe to emit — HTML-escaped, numeric/boolean-typed, or character-whitelisted.
function fdHasNeutralizer($expr)
{
    return (bool) preg_match(
        '/\b(htmlspecialchars|htmlentities|urlencode|rawurlencode|json_encode|number_format|intval|floatval|in_array|count|mb_strtoupper|nl2br|preg_replace|preg_match|is_array|is_string|is_numeric|is_int|is_bool|empty|isset|strlen|ctype_\w+)\s*\(/',
        $expr
    ) || preg_match('/\(\s*(int|float|bool|integer)\s*\)/', $expr);
}

// Does an expression compute/derive a value (a ternary or a comparison/boolean)
// rather than pass a raw value straight through? Such results are developer-chosen
// (literal branches, booleans) — not the field's own text — so they are safe.
// Callers strip any `?? default` / `?: default` first so only real ternaries trip
// the `?` check.
function fdIsComputed($core)
{
    return (bool) preg_match('/\?/', $core)
        || preg_match('/(===|!==|==|!=|<=|>=|<|>|&&|\|\|)/', $core);
}

// Byte-offset → 1-based line number.
function fdLineAt($src, $offset)
{
    return substr_count($src, "\n", 0, $offset) + 1;
}

/**
 * Analyze one block .tpl. Returns a list of violations:
 *   ['line' => int, 'field' => string, 'snippet' => string]
 * A violation is a raw `<?= ... ?>` echo that emits an UNESCAPED block-field
 * value whose field name is NOT in $htmlFields.
 */
function fdAnalyzeTpl($src, array $htmlFields)
{
    // 1) Discover block-field containers: $blockFields and anything derived from
    //    it (array reads with an array default, array_* transforms, and foreach
    //    item bindings over a known container).
    $containers = array('blockFields' => true);
    for ($pass = 0; $pass < 4; $pass++) {
        $before = count($containers);

        // $x = <array-ish expression referencing a known container>
        if (preg_match_all('/\$([A-Za-z_]\w*)\s*=\s*([^;]*);/s', $src, $am, PREG_SET_ORDER)) {
            foreach ($am as $a) {
                $lhs = $a[1];
                $rhs = $a[2];
                if (isset($containers[$lhs])) {
                    continue;
                }
                $refsContainer = false;
                foreach ($containers as $c => $_) {
                    if (preg_match('/\$' . preg_quote($c, '/') . '\b/', $rhs)) {
                        $refsContainer = true;
                        break;
                    }
                }
                if (!$refsContainer) {
                    continue;
                }
                $arrayish = preg_match('/\?\?\s*(\[\]|array\()/', $rhs)
                    || preg_match('/\barray_(values|filter|slice|map|merge)\s*\(/', $rhs)
                    || preg_match('/\bis_array\s*\(/', $rhs);
                if ($arrayish) {
                    $containers[$lhs] = true;
                }
            }
        }

        // foreach ($container as [$k =>] $item) → $item is a container
        if (preg_match_all('/foreach\s*\(\s*([^)]*?)\s+as\s+(?:\$\w+\s*=>\s*)?\$([A-Za-z_]\w*)\s*\)/', $src, $fm, PREG_SET_ORDER)) {
            foreach ($fm as $f) {
                $iter = $f[1];
                $item = $f[2];
                foreach ($containers as $c => $_) {
                    if (preg_match('/\$' . preg_quote($c, '/') . '\b/', $iter)) {
                        $containers[$item] = true;
                        break;
                    }
                }
            }
        }

        if (count($containers) === $before) {
            break;
        }
    }

    // A "field read" is $container['field'] (single/double-quoted bare key).
    $containerAlt = implode('|', array_map(function ($c) {
        return preg_quote($c, '/');
    }, array_keys($containers)));
    $fieldReadRe = '/\$(?:' . $containerAlt . ')\[\s*[\'"]([A-Za-z_]\w*)[\'"]\s*\]/';

    // 2) Classify each scalar local variable by its LAST assignment (straight-line
    //    order mirrors runtime for these partials; a later validating reassignment
    //    of the same name — e.g. an in_array() whitelist — correctly wins).
    //    A variable is an UNSAFE block-field ALIAS only when its assignment passes
    //    the field's raw text straight through: no neutralizer, not a computed
    //    ternary/boolean, and it either reads a block field directly (optionally
    //    concatenated) or is a pure re-alias of another unsafe alias. Everything
    //    else — derived booleans, chosen literals, sanitized values — is safe.
    //    varInfo[$name] = ['safe' => bool, 'field' => ?string]
    $varInfo = array();
    if (preg_match_all('/\$([A-Za-z_]\w*)\s*=\s*([^;]*);/s', $src, $am, PREG_SET_ORDER)) {
        foreach ($am as $a) {
            $lhs = $a[1];
            $rhs = $a[2];

            if (fdHasNeutralizer($rhs)) {
                $varInfo[$lhs] = array('safe' => true, 'field' => null);
                continue;
            }

            // Strip null-coalesce / elvis defaults so only real ternaries remain.
            $core = preg_replace('/\?\?.*$/s', '', $rhs);
            $core = preg_replace('/\?:.*$/s', '', $core);

            if (fdIsComputed($core)) {
                $varInfo[$lhs] = array('safe' => true, 'field' => null);
                continue;
            }
            // Raw block-field read (possibly concatenated into markup) → alias.
            if (preg_match($fieldReadRe, $core, $fr)) {
                $varInfo[$lhs] = array('safe' => false, 'field' => $fr[1]);
                continue;
            }
            // Pure re-alias of a single local ($y = $x) → inherit its taint.
            if (preg_match('/^\s*\$([A-Za-z_]\w*)\s*$/', $core, $vm)
                && isset($varInfo[$vm[1]]) && !$varInfo[$vm[1]]['safe']) {
                $varInfo[$lhs] = array('safe' => false, 'field' => $varInfo[$vm[1]]['field']);
                continue;
            }
            $varInfo[$lhs] = array('safe' => true, 'field' => null);
        }
    }

    // 3) Inspect every raw PHP short-echo tag.
    $violations = array();
    if (preg_match_all('/<\?=\s*(.*?)\?>/s', $src, $em, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($em as $e) {
            $expr   = $e[1][0];
            $offset = $e[0][1];
            if (fdHasNeutralizer($expr)) {
                continue; // escaped/cast at the echo site
            }

            // (a) Direct unescaped container field read in the echo.
            if (preg_match($fieldReadRe, $expr, $fr)) {
                $field = $fr[1];
                if (!in_array($field, $htmlFields, true)) {
                    $violations[] = array('line' => fdLineAt($src, $offset), 'field' => $field, 'snippet' => trim($expr));
                    continue;
                }
            }

            // (b) Referenced local that is an unescaped block-field alias.
            if (preg_match_all('/\$([A-Za-z_]\w*)\b/', $expr, $vm)) {
                foreach ($vm[1] as $ref) {
                    if (isset($varInfo[$ref]) && !$varInfo[$ref]['safe'] && $varInfo[$ref]['field'] !== null) {
                        $field = $varInfo[$ref]['field'];
                        if (!in_array($field, $htmlFields, true)) {
                            $violations[] = array('line' => fdLineAt($src, $offset), 'field' => $field, 'snippet' => trim($expr));
                            break;
                        }
                    }
                }
            }
        }
    }
    return $violations;
}

// ---------------------------------------------------------------------------
// Self-test the analyzer with synthetic fixtures (proves it can both catch a
// hole and clear the known-safe patterns) before running it on the real files.
// ---------------------------------------------------------------------------
$fixtureBad = <<<'TPL'
<?php
$title = $blockFields['title'] ?? '';
?>
<h2><?= $title ?></h2>
TPL;
$fixtureBadDirect = <<<'TPL'
<?php ?>
<div><?= $blockFields['tagline'] ?></div>
TPL;
$fixtureSafeEscaped = <<<'TPL'
<?php
$title = $blockFields['title'] ?? '';
?>
<h2><?= htmlspecialchars($title, ENT_QUOTES) ?></h2>
TPL;
$fixtureSafeHtmlField = <<<'TPL'
<?php
$body = $blockFields['body'] ?? '';
?>
<div><?= $body ?></div>
TPL;
$fixtureSafeUpstream = <<<'TPL'
<?php
$name = htmlspecialchars($blockFields['name'] ?? '', ENT_QUOTES);
?>
<div><?= $name ?></div>
TPL;
$fixtureSafeValidated = <<<'TPL'
<?php
$align = $blockFields['align'] ?? 'left';
$align = in_array($align, ['left','center','right'], true) ? $align : 'left';
?>
<div class="a-<?= $align ?>"></div>
TPL;

check('analyzer flags raw echo of aliased non-HTML field', count(fdAnalyzeTpl($fixtureBad, $HTML_FIELDS)) === 1);
check('analyzer flags raw direct field read', count(fdAnalyzeTpl($fixtureBadDirect, $HTML_FIELDS)) === 1);
check('analyzer clears escaped-at-echo', count(fdAnalyzeTpl($fixtureSafeEscaped, $HTML_FIELDS)) === 0);
check('analyzer clears HTML_FIELDS passthrough', count(fdAnalyzeTpl($fixtureSafeHtmlField, $HTML_FIELDS)) === 0);
check('analyzer clears escaped-upstream', count(fdAnalyzeTpl($fixtureSafeUpstream, $HTML_FIELDS)) === 0);
check('analyzer clears validated whitelist', count(fdAnalyzeTpl($fixtureSafeValidated, $HTML_FIELDS)) === 0);

// ---------------------------------------------------------------------------
// Run against every real front-door block partial.
// ---------------------------------------------------------------------------
$blockDir = $root . '/orkui/template/default/frontdoor/blocks';
// blocks/_shared/*.tpl holds the shared bodies that thin block adapters include
// (events.tpl, officers.tpl). The block-field echoes live THERE, not in the
// adapter, so a non-recursive glob would report a hollow PASS on the adapter
// and never look at the markup that actually renders. The analyzer is per-file
// and each shared partial is self-contained for its purposes, so scanning the
// union of both directories gives the shared markup real coverage.
$files = array_merge(
    glob($blockDir . '/*.tpl') ?: [],
    glob($blockDir . '/_shared/*.tpl') ?: []
);
check('found block partials to scan', count($files) > 0);
// Assert the SCAN LIST contains them, not merely that the directory is non-empty:
// the latter stays green if the union above regresses to a non-recursive glob,
// which is the exact silent-coverage-loss this guard exists to catch.
check('shared block partials are scanned too', count(array_filter($files, static function ($f) {
    return strpos($f, '/_shared/') !== false;
})) > 0);

$totalViolations = 0;
foreach ($files as $file) {
    $src = file_get_contents($file);
    $name = ltrim(str_replace($blockDir, '', $file), '/');
    $violations = fdAnalyzeTpl($src, $HTML_FIELDS);
    if (empty($violations)) {
        check("no raw unescaped non-HTML block field in $name", true);
    } else {
        $totalViolations += count($violations);
        foreach ($violations as $v) {
            echo "      -> $name:{$v['line']} field '{$v['field']}' echoed raw: {$v['snippet']}\n";
        }
        check("no raw unescaped non-HTML block field in $name", false);
    }
}
check('zero raw-echoed non-HTML block fields across all partials', $totalViolations === 0);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
