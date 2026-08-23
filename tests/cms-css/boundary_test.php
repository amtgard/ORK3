<?php

// tests/cms-css/boundary_test.php — run: php tests/cms-css/boundary_test.php
//
// RUNTIME backstop for the CMS/CRM CSS separation.
//
// bin/check-css-boundaries.sh is a text scanner, and every hole ever found in it
// existed because a scanner was outwitted by a spelling. The property the
// separation actually protects is observable at runtime and cannot be spelled
// around:
//
//     A standalone public org site must serve ZERO bytes of ORK CRM CSS.
//
// So this test does not read source. It fetches the real surfaces off a running
// app and asserts on the HTML that is actually served:
//
//   1. no org-site page links a CRM stylesheet — by NAME (the set is derived
//      from what actually exists in orkui/template/default/style/, plus
//      cms-admin.css and orkshell-interop.css) and by SHAPE (nothing served out
//      of a style/ directory, however the href is spelled);
//   2. the IN-SHELL surfaces (front door, Blog/index) still link orkui.css +
//      tokens.css + orkshell-interop.css — so "fixing" (1) by breaking the shell
//      fails here instead of shipping;
//   3. stylesheet ORDER holds on every surface (frontdoor → blocks → blog →
//      orgsite / orkshell-interop). Order is load-bearing: blocks.css and
//      blog.css were split off the END of frontdoor.css and several of their
//      rules win same-specificity ties against it;
//   4. blog.css is linked on exactly the blog surfaces and nowhere else;
//   5. no org-site page carries an inline <style> naming an ORK shell selector;
//   6. authored body-copy links carry a non-colour affordance on every tier;
//   7. no org-site page serves ORK's own analytics payload (gtag.js, Google Tag
//      Manager, Cloudflare Web Analytics) — and the in-shell tier still does.
//   8. no org-site page loads orkui.js (jQuery + the 1 MB CRM app bundle) while it
//      still loads its own frontdoor.js — and the in-shell tier still gets orkui.js.
//   9. the SCRIPTS an org site is served — every same-origin <script src> plus
//      every inline <script> — inject no CSS: no <style>/<link> element built in
//      the DOM, no @import, no constructed stylesheet, no CRM stylesheet name or
//      style/ path, no ORK shell selector, no --ork-* token. This is the runtime
//      mirror of C7 in bin/check-css-boundaries.sh: sections 1-4 read the served
//      HTML, and a stylesheet a script appends after load is not in it.
//
// ---------------------------------------------------------------------------
// SKIPS ARE ACCOUNTED FOR, NOT SWALLOWED
//
// This file used to skip in two ways, and BOTH of them exited 0 with no machine-
// readable trace:
//
//   * whole run — nothing answers at the base URL: it printed "SKIP: …" and
//     "SKIPPED (0 assertions run)" and exited 0;
//   * per surface — a surface did not render a CMS page: it printed
//     "  note: surface not available, skipped — org home (park ambient-forest)"
//     and carried on to print ALL PASS.
//
// The second one is the dangerous one. With the park site unpublished the run
// dropped from 227 assertions to 194 — the entire park tier and the only
// &_pfx=p coverage gone — and still printed ALL PASS, still exited 0, and CI
// (which detected a skip by grepping for a leading "SKIP:") reported an
// unqualified green. A backstop that can lose a third of itself without a
// signal is worse than no backstop: it produces false confidence.
//
// So every exit path now ends with a MACHINE-READABLE SUMMARY, always printed:
//
//     SURFACES: 9 EXPECTED, 8 COVERED, 1 SKIPPED, 0 NOT-APPLICABLE
//     SURFACE: org home (kingdom burning-lands) COVERED 33
//     SURFACE: org post (discovered) SKIPPED 0 — <why>
//     ASSERTIONS: 194 RAN, 0 FAILED
//     MODE: LENIENT
//     SKIP-KIND: PARTIAL
//     RESULT: PASS-WITH-SKIPS
//
//   RESULT is one of PASS | PASS-WITH-SKIPS | FAIL, and it is the line to parse.
//   SKIP-KIND is NONE | WHOLE-RUN (nothing answered; no surface ran) | PARTIAL
//   (the app answered and some named surface still did not run) — the two mean
//   very different things to a caller, so they are reported separately.
//
// THE EXPECTED SURFACE SET IS DERIVED, NOT PINNED. It is $surfaces below plus
// one single-post surface per tier that list covers, so adding a surface to the
// list extends the contract automatically and no constant can go stale. A total
// assertion count is deliberately NOT pinned: the per-surface count legitimately
// varies with how many stylesheets and same-origin scripts a surface serves, so
// a pinned total would be a false-failure engine. What is asserted instead is
// that every expected surface ran, and that each one ran at least one assertion;
// the per-surface counts are printed so a drop is visible in a diff of the log.
//
// ---------------------------------------------------------------------------
// "DID NOT RUN" AND "CANNOT EXIST" ARE DIFFERENT ANSWERS
//
// The expected set is derived, which is right — but it derived one single-post
// surface PER TIER unconditionally, and a post is DATA. A stock local database
// has a global post and no kingdom- or park-scoped one, so `--strict` — the
// command the README tells you to run before merging — exited 1 on a clean
// checkout, every time, naming a "coverage hole" nobody could close without
// authoring content. A documented pre-merge check that is red by default teaches
// people to ignore it, which costs more than the surface it was reporting.
//
// A surface that CANNOT exist in the current data is now NOT-APPLICABLE rather
// than SKIPPED. It is still expected, still listed, still counted — it simply is
// not a coverage loss, because there is no coverage available to lose.
//
// THE DISTINCTION IS DERIVED FROM THE DATA, NOT DECLARED. Hardcoding "the org
// post surface is optional" would be exactly the swallow this file exists to
// end: it would forgive a REAL skip forever. Instead the app is asked, in the
// machine-readable form it already publishes — the RSS feed of the very scope
// the surface belongs to (Site/rss/{slug} per org site, Blog/rss for the shell
// tier). Every covered surface of the tier contributes its scope's feed:
//
//   * every feed answers, and they carry ZERO <item> elements in total
//         → no published post exists in any scope this run covers
//         → NOT-APPLICABLE. Nothing rendered it because nothing is there.
//   * any feed carries an <item>, or a post link was found and would not render
//         → the surface SHOULD exist and did not
//         → SKIPPED, i.e. still a hard failure under --strict.
//   * no feed derivable for the tier, or a feed did not answer, or did not parse
//         → cannot prove absence
//         → SKIPPED. Fail closed: "cannot tell" is not "not applicable".
//
// So the forgiving path is only ever reached on the app's own evidence that the
// data is empty, and the moment someone publishes a kingdom post the surface
// becomes required again with no edit to this file. (The feeds are GhettoCached
// per scope for 1800s, the same cache that serves the index pages the discovery
// reads, so a post published seconds ago can read as absent on both — restart
// the app container after a DB change, exactly as for any other CMS probe.)
//
// TWO MODES:
//
//   LENIENT (default) — a skip is reported, RESULT is PASS-WITH-SKIPS, exit 0.
//     Keeps the script usable on a laptop with the app down, and safe to drop
//     into a for-loop over tests/cms-*/.
//   STRICT (--strict, or CMS_CSS_STRICT=1) — ANY skip, whole-run or per-surface,
//     is a FAILURE: RESULT is FAIL and the exit status is 1. This is the mode to
//     run anywhere that is supposed to have a fully populated CMS database.
//
// Point it at another host with ORK_BASE_URL:
//
//     ORK_BASE_URL=http://localhost:19080 php tests/cms-css/boundary_test.php
//     CMS_CSS_STRICT=1 php tests/cms-css/boundary_test.php
//     php tests/cms-css/boundary_test.php --strict
//
// Design: docs/superpowers/specs/2026-08-21-cms-css-separation-design.md
// Working reference: orkui/template/default/frontdoor/css/README.md

