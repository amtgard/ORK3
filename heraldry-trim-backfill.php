<?php
/**
 * One-shot backfill: trim fully-transparent edges from existing heraldry PNGs.
 *
 * Uses Common::gd_trim_transparent (pixel-exact crop, no resampling, aspect
 * ratio preserved). Only touches .png files — .jpg has no alpha to trim.
 *
 * Usage (from host):
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/heraldry-trim-backfill.php --dry-run
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/heraldry-trim-backfill.php
 *
 * Flags:
 *   --dry-run           Report what would change without writing files.
 *   --only=kingdom,park Limit to specific heraldry types (default: all).
 *   --verbose           Log every file, not just ones that were trimmed.
 */

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "This script must be run from the command line.\n");
	exit(1);
}

// Bootstrap config (defines the DIR_*_HERALDRY constants).
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
	if ($arg === '--dry-run')           $dry_run = true;
	elseif ($arg === '--verbose')       $verbose = true;
	elseif (strpos($arg, '--only=') === 0) {
		$only = array_filter(array_map('trim', explode(',', substr($arg, 7))));
	} else {
		fwrite(STDERR, "Unknown argument: $arg\n");
		exit(1);
	}
}

$all_dirs = array(
	'player'  => DIR_PLAYER_HERALDRY,
	'park'    => DIR_PARK_HERALDRY,
	'kingdom' => DIR_KINGDOM_HERALDRY,
	'unit'    => DIR_UNIT_HERALDRY,
	'event'   => DIR_EVENT_HERALDRY,
);

$targets = $only ? array_intersect_key($all_dirs, array_flip($only)) : $all_dirs;
if (empty($targets)) {
	fwrite(STDERR, "No valid heraldry types to process.\n");
	exit(1);
}

$totals = array('scanned' => 0, 'trimmed' => 0, 'skipped' => 0, 'failed' => 0, 'bytes_saved' => 0);

echo ($dry_run ? "[DRY RUN] " : "") . "Heraldry trim backfill starting.\n";
echo "Targets: " . implode(', ', array_keys($targets)) . "\n\n";

foreach ($targets as $label => $dir) {
	if (!is_dir($dir)) {
		echo "[$label] directory does not exist: $dir — skipping.\n\n";
		continue;
	}
	echo "[$label] scanning $dir\n";
	$files = glob($dir . '*.png') ?: array();
	foreach ($files as $path) {
		$totals['scanned']++;
		$before_size = filesize($path);

		$src = @imagecreatefrompng($path);
		if ($src === false) {
			$totals['failed']++;
			echo "  FAIL  " . basename($path) . " (could not decode)\n";
			continue;
		}
		$w0 = imagesx($src);
		$h0 = imagesy($src);

		// gd_trim_transparent returns the same resource if nothing to trim.
		$cropped = Common::gd_trim_transparent($src);
		$w1 = imagesx($cropped);
		$h1 = imagesy($cropped);

		if ($cropped === $src || ($w0 === $w1 && $h0 === $h1)) {
			$totals['skipped']++;
			if ($cropped !== $src) imagedestroy($cropped);
			imagedestroy($src);
			if ($verbose) echo "  skip  " . basename($path) . " ({$w0}x{$h0} — already tight)\n";
			continue;
		}

		if (!$dry_run) {
			// Preserve alpha on write.
			imagealphablending($cropped, false);
			imagesavealpha($cropped, true);
			$ok = @imagepng($cropped, $path);
			if (!$ok) {
				$totals['failed']++;
				echo "  FAIL  " . basename($path) . " (write failed)\n";
				imagedestroy($cropped);
				imagedestroy($src);
				continue;
			}
			clearstatcache(true, $path);
			$after_size = filesize($path);
		} else {
			$after_size = $before_size; // unchanged on disk
		}

		$totals['trimmed']++;
		$totals['bytes_saved'] += max(0, $before_size - $after_size);
		echo sprintf(
			"  trim  %s  %dx%d → %dx%d  (%s)\n",
			basename($path),
			$w0, $h0, $w1, $h1,
			$dry_run
				? 'dry-run'
				: sprintf('%d → %d bytes', $before_size, $after_size)
		);

		if ($cropped !== $src) imagedestroy($cropped);
		imagedestroy($src);
	}
	echo "\n";
}

echo "----------------------------------------\n";
echo ($dry_run ? "[DRY RUN] " : "") . "Summary:\n";
echo "  Scanned:     {$totals['scanned']}\n";
echo "  Trimmed:     {$totals['trimmed']}\n";
echo "  Already tight: {$totals['skipped']}\n";
echo "  Failed:      {$totals['failed']}\n";
if (!$dry_run) {
	echo "  Bytes saved: " . number_format($totals['bytes_saved']) . "\n";
}
echo "\nDone.\n";
