<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization for P3-R2 GetLadderProgress (Approx / Master / Walker skip).
 */
final class PlayerLadderProgressTest extends TestCase
{
    private Player $player;

    protected function setUp(): void
    {
        $this->player = new Player();
    }

    public function testSkipsWalkerOfTheMiddle(): void
    {
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                [
                    'AwardId' => 31,
                    'IsLadder' => 1,
                    'Rank' => 5,
                    'Name' => 'Walker of the Middle',
                ],
                [
                    'AwardId' => 21,
                    'IsLadder' => 1,
                    'Rank' => 3,
                    'Name' => 'Order of the Rose',
                ],
            ],
        ]);

        $this->assertSame(0, $response['Status']);
        $ids = array_column($response['Detail'], 'AwardId');
        $this->assertNotContains(31, $ids);
        $this->assertContains(21, $ids);
    }

    public function testApproxWhenUnrankedExceedsHighestRankWithoutMaster(): void
    {
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                ['AwardId' => 21, 'IsLadder' => 1, 'Rank' => 2, 'Name' => 'Order of the Rose'],
                ['AwardId' => 21, 'IsLadder' => 1, 'Rank' => 0, 'Name' => 'Order of the Rose'],
                ['AwardId' => 21, 'IsLadder' => 1, 'Rank' => 0, 'Name' => 'Order of the Rose'],
            ],
        ]);

        $tile = $this->tileByAwardId($response['Detail'], 21);
        $this->assertTrue($tile['Approx']);
        $this->assertFalse($tile['HasMaster']);
        // effective = 1 ranked + 2 unranked = 3; max(2,3)=3
        $this->assertSame(3, $tile['Rank']);
        $this->assertSame(10, $tile['MaxRank']);
    }

    public function testApproxSuppressedWhenMasterHeld(): void
    {
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                ['AwardId' => 21, 'IsLadder' => 1, 'Rank' => 2, 'Name' => 'Order of the Rose'],
                ['AwardId' => 21, 'IsLadder' => 1, 'Rank' => 0, 'Name' => 'Order of the Rose'],
                ['AwardId' => 21, 'IsLadder' => 1, 'Rank' => 0, 'Name' => 'Order of the Rose'],
                ['AwardId' => 1, 'IsLadder' => 0, 'Rank' => 0, 'Name' => 'Master Rose', 'IsTitle' => 1],
            ],
        ]);

        $tile = $this->tileByAwardId($response['Detail'], 21);
        $this->assertTrue($tile['HasMaster']);
        $this->assertFalse($tile['Approx']);
    }

    public function testSyntheticMasterTileWhenNoLadderRows(): void
    {
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                ['AwardId' => 1, 'IsLadder' => 0, 'Rank' => 0, 'Name' => 'Master Rose', 'IsTitle' => 1],
            ],
        ]);

        $tile = $this->tileByAwardId($response['Detail'], 21);
        $this->assertTrue($tile['HasMaster']);
        $this->assertFalse($tile['Approx']);
        $this->assertSame(10, $tile['Rank']);
        $this->assertSame(10, $tile['MaxRank']);
        $this->assertSame('Order of the Rose', $tile['Name']);
        $this->assertSame('Rose', $tile['Short']);
    }

    public function testZodiacMaxRankTwelve(): void
    {
        // Order of the Zodiac (award_id 30) is a monthly ladder: MaxRank stays the
        // ceremonial 12, but 'Rank' now mirrors the uncapped grant Count, not a
        // clamped rank. A single legacy grant with no recorded month is one grant.
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                ['AwardId' => 30, 'IsLadder' => 1, 'Rank' => 12, 'Name' => 'Order of the Zodiac'],
            ],
        ]);

        $tile = $this->tileByAwardId($response['Detail'], 30);
        $this->assertSame(12, $tile['MaxRank']);
        $this->assertSame(1, $tile['Rank']);
        $this->assertSame(1, $tile['Count']);
        $this->assertSame([], $tile['MonthsHeld'], 'legacy rank is never read as a month');
        $this->assertSame(1, $tile['Unmonthed']);
    }

    public function testDuplicateRanksDoNotInflateEffectiveCount(): void
    {
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                ['AwardId' => 22, 'IsLadder' => 1, 'Rank' => 5, 'Name' => 'Order of the Smith'],
                ['AwardId' => 22, 'IsLadder' => 1, 'Rank' => 5, 'Name' => 'Order of the Smith'],
                ['AwardId' => 22, 'IsLadder' => 1, 'Rank' => 4, 'Name' => 'Order of the Smith'],
            ],
        ]);

        $tile = $this->tileByAwardId($response['Detail'], 22);
        $this->assertFalse($tile['Approx']);
        $this->assertSame(5, $tile['Rank']);
    }

    public function testTilesSortedByName(): void
    {
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                ['AwardId' => 25, 'IsLadder' => 1, 'Rank' => 1, 'Name' => 'Order of the Dragon'],
                ['AwardId' => 21, 'IsLadder' => 1, 'Rank' => 1, 'Name' => 'Order of the Rose'],
            ],
        ]);

        $names = array_column($response['Detail'], 'Name');
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names);
    }

    public function testTwoKingdomLaddersSharingAnAwardIdRenderAsTwoTiles(): void
    {
        // Defect X2. Nine Blades' Hardcore and Sharpshooter both hang off the
        // generic 94 "Custom Award" placeholder. Bucketed on award_id they merged
        // into one tile: Sharpshooter vanished and its grants inflated Hardcore's
        // count. Six players hold two ka-94 ladders today.
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                $this->kingdomLadderRow(94, 6283, 'Order of the Hardcore', 8),
                $this->kingdomLadderRow(94, 6283, 'Order of the Hardcore', 6),
                $this->kingdomLadderRow(94, 7055, 'Order of the Sharpshooter', 7),
            ],
        ]);

        $hardcore = $this->tileByKingdomAwardId($response['Detail'], 6283);
        $sharpshooter = $this->tileByKingdomAwardId($response['Detail'], 7055);

        $this->assertSame('Order of the Hardcore', $hardcore['Name']);
        $this->assertSame(8, $hardcore['Rank'], "Sharpshooter's grant must not inflate Hardcore");
        $this->assertSame('Order of the Sharpshooter', $sharpshooter['Name']);
        $this->assertSame(7, $sharpshooter['Rank']);

        // Both carry the same award_id -- which is exactly why award_id cannot be
        // the bucket key, and why the tile has to say which kingdomaward it is.
        $this->assertSame(94, $hardcore['AwardId']);
        $this->assertSame(94, $sharpshooter['AwardId']);
        $this->assertSame('kingdom', $hardcore['Scope']);
        $this->assertSame('kingdom', $sharpshooter['Scope']);
    }

    public function testAKingdomLadderWithAwardIdZeroStillGetsATile(): void
    {
        // 17 of 26 kingdom-ladder rows carry ka.award_id = 0. The old `$aid <= 0`
        // guard dropped every one of them before they reached a tile -- 56 players
        // hold two such ladders and saw neither.
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                $this->kingdomLadderRow(0, 5813, 'Order of the Roach', 1),
                $this->kingdomLadderRow(0, 6628, 'Order of the Juror', 2),
            ],
        ]);

        $roach = $this->tileByKingdomAwardId($response['Detail'], 5813);
        $juror = $this->tileByKingdomAwardId($response['Detail'], 6628);

        $this->assertSame(0, $roach['AwardId']);
        $this->assertSame(1, $roach['Rank']);
        $this->assertSame(2, $juror['Rank']);
    }

    public function testAKingdomLadderTakesItsHeightFromKaMaxLevel(): void
    {
        // MaxRankFor() is handed 0 for a kingdom ladder, so it falls through to
        // ka.max_level rather than reading the placeholder award_id's height.
        $row = $this->kingdomLadderRow(94, 6577, 'Order of the Awesome', 5);
        $row['KaMaxLevel'] = 6;

        $response = $this->player->GetLadderProgress(['MundaneId' => 0, 'Awards' => [$row]]);

        $this->assertSame(6, $this->tileByKingdomAwardId($response['Detail'], 6577)['MaxRank']);
    }

    public function testAKingdomLadderNeverBorrowsAnOfficialMasterCompanion(): void
    {
        // A kingdom ladder raised on a shared award_id must not inherit the
        // Master-peerage suppression that belongs to the official ladder of the
        // same id. Award 21 is Order of the Rose; award 1 is Master Rose.
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                $this->kingdomLadderRow(21, 9001, 'A Kingdom Rose', 2),
                $this->kingdomLadderRow(21, 9001, 'A Kingdom Rose', 0),
                $this->kingdomLadderRow(21, 9001, 'A Kingdom Rose', 0),
                ['AwardId' => 1, 'IsLadder' => 0, 'Rank' => 0, 'Name' => 'Master Rose', 'IsTitle' => 1, 'OfficialIsLadder' => 0],
            ],
        ]);

        $tile = $this->tileByKingdomAwardId($response['Detail'], 9001);
        $this->assertFalse($tile['HasMaster'], 'a kingdom ladder has no Master companion');
        $this->assertTrue($tile['Approx']);
    }

    public function testOfficialLaddersStillBucketAcrossKingdomawards(): void
    {
        // The official key space is unchanged: one tile per award_id, whatever
        // kingdomaward the grants sit on. A player who transferred kingdoms still
        // reads as holding one Order of the Rose, not two.
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                ['AwardId' => 21, 'OfficialIsLadder' => 1, 'KingdomAwardId' => 5449, 'IsLadder' => 1, 'Rank' => 3, 'Name' => 'Order of the Rose'],
                ['AwardId' => 21, 'OfficialIsLadder' => 1, 'KingdomAwardId' => 3016, 'IsLadder' => 1, 'Rank' => 4, 'Name' => 'Order of the Rose'],
            ],
        ]);

        $this->assertCount(1, $response['Detail']);
        $tile = $this->tileByAwardId($response['Detail'], 21);
        $this->assertSame('official', $tile['Scope']);
        $this->assertSame(0, $tile['KingdomAwardId']);
        $this->assertSame(4, $tile['Rank']);
    }

    public function testWalkerIsStillSkippedWithTheOfficialFlagPresent(): void
    {
        // The Walker exclusion lives on the official branch now; prove it still
        // fires when the row carries the flag that routes it there.
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                ['AwardId' => 31, 'OfficialIsLadder' => 1, 'KingdomAwardId' => 4242, 'IsLadder' => 1, 'Rank' => 5, 'Name' => 'Walker of the Middle'],
            ],
        ]);

        $this->assertSame([], $response['Detail']);
    }

    /**
     * One row in AwardsForPlayer()'s shape for a KINGDOM ladder: the kingdom
     * raised it (IsLadder = 1, the effective flag) but the official ork_award row
     * behind it is not a ladder (OfficialIsLadder = 0), which is what routes the
     * row into the kingdomaward key space.
     *
     * @return array<string, mixed>
     */
    private function kingdomLadderRow(int $awardId, int $kingdomAwardId, string $name, int $rank): array
    {
        return [
            'AwardId' => $awardId,
            'KingdomAwardId' => $kingdomAwardId,
            'IsLadder' => 1,
            'OfficialIsLadder' => 0,
            'KaMaxLevel' => 0,
            'Rank' => $rank,
            'Date' => '',
            'KingdomAwardName' => $name,
            'Name' => $name,
        ];
    }

    /**
     * @param list<array<string, mixed>> $tiles
     * @return array<string, mixed>
     */
    private function tileByKingdomAwardId(array $tiles, int $kingdomAwardId): array
    {
        foreach ($tiles as $tile) {
            if ((int)($tile['KingdomAwardId'] ?? 0) === $kingdomAwardId) {
                return $tile;
            }
        }
        $this->fail('Missing ladder tile for KingdomAwardId ' . $kingdomAwardId);
    }

    /**
     * @param list<array<string, mixed>> $tiles
     * @return array<string, mixed>
     */
    private function tileByAwardId(array $tiles, int $awardId): array
    {
        foreach ($tiles as $tile) {
            if ((int)$tile['AwardId'] === $awardId) {
                return $tile;
            }
        }
        $this->fail('Missing ladder tile for AwardId ' . $awardId);
    }
}
