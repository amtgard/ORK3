<?php

/**
 * Polish the global 'marketing' nav menu:
 *
 *   1. Relabel "AI Programs" -> "Programs" (link_type/page_id/url untouched).
 *   2. Fill in the "Official Resources" dropdown's missing children. The
 *      Official Resources hub page has 8 cards but the dropdown only ever
 *      got one child ("Documents"). Read the real destinations from the
 *      resources page spec (db-migrations/.amtgard-assets/specs/resources.json)
 *      and add one external-url nav child per card that:
 *        - has a non-empty absolute http(s) href, and
 *        - isn't "Official Documents"/"Documents" (already a child), and
 *        - isn't already present as a child label (idempotency guard).
 *
 * Matching is by exact label within menu='marketing', scope global. Idempotent.
 *
 * Run:
 *   docker exec ork3-php8-app php \
 *     /var/www/ork.amtgard.com/db-migrations/2026-07-08-cms-nav-polish.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}
require_once __DIR__ . '/../startup.php';

$nav = new CmsNav();

$result = array(
    'relabeled' => array(),
    'children_added' => array(),
    'children_skipped' => array(),
);

$items = $nav->ListItems('marketing', 'global', 0);

// -------------------------------------------------------------------------
// 1. "AI Programs" -> "Programs"
// -------------------------------------------------------------------------
$officialResourcesNavId = null;
foreach ($items as $it) {
    $label = (string) (isset($it['label']) ? $it['label'] : '');
    if ($label === 'AI Programs') {
        $nav->UpdateItem((int) $it['nav_id'], array('label' => 'Programs'));
        $result['relabeled'][] = 'AI Programs -> Programs (nav_id ' . (int) $it['nav_id'] . ')';
    }
    if ($label === 'Official Resources') {
        $officialResourcesNavId = (int) $it['nav_id'];
    }
}

// -------------------------------------------------------------------------
// 2. Official Resources dropdown children
// -------------------------------------------------------------------------
if ($officialResourcesNavId === null) {
    $result['children_skipped'][] = '(no "Official Resources" nav item found)';
} else {
    $specPath = __DIR__ . '/.amtgard-assets/specs/resources.json';
    if (!is_file($specPath)) {
        $result['children_skipped'][] = "(resources.json spec not found at $specPath)";
    } else {
        $spec = json_decode(file_get_contents($specPath), true);
        $cards = array();
        if (is_array($spec) && !empty($spec['blocks'])) {
            foreach ($spec['blocks'] as $block) {
                if (isset($block['type']) && $block['type'] === 'card_grid' && !empty($block['fields']['cards'])) {
                    $cards = $block['fields']['cards'];
                    break;
                }
            }
        }

        // Re-list children fresh each time (also picks up items just created
        // on this run, guarding idempotency within a single execution too).
        $existingChildLabels = array();
        $maxOrdering = 0;
        $refresh = function () use ($nav, $officialResourcesNavId, &$existingChildLabels, &$maxOrdering) {
            $existingChildLabels = array();
            $maxOrdering = 0;
            $all = $nav->ListItems('marketing', 'global', 0);
            foreach ($all as $row) {
                if ((int) (isset($row['parent_id']) ? $row['parent_id'] : 0) === $officialResourcesNavId) {
                    $existingChildLabels[strtolower(trim((string) $row['label']))] = true;
                    if ((int) $row['ordering'] > $maxOrdering) {
                        $maxOrdering = (int) $row['ordering'];
                    }
                }
            }
        };
        $refresh();

        $skipLabels = array('official documents', 'documents');

        foreach ($cards as $card) {
            $title = trim((string) (isset($card['title']) ? $card['title'] : ''));
            $href = trim((string) (isset($card['href']) ? $card['href'] : ''));

            if ($title === '') {
                continue;
            }
            if (in_array(strtolower($title), $skipLabels, true)) {
                $result['children_skipped'][] = "$title (reserved/existing Documents child)";
                continue;
            }
            if ($href === '' || !preg_match('#^https?://#i', $href)) {
                $result['children_skipped'][] = "$title (no absolute http(s) href: '$href')";
                continue;
            }
            if (isset($existingChildLabels[strtolower($title)])) {
                $result['children_skipped'][] = "$title (already a child)";
                continue;
            }

            $maxOrdering += 10;
            $newId = $nav->CreateItem(array(
                'menu'       => 'marketing',
                'label'      => $title,
                'link_type'  => 'url',
                'url'        => $href,
                'parent_id'  => $officialResourcesNavId,
                'ordering'   => $maxOrdering,
                'enabled'    => 1,
                'scope_type' => 'global',
                'scope_id'   => 0,
            ));

            if ($newId > 0) {
                $result['children_added'][] = "$title -> $href (nav_id $newId)";
                $existingChildLabels[strtolower($title)] = true;
            } else {
                $result['children_skipped'][] = "$title (CreateItem failed)";
            }
        }
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
