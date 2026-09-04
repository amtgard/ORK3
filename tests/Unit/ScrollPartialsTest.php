<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollPartialsTest extends TestCase
{
    private function scroll(array $overrides = []): array
    {
        return array_merge([
            'order' => 'griffon', 'rank' => 3, 'variant' => '',
            'title' => 'Order of the Griffin', 'player' => 'Testerly',
            'pronoun' => 'her', 'reason' => 'valour', 'date' => '13th May 2024',
            'givenBy' => 'Sir Someone', 'issuerTitle' => 'Barony',
            'issuerName' => 'Rising Sun Station', 'useThe' => true, 'useOf' => true,
            'heraldry' => '/assets/heraldry/park/00435.png',
            'flavour' => 'flavour text', 'primary' => '#8d1b3d', 'secondary' => '#d4af37',
            'resources' => Scroll::DEFAULT_RESOURCES,
        ], $overrides);
    }

    private function render(string $name, array $vars): string
    {
        // finally: a partial that throws must not leave the buffer open, or every
        // later assertion in the file writes into a dangling buffer and the real
        // exception is never seen.
        ob_start();
        try {
            scroll_partial($name, $vars);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    public function testPartialsDoNotLeakVariablesBetweenCalls(): void
    {
        $first  = $this->render('border', ['primary' => '#111111', 'secondary' => '#222222', 'griffons' => true]);
        $second = $this->render('border', ['primary' => '#333333', 'secondary' => '#444444']);

        $this->assertStringContainsString('#111111', $first);
        $this->assertStringContainsString('#333333', $second);
        $this->assertStringNotContainsString('#111111', $second);
    }

    public function testBorderDrawsGriffonsOnlyWhenAsked(): void
    {
        $with    = $this->render('border', ['primary' => '#111', 'secondary' => '#222', 'griffons' => true]);
        $without = $this->render('border', ['primary' => '#111', 'secondary' => '#222']);

        $this->assertStringContainsString('id="Griffons"', $with);
        $this->assertStringNotContainsString('id="Griffons"', $without);
    }

    /**
     * The griffon geometry is roughly half the border partial. Omitting it must
     * actually omit it — hiding it in CSS would put ~47 KB of unused paths into the
     * DOM of all ten orders that compose this partial.
     */
    public function testOmittingGriffonsOmitsTheirGeometryNotJustTheirVisibility(): void
    {
        $with    = $this->render('border', ['primary' => '#111', 'secondary' => '#222', 'griffons' => true]);
        $without = $this->render('border', ['primary' => '#111', 'secondary' => '#222']);

        $this->assertGreaterThan(40000, strlen($with) - strlen($without));
    }

    /**
     * Aurelia's css= binding merged into an element's existing style=. Transliterating
     * css= to a second style attribute instead makes the browser keep the first and
     * drop the second, silently. It bit twice: the border's root <svg> would have lost
     * fill-rule:evenodd — filling in every hole in the border paths — and the reason
     * section would have lost its margins. Neither throws; both just render wrong.
     */
    public function testNoElementCarriesTwoStyleAttributes(): void
    {
        $stack = $this->render('parchment', [])
            . $this->render('border', ['primary' => '#111', 'secondary' => '#222', 'griffons' => true])
            . $this->render('heraldry', ['s' => $this->scroll()])
            . $this->render('text', ['s' => $this->scroll()]);

        preg_match_all('/<[a-zA-Z][^>]*>/s', $stack, $tags);
        $duplicated = array_values(array_filter(
            $tags[0],
            static fn (string $tag): bool => substr_count($tag, 'style=') > 1
        ));

        $this->assertSame([], $duplicated);
        $this->assertStringContainsString('fill-rule:evenodd', $stack);
    }

    public function testHeraldryUsesDefaultsAndAcceptsOverrides(): void
    {
        $default = $this->render('heraldry', ['s' => $this->scroll()]);
        $this->assertStringContainsString('width="420"', $default);
        $this->assertStringContainsString('height="420"', $default);
        $this->assertStringContainsString('margin-top:50px', $default);
        $this->assertStringContainsString('mix-blend-mode: multiply', $default);

        $sized = $this->render('heraldry', ['s' => $this->scroll(), 'width' => 370, 'height' => 460, 'top' => 5]);
        $this->assertStringContainsString('width="370"', $sized);
        $this->assertStringContainsString('margin-top:5px', $sized);
    }

    public function testHeraldryTopOfZeroIsHonouredNotTreatedAsMissing(): void
    {
        $zero = $this->render('heraldry', ['s' => $this->scroll(), 'top' => 0]);
        $this->assertStringContainsString('margin-top:0px', $zero);
    }

    public function testHeraldryIsOmittedEntirelyWhenThereIsNoImage(): void
    {
        $none = $this->render('heraldry', ['s' => $this->scroll(['heraldry' => ''])]);
        $this->assertStringNotContainsString('<img', $none);
    }

    public function testTextLayerRendersTheAwardFields(): void
    {
        $html = $this->render('text', ['s' => $this->scroll()]);

        $this->assertStringContainsString('Order of the Griffin', $html);
        $this->assertStringContainsString('Testerly', $html);
        $this->assertStringContainsString('with her third', $html);
        $this->assertStringContainsString('valour', $html);
        $this->assertStringContainsString('13th May 2024', $html);
        $this->assertStringContainsString('Sir Someone', $html);
        $this->assertStringContainsString('and the Barony of', $html);
        $this->assertStringContainsString('Rising Sun Station', $html);
    }

    public function testTextLayerEscapesUserSuppliedContent(): void
    {
        $html = $this->render('text', ['s' => $this->scroll([
            'player' => '<script>alert(1)</script>',
            'reason' => 'a & b',
        ])]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('a &amp; b', $html);
    }

    /**
     * The reason and player font sizes scale with string length, and that length
     * reaches the markup through an inline calc(). A quote in a persona must not be
     * able to close the style attribute.
     */
    public function testLengthDrivenFontSizesAreNumericAndUnescapable(): void
    {
        $html = $this->render('text', ['s' => $this->scroll([
            'player' => 'Ru"><script>alert(1)</script>',
            'reason' => str_repeat('a', 240),
        ])]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertMatchesRegularExpression('/font-size:max\(1\.36em, calc\(2\.55em \+ -0\.374em \* \(\(29 \/ 8\)\)\)\)/', $html);
        $this->assertStringContainsString('(240 / 150)', $html);
    }

    public function testRankZeroDrawsABlankRatherThanAnOrdinal(): void
    {
        $html = $this->render('text', ['s' => $this->scroll(['rank' => 0])]);
        $this->assertStringNotContainsString('with her first', $html);
        $this->assertStringContainsString('writing-line.svg', $html);
    }

    /**
     * An empty reason falls back to a length of 200 in the size calc, exactly as
     * Arcanyst's `${award.reason.length ? award.reason.length : 200}` did — not to 0,
     * which would compute a font size far larger than the box.
     */
    public function testEmptyReasonUsesTheFallbackLength(): void
    {
        $html = $this->render('text', ['s' => $this->scroll(['reason' => ''])]);
        $this->assertStringContainsString('(200 / 150)', $html);
    }
}
