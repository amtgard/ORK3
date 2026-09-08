<?php

declare(strict_types=1);

/**
 * Build assets/scrolls/ink-extents.json — what the Scrollery artwork actually PAINTS.
 *
 * Run:
 *   php bin/build-scroll-ink-extents.php               # rebuild the whole manifest
 *   php bin/build-scroll-ink-extents.php --only=lion   # substring filter on the path;
 *                                                      # does NOT write the manifest
 *   php bin/build-scroll-ink-extents.php --verbose
 *
 * Requires ImageMagick (`magick`, or `convert` on IM6) and PHP's GD extension on the
 * machine running it — the same "build machine, not the runtime image" split that
 * bin/build-scroll-renditions.sh documents: ork3-php8-app carries no ImageMagick, and
 * this output is committed rather than generated at deploy because the art is frozen
 * (it is Valory Fox's, licensed — see NOTICE).
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS EXISTS: A LAYOUT BOX IS NOT INK
 *
 * Every collision this manifest is meant to prevent has the same shape. An offset is
 * measured against an element's BOX and then applied to its INK, and the two are not
 * the same rectangle. `getBoundingClientRect()` on `#BottomTextFlourish` reports the
 * full 424x161 viewBox of squiggly-bottom.svg. The swash inside it is a hairline that
 * stops at 0.892 of that width and never reaches the last 11% at all. A bounding-box
 * collision test therefore reported the ornament as CLEAR of the issuing line while
 * the two were visibly crossing, and reported it as COLLIDING where nothing was
 * painted. Both directions of that error have already shipped a wrong constant here.
 *
 * So: rasterise the art, scan the alpha, and write down where the ink is.
 *
 * WHY FRACTIONS AND NOT PIXELS
 *
 * The same ornament is drawn at a different size on every certificate. `#RightTextFlourish`
 * is `width: 4.5em` against a font-size the autofit ladder chooses per award, so its
 * painted box is a different number of device pixels on every print, and a stored pixel
 * offset would be right for exactly one of them. A fraction of the element's own box
 * survives the scale, which is precisely the property the consumer needs: multiply the
 * fraction by whatever `getBoundingClientRect()` just returned and you have the ink box
 * in the coordinate system the layout is actually working in.
 *
 * WHAT THIS RETIRES
 *
 * These numbers are already in the tree — as PROSE, in a CSS comment, measured by hand
 * once and never re-measurable. orkui/template/revised-frontend/style/scrollery.css, on
 * `section.IssuingTextSection > p:nth-child(2)`, says:
 *
 *     squiggly-bottom  ink stops at 0.890 of its box   (box 424x161)
 *     squiggly-right   ink runs the full width through this line's own y band
 *
 * and then encodes the first of those as `margin-left: 4.4em`. A hand-measured constant
 * is right for the one data point somebody measured and silently wrong for the next
 * plate, the next hand, or the next redraw. `--sc-swash-hang: 0.9em` says so in its own
 * comment: one value for seventeen frames because five of them were only ever read off
 * a crop rather than off alpha. This script is what makes those measurements data, so
 * the next plate is covered without another patch.
 *
 * This run measures squiggly-bottom's ink at x1 = 0.8924, against the hand-measured
 * 0.890 in that comment. The two agree to a quarter of one percent of the box — about
 * 3px of the 1348px raster — which is what a careful hand measurement and an alpha scan
 * should differ by, and it is the check that says this pipeline is measuring the same
 * thing the CSS comment was talking about.
 *
 * ---------------------------------------------------------------------------------
 * THE INK FORMULA IS SHARED WITH THE SWEEP HARNESS. DO NOT CHANGE ONE ALONE.
 *
 * WHY THIS IS COVERAGE AND NOT DARKNESS.
 *
 * The first version of this scan used `(1 - L) * a`, the darkness metric — and it read
 * 42 of the 253 masters as completely blank, including all eleven `owl/rank_*.png`.
 * Those are not blank. They are WHITE numerals, drawn over owl's dark blueprint ground,
 * and a darkness metric scores white art at exactly zero. A manifest whose whole
 * purpose is to record where the ink is, asserting that real ink is not there, is worse
 * than no manifest: the next person reads it and believes it.
 *
 * An asset here is rasterised ALONE on transparency, so the honest question is "where
 * does this put marks", not "where is it dark". `a * max(L, 1 - L)` answers that: it is
 * the alpha coverage weighted by how far the mark is from mid-grey in EITHER direction,
 * so black knotwork and a white numeral both count and a faint antialiasing fringe does
 * not.
 *
 * NOTE THE DIFFERENCE FROM `tools/scroll-sweep/lib/mask.ts`, and do not "reconcile"
 * them. That file measures a layer IN SITU, against the certificate's own ground, so it
 * differences against the ground (`diffMask`) and needs no opinion about colour. This
 * file measures an asset IN ISOLATION, where there is no ground to difference against.
 * Same problem, two settings, two right answers. The floor is shared at 0.06.
 *
 *     alpha      a = (127 - ((rgb >> 24) & 0x7F)) / 127        [0 = clear, 1 = opaque]
 *     luminance  L = (0.2126 R + 0.7152 G + 0.0722 B) / 255    [Rec.709]
 *     ink        i = (1 - L) * a                               [0 = nothing, 1 = solid black]
 *     ink pixel  i > 0.06
 *
 * Rec.709 rather than a flat average because the art is drawn on parchment and the
 * failure being detected is PERCEPTUAL overlap: a green at (0,180,0) and a blue at
 * (0,0,180) are the same distance from white by a flat average and nothing like the same
 * distance by eye. 0.06 is the floor that keeps a hairline swash and drops the ~2/255 of
 * ringing an antialiased edge leaves behind. Both files carry the same two numbers; a
 * change to either belongs in both, in the same commit.
 *
 * ---------------------------------------------------------------------------------
 * WHY 512 ROWS
 *
 * RASTER = 512 is the height every asset is rasterised to, and therefore the number of
 * per-row extents emitted. It is the smallest number that still resolves the thing being
 * measured. A certificate is a 2550x3300 frame and `#BottomTextFlourish` occupies roughly
 * 3.3em of it; the swash's own stroke is under 1% of its box's height, which is 5 rows at
 * 512 and would be under 1 at 64. Upward, the cost is linear and the manifest is a
 * committed file: at 512 the whole 253-file corpus scans in a couple of seconds and the
 * JSON is ~2 MB, and every doubling from here buys resolution the consumer cannot use —
 * the ink box is applied to a `getBoundingClientRect()` that is itself only accurate to a
 * device pixel.
 *
 * `magick -background none <src> -resize x512 png32:<tmp>` — and `-background none` is
 * NOT optional. Without it ImageMagick flattens an SVG onto ITS DEFAULT WHITE BACKGROUND,
 * every pixel comes back fully opaque, and the alpha term above becomes the constant 1.
 * The scan then reports a white page as clear (L = 1, ink = 0) and any parchment-toned
 * ground as SOLID INK EDGE TO EDGE, which is a manifest that says every ornament collides
 * with everything. png32: forces the RGBA channel layout GD then reads back; without it a
 * fully-opaque source can be written as a 24-bit PNG and `(rgb >> 24) & 0x7F` reads 0 for
 * a reason that has nothing to do with the art.
 *
 * ---------------------------------------------------------------------------------
 * WHAT IS SCANNED, AND WHAT IS NOT
 *
 * `.svg`, `.png`, `.jpg` and `.jpeg` under assets/scrolls/ — the MASTERS. `.webp` is
 * skipped in both its forms: `<name>.webp` and `<name>_display.webp` are what
 * bin/build-scroll-renditions.sh emits BESIDE each master, so scanning them would add a
 * second and a third entry for art that already has one, keyed on a filename the layout
 * never names. The display rendition would also be measuring a third-scale re-encode.
 * The masters are the art; the renditions are a delivery detail.
 *
 * `box` is the MASTER's real pixel dimensions, not the raster's. For a raster that is
 * `getimagesize()`. For an SVG it is the `width`/`height` attributes when they are
 * absolute lengths, and the `viewBox` when they are not — and in this corpus they never
 * are: all nine SVGs are Affinity/Serif exports carrying `width="100%" height="100%"`,
 * so the viewBox is the only statement of intrinsic size any of them makes. That is what
 * makes squiggly-bottom 424x161 rather than the 1348x512 it was scanned at.
 *
 * A file with NO ink above the floor is recorded in `blank` and left out of `files`
 * rather than given a fake `{0,0,1,1}` box. An entry that claims the ink fills the box is
 * indistinguishable from a real measurement, and the consumer would quietly reserve the
 * whole element — the box-not-ink error again, reintroduced by the file that exists to
 * prevent it. Absent means "no measurement", the consumer falls back to the box on its
 * own, and the fallback is at least visible in the manifest.
 *
 * ---------------------------------------------------------------------------------
 * MTIME IS LOAD-BEARING
 *
 * The file is `touch`ed last. `Scroll::artGeneration()` already treats
 * assets/scrolls/renditions.json's mtime as the stamp for "the art moved", and
 * tests/Unit/ScrollInkExtentsTest asserts this manifest is not OLDER than it. A stale
 * ink manifest is worse than a missing one: it asserts a number for art that has since
 * been redrawn (owl/rank_9.png has already been redrawn once), and every consumer
 * downstream trusts it silently. Re-run this after any run of
 * bin/build-scroll-renditions.sh.
 */

