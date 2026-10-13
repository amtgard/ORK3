<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Park::GetAdminDashboard(), the data source behind the park
 * admin console's standing cards and work queue.
 *
 * The console renders whatever keys this returns, so the SHAPE is the contract:
 * a missing key is a PHP warning on a live admin page, and a wrong type is a
 * count rendered as an empty string. Values are data-dependent and deliberately
 * asserted only for the properties that must hold on ANY park.
 *
 * The one value that is genuinely load-bearing is QuietDays. It must be NULL --
 * not 0, and not a five-figure number produced by a zero date -- for a park that
 * has never recorded attendance, because "never" and "today" are opposite
 * answers for the officer reading the queue.
 */
final class ParkAdminDashboardTest extends TestCase
{
    private Park $parkDomain;

    private PDO $pdo;

    /** @var list<int> */
    private array $ephemeralParkIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->parkDomain = new Park();
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->ephemeralParkIds as $parkId) {
            $this->pdo->prepare('DELETE FROM ' . DB_PREFIX . 'park WHERE park_id = ?')->execute([$parkId]);
        }
        $this->ephemeralParkIds = [];
    }

    public function testDashboardShapeForRealPark(): void
    {
        $dashboard = $this->parkDomain->GetAdminDashboard($this->firstParkId());

        $this->assertDashboardShape($dashboard);

        // A park drawn from the sandbox has members and attendance, so these are
        // the properties that must hold rather than fixed numbers.
        $this->assertGreaterThan(0, $dashboard['Standing']['Members']);
        $this->assertGreaterThanOrEqual(0, $dashboard['Standing']['AttendanceYtd']);
        $this->assertNotNull($dashboard['Queue']['QuietDays'], 'A park with attendance must report a QuietDays number.');
        $this->assertGreaterThanOrEqual(0, $dashboard['Queue']['QuietDays'], 'QuietDays is never negative.');
    }

    public function testWindowsCarryTheThresholdsTheCountsUsed(): void
    {
        $dashboard = $this->parkDomain->GetAdminDashboard($this->firstParkId());

        // The template describes the windows from these rather than restating
        // literals; if they drift from the SQL the page lies about its own numbers.
        $this->assertSame(182, $dashboard['Windows']['ActiveWindowDays']);
        $this->assertSame(60, $dashboard['Windows']['QuietThreshold']);
    }

    public function testVacantOfficeNamesMatchVacantOfficeCount(): void
    {
        $dashboard = $this->parkDomain->GetAdminDashboard($this->firstParkId());

        $this->assertCount(
            $dashboard['Queue']['VacantOffices'],
            $dashboard['Windows']['VacantOfficeNames'],
            'The queue count and the names behind it are one list; they cannot disagree.'
        );
        $this->assertLessThanOrEqual(
            $dashboard['Windows']['OfficeCount'],
            $dashboard['Queue']['VacantOffices'],
            'A park cannot have more vacancies than it has offices.'
        );
        foreach ($dashboard['Windows']['VacantOfficeNames'] as $name) {
            $this->assertIsString($name);
            $this->assertNotSame('', $name, 'A vacant office with no display title cannot be rendered.');
        }
    }

    /**
     * @return list<array{0: int}>
     */
    public static function nonPositiveParkIdProvider(): array
    {
        return [[0], [-1], [-999]];
    }

    #[DataProvider('nonPositiveParkIdProvider')]
    public function testZeroSafeForNonPositiveParkId(int $parkId): void
    {
        $dashboard = $this->parkDomain->GetAdminDashboard($parkId);

        $this->assertDashboardShape($dashboard);
        $this->assertSame(0, $dashboard['Standing']['Members']);
        $this->assertSame(0, $dashboard['Standing']['ActivePlayers']);
        $this->assertSame(0, $dashboard['Standing']['AttendanceYtd']);
        $this->assertSame(0, $dashboard['Queue']['UnwaiveredActive']);
        $this->assertSame(0, $dashboard['Queue']['VacantOffices']);
        $this->assertSame(0, $dashboard['Queue']['OpenRecommendations']);
        $this->assertSame(0, $dashboard['Queue']['WaiveredMembers']);
        $this->assertSame(0, $dashboard['Windows']['OfficeCount']);
        $this->assertSame([], $dashboard['Windows']['VacantOfficeNames']);
        $this->assertNull($dashboard['Queue']['QuietDays'], 'No park means no last-signin date, which is NULL.');
    }

    public function testQuietDaysIsNullForAParkWithNoAttendance(): void
    {
        $parkId = $this->createEphemeralPark();

        $dashboard = $this->parkDomain->GetAdminDashboard($parkId);

        $this->assertDashboardShape($dashboard);
        $this->assertNull(
            $dashboard['Queue']['QuietDays'],
            'A park that has never recorded attendance has no "days since", so QuietDays must be NULL -- not 0, and not a DATEDIFF against a zero date.'
        );
        $this->assertSame(0, $dashboard['Standing']['AttendanceYtd']);
        $this->assertSame(0, $dashboard['Standing']['ActivePlayers']);
    }

    public function testGetAdminDashboardAcceptsANumericStringParkId(): void
    {
        $parkId = $this->firstParkId();

        // The controller casts, but the domain is the boundary that must hold:
        // a route argument arrives as a string everywhere else in this codebase.
        $this->assertSame(
            $this->parkDomain->GetAdminDashboard($parkId),
            $this->parkDomain->GetAdminDashboard((string) $parkId)
        );
    }

    /**
     * @param mixed $dashboard
     */
    private function assertDashboardShape($dashboard): void
    {
        $this->assertIsArray($dashboard);
        $this->assertSame(['Standing', 'Queue', 'Windows'], array_keys($dashboard));

        $this->assertSame(['Members', 'ActivePlayers', 'AttendanceYtd'], array_keys($dashboard['Standing']));
        foreach ($dashboard['Standing'] as $key => $value) {
            $this->assertIsInt($value, "Standing.{$key} must be an int.");
            $this->assertGreaterThanOrEqual(0, $value, "Standing.{$key} must not be negative.");
        }

        $this->assertSame(
            ['UnwaiveredActive', 'VacantOffices', 'QuietDays', 'OpenRecommendations', 'WaiveredMembers'],
            array_keys($dashboard['Queue'])
        );
        foreach (['UnwaiveredActive', 'VacantOffices', 'OpenRecommendations', 'WaiveredMembers'] as $key) {
            $this->assertIsInt($dashboard['Queue'][$key], "Queue.{$key} must be an int.");
            $this->assertGreaterThanOrEqual(0, $dashboard['Queue'][$key], "Queue.{$key} must not be negative.");
        }
        if ($dashboard['Queue']['QuietDays'] !== null) {
            $this->assertIsInt($dashboard['Queue']['QuietDays'], 'Queue.QuietDays is int|null.');
        }

        $this->assertSame(
            ['ActiveWindowDays', 'QuietThreshold', 'OfficeCount', 'VacantOfficeNames'],
            array_keys($dashboard['Windows'])
        );
        $this->assertIsInt($dashboard['Windows']['ActiveWindowDays']);
        $this->assertIsInt($dashboard['Windows']['QuietThreshold']);
        $this->assertIsInt($dashboard['Windows']['OfficeCount']);
        $this->assertIsArray($dashboard['Windows']['VacantOfficeNames']);
    }

    private function firstParkId(): int
    {
        $parkId = (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE active = 'Active' ORDER BY park_id ASC LIMIT 1"
        )->fetchColumn();
        $this->assertGreaterThan(0, $parkId, 'The sandbox must contain at least one active park.');

        return $parkId;
    }

    /**
     * A park with no attendance, no members and no officers. The sandbox ships
     * every park pre-populated, so the "never recorded anything" case has to be
     * created rather than found.
     */
    private function createEphemeralPark(): int
    {
        $kingdomId = (int) $this->pdo->query(
            'SELECT kingdom_id FROM ' . DB_PREFIX . "kingdom WHERE active = 'Active' ORDER BY kingdom_id ASC LIMIT 1"
        )->fetchColumn();

        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'park
             (kingdom_id, name, abbreviation, url, parktitle_id, active, address, city, province,
              postal_code, google_geocode, latitude, longitude, location, map_url, description, directions)
             VALUES (?, ?, ?, "", 1, "Active", "", "", "", "", "", 0, 0, "", "", "", "")'
        );
        $stmt->execute([$kingdomId, 'PADT Quiet Park ' . $suffix, 'ZZZ']);
        $parkId = (int) $this->pdo->lastInsertId();
        $this->ephemeralParkIds[] = $parkId;

        return $parkId;
    }
}
