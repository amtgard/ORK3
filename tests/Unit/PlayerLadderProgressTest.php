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
        $response = $this->player->GetLadderProgress([
            'MundaneId' => 0,
            'Awards' => [
                ['AwardId' => 30, 'IsLadder' => 1, 'Rank' => 12, 'Name' => 'Order of the Zodiac'],
            ],
        ]);

        $tile = $this->tileByAwardId($response['Detail'], 30);
        $this->assertSame(12, $tile['MaxRank']);
        $this->assertSame(12, $tile['Rank']);
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
