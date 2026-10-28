<?php

declare(strict_types=1);

/**
 * Heraldry as a scroll draws it: the uploaded image, with an opaque black square keyed
 * out when the image is plainly a device on a black ground (B6).
 *
 * _heraldry.tpl draws heraldry with mix-blend-mode: multiply, so a white ground
 * vanishes into the paper but a black one prints as a black block. Uploads are stored
 * as-is (Heraldry::store_heraldry), and a shield cropped onto a black canvas, or a PNG
 * whose alpha fell through to JPEG, is exactly that block.
 *
 * The key is deliberately cautious, because many dark images are dark ART (black
 * shields, white-on-black line art) that removing the black would destroy. An image
 * qualifies only when all of these hold:
 *   - it has no meaningful alpha already;
 *   - all four corner patches are near-black;
 *   - the near-black region connected to the corners covers 5-60% of the image;
 *   - at most 10% of that region is out of sight of every edge (HIDDEN_MAX: not in the
 *     brief, added after the corpus dry run found dark art joined to the ground).
 * Then that corner-connected region, and only it, is made transparent, with a 2 px
 * feather that fades dark fringe (JPEG ringing) and never fades ink. Dark art inside the
 * device that does not touch the ground is untouched: it is not connected to a corner.
 * Residual risk, accepted by the brief: a sable tincture running to the device's own
 * edge with no outline (a black chief, a per-bend sable half) joins the ground and is
 * keyed with it when the guards above do not catch it.
 *
 * The keyed copy is a PNG in DIR_HERALDRY/scroll/, named by the source's sha1 and mtime
 * (plus RULES_VERSION) and made lazily on first request. A re-upload changes both, so
 * it is a new file (and the source's stale copies are removed when it is made). Images
 * that do not qualify leave a zero-byte .skip marker so they are judged once, not on
 * every render. The originals are never modified. Only scroll renders call this;
 * profiles and every other heraldry surface keep the master.
 *
 * Fails open: any GD, file or permission problem returns the source URL unchanged.
 *
 * NOTE: startup.php instantiates every class in system/lib/ork3 with `new ClassName()`,
 * so this takes no constructor arguments; everything useful is static.
 */
class ScrollHeraldry
{
    /** A corner patch, and the flood, take pixels at or below this luminance (Rec. 709, 0-1). */
    public const NEAR_BLACK_LUMINANCE = 0.10;

    /**
     * ...that are also neutral: max - min channel <= this (0-255). Black is not navy:
     * a dark blue field (16,13,116 has luminance 0.08) touching a black ground must not
     * be eaten with it.
     */
    public const NEAR_BLACK_CHROMA = 40;

    /** ...and opaque: GD alpha <= this (0 opaque .. 127 clear). */
    public const NEAR_BLACK_MAX_ALPHA = 32;

    /** The corner-connected near-black region must cover this share of the image... */
    public const REGION_MIN = 0.05;

    /** ...and no more than this. Above it the black is the art, not its ground. */
    public const REGION_MAX = 0.60;

    /**
     * At most this share of the region may be out of sight of every edge: unreached by
     * any straight row or column ray from an image edge through region pixels alone. The
     * ground around a device is seen from the edges; dark art that touches it through a
     * gap is not. NOT in the brief: the dry run's hand-check found the 5-60% rules alone
     * eating sable silhouettes and night scenes (park 00768, player 044269); every file
     * above 0.30 it checked was damaged, and about a quarter between 0.10 and 0.30.
     */
    public const HIDDEN_MAX = 0.10;

    /** Corner patch side, as a share of the shorter edge (clamped 2-8 px). */
    public const CORNER_PATCH = 0.03;

    /** Share of a corner patch that must be near-black (JPEG noise allowance). */
    public const CORNER_SHARE = 0.90;

    /**
     * "Meaningful alpha already": at least this share of pixels is half transparent or
     * more (GD alpha >= 64). An image like that has its own ground; leave it alone.
     */
    public const ALPHA_SHARE = 0.001;

    /**
     * Feather: a pixel 1 px outside the keyed region loses opacity in proportion to how
     * dark it is between NEAR_BLACK_LUMINANCE (fully cleared) and this (untouched); at
     * 2 px it loses half that. Ink brighter than this is never faded.
     */
    public const FEATHER_LUMINANCE = 0.30;

    /** Images larger than this are left alone (memory). Uploads are capped far below. */
    public const MAX_PIXELS = 16000000;

    private const CACHE_SUBDIR = 'scroll/';

