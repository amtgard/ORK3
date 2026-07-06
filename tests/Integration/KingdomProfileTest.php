<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Kingdom profile reads (T-KNG-01 through T-KNG-10).
 */
final class KingdomProfileTest extends TestCase
{
    private KingdomProfileFixture $fixture;

    private Kingdom $kingdomDomain;

    private Report $reportDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = KingdomProfileFixture::create();
        $this->kingdomDomain = new Kingdom();
        $this->reportDomain = new Report();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testGetKingdomEventSummary(): void
    {
        $ctx = $this->fixture->createPublishedEvent('summary');
        $summary = $this->mirrorKingdomEventSummary($ctx['kingdom_id'], uid: 0, isAdmin: false);

        $ids = array_column($summary, 'EventId');
        $this->assertContains($ctx['event_id'], $ids);
    }

    public function testEventSummaryStaffSeesDraft(): void
    {
        $ctx = $this->fixture->createPublishedEvent('draft-staff', 'draft');
        $staff = $this->fixture->createPlayer('draft-sched', $ctx['kingdom_id']);
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], canSchedule: true);

        $summary = $this->mirrorKingdomEventSummary($ctx['kingdom_id'], $staff['mundane_id'], isAdmin: false);
        $this->assertContains($ctx['event_id'], array_column($summary, 'EventId'));
    }

    public function testGetKingdomParkDaysListing(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $days = $this->mirrorKingdomParkDays($kid);
        $this->assertIsArray($days);
        foreach ($days as $day) {
            $this->assertArrayHasKey('ParkDayId', $day);
            $this->assertArrayHasKey('ParkName', $day);
        }
    }

    public function testGetKingdomPlayerCount(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('count-me', $kid);
        unset($player);

        $count = $this->mirrorKingdomPlayerCount($kid);
        $this->assertGreaterThan(0, $count);
    }

    public function testGetKingdomExtendedParkAverages(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $weekly = $this->reportDomain->GetKingdomParkAverages(['KingdomId' => $kid, 'AverageMonths' => 6]);
        $this->assertSame(0, $weekly['Status']['Status']);

        $extended = $this->mirrorExtendedParkAverages($kid, isAdmin: false);
        $this->assertArrayHasKey('_kingdom', $extended);
        $this->assertArrayHasKey('att', $extended['_kingdom']);
    }

    public function testPaginatedKingdomEvents(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $page = $this->mirrorPaginatedEvents($kid, window: 1);
        $this->assertSame(1, $page['Window']);
        $this->assertArrayHasKey('Events', $page);
    }

    public function testGetKingdomPlayersRoster(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $this->fixture->createPlayer('roster', $kid);

        $roster = $this->mirrorPlayersRoster($kid);
        $this->assertNotEmpty($roster);
        $this->assertArrayHasKey('persona', $roster[0]);
        $this->assertArrayHasKey('signinCount', $roster[0]);
    }

    public function testExportKingdomIcs(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $ics = $this->mirrorExportKingdomIcs($kid);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }

    public function testGetRoyalOfficerIds(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $royals = $this->mirrorRoyalOfficerIds($kid);
        $this->assertGreaterThan(0, $royals['monarch']);
        $this->assertGreaterThan(0, $royals['regent']);
    }

    public function testKingdomDomainReadsUsedByProfile(): void
    {
        $kid = $this->fixture->firstKingdomId();

        $details = $this->kingdomDomain->GetKingdomDetails(['KingdomId' => $kid]);
        $this->assertSame(0, $details['Status']['Status']);
        $this->assertNotEmpty($details['KingdomInfo']['KingdomName']);

        $parks = $this->kingdomDomain->GetParks(['KingdomId' => $kid]);
        $this->assertSame(0, $parks['Status']['Status']);
        $this->assertNotEmpty($parks['Parks']);

        $officers = $this->kingdomDomain->GetOfficers(['KingdomId' => $kid]);
        $this->assertSame(0, $officers['Status']['Status']);

        $family = $this->kingdomDomain->GetFamilyKingdomIds($kid);
        $this->assertContains($kid, $family);

        $monthly = $this->reportDomain->GetKingdomParkMonthlyAverages(['KingdomId' => $kid]);
        $this->assertSame(0, $monthly['Status']['Status']);
    }

    public function testReportAveragesShapeAndValues(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $weekly = $this->reportDomain->GetKingdomParkAverages([
            'KingdomId' => $kid,
            'AverageMonths' => 6,
            'ReportFromDate' => '',
            'AverageWeeks' => '',
        ]);
        $this->assertSame(0, $weekly['Status']['Status']);
        $summary = $weekly['KingdomParkAveragesSummary'];
        $this->assertIsArray($summary);
        $this->assertNotEmpty($summary);
        $first = $summary[0];
        $this->assertArrayHasKey('ParkId', $first);
        $this->assertArrayHasKey('ParkName', $first);
        $this->assertArrayHasKey('AttendanceCount', $first);
        $this->assertGreaterThan(0, (int) $first['ParkId']);
        $this->assertNotSame('', (string) $first['ParkName']);

        $byWeeks = $this->reportDomain->GetKingdomParkAverages([
            'KingdomId' => $kid,
            'AverageWeeks' => 26,
            'AverageMonths' => '',
            'ReportFromDate' => date('Y-m-d', strtotime('-6 month')),
        ]);
        $this->assertSame(0, $byWeeks['Status']['Status']);
        $this->assertIsArray($byWeeks['KingdomParkAveragesSummary']);

        $monthly = $this->reportDomain->GetKingdomParkMonthlyAverages(['KingdomId' => $kid]);
        $this->assertSame(0, $monthly['Status']['Status']);
        $monthRows = $monthly['KingdomParkMonthlySummary'] ?? [];
        $this->assertNotEmpty($monthRows);
        $this->assertArrayHasKey('MonthlyAvg', $monthRows[0]);
        $this->assertArrayHasKey('ParkId', $monthRows[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mirrorKingdomEventSummary(int $kingdomId, int $uid, bool $isAdmin): array
    {
        $statsEvtKids = implode(',', array_map('intval', $this->kingdomDomain->GetStatsKingdomIds($kingdomId)));
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
            'SELECT e.event_id, e.name, e.status, e.mundane_id AS event_creator, cd.event_start
             FROM ' . DB_PREFIX . 'event e
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
                AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
             WHERE e.kingdom_id IN (' . $statsEvtKids . ') ' . $draftClause
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
                'Status' => $rowStatus,
            ];
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mirrorKingdomParkDays(int $kingdomId): array
    {
        global $DB;
        $DB->Clear();
        $pdResult = $DB->DataSet(
            'SELECT pd.parkday_id, pd.park_id, p.name AS park_name
             FROM ' . DB_PREFIX . 'parkday pd
             JOIN ' . DB_PREFIX . 'park p ON p.park_id = pd.park_id
             WHERE p.kingdom_id = ' . (int) $kingdomId . " AND p.active = 'Active'
             ORDER BY p.name"
        );
        $days = [];
        while ($pdResult && $pdResult->Next()) {
            $days[] = [
                'ParkDayId' => (int) $pdResult->parkday_id,
                'ParkId' => (int) $pdResult->park_id,
                'ParkName' => $pdResult->park_name,
            ];
        }

        return $days;
    }

    private function mirrorKingdomPlayerCount(int $kingdomId): int
    {
        global $DB;
        $DB->Clear();
        $row = $DB->DataSet(
            'SELECT COUNT(*) AS n FROM ' . DB_PREFIX . 'mundane m
             INNER JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id AND p.kingdom_id = ' . (int) $kingdomId . '
             WHERE m.suspended = 0 AND m.active = 1'
        );

        return ($row && $row->Next()) ? (int) $row->n : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function mirrorExtendedParkAverages(int $kingdomId, bool $isAdmin): array
    {
        $statsKids = implode(',', array_map('intval', $this->kingdomDomain->GetStatsKingdomIds($kingdomId)));
        $weekly = $this->reportDomain->GetKingdomParkAverages(['KingdomId' => $kingdomId, 'AverageMonths' => 6]);
        $result = [];
        foreach ((array) ($weekly['KingdomParkAveragesSummary'] ?? []) as $park) {
            $result[$park['ParkId']] = ['att' => (int) $park['AttendanceCount'], 'mo' => 0, 'tp' => 0, 'tm' => 0];
        }

        global $DB;
        $wkStart = date('Y-m-d', strtotime('-6 month'));
        $DB->Clear();
        $knResult = $DB->DataSet(
            'SELECT COUNT(*) AS katt FROM (
                SELECT a.mundane_id FROM ' . DB_PREFIX . 'attendance a
                INNER JOIN ' . DB_PREFIX . 'park p ON p.park_id = a.park_id AND p.kingdom_id IN (' . $statsKids . ')
                WHERE a.date >= \'' . $wkStart . '\' AND a.mundane_id > 0
                GROUP BY a.date_year, a.date_week3, a.mundane_id
            ) t'
        );
        $katt = ($knResult && $knResult->Next()) ? (int) $knResult->katt : 0;
        $result['_kingdom'] = ['att' => $katt, 'mo' => 0, 'wk_count' => 26];
        unset($isAdmin);

        return $result;
    }

    /**
     * @return array{Window: int, Events: list<array<string, mixed>>}
     */
    private function mirrorPaginatedEvents(int $kingdomId, int $window): array
    {
        $statsEvtKids = implode(',', array_map('intval', $this->kingdomDomain->GetStatsKingdomIds($kingdomId)));
        $startMonths = $window * 12;
        $endMonths = $startMonths + 12;

        global $DB;
        $DB->Clear();
        $evtResult = $DB->DataSet(
            'SELECT e.event_id, e.name, cd.event_start, cd.event_calendardetail_id AS next_detail_id
             FROM ' . DB_PREFIX . 'event e
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
                AND cd.event_start > DATE_ADD(NOW(), INTERVAL ' . $startMonths . ' MONTH)
                AND cd.event_start <= DATE_ADD(NOW(), INTERVAL ' . $endMonths . ' MONTH)
             WHERE e.kingdom_id IN (' . $statsEvtKids . ')
             ORDER BY cd.event_start'
        );
        $events = [];
        while ($evtResult && $evtResult->Next()) {
            $events[] = [
                'EventId' => (int) $evtResult->event_id,
                'Name' => $evtResult->name,
                'NextDate' => $evtResult->event_start,
                'NextDetailId' => (int) $evtResult->next_detail_id,
            ];
        }

        return ['Window' => $window, 'Events' => $events];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mirrorPlayersRoster(int $kingdomId): array
    {
        global $DB;
        $DB->Clear();
        $r = $DB->DataSet(
            'SELECT m.mundane_id, m.persona, COALESCE(sub.signin_count, 0) AS signin_count
             FROM ' . DB_PREFIX . 'mundane m
             INNER JOIN ' . DB_PREFIX . 'park hp ON hp.park_id = m.park_id AND hp.kingdom_id = ' . (int) $kingdomId . '
             LEFT JOIN (
                SELECT a.mundane_id, SUM(a.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)) AS signin_count
                FROM ' . DB_PREFIX . 'attendance a
                INNER JOIN ' . DB_PREFIX . 'mundane mm ON mm.mundane_id = a.mundane_id AND mm.kingdom_id = ' . (int) $kingdomId . '
                GROUP BY a.mundane_id
             ) sub ON sub.mundane_id = m.mundane_id
             WHERE m.suspended = 0 AND m.active = 1
             GROUP BY m.mundane_id
             ORDER BY m.persona
             LIMIT 25'
        );
        $players = [];
        while ($r && $r->Next()) {
            $players[] = [
                'id' => (int) $r->mundane_id,
                'persona' => $r->persona,
                'signinCount' => (int) $r->signin_count,
            ];
        }

        return $players;
    }

    private function mirrorExportKingdomIcs(int $kingdomId): string
    {
        $statsEvtKids = implode(',', array_map('intval', $this->kingdomDomain->GetStatsKingdomIds($kingdomId)));
        global $DB;
        $DB->Clear();
        $result = $DB->DataSet(
            'SELECT e.event_id, e.name, cd.event_calendardetail_id, cd.event_start, cd.event_end
             FROM ' . DB_PREFIX . 'event e
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
                AND cd.event_start >= CURDATE()
                AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
             WHERE e.kingdom_id IN (' . $statsEvtKids . ')
             ORDER BY cd.event_start ASC'
        );

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ORK3//Amtgard ORK//EN'];
        while ($result && $result->Next()) {
            if ((int) $result->event_calendardetail_id === 0) {
                continue;
            }
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:event-' . (int) $result->event_id . '-' . (int) $result->event_calendardetail_id . '@ork3';
            $lines[] = 'SUMMARY:' . $result->name;
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    /**
     * @return array{monarch: int, regent: int}
     */
    private function mirrorRoyalOfficerIds(int $kingdomId): array
    {
        global $DB;
        $monarchId = 0;
        $regentId = 0;
        $DB->Clear();
        $mRes = $DB->DataSet(
            'SELECT mundane_id FROM ' . DB_PREFIX . "officer WHERE kingdom_id = {$kingdomId} AND park_id = 0 AND role = 'Monarch' AND mundane_id > 0 ORDER BY officer_id DESC LIMIT 1"
        );
        if ($mRes && $mRes->Next()) {
            $monarchId = (int) $mRes->mundane_id;
        }
        $DB->Clear();
        $rRes = $DB->DataSet(
            'SELECT mundane_id FROM ' . DB_PREFIX . "officer WHERE kingdom_id = {$kingdomId} AND park_id = 0 AND role = 'Regent' AND mundane_id > 0 ORDER BY officer_id DESC LIMIT 1"
        );
        if ($rRes && $rRes->Next()) {
            $regentId = (int) $rRes->mundane_id;
        }

        return ['monarch' => $monarchId, 'regent' => $regentId];
    }
}
