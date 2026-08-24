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
            // Archivo, NOT MedievalSharp. This value is what the theme editor's
            // "reset to default" writes back, so leaving it faux-medieval here
            // re-introduces at one click the exact default the CSS layer and the
            // seeder were both changed to eliminate. MedievalSharp stays PICKABLE
            // in FontAllowlist() for an org that deliberately wants it.
            '--fd-font-heading'     => array('group' => 'type',  'value' => 'Archivo', 'input' => 'font'),
            '--fd-font-body'        => array('group' => 'type',  'value' => 'Open Sans',     'input' => 'font'),
            '--fd-font-scale'       => array('group' => 'type',  'value' => '1',    'input' => 'scale'),
            '--fd-radius'           => array('group' => 'shape', 'value' => '12px', 'input' => 'px'),
            '--fd-space'            => array('group' => 'shape', 'value' => '1',    'input' => 'scale'),
            '--fd-border-width'     => array('group' => 'shape', 'value' => '1px',  'input' => 'px'),
            '--fd-shadow'           => array('group' => 'shape', 'value' => '0 12px 50px rgba(0,0,0,.4)', 'input' => 'shadow'),
        );
    }

    /**
     * The vetted font catalogue: family => group / role / fallback / weights.
     *
     * ONE definition, four consumers — the theme editor's pickers, Validate(),
     * FontStack()'s CSS fallback, and the Google Fonts <link> the public page
     * emits. They used to be four hand-maintained lists (an allowlist here, an
     * if-ladder in FontStack, and hardcoded <link> tags in default.theme), which
     * meant adding a face took three edits in three files and forgetting one
     * failed SILENTLY: the org-site seeder wrote '--fd-font-body' => 'Lexend'
     * for every site while default.theme never linked it, so every org asked for
     * a webfont that was never loaded and fell back to the generic sans.
     *
     *   group    picker section — 'display' | 'serif' | 'sans'. Presentation only.
     *   role     'heading' = display face, heading picker ONLY.
     *            'both'    = readable enough for running text, offered in both.
     *            A blackletter or script face must never be a BODY font: it is
     *            unreadable at paragraph length and the officer choosing it
     *            cannot see the damage from the editor's preview line alone.
     *   fallback the generic family appended after it in the CSS stack.
     *   weights  the css2 'wght@...' axis, or null for a SYSTEM face that must
     *            never be requested from Google (Georgia, system-ui). Every
     *            non-null value here is verified to return 200 from the css2
     *            API — an unavailable weight makes Google answer 400 and the
     *            whole stylesheet, including the families that WERE valid,
     *            fails to load.
     *
     * @return array<string,array{group:string,role:string,fallback:string,weights:?string}>
     */
    public static function FontCatalog()
    {
        return array(
            'Cinzel'            => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => 'wght@400;600;700'),
            'Cinzel Decorative' => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => 'wght@400;700'),
            'Marcellus'         => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => ''),
            'Marcellus SC'      => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => ''),
            'Caudex'            => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => 'wght@400;700'),
            'Eagle Lake'        => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'UnifrakturMaguntia' => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'UnifrakturCook'    => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => 'wght@700'),
            'Pirata One'        => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'Grenze Gotisch'    => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => 'wght@400;700'),
            'Uncial Antiqua'    => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'MedievalSharp'     => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'IM Fell English'   => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => ''),
            'IM Fell English SC' => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => ''),
            'Sorts Mill Goudy'  => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => ''),
            'Metamorphous'      => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => ''),
            'Almendra'          => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => 'wght@400;700'),
            'Almendra Display'  => array('group' => 'display', 'role' => 'heading', 'fallback' => 'serif', 'weights' => ''),
            'Macondo'           => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'Fondamento'        => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'Berkshire Swash'   => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'Griffy'            => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'Pinyon Script'     => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'Great Vibes'       => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => ''),
            'Tangerine'         => array('group' => 'display', 'role' => 'heading', 'fallback' => 'cursive', 'weights' => 'wght@400;700'),
            'Oswald'            => array('group' => 'display', 'role' => 'heading', 'fallback' => 'sans-serif', 'weights' => 'wght@400;500;600;700'),
            'EB Garamond'       => array('group' => 'serif', 'role' => 'both', 'fallback' => 'serif', 'weights' => 'wght@400;500;600;700'),
            'Cormorant Garamond' => array('group' => 'serif', 'role' => 'both', 'fallback' => 'serif', 'weights' => 'wght@400;500;600;700'),
            'Crimson Pro'       => array('group' => 'serif', 'role' => 'both', 'fallback' => 'serif', 'weights' => 'wght@400;600;700'),
            'Libre Baskerville' => array('group' => 'serif', 'role' => 'both', 'fallback' => 'serif', 'weights' => 'wght@400;700'),
            'Lora'              => array('group' => 'serif', 'role' => 'both', 'fallback' => 'serif', 'weights' => 'wght@400;500;600;700'),
            'Spectral'          => array('group' => 'serif', 'role' => 'both', 'fallback' => 'serif', 'weights' => 'wght@400;600;700'),
            'Vollkorn'          => array('group' => 'serif', 'role' => 'both', 'fallback' => 'serif', 'weights' => 'wght@400;600;700'),
            'Gentium Book Plus' => array('group' => 'serif', 'role' => 'both', 'fallback' => 'serif', 'weights' => 'wght@400;700'),
            'Alegreya'          => array('group' => 'serif', 'role' => 'both', 'fallback' => 'serif', 'weights' => 'wght@400;500;600;700'),
            'Georgia'           => array('group' => 'serif', 'role' => 'both', 'fallback' => 'serif', 'weights' => null),
            'Archivo'           => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => 'wght@400;500;600;700;800'),
            'Open Sans'         => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => 'wght@400;600;700'),
            'Lexend'            => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => 'wght@300;400;500;600;700'),
            'Inter'             => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => 'wght@400;500;600;700'),
            'Source Sans 3'     => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => 'wght@400;600;700'),
            'Work Sans'         => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => 'wght@400;500;600;700'),
            'Public Sans'       => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => 'wght@400;600;700'),
            'Karla'             => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => 'wght@400;600;700'),
            'Nunito Sans'       => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => 'wght@400;600;700'),
            'Alegreya Sans'     => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => 'wght@400;500;700'),
            'system-ui'         => array('group' => 'sans', 'role' => 'both', 'fallback' => 'sans-serif', 'weights' => null),
        );
    }

    /**
     * Every pickable family, flattened.
     *
     * Kept as the historical name because Validate() and the theme editor's
     * controller both read it, and it is the coarse "is this a family we ship"
     * question. Role-aware callers want FontsForRole() instead.
     *
     * @return string[]
     */
    public static function FontAllowlist()
    {
        return array_keys(self::FontCatalog());
    }

    /**
     * The families offered for one token's picker.
     *
     * @param  string $role 'heading' or 'body'
     * @return string[] in catalogue order (display faces first)
     */
    public static function FontsForRole($role)
    {
        $role = ($role === 'body') ? 'body' : 'heading';
        $out  = array();
        foreach (self::FontCatalog() as $family => $meta) {
            if ($role === 'heading' || $meta['role'] === 'both') {
                $out[] = $family;
            }
        }
        return $out;
    }

    /** The role a font token selects for: '--fd-font-body' => 'body'. */
    public static function RoleForToken($token)
    {
        return ($token === '--fd-font-body') ? 'body' : 'heading';
    }

    /**
     * The css2 href that loads these families, or '' when none needs loading.
     *
     * Used by the public page (the two families a site actually uses) and by the
     * theme editor (one family at a time, as its picker row scrolls into view).
     * System faces are skipped — asking Google for 'system-ui' 404s the family
     * and takes the rest of the request down with it.
     *
     * @param  string[] $families
     * @return string   a full https URL, or '' when every family was a system face
     */
    public static function FontHref($families)
    {
        $cat   = self::FontCatalog();
        $parts = array();
        foreach ((array)$families as $family) {
            $family = (string)$family;
            if (!isset($cat[$family]) || $cat[$family]['weights'] === null) {
                continue;   // unknown, or a system face that needs no request
            }
            $spec = str_replace(' ', '+', $family);
            if ($cat[$family]['weights'] !== '') {
                $spec .= ':' . $cat[$family]['weights'];
            }
            if (!in_array($spec, $parts, true)) {
                $parts[] = $spec;   // heading === body must not be requested twice
            }
        }
        if (count($parts) === 0) {
            return '';
        }
        return self::FONT_CSS2_URL . '?' . self::FontQuery($families);
    }

    /** The css2 origin+path. A LITERAL, so a template can emit it without the
     *  CSS-boundary gate having to prove where a variable href lands (C6). */
    public const FONT_CSS2_URL = 'https://fonts.googleapis.com/css2';

    /**
     * Just the query string for FontHref(): 'family=A&family=B&display=swap'.
     *
     * Exists so default.theme can write the origin as a literal and interpolate
     * only the query. C6 fails closed on a stylesheet href built from an
     * unresolvable variable — correctly, since such a href could name any file,
     * orkui.css included — and an org's font choice must not be the thing that
     * makes a stylesheet path unprovable.
     *
     * @return string '' when every family was a system face
     */
    public static function FontQuery($families)
    {
        $cat   = self::FontCatalog();
        $parts = array();
        foreach ((array)$families as $family) {
            $family = (string)$family;
            if (!isset($cat[$family]) || $cat[$family]['weights'] === null) {
                continue;
            }
            $spec = str_replace(' ', '+', $family);
            if ($cat[$family]['weights'] !== '') {
                $spec .= ':' . $cat[$family]['weights'];
            }
            if (!in_array($spec, $parts, true)) {
                $parts[] = $spec;
            }
        }
        if (count($parts) === 0) {
            return '';
        }
        return 'family=' . implode('&family=', $parts) . '&display=swap';
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
                // Role-aware on purpose. A blackletter or script face is a valid
                // HEADING and an invalid BODY, so the flat allowlist is not a
                // strong enough gate: FontsForRole() is what the picker offered,
                // and the save path has to enforce the same thing the UI showed.
                $allowed = self::FontsForRole(self::RoleForToken($k));
                if (in_array((string)$raw, $allowed, true)) {
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

        // The hue alone, so CSS can build the tinted paper scale
        // (hsl(var(--fd-primary-h) 34% 98.5%)) without re-parsing the hex.
        $primaryHsl = self::HexToHsl($light['--fd-primary']);
        $light['--fd-primary-h'] = (string) (int) round($primaryHsl[0]);

        // Gold-on-gold guard: ~6% of hues collide with the ORK accent. Where the
        // accent would not read on the field, fall back to the field's own
        // contrast colour instead of special-casing at the template layer.
        // Threshold is 4.5, not the 3.0 "large text" bar: this token is consumed
        // by .pk-eyebrow (11px) and .pk-strip-link (~15px/600), neither of which
        // qualifies as WCAG "large text", so both need the normal-text bar. (Fix
        // round 1, Task 10 review — see the dark branch below for the rest of
        // the story.)
        $light['--fd-accent-on-primary'] = (self::Contrast($light['--fd-accent'], $light['--fd-primary']) >= 4.5)
            ? $light['--fd-accent']
            : $light['--fd-primary-contrast'];

        // Card plate. Light: cards sit on the tinted paper band, so plain --fd-bg
        // reads as the raised surface. Dark: the band and the page share --fd-bg,
        // so a card needs its own brand-tinted plate one step lighter or it
        // disappears into the band (.fd-card reads this token in BOTH themes).
        $light['--fd-card-bg'] = $light['--fd-bg'];

        // Dark color set (color tokens only; shape/type pass through).
        $dark = $light;
        $dark['--fd-bg']         = self::WithL($light['--fd-primary'], 0.08);   // brand-tinted near-black
        $dark['--fd-surface']    = self::WithL($light['--fd-primary'], 0.13);
        $dark['--fd-card-bg']    = self::WithL($light['--fd-primary'], 0.18);
        $dark['--fd-border']     = self::WithL($light['--fd-primary'], 0.22);
        $dark['--fd-text']       = '#e8ecf1';
        $dark['--fd-text-muted'] = '#aab3c0';
        // Brand colors: lift lightness for legibility on dark, keep hue/sat.
        list($ph, $ps, $pl) = self::HexToHsl($light['--fd-primary']);
        $dark['--fd-primary'] = self::HslToHex($ph, $ps, max($pl, 0.55));
        list($ah, $as, $al) = self::HexToHsl($light['--fd-accent']);
        $dark['--fd-accent']  = self::HslToHex($ah, $as, max($al, 0.55));
        $dark['--fd-primary-contrast'] = self::BestText($dark['--fd-primary']);

        // Fix round 1 (Task 10 review): --fd-accent-on-primary was inherited from
        // $light via the `$dark = $light;` copy above and never recomputed, so it
        // kept judging contrast against the LIGHT primary/accent pair even though
        // dark just lifted both of them. .pk-strip-link and .pk-eyebrow (both of
        // which read this token) measured 2.77:1 / 3.39:1 in dark mode as a
        // result. Two fixes were needed together, not one: (1) recompute against
        // dark's own lifted primary/accent instead of the stale light values, and
        // (2) raise the guard's own bar from 3.0 to 4.5 — for this park's actual
        // hue the OLD 3.0 bar still passed post-recompute (gold-on-lifted-primary
        // landed at 3.39, "large text" AA but not the normal-text AA these two
        // selectors need), so recomputing alone was verified insufficient before
        // this second change was added. Same 4.5 bar the light branch now uses.
        $dark['--fd-accent-on-primary'] = (self::Contrast($dark['--fd-accent'], $dark['--fd-primary']) >= 4.5)
            ? $dark['--fd-accent']
            : $dark['--fd-primary-contrast'];

        // Contrast safety on derived pairs (nudge derived values, not stored ones).
        $dark['--fd-text']       = self::EnsureContrast($dark['--fd-text'], $dark['--fd-bg'], 4.5, true);
        $dark['--fd-text-muted'] = self::EnsureContrast($dark['--fd-text-muted'], $dark['--fd-bg'], 3.0, true);

        return array('light' => $light, 'dark' => $dark);
    }

    /**
     * The CSS font stack for one family: the family, then its generic fallback.
     *
     * Reads the fallback from FontCatalog() rather than an if-ladder, so adding
     * a face is one edit in one place. A family must never fall back to ITSELF:
     * without the guard the default body font emitted
     * `'Open Sans', 'Open Sans', sans-serif` — harmless to the cascade but a
     * duplicate the static CSS side could not honestly mirror, which is why
     * frontdoor.css's fallback could not be brought to parity with this
     * authority. See tests/cms-theme/tokens_test.php.
     *
     * A system face is emitted unquoted: `system-ui` is a CSS-wide keyword, and
     * quoting it makes it a (nonexistent) family name instead.
     */
    private static function FontStack($family)
    {
        $cat = self::FontCatalog();
        if (!isset($cat[$family])) {
            return "'" . $family . "', sans-serif";
        }
        $generic = $cat[$family]['fallback'];
        $isSystem = ($cat[$family]['weights'] === null);

        // 'system-ui' is a CSS-wide keyword, not a family name — quoting it
        // turns it into a (nonexistent) family and the whole stack falls to the
        // generic. A system face needs no webfont bridge either.
        if ($family === 'system-ui') {
            return 'system-ui, ' . $generic;
        }
        if ($isSystem) {
            return "'" . $family . "', " . $generic;
        }

        // A TEXT face routes through the house text face before its generic: if
        // its webfont fails, the other webfont this page already loaded is a far
        // closer match than whatever the OS calls "serif". A DISPLAY face does
        // NOT — substituting Open Sans for a blackletter heading loses exactly
        // the flavour the officer picked it for, so it falls straight to the
        // generic and keeps the shape.
        // A family must never fall back to ITSELF: without the guard the default
        // body font emitted `'Open Sans', 'Open Sans', sans-serif` — harmless to
        // the cascade, but a duplicate the static CSS side cannot honestly
        // mirror, which is why frontdoor.css's fallback could not be brought to
        // parity with this authority. See tests/cms-theme/tokens_test.php.
        $house = ($cat[$family]['group'] === 'display' || $family === 'Open Sans')
            ? ''
            : "'Open Sans', ";

        return "'" . $family . "', " . $house . $generic;
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

    /**
     * The same token pair as ToCss(), but published at :root instead of .fd-page.
     *
     * Why this exists: custom properties inherit DOWNWARD only. <html> and <body>
     * are ANCESTORS of .fd-page, so a token set scoped to .fd-page is invisible to
     * them — cms-base.css's `body { background: var(--fd-bg, #fff) }` would always
     * take the hardcoded fallback and a dark-themed org site would paint a white
     * body on overscroll and on short pages. Standalone org sites therefore emit
     * this ROOT-scoped copy as well, ahead of the .fd-page copy so the scoped one
     * still wins inside the page itself.
     *
     * The dark selector is 'html[data-theme="dark"]' with NO descendant part:
     * :root IS the <html> element, so 'html[data-theme="dark"] :root' would match
     * nothing at all.
     */
    public static function ToRootCss($userTokens)
    {
        $d = self::Derive($userTokens);
        return self::Block(':root', $d['light'])
            . ' ' . self::Block('html[data-theme="dark"]', $d['dark']);
    }
}
