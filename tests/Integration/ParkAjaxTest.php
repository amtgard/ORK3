<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_ParkAjax actions (T-PRA-03).
 */
final class ParkAjaxTest extends TestCase
{
    private ParkProfileFixture $fixture;

    private ParkProfile $profileDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ParkProfileFixture::create();
        $this->profileDomain = new ParkProfile();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testCheckParkAbbreviationUnique(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $abbr = $this->fixture->fetchParkAbbreviation($parkId);

        $taken = $this->profileDomain->CheckParkAbbreviationTaken($kingdomId, $abbr, 0);
        $this->assertTrue($taken);

        $available = $this->profileDomain->CheckParkAbbreviationTaken($kingdomId, $abbr, $parkId);
        $this->assertFalse($available);
    }
}
