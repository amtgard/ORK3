<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_AdminAjax::stateofamtgard validation (T-ADM-09, T-ADM-12).
 */
final class StateOfAmtgardValidationTest extends TestCase
{
    private StateOfAmtgard $sorDomain;

    protected function setUp(): void
    {
        $this->sorDomain = new StateOfAmtgard();
    }

    public function testDateCapProduction(): void
    {
        $narrow = $this->sorDomain->ValidateDateRange('2025-01-01', '2025-06-01', 'TEST');
        $this->assertTrue($narrow['ok']);

        $wide = $this->sorDomain->ValidateDateRange('2025-01-01', '2026-06-01', 'TEST');
        $this->assertFalse($wide['ok']);
        $this->assertSame(400, $wide['httpCode']);

        $devWide = $this->sorDomain->ValidateDateRange('2025-01-01', '2026-06-01', 'DEV');
        $this->assertTrue($devWide['ok']);
    }

    public function testInvalidDateRejected(): void
    {
        $result = $this->sorDomain->ValidateDateRange('2026-02-30', '2026-03-01', 'TEST');

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['httpCode']);
    }

    public function testRetentionIgnoresDateRange(): void
    {
        $payload = $this->sorDomain->DispatchChartSection('retention', '1900-01-01', '2099-12-31', []);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('retention', $payload);

        if (ork3_test_db_available()) {
            $retention = Ork3::$Lib->stateofamtgard->getNewPlayerRetention([]);
            $this->assertIsArray($retention);
        }
    }
}
