<?php

// tests/cms-sanitizer/preview_render_test.php — run: php tests/cms-sanitizer/preview_render_test.php
//
// E2 (live preview) contract test for CmsPage::SanitizeBlocksForRender, the
// method behind CmsAjax/previewblocks.
//
// THE RISK THIS COVERS. previewblocks renders author-supplied JSON that has not
// been anywhere near the database. If its clean were a preview-shaped copy of
// the save-time clean, the two would drift and the preview would become a
// stored-XSS-shaped hole that also lies about what publishing will produce.
// The method therefore calls the SAME private _normalizeBlocks() that
// ReplaceBlocks() calls. These tests assert that, twice over:
//
//   (a) STRUCTURALLY — a source check that both entry points call
//       _normalizeBlocks, so there is literally one implementation; and
//   (b) BEHAVIOURALLY — every payload's sanitized field is compared byte-for-byte
//       against CmsSanitizer::Clean / ::SafeHrefOrHash run directly, which is
//       what _sanitizeBlockFields does on the way to disk.
//
// Plus the row-shape contract: previewed blocks must be indistinguishable from
// stored ones to render_blocks.tpl (same keys, same key order, same types).
//
// CmsPage extends the framework CmsBase/Ork3, which are not bootstrapped in a
// bare `php` run, so the parents are stubbed and the object is built without its
// constructor. SanitizeBlocksForRender is pure — no DB, no cache, no write — so
// that is enough. Follows the plain-PHP check() harness used by
// tests/cms-sanitizer/sanitizer_test.php.

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

require $root . '/system/lib/ork3/class.CmsSanitizer.php';

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'ork_');
}
if (!class_exists('Ork3', false)) {
    eval('class Ork3 { public function __construct() {} }');
}
if (!class_exists('CmsBase', false)) {
    eval('class CmsBase extends Ork3 {}');
}
require $root . '/system/lib/ork3/class.CmsPage.php';

$rc   = new ReflectionClass('CmsPage');
$page = $rc->newInstanceWithoutConstructor();

check('CmsPage::SanitizeBlocksForRender exists', $rc->hasMethod('SanitizeBlocksForRender'));
check('SanitizeBlocksForRender is public', $rc->getMethod('SanitizeBlocksForRender')->isPublic());

// ---------------------------------------------------------------------------
// (a) STRUCTURAL — one implementation, not two.
// ---------------------------------------------------------------------------
$src = (string) file_get_contents($root . '/system/lib/ork3/class.CmsPage.php');

function bodyOf($src, $name)
{
    $i = strpos($src, 'function ' . $name . '(');
    if ($i === false) {
        return '';
    }
    $open = strpos($src, '{', $i);
    if ($open === false) {
        return '';
    }
    $d = 0;
    for ($j = $open; $j < strlen($src); $j++) {
        if ($src[$j] === '{') {
            $d++;
        } elseif ($src[$j] === '}') {
            $d--;
            if ($d === 0) {
                return substr($src, $i, $j - $i + 1);
            }
        }
    }
    return '';
}

$previewBody = bodyOf($src, 'SanitizeBlocksForRender');
$replaceBody = bodyOf($src, 'ReplaceBlocks');
check('SanitizeBlocksForRender body located', $previewBody !== '');
check('ReplaceBlocks body located', $replaceBody !== '');
check('preview path calls _normalizeBlocks', strpos($previewBody, '_normalizeBlocks(') !== false);
check('save path calls _normalizeBlocks', strpos($replaceBody, '_normalizeBlocks(') !== false);
// The preview must not carry a sanitizer of its own — that is the drift this
// whole test exists to prevent.
check('preview path has no CmsSanitizer call of its own', strpos($previewBody, 'CmsSanitizer') === false);
check('preview path writes nothing (no $DB)', strpos($previewBody, '$DB') === false);

// ---------------------------------------------------------------------------
// (b) BEHAVIOURAL — the payloads, and equality with the save-time primitives.
// ---------------------------------------------------------------------------

// 1. <script> in an HTML field.
$out = $page->SanitizeBlocksForRender(array(
    array('type' => 'rich_text', 'fields' => array('body' => '<p>ok</p><script>alert(1)</script>')),
));
$body = $out[0]['fields']['body'];
check('script tag removed from body', stripos($body, '<script') === false);
check('script payload removed from body', stripos($body, 'alert(1)') === false);
check('surrounding markup kept', stripos($body, 'ok') !== false);
check('body === CmsSanitizer::Clean of the same input',
    $body === CmsSanitizer::Clean('<p>ok</p><script>alert(1)</script>'));

// 2. on* handler.
$out = $page->SanitizeBlocksForRender(array(
    array('type' => 'rich_text', 'fields' => array('body' => '<img src="/logo.png" onerror="alert(1)">')),
));
$body = $out[0]['fields']['body'];
check('onerror handler stripped', stripos($body, 'onerror') === false);
check('onerror payload gone', stripos($body, 'alert(1)') === false);
check('img element survives', stripos($body, '<img') !== false);

