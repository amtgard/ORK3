<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for voting rules and eligibility (T-RPT-04 through T-RPT-08).
 */
final class VotingRulesTest extends TestCase
{
    private ReportsFixture $fixture;

    private Model_Reports $reportsModel;

    private Report $reportDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ReportsFixture::create();
        $this->reportsModel = new Model_Reports();
        $this->reportDomain = new Report();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testSupportedKingdomIds(): void
    {
        $expected = [14, 31, 3, 17, 10, 25, 20, 40, 36, 27, 38, 4, 6, 19, 12, 24];
        $actual = $this->reportsModel->supported_voting_kingdom_ids();
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertCount(16, $actual);
    }

    public function testProvinceModeEligibility(): void
    {
        $rules = $this->mirrorVotingRules(17);
        $this->assertNotNull($rules);
        $this->assertTrue($rules['ProvinceMode']);

        $result = $this->reportDomain->GetVotingEligible(array_merge($rules, ['KingdomId' => 17]));
        $this->assertTrue($result['ProvinceMode']);
        $this->assertArrayHasKey('Players', $result);
    }

    public function testHomeParkOnlyRule(): void
    {
        $rules = $this->mirrorVotingRules(4);
        $this->assertNotNull($rules);
        $this->assertTrue($rules['HomeParkOnly']);

        $result = $this->reportDomain->GetVotingEligible(array_merge($rules, ['KingdomId' => 4]));
        $this->assertArrayHasKey('Players', $result);
    }

    public function testActiveKnightThreshold(): void
    {
        $rules = $this->mirrorVotingRules(6);
        $this->assertNotNull($rules);
        $this->assertSame(8, $rules['ActiveKnightThreshold']);

        $result = $this->reportDomain->GetVotingEligible(array_merge($rules, ['KingdomId' => 6]));
        $this->assertArrayHasKey('Players', $result);
    }

    public function testSinglePlayerBadge(): void
    {
        $kid = 14;
        $parkId = $this->fixture->parkIdInKingdom($kid);
        if ($parkId <= 0) {
            $this->markTestSkipped('No active park in voting kingdom 14.');
        }

        $player = $this->fixture->createPlayer($parkId, 'vote-badge');
        $modelResult = $this->reportsModel->get_voting_eligible_for_player($player['mundane_id'], $kid);
        $domainResult = $this->reportDomain->GetVotingEligible(array_merge(
            $this->mirrorVotingRules($kid) ?? [],
            ['KingdomId' => $kid, 'MundaneId' => $player['mundane_id']],
        ));

        $this->assertArrayHasKey('Players', $modelResult);
        $this->assertArrayHasKey('Players', $domainResult);
        $this->assertLessThanOrEqual(1, count($modelResult['Players']));
        $this->assertSame(count($modelResult['Players']), count($domainResult['Players']));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mirrorVotingRules(int $kingdomId): ?array
    {
        return VotingRules::rulesForKingdom($kingdomId);
    }
}