    /**
     * Part of every cached name. Bump it whenever a rule or threshold here changes, so
     * every image is judged again under the new rules (each source's old files are
     * removed as its new one is made).
     */
    private const RULES_VERSION = 1;

    /**
     * The scroll rendition of a heraldry URL from GetHeraldryUrl(): the keyed copy's
     * URL when the file it names qualifies, else the URL unchanged. Only URLs under
     * HTTP_HERALDRY that name an existing image file are considered.
     */
    public static function ForUrl(string $url): string
    {
        if ($url === '' || !defined('HTTP_HERALDRY') || !defined('DIR_HERALDRY')) {
            return $url;
        }
        $base = (string) HTTP_HERALDRY;
        if (strncmp($url, $base, strlen($base)) !== 0) {
            return $url;
        }
        $rel = (string) strtok(substr($url, strlen($base)), '?#');
        if (!preg_match('~^([a-z-]+)/([A-Za-z0-9_-]+\.(?:jpe?g|png|gif|webp))$~i', $rel, $m)
            || $m[1] . '/' === self::CACHE_SUBDIR) {
            return $url;
        }
        $path = DIR_HERALDRY . $rel;
        if (!is_file($path)) {
            return $url;
        }

        return self::KeyedUrl($path, $url);
    }

    /**
     * A URL to a keyed PNG when $sourcePath qualifies, else $sourceUrl unchanged.
     *
     * $cacheDir / $cacheUrl default to DIR_HERALDRY/scroll/ and HTTP_HERALDRY/scroll/;
     * tests pass their own.
     */
    public static function KeyedUrl(string $sourcePath, string $sourceUrl, ?string $cacheDir = null, ?string $cacheUrl = null): string
    {
        try {
            if ($cacheDir === null || $cacheUrl === null) {
                if (!defined('DIR_HERALDRY') || !defined('HTTP_HERALDRY')) {
                    return $sourceUrl;
                }
                $cacheDir ??= DIR_HERALDRY . self::CACHE_SUBDIR;
                $cacheUrl ??= HTTP_HERALDRY . self::CACHE_SUBDIR;
            }
            if (!is_file($sourcePath) || !function_exists('imagecreatefromstring')) {
                return $sourceUrl;
            }

            $prefix = self::cache_prefix($sourcePath);
            $sha    = sha1_file($sourcePath);
            $mtime  = filemtime($sourcePath);
            if ($sha === false || $mtime === false) {
                return $sourceUrl;
            }
            $name = $prefix . '-' . $sha . '-' . $mtime . '-r' . self::RULES_VERSION;

            if (is_file($cacheDir . $name . '.png')) {
                return $cacheUrl . $name . '.png';
            }
            if (is_file($cacheDir . $name . '.skip')) {
                return $sourceUrl;
            }

            if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
                return $sourceUrl;
            }
            if (!is_writable($cacheDir)) {
                return $sourceUrl;
            }

            $im = self::load($sourcePath);
            if ($im === null) {
                return $sourceUrl;
            }
            $judged = self::judge($im);
            self::forget_stale($cacheDir, $prefix, $name);

            if (!$judged['qualifies']) {
                @touch($cacheDir . $name . '.skip');
                return $sourceUrl;
            }

            self::apply_key($im, $judged['mask'], $judged['ring1'], $judged['ring2']);
            $tmp = $cacheDir . $name . '.' . bin2hex(random_bytes(4)) . '.tmp';
            imagealphablending($im, false);
            imagesavealpha($im, true);
            if (!@imagepng($im, $tmp) || !@rename($tmp, $cacheDir . $name . '.png')) {
                @unlink($tmp);
                return $sourceUrl;
            }

            return $cacheUrl . $name . '.png';
        } catch (\Throwable $e) {
            return $sourceUrl;
        }
    }

    /**
     * Whether an image file qualifies, read-only: for tests and the dry run.
     *
     * @return array{qualifies:bool, reason:string, region:float, hidden:float, width:int, height:int}
     *   reason is one of: keyed, unreadable, corners, alpha, region-small, region-large,
     *   hidden.
     */
    public static function Analyse(string $sourcePath): array
    {
        $im = is_file($sourcePath) ? self::load($sourcePath) : null;
        if ($im === null) {
            return ['qualifies' => false, 'reason' => 'unreadable', 'region' => 0.0, 'hidden' => 0.0, 'width' => 0, 'height' => 0];
        }
        $j = self::judge($im);
        unset($j['mask'], $j['ring1'], $j['ring2']);

        return $j;
    }

    /** Analyse() plus the keyed image itself (null when it does not qualify): dry run. */
    public static function Preview(string $sourcePath): ?\GdImage
    {
        $im = is_file($sourcePath) ? self::load($sourcePath) : null;
        if ($im === null) {
            return null;
        }
        $j = self::judge($im);
        if (!$j['qualifies']) {
            return null;
        }
        self::apply_key($im, $j['mask'], $j['ring1'], $j['ring2']);
        imagesavealpha($im, true);

        return $im;
    }

    private static function load(string $path): ?\GdImage
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return null;
        }
        $size = @getimagesizefromstring($data);
        if (!is_array($size) || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }
        $im = @imagecreatefromstring($data);
        if ($im === false) {
            return null;
        }
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);

        return $im;
    }

    private static function is_near_black(int $c): bool
    {
        if ((($c >> 24) & 0x7F) > self::NEAR_BLACK_MAX_ALPHA) {
            return false;
        }
        $r = ($c >> 16) & 0xFF;
        $g = ($c >> 8) & 0xFF;
        $b = $c & 0xFF;
        if (max($r, $g, $b) - min($r, $g, $b) > self::NEAR_BLACK_CHROMA) {
            return false;
        }

        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255 <= self::NEAR_BLACK_LUMINANCE;
    }

    private static function luminance(int $c): float
    {
        return (0.2126 * (($c >> 16) & 0xFF) + 0.7152 * (($c >> 8) & 0xFF) + 0.0722 * ($c & 0xFF)) / 255;
    }

    /**
     * @return array{qualifies:bool, reason:string, region:float, hidden:float, width:int,
     *               height:int, mask:string, ring1:array<int,int>, ring2:array<int,int>}
     */
    private static function judge(\GdImage $im): array
    {
        $w   = imagesx($im);
        $h   = imagesy($im);
        $out = ['qualifies' => false, 'reason' => 'corners', 'region' => 0.0, 'hidden' => 0.0, 'width' => $w, 'height' => $h,
                'mask' => '', 'ring1' => [], 'ring2' => []];
        if ($w < 4 || $h < 4) {
            return $out;
        }

        // 1. Corner patches — cheap, and most images stop here.
        $p     = max(2, min(8, (int) round(min($w, $h) * self::CORNER_PATCH)));
        $seeds = [];
        foreach ([[0, 0], [$w - $p, 0], [0, $h - $p], [$w - $p, $h - $p]] as [$x0, $y0]) {
            $dark = 0;
            for ($y = $y0; $y < $y0 + $p; $y++) {
                for ($x = $x0; $x < $x0 + $p; $x++) {
                    if (self::is_near_black(imagecolorat($im, $x, $y))) {
                        $dark++;
                        $seeds[] = $y * $w + $x;
                    }
                }
            }
            if ($dark < self::CORNER_SHARE * $p * $p) {
                return $out;
            }
        }

        // 2. One pass: the near-black bitmap, and how much is already transparent.
        $n     = $w * $h;
        $dark  = str_repeat("\0", $n);
        $clear = 0;
        for ($y = 0, $i = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++, $i++) {
                $c = imagecolorat($im, $x, $y);
                if ((($c >> 24) & 0x7F) >= 64) {
                    $clear++;
                } elseif (self::is_near_black($c)) {
                    $dark[$i] = "\1";
                }
            }
        }
        if ($clear >= self::ALPHA_SHARE * $n) {
            $out['reason'] = 'alpha';
            return $out;
        }

        // 3. Flood from the corners over near-black (4-connected). A non-dark neighbour
        //    met on the way is the first feather ring.
        $mask  = str_repeat("\0", $n);
        $ring1 = [];
        $stack = [];
        foreach ($seeds as $s) {
            if ($mask[$s] === "\0") {
                $mask[$s] = "\1";
                $stack[]  = $s;
            }
        }
        $count = 0;
        while ($stack) {
            $i = array_pop($stack);
            $count++;
            $x = $i % $w;
            foreach ([$x > 0 ? $i - 1 : -1, $x < $w - 1 ? $i + 1 : -1, $i - $w, $i + $w] as $j) {
                if ($j < 0 || $j >= $n || $mask[$j] !== "\0") {
                    continue;
                }
                if ($dark[$j] === "\1") {
                    $mask[$j] = "\1";
                    $stack[]  = $j;
                } else {
                    $ring1[$j] = 1;
                }
            }
        }
        $out['region'] = $count / $n;
        if ($out['region'] < self::REGION_MIN) {
            $out['reason'] = 'region-small';
            return $out;
        }
        if ($out['region'] > self::REGION_MAX) {
            $out['reason'] = 'region-large';
            return $out;
        }

        $out['hidden'] = self::hidden_share($mask, $w, $h, $count);
        if ($out['hidden'] > self::HIDDEN_MAX) {
            $out['reason'] = 'hidden';
            return $out;
        }

        $ring2 = [];
        foreach ($ring1 as $i => $_) {
            $x = $i % $w;
            foreach ([$x > 0 ? $i - 1 : -1, $x < $w - 1 ? $i + 1 : -1, $i - $w, $i + $w] as $j) {
                if ($j >= 0 && $j < $n && $mask[$j] === "\0" && !isset($ring1[$j])) {
                    $ring2[$j] = 1;
                }
            }
        }

        $out['qualifies'] = true;
        $out['reason']    = 'keyed';
        $out['mask']      = $mask;
        $out['ring1']     = $ring1;
        $out['ring2']     = $ring2;

        return $out;
    }

    /**
     * The share of the keyed region that no straight row or column ray from an image
     * edge reaches through keyed pixels alone. A ground around a device is seen from
     * the edges; dark art inside the device that happens to touch the ground through a
     * gap (a night scene behind a charge, a field behind tracery) is not.
     */
    private static function hidden_share(string $mask, int $w, int $h, int $count): float
    {
        if ($count === 0) {
            return 0.0;
        }
        $seen = str_repeat("\0", $w * $h);
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w && $mask[$row + $x] === "\1"; $x++) {
                $seen[$row + $x] = "\1";
            }
            for ($x = $w - 1; $x >= 0 && $mask[$row + $x] === "\1"; $x--) {
                $seen[$row + $x] = "\1";
            }
        }
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h && $mask[$y * $w + $x] === "\1"; $y++) {
                $seen[$y * $w + $x] = "\1";
            }
            for ($y = $h - 1; $y >= 0 && $mask[$y * $w + $x] === "\1"; $y--) {
                $seen[$y * $w + $x] = "\1";
            }
        }
        $visible = substr_count($seen, "\1");

        return ($count - $visible) / $count;
    }

    /**
     * @param array<int,int> $ring1
     * @param array<int,int> $ring2
     */
    private static function apply_key(\GdImage $im, string $mask, array $ring1, array $ring2): void
    {
        $w = imagesx($im);
        imagealphablending($im, false);
        $n = strlen($mask);
        for ($i = strpos($mask, "\1"); $i !== false && $i < $n; $i = strpos($mask, "\1", $i + 1)) {
            imagesetpixel($im, $i % $w, intdiv($i, $w), 0x7F000000);
        }
        $span = self::FEATHER_LUMINANCE - self::NEAR_BLACK_LUMINANCE;
        foreach ([[$ring1, 1.0], [$ring2, 0.5]] as [$ring, $falloff]) {
            foreach ($ring as $i => $_) {
                $x = $i % $w;
                $y = intdiv($i, $w);
                $c = imagecolorat($im, $x, $y);
                $t = max(0.0, min(1.0, (self::FEATHER_LUMINANCE - self::luminance($c)) / $span)) * $falloff;
                if ($t <= 0.0) {
                    continue;
                }
                $a = max(($c >> 24) & 0x7F, (int) round(127 * $t));
                imagesetpixel($im, $x, $y, ($a << 24) | ($c & 0xFFFFFF));
            }
        }
    }

    /** "player-002433" for assets/heraldry/player/002433.jpg. */
    private static function cache_prefix(string $path): string
    {
        $dir  = basename(dirname($path));
        $file = pathinfo($path, PATHINFO_FILENAME);

        return strtolower((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $dir . '-' . $file));
    }

    /** Remove this source's keyed copies and skip markers from earlier uploads. */
    private static function forget_stale(string $cacheDir, string $prefix, string $keep): void
    {
        foreach (glob($cacheDir . $prefix . '-*') ?: [] as $f) {
            $base = basename($f);
            if (strpos($base, $keep . '.') === 0) {
                continue;
            }
            // Only our own names: <prefix>-<40 hex>-<mtime>[-r<rules>].(png|skip)
            if (preg_match('/^' . preg_quote($prefix, '/') . '-[0-9a-f]{40}-\d+(-r\d+)?\.(png|skip)$/', $base)) {
                @unlink($f);
            }
        }
    }
}
