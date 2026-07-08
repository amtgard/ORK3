<?php

/**
 * Relink the dead `href="#"` CTA/card links stored in the published `home`
 * page's blocks (ork_cms_block, owner_type='page').
 *
 * Background: the front door's HOME render path is served by the base
 * Controller::index() reading the STORED `home` page blocks (system/lib/system
 * /class.Controller.php), NOT by Model_FrontDoor::GetContent() (that model is
 * only the fallback used when no `home` page exists / to seed one). The home
 * page was seeded once (2026-06-23-cms-seed-home.php) from an earlier version
 * of Model_FrontDoor::GetContent() that had placeholder '#' hrefs on several
 * CTAs/cards. Model_FrontDoor::GetContent() has since been fixed to emit real
 * internal destinations (this same change set), but that source only affects
 * a FUTURE re-seed — it does not touch the already-stored row. This migration
 * patches the stored row directly so the live HOME page's links work today.
 *
 * Scope: only fields matched by (a) exact href === '#' AND (b) a sibling
 * label/title we recognize are rewritten. The marketing_nav block's items are
 * intentionally left untouched — marketing_nav.tpl sources the real menu live
 * from the editable CmsNav 'marketing' store (already relinked by
 * 2026-07-08-cms-nav-relink-amtgard.php) whenever that store is non-empty, so
 * its own stored '#' fallback values are inert dead code, not live links.
 *
 * Idempotent: running twice is a no-op the second time (nothing left to match
 * with href === '#' after the first run reports "no changes").
 *
 * Run:
 *   docker exec ork3-php8-app php \
 *     /var/www/ork.amtgard.com/db-migrations/2026-07-08-cms-home-relink.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (empty($_SERVER['HTTP_HOST'])) {
    // Matches the dev container's external origin (see reference_local_dev_routing).
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}
require_once __DIR__ . '/../startup.php';
if (!defined('UIR')) {
    // Host-agnostic relative internal-link base (matches 2026-07-08-cms-seed-amtgard.php).
    define('UIR', '/orkui/index.php?Route=');
}

$cms = new CmsPage();

$home = $cms->GetHomePage();
if (empty($home) || empty($home['page_id'])) {
    fwrite(STDERR, "No published global 'home' page found; nothing to relink.\n");
    exit(1);
}
$pageId = (int) $home['page_id'];

$blocks = $cms->GetPageBlocks($pageId);
if (empty($blocks)) {
    fwrite(STDERR, "Home page #$pageId has no blocks; nothing to relink.\n");
    exit(1);
}

// label/title (of the sibling entry that carries the href) => corrected
// destination. Mirrors the fixes made to Model_FrontDoor::GetContent() in
// this same change set, so newly-seeded homes and this already-stored one
// end up pointing at the same places.
$linkFor = array(
    'Find Amtgard Near You' => UIR . 'Atlas',
    'Find a Chapter'        => UIR . 'Atlas',
    'Watch & Learn'         => UIR . 'Page/view/learn-the-basics',
    'The full story →'      => UIR . 'Page/view/about',
    'Official Resources'    => UIR . 'Page/view/resources',
    // card_grid "Find Your Path" role cards (matched by card 'title').
    'The Warrior' => UIR . 'Page/view/learn-the-basics',
    'The Archer'  => UIR . 'Page/view/learn-the-basics',
    'The Caster'  => UIR . 'Page/view/learn-the-basics',
    'The Artisan' => UIR . 'Page/view/learn-the-basics',
    'The Monster' => UIR . 'Page/view/learn-the-basics',
    'The Leader'  => UIR . 'Page/view/join',
);

/**
 * Given a cta/card/link assoc array (has an 'href' key), return the corrected
 * href, or null if this href isn't a bare '#' or has no recognized label.
 *
 * @param array $item
 * @return string|null
 */
$resolve = function ($item) use ($linkFor) {
    if (!is_array($item) || !array_key_exists('href', $item)) {
        return null;
    }
    if ($item['href'] !== '#') {
        return null; // only touch dead placeholder links
    }
    $label = null;
    if (isset($item['label']) && is_string($item['label']) && $item['label'] !== '') {
        $label = $item['label'];
    } elseif (isset($item['title']) && is_string($item['title']) && $item['title'] !== '') {
        $label = $item['title'];
    }
    if ($label === null || !isset($linkFor[$label])) {
        return null;
    }
    return $linkFor[$label];
};

$changed = array();
$rebuilt = array();

foreach ($blocks as $block) {
    // The marketing_nav block's own stored 'items'/'cta'/'login' hrefs are
    // fallback-only (see file header); leave it byte-for-byte as stored.
    if ($block['type'] === 'marketing_nav') {
        $rebuilt[] = $block;
        continue;
    }

    $fields = is_array($block['fields']) ? $block['fields'] : array();

    // Walk every place a cta/card/link-shaped array can live in these known
    // front-door block field shapes: a single 'cta', or an 'ctas'/'cards'
    // list. (No other block types in the home page carry hrefs.)
    if (isset($fields['cta']) && is_array($fields['cta'])) {
        $new = $resolve($fields['cta']);
        if ($new !== null) {
            $changed[] = sprintf(
                "block #%d (%s) cta '%s': '#' -> '%s'",
                $block['id'],
                $block['type'],
                $fields['cta']['label'] ?? '',
                $new
            );
            $fields['cta']['href'] = $new;
        }
    }
    foreach (array('ctas', 'cards') as $listKey) {
        if (!isset($fields[$listKey]) || !is_array($fields[$listKey])) {
            continue;
        }
        foreach ($fields[$listKey] as $i => $entry) {
            $new = $resolve($entry);
            if ($new === null) {
                continue;
            }
            $label = $entry['label'] ?? ($entry['title'] ?? '');
            $changed[] = sprintf(
                "block #%d (%s) %s[%d] '%s': '#' -> '%s'",
                $block['id'],
                $block['type'],
                $listKey,
                $i,
                $label,
                $new
            );
            $fields[$listKey][$i]['href'] = $new;
        }
    }

    $block['fields'] = $fields;
    $rebuilt[] = $block;
}

if (empty($changed)) {
    echo json_encode(array(
        'page_id' => $pageId,
        'changed' => array(),
        'note'    => "no '#' hrefs matched a known label; nothing to do (already relinked?)",
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$stored = $cms->ReplaceBlocks('page', $pageId, $rebuilt);

echo json_encode(array(
    'page_id'       => $pageId,
    'blocks_stored' => $stored,
    'changed'       => $changed,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
