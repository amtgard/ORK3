<?php

// tests/cms-sanitizer/sanitizer_test.php — run: php tests/cms-sanitizer/sanitizer_test.php
//
// Regression suite for CmsSanitizer::Clean / CmsSanitizer::IsSafeUrl (#33).
// CmsSanitizer is pure (no DB, does NOT extend Ork3) so it loads standalone in
// a bare `php` run. Follows the plain-PHP check() harness used by
// tests/cms-theme/tokens_test.php and tests/cms-site/site_test.php.
//
// Coverage:
//   - Known XSS payloads are neutralized (<script>, on* handlers, <svg onload>,
//     javascript:, percent/entity-encoded schemes, protocol-relative //, data:).
//   - Legitimate allowlisted markup survives intact.
//   - DROP_TAGS content is discarded wholesale (not merely unwrapped).
//   - The #102 size / deep-nesting / node-budget guards are reachable and bound
//     the walk without crashing.

require __DIR__ . '/../../system/lib/ork3/class.CmsSanitizer.php';

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

/** Case-insensitive "does the cleaned output contain this needle?" */
function has($haystack, $needle)
{
    return stripos($haystack, $needle) !== false;
}

// ---------------------------------------------------------------------------
// XSS payloads — must be neutralized
// ---------------------------------------------------------------------------

// <script> is a DROP_TAG: removed WITH its body.
$out = CmsSanitizer::Clean('<p>before</p><script>alert(1)</script><p>after</p>');
check('script tag removed', !has($out, '<script'));
check('script body discarded', !has($out, 'alert(1)'));
check('text around script kept', has($out, 'before') && has($out, 'after'));

// on* event handlers stripped from an allowed tag; the tag itself survives.
$out = CmsSanitizer::Clean('<img src="/logo.png" onerror="alert(1)">');
check('img kept', has($out, '<img'));
check('onerror handler stripped', !has($out, 'onerror'));
check('onerror payload gone', !has($out, 'alert(1)'));

// onload on a non-allowlisted container (<body>): tag unwrapped, handler gone.
$out = CmsSanitizer::Clean('<body onload="alert(1)"><p>hi</p></body>');
check('onload handler stripped', !has($out, 'onload'));
check('body content preserved (unwrapped)', has($out, 'hi'));

// <svg onload> — svg is a DROP_TAG: whole element (and the handler) removed.
$out = CmsSanitizer::Clean('<svg onload="alert(1)"><circle/></svg><p>ok</p>');
check('svg element removed', !has($out, '<svg'));
check('svg onload gone', !has($out, 'onload'));
check('content after svg kept', has($out, 'ok'));

// javascript: href on an anchor — href stripped, anchor + text survive.
$out = CmsSanitizer::Clean('<a href="javascript:alert(1)">click</a>');
check('javascript: href stripped', !has($out, 'javascript:'));
check('anchor text preserved', has($out, 'click'));

// Entity-encoded scheme: the HTML parser decodes &#x6a; → 'j' BEFORE the
// attribute filter, so IsSafeUrl sees the literal "javascript:" and drops it.
$out = CmsSanitizer::Clean('<a href="&#x6a;avascript:alert(1)">x</a>');
check('entity-encoded javascript scheme stripped', !has($out, 'javascript:') && !has($out, 'alert(1)'));

// data: URI on an image — data: is rejected entirely.
$out = CmsSanitizer::Clean('<img src="data:text/html,<script>alert(1)</script>">');
check('data: src stripped', !has($out, 'data:'));

// inline style is never allowlisted.
$out = CmsSanitizer::Clean('<p style="position:fixed;background:url(javascript:alert(1))">t</p>');
check('inline style stripped', !has($out, 'style='));
check('paragraph kept', has($out, '<p'));

// ---------------------------------------------------------------------------
// IsSafeUrl — scheme/scheme-encoding matrix
// ---------------------------------------------------------------------------

// Unsafe:
check('IsSafeUrl rejects javascript:', CmsSanitizer::IsSafeUrl('javascript:alert(1)') === false);
check('IsSafeUrl rejects percent-encoded js (%6a)', CmsSanitizer::IsSafeUrl('%6aavascript:alert(1)') === false);
check('IsSafeUrl rejects tab-obfuscated js', CmsSanitizer::IsSafeUrl("java\tscript:alert(1)") === false);
check('IsSafeUrl rejects encoded control (java%09script:)', CmsSanitizer::IsSafeUrl('java%09script:alert(1)') === false);
check('IsSafeUrl rejects data:', CmsSanitizer::IsSafeUrl('data:text/html,x') === false);
check('IsSafeUrl rejects vbscript:', CmsSanitizer::IsSafeUrl('vbscript:msgbox(1)') === false);
check('IsSafeUrl rejects file:', CmsSanitizer::IsSafeUrl('file:///etc/passwd') === false);
check('IsSafeUrl rejects protocol-relative //', CmsSanitizer::IsSafeUrl('//evil.example/x') === false);
check('IsSafeUrl rejects empty', CmsSanitizer::IsSafeUrl('') === false);
check('IsSafeUrl rejects unknown scheme', CmsSanitizer::IsSafeUrl('ftp://host/x') === false);

