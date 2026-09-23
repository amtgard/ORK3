<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ParkCalculateNextParkDayTest extends TestCase
{
    public function testWeeklyRecurrenceReturnsNextNamedWeekday(): void
    {
        $from = strtotime('2025-01-02'); // Thursday
        $result = Park::CalculateNextParkDay('weekly', null, null, 'Monday', $from);
        $this->assertSame('2025-01-06', $result);
    }

    public function testMonthlyRecurrenceUsesMonthDay(): void
    {
        $from = strtotime('2025-03-10');
        $result = Park::CalculateNextParkDay('monthly', null, 15, null, $from);
        $this->assertSame('2025-03-15', $result);
    }

    public function testEveryXWeeksUsesAnchorAndInterval(): void
    {
        $anchor = '2025-01-01';
        $from = strtotime('2025-01-20');
        $result = Park::CalculateNextParkDay(
            'every-x-weeks',
            null,
            null,
            null,
            $from,
            $anchor,
            2
        );
        $this->assertSame('2025-01-29', $result);
    }

    public function testEveryXWeeksReturnsAnchorWhenAnchorIsOnOrAfterFromDate(): void
    {
        $anchor = '2025-02-01';
        $from = strtotime('2025-01-15');
        $result = Park::CalculateNextParkDay(
            'every-x-weeks',
            null,
            null,
            null,
            $from,
            $anchor,
            2
        );
        $this->assertSame('2025-02-01', $result);
    }
}
