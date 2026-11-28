<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The pure parts of KnightCircle: eligibility rules, config normalisation, and
 * the order/ladder/master maps that must stay in step with Award and Report.
 * No database.
 */
final class KnightCirclePureTest extends TestCase
{
    /** @return array<string,mixed> */
    private function goodFacts(array $over = []): array
    {
        return array_merge([
            'SignIns' => 6, 'DuesPaid' => true, 'Waivered' => true, 'Resident' => true,
            'Suspended' => false, 'KnightMonths' => 24, 'VotingOk' => null,
        ], $over);
    }

    public function testDefaultsMakeAFullyActiveResidentEligible(): void
    {
        $r = KnightCircle::evaluateEligibility($this->goodFacts(), KnightCircle::DEFAULTS);
        $this->assertTrue($r['Eligible']);
        $this->assertSame([], $r['Reasons']);
    }

    public function testTooFewSignInsNamesTheShortfall(): void
    {
        $r = KnightCircle::evaluateEligibility($this->goodFacts(['SignIns' => 4]), KnightCircle::DEFAULTS);
        $this->assertFalse($r['Eligible']);
        $this->assertCount(1, $r['Reasons']);
        $this->assertStringContainsString('4 of 6', $r['Reasons'][0]);
    }

    public function testExactlyTheMinimumPassesAndZeroMinimumAlwaysPasses(): void
    {
        $this->assertTrue(KnightCircle::evaluateEligibility($this->goodFacts(['SignIns' => 6]), KnightCircle::DEFAULTS)['Eligible']);
        $cfg = array_merge(KnightCircle::DEFAULTS, ['min_signins' => 0]);
        $this->assertTrue(KnightCircle::evaluateEligibility($this->goodFacts(['SignIns' => 0]), $cfg)['Eligible']);
    }

    public function testDuesOnlyMatterWhenRequired(): void
    {
        $facts = $this->goodFacts(['DuesPaid' => false]);
        $this->assertFalse(KnightCircle::evaluateEligibility($facts, KnightCircle::DEFAULTS)['Eligible']);
        $cfg = array_merge(KnightCircle::DEFAULTS, ['require_dues' => 0]);
        $this->assertTrue(KnightCircle::evaluateEligibility($facts, $cfg)['Eligible']);
    }

    public function testWaiverIsOffByDefaultAndEnforcedWhenOn(): void
    {
        $facts = $this->goodFacts(['Waivered' => false]);
        $this->assertTrue(KnightCircle::evaluateEligibility($facts, KnightCircle::DEFAULTS)['Eligible']);
        $cfg = array_merge(KnightCircle::DEFAULTS, ['require_waiver' => 1]);
        $r = KnightCircle::evaluateEligibility($facts, $cfg);
        $this->assertFalse($r['Eligible']);
        $this->assertStringContainsString('waiver', strtolower($r['Reasons'][0]));
    }

    public function testResidenceAndSuspensionToggles(): void
    {
        $nonResident = $this->goodFacts(['Resident' => false]);
        $this->assertFalse(KnightCircle::evaluateEligibility($nonResident, KnightCircle::DEFAULTS)['Eligible']);
        $this->assertTrue(KnightCircle::evaluateEligibility($nonResident, array_merge(KnightCircle::DEFAULTS, ['require_residence' => 0]))['Eligible']);

        $suspended = $this->goodFacts(['Suspended' => true]);
        $this->assertFalse(KnightCircle::evaluateEligibility($suspended, KnightCircle::DEFAULTS)['Eligible']);
        $this->assertTrue(KnightCircle::evaluateEligibility($suspended, array_merge(KnightCircle::DEFAULTS, ['exclude_suspended' => 0]))['Eligible']);
    }

