<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ground-contrast guard.
 *
 * `Scroll::DARK_GROUNDS` is a hand-maintained list of the grounds dark enough that
 * `.Award`'s `color: #000000` (scrollery.css:388) has to be lifted to white. It is
 * accurate today — an earlier measurement pass sampled the text box of all twelve
 * grounds and found cyanotype at 1.88:1 against black type (correctly flipped to
 * 11.15:1 white), forge and arcane compositing through their soft-light washes to
 * ~12:1 (correctly NOT dark), and everything else in the 11-15:1 band. Nothing kept
 * it right. Add a thirteenth ground on a navy plate, forget the constant, and the
 * certificate silently ships 1.9:1 type — on a keepsake, on paper, discovered after
 * the ink is gone.
 *
 * This is the durable version of that measurement pass.
 *
 * ---------------------------------------------------------------------------------
 * WHICH ROUTE THIS TOOK, AND WHY
 *
 * The brief allowed either a live image-library computation or a precomputed
 * fixture. This does BOTH, keyed on content:
 *
 *   system/lib/ork3/data/scroll-ground-luminance.json records, per ground, the sha1 of the
 *   art file (and of the parchment underlay, for the wash grounds) alongside the
 *   composited text-box luminance.
 *
 *   - The membership assertion runs from the fixture, everywhere, with no image
 *     library at all. That is the assertion that actually catches the bug.
 *   - The fixture cannot go stale, because every run re-hashes the art on disk. If a
 *     hash moves — or a ground is added, removed or repointed — the fixture entry is
 *     rejected and the run fails with the regeneration command.
 *   - Regeneration needs GD (or Imagick); nothing else does. So a CI box without an
 *     image extension still runs the guard at full strength, and only a person who
 *     actually changed the artwork needs the extension.
 *
 * The alternative — recomputing all twelve grounds live on every run — decodes
 * ~100 MP of JPEG and takes about 3 s, against a 0.47 s whole-suite baseline. A guard
 * nobody will tolerate running is not a guard either.
 *
 * REGENERATE (after adding/changing a ground, or its art):
 *
 *   SCROLL_CONTRAST_REGEN=1 ENVIRONMENT=TEST \
 *       vendor/bin/phpunit --filter ScrollGroundContrast
 *
 * ---------------------------------------------------------------------------------
 * WHAT "THE TEXT BOX" MEANS HERE
 *
 * `.Award` is absolutely positioned over the whole 2550x3300 plate with
 * `padding: 20% 17% 13% 17%` (scrollery.css:392). CSS resolves percentage padding
 * against the containing block's WIDTH on all four sides, so on a 2550-wide page the
 * type occupies x[433.5, 2116.5], y[510, 2968.5]. Those four fractions are restated
 * as constants below; they are the one thing here coupled to a file this test does
 * not own, and the coupling is named so a change to that padding is findable.
 */
