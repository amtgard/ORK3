<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Pure pieces of SurveyCredit (sharing-and-credits spec §3). No DB. */
final class SurveyCreditTest extends TestCase
{
    private const KSURVEY = ['scope_type' => 'kingdom', 'scope_id' => 17, 'audience_kingdom_ids' => null];
    private const PSURVEY = ['scope_type' => 'park', 'scope_id' => 1049];
    private const ORK     = ['scope_type' => 'ork', 'scope_id' => 0, 'audience_kingdom_ids' => null];

    private static function cfg(int $id, string $type, int $gid, string $at, ?string $off = null): array
    {
        return ['credit_id' => $id, 'grantor_type' => $type, 'grantor_id' => $gid, 'enabled_at' => $at, 'disabled_at' => $off];
    }

    /** A respondent at park 1049 in kingdom 17 who answered at $at. */
    private static function who(int $park, int $kingdom, string $at = '2026-09-10 12:00:00'): array
    {
        return ['park_id' => $park, 'kingdom_id' => $kingdom, 'submitted_at' => $at];
    }

    public function testNoteFitsTheColumnForTenDigitIds(): void
    {
        $this->assertSame('Survey #42', SurveyCredit::noteFor(42));
        $this->assertLessThanOrEqual(200, strlen(SurveyCredit::noteFor(4294967295)));
    }

