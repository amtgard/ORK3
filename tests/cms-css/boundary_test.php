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
//
// SAFE IN ANY ENVIRONMENT. If nothing answers at the base URL the script prints
// a SKIP line and exits 0. Point it elsewhere with ORK_BASE_URL:
//
//     ORK_BASE_URL=http://localhost:19080 php tests/cms-css/boundary_test.php
//
// Design: docs/superpowers/specs/2026-08-21-cms-css-separation-design.md
// Working reference: orkui/template/default/frontdoor/css/README.md

$BASE = (string) getenv('ORK_BASE_URL');
if ($BASE === '') {
    $BASE = 'http://localhost:19080';
}
$BASE = rtrim($BASE, '/');
$UI   = $BASE . '/orkui/index.php?Route=';

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

/** Print a SKIP banner and leave with a success status. */
function skip($why)
{
    echo "SKIP: $why\n";
    echo "\nSKIPPED (0 assertions run) — this test needs a running ORK app.\n";
    exit(0);
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
// Reachability — the SKIP gate
// ---------------------------------------------------------------------------
$probe = http_get($BASE . '/', 5);
if ($probe === null) {
    skip("app not reachable at $BASE (set ORK_BASE_URL to point elsewhere)");
}

// ---------------------------------------------------------------------------
// Surfaces
// ---------------------------------------------------------------------------
// tier: 'org'   standalone public org site  — zero CRM CSS
//       'shell' renders inside the ORK shell — CRM CSS required
// blog: does this surface emit .blog-* / .blogp-* markup, and so need blog.css?
$surfaces = array(
    array('label' => 'org home (kingdom burning-lands)',  'url' => $UI . 'Site/view/burning-lands',         'tier' => 'org',   'blog' => false),
    array('label' => 'org home (kingdom-17)',             'url' => $UI . 'Site/view/kingdom-17',            'tier' => 'org',   'blog' => false),
    array('label' => 'org home (park ambient-forest)',    'url' => $UI . 'Site/view/ambient-forest&_pfx=p',  'tier' => 'org',   'blog' => false),
    array('label' => 'org page (burning-lands/about)',    'url' => $UI . 'Site/page/burning-lands/about',    'tier' => 'org',   'blog' => false),
    array('label' => 'org blog index (burning-lands)',    'url' => $UI . 'Site/blog/burning-lands',          'tier' => 'org',   'blog' => false),
    array('label' => 'front door /',                      'url' => $BASE . '/',                              'tier' => 'shell', 'blog' => false),
    array('label' => 'blog index (Blog/index)',           'url' => $UI . 'Blog/index',                       'tier' => 'shell', 'blog' => true),
);

/** Fetch a surface; null when it did not render a CMS page (404, login wall, empty DB). */
function fetch_surface($url)
{
    $r = http_get($url);
    if ($r === null || $r[0] !== 200 || strpos($r[1], 'fd-page') === false) {
        return null;
    }
    return $r[1];
}

$pages = array();
foreach ($surfaces as $s) {
    $body = fetch_surface($s['url']);
    if ($body === null) {
        echo "  note: surface not available, skipped — {$s['label']} ({$s['url']})\n";
        continue;
    }
    $s['body']  = $body;
    $pages[]    = $s;
}

// Post surfaces need a published post to exist, so they are discovered from the
// index pages rather than hardcoded — no post in the DB simply means one fewer
// surface, not a red suite.
$found = array('org' => false, 'shell' => false);
foreach ($pages as $p) {
    if ($found[$p['tier']]) {
        continue;
    }
    $pat = ($p['tier'] === 'org') ? '#Route=(Site/post/[^"&\']+)#' : '#Route=(Blog/post/[^"&\']+)#';
    if (!preg_match($pat, $p['body'], $m)) {
        continue;
    }

    $url  = $UI . $m[1];
    $body = fetch_surface($url);
    if ($body === null) {
        continue;
    }
    $found[$p['tier']] = true;
    $pages[]           = array(
        'label' => ($p['tier'] === 'org' ? 'org post (discovered)' : 'blog post (discovered)'),
        'url'   => $url,
        'tier'  => $p['tier'],
        // A single post renders .blogp-* only in the ORK shell; the org-site post
        // branch renders .org-post* and needs no blog layer.
        'blog'  => ($p['tier'] !== 'org'),
        'body'  => $body,
    );
}
foreach ($found as $tier => $ok) {
    if (!$ok) {
        echo "  note: no published $tier post to discover — that surface is not covered on this run\n";
    }
}

$orgPages   = array_values(array_filter($pages, function ($p) {
    return $p['tier'] === 'org';
}));
$shellPages = array_values(array_filter($pages, function ($p) {
    return $p['tier'] === 'shell';
}));

if (!$orgPages) {
    skip("app answered at $BASE but served no standalone org site — nothing to assert against");
}
if (!$shellPages) {
    skip("app answered at $BASE but served no in-shell CMS surface — nothing to assert against");
}

echo "Base: $BASE — " . count($orgPages) . " org surface(s), " . count($shellPages) . " in-shell surface(s)\n\n";

// ---------------------------------------------------------------------------
// 1. A standalone public org site serves ZERO bytes of ORK CRM CSS.
// ---------------------------------------------------------------------------
foreach ($orgPages as $p) {
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
    foreach ($ORK_ANALYTICS as $needle => $what) {
        check("org site serves no $what — {$p['label']}", strpos($p['body'], $needle) === false);
    }
}
foreach ($shellPages as $p) {
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
    check("org site links no orkui.js — {$p['label']}", strpos($p['body'], $ORK_APP_JS) === false);
    // "Serves no CRM JS" must not be achievable by serving no JS at all: the
    // org tier's own behaviour layer (carousel, mobile nav, roster modal) has
    // to still be there.
    check("org site still links frontdoor.js — {$p['label']}", strpos($p['body'], 'frontdoor/js/frontdoor.js') !== false);
}
foreach ($shellPages as $p) {
    check("in-shell surface still links orkui.js — {$p['label']}", strpos($p['body'], $ORK_APP_JS) !== false);
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
