<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ork_parkday_next_occurrences() resolves a park-day recurrence rule into its
 * next concrete dates — mirroring Weather::parks_playing_on()'s predicates —
 * for the park pages' schema.org Event markup. Pure date math, so pin all
 * four recurrence flavors.
 */
final class ParkdayOccurrencesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../system/lib/ork3/common.php';
    }

    public function testWeekly(): void
    {
        // From Sunday 2026-08-23: next two Sundays include the from-date itself.
        $d = ork_parkday_next_occurrences(array('Recurrence' => 'weekly', 'WeekDay' => 'Sunday'), '2026-08-23');
        $this->assertSame(array('2026-08-23', '2026-08-30'), $d);
    }

    public function testWeekOfMonth(): void
    {
        // 2nd Saturday: Sep 12 and Oct 10, 2026.
        $d = ork_parkday_next_occurrences(array('Recurrence' => 'week-of-month', 'WeekDay' => 'Saturday', 'WeekOfMonth' => 2), '2026-09-01');
        $this->assertSame(array('2026-09-12', '2026-10-10'), $d);
    }

    public function testMonthly(): void
    {
        $d = ork_parkday_next_occurrences(array('Recurrence' => 'monthly', 'MonthDay' => 15), '2026-08-23');
        $this->assertSame(array('2026-09-15', '2026-10-15'), $d);
    }

    public function testEveryXWeeks(): void
    {
        // Every 3 weeks from Saturday 2026-08-01: Aug 22, Sep 12, ...
        $d = ork_parkday_next_occurrences(array(
            'Recurrence' => 'every-x-weeks', 'WeekDay' => 'Saturday',
            'StartDate' => '2026-08-01', 'WeekInterval' => 3,
        ), '2026-08-23');
        $this->assertSame(array('2026-09-12', '2026-10-03'), $d);
    }

    public function testEveryXWeeksBeforeStartDateYieldsNothingEarly(): void
    {
        $d = ork_parkday_next_occurrences(array(
            'Recurrence' => 'every-x-weeks', 'WeekDay' => 'Saturday',
            'StartDate' => '2026-10-03', 'WeekInterval' => 2,
        ), '2026-08-23');
        $this->assertSame(array('2026-10-03', '2026-10-17'), $d);
    }

    public function testHorizonLimitsResults(): void
    {
        // Monthly on the 15th with a 20-day horizon from Aug 23 → only Sep 15...
        // which is 23 days out, beyond horizon → nothing.
        $d = ork_parkday_next_occurrences(array('Recurrence' => 'monthly', 'MonthDay' => 15), '2026-08-23', 2, 20);
        $this->assertSame(array(), $d);
    }

    public function testGarbageYieldsNothing(): void
    {
        $this->assertSame(array(), ork_parkday_next_occurrences(array('Recurrence' => 'weekly', 'WeekDay' => 'None'), '2026-08-23'));
        $this->assertSame(array(), ork_parkday_next_occurrences(array(), 'not-a-date'));
    }

    public function testAllDayEventJsonLdEmitsBareDates(): void
    {
        $ld = ork_event_jsonld(array('name' => 'Park Day', 'start' => '2026-08-30', 'all_day' => true));
        $this->assertSame('2026-08-30', $ld['startDate']);
        $timed = ork_event_jsonld(array('name' => 'Park Day', 'start' => '2026-08-30 13:00:00'));
        $this->assertSame('2026-08-30T13:00:00', $timed['startDate']);
    }
}
