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
}
