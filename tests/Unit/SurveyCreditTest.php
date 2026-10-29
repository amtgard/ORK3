<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Pure pieces of SurveyCredit (sharing-and-credits spec §3). No DB. */
final class SurveyCreditTest extends TestCase
{
    private const KSURVEY = ['scope_type' => 'kingdom', 'scope_id' => 17, 'audience_kingdom_ids' => null];

    private static function cfg(int $id, string $type, int $gid, string $mode, string $at): array
    {
        return ['credit_id' => $id, 'grantor_type' => $type, 'grantor_id' => $gid, 'mode' => $mode, 'enabled_at' => $at];
    }

    public function testNoteFitsTheTwentyCharacterColumnForTenDigitIds(): void
    {
        $this->assertSame('Survey #42', SurveyCredit::noteFor(42));
        $this->assertLessThanOrEqual(20, strlen(SurveyCredit::noteFor(4294967295)));
    }

    public function testEventNameIsPrefixedAndCutToOneHundredMultibyteCharacters(): void
    {
        $this->assertSame('Survey Credit - Voice of the Kingdom', SurveyCredit::eventName('  Voice of the Kingdom '));
        $long = SurveyCredit::eventName(str_repeat('é', 150));
        $this->assertSame(100, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
        $this->assertStringStartsWith('Survey Credit - ', $long);
    }

    public function testStartDateIsTheLaterOfOpenedAndScheduledOpen(): void
    {
        $this->assertNull(SurveyCredit::startDate(['opened_at' => null, 'open_at' => '2026-09-01 00:00:00']));
        $this->assertSame('2026-09-05', SurveyCredit::startDate(['opened_at' => '2026-09-05 13:00:00', 'open_at' => null]));
        $this->assertSame('2026-09-20', SurveyCredit::startDate(['opened_at' => '2026-09-05 13:00:00', 'open_at' => '2026-09-20 08:00:00']));
        $this->assertSame('2026-09-05', SurveyCredit::startDate(['opened_at' => '2026-09-05 13:00:00', 'open_at' => '2026-08-01 08:00:00']));
    }

    public function testClassFallsBackToColor(): void
    {
        $this->assertSame(6, SurveyCredit::classFor(0));
        $this->assertSame(3, SurveyCredit::classFor(3));
    }

    public function testGrantorReachMatrix(): void
    {
        $park = ['scope_type' => 'park', 'scope_id' => 1049];
        $this->assertTrue(SurveyCredit::grantorReaches($park, 'park', 1049, 17, 0));
        $this->assertFalse(SurveyCredit::grantorReaches($park, 'park', 1050, 17, 0));
        $this->assertFalse(SurveyCredit::grantorReaches($park, 'kingdom', 17, 17, 0));

        $this->assertTrue(SurveyCredit::grantorReaches(self::KSURVEY, 'kingdom', 17, 17, 0));
        $this->assertTrue(SurveyCredit::grantorReaches(self::KSURVEY, 'kingdom', 90, 90, 17));  // principality of 17
        $this->assertTrue(SurveyCredit::grantorReaches(self::KSURVEY, 'park', 5, 90, 17));       // park in that principality
        $this->assertFalse(SurveyCredit::grantorReaches(self::KSURVEY, 'park', 6, 44, 0));       // another kingdom's park

        $ork = ['scope_type' => 'ork', 'scope_id' => 0, 'audience_kingdom_ids' => '[17]'];
        $this->assertTrue(SurveyCredit::grantorReaches($ork, 'kingdom', 17, 17, 0));
        $this->assertTrue(SurveyCredit::grantorReaches($ork, 'park', 5, 90, 17));
        $this->assertFalse(SurveyCredit::grantorReaches($ork, 'kingdom', 44, 44, 0));
        $this->assertTrue(SurveyCredit::grantorReaches(['scope_type' => 'ork', 'scope_id' => 0, 'audience_kingdom_ids' => null], 'kingdom', 44, 44, 0));
    }

    public function testEarliestCoveringConfigWinsWhicheverLevelCameFirst(): void
    {
        $kingdomFirst = [self::cfg(2, 'park', 1049, 'home_park', '2026-09-02 00:00:00'), self::cfg(1, 'kingdom', 17, 'home_park', '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($kingdomFirst, ['park_id' => 1049, 'kingdom_id' => 17], self::KSURVEY, [])['credit_id']);

        $parkFirst = [self::cfg(1, 'kingdom', 17, 'home_park', '2026-09-03 00:00:00'), self::cfg(2, 'park', 1049, 'home_park', '2026-09-02 00:00:00')];
        $this->assertSame(2, SurveyCredit::coverage($parkFirst, ['park_id' => 1049, 'kingdom_id' => 17], self::KSURVEY, [])['credit_id']);
    }

    public function testTiesOnEnabledAtGoToTheLowerId(): void
    {
        $tie = [self::cfg(8, 'kingdom', 17, 'home_park', '2026-09-01 00:00:00'), self::cfg(7, 'park', 1049, 'home_park', '2026-09-01 00:00:00')];
        $this->assertSame(7, SurveyCredit::coverage($tie, ['park_id' => 1049, 'kingdom_id' => 17], self::KSURVEY, [])['credit_id']);
    }

    public function testKingdomConfigCoversItsPrincipalityPlayers(): void
    {
        $c = [self::cfg(1, 'kingdom', 17, 'home_park', '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($c, ['park_id' => 5, 'kingdom_id' => 90], self::KSURVEY, [90 => 17])['credit_id']);
        $this->assertNull(SurveyCredit::coverage($c, ['park_id' => 6, 'kingdom_id' => 44], self::KSURVEY, [90 => 17])['credit_id']);
    }

    public function testOwnersEventConfigCoversVisitorsButANonOwnersDoesNot(): void
    {
        $owner = [self::cfg(1, 'kingdom', 17, 'event', '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($owner, ['park_id' => 6, 'kingdom_id' => 44], self::KSURVEY, [])['credit_id']);

        $ork = ['scope_type' => 'ork', 'scope_id' => 0, 'audience_kingdom_ids' => null];
        $this->assertNull(SurveyCredit::coverage($owner, ['park_id' => 6, 'kingdom_id' => 44], $ork, [])['credit_id']);
    }

    public function testHomeParkModeSkipsARespondentWithNoParkAndSaysWhy(): void
    {
        $c = [self::cfg(1, 'kingdom', 17, 'home_park', '2026-09-01 00:00:00')];
        $cov = SurveyCredit::coverage($c, ['park_id' => null, 'kingdom_id' => 17], self::KSURVEY, []);
        $this->assertNull($cov['credit_id']);
        $this->assertTrue($cov['no_home_park']);

        $withEvent = array_merge($c, [self::cfg(2, 'kingdom', 17, 'event', '2026-09-02 00:00:00')]);
        $this->assertSame(2, SurveyCredit::coverage($withEvent, ['park_id' => null, 'kingdom_id' => 17], self::KSURVEY, [])['credit_id']);
    }

    public function testNoConfigsCoverNobody(): void
    {
        $this->assertSame(['credit_id' => null, 'no_home_park' => false], SurveyCredit::coverage([], ['park_id' => 1, 'kingdom_id' => 17], self::KSURVEY, []));
    }
}
