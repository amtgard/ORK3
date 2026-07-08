<?php

/**
 * CMS seed — marketing navigation menu.
 *
 * Part of the Amtgard CMS (v2) foundation
 * (docs/superpowers/specs/2026-06-23-amtgard-cms-design.md). Run AFTER the
 * foundation migration (db-migrations/2026-06-23-cms-foundation.sql) has
 * created the ork_cms_* tables (including ork_cms_nav_item).
 *
 * What it does (idempotent):
 *   Seeds the editable 'marketing' nav menu (scope global) from the canonical
 *   hardcoded Model_FrontDoor marketing_nav defaults. It reads
 *   Model_FrontDoor::GetContent(), finds the marketing_nav block, and inserts
 *   its items[] (and one level of children[]) into ork_cms_nav_item via the
 *   CmsNav lib:
 *     - each item -> link_type='url', url=<item href>, parent_id=NULL,
 *       ordering by position, enabled=1, scope_type='global', scope_id=0.
 *     - each child -> link_type='url', url=<child href>, parent_id=<new item id>.
 *   Skipped entirely if the 'marketing' menu already has ANY rows (so re-runs
 *   and hand-edits are never clobbered).
 *
 * Run:
 *   docker exec ork3-php8-app php \
 *     /var/www/ork.amtgard.com/db-migrations/2026-06-23-cms-seed-nav.php
 *
 * No destructive operations; safe to run repeatedly.
 */

// Web-reachable file: refuse any non-CLI (HTTP) invocation.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// ---------------------------------------------------------------------------
// Minimal app bootstrap (CLI). startup.php loads the DB + all libs but does
// NOT define UIR or a web HTTP host. Model_FrontDoor::GetContent() references
// UIR (and HTTP_TEMPLATE, built from HTTP_HOST), so provide sane CLI-time
// stand-ins BEFORE startup so the imported defaults are well-formed.
// ---------------------------------------------------------------------------
if (empty($_SERVER['HTTP_HOST'])) {
    // Matches the dev container's external origin (see reference_local_dev_routing).
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}

require_once __DIR__ . '/../startup.php';

if (!defined('UIR')) {
    define('UIR', HTTP_UI_REMOTE . 'index.php?Route=');
}

// Model_FrontDoor lives in the orkui model dir (DIR_MODEL); pull it in directly
// so we can import the canonical front-door defaults.
require_once DIR_MODEL . 'model.FrontDoor.php';

// The CmsNav lib (DB store) is auto-loaded by startup from DIR_ORK3.
$nav = new CmsNav();

$report = [];

// ---------------------------------------------------------------------------
// Idempotency guard — skip if the 'marketing' menu already has any rows
// (enabled OR disabled). ListItems returns the full flat list incl. disabled.
// ---------------------------------------------------------------------------
$existing = $nav->ListItems('marketing', 'global', 0);
if (is_array($existing) && count($existing) > 0) {
    $report = [
        'seeded'        => false,
        'existing_rows' => count($existing),
        'note'          => "'marketing' menu already has rows; left untouched",
    ];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    return;
}

// ---------------------------------------------------------------------------
// Pull the canonical marketing_nav block from the front-door defaults.
// ---------------------------------------------------------------------------
$frontDoor = new Model_FrontDoor();
$blocks    = $frontDoor->GetContent([
    'logged_in'  => false,
    'kingdom_id' => 0,
]);

$navItems = [];
foreach ((array) $blocks as $block) {
    if (isset($block['type']) && $block['type'] === 'marketing_nav') {
        $navItems = isset($block['fields']['items']) && is_array($block['fields']['items'])
            ? $block['fields']['items']
            : [];
        break;
    }
}

if (empty($navItems)) {
    $report = [
        'seeded' => false,
        'note'   => 'no marketing_nav items found in Model_FrontDoor defaults; nothing to seed',
    ];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    return;
}

// ---------------------------------------------------------------------------
// Insert top-level items (and one level of children) into ork_cms_nav_item.
// Hardcoded hrefs are stored verbatim as link_type='url' (the CmsNav lib
// renders '#'-only urls as-is; the admin can later relink them to pages/posts
// or dynamic routes). One dropdown level only, matching the schema.
// ---------------------------------------------------------------------------
$topInserted   = 0;
$childInserted = 0;
$ordering      = 0;

foreach ($navItems as $item) {
    $label = isset($item['label']) ? (string) $item['label'] : '';
    $href  = isset($item['href']) ? (string) $item['href'] : '';
    if ($label === '') {
        continue;
    }

    $ordering += 10;
    $newId = (int) $nav->CreateItem([
        'menu'       => 'marketing',
        'label'      => $label,
        'link_type'  => 'url',
        'url'        => $href,
        'parent_id'  => null,
        'ordering'   => $ordering,
        'enabled'    => 1,
        'scope_type' => 'global',
        'scope_id'   => 0,
    ]);
    if ($newId <= 0) {
        continue;
    }
    $topInserted++;

    if (!empty($item['children']) && is_array($item['children'])) {
        $childOrder = 0;
        foreach ($item['children'] as $child) {
            $childLabel = isset($child['label']) ? (string) $child['label'] : '';
            $childHref  = isset($child['href']) ? (string) $child['href'] : '';
            if ($childLabel === '') {
                continue;
            }
            $childOrder += 10;
            $childId = (int) $nav->CreateItem([
                'menu'       => 'marketing',
                'label'      => $childLabel,
                'link_type'  => 'url',
                'url'        => $childHref,
                'parent_id'  => $newId,
                'ordering'   => $childOrder,
                'enabled'    => 1,
                'scope_type' => 'global',
                'scope_id'   => 0,
            ]);
            if ($childId > 0) {
                $childInserted++;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Verification summary (read back from the store).
// ---------------------------------------------------------------------------
$afterRows = $nav->ListItems('marketing', 'global', 0);

$report = [
    'seeded'          => true,
    'top_inserted'    => $topInserted,
    'child_inserted'  => $childInserted,
    'total_inserted'  => $topInserted + $childInserted,
    'rows_after'      => is_array($afterRows) ? count($afterRows) : 0,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
