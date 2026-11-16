<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests: AddAttendance / SetAttendance carry the Online flag.
 */
final class OnlineAttendanceTest extends TestCase
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

    /** @return array{0: array, 1: array, 2: int} officer, player, parkId */
    private function actors(string $suffix): array
    {
        $parkId = $this->fixture->firstParkId();
        $officer = $this->fixture->createParkOfficer($parkId, 'on-o-' . $suffix);
        $player = $this->fixture->createPlayer($parkId, 'on-p-' . $suffix);

        return [$officer, $player, $parkId];
    }

    private function add(array $officer, array $player, int $parkId, array $extra = []): int
    {
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->attendanceDomain->AddAttendance($extra + [
            'Token' => $officer['token'],
            'ParkId' => $parkId,
            'MundaneId' => $player['mundane_id'],
            'ClassId' => $this->fixture->firstClassId(),
            'Credits' => 1,
            'Date' => date('Y-m-d'),
        ]);
        $this->assertSame(0, $r['Status']);
        $id = (int) $r['Detail'];
        $this->fixture->trackAttendance($id);

        return $id;
    }

    private function set(array $officer, array $player, int $parkId, int $id, array $extra = []): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->attendanceDomain->SetAttendance($extra + [
            'Token' => $officer['token'],
            'ParkId' => $parkId,
            'AttendanceId' => $id,
            'MundaneId' => $player['mundane_id'],
            'ClassId' => $this->fixture->firstClassId(),
            'Credits' => 1,
            'Date' => date('Y-m-d'),
        ]);
        $this->assertSame(0, $r['Status']);
    }

    public function testAddAttendanceDefaultsInPerson(): void
    {
        [$o, $p, $park] = $this->actors('def');
        $id = $this->add($o, $p, $park);
        $this->assertSame(0, $this->fixture->fetchOnline($id));
    }

    public function testAddAttendanceOnline(): void
    {
        [$o, $p, $park] = $this->actors('on');
        // Distinct dates avoid the duplicate-attendance guard.
        $d = fn (int $n) => date('Y-m-d', strtotime("-$n days"));
        $this->assertSame(1, $this->fixture->fetchOnline($this->add($o, $p, $park, ['Online' => 1, 'Date' => $d(1)])));
        $this->assertSame(1, $this->fixture->fetchOnline($this->add($o, $p, $park, ['Online' => 'yes', 'Date' => $d(2)])));
        $this->assertSame(0, $this->fixture->fetchOnline($this->add($o, $p, $park, ['Online' => '0', 'Date' => $d(3)])));
    }

    public function testSetAttendanceFlipsOnline(): void
    {
        [$o, $p, $park] = $this->actors('flip');
        $id = $this->add($o, $p, $park, ['Online' => 1]);
        $this->assertSame(1, $this->fixture->fetchOnline($id));
        $this->set($o, $p, $park, $id, ['Online' => 0]);
        $this->assertSame(0, $this->fixture->fetchOnline($id));
    }

    public function testSetAttendanceWithoutOnlineKeepsValue(): void
    {
        [$o, $p, $park] = $this->actors('keep');
        $id = $this->add($o, $p, $park, ['Online' => 1]);
        $this->set($o, $p, $park, $id);
        $this->assertSame(1, $this->fixture->fetchOnline($id));
    }
}
