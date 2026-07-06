<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Park profile reads (T-PRK-01 through T-PRK-05).
 */
final class ParkProfileTest extends TestCase
{
    private ParkProfileFixture $fixture;

    private Park $parkDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = ParkProfileFixture::create();
        $this->parkDomain = new Park();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testGetParkEventSummary(): void
    {
        $parkId = $this->fixture->firstParkId();
        $ctx = $this->fixture->createPublishedEvent($parkId, 'summary');

        $summary = $this->mirrorParkEventSummary($parkId, uid: 0, isAdmin: false);
        $this->assertContains($ctx['event_id'], array_column($summary, 'EventId'));
    }

    public function testParkEventSummaryBatchCoords(): void
    {
        $parkId = $this->fixture->firstParkId();
        $ctx = $this->fixture->createPublishedEvent($parkId, 'coords');
        $summary = $this->mirrorParkEventSummary($parkId, uid: 0, isAdmin: false);
        $detailIds = array_column(array_filter($summary, static fn ($row) => !empty($row['EventId'])), 'NextDetailId');

        $coordMap = $this->mirrorBatchDetailCoords($detailIds);
        $this->assertArrayHasKey($ctx['detail_id'], $coordMap);
        $this->assertArrayHasKey('event_loc', $coordMap[$ctx['detail_id']]);
    }

    public function testGetParkPlayersRoster(): void
    {
        $parkId = $this->fixture->firstParkId();
        $this->fixture->createPlayer($parkId, 'roster');

        $roster = $this->mirrorParkPlayersRoster($parkId);
        $this->assertNotEmpty($roster);
        $this->assertArrayHasKey('Persona', $roster[0]);
        $this->assertArrayHasKey('SigninCount', $roster[0]);
    }

    public function testGetParkAttendanceAverages(): void
    {
        $parkId = $this->fixture->firstParkId();
        $averages = $this->mirrorParkAttendanceAverages($parkId);
        $this->assertArrayHasKey('MonthlyAvg', $averages);
        $this->assertArrayHasKey('WeeklyAvg', $averages);
        $this->assertGreaterThanOrEqual(0, $averages['MonthlyAvg']);
        $this->assertGreaterThanOrEqual(0, $averages['WeeklyAvg']);
    }