// ---------------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------------

/** The height every asset is rasterised to, and the number of row extents emitted. */
const RASTER = 512;

/**
 * Minimum ink COVERAGE for a pixel to count.
 *
 * The FLOOR is shared with tools/scroll-sweep/lib/mask.ts; the FORMULA deliberately is
 * not. See the header.
 */
const INK_FLOOR = 0.06;

/** Decimal places kept in the emitted fractions. 1/512 is 0.00195, so 4 is exact. */
const PRECISION = 4;

/** @var list<string> Master extensions. `.webp` is deliberately absent — renditions. */
const MASTER_EXTENSIONS = ['svg', 'png', 'jpg', 'jpeg'];

// ---------------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------------

$root = dirname(__DIR__);
$artDir = $root . '/assets/scrolls';
$manifestPath = $artDir . '/ink-extents.json';

$only = '';
$verbose = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
    } elseif (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, usage());
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n\n" . usage());
        exit(2);
    }
}

if (!extension_loaded('gd') || !function_exists('imagecreatefrompng')) {
    fwrite(STDERR, "PHP GD is required (imagecreatefrompng). Enable ext-gd on this machine.\n");
    exit(1);
}

$magick = locateMagick();
if ($magick === null) {
    fwrite(STDERR, <<<'TXT'
    ImageMagick not found. This script needs `magick` (IM7) or `convert` (IM6).

      macOS:  brew install imagemagick librsvg
      Debian: apt-get install imagemagick librsvg2-bin

    librsvg matters: ImageMagick's built-in MSVG renderer does not handle the transform
    stacks these Affinity exports use, and a silently mis-rendered SVG produces a
    plausible-looking wrong ink box rather than an error.

    TXT);
    exit(1);
}

