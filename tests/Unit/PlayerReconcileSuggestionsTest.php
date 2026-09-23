<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization for P3-R3 GetReconcileSuggestions smart-rank + partition.
 */
final class PlayerReconcileSuggestionsTest extends TestCase
{
    private Player $player;

    protected function setUp(): void
    {
        $this->player = new Player();
    }

    public function testPartitionsHistoricalLadderAndCollectsRealRanks(): void
    {
        $dto = $this->player->GetReconcileSuggestions([
            $this->row(101, 21, 2, true, 0, 0, '2020-01-01'), // historical ladder
            $this->row(102, 21, 5, true, 10, 10, '2021-01-01'), // real rose 5
            $this->row(103, 99, 0, false, 0, 0, '2020-02-01'), // historical non-ladder (dropped)
            $this->row(104, 22, 1, true, 0, 0, '2019-06-01'), // historical sword
            $this->row(105, 17, 0, true, 0, 0, '2018-01-01', true), // title — ignored
        ]);

        $this->assertTrue($dto['HasHistoricalLadder']);
        $this->assertSame(2, $dto['Summary']['TotalCount']);
        $this->assertSame(2, $dto['Summary']['AwardTypeCount']);
        $ids = array_column($dto['HistoricalAwards'], 'AwardsId');
        $this->assertSame([101, 104], $ids);
        $this->assertSame([5], $dto['RealRanksByAwardId'][21]);
        $this->assertArrayNotHasKey(99, $dto['RealRanksByAwardId']);
    }

    public function testSortsByAwardIdThenDateMissingLast(): void
    {
        $dto = $this->player->GetReconcileSuggestions([
            $this->row(1, 22, 0, true, 0, 0, '2022-01-01'),
            $this->row(2, 21, 0, true, 0, 0, ''),
            $this->row(3, 21, 0, true, 0, 0, '2020-05-01'),
            $this->row(4, 21, 0, true, 0, 0, '2019-01-01'),
        ]);

        $ids = array_column($dto['HistoricalAwards'], 'AwardsId');
        $this->assertSame([4, 3, 2, 1], $ids);
    }

    public function testPreferExistingRankWhenUnused(): void
    {
        $dto = $this->player->GetReconcileSuggestions([
            $this->row(10, 21, 3, true, 0, 0, '2020-01-01'), // historical with rank 3
            $this->row(11, 21, 1, true, 5, 5, '2021-01-01'), // real holds 1
        ]);

        $this->assertSame(3, $dto['RankSuggestions'][10]);
    }

    public function testSkipsExistingRankWhenHeldByReal(): void
    {
        $dto = $this->player->GetReconcileSuggestions([
            $this->row(10, 21, 3, true, 0, 0, '2020-01-01'),
            $this->row(11, 21, 3, true, 5, 5, '2021-01-01'), // real already has 3
        ]);

        // existing 3 taken by real → smallest free = 1
        $this->assertSame(1, $dto['RankSuggestions'][10]);
    }

    public function testAllocatesSmallestFreeAcrossHistoricalGroup(): void
    {
        $dto = $this->player->GetReconcileSuggestions([
            $this->row(1, 21, 0, true, 0, 0, '2018-01-01'),
            $this->row(2, 21, 0, true, 0, 0, '2019-01-01'),
            $this->row(3, 21, 2, true, 0, 0, '2020-01-01'), // would prefer 2, but 2 already taken
            $this->row(4, 21, 5, true, 9, 9, '2021-01-01'), // real holds 5
        ]);

        // Chronological: 1→1, 2→2, 3 (existing 2 used)→3
        $this->assertSame(1, $dto['RankSuggestions'][1]);
        $this->assertSame(2, $dto['RankSuggestions'][2]);
        $this->assertSame(3, $dto['RankSuggestions'][3]);
    }

    public function testMixedAwardGroupsIndependent(): void
    {
        $dto = $this->player->GetReconcileSuggestions([
            $this->row(1, 21, 0, true, 0, 0, '2020-01-01'),
            $this->row(2, 22, 0, true, 0, 0, '2020-01-01'),
            $this->row(3, 21, 1, true, 8, 8, '2021-01-01'), // real rose 1
        ]);

        $this->assertSame(2, $dto['RankSuggestions'][1]); // 1 taken by real
        $this->assertSame(1, $dto['RankSuggestions'][2]); // sword group free at 1
    }

    public function testEmptyAwardsHasNoHistorical(): void
    {
        $dto = $this->player->GetReconcileSuggestions([]);
        $this->assertFalse($dto['HasHistoricalLadder']);
        $this->assertSame(0, $dto['Summary']['TotalCount']);
        $this->assertSame([], $dto['HistoricalAwards']);
        $this->assertSame([], $dto['RankSuggestions']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        int $awardsId,
        int $awardId,
        int $rank,
        bool $isLadder,
        int $givenById,
        int $enteredById,
        string $date,
        bool $isTitle = false
    ): array {
        return [
            'AwardsId' => $awardsId,
            'AwardId' => $awardId,
            'Rank' => $rank,
            'IsLadder' => $isLadder ? 1 : 0,
            'GivenById' => $givenById,
            'EnteredById' => $enteredById,
            'Date' => $date,
            'OfficerRole' => 'none',
            'IsTitle' => $isTitle ? 1 : 0,
            'Name' => 'Award ' . $awardId,
        ];
    }
}
