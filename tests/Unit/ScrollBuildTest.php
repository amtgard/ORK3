<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollBuildTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'AwardId'          => 33,
            'Rank'             => 3,
            'Date'             => '2024-05-13',
            'Note'             => 'valour in the field',
            'GivenBy'          => 'Sir Someone',
            'Name'             => 'Order of the Griffin',
            'KingdomAwardName' => 'Order of the Griffin',
            'CustomAwardName'  => '',
            'AliasAwardName'   => '',
            'ParkName'         => 'Rising Sun Station',
            'KingdomName'      => 'Crystal Groves',
        ], $overrides);
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            'player'   => 'Testerly',
            'pronoun'  => 'her',
            'heraldry' => '/assets/heraldry/park/00435.png',
        ], $overrides);
    }

    public function testBuildsTheScrollContractFromAnAwardRow(): void
    {
        $s = Scroll::BuildScroll($this->row(), $this->context());

        $this->assertSame('griffon', $s['order']);
        $this->assertSame(3, $s['rank']);
        $this->assertSame('Order of the Griffin', $s['title']);
        $this->assertSame('Testerly', $s['player']);
        $this->assertSame('her', $s['pronoun']);
        $this->assertSame('valour in the field', $s['reason']);
        $this->assertSame('Sir Someone', $s['givenBy']);
        $this->assertSame('/assets/heraldry/park/00435.png', $s['heraldry']);
    }

    public function testDateIsFormattedForTheCertificate(): void
    {
        $s = Scroll::BuildScroll($this->row(['Date' => '2024-05-13']), $this->context());
        $this->assertSame('13th May 2024', $s['date']);

        $s = Scroll::BuildScroll($this->row(['Date' => '2024-05-01']), $this->context());
        $this->assertSame('1st May 2024', $s['date']);

        $s = Scroll::BuildScroll($this->row(['Date' => '2024-05-22']), $this->context());
        $this->assertSame('22nd May 2024', $s['date']);

        $s = Scroll::BuildScroll($this->row(['Date' => '0000-00-00']), $this->context());
        $this->assertSame('', $s['date']);
    }

    /**
     * The 11th/12th/13th are "th", not "st"/"nd"/"rd" — the one case a naive
     * modulo-10 suffix table gets wrong, and one that lands three days a month.
     */
    public function testTeenDatesTakeTheThSuffix(): void
    {
        foreach (['2024-05-11' => '11th', '2024-05-12' => '12th', '2024-05-13' => '13th'] as $date => $expected) {
            $s = Scroll::BuildScroll($this->row(['Date' => $date]), $this->context());
            $this->assertSame($expected . ' May 2024', $s['date']);
        }
    }

    public function testIssuerPrefersParkAndFallsBackToKingdom(): void
    {
        $s = Scroll::BuildScroll($this->row(), $this->context());
        $this->assertSame('Rising Sun Station', $s['issuerName']);
        $this->assertSame('Barony', $s['issuerTitle']);

        $s = Scroll::BuildScroll($this->row(['ParkName' => '']), $this->context());
        $this->assertSame('Crystal Groves', $s['issuerName']);
        $this->assertSame('Kingdom', $s['issuerTitle']);
    }

    /**
     * "Barony" is only a fallback. ORK does store each park's rank word
     * (ork_park.parktitle_id -> ork_parktitle.title, set per kingdom), and most
     * parks are not baronies — 349 are Freeholds and 274 Grand Duchies. Whoever
     * has the row supplies the real word through the context.
     */
    public function testIssuerTitleCanBeSuppliedByTheCaller(): void
    {
        $s = Scroll::BuildScroll($this->row(), $this->context(['issuerTitle' => 'Freehold']));
        $this->assertSame('Freehold', $s['issuerTitle']);
        $this->assertSame('Rising Sun Station', $s['issuerName']);

        // A blank override does not win over the fallback.
        $s = Scroll::BuildScroll($this->row(), $this->context(['issuerTitle' => '  ']));
        $this->assertSame('Barony', $s['issuerTitle']);
    }

    public function testFlavourIsSelectedByClampedRank(): void
    {
        $s = Scroll::BuildScroll($this->row(['Rank' => 3]), $this->context());
        $this->assertSame(Scroll::Orders()['griffon']['flavour']['3'], $s['flavour']);

        // Zodiac 12 clamps to 10 rather than reaching for absent art. Its flavour
        // arrives EXPANDED — zodiac is the one order carrying <<ISSUER>> and
        // <<...|MONTH|...>> placeholders, which must never reach the certificate.
        $s = Scroll::BuildScroll($this->row(['AwardId' => 30, 'Rank' => 12]), $this->context());
        $this->assertSame(10, $s['rank']);
        $this->assertSame(
            Scroll::ExpandFlavour(Scroll::Orders()['zodiac']['flavour']['10'], 'Rising Sun Station'),
            $s['flavour']
        );
        $this->assertStringNotContainsString('<<', $s['flavour']);
    }

    public function testVariantDefaultsToTheFirstDeclaredVariant(): void
    {
        $s = Scroll::BuildScroll($this->row(['AwardId' => 243]), $this->context());
        $this->assertSame('arcane', $s['variant']);

        $s = Scroll::BuildScroll($this->row(['AwardId' => 243]), $this->context(['variant' => 'martial']));
        $this->assertSame('martial', $s['variant']);

        // An unknown variant falls back rather than producing a 404 art path.
        $s = Scroll::BuildScroll($this->row(['AwardId' => 243]), $this->context(['variant' => 'nonsense']));
        $this->assertSame('arcane', $s['variant']);

        $s = Scroll::BuildScroll($this->row(), $this->context());
        $this->assertSame('', $s['variant']);
    }

    public function testBuildingAnAwardWithNoArtThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Scroll::BuildScroll($this->row(['AwardId' => 1]), $this->context());
    }

    public function testArtBaseHasNoTrailingSlash(): void
    {
        $this->assertStringEndsWith('scrolls', Scroll::ArtBase());
    }
}
