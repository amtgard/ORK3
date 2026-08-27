<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bonus grants: an unranked ladder grant dated after the player reached max rank is
 * deliberate recognition, not a broken record. It must not set ~ or inflate the rank.
 */
final class LadderProgressBonusTest extends TestCase
{
    /**
     * @param list<array{Rank: int, Date: string}> $grants
     * @return array{Rank: int, Approx: bool, BonusCount: int, MaxRank: int}
     */
    private function progressFor(array $grants, int $awardId = 21): array
    {
        $player = new Player();

        return $player->ClassifyLadderGrants($awardId, 0, $grants, false);
    }

    public function testUnrankedGrantAfterMaxIsBonus(): void
    {
        $grants = [];
        for ($rank = 1; $rank <= 10; $rank++) {
            $grants[] = ['Rank' => $rank, 'Date' => '2020-0' . min(9, $rank) . '-01'];
        }
        $grants[] = ['Rank' => 0, 'Date' => '2024-01-01'];
        $grants[] = ['Rank' => 0, 'Date' => '2025-01-01'];

        $result = $this->progressFor($grants);

        $this->assertSame(2, $result['BonusCount']);
        $this->assertFalse($result['Approx'], 'bonus grants must not set the ~ marker');
        $this->assertSame(10, $result['Rank'], 'bonus grants must not inflate the rank');
    }

    public function testUnrankedGrantBeforeMaxIsStillUnreconciled(): void
    {
        $grants = [
            ['Rank' => 0, 'Date' => '2019-01-01'],
        ];
        for ($rank = 1; $rank <= 10; $rank++) {
            $grants[] = ['Rank' => $rank, 'Date' => '2020-0' . min(9, $rank) . '-01'];
        }

        $result = $this->progressFor($grants);

        $this->assertSame(0, $result['BonusCount']);
        $this->assertTrue($result['Approx'], 'a pre-max unranked grant is still broken data');
    }

    public function testTieOnTheMaxRankDateCountsAsUnreconciled(): void
    {
        // The conservative side of the line: a false "needs reconciling" is a
        // dismissible prompt; a false "bonus" silently hides a broken record.
        $grants = [];
        for ($rank = 1; $rank <= 9; $rank++) {
            $grants[] = ['Rank' => $rank, 'Date' => '2020-0' . $rank . '-01'];
        }
        $grants[] = ['Rank' => 10, 'Date' => '2021-06-01'];
        $grants[] = ['Rank' => 0,  'Date' => '2021-06-01'];

        $result = $this->progressFor($grants);

        $this->assertSame(0, $result['BonusCount']);
        $this->assertTrue($result['Approx']);
    }

    public function testPlayerWhoNeverReachedMaxHasNoBonusGrants(): void
    {
        // Matches today's behaviour exactly for the entire legacy corpus.
        $grants = [
            ['Rank' => 1, 'Date' => '2020-01-01'],
            ['Rank' => 2, 'Date' => '2020-02-01'],
            ['Rank' => 0, 'Date' => '2024-01-01'],
        ];

        $result = $this->progressFor($grants);

        $this->assertSame(0, $result['BonusCount']);
        $this->assertTrue($result['Approx']);
    }

    public function testKingdomLadderUsesItsOwnMaxRank(): void
    {
        $grants = [];
        for ($rank = 1; $rank <= 5; $rank++) {
            $grants[] = ['Rank' => $rank, 'Date' => '2020-0' . $rank . '-01'];
        }
        $grants[] = ['Rank' => 0, 'Date' => '2024-01-01'];

        $player = new Player();
        $result = $player->ClassifyLadderGrants(0, 5, $grants, false);

        $this->assertSame(5, $result['MaxRank']);
        $this->assertSame(1, $result['BonusCount'], 'max is 5 here, so the late unranked grant is bonus');
    }

    public function testHasMasterStillSuppressesTheMarker(): void
    {
        $grants = [
            ['Rank' => 1, 'Date' => '2020-01-01'],
            ['Rank' => 0, 'Date' => '2020-02-01'],
        ];

        $player = new Player();
        $result = $player->ClassifyLadderGrants(21, 0, $grants, true);

        $this->assertFalse($result['Approx'], 'existing HasMaster suppression is unchanged');
    }