if (!is_dir($artDir)) {
    fwrite(STDERR, "No art directory at {$artDir}\n");
    exit(1);
}

$masters = collectMasters($artDir, $only);
if ($masters === []) {
    fwrite(STDERR, "No master art found under assets/scrolls/ (filter: '" . ($only ?: 'none') . "').\n");
    exit(1);
}

$tmpDir = sys_get_temp_dir() . '/scroll-ink-' . getmypid();
@mkdir($tmpDir, 0700, true);
register_shutdown_function(static function () use ($tmpDir): void {
    foreach (glob($tmpDir . '/*') ?: [] as $leftover) {
        @unlink($leftover);
    }
    @rmdir($tmpDir);
});
$tmpPng = $tmpDir . '/raster.png';

$started = microtime(true);
$files = [];
$blank = [];
$failed = [];
$scanned = 0;

foreach ($masters as $relative) {
    $source = $artDir . '/' . $relative;

    $box = masterBox($source);
    if ($box === null) {
        $failed[] = $relative . ' (could not determine its pixel box)';
        continue;
    }

    // -background none is REQUIRED. See the header: without it an SVG rasterises onto
    // white, every pixel reads opaque, and the alpha term collapses to a constant 1.
    @unlink($tmpPng);
    $cmd = escapeshellarg($magick)
        . ' -background none ' . escapeshellarg($source)
        . ' -resize x' . RASTER
        . ' ' . escapeshellarg('png32:' . $tmpPng)
        . ' 2>&1';
    exec($cmd, $output, $status);
    if ($status !== 0 || !is_file($tmpPng)) {
        $failed[] = $relative . ' (rasterise failed: ' . trim(implode(' ', $output)) . ')';
        $output = [];
        continue;
    }
    $output = [];

    $extent = scanInk($tmpPng);
    if ($extent === null) {
        $failed[] = $relative . ' (raster unreadable by GD)';
        continue;
    }

    $scanned++;

    if ($extent['ink'] === null) {
        // No pixel cleared INK_FLOOR. Recorded, not invented — see the header.
        $blank[] = $relative;
        if ($verbose) {
            printf("  %-52s %5dx%-5d  no ink above %.2f\n", $relative, $box[0], $box[1], INK_FLOOR);
        }
        continue;
    }

    /*
     * The bounding box only — NOT the per-row extents the scan also computes.
     *
     * Emitting `rows` made this manifest 1.57 MB against renditions.json's 62 KB, and
     * nothing reads it: the keep-out pass in scrollery-autofit.js insets a text block
     * off an ornament's ink BOX. A shaped keep-out that followed a swash's actual
     * profile would want the rows, and rowExtents() below still computes them ready
     * for it — but shipping a megabyte of data on the chance that someday something
     * reads it is how a manifest becomes a thing nobody trusts or regenerates.
     *
     * If you need them: put `'rows' => $extent['rows']` back here and in writeManifest(),
     * and say in the commit which surface consumes them.
     */
    $files[$relative] = [
        'box' => $box,
        'ink' => $extent['ink'],
    ];

    if ($verbose) {
        printf(
            "  %-52s %5dx%-5d  ink x %.3f-%.3f  y %.3f-%.3f\n",
            $relative,
            $box[0],
            $box[1],
            $extent['ink']['x0'],
            $extent['ink']['x1'],
            $extent['ink']['y0'],
            $extent['ink']['y1']
        );
    }
}

