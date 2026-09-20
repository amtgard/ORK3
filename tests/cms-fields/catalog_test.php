<?php

// tests/cms-fields/catalog_test.php — run: php tests/cms-fields/catalog_test.php
//
// Store Catalog block (frontdoor/blocks/catalog.tpl) + the 'store' page type.
//
// Covers: the registry wiring (block def appended, page type + chooser lists),
// the "Buy on {store}" auto-detect (CmsBlockRegistry::CatalogStoreName) and its
// JS mirror in cms-block-editor.js, and the partial's rendered output — escaping,
// hostile links, sold-out, badges, and the empty-block behaviour. The partial is
// rendered for real (extract()+include, like render_blocks.tpl) against the
// pure CmsSanitizer / CmsBlockRegistry classes; nothing here needs the DB.

$root = dirname(__DIR__, 2);

$fails = 0;
function check($label, $cond)
{
    global $fails;
    echo($cond ? "PASS  $label\n" : "FAIL  $label\n");
    if (!$cond) {
        $fails++;
    }
}

require $root . '/system/lib/ork3/class.CmsSanitizer.php';
require $root . '/system/lib/ork3/class.CmsBlockRegistry.php';

if (!function_exists('fdEmptyBlockNotice')) {
    function fdEmptyBlockNotice($message)
    {
        echo '<div class="fd-pad fd-empty">' . $message . '</div>';
    }
}

function renderCatalog($root, array $blockFields, $fdIsPreview = false)
{
    unset($GLOBALS['__fd_catalog_modal_emitted']);
    ob_start();
    include $root . '/orkui/template/default/frontdoor/blocks/catalog.tpl';
    return (string) ob_get_clean();
}

function catalogIsland($html)
{
    if (!preg_match('#<script type="application/json" class="fdb-catalog-data">(.*?)</script>#s', $html, $m)) {
        return null;
    }
    return json_decode($m[1], true);
}

// ---------------------------------------------------------------------------
// Registry
// ---------------------------------------------------------------------------
$defs = CmsBlockRegistry::BlockDefs();
check('catalog block is registered', isset($defs['catalog']));
$keys = array_keys($defs);
check('catalog is APPENDED (last), not inserted — block order is load-bearing', end($keys) === 'catalog');
check('catalog is addable in every scope', !empty($defs['catalog']['addable']) && $defs['catalog']['scopes'] === null);
check('catalog starter carries an items list', isset($defs['catalog']['starter_fields']['items']) && $defs['catalog']['starter_fields']['items'] === array());

$pts = CmsBlockRegistry::PageTypeDefs();
check('store page type is registered', isset($pts['store']) && $pts['store']['label'] === 'Store catalog');
check('store page seeds heading + catalog', $pts['store']['starters'] === array('heading', 'catalog'));
check('store chooser offers catalog', in_array('catalog', $pts['store']['extra_blocks'], true));
check('photo gallery chooser offers catalog', in_array('catalog', $pts['media']['extra_blocks'], true));

$mig = (string) @file_get_contents($root . '/db-migrations/2026-09-19-cms-page-type-store.sql');
check('store is in the ork_cms_page.type ENUM migration', strpos($mig, "'about','store')") !== false);
$cls = (string) @file_get_contents($root . '/tools/ork-db/manifests/migration-classification.json5');
check('store migration is classified for drift-check', strpos($cls, '2026-09-19-cms-page-type-store.sql') !== false);

// ---------------------------------------------------------------------------
// Store-name detection
// ---------------------------------------------------------------------------
$cases = array(
    'https://www.redbubble.com/i/sticker/Tabard-by-x/123'  => 'Redbubble',
    'https://redbubble.com/shop/ap/123'                    => 'Redbubble',
    'https://www.spoonflower.com/en/fabric/999-heraldry'   => 'Spoonflower',
    'https://shop.spoonflower.com/x'                       => 'Spoonflower',
    'https://www.etsy.com/listing/1/patch'                 => 'Etsy',
    'https://amzn.to/3abc'                                 => 'Amazon',
    'https://my-guild-shop.example.org/p/1'                => 'my-guild-shop.example.org',
    'https://notredbubble.com/x'                           => 'notredbubble.com',
    '/p/merch'                                             => '',
    '#'                                                    => '',
    ''                                                     => '',
);
foreach ($cases as $url => $want) {
    check('store name for ' . ($url === '' ? "''" : $url) . ' => ' . ($want === '' ? "''" : $want), CmsBlockRegistry::CatalogStoreName($url) === $want);
}