    public function testPreExistingRankAboveMaxIsStillClampedForDisplay(): void
    {
        // Characterisation, not a new rule: the tile has always clamped a display
        // rank to max, and Playernew_index.tpl:2127 clamps again downstream. The
        // spec's "ranks above max are not rewritten" is about the ork_awards.rank
        // column, which nothing here writes — asserted in EditAwardLadderTest.
        $grants = [['Rank' => 14, 'Date' => '2015-01-01']];

        $result = $this->progressFor($grants);

        $this->assertSame(10, $result['Rank']);
    }

    public function testAZeroDateAtMaxRankCannotAnchorTheBonusWindow(): void
    {
        // 3,975 ladder grants carry '0000-00-00' and 28 sit at rank >= 10. If one
        // anchored $maxReached, every later unranked grant would read as bonus and
        // ~ would vanish — hiding exactly the broken records reconciliation exists
        // to surface. The conservative reading wins: no usable anchor, no bonus.
        $grants = [];
        for ($rank = 1; $rank <= 9; $rank++) {
            $grants[] = ['Rank' => $rank, 'Date' => '2020-0' . $rank . '-01'];
        }
        $grants[] = ['Rank' => 10, 'Date' => '0000-00-00'];
        $grants[] = ['Rank' => 0,  'Date' => '2024-01-01'];

        $result = $this->progressFor($grants);

        $this->assertSame(0, $result['BonusCount'], 'a zero date must not anchor the window');
        $this->assertTrue($result['Approx'], 'the unranked grant is still unreconciled');
    }

    public function testAnUnrankedGrantWithNoUsableDateIsNeverBonus(): void
    {
        // "Later than" is unanswerable for a dateless grant, so it stays reconcilable.
        $grants = [];
        for ($rank = 1; $rank <= 10; $rank++) {
            $grants[] = ['Rank' => $rank, 'Date' => '2020-0' . min(9, $rank) . '-01'];
        }
        $grants[] = ['Rank' => 0, 'Date' => '0000-00-00'];

        $result = $this->progressFor($grants);

        $this->assertSame(0, $result['BonusCount']);
        $this->assertTrue($result['Approx']);
    }

    // -----------------------------------------------------------------
    // Task 10A: BonusCount reaches the tiles; reconcile rows resolve
    // MaxRank and IsBonus in the domain.
    // -----------------------------------------------------------------

    public function testEveryTileCarriesBonusCountIncludingTheSyntheticMasterTile(): void
    {
        $player = new Player();

        $result = $player->GetLadderProgress([
            'Awards' => [
                // Order of the Rose (21): reaches max rank 10 in 2020, plus one
                // bonus grant recorded in 2024 -- exercises the classified-loop
                // tile-build site.
                ['AwardId' => 21, 'IsLadder' => 1, 'Rank' => 10, 'Date' => '2020-01-01', 'Name' => 'Order of the Rose'],
                ['AwardId' => 21, 'IsLadder' => 1, 'Rank' => 0, 'Date' => '2024-01-01', 'Name' => 'Order of the Rose'],
                // Master Smith (award 2) held with no Order of the Smith (22)
                // grants at all -- exercises the synthetic-master-tile site.
                ['AwardId' => 2, 'IsLadder' => 0, 'Rank' => 0, 'Date' => '2021-01-01', 'Name' => 'Master Smith'],
            ],
        ]);

        $this->assertSame(0, (int) $result['Status']);
        $tiles = $result['Detail'];
        $this->assertNotEmpty($tiles);

        $byAwardId = [];
        foreach ($tiles as $tile) {
            $this->assertArrayHasKey('BonusCount', $tile, ($tile['Name'] ?? '?') . ' tile is missing BonusCount');
            $byAwardId[(int) $tile['AwardId']] = $tile;
        }

        $this->assertSame(1, $byAwardId[21]['BonusCount'], 'classified-loop tile');
        $this->assertArrayHasKey(22, $byAwardId, 'Master Smith must synthesize an Order of the Smith tile');
        $this->assertSame(0, $byAwardId[22]['BonusCount'], 'synthetic master tile must carry BonusCount = 0 explicitly');
    }

