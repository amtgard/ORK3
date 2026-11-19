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
        $this->assertSame('IFNULL(a.is_ladder, 0) = 1', Award::OfficialLadderSql());
        $this->assertSame('IFNULL(aw.is_ladder, 0) = 1', Award::OfficialLadderSql('aw'));
    }

    /**
     * The whole point of the IFNULL: a bare `a.is_ladder = 1` is NULL for a
     * LEFT JOIN that matched nothing, and `NOT (NULL)` is NULL, which fails a
     * WHERE clause exactly as an INNER join's drop would. Report::GetLadderAwardGrid
     * puts this predicate under NOT (...) over a LEFT JOIN, so an unsafe spelling
     * silently hides every pure kingdom ladder (ka.award_id = 0 -> no ork_award row).
     */
    public function testOfficialLadderSqlIsNullSafeSoItCanSitUnderNot(): void
    {
        $this->assertNotSame(
            'a.is_ladder = 1',
            Award::OfficialLadderSql(),
            'OfficialLadderSql() must never emit a bare, NULL-unsafe comparison'
        );
        $this->assertStringStartsWith('IFNULL(', Award::OfficialLadderSql());
        $this->assertStringStartsWith('IFNULL(', Award::OfficialLadderSql('a', 'alias'));
    }

    /**
     * The optional alias leg. A Custom Title aliased to a peerage award reads its
     * flags off the alias TARGET, so three queries used to hand-roll
     * COALESCE(alias.is_ladder, a.is_ladder) around the helper's body rather than
     * calling it -- a sixth and seventh spelling waiting to drift.
     */
    public function testLadderSqlFoldsInTheCustomTitleAliasLeg(): void
    {
        $this->assertSame(
            'GREATEST(IFNULL(ka.is_ladder, 0), IFNULL(COALESCE(alias.is_ladder, a.is_ladder), 0))',
            Award::LadderSql('ka', 'a', 'alias')
        );
    }

    public function testOfficialLadderSqlFoldsInTheCustomTitleAliasLeg(): void
    {
        $this->assertSame(
            'IFNULL(COALESCE(alias.is_ladder, a.is_ladder), 0) = 1',
            Award::OfficialLadderSql('a', 'alias')
        );
    }

    /**
     * Adding the alias leg must not have moved the two-alias output by one byte --
     * roughly fifteen call sites depend on it and none of them passes an alias.
     */
    public function testAddingTheAliasLegLeftTheTwoAliasFormByteIdentical(): void
    {
        $this->assertSame(
            'GREATEST(IFNULL(ka.is_ladder, 0), IFNULL(a.is_ladder, 0))',
            Award::LadderSql('ka', 'a', null)
        );
        $this->assertSame(Award::LadderSql(), Award::LadderSql('ka', 'a', null));
        $this->assertSame(Award::OfficialLadderSql(), Award::OfficialLadderSql('a', null));
    }

    /**
     * The invariant this whole helper exists to hold: exactly one spelling of
     * "is this a ladder?" in SQL. Three domain sites used to hand-roll
     * GREATEST(IFNULL(COALESCE(alias.is_ladder, a.is_ladder), 0), IFNULL(ka.is_ladder, 0))
     * longhand because the helper took only two aliases; they agreed with it by
     * luck, and would have silently fallen behind the NULL-safety fix above. The
     * $alias parameter removed the reason to fork, so nothing in the domain layer
     * may spell the ladder columns out by hand again.
     */
    public function testNoDomainFileHandRollsTheLadderPredicate(): void
    {
        $dir = dirname(__DIR__, 2) . '/system/lib/ork3';
        $offenders = [];
        foreach (glob($dir . '/class.*.php') as $file) {
            if (basename($file) === 'class.Award.php') {
                continue; // the helper's own body is the one legal spelling
            }
            $source = file_get_contents($file);
            if (preg_match('/COALESCE\(\s*alias\.is_ladder/i', $source)
                || preg_match('/GREATEST\(\s*IFNULL\(\s*(ka|a)\.is_ladder/i', $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these files hand-roll the ladder predicate instead of calling Award::LadderSql()/OfficialLadderSql()'
        );
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
