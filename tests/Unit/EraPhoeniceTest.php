<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for EraPhoenice static helpers and JSON shape (T-LIB-05).
 */
final class EraPhoeniceTest extends TestCase
{
    public function testKnownBoundaryDate(): void
    {
        $d = new DateTimeImmutable('2026-02-12');
        $ep = EraPhoenice::fromDate($d);
        $this->assertSame(44, $ep['year']);
    }

    public function testHolidayLookup(): void
    {
        $d = new DateTimeImmutable('2026-01-04');
        $this->assertSame('Garbmas', EraPhoenice::holiday($d));
    }

    public function testServiceJsonShape(): void
    {
        $d = new DateTimeImmutable('2026-02-12');
        $payload = [
            'today' => EraPhoenice::formatToday(new DateTimeZone('UTC')),
            'date' => EraPhoenice::format($d),
            'ep' => EraPhoenice::fromDate($d),
            'holiday' => EraPhoenice::holiday($d),
        ];

        $this->assertArrayHasKey('today', $payload);
        $this->assertArrayHasKey('date', $payload);
        $this->assertArrayHasKey('year', $payload['ep']);
        $this->assertStringStartsWith('E.P.', $payload['date']);
    }
}