    public function testMinimumKnightMonths(): void
    {
        $cfg = array_merge(KnightCircle::DEFAULTS, ['min_knight_months' => 12]);
        $this->assertFalse(KnightCircle::evaluateEligibility($this->goodFacts(['KnightMonths' => 11]), $cfg)['Eligible']);
        $this->assertTrue(KnightCircle::evaluateEligibility($this->goodFacts(['KnightMonths' => 12]), $cfg)['Eligible']);
        // An undated knighthood is not held against the player.
        $this->assertTrue(KnightCircle::evaluateEligibility($this->goodFacts(['KnightMonths' => null]), $cfg)['Eligible']);
    }

    public function testKingdomVotingRulesOnlyBindWhenSwitchedOnAndKnown(): void
    {
        $no = $this->goodFacts(['VotingOk' => false]);
        $this->assertTrue(KnightCircle::evaluateEligibility($no, KnightCircle::DEFAULTS)['Eligible']);
        $cfg = array_merge(KnightCircle::DEFAULTS, ['use_kingdom_voting_rules' => 1]);
        $this->assertFalse(KnightCircle::evaluateEligibility($no, $cfg)['Eligible']);
        $this->assertTrue(KnightCircle::evaluateEligibility($this->goodFacts(['VotingOk' => null]), $cfg)['Eligible']);
        $this->assertTrue(KnightCircle::evaluateEligibility($this->goodFacts(['VotingOk' => true]), $cfg)['Eligible']);
    }

    public function testEveryFailingRuleIsReported(): void
    {
        $facts = ['SignIns' => 0, 'DuesPaid' => false, 'Waivered' => false, 'Resident' => false, 'Suspended' => true, 'KnightMonths' => 1, 'VotingOk' => false];
        $cfg = array_merge(KnightCircle::DEFAULTS, ['require_waiver' => 1, 'min_knight_months' => 12, 'use_kingdom_voting_rules' => 1]);
        $r = KnightCircle::evaluateEligibility($facts, $cfg);
        $this->assertFalse($r['Eligible']);
        $this->assertCount(7, $r['Reasons']);
    }

    public function testNormalizeConfigAcceptsDefaultsUnchanged(): void
    {
        $r = KnightCircle::normalizeConfig([]);
        $this->assertTrue($r['ok']);
        $this->assertSame(KnightCircle::DEFAULTS, $r['config']);
    }

    public function testNormalizeConfigCoercesAndTrims(): void
    {
        $r = KnightCircle::normalizeConfig([
            'min_signins' => '8', 'window_months' => 12, 'signin_mode' => 'WEEKS', 'require_waiver' => 'on',
            'require_dues' => '0', 'caveat' => "  Ask your GMoK.  ", 'bogus_key' => 'x',
        ]);
        $this->assertTrue($r['ok']);
        $this->assertSame(8, $r['config']['min_signins']);
        $this->assertSame(12, $r['config']['window_months']);
        $this->assertSame('weeks', $r['config']['signin_mode']);
        $this->assertSame(1, $r['config']['require_waiver']);
        $this->assertSame(0, $r['config']['require_dues']);
        $this->assertSame('Ask your GMoK.', $r['config']['caveat']);
        $this->assertArrayNotHasKey('bogus_key', $r['config']);
    }

