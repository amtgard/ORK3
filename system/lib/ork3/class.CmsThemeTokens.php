<?php

// system/lib/ork3/class.CmsThemeTokens.php
// Pure, framework-free token logic for the CMS Theme Engine.
// NO `extends`, NO $DB — safe to include in a CLI harness. All methods static.

class CmsThemeTokens
{
    /** Canonical token catalog (ordered): name => [group, value(default), input]. */
    public static function Defaults()
    {
        return array(
            '--fd-primary'          => array('group' => 'color', 'value' => '#0b1120', 'input' => 'color'),
            '--fd-accent'           => array('group' => 'color', 'value' => '#f0b429', 'input' => 'color'),
            '--fd-bg'               => array('group' => 'color', 'value' => '#ffffff', 'input' => 'color'),
            '--fd-surface'          => array('group' => 'color', 'value' => '#f7f8fa', 'input' => 'color'),
            '--fd-text'             => array('group' => 'color', 'value' => '#1a2236', 'input' => 'color'),
            '--fd-text-muted'       => array('group' => 'color', 'value' => '#5b6472', 'input' => 'color'),
            '--fd-border'           => array('group' => 'color', 'value' => '#e2e6ec', 'input' => 'color'),
            '--fd-primary-contrast' => array('group' => 'color', 'value' => '#ffffff', 'input' => 'derived'),
            '--fd-font-heading'     => array('group' => 'type',  'value' => 'MedievalSharp', 'input' => 'font'),
            '--fd-font-body'        => array('group' => 'type',  'value' => 'Open Sans',     'input' => 'font'),
            '--fd-font-scale'       => array('group' => 'type',  'value' => '1',    'input' => 'scale'),
            '--fd-radius'           => array('group' => 'shape', 'value' => '12px', 'input' => 'px'),
            '--fd-space'            => array('group' => 'shape', 'value' => '1',    'input' => 'scale'),
            '--fd-border-width'     => array('group' => 'shape', 'value' => '1px',  'input' => 'px'),
            '--fd-shadow'           => array('group' => 'shape', 'value' => '0 12px 50px rgba(0,0,0,.4)', 'input' => 'shadow'),
        );
    }

    /** Vetted font families (heading/body must be one of these). */
    public static function FontAllowlist()
    {
        return array('Open Sans', 'MedievalSharp', 'Lexend', 'Georgia', 'system-ui');
    }

    /** token => default value (flattened). */
    public static function DefaultValues()
    {
        $out = array();
        foreach (self::Defaults() as $k => $meta) {
            $out[$k] = $meta['value'];
        }
        return $out;
    }

    /** Numeric ranges for non-color tokens: [min, max, unit]. */
    private static function Ranges()
    {
        return array(
            '--fd-font-scale'   => array(0.9, 1.25, ''),
            '--fd-radius'       => array(0, 24, 'px'),
            '--fd-space'        => array(0.85, 1.3, ''),
            '--fd-border-width' => array(0, 3, 'px'),
        );
    }

    private static $SHADOWS = array(
        'none', '0 1px 3px rgba(0,0,0,.18)', '0 6px 24px rgba(0,0,0,.28)', '0 12px 50px rgba(0,0,0,.4)',
    );

