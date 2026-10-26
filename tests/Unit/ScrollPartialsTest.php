<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollPartialsTest extends TestCase
{
    private function scroll(array $overrides = []): array
    {
        return array_merge([
            // `ranked` is stated rather than left to _text.tpl's empty() default: an
            // absent key omits the rank phrase, and a hand-built array silently losing
            // "with her third" would weaken every assertion in this file that reads it.
            'order' => 'griffon', 'rank' => 3, 'ranked' => true, 'variant' => '',
            'title' => 'Order of the Griffin', 'player' => 'Testerly',
            'pronoun' => 'her', 'reason' => 'valour', 'date' => '13th May 2024',
            'givenBy' => 'Sir Someone', 'issuerTitle' => 'Barony',
            'issuerName' => 'Rising Sun Station', 'useThe' => true, 'useOf' => true,
            'heraldry' => '/assets/heraldry/park/00435.png',
            'flavour' => 'flavour text', 'primary' => '#8d1b3d', 'secondary' => '#d4af37',
            'resources' => Scroll::DEFAULT_RESOURCES,
        ], $overrides);
    }

    /**
     * _border_defs.tpl emits once per request and remembers in $GLOBALS that it has.
     * Every case in a run shares one process, so the flag is cleared here rather
     * than left to whichever test happened to render a page first.
     */
    protected function setUp(): void
    {
        unset($GLOBALS['scroll_border_defs']);
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

        $this->assertStringContainsString('href="#Border-Griffons"', $with);
        $this->assertStringNotContainsString('Border-Griffons', $without);
    }

    /**
     * The certificate carries the REFERENCE; _border_defs.tpl carries the geometry.
     * Ten orders compose the border without drawing griffons, so a CSS
     * display:none on the plate would put ~47 KB of unused paths in their DOM.
     */
    public function testTheGriffonGeometryLivesInTheHoistedDefsNotInTheCertificate(): void
    {
        $fragment = $this->render('border', ['primary' => '#111', 'secondary' => '#222', 'griffons' => true]);

        $this->assertStringNotContainsString('<clipPath', $fragment);
        $this->assertLessThan(4096, strlen($fragment));

        ob_start();
        try {
            scroll_partial('border_defs');
        } finally {
            $defs = (string) ob_get_clean();
        }

        $this->assertStringContainsString('id="Border-Griffons"', $defs);
        $this->assertStringContainsString('<clipPath', $defs);
    }

    /**
     * The hoist, asserted as a contract rather than as a byte count nobody can read.
     *
     * A <use href="#Border-A"> resolves against the DOCUMENT, so the 32 symbols only
     * ever need to exist once on a page. They used to be inline in every border,
     * which meant a 200-certificate bulk run of custom scrolls — 391 of the 391
     * awards on event 12250 are Custom Award, so that is a real run — carried 200
     * copies of a 48 KB block and ~6,400 duplicate element ids.
     */
    public function testNoCertificateCarriesTheSymbolLibrary(): void
    {
        $first  = $this->render('border', ['primary' => '#111', 'secondary' => '#222']);
        $second = $this->render('border', ['primary' => '#333', 'secondary' => '#444']);

        foreach ([$first, $second] as $html) {
            $this->assertSame(0, substr_count($html, '<symbol '));
            $this->assertSame(8, substr_count($html, '<use href="#Border-'));
            $this->assertLessThan(4096, strlen($html));
        }

        // Each certificate still carries its own colour pair.
        $this->assertStringContainsString('--primary-color:#111', $first);
        $this->assertStringContainsString('--primary-color:#333', $second);
    }

    /**
     * WHY the border partial must not emit the defs itself, stated as a test so the
     * "just include them if nobody else has" version cannot come back.
     *
     * Scroll::Render() caches a composed certificate for an hour, keyed on the
     * scroll array and the partials' mtime — and on nothing about the page. A
     * partial that emitted the library only when it was the first on the page would
     * therefore cache whichever answer happened to be true when that certificate was
     * first composed, and serve it everywhere: a fragment cached from the print
     * route (which emits the library at page level) would draw no border at all in
     * the composer. Byte-identical output whatever else is on the page is what makes
     * the cache safe.
     */
    public function testTheBorderFragmentIsIdenticalWhetherOrNotTheDefsAreOnThePage(): void
    {
        $beforeAnyPage = $this->render('border', ['primary' => '#111', 'secondary' => '#222']);

        ob_start();
        try {
            scroll_partial('border_defs');
        } finally {
            ob_end_clean();
        }

        $afterThePageEmittedThem = $this->render('border', ['primary' => '#111', 'secondary' => '#222']);

        $this->assertSame($beforeAnyPage, $afterThePageEmittedThem);
    }

    /**
     * _filters.tpl is the one page-level seam, so the library rides along with it
     * rather than needing a second call every new surface has to remember.
     */
    public function testTheSharedFiltersPartialCarriesTheBorderLibrary(): void
    {
        ob_start();
        try {
            scroll_partial('filters');
        } finally {
            $page = (string) ob_get_clean();
        }

        $this->assertStringContainsString('OWL_edge-detect', $page);
        $this->assertSame(32, substr_count($page, '<symbol '));
        $this->assertStringContainsString('id="Border-Griffons"', $page);
    }

    /** A page that includes the seam twice pays for the library once. */
    public function testTheLibraryIsEmittedOncePerRequest(): void
    {
        ob_start();
        try {
            scroll_partial('filters');
            scroll_partial('filters');
        } finally {
            $page = (string) ob_get_clean();
        }

        $this->assertSame(32, substr_count($page, '<symbol '));
    }

    /**
     * Every plate a certificate draws resolves through scroll_art(), never through
     * the raw $art base.
     *
     * That helper is the only thing that consults assets/scrolls/renditions.json,
     * and the manifest is the only correct source for a rendition's filename: 17 of
     * the 244 masters have no `print` entry because WebP genuinely lost to them
     * (garber/linen.jpg is +119% as WebP), so a partial that built a URL by
     * appending an extension would 404 on exactly the largest files. A partial that
     * kept composing off $art would silently keep drawing the 300 dpi master, and
     * the composer's preview would keep pulling ~4 MB for a postcard.
     *
     * Asserted on the source text rather than the output because it is a rule about
     * how the partials are written, and a new order partial copied from an old one
     * is exactly how it would come back.
     */
    public function testEveryPartialResolvesItsArtThroughTheRenditionHelper(): void
    {
        $offenders = [];
        foreach (glob(DIR_TEMPLATE . 'revised-frontend/scroll/*.tpl') ?: [] as $file) {
            $body = (string) file_get_contents($file);
            // Strip comments first: the rule is explained in prose in several of
            // these files, and an explanation is not a violation.
            $code = preg_replace('~/\*.*?\*/~s', '', $body);
            if (preg_match('/\$art\b/', (string) $code)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these partials still build an art URL from $art instead of scroll_art()'
        );
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

    /**
     * The old form of this test asserted only that writing-line.svg appeared SOMEWHERE
     * in the output, which is also true of the player blank and the six reason
     * underlines — so it passed whether or not the rank phrase rendered at all. The
     * whole phrase is the thing under test, so assert on the phrase.
     */
    public function testRankZeroDrawsABlankRatherThanAnOrdinal(): void
    {
        $html = $this->render('text', ['s' => $this->scroll(['rank' => 0])]);

        $this->assertStringNotContainsString('with her first', $html);
        $this->assertStringContainsString('with ', $html);
        $this->assertStringContainsString('data-scroll-text="rankPhrase"', $html);
    }

    /**
     * A Knighthood, a Master or a title has no ordinal at all, so it must not print a
     * blank inviting one to be written in. A LADDER recorded at rank 0 must — 292 of
     * Walker in the Middle's 379 grants are exactly that. Scroll::BuildScroll() draws
     * the distinction and hands it down as `ranked`; this is the partial honouring it.
     */
    public function testAnUnrankedScrollPrintsNoRankPhraseAtAll(): void
    {
        $html = $this->render('text', ['s' => $this->scroll(['rank' => 0, 'ranked' => false])]);

        $this->assertStringNotContainsString('with ', $html);
        $this->assertStringNotContainsString('data-scroll-text="rankPhrase"', $html);
    }

    /**
     * Dropping the phrase must not drop its box: .Award .Body.AwardDetails carries a
     * margin-block-start, and losing it shifts the block spacing on every unranked
     * certificate.
     */
    public function testTheRankPhrasesContainerSurvivesWithoutThePhrase(): void
    {
        $html = $this->render('text', ['s' => $this->scroll(['rank' => 0, 'ranked' => false])]);

        $this->assertStringContainsString('class="Body AwardDetails"', $html);
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

    /** The inline style of section.Order, which carries the award's first-guess size. */
    private function awardStyle(string $html): string
    {
        $this->assertSame(1, preg_match('#<section class="Order"[^>]*style="([^"]*)"#', $html, $m), 'section.Order has no style');

        return $m[1];
    }

    /**
     * The typeset engine's contract with the markup (spec 8.1): the role elements it
     * sets, the attribute it reads each flourish's ink from, the composition marker, the
     * elastic spacer, and the mirrored rule pair Centered draws. All of it is inert
     * without the engine, so a render with no script is still the certificate it was.
     */
    public function testTheTypesetHooksArePresent(): void
    {
        $html = $this->render('text', ['s' => $this->scroll()]);

        foreach (['data-sc-role="lead"', 'data-sc-role="place"', 'data-sc-role="name"', 'data-sc-role="award"',
                  'data-sc-comp="classic"', 'class="ScSpacer"', 'ScRule--l', 'ScRule--r'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        // Top, Bottom, Right and the two rules: five images, five grids.
        $this->assertSame(5, substr_count($html, 'data-sc-ink="32x32:'));
    }

    /**
     * The sweep's BLOCK_SEL selects the issuing lines by type, not by position, so the
     * two paragraphs must stay the first and second p of the section whatever else is
     * put between or beside them.
     */
    public function testTheIssuingLinesAreStillTheFirstTwoParagraphs(): void
    {
        $html = $this->render('text', ['s' => $this->scroll()]);

        $this->assertSame(1, preg_match('#<section class="IssuingTextSection".*?</section>#s', $html, $m));
        preg_match_all('#<p\b[^>]*>#', $m[0], $ps);
        $this->assertCount(3, $ps[0]);
        $this->assertStringContainsString('ScLead', $ps[0][0]);
        $this->assertStringContainsString('ScPlace', $ps[0][1]);
        $this->assertStringContainsString('IssuingVerb', $ps[0][2]);
    }

    /** A kingdom is set like a park: "and the Kingdom of" small, "the" smaller, then the place. */
    public function testAKingdomReadsLikeAPark(): void
    {
        $html = $this->render('text', ['s' => $this->scroll([
            'issuerTitle' => '', 'issuerName' => 'The Kingdom of the Wetlands', 'useThe' => false, 'useOf' => false,
            'issuerLead' => 'and the Kingdom of', 'issuerArticle' => 'the', 'issuerPlace' => 'Wetlands',
        ])]);

        $this->assertMatchesRegularExpression('#id="IssuerTitle"[^>]*>and the Kingdom of</span>#', $html);
        $this->assertStringContainsString('<span class="sc-art">the</span> Wetlands', $html);
    }

    /** No article, no article span and no stray space in front of the place. */
    public function testAPlaceWithoutAnArticleHasNoArticleSpan(): void
    {
        $html = $this->render('text', ['s' => $this->scroll([
            'issuerLead' => 'and the Barony of', 'issuerArticle' => '', 'issuerPlace' => 'Rising Sun Station',
        ])]);

        $this->assertStringNotContainsString('class="sc-art"', $html);
        $this->assertMatchesRegularExpression('#data-sc-role="place"[^>]*>Rising Sun Station</span>#', $html);
    }

    /** A scroll array built before the split keys existed prints what it always printed. */
    public function testAnOldScrollArrayIsSplitOnTheFly(): void
    {
        $html = $this->render('text', ['s' => $this->scroll([
            'issuerTitle' => 'Kingdom', 'issuerName' => 'the Wetlands', 'useThe' => true, 'useOf' => true,
        ])]);

        $this->assertMatchesRegularExpression('#id="IssuerTitle"[^>]*>and the Kingdom of</span>#', $html);
        $this->assertStringContainsString('<span class="sc-art">the</span> Wetlands', $html);
    }

    /** The lead is data, so it is escaped like the rest of the text. */
    public function testTheLeadIsEscaped(): void
    {
        $html = $this->render('text', ['s' => $this->scroll([
            'issuerLead' => 'and <b>the</b> Barony of', 'issuerArticle' => '', 'issuerPlace' => 'X',
        ])]);

        $this->assertStringNotContainsString('<b>the</b>', $html);
        $this->assertStringContainsString('and &lt;b&gt;the&lt;/b&gt; Barony of', $html);
    }

    /**
     * The award is guessed at 0.85 of the name's guess and never above 1.5em, so a
     * script-less render cannot print an order line that outweighs the recipient. A
     * 100-character persona guesses the name at 1.36em, and 0.85 x 1.36 is 1.156.
     */
    public function testTheAwardFirstGuessNeverExceedsTheNamesShare(): void
    {
        $html = $this->render('text', ['s' => $this->scroll(['player' => str_repeat('n', 100)])]);

        $this->assertStringContainsString('font-size:1.1560em', $this->awardStyle($html));
    }

    /** A short name guesses large, and the award stops at its own maximum. */
    public function testTheAwardFirstGuessStopsAtItsMaximum(): void
    {
        $html = $this->render('text', ['s' => $this->scroll(['player' => 'Ru'])]);

        $this->assertStringContainsString('font-size:1.5000em', $this->awardStyle($html));
    }

    /** The place keeps its formula under a 1.3em ceiling, and the article rides on it. */
    public function testThePlaceFirstGuessIsCappedAndTheArticleFollowsIt(): void
    {
        $html = $this->render('text', ['s' => $this->scroll(['issuerPlace' => 'Rising Sun Station'])]);

        $this->assertMatchesRegularExpression('#data-sc-role="place"[^>]*style="font-size:min\(1\.3em, max\(1em, calc\(1em \+ 0\.5em \* \(14 / 18\)\)\)\)#', $html);
    }
}