    public function testCategoryNameIsPrefixedAndCutToOneHundredMultibyteCharacters(): void
    {
        $this->assertSame('Survey Bonus - Voice of the Kingdom', SurveyCredit::categoryName('  Voice  of the Kingdom '));
        $long = SurveyCredit::categoryName(str_repeat('é', 150));
        $this->assertSame(100, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
        $this->assertStringStartsWith('Survey Bonus - ', $long);
    }

    public function testTheClashSuffixIsKeptWholeAndTheTitleGivesWay(): void
    {
        $this->assertSame('Survey Bonus - Voice of the Kingdom (#42)', SurveyCredit::categoryName('Voice of the Kingdom', 42));
        $long = SurveyCredit::categoryName(str_repeat('x', 150), 1234567890);
        $this->assertSame(100, mb_strlen($long));
        $this->assertStringEndsWith('… (#1234567890)', $long);
    }

    public function testClassFallsBackToColor(): void
    {
        $this->assertSame(6, SurveyCredit::classFor(0));
        $this->assertSame(3, SurveyCredit::classFor(3));
    }

    public function testGrantorReachMatrix(): void
    {
        $this->assertTrue(SurveyCredit::grantorReaches(self::PSURVEY, 'park', 1049, 17, 0));
        $this->assertFalse(SurveyCredit::grantorReaches(self::PSURVEY, 'park', 1050, 17, 0));
        $this->assertFalse(SurveyCredit::grantorReaches(self::PSURVEY, 'kingdom', 17, 17, 0));

        $this->assertTrue(SurveyCredit::grantorReaches(self::KSURVEY, 'kingdom', 17, 17, 0));
        $this->assertTrue(SurveyCredit::grantorReaches(self::KSURVEY, 'kingdom', 90, 90, 17));  // principality of 17
        $this->assertTrue(SurveyCredit::grantorReaches(self::KSURVEY, 'park', 5, 90, 17));       // park in that principality
        $this->assertFalse(SurveyCredit::grantorReaches(self::KSURVEY, 'park', 6, 44, 0));       // another kingdom's park

        $ork = ['scope_type' => 'ork', 'scope_id' => 0, 'audience_kingdom_ids' => '[17]'];
        $this->assertTrue(SurveyCredit::grantorReaches($ork, 'kingdom', 17, 17, 0));
        $this->assertTrue(SurveyCredit::grantorReaches($ork, 'park', 5, 90, 17));
        $this->assertFalse(SurveyCredit::grantorReaches($ork, 'kingdom', 44, 44, 0));
        $this->assertTrue(SurveyCredit::grantorReaches(self::ORK, 'kingdom', 44, 44, 0));
    }

    public function testEarliestCoveringSwitchWinsWhicheverLevelCameFirst(): void
    {
        $kingdomFirst = [self::cfg(2, 'park', 1049, '2026-09-02 00:00:00'), self::cfg(1, 'kingdom', 17, '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($kingdomFirst, self::who(1049, 17), self::KSURVEY, []));

        $parkFirst = [self::cfg(1, 'kingdom', 17, '2026-09-03 00:00:00'), self::cfg(2, 'park', 1049, '2026-09-02 00:00:00')];
        $this->assertSame(2, SurveyCredit::coverage($parkFirst, self::who(1049, 17), self::KSURVEY, []));
    }

    public function testTiesOnEnabledAtGoToTheLowerId(): void
    {
        $tie = [self::cfg(8, 'kingdom', 17, '2026-09-01 00:00:00'), self::cfg(7, 'park', 1049, '2026-09-01 00:00:00')];
        $this->assertSame(7, SurveyCredit::coverage($tie, self::who(1049, 17), self::KSURVEY, []));
    }

    public function testAParkSwitchCoversItsMembersOnly(): void
    {
        $c = [self::cfg(1, 'park', 1049, '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($c, self::who(1049, 17), self::KSURVEY, []));
        $this->assertNull(SurveyCredit::coverage($c, self::who(1050, 17), self::KSURVEY, []));
        $this->assertNull(SurveyCredit::coverage($c, self::who(0, 17), self::KSURVEY, []), 'no park snapshot: not a member');
    }

    public function testAKingdomSwitchCoversItsPrincipalityAndNeedsNoPark(): void
    {
        // Kingdom 90 is a principality of 17 and a grantor (not the owner) on 17's survey; 91 sits under 90.
        $c = [self::cfg(1, 'kingdom', 90, '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($c, self::who(5, 90), self::KSURVEY, [90 => 17]));
        $this->assertSame(1, SurveyCredit::coverage($c, self::who(7, 91), self::KSURVEY, [90 => 17, 91 => 90]), 'its own principality');
        $this->assertSame(1, SurveyCredit::coverage($c, self::who(0, 90), self::KSURVEY, [90 => 17]), 'a bonus credit needs no park');
        $this->assertNull(SurveyCredit::coverage($c, self::who(1049, 17), self::KSURVEY, [90 => 17]), 'not the kingdom above it');
        $this->assertNull(SurveyCredit::coverage($c, self::who(6, 44), self::KSURVEY, [90 => 17]));
    }

    public function testTheOwnersSwitchCoversVisitorsButANonOwnersDoesNot(): void
    {
        $owner = [self::cfg(1, 'kingdom', 17, '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($owner, self::who(6, 44), self::KSURVEY, []), 'kingdom 17 owns the survey');
        $this->assertNull(SurveyCredit::coverage($owner, self::who(6, 44), self::ORK, []), 'on an Amtgard-wide survey it is just a kingdom');

        $parkOwner = [self::cfg(1, 'park', 1049, '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($parkOwner, self::who(1050, 17), self::PSURVEY, []), 'a park survey\'s owner covers visitors from other parks');
    }

    public function testASwitchTurnedOffCoversOnlyResponsesSubmittedBeforeItWentOff(): void
    {
        $off = [self::cfg(1, 'kingdom', 17, '2026-09-01 00:00:00', '2026-09-10 12:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($off, self::who(1049, 17, '2026-09-10 11:59:59'), self::KSURVEY, []), 'answered while on: still owed');
        $this->assertNull(SurveyCredit::coverage($off, self::who(1049, 17, '2026-09-10 12:00:00'), self::KSURVEY, []), 'answered at or after the switch-off');
        $this->assertNull(SurveyCredit::coverage($off, self::who(1049, 17, '2026-09-11 00:00:00'), self::KSURVEY, []));

        // A later switch that is still on takes over for the later response.
        $both = array_merge($off, [self::cfg(2, 'park', 1049, '2026-09-05 00:00:00')]);
        $this->assertSame(1, SurveyCredit::coverage($both, self::who(1049, 17, '2026-09-09 00:00:00'), self::KSURVEY, []), 'the earlier switch still wins for the earlier response');
        $this->assertSame(2, SurveyCredit::coverage($both, self::who(1049, 17, '2026-09-11 00:00:00'), self::KSURVEY, []));
    }

    public function testSwitchStateHelpers(): void
    {
        $this->assertTrue(SurveyCredit::isOn(self::cfg(1, 'kingdom', 17, '2026-09-01 00:00:00')));
        $this->assertTrue(SurveyCredit::isOn(self::cfg(1, 'kingdom', 17, '2026-09-01 00:00:00', '0000-00-00 00:00:00')), 'sql_mode=\'\' zero date is "never"');
        $this->assertFalse(SurveyCredit::isOn(self::cfg(1, 'kingdom', 17, '2026-09-01 00:00:00', '2026-09-10 12:00:00')));
        $this->assertTrue(SurveyCredit::configLiveFor(self::cfg(1, 'kingdom', 17, '2026-09-01 00:00:00', '2026-09-10 12:00:00'), '2026-09-10 11:00:00'));
        $this->assertFalse(SurveyCredit::configLiveFor(self::cfg(1, 'kingdom', 17, '2026-09-01 00:00:00', '2026-09-10 12:00:00'), '2026-09-10 13:00:00'));
    }

    public function testNoSwitchesCoverNobody(): void
    {
        $this->assertNull(SurveyCredit::coverage([], self::who(1, 17), self::KSURVEY, []));
    }
}
