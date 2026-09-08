<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollResolutionTest extends TestCase
{
    /** A row shaped like Player::AwardsForPlayer() output. */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'AwardId'          => 33,
            'OfficerRole'      => 'none',
            'Rank'             => 5,
            'Name'             => 'Order of the Griffin',
            'KingdomAwardName' => 'Order of the Griffin',
            'CustomAwardName'  => '',
            'AliasAwardName'   => '',
        ], $overrides);
    }

    /**
     * The certificate renders the title on its own line, between "with her third"
     * and "for" — so it must read as a whole order name, never a bare label.
     *
     * awards.custom_name does not hold one. On ladder awards it is overwhelmingly a
     * short tag typed alongside the real name: 19,342 rows reading "Rose" whose
     * kingdomaward is "Order of the Rose", 13,477 "Warrior", 6,084 "Gryphon" — 89,849
     * of 343,091 ladder awards in all. Preferring it would misprint a quarter of
     * every ladder scroll.
     */
    public function testKingdomAwardNameWinsOverCustomName(): void
    {
        $row = $this->row([
            'CustomAwardName'  => 'Rose',
            'KingdomAwardName' => 'Order of the Rose',
        ]);
        $this->assertSame('Order of the Rose', Scroll::ResolveTitle($row));
    }

    public function testKingdomAwardNameWinsOverBaseName(): void
    {
        $row = $this->row([
            'KingdomAwardName' => 'Order of the Marksman',
            'Name'             => 'Order of the Hunter',
        ]);
        $this->assertSame('Order of the Marksman', Scroll::ResolveTitle($row));
    }

    public function testBaseNameIsUsedWhenTheKingdomHasNoNameOfItsOwn(): void
    {
        $row = $this->row(['KingdomAwardName' => '', 'Name' => 'Order of the Griffin']);
        $this->assertSame('Order of the Griffin', Scroll::ResolveTitle($row));
    }

    /**
     * Custom and alias names are not dropped — they are the last resort, so a row
     * that somehow carries neither of the two real names still prints something
     * rather than an empty line.
     */
    public function testCustomAndAliasNamesAreTheLastResortOnly(): void
    {
        $row = $this->row([
            'KingdomAwardName' => '',
            'Name'             => '',
            'AliasAwardName'   => 'Order of the Gryphon',
            'CustomAwardName'  => 'The Gilded Feather',
        ]);
        $this->assertSame('Order of the Gryphon', Scroll::ResolveTitle($row));

        $row['AliasAwardName'] = '';
        $this->assertSame('The Gilded Feather', Scroll::ResolveTitle($row));
    }

    public function testBlankAndWhitespaceOnlyNamesAreSkipped(): void
    {
        $row = $this->row([
            'KingdomAwardName' => '   ',
            'Name'             => 'Order of the Griffin',
        ]);
        $this->assertSame('Order of the Griffin', Scroll::ResolveTitle($row));
    }

    public function testTitleIsEmptyWhenTheRowCarriesNoNameAtAll(): void
    {
        $row = $this->row([
            'KingdomAwardName' => '',
            'Name'             => '',
            'AliasAwardName'   => '',
            'CustomAwardName'  => '',
        ]);
        $this->assertSame('', Scroll::ResolveTitle($row));
    }

    public function testRankClampsToTheOrderRange(): void
    {
        $griffon = Scroll::Orders()['griffon'];
        $warrior = Scroll::Orders()['warrior'];

        $this->assertSame(0, Scroll::ClampRank($griffon, 0));
        $this->assertSame(10, Scroll::ClampRank($griffon, 10));
        $this->assertSame(10, Scroll::ClampRank($griffon, 11));
        $this->assertSame(0, Scroll::ClampRank($griffon, -3));

        // warrior has no rank-0 art
        $this->assertSame(1, Scroll::ClampRank($warrior, 0));
    }

    public function testZodiacClampsAtTenDespiteTheCatalogueAllowingTwelve(): void
    {
        $this->assertSame(12, Award::GetLadderMasterMap()[30]['MaxRank']);
        $zodiac = Scroll::Orders()['zodiac'];
        $this->assertSame(10, Scroll::ClampRank($zodiac, 12));
    }

    /**
     * The ordinal is NOT clamped to the art, which is the whole point of it being a
     * separate number. 79 live grants sit above rank 10 — 35 at 11, 19 at 12, out to
     * one at 22 — and every one of them lettered "with their tenth" while its
     * numeral, correctly, stayed at rank_10.
     */
    public function testOrdinalRankIsNotClampedToTheArtCeiling(): void
    {
        $griffon = Scroll::Orders()['griffon'];
        $zodiac  = Scroll::Orders()['zodiac'];

        $this->assertSame(10, Scroll::OrdinalRank($griffon, 10));
        $this->assertSame(11, Scroll::OrdinalRank($griffon, 11), 'an eleventh grant is the eleventh');
        $this->assertSame(22, Scroll::OrdinalRank($griffon, 22));
        $this->assertSame(12, Scroll::OrdinalRank($zodiac, 12));

        // Where the two differ is exactly and only above the ceiling.
        foreach ([0, 1, 5, 9, 10] as $rank) {
            $this->assertSame(
                Scroll::ClampRank($griffon, $rank),
                Scroll::OrdinalRank($griffon, $rank),
                "art and ordinal must agree at rank $rank"
            );
        }
    }

    /**
     * The LOWER bound is kept. warrior's art starts at rank 1 and a row recorded at
     * 0 has always printed "first"; unclamping that would silently turn those into
     * blank hand-write lines, which is a different decision than this one.
     */
    public function testOrdinalRankKeepsTheLowerBound(): void
    {
        $warrior = Scroll::Orders()['warrior'];

        $this->assertSame(1, Scroll::OrdinalRank($warrior, 0));
        $this->assertSame(1, Scroll::OrdinalRank($warrior, -3));
    }

    /**
     * And the two numbers actually reach the certificate separately: `rank` drives
     * the art, `ordinalRank` the word.
     */
    public function testBuildScrollCarriesBothRanks(): void
    {
        $scroll = Scroll::BuildScroll($this->row(['Rank' => 14]), ['player' => 'Testerly']);

        $this->assertSame(10, $scroll['rank'], 'art clamps — there is no rank_14 plate');
        $this->assertSame(14, $scroll['ordinalRank'], 'the word does not');
    }

    public function testHasArtIsTrueForAnythingPrintableAndFalseForOfficerPositions(): void
    {
        // A specific order.
        $this->assertTrue(Scroll::HasArt($this->row(['AwardId' => 33])));
        // No design of its own, but printable as a custom scroll — and under the
        // allowlist that is Custom Award (94) and Custom Title (246) alone. Lord (52)
        // used to stand here and no longer does; see ScrollExcludedAwardsTest.
        $this->assertTrue(Scroll::HasArt($this->row(['AwardId' => 94])));
        $this->assertTrue(Scroll::HasArt($this->row(['AwardId' => 246])));
        // An officer position is not printable at all.
        $this->assertFalse(Scroll::HasArt($this->row(['AwardId' => 189, 'OfficerRole' => 'other-officer'])));
        // Neither is anything the allowlist does not admit — a masterhood, a title.
        $this->assertFalse(Scroll::HasArt($this->row(['AwardId' => 1])));
        $this->assertFalse(Scroll::HasArt($this->row(['AwardId' => 52])));
    }

    /**
     * hunter keeps Arcanyst's fabricated ork_id 6969, so OrderForAwardId(6969)
     * does resolve — by construction, not by accident. What makes archery safe is
     * that no award in the ORK is keyed 6969 (the catalogue tops out at 246), and
     * the real archery awards hang off award_id 0, 94 (Custom Award) and 131. This
     * test fails the day one of those is given a SPECIFIC order of its own, which is
     * the moment the archery title question has to be answered properly.
     *
     * As of the allowlist, a bare award_id 94 row (no archery name attached) is still
     * printable — as the custom scroll, per ScrollCustomEligibilityTest — while bare
     * 0 and 131 rows are not, because they are neither an order nor a custom entry.
     * That coverage question belongs there, not here. This test only pins that none
     * of the three base ids has been given its OWN (specific, by-id) order.
     *
     * 94 and 131 are asserted directly through OrderForAwardId(): no order in the
     * catalogue declares either as its award_id, so a real regression would still be
     * caught. 0 cannot be asserted the same way — OrderForAwardId() guards
     * $awardId === 0 unconditionally as its very first statement (the custom order's
     * own award_id sentinel would otherwise collide with it), so calling it with 0
     * through this door proves nothing. The invariant that only `custom` may carry
     * the 0 sentinel is asserted directly against the catalogue instead.
     */
    public function testRealArcheryAwardsResolveToNoSpecificOrder(): void
    {
        $this->assertSame('hunter', Scroll::OrderForAwardId(6969)['id']);

        foreach ([94, 131] as $archeryBaseId) {
            $this->assertNull(
                Scroll::OrderForAwardId($archeryBaseId),
                "archery base award {$archeryBaseId} unexpectedly resolved to a specific order"
            );
        }

        foreach (Scroll::Orders() as $id => $order) {
            if ($id === 'custom') {
                continue;
            }
            $this->assertNotSame(0, (int) $order['award_id'], "$id must not use the 0 sentinel");
        }
    }
}
