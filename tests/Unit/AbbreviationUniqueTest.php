<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for abbreviation uniqueness AJAX (T-ADM-06, T-ADM-07).
 */
final class AbbreviationUniqueTest extends TestCase
{
    private AdminDashboardFixture $fixture;
    private ParkProfile $parkProfile;
    private KingdomProfile $kingdomProfile;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = AdminDashboardFixture::create();
        $this->parkProfile = new ParkProfile();
        $this->kingdomProfile = new KingdomProfile();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testParkAbbreviationExcludeSelf(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->firstKingdomId();
        $abbr = $this->parkProfile->GetParkAbbreviation($parkId);
        $this->assertNotNull($abbr);

        $takenOther = $this->parkProfile->CheckParkAbbreviationTaken($kingdomId, $abbr, 0);
        $availableSelf = $this->parkProfile->CheckParkAbbreviationTaken($kingdomId, $abbr, $parkId);

        $this->assertTrue($takenOther);
        $this->assertFalse($availableSelf);

        $parkDomain = new Park();
        $response = $parkDomain->GetParkDetails(['ParkId' => $parkId]);
        $this->assertSame(0, $response['Status']['Status']);
    }

    public function testKingdomAbbreviationExcludeSelf(): void
    {
        $kingdomId = $this->fixture->firstKingdomId();
        $abbr = $this->fixture->kingdomAbbreviation($kingdomId);

        $takenOther = $this->kingdomProfile->CheckKingdomAbbreviationTaken($abbr, 0);
        $availableSelf = $this->kingdomProfile->CheckKingdomAbbreviationTaken($abbr, $kingdomId);

        $this->assertTrue($takenOther);
        $this->assertFalse($availableSelf);
    }
}