final class ScrollGroundContrastTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../system/lib/ork3/data/scroll-ground-luminance.json';

    /** The parchment plate every wash ground is laid over. */
    private const UNDERLAY = 'common/parchment.jpg';

    /** `.Award { padding: 20% 17% 13% 17% }` — all four resolve against page WIDTH. */
    private const PAD_TOP    = 0.20;
    private const PAD_RIGHT  = 0.17;
    private const PAD_BOTTOM = 0.13;
    private const PAD_LEFT   = 0.17;

    /**
     * Every ground is resized to this before measuring. A mean over a 1.68 Mpx region
     * is not meaningfully changed by area-averaging first, and it turns the per-pixel
     * blend math from 8.4 M iterations of PHP into 84 k.
     */
    private const SAMPLE_W = 255;
    private const SAMPLE_H = 330;

    /**
     * WCAG 1.4.3 normal-text contrast. The certificate's type is 75pt, so 3:1 (large
     * text) would pass — the stricter bar is used deliberately, because the measured
     * spread is 1.88:1 against 11.3:1 and there is no ground anywhere near 4.5.
     */
    private const MIN_CONTRAST = 4.5;

    /** Recomputation and stored value may differ by this much in relative luminance. */
    private const LUMINANCE_TOLERANCE = 0.01;

    /** @return iterable<string, array{string}> */
    public static function grounds(): iterable
    {
        foreach (array_keys(Scroll::Recipes()['grounds']) as $name) {
            yield $name => [$name];
        }
    }

    // ---------------------------------------------------------------------------
    // The guard proper
    // ---------------------------------------------------------------------------

    /**
     * The whole point of the file: a ground's composited text box must sit on the
     * side of the contrast line that its DARK_GROUNDS membership claims it does.
     */
    #[DataProvider('grounds')]
    public function testGroundToneMatchesItsDarkGroundsMembership(string $ground): void
    {
        $entry = $this->fixtureEntry($ground);
        $lum   = $entry['luminance'];

        $onBlack = self::contrast($lum, 0.0);
        $onWhite = self::contrast($lum, 1.0);
        $isDark  = in_array($ground, Scroll::DARK_GROUNDS, true);

        if ($isDark) {
            $this->assertLessThan(
                self::MIN_CONTRAST,
                $onBlack,
                sprintf(
                    "%s is in Scroll::DARK_GROUNDS but its text box reaches %.2f:1 against "
                    . "black type, which is legible. Either it does not belong in the list, "
                    . "or its art changed.",
                    $ground,
                    $onBlack
                )
            );
            $this->assertGreaterThanOrEqual(
                self::MIN_CONTRAST,
                $onWhite,
                sprintf(
                    "%s is treated as a dark ground, so scrollery.css lifts the type to "
                    . "#ffffff — but white on it measures only %.2f:1. It is a mid-tone: "
                    . "neither type colour is readable on it.",
                    $ground,
                    $onWhite
                )
            );
            return;
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_CONTRAST,
            $onBlack,
            sprintf(
                "%s is NOT in Scroll::DARK_GROUNDS, so .Award keeps color:#000000 — but "
                . "black type on its composited text box measures %.2f:1 (relative "
                . "luminance %.4f). Add '%s' to Scroll::DARK_GROUNDS (class.Scroll.php) "
                . "AND to the [data-ground] selector lists in scrollery.css, which spell "
                . "that constant out by hand.",
                $ground,
                $onBlack,
                $lum,
                $ground
            )
        );
    }

    /**
     * The second half of shipping a dark ground: `Scroll::DARK_GROUNDS` only tells
     * custom.tpl to set `data-ground`. What actually turns the type white is a
     * literal `[data-ground="cyanotype"]` selector in scrollery.css, whose own comment
     * says "a ground added to that constant needs a line here". Nothing enforced it.
     *
     * No image library, no fixture — just the two files agreeing.
     */
    public function testEveryDarkGroundIsSpelledOutInTheStylesheet(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 2) . '/orkui/template/revised-frontend/style/scrollery.css'
        );
        $this->assertIsString($css);

        foreach (Scroll::DARK_GROUNDS as $ground) {
            $this->assertStringContainsString(
                sprintf('[data-order="custom"][data-ground="%s"] .Award {', $ground),
                $css,
                sprintf(
                    "'%s' is in Scroll::DARK_GROUNDS but scrollery.css has no rule lifting "
                    . ".Award's colour on it, so the type would still print black.",
                    $ground
                )
            );
        }
    }

    /** A dark ground that is not in the vocabulary is a typo nobody would ever see. */
    public function testDarkGroundsAreAllRealGrounds(): void
    {
        $vocab = array_keys(Scroll::Recipes()['grounds']);
        foreach (Scroll::DARK_GROUNDS as $ground) {
            $this->assertContains($ground, $vocab, "DARK_GROUNDS names '{$ground}', which is not a ground");
        }
    }

    // ---------------------------------------------------------------------------
    // Fixture integrity — what stops the numbers above going stale
    // ---------------------------------------------------------------------------

    public function testFixtureCoversExactlyTheVocabulary(): void
    {
        $fixture = self::loadFixture();
        $vocab   = array_keys(Scroll::Recipes()['grounds']);

        sort($vocab);
        $recorded = array_keys($fixture['grounds']);
        sort($recorded);

        $this->assertSame(
            $vocab,
            $recorded,
            'system/lib/ork3/data/scroll-ground-luminance.json is out of step with the grounds '
            . 'vocabulary. Regenerate: SCROLL_CONTRAST_REGEN=1 ENVIRONMENT=TEST '
            . 'vendor/bin/phpunit --filter ScrollGroundContrast'
        );
    }

    /**
     * Re-hash the art on every run. This is what makes a precomputed fixture safe:
     * the recorded luminance is only trusted while the bytes it was measured from are
     * still the bytes on disk.
     */
    #[DataProvider('grounds')]
    public function testFixtureStillDescribesTheArtOnDisk(string $ground): void
    {
        $entry = $this->fixtureEntry($ground);
        $rel   = Scroll::Recipes()['grounds'][$ground];

        $this->assertSame($rel, $entry['file'], "{$ground} now points at {$rel}, not {$entry['file']}");
        $this->assertSame(
            self::hash($rel),
            $entry['sha1'],
            "The art behind ground '{$ground}' ({$rel}) has changed since its contrast was "
            . 'measured. Regenerate: SCROLL_CONTRAST_REGEN=1 ENVIRONMENT=TEST '
            . 'vendor/bin/phpunit --filter ScrollGroundContrast'
        );

        // A wash is composited over the parchment plate, so its measurement depends on
        // that file too.
        if (isset($entry['underlaySha1'])) {
            $this->assertSame(
                self::hash(self::UNDERLAY),
                $entry['underlaySha1'],
                'The parchment underlay has changed; every wash ground needs re-measuring.'
            );
        }
    }

    /**
     * Recompute from the pixels and confirm the fixture is telling the truth.
     *
     * Skipped without GD, which is the whole reason the fixture exists. Not skipped
     * on the machine of anyone who just changed the art — they need GD to regenerate
     * anyway, so this runs for them.
     */
    #[DataProvider('grounds')]
    public function testRecomputingFromThePixelsAgreesWithTheFixture(string $ground): void
    {
        if (!function_exists('imagecreatefromjpeg')) {
            $this->markTestSkipped('GD is not loaded; the checked-in fixture carries the measurement.');
        }

        $expected = $this->fixtureEntry($ground)['luminance'];
        $actual   = self::measure($ground);

        $this->assertEqualsWithDelta(
            $expected,
            $actual,
            self::LUMINANCE_TOLERANCE,
            "Recomputed luminance for '{$ground}' disagrees with the fixture."
        );
    }

    // ---------------------------------------------------------------------------
    // The guard's own guard.
    //
    // A test that cannot fail is not a test. This feeds the classifier a ground that
    // IS dark while pretending it was never declared dark, and asserts the classifier
    // rejects it — the exact shape of "someone added a navy plate and forgot the
    // constant", proven without touching the real vocabulary.
    // ---------------------------------------------------------------------------

    public function testTheGuardRejectsAnUndeclaredDarkGround(): void
    {
        $darkest = null;
        foreach (Scroll::DARK_GROUNDS as $ground) {
            $darkest = $this->fixtureEntry($ground)['luminance'];
            break;
        }
        $this->assertNotNull($darkest, 'DARK_GROUNDS is empty; nothing to prove the guard with.');

        // Undeclared: the branch a non-dark ground takes.
        $this->assertLessThan(
            self::MIN_CONTRAST,
            self::contrast($darkest, 0.0),
            'A ground measured dark enough to need white type still passes the black-type '
            . 'assertion. The guard would not catch a mis-declared ground.'
        );
    }

    // ---------------------------------------------------------------------------
    // Regeneration
    // ---------------------------------------------------------------------------

    /**
     * Rewrites the fixture from the art on disk. Inert unless SCROLL_CONTRAST_REGEN=1,
     * so a normal run never touches the file.
     */
    public function testRegenerateFixture(): void
    {
        if (getenv('SCROLL_CONTRAST_REGEN') !== '1') {
            $this->markTestSkipped(
                'Set SCROLL_CONTRAST_REGEN=1 to rewrite system/lib/ork3/data/scroll-ground-luminance.json.'
            );
        }
        if (!function_exists('imagecreatefromjpeg')) {
            $this->fail('Regenerating the contrast fixture requires GD.');
        }

        $out = [];
        foreach (array_keys(Scroll::Recipes()['grounds']) as $ground) {
            $rel = Scroll::Recipes()['grounds'][$ground];
            $lum = self::measure($ground);
            $row = [
                'file'      => $rel,
                'sha1'      => self::hash($rel),
                'wash'      => Scroll::GROUND_WASHES[$ground] ?? null,
                'luminance' => round($lum, 6),
                'contrastOnBlack' => round(self::contrast($lum, 0.0), 3),
                'contrastOnWhite' => round(self::contrast($lum, 1.0), 3),
                'dark'      => in_array($ground, Scroll::DARK_GROUNDS, true),
            ];
            if (isset(Scroll::GROUND_WASHES[$ground])) {
                $row['underlaySha1'] = self::hash(self::UNDERLAY);
            }
            $out[$ground] = $row;
        }

        $payload = [
            '_comment' => 'Generated by ScrollGroundContrastTest::testRegenerateFixture. '
                . 'SCROLL_CONTRAST_REGEN=1 ENVIRONMENT=TEST vendor/bin/phpunit --filter ScrollGroundContrast',
            '_method' => sprintf(
                'Mean WCAG relative luminance over the .Award text box (padding %.0f%%/%.0f%%/%.0f%%/%.0f%% '
                . 'of page width), sampled at %dx%d, wash grounds composited over %s with their '
                . 'Scroll::GROUND_WASHES declaration.',
                self::PAD_TOP * 100,
                self::PAD_RIGHT * 100,
                self::PAD_BOTTOM * 100,
                self::PAD_LEFT * 100,
                self::SAMPLE_W,
                self::SAMPLE_H,
                self::UNDERLAY
            ),
            'grounds' => $out,
        ];

        file_put_contents(
            self::FIXTURE,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------------
    // Measurement
    // ---------------------------------------------------------------------------

    /** Mean WCAG relative luminance of the composited text box, 0..1. */
    private static function measure(string $ground): float
    {
        $recipes = Scroll::Recipes();
        $layer   = self::sample($recipes['grounds'][$ground]);
        $wash    = Scroll::GROUND_WASHES[$ground] ?? null;

        if ($wash !== null) {
            $layer = self::applyWash(self::sample(self::UNDERLAY), $layer, $wash);
        }

        // Text box, in sample space. Percentage padding resolves against the containing
        // block's WIDTH on all four sides, so the top and bottom insets are fractions of
        // 2550, not 3300. SAMPLE_W x SAMPLE_H holds the page's 2550x3300 aspect exactly,
        // so page pixels and sample pixels share one scale factor in both axes and a
        // fraction of page width is the same fraction of SAMPLE_W vertically too.
        $x0 = (int) round(self::PAD_LEFT * self::SAMPLE_W);
        $x1 = self::SAMPLE_W - (int) round(self::PAD_RIGHT * self::SAMPLE_W);
        $y0 = (int) round(self::PAD_TOP * self::SAMPLE_W);
        $y1 = self::SAMPLE_H - (int) round(self::PAD_BOTTOM * self::SAMPLE_W);

        $sum = 0.0;
        $n   = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                [$r, $g, $b] = $layer[$y][$x];
                $sum += self::relativeLuminance($r, $g, $b);
                $n++;
            }
        }

        return $n > 0 ? $sum / $n : 0.0;
    }

    /**
     * Decode an asset, area-average it down to SAMPLE_W x SAMPLE_H, and return it as
     * a row-major array of [r,g,b] unit triples.
     *
     * @return array<int, array<int, array{float, float, float}>>
     */
    private static function sample(string $rel): array
    {
        $path = DIR_ASSETS . 'scrolls/' . $rel;
        if (!is_file($path)) {
            throw new RuntimeException("Ground art missing: {$path}");
        }

        $src = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => imagecreatefromjpeg($path),
            'png'         => imagecreatefrompng($path),
            'webp'        => imagecreatefromwebp($path),
            default       => throw new RuntimeException("Unsupported ground format: {$rel}"),
        };
        if ($src === false) {
            throw new RuntimeException("Could not decode {$path}");
        }

        $dst = imagecreatetruecolor(self::SAMPLE_W, self::SAMPLE_H);
        imagecopyresampled(
            $dst,
            $src,
            0,
            0,
            0,
            0,
            self::SAMPLE_W,
            self::SAMPLE_H,
            imagesx($src),
            imagesy($src)
        );
        imagedestroy($src);

        $out = [];
        for ($y = 0; $y < self::SAMPLE_H; $y++) {
            $row = [];
            for ($x = 0; $x < self::SAMPLE_W; $x++) {
                $rgb   = imagecolorat($dst, $x, $y);
                $row[] = [
                    (($rgb >> 16) & 0xFF) / 255,
                    (($rgb >> 8) & 0xFF) / 255,
                    ($rgb & 0xFF) / 255,
                ];
            }
            $out[] = $row;
        }
        imagedestroy($dst);

        return $out;
    }

    /**
     * Composite a wash over the parchment exactly as its `Scroll::GROUND_WASHES`
     * declaration does.
     *
     * The declaration is a CSS fragment — `mix-blend-mode:`, `opacity:` and, for
     * rosewash, `filter:hue-rotate()` — parsed here rather than hardcoded, so a change
     * to the constant is picked up instead of quietly diverging. CSS blending happens
     * on gamma-encoded sRGB values, which is what these arrays hold.
     *
     * @param array<int, array<int, array{float, float, float}>> $backdrop
     * @param array<int, array<int, array{float, float, float}>> $source
     * @return array<int, array<int, array{float, float, float}>>
     */
    private static function applyWash(array $backdrop, array $source, string $declaration): array
    {
        preg_match('/mix-blend-mode:\s*([a-z-]+)/', $declaration, $m);
        $mode = $m[1] ?? 'normal';

        preg_match('/opacity:\s*([0-9.]+)/', $declaration, $m);
        $alpha = isset($m[1]) ? (float) $m[1] : 1.0;

        preg_match('/hue-rotate\(\s*(-?[0-9.]+)deg\s*\)/', $declaration, $m);
        $hue = isset($m[1]) ? (float) $m[1] : 0.0;

        $out = [];
        foreach ($backdrop as $y => $row) {
            $line = [];
            foreach ($row as $x => $cb) {
                $cs = $source[$y][$x];
                if ($hue !== 0.0) {
                    $cs = self::hueRotate($cs, $hue);
                }
                $blended = [];
                for ($i = 0; $i < 3; $i++) {
                    $b = match ($mode) {
                        'multiply'   => $cb[$i] * $cs[$i],
                        'screen'     => 1 - (1 - $cb[$i]) * (1 - $cs[$i]),
                        'soft-light' => self::softLight($cb[$i], $cs[$i]),
                        default      => $cs[$i],
                    };
                    // Both layers are opaque; `opacity` on the source is a simple lerp.
                    $blended[] = (1 - $alpha) * $cb[$i] + $alpha * $b;
                }
                $line[] = $blended;
            }
            $out[] = $line;
        }

        return $out;
    }

    /** W3C compositing-1 soft-light, on gamma-encoded values. */
    private static function softLight(float $cb, float $cs): float
    {
        if ($cs <= 0.5) {
            return $cb - (1 - 2 * $cs) * $cb * (1 - $cb);
        }
        $d = $cb <= 0.25 ? ((16 * $cb - 12) * $cb + 4) * $cb : sqrt($cb);

        return $cb + (2 * $cs - 1) * ($d - $cb);
    }

    /**
     * The `filter: hue-rotate()` matrix from Filter Effects 1, which is what a browser
     * applies for rosewash's `filter:hue-rotate(80deg)`.
     *
     * @param array{float, float, float} $c
     * @return array{float, float, float}
     */
    private static function hueRotate(array $c, float $degrees): array
    {
        $a = deg2rad($degrees);
        $cosv = cos($a);
        $sinv = sin($a);

        $m = [
            [0.213 + $cosv * 0.787 - $sinv * 0.213, 0.715 - $cosv * 0.715 - $sinv * 0.715, 0.072 - $cosv * 0.072 + $sinv * 0.928],
            [0.213 - $cosv * 0.213 + $sinv * 0.143, 0.715 + $cosv * 0.285 + $sinv * 0.140, 0.072 - $cosv * 0.072 - $sinv * 0.283],
            [0.213 - $cosv * 0.213 - $sinv * 0.787, 0.715 - $cosv * 0.715 + $sinv * 0.715, 0.072 + $cosv * 0.928 + $sinv * 0.072],
        ];

        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $v     = $m[$i][0] * $c[0] + $m[$i][1] * $c[1] + $m[$i][2] * $c[2];
            $out[] = max(0.0, min(1.0, $v));
        }

        /** @var array{float, float, float} $out */
        return $out;
    }

    /** WCAG 2.x relative luminance from gamma-encoded sRGB unit values. */
    private static function relativeLuminance(float $r, float $g, float $b): float
    {
        $lin = static fn (float $c): float => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /** WCAG contrast ratio between a ground of relative luminance $l and type of $type. */
    private static function contrast(float $l, float $typeLuminance): float
    {
        $hi = max($l, $typeLuminance);
        $lo = min($l, $typeLuminance);

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function hash(string $rel): string
    {
        return sha1_file(DIR_ASSETS . 'scrolls/' . $rel) ?: '';
    }

    /** @return array<string, mixed> */
    private static function loadFixture(): array
    {
        if (!is_file(self::FIXTURE)) {
            throw new RuntimeException(
                'system/lib/ork3/data/scroll-ground-luminance.json is missing. Regenerate: '
                . 'SCROLL_CONTRAST_REGEN=1 ENVIRONMENT=TEST vendor/bin/phpunit --filter ScrollGroundContrast'
            );
        }
        $data = json_decode((string) file_get_contents(self::FIXTURE), true);

        return is_array($data) ? $data : [];
    }

    /** @return array<string, mixed> */
    private function fixtureEntry(string $ground): array
    {
        $fixture = self::loadFixture();
        $this->assertArrayHasKey(
            $ground,
            $fixture['grounds'] ?? [],
            "Ground '{$ground}' has no measured contrast. A new ground must be measured before "
            . 'it can ship: SCROLL_CONTRAST_REGEN=1 ENVIRONMENT=TEST vendor/bin/phpunit '
            . '--filter ScrollGroundContrast'
        );

        return $fixture['grounds'][$ground];
    }
}
