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

// --- #accent-on-primary: dark must be recomputed against dark's own primary,
// not inherited from light, and both branches must clear normal-text AA (4.5),
// not just the 3.0 "large text" bar --fd-primary-contrast uses elsewhere.
// Regression pin: park 1049's actual seeded primary (a navy-purple, #151a5b)
// is the exact case Task 10's live-browser sweep caught failing — .pk-strip-link
// measured 2.77:1 and .pk-eyebrow 3.39:1 in dark before this fix, both of which
// read --fd-accent-on-primary as their text color against --fd-primary. ---
$dPark = CmsThemeTokens::Derive(array('--fd-primary' => '#151a5b'));
check(
    'accent-on-primary (light) clears normal-text AA for park 1049 hue',
    CmsThemeTokens::Contrast($dPark['light']['--fd-accent-on-primary'], $dPark['light']['--fd-primary']) >= 4.5
);
check(
    'accent-on-primary (dark) clears normal-text AA for park 1049 hue',
    CmsThemeTokens::Contrast($dPark['dark']['--fd-accent-on-primary'], $dPark['dark']['--fd-primary']) >= 4.5
);
// Recomputed independently, not copied: light and dark are allowed to (and, for
// this hue, DO) resolve to different colors, because they're judged against
// different primaries. A future regression that reintroduces `$dark = $light`
// for this token would collapse this back to the stale, pre-fix behavior.
check(
    'accent-on-primary is derived per-branch, not copied from light',
    CmsThemeTokens::Contrast($dPark['dark']['--fd-accent'], $dPark['dark']['--fd-primary'])
        !== CmsThemeTokens::Contrast($dPark['light']['--fd-accent'], $dPark['light']['--fd-primary'])
);

// --- ToCss: CSS emission ---
$css = CmsThemeTokens::ToCss(array('--fd-primary' => '#1b4d3e'));
check('emits .fd-page scope', strpos($css, '.fd-page{') !== false);
check('emits dark scope', strpos($css, 'html[data-theme="dark"] .fd-page{') !== false);
check('emits primary var', strpos($css, '--fd-primary:#1b4d3e') !== false);
check('font emitted with fallback', strpos($css, "--fd-font-body:'Open Sans'") !== false);
check('no raw braces injection from value', substr_count($css, '}') === 2);

// ===========================================================================
// #34 — deeper CmsThemeTokens coverage: color-math round-trips, WCAG reference
// pairs, Validate boundary/clamp on every non-color range, Validate/DefaultValues
// identity, the font-scale + shadow branches, and the Derive dark-mode contract
// converging across extreme colors.
// ===========================================================================

/** RGB channels within $tol of each other (float color math never lands exact). */
function rgbClose($hexA, $hexB, $tol = 2)
{
    $a = CmsThemeTokens::HexToRgb($hexA);
    $b = CmsThemeTokens::HexToRgb($hexB);
    return abs($a[0] - $b[0]) <= $tol && abs($a[1] - $b[1]) <= $tol && abs($a[2] - $b[2]) <= $tol;
}

// --- HexToHsl / HslToHex round-trip fidelity ---
foreach (array('#0b1120', '#f0b429', '#1b4d3e', '#ff0000', '#00ff00', '#0000ff', '#7f7f7f', '#123456', '#abcdef') as $hex) {
    list($h, $s, $l) = CmsThemeTokens::HexToHsl($hex);
    check("hsl round-trip $hex", rgbClose(CmsThemeTokens::HslToHex($h, $s, $l), $hex, 2));
}
// 3-digit shorthand expands correctly.
check('short hex #fff → white', CmsThemeTokens::HexToRgb('#fff') === array(255, 255, 255));
check('malformed hex → black fallback', CmsThemeTokens::HexToRgb('nope') === array(0, 0, 0));
// Grayscale: hue undefined (0), saturation 0.
list($gh, $gs, $gl) = CmsThemeTokens::HexToHsl('#808080');
check('gray saturation is 0', abs($gs) < 0.001);
check('gray lightness ~0.5', abs($gl - 0.5019) < 0.01);

// --- Luminance / Contrast reference values (WCAG) ---
check('luminance white == 1', abs(CmsThemeTokens::Luminance('#ffffff') - 1.0) < 0.0001);
check('luminance black == 0', CmsThemeTokens::Luminance('#000000') === 0.0);
check('contrast black/white == 21', abs(CmsThemeTokens::Contrast('#000000', '#ffffff') - 21.0) < 0.01);
check('contrast is symmetric', abs(CmsThemeTokens::Contrast('#123456', '#abcdef') - CmsThemeTokens::Contrast('#abcdef', '#123456')) < 0.0001);
check('contrast identical colors == 1', abs(CmsThemeTokens::Contrast('#336699', '#336699') - 1.0) < 0.0001);
// #767676 on white is the canonical WCAG AA boundary (~4.54:1).
check('contrast #767676/white ~4.5', abs(CmsThemeTokens::Contrast('#767676', '#ffffff') - 4.54) < 0.1);

