<?php

declare(strict_types=1);

/**
 * Regenerates the synthetic heraldry fixtures ScrollHeraldryKeyTest reads.
 *
 *     php tests/fixtures/heraldry/make-fixtures.php
 *
 * Every image is drawn here with GD: none of them is anyone's real heraldry. They are
 * small on purpose (a few KB each) and committed, so the tests never depend on GD's
 * encoder producing identical bytes across versions.
 *
 * - shield-on-black.jpg   a gold heater shield on an opaque black square (the B6 case),
 *                         saved as JPEG so its black carries real compression noise.
 *                         Inside the shield sits a black roundel: dark art that does NOT
 *                         touch a corner and must survive keying.
 * - dark-field.jpg        a near-black field running to every edge with a small gold
 *                         charge: the corner-connected dark is well over 60% of the image.
 * - line-art-on-black.png white open line art on black: again well over 60% dark, all of
 *                         it one ground connected to the corners.
 * - transparent-shield.png the same shield on a transparent ground (already keyed).
 * - silhouette-through-gap.png a black silhouette on a white panel, joined to the black
 *                         ground by a thin neck: corner-connected, inside the 5-60%
 *                         bounds, but dark ART that no straight ray from an edge reaches.
 */

$dir = __DIR__ . '/';

/** A heater shield's outline in a w x h canvas, inset by $m. */
function shield_points(int $w, int $h, int $m): array
{
    $l = $m;
    $r = $w - 1 - $m;
    $t = $m;
    $mid = (int) round($h * 0.55);
    $cx = (int) round(($w - 1) / 2);
    $pts = [$l, $t, $r, $t, $r, $mid];
    // Curve each flank down to the point.
    for ($i = 1; $i <= 12; $i++) {
        $f = $i / 12;
        $pts[] = (int) round($r - ($r - $cx) * (1 - cos($f * M_PI / 2)));
        $pts[] = (int) round($mid + ($h - 1 - $m - $mid) * sin($f * M_PI / 2));
    }
    for ($i = 11; $i >= 0; $i--) {
        $f = $i / 12;
        $pts[] = (int) round($l + ($cx - $l) * (1 - cos($f * M_PI / 2)));
        $pts[] = (int) round($mid + ($h - 1 - $m - $mid) * sin($f * M_PI / 2));
    }

    return $pts;
}

function draw_shield($im, int $w, int $h, int $m): void
{
    $gold  = imagecolorallocate($im, 222, 178, 40);
    $green = imagecolorallocate($im, 40, 120, 50);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefilledpolygon($im, shield_points($w, $h, $m), $gold);
    // A green chevron, and a black roundel well inside the shield.
    imagefilledpolygon($im, [(int) ($w * 0.25), (int) ($h * 0.65), (int) ($w * 0.5), (int) ($h * 0.45), (int) ($w * 0.75), (int) ($h * 0.65), (int) ($w * 0.5), (int) ($h * 0.55)], $green);
    imagefilledellipse($im, (int) ($w * 0.5), (int) ($h * 0.30), (int) ($w * 0.18), (int) ($w * 0.18), $black);
}

// 1. Shield on a black square.
$w = 200;
$h = 240;
$im = imagecreatetruecolor($w, $h);
imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
draw_shield($im, $w, $h, 34);
imagejpeg($im, $dir . 'shield-on-black.jpg', 85);

// 2. A dark field to every edge with a small charge.
$im = imagecreatetruecolor($w, $h);
imagefill($im, 0, 0, imagecolorallocate($im, 12, 12, 14));
imagefilledellipse($im, 100, 120, 70, 70, imagecolorallocate($im, 222, 178, 40));
imagejpeg($im, $dir . 'dark-field.jpg', 85);

// 3. White line art on black.
$im = imagecreatetruecolor($w, $h);
imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
$white = imagecolorallocate($im, 255, 255, 255);
imagesetthickness($im, 4);
// Open strokes only: no closed shape, so all the black is one ground.
imageline($im, 100, 50, 100, 190, $white);
imageline($im, 50, 170, 100, 130, $white);
imageline($im, 100, 130, 150, 170, $white);
imageline($im, 60, 100, 140, 100, $white);
imagepng($im, $dir . 'line-art-on-black.png');

// 4. The shield on a transparent ground.
$im = imagecreatetruecolor($w, $h);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);
draw_shield($im, $w, $h, 34);
imagealphablending($im, false);
imagepng($im, $dir . 'transparent-shield.png');

// 5. A black silhouette on a white panel, joined to the black ground by a thin neck.
$im = imagecreatetruecolor($w, $h);
$black = imagecolorallocate($im, 0, 0, 0);
imagefill($im, 0, 0, $black);
imagefilledrectangle($im, 16, 16, 183, 223, imagecolorallocate($im, 245, 245, 245));
imagefilledrectangle($im, 98, 16, 101, 90, $black);
imagefilledellipse($im, 100, 130, 110, 90, $black);
imagepng($im, $dir . 'silhouette-through-gap.png');

echo "fixtures written to {$dir}\n";