    public function testParkEventStaffSeesDraft(): void
    {
        $parkId = $this->fixture->firstParkId();
        $ctx = $this->fixture->createPublishedEvent($parkId, 'draft-staff', 'draft');
        $staff = $this->fixture->createPlayer($parkId, 'draft-sched');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], canSchedule: true);

        $summary = $this->mirrorParkEventSummary($parkId, $staff['mundane_id'], isAdmin: false);
        $this->assertContains($ctx['event_id'], array_column($summary, 'EventId'));
    }

    public function testParkDomainGetParkDetails(): void
    {
        $parkId = $this->fixture->firstParkId();
        $response = $this->parkDomain->GetParkDetails(['ParkId' => $parkId]);
        $this->assertSame(0, $response['Status']['Status']);
        $this->assertSame($parkId, (int) $response['ParkId']);
        $this->assertNotEmpty($response['ParkName']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mirrorParkEventSummary(int $parkId, int $uid, bool $isAdmin): array
    {
        if ($isAdmin || $uid === 0) {
            $draftClause = $isAdmin ? '' : "AND e.status = 'published'";
        } else {
            $draftClause = "AND (e.status = 'published' OR e.mundane_id = {$uid} OR EXISTS (SELECT 1 FROM "
                . DB_PREFIX . 'event_staff es JOIN ' . DB_PREFIX
                . 'event_calendardetail cds ON cds.event_calendardetail_id = es.event_calendardetail_id WHERE cds.event_id = e.event_id AND es.mundane_id = '
                . $uid . '))';
        }

        global $DB;
        $DB->Clear();
        $evtResult = $DB->DataSet(
            'SELECT e.event_id, e.name, e.status, e.mundane_id AS event_creator, cd.event_start, cd.event_calendardetail_id AS next_detail_id
             FROM ' . DB_PREFIX . 'event e
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
                AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
             WHERE (e.park_id = ' . $parkId . ' OR cd.at_park_id = ' . $parkId . ') ' . $draftClause
            . ' ORDER BY cd.event_start'
        );

        $summary = [];
        while ($evtResult && $evtResult->Next()) {
            $eid = (int) $evtResult->event_id;
            $rowStatus = (string) ($evtResult->status ?? 'published');
            if ($rowStatus !== 'published' && !$isAdmin && (int) $evtResult->event_creator !== $uid) {
                $canEditRow = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $eid, AUTH_EDIT);
                if (!$canEditRow && $uid > 0) {
                    $DB->Clear();
                    $_staffRow = $DB->DataSet(
                        'SELECT 1 FROM ' . DB_PREFIX . 'event_staff es JOIN ' . DB_PREFIX
                        . 'event_calendardetail cds ON cds.event_calendardetail_id = es.event_calendardetail_id WHERE cds.event_id = '
                        . $eid . ' AND es.mundane_id = ' . $uid . ' LIMIT 1'
                    );
                    $canEditRow = (bool) ($_staffRow && $_staffRow->Next());
                }
                if (!$canEditRow) {
                    continue;
                }
            }
            $summary[] = [
                'EventId' => $eid,
                'Name' => $evtResult->name,
                'NextDate' => $evtResult->event_start,
                'NextDetailId' => (int) $evtResult->next_detail_id,
                'Status' => $rowStatus,
            ];
        }

        return $summary;
    }

    /**
     * @param list<int> $detailIds
     *
     * @return array<int, array{event_loc: string, at_park_lat: mixed, at_park_lng: mixed}>
     */
    private function mirrorBatchDetailCoords(array $detailIds): array
    {
        if ($detailIds === []) {
            return [];
        }

        global $DB;
        $detailIdList = implode(',', array_map('intval', $detailIds));
        $DB->Clear();
        $cdBatch = $DB->DataSet(
            'SELECT cd.event_calendardetail_id, cd.location AS event_loc, cd.at_park_id,
                    p.latitude AS at_park_lat, p.longitude AS at_park_lng
             FROM ' . DB_PREFIX . 'event_calendardetail cd
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = cd.at_park_id
             WHERE cd.event_calendardetail_id IN (' . $detailIdList . ')'
        );
        $map = [];
        while ($cdBatch && $cdBatch->Next()) {
            $map[(int) $cdBatch->event_calendardetail_id] = [
                'event_loc' => (string) ($cdBatch->event_loc ?? ''),
                'at_park_lat' => $cdBatch->at_park_lat,
                'at_park_lng' => $cdBatch->at_park_lng,
            ];
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mirrorParkPlayersRoster(int $parkId): array
    {
        global $DB;
        $DB->Clear();
        $rosterResult = $DB->DataSet(
            'SELECT m.mundane_id, m.persona, COUNT(DISTINCT a6.date) AS signin_count
             FROM ' . DB_PREFIX . 'mundane m
             LEFT JOIN ' . DB_PREFIX . 'attendance a6 ON a6.mundane_id = m.mundane_id
                AND a6.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             WHERE m.park_id = ' . $parkId . ' AND m.suspended = 0 AND m.active = 1
             GROUP BY m.mundane_id
             ORDER BY m.persona
             LIMIT 25'
        );
        $players = [];
        while ($rosterResult && $rosterResult->Next()) {
            $mid = (int) $rosterResult->mundane_id;
            if ($mid <= 0) {
                continue;
            }
            $players[] = [
                'MundaneId' => $mid,
                'Persona' => $rosterResult->persona,
                'SigninCount' => (int) $rosterResult->signin_count,
            ];
        }

        return $players;
    }

    /**
     * @return array{MonthlyAvg: float, WeeklyAvg: float}
     */
    private function mirrorParkAttendanceAverages(int $parkId): array
    {
        global $DB;
        $monthlyAvg = 0.0;
        $DB->Clear();
        $maResult = $DB->DataSet(
            'SELECT AVG(monthly_unique) AS avg_per_month FROM (
                SELECT COUNT(DISTINCT a.mundane_id) AS monthly_unique
                FROM ' . DB_PREFIX . 'attendance a
                WHERE a.park_id = ' . $parkId . '
                  AND a.date > DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                  AND a.mundane_id > 0
                GROUP BY a.date_year, a.date_month
            ) sub'
        );
        if ($maResult && $maResult->Next()) {
            $_avg = (float) $maResult->avg_per_month;
            if ($_avg > 0) {
                $monthlyAvg = round($_avg, 1);
            }
        }

        $wkStart = date('Y-m-d', strtotime('-6 month'));
        $wkEnd = date('Y-m-d');
        $wkCount = max(1, (int) ceil((strtotime($wkEnd) - strtotime($wkStart)) / (7 * 86400)));
        $weeklyAvg = 0.0;
        $DB->Clear();
        $waResult = $DB->DataSet(
            'SELECT COUNT(*) AS player_weeks FROM (
                SELECT a.mundane_id
                FROM ' . DB_PREFIX . 'attendance a
                WHERE a.park_id = ' . $parkId . "
                  AND a.date >= '{$wkStart}'
                  AND a.date <= '{$wkEnd}'
                  AND a.mundane_id > 0
                GROUP BY a.date_year, a.date_week3, a.mundane_id
            ) sub"
        );
        if ($waResult && $waResult->Next()) {
            $_wk = (int) $waResult->player_weeks;
            if ($_wk > 0) {
                $weeklyAvg = round($_wk / $wkCount, 2);
            }
        }

        return ['MonthlyAvg' => $monthlyAvg, 'WeeklyAvg' => $weeklyAvg];
    }
}
