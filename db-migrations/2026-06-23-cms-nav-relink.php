<?php

/**
 * CMS migration — relink the 'marketing' nav menu to real destinations.
 *
 * The menu was originally seeded (2026-06-23-cms-seed-nav.php) with every item
 * as link_type='url', url='#' (placeholders). This migration repoints each item
 * at its proper destination, mirroring amtgard.com's menu structure:
 *
 *   - items we have built as CMS pages       -> link_type='page'   (by slug)
 *   - internal ORK features (chapter dir,     -> link_type='dynamic' (route key)
 *     the blog)
 *   - sections not yet built as CMS pages     -> link_type='url'    (live
 *                                                amtgard.com page)
 *   - Home                                    -> the front-door root
 *
 * It also points the home page's marketing_nav block CTA ("Find a Chapter") and
 * login ("Record Keeper") at the chapter directory (Atlas) and the ORK proper
 * (the Kingdoms Directory) respectively. Sub-page chrome (site_header.tpl) and
 * the canonical defaults (model.FrontDoor.php) are updated in code separately.
 *
 * Matching is by exact label within menu='marketing', scope global — robust
 * across environments (no hardcoded nav_ids/page_ids). Idempotent: re-running
 * simply re-applies the same targets.
 *
 * Run:
 *   docker exec ork3-php8-app php \
 *     /var/www/ork.amtgard.com/db-migrations/2026-06-23-cms-nav-relink.php
 *
 * No destructive operations; safe to run repeatedly.
 */

// Web-reachable file: refuse any non-CLI (HTTP) invocation.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}

require_once __DIR__ . '/../startup.php';

if (!defined('UIR')) {
    define('UIR', HTTP_UI_REMOTE . 'index.php?Route=');
}

global $DB;

$nav = new CmsNav();
$cms = new CmsPage();

// ---------------------------------------------------------------------------
// Resolve CMS page ids by slug (null if a page is absent — that item is then
// left at its placeholder rather than pointed at a broken page link).
// ---------------------------------------------------------------------------
$pageId = function ($slug) use ($cms) {
    $row = $cms->GetPageBySlug($slug, 'global', 0, true);
    return (!empty($row) && !empty($row['page_id'])) ? (int) $row['page_id'] : null;
};
$aboutId   = $pageId('about');
$joinId    = $pageId('join');
$galleryId = $pageId('media-gallery');

// ---------------------------------------------------------------------------
// label  =>  target spec. Internal pages by id; internal features by route key
// (dynamic); external sections by absolute url; Home -> relative front-door root.
// ---------------------------------------------------------------------------
$targets = [
    // Top-level
    'Home'               => ['type' => 'url', 'url' => 'index.php?Route='],
    'About'              => ['type' => 'page', 'page_id' => $aboutId],
    'Join'               => ['type' => 'page', 'page_id' => $joinId],
    'AI Programs'        => ['type' => 'url', 'url' => 'https://www.amtgard.com/programs'],
    'Media'              => ['type' => 'page', 'page_id' => $galleryId],
    'Official Resources' => ['type' => 'url', 'url' => 'https://www.amtgard.com/resources'],
    'Merch'              => ['type' => 'url', 'url' => 'https://www.redbubble.com/people/amtgardmarket/shop'],
    // About children
    'Mission'            => ['type' => 'url', 'url' => 'https://www.amtgard.com/mission'],
    'Staff'              => ['type' => 'url', 'url' => 'https://www.amtgard.com/staff'],
    'Volunteers'         => ['type' => 'url', 'url' => 'https://www.amtgard.com/volunteers'],
    // Join children
    'Learn the Basics'   => ['type' => 'url', 'url' => 'https://www.amtgard.com/learn-the-basics'],
    'Find a Chapter'     => ['type' => 'dynamic', 'url' => 'Atlas'],
    'Start a Chapter'    => ['type' => 'url', 'url' => 'https://www.amtgard.com/start-a-chapter'],
    // AI Programs children
    'Food Fight'         => ['type' => 'url', 'url' => 'https://www.amtgard.com/foodfight'],
    'Olympiad'           => ['type' => 'url', 'url' => 'https://www.amtgard.com/olympiad'],
    // Media children
    'Galleries'          => ['type' => 'page', 'page_id' => $galleryId],
    'Writing'            => ['type' => 'dynamic', 'url' => 'Blog/index'],
    // Official Resources children
    'Documents'          => ['type' => 'url', 'url' => 'https://www.amtgard.com/documents'],
];

// ---------------------------------------------------------------------------
// Apply per-item relink (match by label within the marketing menu).
// ---------------------------------------------------------------------------
$items     = $nav->ListItems('marketing', 'global', 0);
$updated   = [];
$skipped   = [];
$unmatched = [];

foreach ($items as $item) {
    $label = (string) ($item['label'] ?? '');
    if (!isset($targets[$label])) {
        $unmatched[] = $label;
        continue;
    }
    $spec = $targets[$label];

    if ($spec['type'] === 'page') {
        if (empty($spec['page_id'])) {
            // Page not present yet — leave as-is instead of linking to nothing.
            $skipped[] = $label . ' (page missing)';
            continue;
        }
        $nav->UpdateItem((int) $item['nav_id'], [
            'link_type' => 'page',
            'page_id'   => (int) $spec['page_id'],
            'post_id'   => null,
            'url'       => null,
        ]);
    } elseif ($spec['type'] === 'dynamic') {
        $nav->UpdateItem((int) $item['nav_id'], [
            'link_type' => 'dynamic',
            'page_id'   => null,
            'post_id'   => null,
            'url'       => $spec['url'],
        ]);
    } else { // url
        $nav->UpdateItem((int) $item['nav_id'], [
            'link_type' => 'url',
            'page_id'   => null,
            'post_id'   => null,
            'url'       => $spec['url'],
        ]);
    }
    $updated[] = $label . ' -> ' . $spec['type'];
}

// ---------------------------------------------------------------------------
// Home page marketing_nav block: point CTA + login at internal routes. The
// block's items[] are ignored at render (marketing_nav.tpl reads the nav store)
// but its cta/login chrome is authoritative — JSON_SET just those two hrefs.
// Relative routes keep the stored value host-agnostic.
// ---------------------------------------------------------------------------
$home = $cms->GetHomePage();
$homeBlockUpdated = false;
if (!empty($home) && !empty($home['page_id'])) {
    $DB->Clear();
    $DB->cta      = 'index.php?Route=Atlas';
    $DB->login    = 'index.php?Route=Directory';
    $DB->owner_id = (int) $home['page_id'];
    $DB->Execute(
        'UPDATE ' . DB_PREFIX . "cms_block"
        . " SET fields_json = JSON_SET(fields_json, '$.cta.href', :cta, '$.login.href', :login)"
        . " WHERE owner_type = 'page' AND owner_id = :owner_id AND type = 'marketing_nav'"
    );
    $homeBlockUpdated = true;
}

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
echo json_encode([
    'resolved_page_ids' => ['about' => $aboutId, 'join' => $joinId, 'media-gallery' => $galleryId],
    'updated'           => $updated,
    'skipped'           => $skipped,
    'unmatched'         => $unmatched,
    'home_block_cta_login_updated' => $homeBlockUpdated,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