// 3. raw_html's `html` field is the other HTML_FIELD and takes the same clean.
$out = $page->SanitizeBlocksForRender(array(
    array('type' => 'raw_html', 'fields' => array('html' => '<svg onload="alert(1)"><circle/></svg><p>kept</p>')),
));
$html = $out[0]['fields']['html'];
check('svg onload dropped from html field', stripos($html, 'onload') === false && stripos($html, '<svg') === false);
check('html === CmsSanitizer::Clean of the same input',
    $html === CmsSanitizer::Clean('<svg onload="alert(1)"><circle/></svg><p>kept</p>'));

// 4. javascript: / data: / protocol-relative in URL fields — including one
//    nested inside a repeater, which is where authors actually put them.
$badHrefs = array(
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    'java&#115;cript:alert(1)',
    'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    '//evil.example.com/x',
);
foreach ($badHrefs as $bad) {
    $out = $page->SanitizeBlocksForRender(array(
        array('type' => 'cta_band', 'fields' => array(
            'heading' => 'H',
            'ctas'    => array(array('label' => 'Go', 'href' => $bad)),
        )),
    ));
    $got = $out[0]['fields']['ctas'][0]['href'];
    check('nested href neutralized: ' . $bad, stripos($got, 'javascript:') === false && stripos($got, 'data:') === false);
    check('nested href === SafeHrefOrHash: ' . $bad, $got === CmsSanitizer::SafeHrefOrHash($bad));
}

// A legitimate href must survive untouched — a preview that mangles good links
// is as misleading as one that renders bad ones.
$out = $page->SanitizeBlocksForRender(array(
    array('type' => 'cta_band', 'fields' => array(
        'ctas' => array(array('label' => 'Go', 'href' => 'https://amtgard.com/about')),
    )),
));
check('legitimate https href preserved',
    $out[0]['fields']['ctas'][0]['href'] === CmsSanitizer::SafeHrefOrHash('https://amtgard.com/about'));

// 5. Recursion depth: a columns block's CHILD block fields are sanitized too.
$out = $page->SanitizeBlocksForRender(array(
    array('type' => 'columns', 'fields' => array('columns' => array(
        array(array('type' => 'rich_text', 'fields' => array('body' => '<p>a</p><script>alert(2)</script>'))),
        array(),
    ))),
));
$childBody = $out[0]['fields']['columns'][0][0]['fields']['body'];
check('columns child body sanitized', stripos($childBody, '<script') === false && stripos($childBody, 'alert(2)') === false);

// ---------------------------------------------------------------------------
// Row-shape contract — a previewed block must be indistinguishable from a
// stored one to render_blocks.tpl (which reads type/enabled/fields) and to
// anything that json_encodes either.
// ---------------------------------------------------------------------------
$out = $page->SanitizeBlocksForRender(array(
    array('id' => '17', 'type' => 'heading', 'enabled' => 1, 'order' => 40, 'source' => 'authored',
        'fields' => array('text' => 'Hi', 'level' => 2)),
));
$row = $out[0];
check('row keys + ORDER match the stored-block shape',
    array_keys($row) === array('id', 'type', 'enabled', 'order', 'source', 'fields'));
check('id is an int', $row['id'] === 17);
check('enabled is a real bool (renderer + cache payload expect it)', $row['enabled'] === true);
check('order carries through', $row['order'] === 40);
check('source carries through', $row['source'] === 'authored');

// enabled=0 must stay a bool false, and stay PRESENT: render_blocks.tpl skips
// it, but dropping it here would silently reorder the rest.
$out = $page->SanitizeBlocksForRender(array(
    array('type' => 'heading', 'enabled' => 0, 'fields' => array('text' => 'off')),
    array('type' => 'heading', 'enabled' => 1, 'fields' => array('text' => 'on')),
));
check('disabled block kept in the list', count($out) === 2);
check('disabled block reports enabled === false', $out[0]['enabled'] === false);

// Defaults for a block the editor just created: no id, no order, no source.
$out = $page->SanitizeBlocksForRender(array(
    array('type' => 'heading', 'fields' => array('text' => 'new')),
    array('type' => 'quote', 'fields' => array('text' => 'q')),
));
check('missing id defaults to 0', $out[0]['id'] === 0);
check('missing order falls back to positional', $out[0]['order'] === 0 && $out[1]['order'] === 10);
check('missing source defaults to authored', $out[0]['source'] === 'authored');
check('missing enabled defaults to true', $out[0]['enabled'] === true);
check('source=dynamic preserved',
    $page->SanitizeBlocksForRender(array(array('type' => 'events_feed', 'source' => 'dynamic', 'fields' => array())))[0]['source'] === 'dynamic');

// Junk entries are skipped, not rendered.
$out = $page->SanitizeBlocksForRender(array(
    'not-an-array',
    array('fields' => array('text' => 'no type')),
    array('type' => '', 'fields' => array()),
    array('type' => 'heading', 'fields' => array('text' => 'kept')),
    array('type' => 'heading', 'fields' => 'not-an-array'),
));
check('non-array / typeless / empty-type entries dropped', count($out) === 2);
check('surviving entries are the typed ones',
    $out[0]['fields']['text'] === 'kept' && $out[1]['fields'] === array());
check('non-array input returns an empty list', $page->SanitizeBlocksForRender('nope') === array());
check('empty input returns an empty list', $page->SanitizeBlocksForRender(array()) === array());

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
