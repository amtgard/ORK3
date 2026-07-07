<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for attendance date picker SQL (T-RPT-03).
 */
final class AttendanceDatesTest extends TestCase
{
    private ?ReportsFixture $fixture = null;

    private Model_Reports $reportsModel;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ReportsFixture::create();
        $this->reportsModel = new Model_Reports();
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->cleanup();
        }
    }

    public function testDistinctDatesByKingdom(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'att-k');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2024-06-15');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2024-07-01');

        $modelDates = $this->reportsModel->get_attendance_dates('Kingdom', $kid);
        $mirrorDates = $this->mirrorAttendanceDates('Kingdom', $kid);

        $this->assertSame($mirrorDates, $modelDates);
        $this->assertContains('2024-07-01', $modelDates);
        $this->assertSame($modelDates, array_values(array_unique($modelDates)));
        $sorted = $modelDates;
        rsort($sorted);
        $this->assertSame($sorted, $modelDates);
    }

    public function testDistinctDatesByPark(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'att-p');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2024-08-10');

        $modelDates = $this->reportsModel->get_attendance_dates('Park', $parkId);
        $mirrorDates = $this->mirrorAttendanceDates('Park', $parkId);

        $this->assertSame($mirrorDates, $modelDates);
        $this->assertContains('2024-08-10', $modelDates);
    }

    /**
     * @return list<string>
     */
    private function mirrorAttendanceDates(string $type, int $id): array
    {
        global $DB;
        $id = (int) $id;
        $col = ($type === 'Kingdom') ? 'kingdom_id' : 'park_id';
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT DISTINCT DATE(date) AS att_date FROM ' . DB_PREFIX . "attendance WHERE {$col} = {$id} ORDER BY att_date DESC"
        );
        $dates = [];
        if ($rs) {
            while ($rs->Next()) {
                $dates[] = $rs->att_date;
            }
        }

        return $dates;
    }
}
