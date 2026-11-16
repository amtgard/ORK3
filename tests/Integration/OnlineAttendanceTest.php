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

    public function testCreateParkLinkStoresOnline(): void
    {
        [$o, , $park] = $this->actors('lnk');
        unset($_SESSION['is_authorized_mundane_id']);
        $c = $this->attendanceDomain->CreateAttendanceLink([
            'Token' => $o['token'], 'ParkId' => $park, 'Credits' => 1, 'Hours' => 1, 'Online' => 1,
        ]);
        $this->assertSame(0, $c['Status']);
        $this->fixture->trackLink((int) $c['Detail']['LinkId']);

        unset($_SESSION['is_authorized_mundane_id']);
        $l = $this->attendanceDomain->GetAttendanceLinks(['Token' => $o['token'], 'ParkId' => $park]);
        $this->assertSame(0, $l['Status']);
        $found = null;
        foreach ($l['Detail'] as $row) {
            if ($row['LinkId'] === (int) $c['Detail']['LinkId']) {
                $found = $row;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame(1, $found['Online']);

        $info = $this->attendanceDomain->GetAttendanceLinkInfo(['LinkToken' => $c['Detail']['Token']]);
        $this->assertSame(1, $info['Detail']['Online']);
    }

    private function useLink(int $online): int
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $officer = $this->fixture->createParkOfficer($parkId, 'on-lo' . $online);
        $player = $this->fixture->createPlayer($parkId, 'on-lp' . $online);
        $link = $this->fixture->insertParkLink($parkId, $kingdomId, $officer['mundane_id'], null, $online);
        unset($_SESSION['is_authorized_mundane_id']);
        $use = $this->attendanceDomain->UseAttendanceLink([
            'Token' => $player['token'],
            'LinkToken' => $link['token'],
            'ClassId' => $this->fixture->firstClassId(),
        ]);
        $this->assertSame(0, $use['Status']);
        $id = (int) $use['Detail'];
        $this->fixture->trackAttendance($id);

        return $id;
    }

    public function testUseOnlineLinkWritesOnlineRow(): void
    {
        $id = $this->useLink(1);
        $this->assertSame(1, $this->fixture->fetchOnline($id));
    }

    public function testLegacyLinkWritesInPersonRow(): void
    {
        $id = $this->useLink(0);
        $this->assertSame(0, $this->fixture->fetchOnline($id));
    }

    private function dayType(int $park, string $date): array
    {
        return (new Park())->GetParkDayTypeForDate($park, $date);
    }

    private function dayTypePark(): int
    {
        $park = $this->fixture->parkIdWithoutParkDays();
        $this->assertGreaterThan(0, $park, 'Need a park with no park days');

        return $park;
    }

    public function testDayTypeOnlineOnlyWeekly(): void
    {
        $park = $this->dayTypePark();
        $this->fixture->insertParkDay($park, ['week_day' => 'Tuesday', 'online' => 1]);
        $tue = $this->dayType($park, '2026-11-17');
        $this->assertSame(0, $tue['Status']['Status']);
        $this->assertTrue($tue['Online']);
        $this->assertFalse($tue['InPerson']);
        $wed = $this->dayType($park, '2026-11-18');
        $this->assertFalse($wed['Online']);
        $this->assertFalse($wed['InPerson']);
    }

    public function testDayTypeOnlineAndInPersonSameWeekday(): void
    {
        $park = $this->dayTypePark();
        $this->fixture->insertParkDay($park, ['week_day' => 'Tuesday', 'online' => 1, 'description' => 'oa-online']);
        $this->fixture->insertParkDay($park, ['week_day' => 'Tuesday', 'online' => 0, 'description' => 'oa-inperson']);
        $r = $this->dayType($park, '2026-11-17');
        $this->assertTrue($r['Online']);
        $this->assertTrue($r['InPerson']);
    }

    public function testDayTypeEveryXWeeks(): void
    {
        $park = $this->dayTypePark();
        $this->fixture->insertParkDay($park, [
            'recurrence' => 'every-x-weeks', 'week_day' => 'Tuesday', 'online' => 1,
            'start_date' => '2026-11-03', 'week_interval' => 2,
        ]);
        $this->assertTrue($this->dayType($park, '2026-11-17')['Online']);
        $this->assertFalse($this->dayType($park, '2026-11-10')['Online']);
    }

    public function testDayTypeNoParkDaysAndInvalidDate(): void
    {
        $park = $this->dayTypePark();
        $r = $this->dayType($park, '2026-11-17');
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertFalse($r['Online']);
        $this->assertFalse($r['InPerson']);
        $this->assertNotSame(0, $this->dayType($park, '2026-13-40')['Status']['Status']);
    }

    /** @return array<int, array> rows keyed by AttendanceId */
    private function byId(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['AttendanceId']] = $row;
        }

        return $out;
    }

    public function testAttendanceForPlayerCarriesOnline(): void
    {
        [$o, $p, $park] = $this->actors('lp');
        $d = fn (int $n) => date('Y-m-d', strtotime("-$n days"));
        $online = $this->add($o, $p, $park, ['Online' => 1, 'Date' => $d(1)]);
        $inPerson = $this->add($o, $p, $park, ['Online' => 0, 'Date' => $d(2)]);

        $r = (new Player())->AttendanceForPlayer(['MundaneId' => $p['mundane_id']]);
        $this->assertSame(0, $r['Status']['Status']);
        $rows = $this->byId($r['Attendance']);
        $this->assertSame(1, $rows[$online]['Online']);
        $this->assertSame(0, $rows[$inPerson]['Online']);
    }

    public function testAttendanceForDateCarriesOnline(): void
    {
        $parkId = $this->fixture->firstParkId();
        $o = $this->fixture->createParkOfficer($parkId, 'on-o-ld');
        $p1 = $this->fixture->createPlayer($parkId, 'on-p-ld1');
        $p2 = $this->fixture->createPlayer($parkId, 'on-p-ld2');
        $date = date('Y-m-d', strtotime('-3 days'));
        $online = $this->add($o, $p1, $parkId, ['Online' => 1, 'Date' => $date]);
        $inPerson = $this->add($o, $p2, $parkId, ['Online' => 0, 'Date' => $date]);

        $r = (new Report())->AttendanceForDate(['ParkId' => $parkId, 'Date' => $date]);
        $this->assertSame(0, $r['Status']['Status']);
        $rows = $this->byId($r['Attendance']);
        $this->assertSame(1, $rows[$online]['Online']);
        $this->assertSame(0, $rows[$inPerson]['Online']);
    }

    public function testAttendanceDisplayRowCarriesOnline(): void
    {
        [$o, $p, $park] = $this->actors('dr');
        $d = fn (int $n) => date('Y-m-d', strtotime("-$n days"));
        $online = $this->add($o, $p, $park, ['Online' => 1, 'Date' => $d(4)]);
        $inPerson = $this->add($o, $p, $park, ['Online' => 0, 'Date' => $d(5)]);

        $ep = new EventPlanning();
        $this->assertSame(1, $ep->GetAttendanceDisplayRow($online)['Online']);
        $this->assertSame(0, $ep->GetAttendanceDisplayRow($inPerson)['Online']);
    }
}
