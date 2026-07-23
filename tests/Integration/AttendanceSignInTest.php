<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for attendance sign-in and link flows (T-ATT-01, T-SIN-01–03, T-QR-01).
 */
final class AttendanceSignInTest extends TestCase
{
    private AttendanceFixture $fixture;

    private Attendance $attendanceDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = AttendanceFixture::create();
        $this->attendanceDomain = new Attendance();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testAddAttendanceReactivatesInactive(): void
    {
        $parkId = $this->fixture->firstParkId();
        $officer = $this->fixture->createParkOfficer($parkId, 'react-off');
        $player = $this->fixture->createPlayer($parkId, 'react-player', active: false);
        $this->assertSame(0, $this->fixture->fetchActive($player['mundane_id']));

        unset($_SESSION['is_authorized_mundane_id']);
        $add = $this->attendanceDomain->AddAttendance([
            'Token' => $officer['token'],
            'ParkId' => $parkId,
            'MundaneId' => $player['mundane_id'],
            'ClassId' => $this->fixture->firstClassId(),
            'Credits' => 1,
            'Date' => date('Y-m-d'),
        ]);
        $this->assertSame(0, $add['Status']);
        $this->assertSame(1, (int) ($add['Reactivated'] ?? 0));
        if (!empty($add['Detail'])) {
            $this->fixture->trackAttendance((int) $add['Detail']);
        }

        $this->assertSame(1, $this->fixture->fetchActive($player['mundane_id']));
    }

    public function testUseLinkDoesNotReactivateInactive(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $officer = $this->fixture->createParkOfficer($parkId, 'link-off');
        $player = $this->fixture->createPlayer($parkId, 'link-player', active: false);
        $link = $this->fixture->insertParkLink($parkId, $kingdomId, $officer['mundane_id']);
        $this->assertSame(0, $this->fixture->fetchActive($player['mundane_id']));

        unset($_SESSION['is_authorized_mundane_id']);
        $use = $this->attendanceDomain->UseAttendanceLink([
            'Token' => $player['token'],
            'LinkToken' => $link['token'],
            'ClassId' => $this->fixture->firstClassId(),
        ]);
        $this->assertSame(0, $use['Status']);
        if (!empty($use['Detail'])) {
            $this->fixture->trackAttendance((int) $use['Detail']);
        }

        $this->assertSame(0, $this->fixture->fetchActive($player['mundane_id']));
    }

    public function testGetAdjacentParkDates(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $player = $this->fixture->createPlayer($parkId, 'adj-player');
        $classId = $this->fixture->firstClassId();

        $this->fixture->insertParkAttendance($parkId, $kingdomId, $player['mundane_id'], '2099-06-01', $classId);
        $this->fixture->insertParkAttendance($parkId, $kingdomId, $player['mundane_id'], '2099-06-15', $classId);
        $this->fixture->insertParkAttendance($parkId, $kingdomId, $player['mundane_id'], '2099-06-29', $classId);

        $adjacent = $this->attendanceDomain->GetAdjacentParkDates([
            'ParkId' => $parkId,
            'Date' => '2099-06-15',
        ]);
        $this->assertSame(0, $adjacent['Status']);
        $this->assertSame('2099-06-01', $adjacent['Detail']['prev']);
        $this->assertSame('2099-06-29', $adjacent['Detail']['next']);
    }

    public function testGetAttendanceLinkInfoIncludesEventName(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $officer = $this->fixture->createParkOfficer($parkId, 'ev-link-off');
        $event = $this->fixture->createPublishedEvent($parkId, 'signin-ev');
        $link = $this->fixture->insertEventLink(
            $event['event_id'],
            $event['detail_id'],
            $parkId,
            $kingdomId,
            $officer['mundane_id'],
        );

        $info = $this->attendanceDomain->GetAttendanceLinkInfo(['LinkToken' => $link['token']]);
        $this->assertSame(0, $info['Status']);
        $this->assertSame($event['event_id'], (int) ($info['Detail']['EventId'] ?? 0));
        $this->assertSame($event['name'], (string) ($info['Detail']['EventName'] ?? ''));
        $this->assertSame('event', (string) ($info['Detail']['ScopeType'] ?? ''));
    }