$elapsed = microtime(true) - $started;

if ($failed !== []) {
    fwrite(STDERR, "\nFAILED on " . count($failed) . " file(s):\n");
    foreach ($failed as $line) {
        fwrite(STDERR, '  ' . $line . "\n");
    }
    fwrite(STDERR, "\nThe manifest was NOT written: a partial one is a manifest that lies by omission,\n");
    fwrite(STDERR, "and a consumer cannot tell a file with no measurement from a file with no ink.\n");
    exit(1);
}

// --only is a BUILD filter, never a manifest filter — the same trap
// bin/build-scroll-renditions.sh documents. A filtered run knows about the files it
// scanned and nothing else, so writing the manifest here would drop the other 250
// entries and nothing would look broken: the consumer falls back to the box for a file
// it cannot find, so the measurements would simply stop being used.
if ($only !== '') {
    printf(
        "\nScanned %d file(s) matching '%s' in %.1fs.\n\n"
        . "  %s was NOT written: a filtered run only knows about the files it scanned.\n"
        . "  Re-run without --only to refresh it:  php bin/build-scroll-ink-extents.php\n",
        $scanned,
        $only,
        $elapsed,
        relativeTo($root, $manifestPath)
    );
    exit(0);
}

ksort($files);
sort($blank);

writeManifest($manifestPath, $files, $blank);

// The mtime is the contract with ScrollInkExtentsTest and with Scroll::artGeneration():
// this manifest must never be older than the art it describes. file_put_contents has
// already set it, and touch() states the intent so nobody "optimises" a skip-if-identical
// write in here later without noticing what it would break.
touch($manifestPath);

printf(
    "\nScroll ink extents built.\n\n"
    . "  scanned                 %d files   in %.1fs\n"
    . "  with ink                %d\n"
    . "  blank (no ink >%.2f)    %d%s\n"
    . "  raster                  x%d\n"
    . "  manifest                %s   %s bytes\n",
    $scanned,
    $elapsed,
    count($files),
    INK_FLOOR,
    count($blank),
    $blank === [] ? '' : '   (' . implode(', ', $blank) . ')',
    RASTER,
    relativeTo($root, $manifestPath),
    number_format((float) filesize($manifestPath), 0, '.', '')
);

exit(0);

// ---------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------

function usage(): string
{
    return <<<'TXT'
    Build assets/scrolls/ink-extents.json — where the Scrollery art actually paints.

      php bin/build-scroll-ink-extents.php              rebuild the whole manifest
      php bin/build-scroll-ink-extents.php --only=lion  scan a subset (writes nothing)
      php bin/build-scroll-ink-extents.php --verbose    one line per file

    Requires ImageMagick and PHP's GD extension. Re-run after
    bin/build-scroll-renditions.sh — the manifest must not be older than the art.

    TXT;
}