$BASE = (string) getenv('ORK_BASE_URL');
if ($BASE === '') {
    $BASE = 'http://localhost:19080';
}
$BASE = rtrim($BASE, '/');
$UI   = $BASE . '/orkui/index.php?Route=';

// ---------------------------------------------------------------------------
// Mode
// ---------------------------------------------------------------------------
$STRICT = ((string) getenv('CMS_CSS_STRICT') === '1');
foreach (array_slice(isset($argv) ? $argv : array(), 1) as $arg) {
    if ($arg === '--strict') {
        $STRICT = true;
    } elseif ($arg === '--lenient') {
        $STRICT = false;
    } else {
        fwrite(STDERR, "usage: php tests/cms-css/boundary_test.php [--strict|--lenient]\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// Accounting. Every assertion is attributed to the surface it was made against,
// so a surface that did not run is visible as an absence rather than inferred
// from a total that nobody knows the right value of.
// ---------------------------------------------------------------------------
$fails    = 0;
$ran      = 0;
$SURFACE  = '(setup)';          // the surface check() attributes to right now
$COVERED  = array();            // label => assertions run against it
$SKIPPED  = array();            // label => why it did not run
$NA       = array();            // label => the EVIDENCE that it cannot exist here

/** Attribute every following check() to $label. */
function surface($label)
{
    global $SURFACE, $COVERED;
    $SURFACE = $label;
    if (!array_key_exists($label, $COVERED)) {
        $COVERED[$label] = 0;
    }
}

function check($label, $cond)
{
    global $fails, $ran, $SURFACE, $COVERED;
    $ran++;
    $COVERED[$SURFACE] = (isset($COVERED[$SURFACE]) ? $COVERED[$SURFACE] : 0) + 1;
    if ($cond) {
        echo "PASS  $label\n";
    } else {
        echo "FAIL  $label\n";
        $fails++;
    }
}

/** Record a surface that could not be exercised, and why. */
function skip_surface($label, $why)
{
    global $SKIPPED;
    $SKIPPED[$label] = $why;
    echo "  note: surface not available, skipped — $label ($why)\n";
}

/**
 * Record a surface that CANNOT exist against the data this run is pointed at,
 * together with the evidence — never a policy, always something the app said.
 * Not a skip: there is no coverage available to lose, so --strict does not fail
 * on it. See "DID NOT RUN AND CANNOT EXIST ARE DIFFERENT ANSWERS" above.
 */
function na_surface($label, $evidence)
{
    global $NA;
    $NA[$label] = $evidence;
    echo "  note: surface not applicable to this data — $label ($evidence)\n";
}

/**
 * The machine-readable summary, and the ONLY exit from this script.
 *
 * $skipKind is NONE, WHOLE-RUN (nothing answered at the base URL, so no surface
 * could run at all) or PARTIAL (the app answered and a named surface still did
 * not run). They are reported separately because a caller treats them
 * differently: WHOLE-RUN is an environment fact, PARTIAL is coverage loss.
 */
function finish($skipKind = 'NONE')
{
    global $fails, $ran, $STRICT, $EXPECTED_SURFACES, $COVERED, $SKIPPED, $NA;

    // Fail closed: an expected surface that is neither registered as covered nor
    // recorded as skipped is counted as SKIPPED, so a future edit that forgets to
    // account for one cannot make it disappear from the tally. NOT-APPLICABLE is
    // NOT a third way to be unaccounted for — it is only ever set by
    // na_surface(), on evidence from the app, and it loses to SKIPPED if both
    // were somehow recorded for one label.
    $expected = count($EXPECTED_SURFACES);
    $covered  = 0;
    $na       = 0;
    foreach ($EXPECTED_SURFACES as $label => $_meta) {
        if (isset($SKIPPED[$label])) {
            unset($NA[$label]);
            continue;
        }
        if (isset($NA[$label])) {
            $na++;
            continue;
        }
        if (array_key_exists($label, $COVERED)) {
            $covered++;
        } else {
            $SKIPPED[$label] = 'surface was never attempted (unaccounted for)';
        }
    }
    // A not-applicable surface is expected but not coverable, so it is removed
    // from the denominator rather than counted as lost coverage.
    $skipped = $expected - $covered - $na;
    if ($skipped > 0 && $skipKind === 'NONE') {
        $skipKind = ($covered === 0) ? 'WHOLE-RUN' : 'PARTIAL';
    }

    $result = 'PASS';
    if ($fails > 0) {
        $result = 'FAIL';
    } elseif ($skipped > 0) {
        $result = $STRICT ? 'FAIL' : 'PASS-WITH-SKIPS';
    }

    // The human verdict. "ALL PASS" is reserved for a run that actually covered
    // everything: printing it over a run that quietly lost a third of its
    // surfaces is the exact defect this accounting exists to end.
    if ($fails > 0) {
        echo "\n$fails FAILED\n";
    } elseif ($ran === 0) {
        echo "\nNOTHING RAN — 0 assertions, $skipped of $expected SURFACE(S) SKIPPED\n";
    } elseif ($skipped > 0) {
        echo "\nALL $ran RAN ASSERTIONS PASSED — but $skipped of $expected SURFACE(S) DID NOT RUN\n";
    } elseif ($na > 0) {
        echo "\nALL $ran RAN ASSERTIONS PASSED — $na of $expected SURFACE(S) NOT APPLICABLE"
            . " TO THIS DATA (no coverage was lost; see the SURFACE: lines for the evidence)\n";
    } else {
        echo "\nALL PASS\n";
    }

    echo "\n--- machine-readable summary (parsed by .github/workflows/gates.yml) ---\n";
    echo "SURFACES: $expected EXPECTED, $covered COVERED, $skipped SKIPPED, $na NOT-APPLICABLE\n";
    foreach ($EXPECTED_SURFACES as $label => $_meta) {
        if (isset($SKIPPED[$label])) {
            echo "SURFACE: $label SKIPPED 0 — {$SKIPPED[$label]}\n";
        } elseif (isset($NA[$label])) {
            echo "SURFACE: $label NOT-APPLICABLE 0 — {$NA[$label]}\n";
        } else {
            echo "SURFACE: $label COVERED " . (isset($COVERED[$label]) ? $COVERED[$label] : 0) . "\n";
        }
    }
    echo "ASSERTIONS: $ran RAN, $fails FAILED\n";
    echo 'MODE: ' . ($STRICT ? 'STRICT' : 'LENIENT') . "\n";
    echo "SKIP-KIND: $skipKind\n";
    echo "RESULT: $result\n";

    if ($result === 'FAIL' && $fails === 0) {
        echo "\nSTRICT: $skipped expected surface(s) did not run. In strict mode that is a\n";
        echo "failure, not a note — a backstop that quietly covers less than it claims is\n";
        echo "worse than one that is honestly absent. Run without --strict (and without\n";
        echo "CMS_CSS_STRICT=1) to treat these as notes.\n";
    }

    exit($result === 'FAIL' ? 1 : 0);
}

/**
 * A skip that ends the run: nothing more can be asserted. Every expected
 * surface that has not already been accounted for is recorded as skipped, so
 * the summary is complete rather than empty.
 */
function skip($why, $kind)
{
    global $EXPECTED_SURFACES, $SKIPPED, $COVERED, $NA;
    echo "SKIP: $why\n";
    foreach ($EXPECTED_SURFACES as $label => $_meta) {
        if (isset($NA[$label])) {
            continue;   // already answered, on evidence: it cannot exist here
        }
        if (!isset($SKIPPED[$label]) && !array_key_exists($label, $COVERED)) {
            $SKIPPED[$label] = $why;
        }
    }
    finish($kind);
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

/**
 * GET a URL, following redirects (the front door answers 302 → /orkui/).
 * Returns array(status, body), or null when the host does not answer at all —
 * which is the difference between "the app is down" (skip) and "the app served
 * the wrong thing" (fail).
 */
function http_get($url, $timeout = 10)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'ork3-css-boundary-test',
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // No curl_close(): it has been a no-op since PHP 8.0 and is deprecated
        // in 8.5. The handle is released when $ch goes out of scope.
        if ($body === false || $code === 0) {
            return null;
        }
        return array($code, (string) $body);
    }

    $ctx  = stream_context_create(array('http' => array(
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'user_agent'    => 'ork3-css-boundary-test',
    )));
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    // The response headers: http_get_last_response_headers() on PHP 8.4+, the
    // magic function-local $http_response_header before that. The legacy name is
    // reached through a variable variable on purpose — writing it literally makes
    // PHP 8.4 emit a deprecation notice at COMPILE time, i.e. even on the curl
    // path above where this branch never runs.
    $hdrs = null;
    if (function_exists('http_get_last_response_headers')) {
        $hdrs = http_get_last_response_headers();
    } else {
        $legacy = 'http_response_header';
        $hdrs   = isset($$legacy) ? $$legacy : null;
    }
    $code = 0;
    if (is_array($hdrs)) {
        foreach ($hdrs as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    return array($code, (string) $body);
}

// ---------------------------------------------------------------------------
// Markup readers
// ---------------------------------------------------------------------------

/**
 * Stylesheet basenames in DOCUMENT ORDER (query string stripped, lowercased).
 * Both <link rel="stylesheet"> and @import are read, because an @import inside
 * an inline <style> loads exactly the same bytes as a link would.
 */
function stylesheets($html)
{
    $out = array();
    if (preg_match_all('#<link\b[^>]*>#i', $html, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match('#\brel\s*=\s*["\']?[^"\'>]*stylesheet#i', $tag)) {
                continue;
            }
            if (!preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#i', $tag, $h)) {
                continue;
            }
            $out[] = css_basename($h[2]);
        }
    }
    if (preg_match_all('#@import\s+(?:url\()?\s*["\']?([^"\')\s;]+)#i', $html, $m2)) {
        foreach ($m2[1] as $href) {
            $out[] = css_basename($href);
        }
    }
    return $out;
}

/**
 * Every stylesheet href/@import a surface emits, as WRITTEN (query kept off,
 * but the directories intact). stylesheets() reduces to a basename, which is
 * blind to "a file called blocks.css that is served out of style/". This is
 * what the shape assertion in section 1 reads.
 */
function stylesheet_hrefs($html)
{
    $out = array();
    if (preg_match_all('#<link\b[^>]*>#i', $html, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match('#\brel\s*=\s*["\']?[^"\'>]*stylesheet#i', $tag)) {
                continue;
            }
            if (preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#i', $tag, $h)) {
                $out[] = html_entity_decode($h[2], ENT_QUOTES);
            }
        }
    }
    if (preg_match_all('#@import\s+(?:url\()?\s*["\']?([^"\')\s;]+)#i', $html, $m2)) {
        foreach ($m2[1] as $href) {
            $out[] = html_entity_decode($href, ENT_QUOTES);
        }
    }
    return $out;
}

/** Collapse "//" and resolve ".." so a path is judged by where it LANDS. */
function norm_url_path($url)
{
    $p = preg_replace('/[?#].*$/', '', trim((string) $url));
    $p = preg_replace('#^[a-z][a-z0-9+.-]*://[^/]*#i', '', $p);
    $p = preg_replace('#^//[^/]*#', '', $p);
    $p = preg_replace('#/{2,}#', '/', $p);
    $out = array();
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $seg;
    }
    return implode('/', $out);
}

function css_basename($url)
{
    $url = preg_replace('/[?#].*$/', '', trim((string) $url));
    return strtolower(basename($url));
}

/**
 * basename => absolute href, for the SAME-ORIGIN stylesheets a surface links.
 * Section 6 asserts on the CSS bytes a surface actually serves, which means
 * fetching them; Google Fonts and the FontAwesome CDN are skipped because they
 * are not ours and are not on any boundary.
 */
function stylesheet_urls($html, $base)
{
    $out = array();
    if (!preg_match_all('#<link\b[^>]*>#i', $html, $m)) {
        return $out;
    }
    foreach ($m[0] as $tag) {
        if (!preg_match('#\brel\s*=\s*["\']?[^"\'>]*stylesheet#i', $tag)) {
            continue;
        }
        if (!preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#i', $tag, $h)) {
            continue;
        }
        $href = html_entity_decode($h[2], ENT_QUOTES);
        if (preg_match('#^https?://#i', $href)) {
            if (strpos($href, $base) !== 0) {
                continue;   // third-party CDN — not ours, not on a boundary
            }
        } elseif ($href !== '' && $href[0] === '/') {
            $href = $base . $href;
        } else {
            continue;       // relative to an unknown directory; not emitted here
        }
        $out[css_basename($href)] = $href;
    }
    return $out;
}

/** Fetched stylesheet bodies, memoised — a run touches the same files repeatedly. */
function css_body($url)
{
    static $cache = array();
    if (!array_key_exists($url, $cache)) {
        $r = http_get($url);
        $cache[$url] = ($r === null || $r[0] !== 200) ? null : $r[1];
    }
    return $cache[$url];
}

/** The bodies of every inline <style> element. */
function inline_styles($html)
{
    $out = array();
    if (preg_match_all('#<style\b[^>]*>(.*?)</style\s*>#is', $html, $m)) {
        foreach ($m[1] as $body) {
            $out[] = preg_replace('#/\*.*?\*/#s', ' ', $body);
        }
    }
    return $out;
}

/**
 * The SAME-ORIGIN script URLs a surface links, absolute. Third-party CDNs are
 * skipped for the same reason stylesheet_urls() skips them: they are not ours.
 */
function script_urls($html, $base)
{
    $out = array();
    if (!preg_match_all('#<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>#i', $html, $m)) {
        return $out;
    }
    foreach ($m[2] as $raw) {
        $src = html_entity_decode($raw, ENT_QUOTES);
        if (preg_match('#^https?://#i', $src)) {
            if (strpos($src, $base) !== 0) {
                continue;
            }
        } elseif ($src !== '' && $src[0] === '/') {
            $src = $base . $src;
        } else {
            continue;
        }
        $out[$src] = $src;
    }
    return array_values($out);
}

/** The bodies of every INLINE <script> element (no src attribute). */
function inline_scripts($html)
{
    $out = array();
    if (preg_match_all('#<script\b(?![^>]*\bsrc\s*=)[^>]*>(.*?)</script\s*>#is', $html, $m)) {
        foreach ($m[1] as $body) {
            $out[] = $body;
        }
    }
    return $out;
}

/** Fetched script bodies, memoised — the same bundle is linked by every surface. */
function js_body($url)
{
    static $cache = array();
    if (!array_key_exists($url, $cache)) {
        $r = http_get($url);
        $cache[$url] = ($r === null || $r[0] !== 200) ? null : $r[1];
    }
    return $cache[$url];
}

/** Class TOKENS used anywhere in the document (so "org-blog-card" != "blog-card"). */
function class_tokens($html)
{
    $out = array();
    if (preg_match_all('#\bclass\s*=\s*(["\'])(.*?)\1#is', $html, $m)) {
        foreach ($m[2] as $val) {
            foreach (preg_split('/\s+/', trim($val)) as $t) {
                if ($t !== '') {
                    $out[$t] = true;
                }
            }
        }
    }
    return array_keys($out);
}

// ---------------------------------------------------------------------------
// The contract
// ---------------------------------------------------------------------------

// CRM CSS: the bytes a standalone org site must never receive. orkshell-interop
// is CMS-authored but exists only to fight ORK chrome, so it belongs on this
// list for exactly the same reason.
//
// DERIVED, not listed. The hardcoded trio this list used to be (tokens, orkui,
// reports) silently omitted custom.css and the two stylesheets under style/css/,
// and would have omitted whatever lands in style/ next. Everything in the CRM's
// style directory is CRM CSS by definition, so ask the filesystem — the same
// derivation bin/check-css-boundaries.sh makes for its C4/C6 name set. The
// hand-written entries stay because they are NOT under style/: cms-admin.css is
// the OGRE admin's, orkshell-interop.css is the CMS's ORK-shell layer, and
// neither belongs on a standalone org site either.
$CRM_CSS = array('cms-admin.css', 'orkshell-interop.css');
$styleDir = __DIR__ . '/../../orkui/template/default/style';
$derived  = array();
$rii = @new RecursiveIteratorIterator(new RecursiveDirectoryIterator($styleDir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'css') {
        $derived[] = strtolower($f->getFilename());
    }
}
sort($derived);
$CRM_CSS = array_values(array_unique(array_merge($CRM_CSS, $derived)));
if (!$derived) {
    echo "  note: no stylesheets found under $styleDir — the CRM set is the hand-written pair only\n";
} else {
    echo '  note: CRM stylesheet set derived from style/: ' . implode(', ', $derived) . "\n";
}

// The in-shell tier must keep these three. Asserting it is what stops someone
// from satisfying the org-site rule by unlinking the CRM stylesheets globally.
$SHELL_REQUIRED = array('orkui.css', 'tokens.css', 'orkshell-interop.css');

// Cascade order, low to high. Only the names PRESENT on a surface are compared,
// so "orgsite.css or orkshell-interop.css last" falls out of one list — the two
// never co-occur.
$CASCADE = array('frontdoor.css', 'blocks.css', 'blog.css', 'orgsite.css', 'orkshell-interop.css');

// ORK application-shell selectors, the same three C1 forbids on the public tier.
$ORK_SELECTORS = array('#theme_container', '#newmenu', '.ork-');

// ORK's OWN analytics. A standalone org site is a kingdom's or park's public
// marketing site: it must not report pageviews into the ORK's Google Analytics
// property, its Tag Manager container, or its Cloudflare Web Analytics token.
// GTM was gated on $IsOrgSite from the start; the gtag.js pair and the
// Cloudflare beacon were missed, and shipped from every org site until F2.
// Substrings, not selectors — what matters is that the bytes are not served.
$ORK_ANALYTICS = array(
    'G-PVQCKENY0M'                  => 'gtag.js measurement id',
    'googletagmanager.com/gtag/js'  => 'gtag.js loader',
    'static.cloudflareinsights.com' => 'Cloudflare Web Analytics beacon',
    'GTM-5HZHGSLT'                  => 'Google Tag Manager container',
);

// The ORK application JS bundle — jQuery 1.7.1 + jQuery UI + tablesorter + the
// CRM's app code, 1,032,786 bytes, render-blocking in <head>. Nothing a
// standalone org site renders touches it (audited across every file on the
// org-site render path), and it is 11x the CSS this separation removed. The
// path is matched, not the basename, so a copy served from somewhere else is
// still caught.
$ORK_APP_JS = 'script/orkui.js';

// ---------------------------------------------------------------------------
// Surfaces
// ---------------------------------------------------------------------------
// tier: 'org'   standalone public org site  — zero CRM CSS
//       'shell' renders inside the ORK shell — CRM CSS required
// blog: does this surface emit .blog-* / .blogp-* markup, and so need blog.css?
//
// Declared BEFORE the reachability probe on purpose: the expected surface set is
// derived from this list, and the summary has to be able to report "9 expected,
// 0 covered" when nothing answers. A whole-run skip that reports no expectation
// at all is indistinguishable from a run with nothing to do.
$surfaces = array(
    array('label' => 'org home (kingdom burning-lands)',  'url' => $UI . 'Site/view/burning-lands',         'tier' => 'org',   'blog' => false),
    array('label' => 'org home (kingdom-17)',             'url' => $UI . 'Site/view/kingdom-17',            'tier' => 'org',   'blog' => false),
    array('label' => 'org home (park ambient-forest)',    'url' => $UI . 'Site/view/ambient-forest&_pfx=p',  'tier' => 'org',   'blog' => false),
    array('label' => 'org page (burning-lands/about)',    'url' => $UI . 'Site/page/burning-lands/about',    'tier' => 'org',   'blog' => false),
    array('label' => 'org blog index (burning-lands)',    'url' => $UI . 'Site/blog/burning-lands',          'tier' => 'org',   'blog' => false),
    array('label' => 'front door /',                      'url' => $BASE . '/',                              'tier' => 'shell', 'blog' => false),
    array('label' => 'blog index (Blog/index)',           'url' => $UI . 'Blog/index',                       'tier' => 'shell', 'blog' => true),
);

// The single-post surfaces are DISCOVERED from the index pages rather than
// hardcoded (a post slug is data, not configuration) — but they are expected all
// the same: the single-post render path is the only place .blogp-* / .org-post*
// is exercised, and "no post in the database" is a coverage hole whether or not
// anyone chose it. One per tier the list above covers, so this derives too.
function post_surface_label($tier)
{
    return ($tier === 'org') ? 'org post (discovered)' : 'blog post (discovered)';
}

$EXPECTED_SURFACES = array();
foreach ($surfaces as $s) {
    $EXPECTED_SURFACES[$s['label']] = array('tier' => $s['tier'], 'kind' => 'listed');
}
foreach (array_unique(array_column($surfaces, 'tier')) as $tier) {
    $EXPECTED_SURFACES[post_surface_label($tier)] = array('tier' => $tier, 'kind' => 'discovered');
}

// ---------------------------------------------------------------------------
// Reachability — the whole-run skip gate
// ---------------------------------------------------------------------------
$probe = http_get($BASE . '/', 5);
if ($probe === null) {
    skip("app not reachable at $BASE (set ORK_BASE_URL to point elsewhere)", 'WHOLE-RUN');
}

/**
 * Fetch a surface. Returns array(body, null), or array(null, why) — a reason
 * precise enough to act on, because "not available" was exactly the shrug that
 * let a whole tier disappear from a green run.
 */
function fetch_surface($url)
{
    $r = http_get($url);
    if ($r === null) {
        return array(null, "no HTTP response from $url");
    }
    if ($r[0] !== 200) {
        return array(null, "HTTP {$r[0]} from $url");
    }
    if (strpos($r[1], 'fd-page') === false) {
        return array(null, "200 but no .fd-page — not a rendered CMS page — $url");
    }
    return array($r[1], null);
}

$pages = array();
foreach ($surfaces as $s) {
    list($body, $why) = fetch_surface($s['url']);
    if ($body === null) {
        skip_surface($s['label'], $why);
        continue;
    }
    surface($s['label']);   // registers it as covered, with 0 assertions so far
    $s['body']  = $body;
    $pages[]    = $s;
}

/**
 * The RSS feed URL for the SCOPE a covered surface belongs to, derived from that
 * surface's own route — never listed, so a surface added to $surfaces brings its
 * feed with it. '' when the surface names no scope-bearing route (the front door
 * is fetched at $BASE . '/', has no Route= at all, and is global scope, which
 * Blog/rss already speaks for).
 *
 *   Site/view/{slug}          → Site/rss/{slug}
 *   Site/page/{slug}/{path}   → Site/rss/{slug}
 *   Site/blog/{slug}          → Site/rss/{slug}
 *   Blog/index                → Blog/rss
 *
 * Any trailing query the surface carries is preserved, because a park site is
 * only reachable with &_pfx=p and its feed is no different.
 */
$feedUrlFor = function ($surfaceUrl) use ($UI) {
    $pos = strpos($surfaceUrl, 'Route=');
    if ($pos === false) {
        return '';
    }
    $route = substr($surfaceUrl, $pos + 6);
    $tail  = '';
    if (($amp = strpos($route, '&')) !== false) {
        $tail  = substr($route, $amp);
        $route = substr($route, 0, $amp);
    }
    $seg = explode('/', trim($route, '/'));
    if (!isset($seg[0])) {
        return '';
    }
    if ($seg[0] === 'Blog') {
        return $UI . 'Blog/rss' . $tail;
    }
    if ($seg[0] === 'Site' && isset($seg[2]) && $seg[2] !== '') {
        return $UI . 'Site/rss/' . $seg[2] . $tail;
    }
    return '';
};

/**
 * Does the DATA contain a published post anywhere this tier's covered surfaces
 * reach? Answered from the app's own per-scope RSS feeds, never from a list in
 * this file — see the header. Returns array($exists, $evidence):
 *
 *   array(true,  …)  at least one feed carries an <item>
 *   array(false, …)  every feed answered, parsed, and carried no <item> at all
 *   array(null,  …)  no feed derivable, or one did not answer / did not parse —
 *                    absence unproven, so the caller must fail closed.
 */
$tierHasPublishedPost = function ($tier, $pages) use ($feedUrlFor) {
    $feeds = array();
    foreach ($pages as $p) {
        if ($p['tier'] !== $tier) {
            continue;
        }
        $u = $feedUrlFor($p['url']);
        if ($u !== '') {
            $feeds[$u] = true;      // keyed: two surfaces of one scope = one feed
        }
    }
    $feeds = array_keys($feeds);
    if (!$feeds) {
        return array(null, "no RSS feed could be derived from any covered $tier surface");
    }

    $items = 0;
    foreach ($feeds as $u) {
        $r = http_get($u);
        if ($r === null) {
            return array(null, "no HTTP response from $u");
        }
        if ($r[0] !== 200) {
            return array(null, "HTTP {$r[0]} from $u");
        }
        if (!preg_match('#<channel[\s>]#i', $r[1])) {
            return array(null, "200 but no <channel> — not an RSS feed — $u");
        }
        $items += preg_match_all('#<item[\s>]#i', $r[1]);
    }

    $n = count($feeds);
    return $items > 0
        ? array(true, "$items published post(s) in $n $tier scope feed(s)")
        : array(false, "0 <item> across all $n $tier scope RSS feed(s): " . implode(', ', $feeds));
};

// Post surfaces need a published post to exist, so they are discovered by
// following a post link off an index page. A tier with no discoverable post is
// a SKIPPED surface — reported in the summary and fatal under --strict — unless
// the tier's own feeds prove no such post exists, in which case it is
// NOT-APPLICABLE. Never a silent "one fewer surface".
$found = array('org' => false, 'shell' => false);
$whyNot = array('org' => '', 'shell' => '');
foreach ($pages as $p) {
    if ($found[$p['tier']]) {
        continue;
    }
    $pat = ($p['tier'] === 'org') ? '#Route=(Site/post/[^"&\']+)#' : '#Route=(Blog/post/[^"&\']+)#';
    if (!preg_match($pat, $p['body'], $m)) {
        continue;
    }

    $url = $UI . $m[1];
    list($body, $why) = fetch_surface($url);
    if ($body === null) {
        $whyNot[$p['tier']] = $why;
        continue;
    }
    $found[$p['tier']] = true;
    $label             = post_surface_label($p['tier']);
    surface($label);
    $pages[]           = array(
        'label' => $label,
        'url'   => $url,
        'tier'  => $p['tier'],
        // A single post renders .blogp-* only in the ORK shell; the org-site post
        // branch renders .org-post* and needs no blog layer.
        'blog'  => ($p['tier'] !== 'org'),
        'body'  => $body,
    );
}
foreach ($found as $tier => $ok) {
    if ($ok) {
        continue;
    }
    $label = post_surface_label($tier);

    // A post link WAS found and the page would not render: that is the surface
    // failing, not the data being empty. Never forgiven, whatever the feeds say.
    if ($whyNot[$tier] !== '') {
        skip_surface($label, $whyNot[$tier]);
        continue;
    }

    // Otherwise ask the app whether a post exists in this tier's scopes at all.
    list($exists, $evidence) = $tierHasPublishedPost($tier, $pages);
    if ($exists === false) {
        na_surface($label, $evidence);
    } elseif ($exists === true) {
        skip_surface(
            $label,
            "a published $tier post EXISTS but no covered $tier index page linked"
                . " one this run could render — $evidence"
        );
    } else {
        skip_surface(
            $label,
            "no published $tier post linked from any covered $tier index page, and"
                . " this run could not prove none exists — $evidence"
        );
    }
}

$orgPages   = array_values(array_filter($pages, function ($p) {
    return $p['tier'] === 'org';
}));
$shellPages = array_values(array_filter($pages, function ($p) {
    return $p['tier'] === 'shell';
}));

// PARTIAL, not WHOLE-RUN: the app answered, so an empty tier is coverage loss
// rather than an absent environment, and a caller must be able to tell them
// apart.
if (!$orgPages) {
    skip("app answered at $BASE but served no standalone org site — nothing to assert against", 'PARTIAL');
}
if (!$shellPages) {
    skip("app answered at $BASE but served no in-shell CMS surface — nothing to assert against", 'PARTIAL');
}

echo "Base: $BASE — " . count($orgPages) . " org surface(s), " . count($shellPages) . " in-shell surface(s)\n\n";

// ---------------------------------------------------------------------------
// 1. A standalone public org site serves ZERO bytes of ORK CRM CSS.
// ---------------------------------------------------------------------------
foreach ($orgPages as $p) {
    surface($p['label']);
    $sheets = stylesheets($p['body']);
    foreach ($CRM_CSS as $crm) {
        check("org site links no $crm — {$p['label']}", !in_array($crm, $sheets, true));
    }
    // By SHAPE as well as by name: a stylesheet served out of the CRM's style/
    // directory is CRM CSS whatever it is called, and a name list only ever
    // knows about the files that existed when it was written. Mirrors the
    // path-shape rule bin/check-css-boundaries.sh applies to the source.
    $inStyleDir = array();
    foreach (stylesheet_hrefs($p['body']) as $href) {
        $norm = norm_url_path($href);
        if (preg_match('#(^|/)style/.*\.css$#i', $norm)) {
            $inStyleDir[] = $href;
        }
    }
    check(
        "org site links nothing out of a style/ directory — {$p['label']}"
            . ($inStyleDir ? ' [' . implode(', ', $inStyleDir) . ']' : ''),
        !$inStyleDir
    );

    // The org tier's own base layer must be there, or "no CRM CSS" is being
    // achieved by serving no CSS at all.
    check("org site still links cms-base.css — {$p['label']}", in_array('cms-base.css', $sheets, true));
    check("org site still links orgsite.css — {$p['label']}", in_array('orgsite.css', $sheets, true));
}

// ---------------------------------------------------------------------------
// 2. The in-shell surfaces still load the CRM stylesheets + the interop layer.
//    Without this, deleting the links from default.theme would turn the whole
//    suite green while breaking every page inside the ORK shell.
// ---------------------------------------------------------------------------
foreach ($shellPages as $p) {
    surface($p['label']);
    $sheets = stylesheets($p['body']);
    foreach ($SHELL_REQUIRED as $need) {
        check("in-shell surface links $need — {$p['label']}", in_array($need, $sheets, true));
    }
    check("in-shell surface does NOT link cms-base.css — {$p['label']}", !in_array('cms-base.css', $sheets, true));
}

// ---------------------------------------------------------------------------
// 3. Cascade order, on every surface. blocks.css and blog.css were split off the
//    end of frontdoor.css and win same-specificity ties against it, so a
//    reordered set renders differently while linking exactly the same files.
// ---------------------------------------------------------------------------
foreach ($pages as $p) {
    surface($p['label']);
    $sheets = stylesheets($p['body']);
    $seen   = array();
    foreach ($CASCADE as $name) {
        $at = array_keys($sheets, $name, true);
        if (!$at) {
            continue;
        }
        // A second link of the same layer makes "order" meaningless.
        check("$name linked exactly once — {$p['label']}", count($at) === 1);
        $seen[$name] = $at[0];
    }
    $prevName = null;
    $prevPos  = -1;
    $ok       = true;
    $where    = '';
    foreach ($seen as $name => $pos) {
        if ($pos <= $prevPos) {
            $ok    = false;
            $where = "$name at $pos is not after $prevName at $prevPos";
            break;
        }
        $prevName = $name;
        $prevPos  = $pos;
    }
    check(
        'cascade order ' . implode(' < ', array_keys($seen)) . " — {$p['label']}" . ($ok ? '' : " [$where]"),
        $ok
    );
}

// ---------------------------------------------------------------------------
// 4. blog.css is linked on exactly the blog surfaces, and nowhere else.
//    Checked both ways: the link must match the expectation, and the expectation
//    must match the markup actually rendered (a class token STARTING with
//    "blog-"/"blogp-" — "org-blog-card" is orgsite.css, not blog.css).
// ---------------------------------------------------------------------------
foreach ($pages as $p) {
    surface($p['label']);
    $sheets  = stylesheets($p['body']);
    $linked  = in_array('blog.css', $sheets, true);
    $usesIt  = false;
    foreach (class_tokens($p['body']) as $t) {
        if (strpos($t, 'blog-') === 0 || strpos($t, 'blogp-') === 0) {
            $usesIt = true;
            break;
        }
    }
    if ($p['blog']) {
        check("blog.css linked on a blog surface — {$p['label']}", $linked);
    } else {
        check("blog.css NOT linked on a non-blog surface — {$p['label']}", !$linked);
    }
    check("blog.css link matches the markup rendered — {$p['label']}", $linked === $usesIt);
}

// ---------------------------------------------------------------------------
// 5. No org-site page smuggles ORK CSS back in through an inline <style>.
//    The theme-token block IS inline and legitimate; what it may never contain
//    is a rule naming the ORK application shell.
// ---------------------------------------------------------------------------
foreach ($orgPages as $p) {
    surface($p['label']);
    $bad = array();
    foreach (inline_styles($p['body']) as $css) {
        $lc = strtolower($css);
        foreach ($ORK_SELECTORS as $sel) {
            if (strpos($lc, $sel) !== false) {
                $bad[$sel] = true;
            }
        }
    }
    check(
        "no inline <style> names an ORK selector — {$p['label']}" . ($bad ? ' [' . implode(', ', array_keys($bad)) . ']' : ''),
        !$bad
    );
}

// ---------------------------------------------------------------------------
// 6. Authored body-copy links carry a non-colour affordance on EVERY tier.
//
//    A link an author writes inside a richtext / raw_html block is a
//    .fd-body-text descendant. It used to be styled only for standalone org
//    sites (`.fd-org .fd-body-text a` in orgsite.css), so in the ORK shell it
//    fell through to orkui.css's `a { color: #333; text-decoration: none }` —
//    #333 against .fd-body-text's own #1a2236 is ~1.15:1 with no underline,
//    i.e. WCAG 1.4.1 (use of colour): colour was the only signal and it was
//    effectively the same colour. The underline is what satisfies 1.4.1
//    independently of colour, so it is the thing worth asserting.
//
//    Asserted on the CSS BYTES each surface serves, not on source, and on the
//    property the fix is FOR (an underline reaching authored body copy) rather
//    than on one file's contents — the rule may move again, but it must never
//    stop being served, and it must never go back to being org-only.
// ---------------------------------------------------------------------------
foreach ($pages as $p) {
    surface($p['label']);
    $urls  = stylesheet_urls($p['body'], $BASE);
    $all   = '';
    $unread = array();
    foreach ($urls as $name => $u) {
        $b = css_body($u);
        if ($b === null) {
            $unread[] = $name;
            continue;
        }
        $all .= "\n" . $b;
    }
    check("every linked stylesheet was readable — {$p['label']}" . ($unread ? ' [' . implode(', ', $unread) . ']' : ''), !$unread);

    // Comments discuss these selectors at length; strip them before matching.
    $css = preg_replace('#/\*.*?\*/#s', ' ', $all);

    check(
        "authored body-copy links are underlined — {$p['label']}",
        (bool) preg_match('#(^|[,}])\s*\.fd-body-text\s+a\s*\{[^}]*text-decoration\s*:\s*underline#is', $css)
    );
    check(
        "authored body-copy links take --pk-link — {$p['label']}",
        (bool) preg_match('#(^|[,}])\s*\.fd-body-text\s+a\s*\{[^}]*color\s*:\s*var\(\s*--pk-link#is', $css)
    );
    // One home for the rule: the `.fd-org`-scoped copy is gone and must not
    // come back, or the two tiers can drift apart again.
    check(
        "no .fd-org-scoped copy of the rule is served — {$p['label']}",
        !preg_match('#\.fd-org\s+\.fd-body-text\s+a\b#is', $css)
    );

    // The dark-mode ID armour is ORK-shell-only by construction: it names
    // #theme_container, so it may exist ONLY in orkshell-interop.css, which a
    // standalone org site never loads. Checked in both directions.
    $armour = '#html\[data-theme="dark"\]\s+\#theme_container\s+\.fd-body-text\s+a\b#is';
    if ($p['tier'] === 'shell') {
        check("dark-mode #theme_container armour is served — {$p['label']}", (bool) preg_match($armour, $css));
    } else {
        check("org site is served no #theme_container armour — {$p['label']}", !preg_match($armour, $css));
    }
}

// ---------------------------------------------------------------------------
// 7. A standalone org site serves none of ORK's own analytics payload.
//
//    Same shape as rule 1, one layer out: the separation is not only about CSS
//    bytes. A kingdom's or park's public site is not an ORK application surface
//    and has no business reporting into ORK's Google Analytics property, Tag
//    Manager container or Cloudflare Web Analytics token. Checked in BOTH
//    directions, so the org-site half cannot be satisfied by ripping the
//    snippets out globally — the in-shell tier still has to serve them.
// ---------------------------------------------------------------------------
foreach ($orgPages as $p) {
    surface($p['label']);
    foreach ($ORK_ANALYTICS as $needle => $what) {
        check("org site serves no $what — {$p['label']}", strpos($p['body'], $needle) === false);
    }
}
foreach ($shellPages as $p) {
    surface($p['label']);
    foreach ($ORK_ANALYTICS as $needle => $what) {
        check("in-shell surface still serves the $what — {$p['label']}", strpos($p['body'], $needle) !== false);
    }
}

// ---------------------------------------------------------------------------
// 8. A standalone org site is served none of the 1 MB ORK application JS.
//
//    Both directions again. The org-site half is the saving; the in-shell half
//    is what stops it from being won by deleting the <script> outright, which
//    would take jQuery away from the entire CRM.
// ---------------------------------------------------------------------------
foreach ($orgPages as $p) {
    surface($p['label']);
    check("org site links no orkui.js — {$p['label']}", strpos($p['body'], $ORK_APP_JS) === false);
    // "Serves no CRM JS" must not be achievable by serving no JS at all: the
    // org tier's own behaviour layer (carousel, mobile nav, roster modal) has
    // to still be there.
    check("org site still links frontdoor.js — {$p['label']}", strpos($p['body'], 'frontdoor/js/frontdoor.js') !== false);
}
foreach ($shellPages as $p) {
    surface($p['label']);
    check("in-shell surface still links orkui.js — {$p['label']}", strpos($p['body'], $ORK_APP_JS) !== false);
}

// ---------------------------------------------------------------------------
// 9. The SCRIPTS an org site serves inject no CSS.
//
//    The runtime mirror of C7 in bin/check-css-boundaries.sh. That gate reads
//    the CMS PHP and JS sources in the repo; this reads the JavaScript a
//    standalone org site is ACTUALLY SERVED — every same-origin <script src>
//    it links, plus every inline <script> in its HTML — so a payload that
//    reaches the page from somewhere the source gate never scans (a new bundle
//    outside frontdoor/js/, a vendored script, a snippet pasted into a
//    template) is caught here.
//
//    A stylesheet a script appends after load is invisible to sections 1-4:
//    they read the served HTML, and the injected <link> is not in it. This is
//    the assertion that closes that, and it is the reason C7 exists — both
//    halves of the verifier's proof (an insertAdjacentHTML() in frontdoor.js
//    and an echo in controller.Site.php) put orkui.css and a
//    #theme_container rule onto a live org-site page with every gate green.
//
//    Scoped to the ORG tier deliberately. The in-shell tier legitimately loads
//    orkui.js — 1 MB of jQuery, jQuery UI and CRM app code that manipulates
//    styles constantly — and asserting on bytes we do not own would be noise.
//    The property being protected is "an org site receives zero CRM CSS", and
//    that is an org-tier property.
// ---------------------------------------------------------------------------
$JS_INJECTION = array(
    // A <style> element, however it is built.
    '#<\s*style#i'                                         => 'emits a <style> tag',
    '#createelement\s*\(\s*["\'`]\s*(style|link)\s*["\'`]#i' => 'builds a style/link element',
    '#\binsertrule\b#i'                                    => 'inserts a CSS rule',
    '#\badoptedstylesheets\b#i'                            => 'adopts a constructed stylesheet',
    '#new\s+cssstylesheet#i'                               => 'constructs a stylesheet',
    // A stylesheet link, and the @import that needs no tag at all.
    '#\bstylesheet\b#i'                                    => 'names rel="stylesheet"',
    '#@import#i'                                           => 'emits an @import',
    // A CRM stylesheet by name, or by the style/ path shape.
    '#(^|[^a-z0-9_-])style/[a-z0-9_./-]*\.css#i'           => 'names a style/ stylesheet path',
    // The ORK application shell, and the CRM token namespace.
    '#(\#theme_container|\#newmenu|\.ork-)#i'              => 'names an ORK shell selector',
    '#--ork-#i'                                            => 'names a CRM --ork-* token',
);
foreach ($CRM_CSS as $sheet) {
    $JS_INJECTION['#' . preg_quote($sheet, '#') . '#i'] = "names $sheet";
}

foreach ($orgPages as $p) {
    surface($p['label']);
    $bodies = array();
    foreach (script_urls($p['body'], $BASE) as $u) {
        $js = js_body($u);
        if ($js === null) {
            echo '  note: could not fetch ' . $u . " — script not checked\n";
            continue;
        }
        $bodies[basename(preg_replace('/[?#].*$/', '', $u))] = $js;
    }
    foreach (inline_scripts($p['body']) as $i => $js) {
        $bodies['inline #' . ($i + 1)] = $js;
    }
    // "No CSS-injecting JS" must not be winnable by serving no JS at all —
    // section 8 already requires frontdoor.js, and this asserts we read it.
    check("org site scripts were readable — {$p['label']}", count($bodies) > 0);
    // One assertion per script, not one per needle: the needle set is a dozen
    // spellings of ONE property ("this script injects no CSS"), and a
    // cross-product of them against every script on every surface buries the
    // rest of the run in PASS lines. A failure names every spelling that hit.
    foreach ($bodies as $what => $js) {
        $hits = array();
        foreach ($JS_INJECTION as $re => $desc) {
            if (preg_match($re, $js)) {
                $hits[] = $desc;
            }
        }
        check(
            "org-site script injects no CSS — $what — {$p['label']}"
                . ($hits ? ' [' . implode('; ', $hits) . ']' : ''),
            count($hits) === 0
        );
    }
}

// ---------------------------------------------------------------------------
// 10. The accounting itself.
//
//     A surface that was fetched but had nothing asserted against it is the same
//     failure as one that was never fetched, one step further along: the run
//     would report it COVERED while covering nothing. And a surface that ran but
//     is not in the expected set means the derivation above and the loops below
//     have drifted apart, which would let a future surface run un-tallied.
// ---------------------------------------------------------------------------
surface('(accounting)');
foreach ($pages as $p) {
    check(
        "surface is in the expected set — {$p['label']}",
        isset($EXPECTED_SURFACES[$p['label']])
    );
    check(
        "surface ran at least one assertion — {$p['label']} (" . (isset($COVERED[$p['label']]) ? $COVERED[$p['label']] : 0) . ')',
        !empty($COVERED[$p['label']])
    );
}

finish();
