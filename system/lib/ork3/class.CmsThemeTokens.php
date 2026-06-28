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
            $group = $catalog[$k]['input'];
            if ($group === 'color') {
                $val = strtolower(trim((string)$raw));
                if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $val)) {
                    $out[$k] = $val;
                }
            } elseif ($group === 'font') {
                if (in_array((string)$raw, self::FontAllowlist(), true)) {
                    $out[$k] = (string)$raw;
                }
            } elseif ($group === 'shadow') {
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
}