// The editor mirrors the host map to preview the detected name; keys must match.
$js = (string) file_get_contents($root . '/orkui/template/default/script/cms-block-editor.js');
$jsKeys = array();
if (preg_match('/var CATALOG_STORES = \{(.*?)\};/s', $js, $m)) {
    preg_match_all("/'([a-z0-9.-]+)'\\s*:\\s*'([^']+)'/", $m[1], $mm, PREG_SET_ORDER);
    foreach ($mm as $pair) {
        $jsKeys[$pair[1]] = $pair[2];
    }
}
check('editor CATALOG_STORES mirrors CmsBlockRegistry::CatalogStores()', $jsKeys === CmsBlockRegistry::CatalogStores());

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------
$item = function (array $over = array()) {
    return array_merge(array(
        'image'       => array('src' => '/assets/cms-media/a.png', 'thumb' => '/assets/cms-media/a.thumb.webp', 'display' => '/assets/cms-media/a.display.webp', 'alt' => 'A patch'),
        'images'      => array(array('src' => '/assets/cms-media/b.png', 'thumb' => '/assets/cms-media/b.thumb.webp', 'alt' => 'Back')),
        'title'       => 'Tabard Patch',
        'subtitle'    => 'Embroidered · 3 in',
        'price'       => '$12',
        'description' => "Line one\nLine two",
        'href'        => 'https://www.redbubble.com/i/patch/1',
        'store'       => '',
        'badge'       => '',
        'badge_text'  => '',
    ), $over);
};

$html = renderCatalog($root, array('heading' => 'Merch', 'columns' => 4, 'items' => array($item())));
check('renders the grid', strpos($html, 'class="fdb-catalog-grid"') !== false);
check('column count carried as --fdb-cols', strpos($html, '--fdb-cols:4;') !== false);
check('card uses the display rendition', strpos($html, 'src="/assets/cms-media/a.display.webp"') !== false);
check('auto-detected CTA reads "Buy on Redbubble"', strpos($html, 'Buy on Redbubble') !== false);
check('CTA opens in a new tab with noopener', strpos($html, 'target="_blank" rel="noopener noreferrer"') !== false);
check('emits the shared modal once', substr_count($html, 'id="fdCatalogModal"') === 1);
$data = catalogIsland($html);
check('modal island decodes', is_array($data) && count($data) === 1);
check('modal photos = cover + extras, full size', is_array($data) && array_column($data[0]['photos'], 'src') === array('/assets/cms-media/a.png', '/assets/cms-media/b.png'));
check('modal keeps description line breaks', is_array($data) && $data[0]['description'] === "Line one\nLine two");

$html = renderCatalog($root, array('items' => array($item(array('store' => 'Our Shop')))));
check('an authored store name overrides detection', strpos($html, 'Buy on Our Shop') !== false && strpos($html, 'Buy on Redbubble') === false);

$html = renderCatalog($root, array('items' => array($item(array('href' => '')))));
check('no link => no Buy button', strpos($html, 'fdb-catalog-cta"') === false && strpos($html, 'Buy on') === false);

$html = renderCatalog($root, array('items' => array($item(array('href' => 'javascript:alert(1)')))));
check('javascript: link never reaches the page', stripos($html, 'javascript:') === false);

$html = renderCatalog($root, array('items' => array($item(array('badge' => 'sold_out')))));
check('sold out: card flagged', strpos($html, 'fdb-catalog-card is-sold-out') !== false);
check('sold out: no live Buy link', strpos($html, 'Buy on Redbubble') === false && strpos($html, '>Sold out<') !== false);

$html = renderCatalog($root, array('items' => array($item(array('badge' => 'going_soon')))));
check('preset badge renders with its key + label', strpos($html, 'data-badge="going_soon">Going Soon<') !== false);

$html = renderCatalog($root, array('items' => array($item(array('badge' => 'custom', 'badge_text' => 'Crown Qual Only')))));
check('custom badge uses the author text', strpos($html, 'data-badge="custom">Crown Qual Only<') !== false);

$html = renderCatalog($root, array('items' => array($item(array('badge' => 'custom', 'badge_text' => '  ')))));
check('custom badge with no text renders no badge', strpos($html, 'fdb-catalog-badge"') === false);

$html = renderCatalog($root, array('items' => array($item(array('badge' => 'bogus')))));
check('unknown badge key renders no badge', strpos($html, 'data-badge="bogus"') === false);

$evil = '<img src=x onerror=alert(1)>';
$html = renderCatalog($root, array('heading' => $evil, 'items' => array($item(array(
    'title' => $evil, 'subtitle' => $evil, 'price' => $evil, 'description' => '</script><script>alert(2)</script>',
    'store' => $evil, 'badge' => 'custom', 'badge_text' => $evil,
)))));
check('authored strings are escaped in markup', strpos($html, '<img src=x') === false);
check('JSON island cannot be broken out of', substr_count($html, '</script>') === 1 && strpos($html, '<script>alert(2)') === false);
$data = catalogIsland($html);
check('island still round-trips the hostile text as data', is_array($data) && $data[0]['title'] === $evil);

$html = renderCatalog($root, array('items' => array($item(array('title' => '', 'image' => array())), array())));
check('items with no name and no photo are skipped (public: renders nothing)', trim($html) === '');

$html = renderCatalog($root, array('items' => array()), true);
check('empty block in preview shows the author notice', strpos($html, 'fd-empty') !== false && strpos($html, 'fdCatalogModal') === false);

$html = renderCatalog($root, array('heading' => 'Coming soon', 'items' => array()));
check('heading-only block renders its header but no modal', strpos($html, 'Coming soon') !== false && strpos($html, 'fdCatalogModal') === false);

$html = renderCatalog($root, array('columns' => 9, 'items' => array($item())));
check('out-of-range columns clamp to 3', strpos($html, '--fdb-cols:3;') !== false);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