    public function testGetPlayerLastClass(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $player = $this->fixture->createPlayer($parkId, 'last-class');
        $classA = $this->fixture->firstClassId();
        $classB = $this->fixture->secondClassId($classA);

        $this->fixture->insertParkAttendance($parkId, $kingdomId, $player['mundane_id'], '2098-01-01', $classA);
        $this->fixture->insertParkAttendance($parkId, $kingdomId, $player['mundane_id'], '2098-02-01', $classB);

        $lastClass = (int) $this->attendanceDomain->GetPlayerLastClass(['MundaneId' => $player['mundane_id']]);
        $this->assertSame($classB, $lastClass);
    }

    public function testSignInClassProgressionUsesGetPlayerClasses(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $player = $this->fixture->createPlayer($parkId, 'credits');
        $classId = $this->fixture->firstClassId();
        $attendanceDate = '2097-05-10 12:00:00';

        $this->fixture->insertParkAttendance($parkId, $kingdomId, $player['mundane_id'], '2097-05-10', $classId, 1.0);
        $this->pdoInsertDuplicateAttendance(
            $parkId,
            $kingdomId,
            $player['mundane_id'],
            $attendanceDate,
            $classId,
        );
        $this->fixture->insertReconciliation($player['mundane_id'], $classId, 2.0);

        $playerDomain = new Player();
        $progress = $playerDomain->ComputeClassProgress(['MundaneId' => $player['mundane_id']]);
        $this->assertSame(0, $progress['Status']);

        $domainCredits = null;
        foreach ($progress['Detail'] ?? [] as $row) {
            if ((int) ($row['ClassId'] ?? 0) === $classId) {
                $domainCredits = (float) ($row['Credits'] ?? 0);
                break;
            }
        }

        $this->assertNotNull($domainCredits);
        $this->assertSame(3.0, $domainCredits);
    }

    public function testValidateLinkTokenForQr(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $officer = $this->fixture->createParkOfficer($parkId, 'qr-off');
        $valid = $this->fixture->insertParkLink($parkId, $kingdomId, $officer['mundane_id']);
        $expired = $this->fixture->insertParkLink($parkId, $kingdomId, $officer['mundane_id']);
        $this->fixture->expireLink($expired['link_id']);
        $revoked = $this->fixture->insertParkLink($parkId, $kingdomId, $officer['mundane_id']);
        $this->fixture->revokeLink($revoked['link_id']);

        $validInfo = $this->attendanceDomain->GetAttendanceLinkInfo(['LinkToken' => $valid['token']]);
        $expiredInfo = $this->attendanceDomain->GetAttendanceLinkInfo(['LinkToken' => $expired['token']]);
        $revokedInfo = $this->attendanceDomain->GetAttendanceLinkInfo(['LinkToken' => $revoked['token']]);

        $this->assertSame(0, $validInfo['Status']);
        $this->assertNotSame(0, $expiredInfo['Status']);
        $this->assertNotSame(0, $revokedInfo['Status']);
        $this->assertStringContainsString('revoked', strtolower((string) ($revokedInfo['Detail'] ?? '')));
    }

    private function pdoInsertDuplicateAttendance(
        int $parkId,
        int $kingdomId,
        int $mundaneId,
        string $dateTime,
        int $classId,
    ): void {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8',
                DB_HOSTNAME,
                DB_PORT,
                DB_DATABASE,
            ),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $ts = strtotime($dateTime);
        $stmt = $pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'attendance
             (park_id, kingdom_id, mundane_id, persona, class_id, date, credits, note, flavor, by_whom_id, entered_at,
              entry_method, event_id, event_calendardetail_id, date_year, date_month, date_week3, date_week6)
             VALUES (?, ?, ?, ?, ?, ?, 1, \'\', \'\', ?, NOW(), \'manual\', 0, 0, ?, ?, ?, 0)'
        );
        $stmt->execute([
            $parkId,
            $kingdomId,
            $mundaneId,
            'T12ATT duplicate same day',
            $classId,
            $dateTime,
            $mundaneId,
            (int) date('Y', $ts),
            (int) date('n', $ts),
            (int) date('W', $ts),
        ]);
        $this->fixture->trackAttendance((int) $pdo->lastInsertId());
    }
}
