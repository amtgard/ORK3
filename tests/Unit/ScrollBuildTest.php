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
            'KingdomName'      => 'The Kingdom of Crystal Groves',
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
        $this->assertTrue($s['useThe']);
        $this->assertTrue($s['useOf']);
    }

    /**
     * The issuer line is built as "and" [the] [title] [of] / NAME.
     *
     * A park needs all of it — park names are bare ("Mordengaard", "Granite Spyre"),
     * only 1 of ~600 carrying a rank word, so "and the Barony of / Mordengaard" reads
     * correctly.
     *
     * A KINGDOM does not. Every kingdom name in the ORK is already a complete phrase
     * with its own article and rank word: "The Kingdom of the Wetlands", "The Empire
     * of the Iron Mountains", "The Freeholds of Amtgard", "The Celestial Kingdom".
     * Prepending to that yields "and the Kingdom of The Kingdom of the Wetlands" —
     * and 159,768 ladder awards, 47% of them, are kingdom-issued.
     *
     * Arcanyst's UseThe/UseOf flags exist precisely for this; there an officer typed
     * the title and name apart and toggled them. Here the shape is known from the
     * data, so the flags are set rather than asked for.
     */
    public function testAKingdomIssuerSuppliesItsOwnArticleAndRankWord(): void
    {
        $s = Scroll::BuildScroll($this->row(['ParkName' => '']), $this->context());

        $this->assertSame('The Kingdom of Crystal Groves', $s['issuerName']);
        $this->assertSame('', $s['issuerTitle']);
        $this->assertFalse($s['useThe']);
        $this->assertFalse($s['useOf']);
    }

    /** An explicit override still wins over the shape inferred from the data. */
    public function testTheIssuerWordingCanStillBeOverridden(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['ParkName' => '']),
            $this->context(['issuerTitle' => 'Principality', 'useThe' => true, 'useOf' => true])
        );

        $this->assertSame('Principality', $s['issuerTitle']);
        $this->assertTrue($s['useThe']);
        $this->assertTrue($s['useOf']);
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

    /**
     * As of Scroll::OrderForAwardRow(), an award with no design of its own (e.g.
     * AwardId 1, Master Rose) still builds — as the custom scroll — so it no longer
     * throws here. Only an officer position, which OrderForAwardRow() never resolves
     * to anything, still cannot be built.
     */
    public function testBuildingAnOfficerPositionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Scroll::BuildScroll(
            $this->row(['AwardId' => 189, 'OfficerRole' => 'other-officer']),
            $this->context()
        );
    }

    public function testAwardWithNoDesignOfItsOwnBuildsAsTheCustomScroll(): void
    {
        $s = Scroll::BuildScroll($this->row(['AwardId' => 1]), $this->context());
        $this->assertSame('custom', $s['order']);
    }

    /**
     * `ranked` decides whether the certificate prints "with <pronoun> ______" — a
     * blank somebody writes the level into. It is right for a ladder and wrong for a
     * Knighthood, and those are the same order id at the same rank, so nothing about
     * the order or the rank alone can answer it.
     */
    public function testAnUnrankedAwardWithNoOrdinalDoesNotPrintABlankToWriteOneIn(): void
    {
        // Master Rose: not a ladder, rank 0. There is no first Master Rose.
        $s = Scroll::BuildScroll(
            $this->row(['AwardId' => 1, 'Rank' => 0, 'IsLadder' => '0']),
            $this->context()
        );
        $this->assertSame('custom', $s['order']);
        $this->assertFalse($s['ranked']);
    }

    public function testALadderAtRankZeroKeepsItsBlank(): void
    {
        // award_id 31, Order of the Walker in the Middle: the one ladder with no art of
        // its own, and 292 of its 379 grants are recorded at rank 0 (verified against
        // the awards table) — exactly the grants the blank exists for.
        //
        // IsLadder arrives from Yapo as the raw column STRING, which is why BuildScroll
        // casts rather than comparing strictly to 1. It is passed as '1' here so this
        // test fails the moment that cast is removed.
        $s = Scroll::BuildScroll(
            $this->row(['AwardId' => 31, 'Rank' => 0, 'IsLadder' => '1']),
            $this->context()
        );
        $this->assertSame('custom', $s['order']);
        $this->assertTrue($s['ranked']);
    }

    public function testANonLadderWithARealRankStillPrintsItsOrdinal(): void
    {
        // 8,825 grants of non-ladder, non-officer awards carry rank > 0, and the
        // numeral is drawn on rank > 0 regardless of ladder-ness. The phrase and the
        // numeral must not disagree.
        $s = Scroll::BuildScroll(
            $this->row(['AwardId' => 1, 'Rank' => 4, 'IsLadder' => '0']),
            $this->context()
        );
        $this->assertSame('custom', $s['order']);
        $this->assertTrue($s['ranked']);
    }

    /**
     * The create flow synthesises its award row out of $_GET/$_POST and carries no
     * IsLadder key at all (controller.Scrollery::compose, controller.ScrolleryAjax).
     * A blank Griffon at rank 0 is precisely the hand-writing case, so a bespoke order
     * must never depend on that key.
     */
    public function testABespokeOrderAtRankZeroKeepsItsBlankWithNoIsLadderKey(): void
    {
        $row = $this->row(['Rank' => 0]);
        $this->assertArrayNotHasKey('IsLadder', $row);

        $s = Scroll::BuildScroll($row, $this->context());
        $this->assertSame('griffon', $s['order']);
        $this->assertTrue($s['ranked']);
    }

    public function testArtBaseHasNoTrailingSlash(): void
    {
        $this->assertStringEndsWith('scrolls', Scroll::ArtBase());
    }
}