// Safe:
check('IsSafeUrl allows https', CmsSanitizer::IsSafeUrl('https://example.com/x') === true);
check('IsSafeUrl allows http', CmsSanitizer::IsSafeUrl('http://example.com') === true);
check('IsSafeUrl allows mailto', CmsSanitizer::IsSafeUrl('mailto:a@b.com') === true);
check('IsSafeUrl allows root-relative', CmsSanitizer::IsSafeUrl('/assets/logo.png') === true);
check('IsSafeUrl allows anchor', CmsSanitizer::IsSafeUrl('#section') === true);
check('IsSafeUrl allows query-only', CmsSanitizer::IsSafeUrl('?q=1') === true);
check('IsSafeUrl allows bare relative path', CmsSanitizer::IsSafeUrl('page/slug') === true);

// SafeHrefOrHash centralizes the ternary.
check('SafeHrefOrHash keeps safe href', CmsSanitizer::SafeHrefOrHash('https://x.com') === 'https://x.com');
check('SafeHrefOrHash hashes unsafe href', CmsSanitizer::SafeHrefOrHash('javascript:alert(1)') === '#');
check('SafeHrefOrHash hashes empty', CmsSanitizer::SafeHrefOrHash('') === '#');

// ---------------------------------------------------------------------------
// Legitimate markup survives intact
// ---------------------------------------------------------------------------

$rich = '<p>Hello <strong>world</strong> and <em>friends</em>.</p>'
    . '<h2>Heading</h2><ul><li>one</li><li>two</li></ul>'
    . '<a href="https://example.com" title="ok">a link</a>'
    . '<blockquote>quote</blockquote>';
$out = CmsSanitizer::Clean($rich);
check('paragraph survives', has($out, '<p>'));
check('strong survives', has($out, '<strong>'));
check('em survives', has($out, '<em>'));
check('h2 survives', has($out, '<h2>'));
check('list survives', has($out, '<ul>') && has($out, '<li>'));
check('safe anchor href survives', has($out, 'href="https://example.com"'));
check('anchor title survives', has($out, 'title="ok"'));
check('blockquote survives', has($out, '<blockquote>'));

// target=_blank gets a hardened rel forced on.
$out = CmsSanitizer::Clean('<a href="https://x.com" target="_blank">x</a>');
check('_blank target kept', has($out, 'target="_blank"'));
check('_blank forces noopener rel', has($out, 'noopener') && has($out, 'noreferrer'));

// Non-_blank target values are dropped (only _blank is allowed).
$out = CmsSanitizer::Clean('<a href="/x" target="_top">x</a>');
check('non-_blank target dropped', !has($out, 'target='));

// ---------------------------------------------------------------------------
// DROP_TAGS content is discarded (not unwrapped)
// ---------------------------------------------------------------------------

$out = CmsSanitizer::Clean('<style>.x{color:red}</style><p>visible</p>');
check('style tag removed', !has($out, '<style'));
check('style body discarded', !has($out, 'color:red'));
check('sibling content kept', has($out, 'visible'));

$out = CmsSanitizer::Clean('<form action="/x"><input name="y"><button>go</button></form><p>after</p>');
check('form removed', !has($out, '<form'));
check('input removed', !has($out, '<input'));
check('button removed', !has($out, '<button'));
check('content after form kept', has($out, 'after'));

$out = CmsSanitizer::Clean('<iframe src="//evil/x"></iframe><p>ok</p>');
check('iframe removed', !has($out, '<iframe'));

// HTML comments are stripped (IE conditional-comment vector).
$out = CmsSanitizer::Clean('<p>a</p><!--[if IE]><script>x</script><![endif]--><p>b</p>');
check('comment stripped', !has($out, '<!--'));
check('comment-hidden script gone', !has($out, '<script'));

// Empty / non-string input.
check('empty string → empty', CmsSanitizer::Clean('') === '');
check('non-string → empty', CmsSanitizer::Clean(null) === '');
check('CleanFragment mirrors Clean', CmsSanitizer::CleanFragment('<b>x</b>') === CmsSanitizer::Clean('<b>x</b>'));

// ---------------------------------------------------------------------------
// #102 — size / depth / node budget guards (must bound, not crash)
// ---------------------------------------------------------------------------

// Oversize input: > 256 KB is truncated before the DOM parse. A 400 KB run of
// text inside a <p> must return promptly and never exceed the input size.
$big = '<p>' . str_repeat('A', 400 * 1024) . '</p>';
$t0  = microtime(true);
$out = CmsSanitizer::Clean($big);
$dt  = microtime(true) - $t0;
check('oversize input returns a string', is_string($out));
check('oversize output is bounded (< input)', strlen($out) < strlen($big));
check('oversize input handled quickly (< 2s)', $dt < 2.0);

// Deep nesting: 300 nested blockquotes exceed MAX_DEPTH (60); the walk drops the
// subtree past the ceiling rather than recursing unbounded.
$deep = str_repeat('<blockquote>', 300) . 'x' . str_repeat('</blockquote>', 300);
$t0   = microtime(true);
$out  = CmsSanitizer::Clean($deep);
$dt   = microtime(true) - $t0;
check('deep nesting returns a string', is_string($out));
check('deep nesting bounded below input depth', substr_count($out, '<blockquote') > 0 && substr_count($out, '<blockquote') < 300);
check('deep nesting handled quickly (< 2s)', $dt < 2.0);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
