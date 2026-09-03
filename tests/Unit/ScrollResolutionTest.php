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

    public function testHasArtIsFalseForAwardsWithNoOrder(): void
    {
        $this->assertTrue(Scroll::HasArt($this->row(['AwardId' => 33])));
        $this->assertFalse(Scroll::HasArt($this->row(['AwardId' => 1])));      // Master Rose
        $this->assertFalse(Scroll::HasArt($this->row(['AwardId' => 999999]))); // not in the catalogue
    }

    /**
     * hunter keeps Arcanyst's fabricated ork_id 6969, so OrderForAwardId(6969)
     * does resolve — by construction, not by accident. What makes archery safe is
     * that no award in the ORK is keyed 6969 (the catalogue tops out at 246), and
     * the real archery awards hang off award_id 0, 94 (Custom Award) and 131. This
     * test fails the day one of those is given an order, which is the moment the
     * archery title question has to be answered properly.
     */
    public function testRealArcheryAwardsResolveToNoOrder(): void
    {
        $this->assertSame('hunter', Scroll::OrderForAwardId(6969)['id']);

        foreach ([0, 94, 131] as $archeryBaseId) {
            $this->assertFalse(
                Scroll::HasArt($this->row(['AwardId' => $archeryBaseId])),
                "archery base award {$archeryBaseId} unexpectedly has art"
            );
        }
    }
}
