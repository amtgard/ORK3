<?php

// tests/cms-theme/tokens_test.php — run: php tests/cms-theme/tokens_test.php
require __DIR__ . '/../../system/lib/ork3/class.CmsThemeTokens.php';

$fails = 0;
function check($label, $cond)
{
    global $fails;
    if ($cond) {
        echo "PASS  $label\n";
    } else {
        echo "FAIL  $label\n";
        $fails++;
    }
}

// --- Catalog / defaults ---
$def = CmsThemeTokens::Defaults();
check('Defaults has --fd-primary', isset($def['--fd-primary']));
check('primary default is navy', ($def['--fd-primary']['value'] ?? null) === '#0b1120');
check('DefaultValues flattens', CmsThemeTokens::DefaultValues()['--fd-accent'] === '#f0b429');
check('font allowlist has Open Sans', in_array('Open Sans', CmsThemeTokens::FontAllowlist(), true));

// --- Validate token input ---
$v = CmsThemeTokens::Validate(array(
  '--fd-primary'    => '#0B4D3E',
  '--fd-accent'     => 'red; }',          // invalid → dropped
  '--fd-font-body'  => 'Comic Sans',      // not allowlisted → dropped
  '--fd-font-heading' => 'Lexend',         // ok
  '--fd-radius'     => '999px',           // clamped to max 24px
  '--fd-font-scale' => '1.1',             // ok
  'evil'            => 'x',               // unknown → dropped
));
check('valid hex kept (lowercased)', ($v['--fd-primary'] ?? '') === '#0b4d3e');
check('css-injection value dropped', !isset($v['--fd-accent']));
check('non-allowlist font dropped', !isset($v['--fd-font-body']));
check('allowlist font kept', ($v['--fd-font-heading'] ?? '') === 'Lexend');
check('radius clamped to 24px', ($v['--fd-radius'] ?? '') === '24px');
check('unknown key dropped', !isset($v['evil']));

// --- Derive: light/dark token maps ---
$d = CmsThemeTokens::Derive(array('--fd-primary' => '#1b4d3e', '--fd-radius' => '6px'));
check('light keeps user primary', $d['light']['--fd-primary'] === '#1b4d3e');
check('light bg stays default white', $d['light']['--fd-bg'] === '#ffffff');
check('dark bg is dark (low luminance)', CmsThemeTokens::Luminance($d['dark']['--fd-bg']) < 0.15);
check('dark text is light (high luminance)', CmsThemeTokens::Luminance($d['dark']['--fd-text']) > 0.6);
check('shape passes through to dark', $d['dark']['--fd-radius'] === '6px');
check('primary-contrast computed for light', in_array($d['light']['--fd-primary-contrast'], array('#ffffff', '#1a2236'), true));
check('dark text/bg contrast >= 4.5', CmsThemeTokens::Contrast($d['dark']['--fd-text'], $d['dark']['--fd-bg']) >= 4.5);
// hue preserved: a green primary stays greener than red in dark
$h = CmsThemeTokens::HexToHsl($d['dark']['--fd-primary']);
check('primary hue preserved (green-ish)', $h[0] > 90 && $h[0] < 180);

// --- ToCss: CSS emission ---
$css = CmsThemeTokens::ToCss(array('--fd-primary' => '#1b4d3e'));
check('emits .fd-page scope', strpos($css, '.fd-page{') !== false);
check('emits dark scope', strpos($css, 'html[data-theme="dark"] .fd-page{') !== false);
check('emits primary var', strpos($css, '--fd-primary:#1b4d3e') !== false);
check('font emitted with fallback', strpos($css, "--fd-font-body:'Open Sans'") !== false);
check('no raw braces injection from value', substr_count($css, '}') === 2);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
