<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The ladder predicate helpers that replace five competing spellings of
 * "is this a ladder?" (kingdom-ladder-awards spec, section 1).
 */
final class LadderPredicateTest extends TestCase
{
    public function testLadderSqlIsAdditiveOverBothColumns(): void
    {
        $this->assertSame(
            'GREATEST(IFNULL(ka.is_ladder, 0), IFNULL(a.is_ladder, 0))',
            Award::LadderSql()
        );
    }

    public function testLadderSqlHonoursTableAliases(): void
    {
        $this->assertSame(
            'GREATEST(IFNULL(kaw.is_ladder, 0), IFNULL(aw.is_ladder, 0))',
            Award::LadderSql('kaw', 'aw')
        );
    }

    public function testOfficialLadderSqlKeysOnTheAwardTableOnly(): void
    {
        $this->assertSame('a.is_ladder = 1', Award::OfficialLadderSql());
        $this->assertSame('aw.is_ladder = 1', Award::OfficialLadderSql('aw'));
    }

    public function testMaxRankForZodiacIsTwelve(): void
    {
        // The special case currently written out three times: GetLadderMasterMap,
        // GetLadderProgress:1636, and Playernew_reconcile.tpl:185.
        $this->assertSame(12, Award::MaxRankFor(30));
    }

    public function testMaxRankForOtherOfficialLaddersIsTen(): void
    {
        $this->assertSame(10, Award::MaxRankFor(21));  // Order of the Rose
        $this->assertSame(10, Award::MaxRankFor(243)); // Order of Battle
    }

    public function testOfficialMaxRankIgnoresKingdomMaxLevel(): void
    {
        // ka.max_level is 0 on all official rows and must never override the map.
        $this->assertSame(12, Award::MaxRankFor(30, 5));
        $this->assertSame(10, Award::MaxRankFor(21, 7));
    }

    public function testKingdomLadderUsesItsOwnMaxLevel(): void
    {
        $this->assertSame(7, Award::MaxRankFor(0, 7));
        $this->assertSame(12, Award::MaxRankFor(0, 12));
    }

    public function testUnspecifiedMaxLevelFallsBackToTen(): void
    {
        $this->assertSame(10, Award::MaxRankFor(0, 0));
        $this->assertSame(10, Award::MaxRankFor(9999, 0));
    }

    public function testMaxRankForClampsToTwelve(): void
    {
        $this->assertSame(12, Award::MaxRankFor(0, 40));
    }

    public function testMaxRankForRejectsNegativeMaxLevel(): void
    {
        $this->assertSame(10, Award::MaxRankFor(0, -3));
    }

    public function testWalkerAndFlameFallThroughToTen(): void
    {
        // Neither is in GetLadderMasterMap. Flame's correct value is 10; the helper
        // makes that explicit instead of accidental.
        $this->assertSame(10, Award::MaxRankFor(31)); // Walker
        $this->assertSame(10, Award::MaxRankFor(34)); // Order of the Flame
    }

    public function testOnlyZodiacIsAMonthlyLadder(): void
    {
        $this->assertTrue(Award::IsMonthlyLadder(30));
        foreach (array_keys(Award::GetLadderMasterMap()) as $awardId) {
            if ($awardId === 30) {
                continue;
            }
            $this->assertFalse(
                Award::IsMonthlyLadder($awardId),
                "Award {$awardId} must not be a monthly ladder"
            );
        }
        $this->assertFalse(Award::IsMonthlyLadder(0));
        $this->assertFalse(Award::IsMonthlyLadder(31));
    }
}
