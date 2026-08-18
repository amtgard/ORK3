<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for AdminAjax / AdminDashboard StateOfAmtgard lib paths (T-LIB-10, T-LIB-17).
 */
final class StateOfAmtgardTest extends TestCase
{
    private StateOfAmtgard $sorDomain;

    protected function setUp(): void
    {
        $this->sorDomain = new StateOfAmtgard();
    }

    public function testGetPageBootstrapShape(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $bootstrap = $this->sorDomain->GetPageBootstrap();

        $this->assertArrayHasKey('Kingdoms', $bootstrap);
        $this->assertArrayHasKey('MinDate', $bootstrap);
        $this->assertArrayHasKey('MaxDate', $bootstrap);
        $this->assertArrayHasKey('RangeLimitMonths', $bootstrap);
        $this->assertIsArray($bootstrap['Kingdoms']);
        $this->assertGreaterThan(0, $bootstrap['RangeLimitMonths']);
    }
}
