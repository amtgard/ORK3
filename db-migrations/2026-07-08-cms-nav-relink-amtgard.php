<?php

/**
 * Relink the global 'marketing' nav menu to the amtgard.com replication pages.
 *
 * The menu labels already mirror amtgard.com (seeded by 2026-06-23-cms-seed-nav
 * / relinked to live amtgard.com URLs by 2026-06-23-cms-nav-relink). Now that we
 * have real CMS pages for every section (2026-07-08-cms-seed-amtgard.php), point
 * each label at its CMS page; keep the two intentional externals (Find a Chapter
 * -> Atlas, Merch -> Redbubble) and Home -> front-door root.
 *
 * Matching is by exact label within menu='marketing', scope global. Idempotent.
 *
 * Run:
 *   docker exec ork3-php8-app php \
 *     /var/www/ork.amtgard.com/db-migrations/2026-07-08-cms-nav-relink-amtgard.php
 */

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

$nav = new CmsNav();
$cms = new CmsPage();

$pid = function ($slug) use ($cms) {
    $r = $cms->GetPageBySlug($slug, 'global', 0, true);
    return (!empty($r) && !empty($r['page_id'])) ? (int) $r['page_id'] : null;
};

// label => CMS page slug (internal). Every section we replicated.
$pageFor = array(
    'About' => 'about', 'Join' => 'join', 'AI Programs' => 'programs',
    'Media' => 'media', 'Official Resources' => 'resources',
    'Mission' => 'mission', 'Staff' => 'staff', 'Volunteers' => 'volunteers',
    'Learn the Basics' => 'learn-the-basics', 'Start a Chapter' => 'start-a-chapter',
    'Food Fight' => 'foodfight', 'Olympiad' => 'olympiad',
    'Galleries' => 'galleries', 'Writing' => 'writing', 'Documents' => 'documents',
);
// label => [link_type, url] for the intentional non-page destinations.
$external = array(
    'Home' => array('dynamic', 'index.php?Route='),
    'Find a Chapter' => array('dynamic', 'Atlas'),
    'Merch' => array('url', 'https://www.redbubble.com/people/amtgardmarket/shop'),
);

$items = $nav->ListItems('marketing', 'global', 0);
$updated = array();
$skipped = array();
$unmatched = array();

foreach ($items as $it) {
    $label = (string) (isset($it['label']) ? $it['label'] : '');
    if (isset($pageFor[$label])) {
        $id = $pid($pageFor[$label]);
        if (!$id) {
            $skipped[] = "$label (page '{$pageFor[$label]}' missing)";
            continue;
        }
        $nav->UpdateItem((int) $it['nav_id'], array(
            'link_type' => 'page', 'page_id' => $id, 'post_id' => null, 'url' => null,
        ));
        $updated[] = "$label -> page:{$pageFor[$label]}#$id";
    } elseif (isset($external[$label])) {
        list($lt, $url) = $external[$label];
        $nav->UpdateItem((int) $it['nav_id'], array(
            'link_type' => $lt, 'page_id' => null, 'post_id' => null, 'url' => $url,
        ));
        $updated[] = "$label -> $lt:$url";
    } else {
        $unmatched[] = $label;
    }
}

echo json_encode(array(
    'updated' => $updated, 'skipped' => $skipped, 'unmatched' => $unmatched,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
