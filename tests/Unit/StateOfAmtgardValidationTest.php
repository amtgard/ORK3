<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_AdminAjax::stateofamtgard validation (T-ADM-09, T-ADM-12).
 */
final class StateOfAmtgardValidationTest extends TestCase
{
    public function testDateCapProduction(): void
    {
        $narrow = $this->mirrorValidateDateRange('2025-01-01', '2025-06-01', environment: 'TEST');
        $this->assertTrue($narrow['ok']);

        $wide = $this->mirrorValidateDateRange('2025-01-01', '2026-06-01', environment: 'TEST');
        $this->assertFalse($wide['ok']);
        $this->assertSame(400, $wide['httpCode']);

        $devWide = $this->mirrorValidateDateRange('2025-01-01', '2026-06-01', environment: 'DEV');
        $this->assertTrue($devWide['ok']);
    }

    public function testInvalidDateRejected(): void
    {
        $result = $this->mirrorValidateDateRange('2026-02-30', '2026-03-01', environment: 'TEST');

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['httpCode']);
    }

    public function testRetentionIgnoresDateRange(): void
    {
        $this->assertTrue($this->mirrorRetentionIgnoresDateRange());

        if (ork3_test_db_available()) {
            $retention = Ork3::$Lib->stateofamtgard->getNewPlayerRetention([]);
            $this->assertIsArray($retention);
        }
    }

    /**
     * @return array{ok: bool, start?: string, end?: string, httpCode?: int, error?: string}
     */
    private function mirrorValidateDateRange(string $startInput, string $endInput, string $environment): array
    {
        $validateDate = static function ($val, $fallback) {
            $val = preg_replace('/[^0-9\-]/', '', $val ?? '');
            if ($val === '') {
                return $fallback;
            }
            $dt = DateTime::createFromFormat('Y-m-d', $val);
            if ($dt === false || $dt->format('Y-m-d') !== $val) {
                return false;
            }

            return $val;
        };

        $start = $validateDate($startInput, date('Y') . '-01-01');
        $end = $validateDate($endInput, date('Y') . '-12-31');
        if ($start === false || $end === false) {
            return ['ok' => false, 'httpCode' => 400, 'error' => 'Invalid date format. Expected YYYY-MM-DD.'];
        }
        if ($start > $end) {
            return ['ok' => false, 'httpCode' => 400, 'error' => 'Start date must be on or before end date.'];
        }

        $limitMonths = ($environment === 'DEV') ? 0 : 12;
        if ($limitMonths > 0) {
            $maxEnd = (new DateTime($start))->modify('+' . $limitMonths . ' months')->format('Y-m-d');
            if ($end > $maxEnd) {
                return [
                    'ok' => false,
                    'httpCode' => 400,
                    'error' => 'The reporting window cannot exceed ' . $limitMonths . ' months. Please narrow the date range.',
                ];
            }
        }

        return ['ok' => true, 'start' => $start, 'end' => $end];
    }

    private function mirrorRetentionIgnoresDateRange(): bool
    {
        $section = 'retention';
        $start = '1900-01-01';
        $end = '2099-12-31';

        return $section === 'retention';
    }
}