    /**
     * @return array<string, mixed>
     */
    private function historicalRow(int $awardsId, int $awardId, int $rank, string $date, int $kaMaxLevel = 0): array
    {
        return [
            'AwardsId' => $awardsId,
            'AwardId' => $awardId,
            'Rank' => $rank,
            'IsLadder' => 1,
            'GivenById' => 0,
            'EnteredById' => 0,
            'Date' => $date,
            'OfficerRole' => 'none',
            'IsTitle' => 0,
            'KaMaxLevel' => $kaMaxLevel,
            'Name' => 'Award ' . $awardId,
        ];
    }

    /**
     * An already-reconciled (attributed) grant: excluded from HistoricalAwards,
     * but must still be seen by the max-reached-date window -- that is exactly
     * what "full grant history, not just the unreconciled subset" means.
     *
     * @return array<string, mixed>
     */
    private function realRow(int $awardsId, int $awardId, int $rank, string $date, int $kaMaxLevel = 0): array
    {
        return [
            'AwardsId' => $awardsId,
            'AwardId' => $awardId,
            'Rank' => $rank,
            'IsLadder' => 1,
            'GivenById' => 1,
            'EnteredById' => 1,
            'Date' => $date,
            'OfficerRole' => 'none',
            'IsTitle' => 0,
            'KaMaxLevel' => $kaMaxLevel,
            'Name' => 'Award ' . $awardId,
        ];
    }

    public function testReconcileRowResolvesMaxRankForOfficialAndKingdomLadders(): void
    {
        $player = new Player();

        $dto = $player->GetReconcileSuggestions([
            // Order of the Rose (21), official ladder, max 10.
            $this->historicalRow(1, 21, 0, '2020-01-01'),
            // Order of the Zodiac (30), official ladder, max 12 -- the map
            // override wins even though a (wrong) ka_max_level of 10 rides along.
            $this->historicalRow(2, 30, 0, '2020-01-01', 10),
            // A kingdom-raised ladder (award_id 0) with its own max_level of 5.
            $this->historicalRow(3, 0, 0, '2020-01-01', 5),
        ]);

        $byAwardsId = [];
        foreach ($dto['HistoricalAwards'] as $row) {
            $byAwardsId[$row['AwardsId']] = $row;
        }

        $this->assertSame(10, $byAwardsId[1]['MaxRank'], 'Order of the Rose');
        $this->assertSame(12, $byAwardsId[2]['MaxRank'], 'Order of the Zodiac');
        $this->assertSame(5, $byAwardsId[3]['MaxRank'], "a kingdom ladder's own max_level");
    }

    public function testReconcileRowFlagsBonusGrantsAndLeavesPreMaxGrantsAlone(): void
    {
        $player = new Player();

        $dto = $player->GetReconcileSuggestions([
            // Reaches max rank 10 in 2020 via a real (already-attributed) grant --
            // present only to anchor the window, never itself reconcilable.
            $this->realRow(100, 21, 10, '2020-01-01'),
            // Historical unranked grant dated AFTER max was reached -- bonus.
            $this->historicalRow(1, 21, 0, '2024-01-01'),
            // Historical unranked grant dated BEFORE max was reached -- not
            // bonus, still reconcilable.
            $this->historicalRow(2, 21, 0, '2019-01-01'),
        ]);

        $byAwardsId = [];
        foreach ($dto['HistoricalAwards'] as $row) {
            $byAwardsId[$row['AwardsId']] = $row;
        }

        $this->assertArrayNotHasKey(100, $byAwardsId, 'the real/attributed row is not itself a reconcile row');
        $this->assertTrue($byAwardsId[1]['IsBonus'], 'unranked grant dated after max was reached');
        $this->assertFalse($byAwardsId[2]['IsBonus'], 'unranked grant dated before max was reached');
    }
}