function locateMagick(): ?string
{
    foreach (['magick', 'convert'] as $binary) {
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
        if ($found !== '') {
            return $found;
        }
    }

    return null;
}

/**
 * Every master under assets/scrolls/, as paths relative to it, sorted.
 *
 * @return list<string>
 */
function collectMasters(string $artDir, string $only): array
{
    $out = [];
    $walker = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($artDir, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($walker as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $ext = strtolower($file->getExtension());

        // Explicit, though MASTER_EXTENSIONS already excludes it: `<name>.webp` and
        // `<name>_display.webp` are renditions bin/build-scroll-renditions.sh emits
        // beside each master. Scanning them would key extra entries on filenames the
        // layout never names, and the display one is a third-scale re-encode besides.
        if ($ext === 'webp') {
            continue;
        }

        if (!in_array($ext, MASTER_EXTENSIONS, true)) {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($artDir) + 1);
        if ($only !== '' && !str_contains($relative, $only)) {
            continue;
        }

        $out[] = $relative;
    }

    sort($out, SORT_STRING);

    return $out;
}

/**
 * The master's own pixel box — NOT the raster's.
 *
 * Rasters answer through getimagesize(). SVGs answer through their width/height when
 * those are absolute lengths and through their viewBox when they are not, which in this
 * corpus is always: all nine are `width="100%" height="100%"`, so the viewBox is the only
 * intrinsic size they state.
 *
 * @return array{0:int,1:int}|null
 */
function masterBox(string $source): ?array
{
    if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'svg') {
        return svgBox($source);
    }

    $size = @getimagesize($source);
    if (!is_array($size) || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
        return null;
    }

    return [(int) $size[0], (int) $size[1]];
}

/**
 * @return array{0:int,1:int}|null
 */
function svgBox(string $source): ?array
{
    // The opening <svg> tag is within the first few hundred bytes of every file here;
    // 8 KB is generous headroom and avoids pulling a 2550x3300 path soup into memory.
    $head = (string) @file_get_contents($source, false, null, 0, 8192);
    if (!preg_match('/<svg\b[^>]*>/is', $head, $tag)) {
        return null;
    }
    $tag = $tag[0];

    $width = svgLength($tag, 'width');
    $height = svgLength($tag, 'height');
    if ($width !== null && $height !== null) {
        return [$width, $height];
    }

    if (preg_match('/\bviewBox\s*=\s*"([^"]*)"/i', $tag, $vb)) {
        $parts = preg_split('/[\s,]+/', trim($vb[1])) ?: [];
        if (count($parts) === 4) {
            $w = (int) round((float) $parts[2]);
            $h = (int) round((float) $parts[3]);
            if ($w > 0 && $h > 0) {
                return [$w, $h];
            }
        }
    }

    return null;
}

/**
 * An SVG width/height attribute, but only when it is an ABSOLUTE length.
 *
 * `100%` is not a size, it is a deferral to whatever box the ornament is placed in —
 * returning 100 for it would key the whole manifest to a fiction. Unitless and `px` are
 * the same number; anything else (em, %, pt...) falls through to the viewBox.
 */
function svgLength(string $tag, string $attribute): ?int
{
    if (!preg_match('/\b' . $attribute . '\s*=\s*"([^"]*)"/i', $tag, $m)) {
        return null;
    }

    $value = trim($m[1]);
    if (!preg_match('/^([0-9]*\.?[0-9]+)(px)?$/i', $value, $n)) {
        return null;
    }

    $px = (int) round((float) $n[1]);

    return $px > 0 ? $px : null;
}

/**
 * Scan a rasterised PNG for ink, returning the fractional bbox and the per-row extents.
 *
 * INK_FLOOR is shared with tools/scroll-sweep/lib/mask.ts; the formula is not. See the
 * file header before touching either.
 *
 * Fractions use the pixel's OUTER edges: ink covering pixel columns [x0..x1] of w spans
 * x0/w to (x1+1)/w, because a pixel is a cell and not a point. At RASTER = 512 that is a
 * 0.2% difference, which matters only in that it keeps `x1 = 1.0` meaning "the ink
 * genuinely reaches the right edge" rather than "it reaches the last column's left side".
 *
 * @return array{ink: array{x0:float,y0:float,x1:float,y1:float}|null, rows: list<array{0:float,1:float}|null>}|null
 */