    public function testNormalizeConfigKeepsBaseForKeysNotSent(): void
    {
        $base = array_merge(KnightCircle::DEFAULTS, ['min_signins' => 10, 'signin_scope' => 'anywhere']);
        $r = KnightCircle::normalizeConfig(['window_months' => 3], $base);
        $this->assertTrue($r['ok']);
        $this->assertSame(10, $r['config']['min_signins']);
        $this->assertSame('anywhere', $r['config']['signin_scope']);
        $this->assertSame(3, $r['config']['window_months']);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function badConfigs(): array
    {
        return [
            'enum'              => [['signin_mode' => 'daily']],
            'scope enum'        => [['signin_scope' => 'planet']],
            'online enum'       => [['online_mode' => 'maybe']],
            'dues scope enum'   => [['dues_scope' => '']],
            'signins high'      => [['min_signins' => 53]],
            'signins negative'  => [['min_signins' => -1]],
            'window zero'       => [['window_months' => 0]],
            'window high'       => [['window_months' => 25]],
            'knight months high' => [['min_knight_months' => 601]],
            'rank low'          => [['watchlist_min_rank' => 0]],
            'rank high'         => [['watchlist_min_rank' => 13]],
            'not a number'      => [['min_signins' => 'lots']],
            'fraction'          => [['min_signins' => '2.5']],
            'array'             => [['window_months' => [3]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badConfigs')]
    public function testNormalizeConfigRejectsBadInput(array $input): void
    {
        $r = KnightCircle::normalizeConfig($input);
        $this->assertFalse($r['ok']);
        $this->assertNotSame('', $r['error']);
    }

    public function testNormalizeConfigAcceptsTheRangeEdges(): void
    {
        $r = KnightCircle::normalizeConfig([
            'min_signins' => 0, 'window_months' => 24, 'min_knight_months' => 600, 'watchlist_min_rank' => 1,
        ]);
        $this->assertTrue($r['ok']);
        $r = KnightCircle::normalizeConfig(['min_signins' => 52, 'window_months' => 1, 'min_knight_months' => 0, 'watchlist_min_rank' => 12]);
        $this->assertTrue($r['ok']);
    }

    public function testCaveatIsCapped(): void
    {
        $r = KnightCircle::normalizeConfig(['caveat' => str_repeat('a', 5000)]);
        $this->assertTrue($r['ok']);
        $this->assertSame(1000, strlen($r['config']['caveat']));
    }

    public function testOrdersMatchTheAwardKnightMap(): void
    {
        $this->assertSame(Award::GetKnightAwardMap(), KnightCircle::ORDERS);
        $this->assertSame(array_keys(KnightCircle::ORDERS), array_keys(KnightCircle::ORDER_NAMES));
    }

    public function testLadderOrderMatchesReportLadderKnightGroupMap(): void
    {
        $m = new ReflectionMethod(Report::class, 'ladderKnightGroupMap');
        $m->setAccessible(true);
        /** @var array<string,string> $byName ladder name => knight group */
        $byName = $m->invoke(null);
        $ladders = Award::GetLadderMasterMap();

        $this->assertCount(count($byName), KnightCircle::LADDER_ORDER, 'same number of knight-bearing ladders');
        foreach (KnightCircle::LADDER_ORDER as $ladderId => $orderId) {
            $this->assertArrayHasKey($ladderId, $ladders, "ladder $ladderId exists");
            $name = $ladders[$ladderId]['LadderName'];
            $this->assertArrayHasKey($name, $byName, "$name leads to a knighthood in Report");
            $this->assertSame($byName[$name], KnightCircle::ORDERS[$orderId], "$name leads to the same order");
        }
    }

    public function testMasterOrderMap(): void
    {
        $this->assertSame([
            1 => 17, 2 => 17, 3 => 17,
            4 => 19, 5 => 19, 6 => 19,
            12 => 20,
            240 => 18,
            244 => 245,
        ], KnightCircle::masterOrderMap());
    }

    public function testMasterOrderMapOnlyPointsAtRealOrders(): void
    {
        foreach (KnightCircle::masterOrderMap() as $orderId) {
            $this->assertArrayHasKey($orderId, KnightCircle::ORDERS);
        }
    }

    public function testDefaultsCoverEveryConfigKey(): void
    {
        $this->assertSame(
            ['min_signins', 'window_months', 'signin_mode', 'signin_scope', 'online_mode', 'require_dues', 'dues_scope',
             'require_waiver', 'require_residence', 'include_principalities', 'exclude_suspended', 'min_knight_months',
             'use_kingdom_voting_rules', 'watchlist_min_rank', 'officers_see_pipeline', 'caveat'],
            array_keys(KnightCircle::DEFAULTS)
        );
    }
}
