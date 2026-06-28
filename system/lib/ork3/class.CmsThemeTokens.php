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
}
