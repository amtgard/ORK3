<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollBuildTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'AwardId'          => 33,
            'OfficerRole'      => 'none',
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

    /**
     * PROVENANCE. Nothing on the certificate draws these three, but the print log
     * (ScrollPrintLog::RecordPrint()) is written straight from this array, and
     * without them it can answer neither "has this player already had a scroll?"
     * nor "which kingdoms actually use this module?". They went missing once
     * already: the first logged rows carried mundane_id 0, scope_type '' and
     * scope_id 0 on every row because BuildScroll() did not return them.
     */
    public function testTheScrollCarriesItsRecipientAndIssuingBody(): void
    {
        $s = Scroll::BuildScroll($this->row(['MundaneId' => 11956, 'ParkId' => 435]), $this->context());

        $this->assertSame(11956, $s['mundaneId']);
        $this->assertSame('park', $s['scopeType']);
        $this->assertSame(435, $s['scopeId']);
    }

    /**
     * Park beats kingdom, matching Model_Award::kingdom_for_grant_row(): a park
     * grant leaves at_kingdom_id blank, so when both are set the park is the
     * narrower truth.
     */
    public function testAParkGrantScopesToTheParkAndAKingdomGrantToTheKingdom(): void
    {
        $both = Scroll::BuildScroll($this->row(['ParkId' => 435, 'KingdomId' => 4]), $this->context());
        $this->assertSame('park', $both['scopeType']);
        $this->assertSame(435, $both['scopeId']);

        $kingdom = Scroll::BuildScroll($this->row(['ParkId' => 0, 'KingdomId' => 4]), $this->context());
        $this->assertSame('kingdom', $kingdom['scopeType']);
        $this->assertSame(4, $kingdom['scopeId']);
    }

    /**
     * Scrollery/compose builds its award row out of $_GET and carries none of the
     * three, so its controller passes them in context — the same override pattern
     * issuerTitle / useThe / useOf already follow.
     */
    public function testAComposedScrollTakesItsProvenanceFromContext(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['ParkName' => '', 'KingdomName' => 'The Kingdom of Goldenvale']),
            $this->context(['mundaneId' => 54527, 'scopeType' => 'kingdom', 'scopeId' => 4])
        );

        $this->assertSame(54527, $s['mundaneId']);
        $this->assertSame('kingdom', $s['scopeType']);
        $this->assertSame(4, $s['scopeId']);
    }

    /**
     * A zero is "the row does not say", not "the row says zero" — 120,284 award rows
     * carry neither scope id — so the context fallback has to test the VALUE. A bare
     * ?? would keep a present-but-zero row value and never reach context.
     */
    public function testAPresentButZeroRowValueStillFallsThroughToContext(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['MundaneId' => 0, 'ParkId' => 0, 'KingdomId' => 0, 'ParkName' => '', 'KingdomName' => 'K']),
            $this->context(['mundaneId' => 99, 'scopeType' => 'park', 'scopeId' => 7])
        );

        $this->assertSame(99, $s['mundaneId']);
        $this->assertSame('park', $s['scopeType']);
        $this->assertSame(7, $s['scopeId']);
    }

    /** scope_type is a closed vocabulary; junk from a URL becomes "unknown", not echoed. */
    public function testAnUnknownContextScopeTypeIsRefusedRatherThanEchoed(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['ParkName' => '', 'KingdomName' => 'K']),
            $this->context(['scopeType' => "kingdom'; DROP TABLE x; --", 'scopeId' => 4])
        );

        $this->assertSame('', $s['scopeType']);
        $this->assertSame(0, $s['scopeId'], 'a refused scope type must not keep its id');
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
     * Under Scroll::OrderForAwardRow()'s ALLOWLIST the custom scroll is no longer the
     * catch-all it was: only Custom Award (94) and Custom Title (246) reach it. An
     * award with no design of its own that is NOT one of those two — Lord (52),
     * Master Rose (1), Walker in the Middle (31) — no longer builds at all, which is
     * why every case below uses 94. An officer position, which OrderForAwardRow()
     * never resolves to anything by any route, still cannot be built either. See
     * ScrollExcludedAwardsTest.
     */
    public function testBuildingAnOfficerPositionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Scroll::BuildScroll(
            $this->row(['AwardId' => 189, 'OfficerRole' => 'other-officer']),
            $this->context()
        );
    }

    public function testACustomAwardBuildsAsTheCustomScroll(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['AwardId' => 94, 'Name' => 'Custom Award', 'KingdomAwardName' => 'Order of the Emerald']),
            $this->context()
        );
        $this->assertSame('custom', $s['order']);
    }

    /**
     * The other half of the allowlist, stated here because BuildScroll() is the door
     * every print goes through: an award that resolves to nothing throws rather than
     * quietly falling back to the custom scroll. Lord used to build; it does not now.
     */
    public function testAnAwardOutsideTheAllowlistThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Scroll::BuildScroll($this->row(['AwardId' => 52, 'KingdomAwardName' => 'Lord']), $this->context());
    }

    /**
     * `ranked` decides whether the certificate prints "with <pronoun> ______" — a
     * blank somebody writes the level into. It is right for a ladder and wrong for a
     * Knighthood, and those are the same order id at the same rank, so nothing about
     * the order or the rank alone can answer it.
     */
    public function testAnUnrankedAwardWithNoOrdinalDoesNotPrintABlankToWriteOneIn(): void
    {
        // A kingdom's own Custom Award: not a ladder, rank 0. There is no first one.
        $s = Scroll::BuildScroll(
            $this->row(['AwardId' => 94, 'Rank' => 0, 'IsLadder' => '0']),
            $this->context()
        );
        $this->assertSame('custom', $s['order']);
        $this->assertFalse($s['ranked']);
    }

    public function testALadderAtRankZeroKeepsItsBlank(): void
    {
        // A kingdom's own ladder built on Custom Award — Award::pseudoLadderKingdomAwardIds()
        // is exactly this shape — takes the custom scroll while carrying is_ladder, and a
        // rank-0 grant of one is precisely the case the blank exists for.
        //
        // This used to be award_id 31, Order of the Walker in the Middle (292 of its 379
        // grants sit at rank 0). 31 no longer prints at all: it is the one ladder in the
        // catalogue with no entry in scroll-orders.php, and the allowlist admits nothing
        // it has no artwork for. See ScrollExcludedAwardsTest.
        //
        // IsLadder arrives from Yapo as the raw column STRING, which is why BuildScroll
        // casts rather than comparing strictly to 1. It is passed as '1' here so this
        // test fails the moment that cast is removed.
        $s = Scroll::BuildScroll(
            $this->row(['AwardId' => 94, 'Rank' => 0, 'IsLadder' => '1']),
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
            $this->row(['AwardId' => 94, 'Rank' => 4, 'IsLadder' => '0']),
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

    /**
     * A Custom Title gets an interstitial line, because it has no ordinal phrase and
     * without one the certificate reads as two unrelated lines — the recipient's name
     * running straight into a bare title.
     */
    public function testACustomTitleIsIntroducedByAPhrase(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['AwardId' => 246, 'Rank' => 0, 'IsLadder' => '0']),
            $this->context() + ['customName' => 'Fantastic Beast']
        );

        $this->assertSame('with the title of', $s['titlePhrase']);
        $this->assertSame('Fantastic Beast', $s['title']);
        // 'ranked' is what gates the ordinal phrase, and the two must never both render.
        $this->assertFalse($s['ranked']);
    }

    /**
     * Custom Award takes a SHORTER phrase than Custom Title, because the two entries
     * are different parts of speech: a title names what someone is, an award names a
     * thing granted, and award custom_names are frequently already order-shaped in the
     * real data ("Order of the Raider") — where "with the" reads as a sentence and the
     * title wording would not.
     */
    public function testACustomAwardTakesTheShorterDefaultPhrase(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['AwardId' => 94, 'Rank' => 0, 'IsLadder' => '0']),
            $this->context() + ['customName' => 'Order of the Raider']
        );

        $this->assertSame('with the', $s['titlePhrase']);
        $this->assertSame('Order of the Raider', $s['title']);
    }

    /** Both defaults are per-print overridable from the composer's "With phrase" box. */
    public function testTheWithPhraseIsOverridable(): void
    {
        foreach ([94, 246] as $awardId) {
            $s = Scroll::BuildScroll(
                $this->row(['AwardId' => $awardId, 'Rank' => 0, 'IsLadder' => '0']),
                $this->context() + ['customName' => 'Gladius', 'titlePhrase' => 'bearing the style of']
            );
            $this->assertSame('bearing the style of', $s['titlePhrase'], "award {$awardId}");
        }
    }

    /**
     * An empty override is a real choice — print the title with no introducing line —
     * and must NOT fall back to the default. This is why the context key is read with
     * array_key_exists rather than ??, and why the JS omits the parameter entirely
     * rather than sending ''.
     */
    public function testAnEmptyWithPhraseOverrideSuppressesTheLine(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['AwardId' => 94, 'Rank' => 0, 'IsLadder' => '0']),
            $this->context() + ['customName' => 'Gladius', 'titlePhrase' => '']
        );

        $this->assertSame('', $s['titlePhrase']);
    }

    /** An override must never give a ranked award a second phrase beside its ordinal. */
    public function testAnOverrideCannotAddAPhraseToARankedAward(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['Rank' => 3]),
            $this->context() + ['titlePhrase' => 'with the']
        );

        $this->assertSame('', $s['titlePhrase']);
        $this->assertTrue($s['ranked']);
    }

    /**
     * An award that already carries the ordinal phrase must never also take the title
     * phrase — they occupy the same slot in _text.tpl.
     */
    public function testARankedAwardTakesNoTitlePhrase(): void
    {
        $s = Scroll::BuildScroll($this->row(['Rank' => 3]), $this->context());

        $this->assertSame('', $s['titlePhrase']);
        $this->assertTrue($s['ranked']);
    }

    /**
     * AwardsForPlayer() resolves an alias into AliasAwardId and leaves AwardId on the
     * base award, so the phrase has to read both — the same reason the printability
     * rule and IsCustomAward() do.
     */
    public function testAnAliasedCustomTitleStillTakesThePhrase(): void
    {
        $this->assertSame(
            'with the title of',
            Scroll::TitlePhrase(['AwardId' => 21, 'AliasAwardId' => 246])
        );
        $this->assertSame('', Scroll::TitlePhrase(['AwardId' => 21]));
    }
}