// --- Validate: boundary / clamp on every numeric range ---
$hi = CmsThemeTokens::Validate(array(
    '--fd-radius'       => '999px',   // > max 24
    '--fd-font-scale'   => '9',       // > max 1.25
    '--fd-space'        => '5',        // > max 1.3
    '--fd-border-width' => '10px',    // > max 3
));
check('radius clamps to max', ($hi['--fd-radius'] ?? '') === '24px');
check('font-scale clamps to max', ($hi['--fd-font-scale'] ?? '') === '1.25');
check('space clamps to max', ($hi['--fd-space'] ?? '') === '1.3');
check('border-width clamps to max', ($hi['--fd-border-width'] ?? '') === '3px');

$lo = CmsThemeTokens::Validate(array(
    '--fd-radius'       => '-40px',   // < min 0
    '--fd-font-scale'   => '0',        // < min 0.9
    '--fd-space'        => '0.1',      // < min 0.85
    '--fd-border-width' => '-3px',    // < min 0
));
check('radius clamps to min', ($lo['--fd-radius'] ?? '') === '0px');
check('font-scale clamps to min', ($lo['--fd-font-scale'] ?? '') === '0.9');
check('space clamps to min', ($lo['--fd-space'] ?? '') === '0.85');
check('border-width clamps to min', ($lo['--fd-border-width'] ?? '') === '0px');
// px inputs are integer-rounded; scales keep up to 2dp trimmed.
$mid = CmsThemeTokens::Validate(array('--fd-radius' => '11.7px', '--fd-font-scale' => '1.005'));
check('radius rounds to int px', ($mid['--fd-radius'] ?? '') === '12px');
check('font-scale trims trailing zeros', ($mid['--fd-font-scale'] ?? '') === '1');

// --- Shadow branch ---
$sh = CmsThemeTokens::Validate(array('--fd-shadow' => '0 6px 24px rgba(0,0,0,.28)'));
check('allowlisted shadow kept', ($sh['--fd-shadow'] ?? '') === '0 6px 24px rgba(0,0,0,.28)');
$shBad = CmsThemeTokens::Validate(array('--fd-shadow' => '0 0 99px red'));
check('non-allowlist shadow dropped', !isset($shBad['--fd-shadow']));
$shNone = CmsThemeTokens::Validate(array('--fd-shadow' => 'none'));
check('shadow "none" kept', ($shNone['--fd-shadow'] ?? '') === 'none');

// --- Validate(DefaultValues()) == DefaultValues() (minus derived-only tokens) ---
// Derived tokens (input 'derived', e.g. --fd-primary-contrast) are auto-computed
// and intentionally dropped by Validate, so compare against defaults with those
// keys removed. Every OTHER default must survive Validate byte-for-byte.
$defs     = CmsThemeTokens::DefaultValues();
$expected = array();
foreach (CmsThemeTokens::Defaults() as $k => $meta) {
    if ($meta['input'] !== 'derived') {
        $expected[$k] = $meta['value'];
    }
}
check('Validate(DefaultValues()) is idempotent on stored tokens', CmsThemeTokens::Validate($defs) === $expected);

// --- ToCss: font-scale calc() + shadow + heading-font branches ---
$css2 = CmsThemeTokens::ToCss(array('--fd-font-scale' => '1.15', '--fd-font-heading' => 'MedievalSharp'));
check('font-scale emitted as calc()', strpos($css2, '--fd-font-scale:calc(1rem * 1.15)') !== false);
check('heading font emitted with cursive fallback', strpos($css2, "--fd-font-heading:'MedievalSharp', cursive") !== false);
check('shadow emitted verbatim', strpos($css2, '--fd-shadow:0 12px 50px rgba(0,0,0,.4)') !== false);
$cssGeorgia = CmsThemeTokens::ToCss(array('--fd-font-body' => 'Georgia'));
check('Georgia body emitted with serif fallback', strpos($cssGeorgia, "--fd-font-body:'Georgia', serif") !== false);
$cssSys = CmsThemeTokens::ToCss(array('--fd-font-body' => 'system-ui'));
check('system-ui body emitted without quotes', strpos($cssSys, '--fd-font-body:system-ui, sans-serif') !== false);

