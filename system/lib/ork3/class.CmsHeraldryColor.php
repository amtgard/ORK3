<?php

/*************************************************************************
 * CmsHeraldryColor — derive an org's site palette from its heraldry device.
 *
 * WHY THIS EXISTS
 * 92% of parks have a heraldry device and 1.5% have a banner photo, so a park
 * site's only source of individuation is its own arms. Sampling the device gives
 * each of 342 parks a palette nobody had to choose.
 *
 * The algorithm is a port of pkApplyHeroColor() in revised.js (60x60 downsample,
 * 16-step RGB bucketing, skip transparent / near-white / near-black). The port is
 * the point: on the client it re-ran on every page load and every theme toggle,
 * cached nothing, flashed a default green before the image loaded, and broke
 * outright under CORS once org sites get custom domains. Here it runs ONCE, at
 * seed time, and the result is persisted as --fd-primary in ork_cms_theme, where
 * an officer can then override it.
 *
 * Pure logic: no DB, no request state (CmsSanitizer is the precedent), so it is
 * safe to call from a seeder, a migration, or a test.
 *
 * Consumers:
 *   CmsSite::_seedOrgTheme()                       seed-time palette
 *   db-migrations/2026-08-11-park-theme-backfill.php
 *************************************************************************/

class CmsHeraldryColor
{
    /** Sampling grid. Matches the client implementation. */
    public const SAMPLE = 60;

    /** Quantisation step for RGB bucketing. */
    public const BUCKET = 16;

    /** Alpha below this is treated as transparent and skipped. */
    public const MIN_ALPHA = 120;

    /** Channel means outside this band are page-white / ink-black, not tincture. */
    public const MAX_CHANNEL = 215;
    public const MIN_CHANNEL = 25;

    /** Saturation floor kills mud; ceiling kills neon. */
    public const SAT_MIN = 0.30;
    public const SAT_MAX = 0.62;

    /**
     * Forced lightness. Not a preference — at L 0.22 white text clears 7:1 on the
     * resulting field for EVERY hue, which is what lets the hero use white type
     * without a per-park contrast check.
     */
    public const FIELD_L = 0.22;

    /**
     * Dominant tincture of a heraldry file, clamped to a usable field colour.
     *
     * @param string $absPath absolute path to a local png/jpg/gif
     * @return string '#rrggbb', or '' when unreadable or when no pixel qualified
     */
    public static function FromFile($absPath)
    {
        $absPath = (string) $absPath;
        if ($absPath === '' || !is_readable($absPath)) {
            return '';
        }
        if (!function_exists('imagecreatefromstring')) {
            return '';
        }

        $raw = @file_get_contents($absPath);
        if ($raw === false || $raw === '') {
            return '';
        }
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return '';
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 1 || $h < 1) {
            imagedestroy($src);
            return '';
        }

        // Downsample to a fixed grid so cost is independent of the source size.
        $small = imagecreatetruecolor(self::SAMPLE, self::SAMPLE);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $src, 0, 0, 0, 0, self::SAMPLE, self::SAMPLE, $w, $h);
        imagedestroy($src);

        $buckets = array();
        for ($y = 0; $y < self::SAMPLE; $y++) {
            for ($x = 0; $x < self::SAMPLE; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                // GD alpha is 0 (opaque) .. 127 (transparent); invert to 0..255.
                $a = 255 - (int) ((($rgba >> 24) & 0x7F) * 2);
                if ($a < self::MIN_ALPHA) {
                    continue;
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                // Skip the plate and the ink: near-white is usually the device's
                // background, near-black is usually its outline. Neither is the
                // tincture we want to theme from.
                $mean = ($r + $g + $b) / 3;
                if ($mean > self::MAX_CHANNEL || $mean < self::MIN_CHANNEL) {
                    continue;
                }

                $key = (intdiv($r, self::BUCKET) << 16)
                     | (intdiv($g, self::BUCKET) << 8)
                     |  intdiv($b, self::BUCKET);
                if (!isset($buckets[$key])) {
                    $buckets[$key] = array('n' => 0, 'r' => 0, 'g' => 0, 'b' => 0);
                }
                $buckets[$key]['n']++;
                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
            }
        }
        imagedestroy($small);

        if (empty($buckets)) {
            return '';
        }

        $best = null;
        foreach ($buckets as $bucket) {
            if ($best === null || $bucket['n'] > $best['n']) {
                $best = $bucket;
            }
        }

        return self::Clamp(
            (int) round($best['r'] / $best['n']),
            (int) round($best['g'] / $best['n']),
            (int) round($best['b'] / $best['n'])
        );
    }

    /**
     * Deterministic palette from a name — the last resort when an org has no
     * device and no parent device either. Deliberately NOT a fixed default: a
     * single default colour would make every deviceless park identical, which
     * reads as unloved. Hashing the name means neighbouring parks never collide
     * and the colour is stable across re-seeds.
     *
     * @param string $name
     * @return string '#rrggbb'
     */
    public static function FromName($name)
    {
        $hash = crc32((string) $name);
        $hue  = $hash % 360;
        $sat  = self::SAT_MIN + (($hash >> 9) % 100) / 100 * (self::SAT_MAX - self::SAT_MIN);
        return CmsThemeTokens::HslToHex($hue, $sat, self::FIELD_L);
    }

    /**
     * Apply the field clamps to a raw RGB triple.
     *
     * @return string '#rrggbb'
     */
    public static function Clamp($r, $g, $b)
    {
        $hex = CmsThemeTokens::RgbToHex((int) $r, (int) $g, (int) $b);
        $hsl = CmsThemeTokens::HexToHsl($hex);

        $h = $hsl[0];
        $s = max(self::SAT_MIN, min(self::SAT_MAX, $hsl[1]));

        return CmsThemeTokens::HslToHex($h, $s, self::FIELD_L);
    }
}
