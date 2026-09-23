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
        $this->assertSame('Marching', $ep['month']);
        $this->assertSame(1, $ep['day']);
    }

    public function testDateBeforeBoundaryUsesPriorYear(): void
    {
        $d = new DateTimeImmutable('2026-02-11');
        $ep = EraPhoenice::fromDate($d);
        $this->assertSame(43, $ep['year']);
        $this->assertSame('Festivus', $ep['month']);
    }

    public function testMidYearMonthAndDay(): void
    {
        // 90 days after Feb 12 2026 → first day of Sowing
        $d = new DateTimeImmutable('2026-05-13');
        $ep = EraPhoenice::fromDate($d);
        $this->assertSame(44, $ep['year']);
        $this->assertSame('Sowing', $ep['month']);
        $this->assertSame(1, $ep['day']);
    }

    public function testHolidayLookup(): void
    {
        $d = new DateTimeImmutable('2026-01-04');
        $this->assertSame('Garbmas', EraPhoenice::holiday($d));
        $this->assertNull(EraPhoenice::holiday(new DateTimeImmutable('2026-06-15')));
    }

    public function testFormatUsesOrdinalSuffixes(): void
    {
        $this->assertSame(
            'E.P. 44, 1st of Marching',
            EraPhoenice::format(new DateTimeImmutable('2026-02-12'))
        );
        // day 2 of Marching → 2nd
        $this->assertSame(
            'E.P. 44, 2nd of Marching',
            EraPhoenice::format(new DateTimeImmutable('2026-02-13'))
        );
        // day 3 → 3rd
        $this->assertSame(
            'E.P. 44, 3rd of Marching',
            EraPhoenice::format(new DateTimeImmutable('2026-02-14'))
        );
        // day 4 → 4th
        $this->assertSame(
            'E.P. 44, 4th of Marching',
            EraPhoenice::format(new DateTimeImmutable('2026-02-15'))
        );
        // teen exception: 11th / 12th / 13th
        $this->assertSame(
            'E.P. 44, 11th of Marching',
            EraPhoenice::format(new DateTimeImmutable('2026-02-22'))
        );
        $this->assertSame(
            'E.P. 44, 12th of Marching',
            EraPhoenice::format(new DateTimeImmutable('2026-02-23'))
        );
        $this->assertSame(
            'E.P. 44, 13th of Marching',
            EraPhoenice::format(new DateTimeImmutable('2026-02-24'))
        );
        // 21st / 22nd / 23rd
        $this->assertSame(
            'E.P. 44, 21st of Marching',
            EraPhoenice::format(new DateTimeImmutable('2026-03-04'))
        );
        $this->assertSame(
            'E.P. 44, 22nd of Marching',
            EraPhoenice::format(new DateTimeImmutable('2026-03-05'))
        );
        $this->assertSame(
            'E.P. 44, 23rd of Marching',
            EraPhoenice::format(new DateTimeImmutable('2026-03-06'))
        );
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
        $this->assertSame("Attila the Hun's Birthday", $payload['holiday']);
    }

    public function testNeighborHolidays(): void
    {
        $d = new DateTimeImmutable('2026-02-12');
        $last = EraPhoenice::lastHoliday($d);
        $this->assertNotNull($last);
        $this->assertSame('The Feast of Glares', $last['name']);
        $this->assertSame('2026-01-23', $last['date']->format('Y-m-d'));

        $next = EraPhoenice::nextHoliday($d);
        $this->assertNotNull($next);
        $this->assertSame('Mustering', $next['name']);
        $this->assertSame('2026-03-01', $next['date']->format('Y-m-d'));
    }

    public function testImperiumReckoning(): void
    {
        $d = new DateTimeImmutable('2026-02-12');
        $im = EraPhoenice::imperiumFromDate($d);
        $this->assertSame(11, $im['year']);
        $this->assertSame('Marching', $im['month']);
        $this->assertSame(1, $im['day']);
        $this->assertSame(
            'Era Imperium 11, 1st of Marching',
            EraPhoenice::imperiumFormat($d)
        );
    }

    public function testLongCivil(): void
    {
        $this->assertSame('April 1st', EraPhoenice::longCivil(new DateTimeImmutable('2026-04-01')));
        $this->assertSame('December 17th', EraPhoenice::longCivil(new DateTimeImmutable('2026-12-17')));
    }
}
