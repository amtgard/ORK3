<?php

/**
 * CMS seed — home page + a tiny test page.
 *
 * Part of the Amtgard CMS (v2) foundation
 * (docs/superpowers/specs/2026-06-23-amtgard-cms-design.md). Run AFTER the
 * foundation migration (db-migrations/2026-06-23-cms-foundation.sql) has
 * created the ork_cms_* tables.
 *
 * What it does (idempotent):
 *   1. Imports the hardcoded Model_FrontDoor::GetContent() block defaults and
 *      writes them into the store as the `home` system page
 *      (slug='home', type='composed', status='published', is_system=1, global).
 *      Skipped entirely if a `home` page already exists.
 *   2. Seeds a tiny published test page (slug='test-cms') with two blocks
 *      (richtext + cta_band) so Page/view/test-cms can be verified. Skipped if
 *      it already exists.
 *
 * Run:
 *   docker exec ork3-php8-app php \
 *     /var/www/ork.amtgard.com/db-migrations/2026-06-23-cms-seed-home.php
 *
 * No destructive operations; safe to run repeatedly.
 */

// ---------------------------------------------------------------------------
// Minimal app bootstrap (CLI). startup.php loads the DB + all libs but does
// NOT define UIR or a web HTTP host. Model_FrontDoor::GetContent() references
// UIR (and HTTP_TEMPLATE, which is built from HTTP_HOST), so provide sane
// CLI-time stand-ins BEFORE startup so the imported defaults are well-formed.
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

// The CmsPage lib (DB store) is auto-loaded by startup from DIR_ORK3.
$cms = new CmsPage();

$now = date('Y-m-d H:i:s');
$report = [];

// ---------------------------------------------------------------------------
// 1) Home page — import Model_FrontDoor defaults.
// ---------------------------------------------------------------------------
$existingHome = $cms->GetPageBySlug('home', 'global', 0, false); // any status
if (!empty($existingHome) && !empty($existingHome['page_id'])) {
    $homePageId = (int) $existingHome['page_id'];
    $report['home'] = [
        'created'  => false,
        'page_id'  => $homePageId,
        'note'     => 'home page already exists; left untouched',
    ];
} else {
    $homePageId = (int) $cms->CreatePage([
        'slug'       => 'home',
        'type'       => 'composed',
        'title'      => 'Home',
        'status'     => 'published',
        'is_system'  => 1,
        'scope_type' => 'global',
        'scope_id'   => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $homeInserted = 0;
    if ($homePageId > 0) {
        $frontDoor = new Model_FrontDoor();
        $blocks = $frontDoor->GetContent([
            'logged_in'  => false,
            'kingdom_id' => 0,
        ]);
        // ReplaceBlocks reads 'order' from each block and json-encodes 'fields'.
        $homeInserted = (int) $cms->ReplaceBlocks('page', $homePageId, $blocks);
    }

    $report['home'] = [
        'created'         => $homePageId > 0,
        'page_id'         => $homePageId,
        'blocks_inserted' => $homeInserted,
    ];
}

// ---------------------------------------------------------------------------
// 2) Tiny test page (slug='test-cms') with two real, renderable blocks.
//    Uses existing block partials only: richtext + cta_band.
// ---------------------------------------------------------------------------
$existingTest = $cms->GetPageBySlug('test-cms', 'global', 0, false); // any status
if (!empty($existingTest) && !empty($existingTest['page_id'])) {
    $testPageId = (int) $existingTest['page_id'];
    $report['test'] = [
        'created' => false,
        'page_id' => $testPageId,
        'slug'    => 'test-cms',
        'note'    => 'test page already exists; left untouched',
    ];
} else {
    $testPageId = (int) $cms->CreatePage([
        'slug'             => 'test-cms',
        'type'             => 'composed',
        'title'            => 'CMS Test Page',
        'status'           => 'published',
        'is_system'        => 0,
        'scope_type'       => 'global',
        'scope_id'         => 0,
        'meta_description' => 'A small CMS-managed test page.',
        'created_at'       => $now,
        'updated_at'       => $now,
    ]);

    $testInserted = 0;
    if ($testPageId > 0) {
        $testBlocks = [
            [
                'type'    => 'richtext',
                'enabled' => true,
                'order'   => 10,
                'source'  => 'authored',
                'fields'  => [
                    'kicker'  => 'CMS Test',
                    'heading' => 'Hello from the CMS',
                    'align'   => 'center',
                    'body'    => 'This page is stored entirely in <strong>ork_cms_page</strong> + '
                        . '<strong>ork_cms_block</strong> and rendered through the shared '
                        . 'front-door block renderer. If you can read this, the page render '
                        . 'path works end to end.',
                    'cta'     => ['label' => 'Back to Home →', 'href' => UIR],
                ],
            ],
            [
                'type'    => 'cta_band',
                'enabled' => true,
                'order'   => 20,
                'source'  => 'authored',
                'fields'  => [
                    'heading' => 'Ready to take up arms?',
                    'subcopy' => 'There\'s a chapter near you, and your first day on the field is always free.',
                    'ctas'    => [
                        ['label' => 'Find Amtgard Near You', 'href' => '#', 'style' => 'gold'],
                        ['label' => 'Official Resources', 'href' => '#', 'style' => 'ghost'],
                    ],
                    'links'   => 'amtgard.com · play.amtgard.com · Online Record Keeper',
                ],
            ],
        ];
        $testInserted = (int) $cms->ReplaceBlocks('page', $testPageId, $testBlocks);
    }

    $report['test'] = [
        'created'         => $testPageId > 0,
        'page_id'         => $testPageId,
        'slug'            => 'test-cms',
        'blocks_inserted' => $testInserted,
    ];
}

// ---------------------------------------------------------------------------
// Verification summary (read back from the store).
// ---------------------------------------------------------------------------
global $DB;
$DB->Clear();
$rs = $DB->DataSet(
    'SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . "cms_block WHERE owner_type = 'page'"
);
$totalPageBlocks = 0;
if ($rs && $rs->Next()) {
    $totalPageBlocks = (int) $rs->cnt;
}
$DB->Clear();
$report['total_page_blocks'] = $totalPageBlocks;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
