<?php

/**
 * Export the amtgard.com replication pages from a seeded database back into
 * the spec JSON that 2026-07-08-cms-seed-amtgard.php consumes.
 *
 * WHY THIS EXISTS: the specs were originally produced by scraping www.amtgard.com
 * into a staging directory that was never committed (db-migrations/.amtgard-assets/
 * is gitignored). Once that staging directory is gone, the only surviving copy of
 * the content is the seeded database — which makes the replication unreproducible
 * from a clean checkout. This turns a seeded DB back into committable specs, so
 * the round trip is closed: seed -> DB -> specs -> seed.
 *
 * It is the exact inverse of the seed's spec->block conversion:
 *   - a resolved media ref (an array carrying media_id/src/thumb) becomes the bare
 *     source filename again, and is recorded in the block's assets.images[];
 *   - a self-hosted /assets/cms-docs/<name> path becomes the bare doc filename and
 *     is recorded in assets.docs[];
 *   - a resolved UIR href becomes UIRPLACEHOLDER again.
 * Blocks with none of those are copied through byte-identically.
 *
 * ASSET BYTES ARE NOT EXPORTED — only their filenames. The seed logs
 * "missing image <slug>/<file>" and continues, so specs-only seeding reproduces
 * every page's structure and copy without its imagery.
 *
 * Run:
 *   docker exec ork3-php8-app php \
 *     /var/www/ork.amtgard.com/db-migrations/export-amtgard-specs.php \
 *     /var/www/ork.amtgard.com/db-migrations/amtgard-specs
 */

$OUT = isset($argv[1]) ? rtrim($argv[1], '/') : (__DIR__ . '/amtgard-specs');
if ($OUT !== '' && $OUT[0] !== '/') {
    $OUT = rtrim(getcwd(), '/') . '/' . $OUT;
}

require_once __DIR__ . '/_cms_cli_bootstrap.php';

global $DB;

// The replication set + hierarchy, mirroring the seed's own $order/parents.
$ORDER = array(
    'about', 'mission', 'staff', 'volunteers', 'join', 'learn-the-basics',
    'start-a-chapter', 'programs', 'foodfight', 'olympiad', 'media',
    'galleries', 'writing', 'resources', 'documents',
);

@mkdir("$OUT/specs", 0775, true);

// media_id => source filename, derived from the deterministic 'amtg-<slug>-<base>.<ext>'
// name the seed uploads under. Stripping the prefix yields the staging filename.
$srcName = array();
$DB->Clear();
$r = $DB->DataSet('SELECT media_id, filename, alt FROM ' . DB_PREFIX . 'cms_media WHERE filename LIKE \'amtg-%\'');
while ($r && $r->Next()) {
    $srcName[(int)$r->media_id] = array('file' => (string)$r->filename, 'alt' => (string)$r->alt);
}

$uir = defined('UIR') ? UIR : '/orkui/index.php?Route=';

/** Bare source filename for a media row, e.g. amtg-about-1.jpg (slug=about) -> 1.jpg */
$bare = function ($filename, $slug) {
    $p = 'amtg-' . $slug . '-';
    return (strpos($filename, $p) === 0) ? substr($filename, strlen($p)) : $filename;
};

$report = array();
foreach ($ORDER as $slug) {
    $DB->Clear();
    $DB->slug = $slug;
    $pr = $DB->DataSet(
        'SELECT page_id, title, meta_description, parent_id, type FROM ' . DB_PREFIX . 'cms_page'
        . ' WHERE scope_type=\'global\' AND scope_id=0 AND slug=:slug AND deleted_at IS NULL LIMIT 1'
    );
    if (!$pr || !$pr->Next()) {
        $report[] = "$slug: NOT IN DB";
        continue;
    }
    $pageId = (int)$pr->page_id;
    $parentSlug = null;
    if (!empty($pr->parent_id)) {
        $DB->Clear();
        $DB->pid = (int)$pr->parent_id;
        $par = $DB->DataSet('SELECT slug FROM ' . DB_PREFIX . 'cms_page WHERE page_id=:pid LIMIT 1');
        if ($par && $par->Next()) {
            $parentSlug = (string)$par->slug;
        }
    }

    $spec = array(
        'slug'             => $slug,
        'type'             => (string)$pr->type,
        'parent_slug'      => $parentSlug,
        'title'            => (string)$pr->title,
        'meta_description' => (string)$pr->meta_description,
        'blocks'           => array(),
    );

    $DB->Clear();
    $DB->oid = $pageId;
    $br = $DB->DataSet(
        'SELECT type, fields_json FROM ' . DB_PREFIX . 'cms_block'
        . ' WHERE owner_type=\'page\' AND owner_id=:oid ORDER BY ordering ASC'
    );
    while ($br && $br->Next()) {
        $type = (string)$br->type;
        $f = json_decode((string)$br->fields_json, true);
        if (!is_array($f)) {
            $f = array();
        }
        $images = array();
        $docs   = array();

        // Walk the field tree, turning resolved refs back into source filenames.
        $walk = function (&$node) use (&$walk, &$images, &$docs, $srcName, $bare, $slug, $uir) {
            if (is_array($node)) {
                // A resolved media ref: an array carrying media_id (+ src/thumb).
                if (isset($node['media_id']) && isset($srcName[(int)$node['media_id']])) {
                    $m    = $srcName[(int)$node['media_id']];
                    $file = $bare($m['file'], $slug);
                    $images[$file] = array('file' => $file, 'alt' => $m['alt']);
                    $node = $file;
                    return;
                }
                foreach ($node as &$v) {
                    $walk($v);
                }
                unset($v);
                return;
            }
            if (!is_string($node) || $node === '') {
                return;
            }
            // Self-hosted document -> bare filename + assets.docs entry.
            if (preg_match('#/assets/cms-docs/([^"\'\s]+)#', $node, $m)) {
                $docs[$m[1]] = array('file' => $m[1]);
                $node = str_replace($m[0], $m[1], $node);
            }
            // Resolved internal route -> the placeholder the seed re-resolves.
            if ($uir !== '' && strpos($node, $uir) !== false) {
                $node = str_replace($uir, 'UIRPLACEHOLDER/', $node);
            }
        };
        $walk($f);

        $block = array('type' => $type, 'fields' => $f);
        if ($images || $docs) {
            $block['assets'] = array();
            if ($images) {
                $block['assets']['images'] = array_values($images);
            }
            if ($docs) {
                $block['assets']['docs'] = array_values($docs);
            }
        }
        $spec['blocks'][] = $block;
    }

    $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents("$OUT/specs/$slug.json", $json . "\n");
    $nImg = 0;
    $nDoc = 0;
    foreach ($spec['blocks'] as $b) {
        $nImg += count($b['assets']['images'] ?? array());
        $nDoc += count($b['assets']['docs'] ?? array());
    }
    $report[] = sprintf('%s: %d blocks, %d image(s), %d doc(s)', $slug, count($spec['blocks']), $nImg, $nDoc);
}

echo implode("\n", $report) . "\n";