    /** Keep only known tokens whose values pass per-group validation. Pure. */
    public static function Validate($tokens)
    {
        $catalog = self::Defaults();
        $ranges  = self::Ranges();
        $out = array();
        foreach ((array)$tokens as $k => $raw) {
            if (!isset($catalog[$k]) || $catalog[$k]['input'] === 'derived') {
                continue; // unknown or auto-only
            }
            $input = $catalog[$k]['input'];
            if ($input === 'color') {
                $val = strtolower(trim((string)$raw));
                if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $val)) {
                    $out[$k] = $val;
                }
            } elseif ($input === 'font') {
                if (in_array((string)$raw, self::FontAllowlist(), true)) {
                    $out[$k] = (string)$raw;
                }
            } elseif ($input === 'shadow') {
                if (in_array((string)$raw, self::$SHADOWS, true)) {
                    $out[$k] = (string)$raw;
                }
            } elseif (isset($ranges[$k])) {
                list($min, $max, $unit) = $ranges[$k];
                $n = (float)preg_replace('/[^0-9.\-]/', '', (string)$raw);
                $n = max($min, min($max, $n));
                // integers for px, 2-dp for scales
                $out[$k] = ($unit === 'px') ? (((int)round($n)) . 'px') : rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');
            }
        }
        return $out;
    }

    /** '#rrggbb'|'#rgb' => [r,g,b] 0-255. */
    public static function HexToRgb($hex)
    {
        $hex = ltrim(strtolower(trim((string)$hex)), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/', $hex)) {
            return array(0, 0, 0);
        }
        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    public static function RgbToHex($r, $g, $b)
    {
        $c = function ($n) {
            return str_pad(dechex(max(0, min(255, (int)round($n)))), 2, '0', STR_PAD_LEFT);
        };
        return '#' . $c($r) . $c($g) . $c($b);
    }

    /** hex => [h(0-360), s(0-1), l(0-1)]. */
    public static function HexToHsl($hex)
    {
        list($r, $g, $b) = array_map(function ($v) {
            return $v / 255;
        }, self::HexToRgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;
        $l = ($max + $min) / 2;
        $h = 0;
        $s = 0;
        if ($d > 0) {
            $s = $d / (1 - abs(2 * $l - 1));
            if ($max === $r) {
                $h = 60 * fmod((($g - $b) / $d), 6);
            } elseif ($max === $g) {
                $h = 60 * ((($b - $r) / $d) + 2);
            } else {
                $h = 60 * ((($r - $g) / $d) + 4);
            }
        }
        if ($h < 0) {
            $h += 360;
        }
        return array($h, $s, $l);
    }

    public static function HslToHex($h, $s, $l)
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        if ($h < 60) {
            $rp = $c;
            $gp = $x;
            $bp = 0;
        } elseif ($h < 120) {
            $rp = $x;
            $gp = $c;
            $bp = 0;
        } elseif ($h < 180) {
            $rp = 0;
            $gp = $c;
            $bp = $x;
        } elseif ($h < 240) {
            $rp = 0;
            $gp = $x;
            $bp = $c;
        } elseif ($h < 300) {
            $rp = $x;
            $gp = 0;
            $bp = $c;
        } else {
            $rp = $c;
            $gp = 0;
            $bp = $x;
        }
        return self::RgbToHex(($rp + $m) * 255, ($gp + $m) * 255, ($bp + $m) * 255);
    }

    /** WCAG relative luminance 0-1. */
    public static function Luminance($hex)
    {
        $lin = array_map(function ($v) {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }, self::HexToRgb($hex));
        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /** WCAG contrast ratio between two hex colors (>=1). */
    public static function Contrast($a, $b)
    {
        $la = self::Luminance($a);
        $lb = self::Luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Black or white, whichever contrasts better with $bg. */
    private static function BestText($bg)
    {
        return self::Contrast('#ffffff', $bg) >= self::Contrast('#1a2236', $bg) ? '#ffffff' : '#1a2236';
    }

    private static function WithL($hex, $l)
    {
        list($h, $s) = self::HexToHsl($hex);
        return self::HslToHex($h, $s, max(0, min(1, $l)));
    }

    /** Nudge $fg lightness until it clears $ratio against $bg (preserving hue). */
    private static function EnsureContrast($fg, $bg, $ratio, $towardLight)
    {
        for ($i = 0; $i < 20 && self::Contrast($fg, $bg) < $ratio; $i++) {
            list($h, $s, $l) = self::HexToHsl($fg);
            $l = $towardLight ? min(1, $l + 0.04) : max(0, $l - 0.04);
            $fg = self::HslToHex($h, $s, $l);
        }
        return $fg;
    }

    /** Resolve user tokens to full light + dark token maps. Pure. */
    public static function Derive($userTokens)
    {
        $light = array_merge(self::DefaultValues(), self::Validate($userTokens));
        $light['--fd-primary-contrast'] = self::BestText($light['--fd-primary']);

        // Dark color set (color tokens only; shape/type pass through).
        $dark = $light;
        $dark['--fd-bg']         = self::WithL($light['--fd-primary'], 0.08);   // brand-tinted near-black
        $dark['--fd-surface']    = self::WithL($light['--fd-primary'], 0.13);
        $dark['--fd-border']     = self::WithL($light['--fd-primary'], 0.22);
        $dark['--fd-text']       = '#e8ecf1';
        $dark['--fd-text-muted'] = '#aab3c0';
        // Brand colors: lift lightness for legibility on dark, keep hue/sat.
        list($ph, $ps, $pl) = self::HexToHsl($light['--fd-primary']);
        $dark['--fd-primary'] = self::HslToHex($ph, $ps, max($pl, 0.55));
        list($ah, $as, $al) = self::HexToHsl($light['--fd-accent']);
        $dark['--fd-accent']  = self::HslToHex($ah, $as, max($al, 0.55));
        $dark['--fd-primary-contrast'] = self::BestText($dark['--fd-primary']);

        // Contrast safety on derived pairs (nudge derived values, not stored ones).
        $dark['--fd-text']       = self::EnsureContrast($dark['--fd-text'], $dark['--fd-bg'], 4.5, true);
        $dark['--fd-text-muted'] = self::EnsureContrast($dark['--fd-text-muted'], $dark['--fd-bg'], 3.0, true);

        return array('light' => $light, 'dark' => $dark);
    }

    private static function FontStack($family)
    {
        $fallback = ($family === 'MedievalSharp') ? 'cursive'
            : (($family === 'Georgia') ? 'serif'
            : (($family === 'system-ui') ? 'sans-serif' : "'Open Sans', sans-serif"));
        return ($family === 'system-ui') ? 'system-ui, sans-serif' : "'" . $family . "', " . $fallback;
    }

    private static function Block($selector, $tokens)
    {
        $parts = array();
        foreach ($tokens as $k => $v) {
            if ($k === '--fd-font-heading' || $k === '--fd-font-body') {
                $v = self::FontStack($v);
            } elseif ($k === '--fd-font-scale') {
                $v = 'calc(1rem * ' . $v . ')';
            }
            $parts[] = $k . ':' . $v;
        }
        return $selector . '{' . implode(';', $parts) . '}';
    }

    public static function ToCss($userTokens)
    {
        $d = self::Derive($userTokens);
        return self::Block('.fd-page', $d['light'])
            . ' ' . self::Block('html[data-theme="dark"] .fd-page', $d['dark']);
    }
}
