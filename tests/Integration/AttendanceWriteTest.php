<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * In-memory ghettocache for attendance write characterization tests (T-ATT-06).
 */
final class AttendanceInMemoryGhettocache extends Ghettocache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get($call, $key, $lifetime)
    {
        $fullKey = $this->prefix . '.' . $call . '.' . $key;

        return $this->store[$fullKey] ?? false;
    }

    public function cache($call, $key, $content)
    {
        $fullKey = $this->prefix . '.' . $call . '.' . $key;
        $this->store[$fullKey] = $content;

        return $content;
    }

    public function bust($call, $key): void
    {
        unset($this->store[$this->prefix . '.' . $call . '.' . $key]);
    }

    public function has(string $call, string $key): bool
    {
        return array_key_exists($this->prefix . '.' . $call . '.' . $key, $this->store);
    }
}

/**
 * Characterization tests for attendance write side effects (T-ATT-02, T-ATT-06).
 */
final class AttendanceWriteTest extends TestCase
{
    private AttendanceFixture $fixture;

    private Attendance $attendanceDomain;

    private Ghettocache $originalCache;

    private AttendanceInMemoryGhettocache $cache;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = AttendanceFixture::create();
        $this->attendanceDomain = new Attendance();
        $this->originalCache = Ork3::$Lib->ghettocache;
        $this->cache = new AttendanceInMemoryGhettocache();
        Ork3::$Lib->ghettocache = $this->cache;
    }

    protected function tearDown(): void
    {
        Ork3::$Lib->ghettocache = $this->originalCache;
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testSetAttendanceReturnsEditorPersona(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $officer = $this->fixture->createParkOfficer($parkId, 'edit-off');
        $player = $this->fixture->createPlayer($parkId, 'edit-player');
        $attendanceId = $this->fixture->insertParkAttendance(
            $parkId,
            $kingdomId,
            $player['mundane_id'],
            date('Y-m-d'),
            $this->fixture->firstClassId(),
        );

        unset($_SESSION['is_authorized_mundane_id']);
        $update = $this->attendanceDomain->SetAttendance([
            'Token' => $officer['token'],
            'AttendanceId' => $attendanceId,
            'MundaneId' => $player['mundane_id'],
            'Date' => date('Y-m-d'),
            'Credits' => 2,
            'ClassId' => $this->fixture->secondClassId($this->fixture->firstClassId()),
        ]);
        $this->assertSame(0, $update['Status']);
        $this->assertSame($officer['mundane_id'], (int) ($update['EditorId'] ?? 0));
        $this->assertSame($this->fixture->fetchPersona($officer['mundane_id']), (string) ($update['EditorPersona'] ?? ''));
    }

    public function testCacheBustOnWrite(): void
    {
        $parkId = $this->fixture->firstParkId();
        $officer = $this->fixture->createParkOfficer($parkId, 'cache-off');
        $player = $this->fixture->createPlayer($parkId, 'cache-player');
        $assocKey = Ork3::$Lib->ghettocache->key(['MundaneId' => $player['mundane_id']]);
        $idKey = Ork3::$Lib->ghettocache->key([$player['mundane_id']]);

        $this->cache->cache('Player.GetPlayerProfileDetails', $assocKey, ['cached' => true]);
        $this->cache->cache('Player.GetPlayerAttendanceList', $assocKey, ['cached' => true]);
        $this->cache->cache('Player.get_latest_attendance_date', $idKey, ['cached' => true]);
        $this->cache->cache('Player.get_earliest_attendance_date', $idKey, ['cached' => true]);
        $this->cache->cache('Player.GetPlayerClasses', $assocKey, ['cached' => true]);

        $model = new Model_Attendance();
        $add = $model->add_attendance(
            $officer['token'],
            date('Y-m-d'),
            $parkId,
            null,
            $player['mundane_id'],
            $this->fixture->firstClassId(),
            1.0,
        );
        $this->assertSame(0, $add['Status']);
        if (!empty($add['Detail'])) {
            $this->fixture->trackAttendance((int) $add['Detail']);
        }

        $this->assertFalse($this->cache->has('Player.GetPlayerProfileDetails', $assocKey));
        $this->assertFalse($this->cache->has('Player.GetPlayerAttendanceList', $assocKey));
        $this->assertFalse($this->cache->has('Player.get_latest_attendance_date', $idKey));
        $this->assertFalse($this->cache->has('Player.get_earliest_attendance_date', $idKey));
        $this->assertFalse($this->cache->has('Player.GetPlayerClasses', $assocKey));
    }
}
