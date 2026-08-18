<?php

/**
 * One-shot backfill: generate thumb/display renditions for existing masters.
 *
 * Wraps Common::generate_renditions (thumb 256px, display 1024px; WebP when
 * available, JPEG otherwise) over every stored master image. Covers both .png
 * and .jpg masters across all heraldry types plus player avatars.
 *
 * NO quality recovery is attempted. Pre-pipeline uploads were already shrunk
 * at upload time and their high-res originals are gone; this script only
 * derives renditions from whatever master is on disk — it never upscales or
 * "restores" quality. Old images stay soft until re-uploaded.
 *
 * Usage (from host):
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/heraldry-rendition-backfill.php --dry-run
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/heraldry-rendition-backfill.php
 *
 * Flags:
 *   --dry-run                 Report what would change without writing files.
 *   --only=kingdom,player     Limit to specific targets (default: all).
 *   --verbose                 Log every file, not just ones that were generated.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// Bootstrap config (defines the DIR_* asset constants).
if (getenv('ENVIRONMENT') == 'DEV' || file_exists(__DIR__ . '/config.dev.php')) {
    require_once __DIR__ . '/config.dev.php';
} else {
    require_once __DIR__ . '/config.php';
}
require_once DIR_ORK3 . 'common.php';

// --- Parse args ---------------------------------------------------------
$dry_run = false;
$verbose = false;
$only    = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dry_run = true;
    } elseif ($arg === '--verbose') {
        $verbose = true;
    } elseif (strpos($arg, '--only=') === 0) {
        $only = array_filter(array_map('trim', explode(',', substr($arg, 7))));
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(1);
    }
}

$all_dirs = array(
    'player'       => DIR_PLAYER_HERALDRY,
    'park'         => DIR_PARK_HERALDRY,
    'kingdom'      => DIR_KINGDOM_HERALDRY,
    'unit'         => DIR_UNIT_HERALDRY,
    'event'        => DIR_EVENT_HERALDRY,
    'player_image' => DIR_PLAYER_IMAGE,
);

$targets = $only ? array_intersect_key($all_dirs, array_flip($only)) : $all_dirs;
if (empty($targets)) {
    fwrite(STDERR, "No valid targets to process.\n");
    exit(1);
}

$totals = array('scanned' => 0, 'generated' => 0, 'skipped' => 0, 'failed' => 0);

echo ($dry_run ? "[DRY RUN] " : "") . "Heraldry rendition backfill starting.\n";
echo "Targets: " . implode(', ', array_keys($targets)) . "\n\n";

foreach ($targets as $label => $dir) {
    if (!is_dir($dir)) {
        echo "[$label] directory does not exist: $dir — skipping.\n\n";
        continue;
    }
    echo "[$label] scanning $dir\n";
    $files = array_merge(glob($dir . '*.png') ?: array(), glob($dir . '*.jpg') ?: array());
    sort($files);
    foreach ($files as $path) {
        $base = basename($path);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $name = pathinfo($path, PATHINFO_FILENAME);

        // Never treat a rendition as a master.
        if (strpos($name, '_thumb') !== false || strpos($name, '_display') !== false) {
            continue;
        }
        // Skip the all-zeros default placeholder — renditions of it are pointless.
        if (preg_match('/^0+$/', $name)) {
            if ($verbose) {
                echo "  skip  $base (default placeholder)\n";
            }
            continue;
        }

        $totals['scanned']++;
        $base_no_ext = substr($path, 0, -(strlen($ext) + 1));

        // Idempotent: skip when BOTH renditions already exist and are newer
        // than the master. A rendition may be .webp (preferred) or .jpg.
        if (rendition_up_to_date($base_no_ext, 'thumb', $path) &&
            rendition_up_to_date($base_no_ext, 'display', $path)) {
            $totals['skipped']++;
            if ($verbose) {
                echo "  skip  $base (up to date)\n";
            }
            continue;
        }

        $src = ($ext === 'png') ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if ($src === false) {
            $totals['failed']++;
            echo "  FAIL  $base (could not decode)\n";
            continue;
        }

        if ($dry_run) {
            $totals['generated']++;
            echo "  gen   $base → {$name}_thumb / {$name}_display  (dry-run)\n";
            imagedestroy($src);
            continue;
        }

        $preserve_alpha = ($ext === 'png');
        $written = Common::generate_renditions($src, $base_no_ext, $preserve_alpha);
        imagedestroy($src);

        if (empty($written['thumb']) || empty($written['display'])) {
            $totals['failed']++;
            echo "  FAIL  $base (rendition write failed)\n";
            continue;
        }

        $totals['generated']++;
        echo sprintf(
            "  gen   %s → %s_thumb.%s, %s_display.%s\n",
            $base,
            $name,
            $written['thumb'],
            $name,
            $written['display']
        );
    }
    echo "\n";
}

echo "----------------------------------------\n";
echo ($dry_run ? "[DRY RUN] " : "") . "Summary:\n";
echo "  Scanned:     {$totals['scanned']}\n";
echo "  Generated:   {$totals['generated']}\n";
echo "  Up to date:  {$totals['skipped']}\n";
echo "  Failed:      {$totals['failed']}\n";
echo "\nDone.\n";

/**
 * A rendition tier is up to date when its file (WebP preferred, JPEG fallback)
 * exists and is at least as new as the master. Missing either variant → stale.
 */
function rendition_up_to_date($base_no_ext, $tier, $master_path)
{
    $master_mtime = filemtime($master_path);
    foreach (array('webp', 'jpg') as $rext) {
        $rpath = $base_no_ext . '_' . $tier . '.' . $rext;
        if (file_exists($rpath) && filemtime($rpath) >= $master_mtime) {
            return true;
        }
    }
    return false;
}