function scanInk(string $png): ?array
{
    $image = @imagecreatefrompng($png);
    if ($image === false) {
        return null;
    }

    // A palette PNG would make imagecolorat() return an index rather than a packed
    // colour, and the alpha shift below would read garbage. png32: should already have
    // ruled this out; this makes it impossible rather than merely unlikely.
    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image);
    }

    $w = imagesx($image);
    $h = imagesy($image);

    $minX = PHP_INT_MAX;
    $maxX = -1;
    $minY = PHP_INT_MAX;
    $maxY = -1;
    $rows = [];

    for ($y = 0; $y < $h; $y++) {
        $rowLeft = -1;
        $rowRight = -1;

        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($image, $x, $y);

            // GD packs alpha as 0 = opaque, 127 = fully transparent — inverted from the
            // usual convention, hence the subtraction rather than a plain mask.
            $a = (127 - (($rgb >> 24) & 0x7F)) / 127;
            if ($a <= 0.0) {
                continue;
            }

            $luma = (0.2126 * (($rgb >> 16) & 0xFF)
                + 0.7152 * (($rgb >> 8) & 0xFF)
                + 0.0722 * ($rgb & 0xFF)) / 255.0;

            // COVERAGE, not darkness. See the note on INK_FLOOR.
            if (($a * max($luma, 1.0 - $luma)) <= INK_FLOOR) {
                continue;
            }

            if ($rowLeft < 0) {
                $rowLeft = $x;
            }
            $rowRight = $x;
        }

        if ($rowLeft < 0) {
            $rows[] = null;
            continue;
        }

        $rows[] = [
            round($rowLeft / $w, PRECISION),
            round(($rowRight + 1) / $w, PRECISION),
        ];

        if ($rowLeft < $minX) {
            $minX = $rowLeft;
        }
        if ($rowRight > $maxX) {
            $maxX = $rowRight;
        }
        if ($minY === PHP_INT_MAX) {
            $minY = $y;
        }
        $maxY = $y;
    }

    // No imagedestroy(): it has been a no-op since PHP 8.0 and is deprecated in 8.5,
    // where calling it emits 253 warnings into this script's own output. $image is a
    // GdImage object and the refcount frees it at the end of this call.
    unset($image);

    if ($maxX < 0) {
        return ['ink' => null, 'rows' => $rows];
    }

    return [
        'ink' => [
            'x0' => round($minX / $w, PRECISION),
            'y0' => round($minY / $h, PRECISION),
            'x1' => round(($maxX + 1) / $w, PRECISION),
            'y1' => round(($maxY + 1) / $h, PRECISION),
        ],
        'rows' => $rows,
    ];
}

/**
 * Write the manifest, pretty-printed everywhere except the row arrays.
 *
 * JSON_PRETTY_PRINT puts every scalar of a nested array on its own line, so 253 files x
 * 512 rows x [left, right] would be a half-million-line, ~15 MB file that no diff and no
 * reader can use. Each `rows` value is therefore encoded compactly and spliced in through
 * a placeholder, which leaves the manifest readable at the level a human reads it — one
 * entry, its box, its ink box — with the row detail on a single machine-readable line.
 *
 * @param array<string, array{box: array{0:int,1:int}, ink: array<string,float>, rows: list<array{0:float,1:float}|null>}> $files
 * @param list<string> $blank
 */
function writeManifest(string $path, array $files, array $blank): void
{
    $rowsByToken = [];
    $doc = [
        '_comment' => 'Generated by bin/build-scroll-ink-extents.php. Do not edit by hand.',
        'raster' => RASTER,
        'inkFloor' => INK_FLOOR,
        'blank' => $blank,
        'files' => [],
    ];

    foreach ($files as $relative => $entry) {
        $doc['files'][$relative] = [
            'box' => $entry['box'],
            'ink' => $entry['ink'],
        ];
    }

    $json = (string) json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    foreach ($rowsByToken as $token => $compact) {
        $json = str_replace('"' . $token . '"', (string) $compact, $json);
    }

    file_put_contents($path, $json . "\n");
}

function relativeTo(string $root, string $path): string
{
    return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}
