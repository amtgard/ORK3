<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pilot integration coverage for Calendar domain logic (M0.1 Infection scope).
 *
 * Replaces the ad-hoc orkservice/Calendar/CalendarService.test.php script.
 */
final class CalendarServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
    }

    public function testNextYearReturnsSuccessAndDatesArray(): void
    {
        $calendar = new Calendar();
        $result = $calendar->Next([
            'Type' => 'Year',
            'Date' => '2026-01-01',
        ]);

        $this->assertSame(0, $result['Status']['Status']);
        $this->assertArrayHasKey('Dates', $result);
        $this->assertIsArray($result['Dates']);
    }

    public function testNextDispatchesByType(): void
    {
        $calendar = new Calendar();
        $request = ['Date' => '2026-06-01'];

        foreach (['Week', 'Month', 'Year'] as $type) {
            $request['Type'] = $type;
            $result = $calendar->Next($request);
            $this->assertSame(0, $result['Status']['Status'], "Expected success for Type={$type}");
            $this->assertIsArray($result['Dates']);
        }
    }
}
