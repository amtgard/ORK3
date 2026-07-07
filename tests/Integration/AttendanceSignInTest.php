<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for attendance sign-in and link flows (T-ATT-01, T-SIN-01–03, T-QR-01).
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
        if (!empty($add['Detail'])) {
            $this->fixture->trackAttendance((int) $add['Detail']);
        }

        $this->mirrorAttendanceAjaxReactivate($player['mundane_id']);
        $this->assertSame(1, $this->fixture->fetchActive($player['mundane_id']));
    }

    public function testUseLinkReactivatesInactive(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $officer = $this->fixture->createParkOfficer($parkId, 'link-off');
        $player = $this->fixture->createPlayer($parkId, 'link-player', active: false);
        $link = $this->fixture->insertParkLink($parkId, $kingdomId, $officer['mundane_id']);

        unset($_SESSION['is_authorized_mundane_id']);
        $use = $this->attendanceDomain->UseAttendanceLink([
            'Token' => $player['token'],
            'LinkToken' => $link['token'],
            'ClassId' => $this->fixture->firstClassId(),
        ]);
        $this->assertSame(0, $use['Status']);

        $this->mirrorAttendanceAjaxReactivate($player['mundane_id']);
        $this->assertSame(1, $this->fixture->fetchActive($player['mundane_id']));
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

        $model = new Model_Attendance();
        $adjacent = $model->get_adjacent_park_dates($parkId, '2099-06-15');

        $this->assertSame('2099-06-01', $adjacent['prev']);
        $this->assertSame('2099-06-29', $adjacent['next']);
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

        $eventName = $this->mirrorSignInEventName((int) $info['Detail']['EventId']);
        $this->assertSame($event['name'], $eventName);
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

        $expected = $this->mirrorSignInLastClass($player['mundane_id']);
        $this->assertSame($classB, $expected);
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

        $signInCredits = $this->mirrorSignInCreditsSum($player['mundane_id'], $classId);
        $this->assertSame(4.0, $signInCredits);

        $playerDomain = new Player();
        $response = $playerDomain->GetPlayerClasses(['MundaneId' => $player['mundane_id']]);
        $this->assertSame(0, $response['Status']['Status']);

        $domainCredits = null;
        foreach ($response['Classes'] ?? [] as $row) {
            if ((int) ($row['ClassId'] ?? 0) === $classId) {
                $domainCredits = (float) ($row['Credits'] ?? 0) + (float) ($row['Reconciled'] ?? 0);
                break;
            }
        }

        $this->assertNotNull($domainCredits);
        $this->assertNotSame($signInCredits, $domainCredits);
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

        $this->assertTrue($this->mirrorQrTokenValid($valid['token']));
        $this->assertFalse($this->mirrorQrTokenValid($expired['token']));
        // QR route only checks expiry — revoked links still pass until R-12 uses GetAttendanceLinkInfo.
        $this->assertTrue($this->mirrorQrTokenValid($revoked['token']));

        $validInfo = $this->attendanceDomain->GetAttendanceLinkInfo(['LinkToken' => $valid['token']]);
        $expiredInfo = $this->attendanceDomain->GetAttendanceLinkInfo(['LinkToken' => $expired['token']]);
        $revokedInfo = $this->attendanceDomain->GetAttendanceLinkInfo(['LinkToken' => $revoked['token']]);

        $this->assertSame(0, $validInfo['Status']);
        $this->assertNotSame(0, $expiredInfo['Status']);
        $this->assertNotSame(0, $revokedInfo['Status']);
        $this->assertStringContainsString('revoked', strtolower((string) ($revokedInfo['Detail'] ?? '')));
    }

    private function mirrorAttendanceAjaxReactivate(int $mundaneId): void
    {
        if ($mundaneId <= 0) {
            return;
        }

        global $DB;
        $DB->Clear();
        $chk = $DB->DataSet(
            'SELECT active FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mundaneId . ' LIMIT 1'
        );
        if ($chk && $chk->Size() > 0 && $chk->Next() && (int) $chk->active === 0) {
            $DB->Clear();
            $DB->Execute('UPDATE ' . DB_PREFIX . 'mundane SET active = 1 WHERE mundane_id = ' . $mundaneId);
        }
    }

    private function mirrorSignInEventName(int $eventId): string
    {
        if (!valid_id($eventId)) {
            return '';
        }

        global $DB;
        $DB->Clear();
        $row = $DB->DataSet(
            'SELECT name FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $eventId . ' LIMIT 1'
        );
        if ($row && $row->Next()) {
            return (string) ($row->name ?: '');
        }

        return '';
    }

    private function mirrorSignInLastClass(int $mundaneId): int
    {
        global $DB;
        $DB->Clear();
        $lastRow = $DB->DataSet(
            'SELECT class_id FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = ' . $mundaneId
            . ' ORDER BY date DESC, attendance_id DESC LIMIT 1'
        );
        if ($lastRow && $lastRow->Next() && (int) $lastRow->class_id > 0) {
            return (int) $lastRow->class_id;
        }

        return 0;
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

    private function mirrorSignInCreditsSum(int $mundaneId, int $classId): float
    {
        global $DB;
        $perClass = [];
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT class_id, SUM(credits) AS c FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = '
            . $mundaneId . ' GROUP BY class_id'
        );
        if ($rs) {
            while ($rs->Next()) {
                $perClass[(int) $rs->class_id] = (float) $rs->c;
            }
        }
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT class_id, reconciled AS c FROM ' . DB_PREFIX . 'class_reconciliation WHERE mundane_id = '
            . $mundaneId
        );
        if ($rs) {
            while ($rs->Next()) {
                $cid = (int) $rs->class_id;
                $perClass[$cid] = ($perClass[$cid] ?? 0) + (float) $rs->c;
            }
        }

        return (float) ($perClass[$classId] ?? 0.0);
    }

    private function mirrorQrTokenValid(string $token): bool
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT link_id FROM ' . DB_PREFIX . "attendance_link WHERE token = '" . $token
            . "' AND expires_at > NOW() LIMIT 1"
        );

        return (bool) ($rs && $rs->Next() && (int) $rs->link_id > 0);
    }
}
