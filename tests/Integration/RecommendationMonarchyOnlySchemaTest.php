<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;

final class RecommendationMonarchyOnlySchemaTest extends TestCase
{
    private CourtFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }
        $this->fixture = CourtFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testColumnDefaultsToPublicAndFixtureCanSetIt(): void
    {
        $k  = $this->fixture->firstKingdomId();
        $p  = $this->fixture->firstParkId($k);
        $ka = $this->fixture->firstKingdomAwardId($k);
        $r  = $this->fixture->createPlayer('mo-recip', $k, $p)['mundane_id'];

        $plain  = $this->fixture->createRecommendation($r, (int) $ka['kingdomaward_id'], (int) $ka['award_id']);
        $hidden = $this->fixture->createRecommendation($r, (int) $ka['kingdomaward_id'], (int) $ka['award_id'], ['monarchy_only' => 2, 'rank' => 2]);

        $this->assertSame(0, (int) $this->fixture->fetchRecommendation($plain)['monarchy_only']);
        $this->assertSame(2, (int) $this->fixture->fetchRecommendation($hidden)['monarchy_only']);
    }
}