// --- Derive dark-mode contract across extreme colors (converges within budget) ---
$extremes = array('#ff0000', '#00ff00', '#0000ff', '#ffff00', '#00ffff', '#ff00ff', '#000000', '#ffffff', '#010203', '#fdfdfd', '#1b4d3e');
foreach ($extremes as $primary) {
    $dd = CmsThemeTokens::Derive(array('--fd-primary' => $primary, '--fd-accent' => $primary));
    $bodyC  = CmsThemeTokens::Contrast($dd['dark']['--fd-text'], $dd['dark']['--fd-bg']);
    $mutedC = CmsThemeTokens::Contrast($dd['dark']['--fd-text-muted'], $dd['dark']['--fd-bg']);
    check("dark body text >= 4.5 for $primary", $bodyC >= 4.5);
    check("dark muted text >= 3.0 for $primary", $mutedC >= 3.0);
    // primary-contrast is legible against the derived dark primary (AA large).
    $pc = CmsThemeTokens::Contrast($dd['dark']['--fd-primary-contrast'], $dd['dark']['--fd-primary']);
    check("dark primary-contrast >= 3.0 for $primary", $pc >= 3.0);
    // Light-mode primary-contrast is one of the two brand ink choices.
    check("light primary-contrast is a brand ink for $primary", in_array($dd['light']['--fd-primary-contrast'], array('#ffffff', '#1a2236'), true));
    // accent-on-primary: this loop sets accent == primary, so the accent can
    // never read against its own field (contrast 1.0) and BOTH branches must
    // fall back to their own primary-contrast — proving the fallback actually
    // triggers, not just that it exists. 3.0 (not 4.5) matches the documented,
    // pre-existing floor of the primary-contrast system itself (BestText can't
    // guarantee 4.5 at every hue once dark's primary is lightness-lifted to
    // >=0.55 — same limit the sibling "dark primary-contrast >= 3.0" check
    // above already accepts); a live sweep found the true worst case at 3.98.
    check("dark accent-on-primary falls back for $primary", $dd['dark']['--fd-accent-on-primary'] === $dd['dark']['--fd-primary-contrast']);
    check("light accent-on-primary falls back for $primary", $dd['light']['--fd-accent-on-primary'] === $dd['light']['--fd-primary-contrast']);
    $aopC = CmsThemeTokens::Contrast($dd['dark']['--fd-accent-on-primary'], $dd['dark']['--fd-primary']);
    check("dark accent-on-primary >= 3.0 for $primary", $aopC >= 3.0);
}

// --- Default heading face is not faux-medieval ----------------------------
// Defaults() is what the theme editor's "reset to default" writes back, so a
// MedievalSharp here quietly reinstates the exact default the CSS layer and the
// org seeder were both changed to get rid of — one click, no warning. It must
// stay PICKABLE, just not the default.
$dv = CmsThemeTokens::DefaultValues();
check('default heading font is Archivo, not MedievalSharp', ($dv['--fd-font-heading'] ?? '') === 'Archivo');
check('MedievalSharp is still selectable for orgs that want it', in_array('MedievalSharp', CmsThemeTokens::FontAllowlist(), true));
check('the default heading font is itself allowlisted', in_array($dv['--fd-font-heading'] ?? '', CmsThemeTokens::FontAllowlist(), true));

// --- ToRootCss: the token pair standalone org sites publish at :root ---------
// body/html are ANCESTORS of .fd-page and custom properties inherit downward
// only, so the .fd-page block ToCss() emits is invisible to cms-base.css's
// `body { background: var(--fd-bg) }`. ToRootCss() is the copy that fixes that.
$rootCss = CmsThemeTokens::ToRootCss(array('--fd-primary' => '#0b4d3e'));
check('ToRootCss emits a :root block', strpos($rootCss, ':root{') !== false);
check('ToRootCss emits an html[data-theme="dark"] block', strpos($rootCss, 'html[data-theme="dark"]{') !== false);
check('ToRootCss does not scope to .fd-page', strpos($rootCss, '.fd-page') === false);
// :root IS the <html> element, so a descendant combinator here would match
// nothing at all and the dark half would silently never apply.
check('ToRootCss dark selector is not a descendant of :root', strpos($rootCss, 'html[data-theme="dark"] :root') === false);
check('ToRootCss carries the real tokens', strpos($rootCss, '--fd-bg:') !== false && strpos($rootCss, '--fd-primary:') !== false);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
