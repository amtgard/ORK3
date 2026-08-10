<?php

// tests/cms-heraldry-color/color_test.php — run: php tests/cms-heraldry-color/color_test.php
//
// CmsHeraldryColor turns a park's heraldry file into the single --fd-primary its
// whole site is themed from. Pure GD + math: no DB, no request state (CmsSanitizer
// is the precedent), so it runs in a bare `php` process.
//
// The clamps are the load-bearing part. A device sampled raw yields muddy browns and
// neon primaries in equal measure; the floor kills mud, the ceiling kills neon, and
// the forced lightness guarantees white text clears 7:1 on the resulting field.

require_once __DIR__ . '/../../system/lib/ork3/class.CmsThemeTokens.php';
require_once __DIR__ . '/../../system/lib/ork3/class.CmsHeraldryColor.php';

$fails = 0;
function check($label, $cond)
{
    global $fails;
    echo($cond ? "PASS  $label\n" : "FAIL  $label\n");
    if (!$cond) {
        $fails++;
    }
}

/** Build a temp PNG that is mostly one colour, with noise GD must ignore. */
function make_png($path, $r, $g, $b)
{
    $im = imagecreatetruecolor(60, 60);
    imagefilledrectangle($im, 0, 0, 59, 59, imagecolorallocate($im, $r, $g, $b));
    // Near-white and near-black corners: the extractor must skip both.
    imagefilledrectangle($im, 0, 0, 9, 9, imagecolorallocate($im, 252, 252, 252));
    imagefilledrectangle($im, 50, 50, 59, 59, imagecolorallocate($im, 3, 3, 3));
    imagepng($im, $path);
    imagedestroy($im);
}

$tmp = sys_get_temp_dir() . '/ogre-heraldry-test';
@mkdir($tmp, 0777, true);

// --- Extraction picks the dominant hue, not the white/black noise ---
$redFile = $tmp . '/red.png';
make_png($redFile, 170, 30, 40);
$red = CmsHeraldryColor::FromFile($redFile);
check('FromFile returns a hex colour', preg_match('/^#[0-9a-f]{6}$/', $red) === 1);

$hsl = CmsThemeTokens::HexToHsl($red);
check('extracted hue is red-ish (330-360 or 0-20)', $hsl[0] >= 330 || $hsl[0] <= 20);
check('white corner did not win (result is not near-white)', $hsl[2] < 0.5);
check('black corner did not win (result is not near-black)', $hsl[2] > 0.05);

// --- Clamps ---
$greenFile = $tmp . '/green.png';
make_png($greenFile, 20, 120, 60);
$green = CmsHeraldryColor::FromFile($greenFile);
$ghsl  = CmsThemeTokens::HexToHsl($green);
check('lightness is forced to the deep-field value 0.22', abs($ghsl[2] - 0.22) < 0.02);
check('saturation is clamped into [0.30, 0.62]', $ghsl[1] >= 0.29 && $ghsl[1] <= 0.63);

// A near-grey device must not produce a muddy field — the floor lifts it.
$greyFile = $tmp . '/grey.png';
make_png($greyFile, 128, 126, 130);
$greyHsl = CmsThemeTokens::HexToHsl(CmsHeraldryColor::FromFile($greyFile));
check('a near-grey device is lifted to the saturation floor', $greyHsl[1] >= 0.29);

// A neon device must not stay neon — the ceiling pulls it down.
$neonFile = $tmp . '/neon.png';
make_png($neonFile, 0, 255, 0);
$neonHsl = CmsThemeTokens::HexToHsl(CmsHeraldryColor::FromFile($neonFile));
check('a neon device is pulled to the saturation ceiling', $neonHsl[1] <= 0.63);

// --- White text must always clear 7:1 on the field. That is why L is forced. ---
// Sweep EVERY hue at both saturation limits, not a few sampled colours: this is the
// invariant the hero relies on to use white type with no per-park contrast check.
// The margin is thin — the worst case is ~7.11:1 at hue 60 (yellow) — so raising
// SAT_MAX or FIELD_L breaks it. This test is what catches that.
$worst = 99.0;
$worstAt = -1;
for ($h = 0; $h < 360; $h += 5) {
    foreach (array(CmsHeraldryColor::SAT_MIN, 0.46, CmsHeraldryColor::SAT_MAX) as $s) {
        $field = CmsThemeTokens::HslToHex($h, $s, CmsHeraldryColor::FIELD_L);
        $c = CmsThemeTokens::Contrast('#ffffff', $field);
        if ($c < $worst) {
            $worst = $c;
            $worstAt = $h;
        }
    }
}
check(
    sprintf('white clears 7:1 on EVERY hue (worst %.2f:1 at hue %d)', $worst, $worstAt),
    $worst >= 7.0
);

// --- Failure modes must be silent and typed, never fatal ---
check('a missing file returns empty string', CmsHeraldryColor::FromFile($tmp . '/nope.png') === '');
check('a non-image file returns empty string', (function () use ($tmp) {
    file_put_contents($tmp . '/junk.png', 'not an image');
    return CmsHeraldryColor::FromFile($tmp . '/junk.png') === '';
})());
check('an empty path returns empty string', CmsHeraldryColor::FromFile('') === '');

// --- Name fallback is deterministic and well-formed ---
$a1 = CmsHeraldryColor::FromName("Angler's Rift");
$a2 = CmsHeraldryColor::FromName("Angler's Rift");
$b1 = CmsHeraldryColor::FromName('Granite Spyre');
check('FromName is deterministic', $a1 === $a2);
check('FromName differs for different names', $a1 !== $b1);
check('FromName returns a hex colour', preg_match('/^#[0-9a-f]{6}$/', $a1) === 1);
check('FromName obeys the same lightness clamp', abs(CmsThemeTokens::HexToHsl($a1)[2] - 0.22) < 0.02);
check('FromName never returns the old default green', CmsThemeTokens::HexToHsl($a1)[0] !== 153);

array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
