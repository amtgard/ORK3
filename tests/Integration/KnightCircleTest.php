<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** In-memory ghettocache, so the roster cache and its bust are observable without memcached. */
final class KnightCircleInMemoryGhettocache extends Ghettocache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get($call, $key, $lifetime)
    {
        return $this->store[$this->prefix . '.' . $call . '.' . $key] ?? false;
    }

    public function cache($call, $key, $content)
    {
        $this->store[$this->prefix . '.' . $call . '.' . $key] = $content;
        return $content;
    }

    public function bust($call, $key): void
    {
        unset($this->store[$this->prefix . '.' . $call . '.' . $key]);
    }
}

/**
 * Circle of Knights Manager, roster half (KnightCircle): the authority matrix,
 * the settings, the GMoK and deputy appointments, and the roster with its
 * eligibility facts. Needs the knight-circle migration applied to the test DB;
 * skips otherwise.
 */
final class KnightCircleTest extends TestCase
{
    private ReportsFixture $fixture;
    private KnightCircle $circle;
    private Ghettocache $originalCache;
    private PDO $pdo;
    private int $kingdom = 0;
    private int $park = 0;
    private bool $hadConfigRow = false;
    /** @var int[] */
    private array $mundaneIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $this->fixture = ReportsFixture::create();
        $this->pdo     = $this->fixture->pdo();
        $exists = $this->pdo->query("SHOW TABLES LIKE '" . DB_PREFIX . "knight_circle_config'")->fetchColumn();
        if (!$exists) {
            $this->markTestSkipped('db-migrations/2026-11-28-knight-circle.sql is not applied to the test database.');
        }
        $this->originalCache = Ork3::$Lib->ghettocache;
        Ork3::$Lib->ghettocache = new KnightCircleInMemoryGhettocache();
        $this->circle = new KnightCircle();

        $this->kingdom = $this->fixture->firstKingdomId();
        $this->park    = $this->fixture->parkIdInKingdom($this->kingdom);
        $this->assertGreaterThan(0, $this->park, 'the sandbox needs a park in its first kingdom');
        $this->hadConfigRow = (bool) $this->pdo->query(
            'SELECT 1 FROM ' . DB_PREFIX . 'knight_circle_config WHERE kingdom_id = ' . $this->kingdom
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->fixture)) {
            if ($this->mundaneIds) {
                $in = implode(',', $this->mundaneIds);
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'knight_circle_admin WHERE mundane_id IN (' . $in . ')');
            }
            if (!$this->hadConfigRow && $this->kingdom > 0) {
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'knight_circle_config WHERE kingdom_id = ' . $this->kingdom);
            }
            $this->fixture->cleanup();
        }
        if (isset($this->originalCache)) {
            Ork3::$Lib->ghettocache = $this->originalCache;
        }
    }

    // ---- helpers ----------------------------------------------------------

    private function player(string $suffix, ?int $parkId = null): int
    {
        $p = $this->fixture->createPlayer($parkId ?? $this->park, $suffix);
        $this->fixture->makeVotingReady($p['mundane_id']);
        $this->mundaneIds[] = $p['mundane_id'];
        return $p['mundane_id'];
    }

    /** Make $mid a Knight of the given order (default Flame) via a real ork_awards row. */
    private function knight(int $mid, int $orderId = 17, int $revoked = 0, string $date = '2020-01-01'): int
    {
        $ka = $this->fixture->insertKingdomAward($this->kingdom, $orderId, 'KC test knight ' . $orderId);
        return $this->fixture->insertAwardOn($mid, $this->park, $this->kingdom, $ka, $orderId, 0, $date, $revoked);
    }

    private function signIns(int $mid, int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->fixture->insertAttendance($mid, $this->park, $this->kingdom, date('Y-m-d', strtotime("-{$i} days")));
        }
    }

    private function kingdomCreate(): int
    {
        $mid = $this->player('creator');
        $this->fixture->insertScopedAuth($mid, 0, $this->kingdom, AUTH_CREATE);
        return $mid;
    }

    private function orkAdmin(): int
    {
        $mid = $this->player('orkadmin');
        $this->fixture->insertGlobalAdmin($mid);
        return $mid;
    }

    /** @return array<int,array<string,mixed>> roster rows keyed by MundaneId */
    private function rosterById(int $uid, int $order = 0): array
    {
        $r = $this->circle->roster($uid, $this->kingdom, $order);
        $this->assertSame(0, $r['Status'], $r['Error'] ?? '');
        $by = [];
        foreach ($r['Knights'] as $k) {
            $by[$k['MundaneId']] = $k;
        }
        return $by;
    }

    // ---- config -----------------------------------------------------------

    public function testConfigFallsBackToDefaultsWhenThereIsNoRow(): void
    {
        $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'knight_circle_config WHERE kingdom_id = ' . $this->kingdom);
        $this->hadConfigRow = false;
        $this->assertSame(KnightCircle::DEFAULTS, $this->circle->config($this->kingdom));
    }

    public function testOnlyACircleAdminSavesConfigAndItRoundTrips(): void
    {
        $stranger = $this->player('stranger');
        $denied = $this->circle->saveConfig($stranger, $this->kingdom, ['min_signins' => 3]);
        $this->assertSame(3, $denied['Status']);

        $officer = $this->player('gmok');
        $this->knight($officer);
        $this->assertSame(0, $this->circle->setGmok($this->kingdomCreate(), $this->kingdom, $officer)['Status']);

        $bad = $this->circle->saveConfig($officer, $this->kingdom, ['min_signins' => 99]);
        $this->assertSame(1, $bad['Status']);

        $ok = $this->circle->saveConfig($officer, $this->kingdom, [
            'min_signins' => 3, 'signin_mode' => 'weeks', 'require_waiver' => 1, 'caveat' => "It's 'quoted' \\ text",
        ]);
        $this->assertSame(0, $ok['Status'], $ok['Error'] ?? '');
        $cfg = $this->circle->config($this->kingdom);
        $this->assertSame(3, $cfg['min_signins']);
        $this->assertSame('weeks', $cfg['signin_mode']);
        $this->assertSame(1, $cfg['require_waiver']);
        $this->assertSame("It's 'quoted' \\ text", $cfg['caveat']);
        $this->assertSame(6, $cfg['window_months'], 'keys not sent keep their value');
    }

    // ---- officers ---------------------------------------------------------

    public function testGmokAppointmentRules(): void
    {
        $creator = $this->kingdomCreate();
        $knight  = $this->player('knight');
        $this->knight($knight);
        $plain   = $this->player('plain');
        $stranger = $this->player('stranger');

        $this->assertSame(3, $this->circle->setGmok($stranger, $this->kingdom, $knight)['Status'], 'no authority');
        $this->assertSame(1, $this->circle->setGmok($creator, $this->kingdom, $plain)['Status'], 'target must be a knight');
        $this->assertSame(1, $this->circle->setGmok($creator, $this->kingdom, 0)['Status'], 'target required');

        $ok = $this->circle->setGmok($creator, $this->kingdom, $knight);
        $this->assertSame(0, $ok['Status'], $ok['Error'] ?? '');
        $this->assertSame('gmok', $ok['Admins'][0]['Role']);
        $this->assertSame($knight, $ok['Admins'][0]['MundaneId']);
        $this->assertTrue($this->circle->isCircleAdmin($knight, $this->kingdom));
        $this->assertFalse($this->circle->isCircleAdmin($plain, $this->kingdom));

        // An ORK admin may name a non-knight, and the new GMoK replaces the old.
        $admin = $this->orkAdmin();
        $replace = $this->circle->setGmok($admin, $this->kingdom, $plain);
        $this->assertSame(0, $replace['Status'], $replace['Error'] ?? '');
        $gmoks = array_values(array_filter($replace['Admins'], fn($a) => $a['Role'] === 'gmok'));
        $this->assertCount(1, $gmoks);
        $this->assertSame($plain, $gmoks[0]['MundaneId']);
        $this->assertFalse($this->circle->isCircleAdmin($knight, $this->kingdom), 'the old GMoK lost the role');
    }

    public function testDeputiesAreAddedAndRemovedByKingdomCreateOnly(): void
    {
        $creator = $this->kingdomCreate();
        $deputy  = $this->player('deputy');
        $stranger = $this->player('stranger');

        $this->assertSame(3, $this->circle->addDeputy($stranger, $this->kingdom, $deputy)['Status']);
        $added = $this->circle->addDeputy($creator, $this->kingdom, $deputy);
        $this->assertSame(0, $added['Status'], $added['Error'] ?? '');
        $this->assertTrue($this->circle->isCircleAdmin($deputy, $this->kingdom));
        $again = $this->circle->addDeputy($creator, $this->kingdom, $deputy);
        $this->assertCount(count($added['Admins']), $again['Admins'], 'adding twice is a no-op');

        $rowId = 0;
        foreach ($added['Admins'] as $a) {
            if ($a['MundaneId'] === $deputy) {
                $rowId = $a['CircleAdminId'];
            }
        }
        $this->assertGreaterThan(0, $rowId);
        $this->assertSame(3, $this->circle->removeAdmin($deputy, $this->kingdom, $rowId)['Status'], 'a deputy cannot remove');
        $this->assertSame(0, $this->circle->removeAdmin($creator, $this->kingdom, $rowId)['Status']);
        $this->assertFalse($this->circle->isCircleAdmin($deputy, $this->kingdom));
        $this->assertSame(0, $this->circle->removeAdmin($creator, $this->kingdom, $rowId)['Status'], 'idempotent');
    }

    // ---- access -----------------------------------------------------------

    public function testAccessMatrix(): void
    {
        $knight   = $this->player('knight');
        $this->knight($knight);
        $officer  = $this->player('officer');
        $this->fixture->insertScopedAuth($officer, 0, $this->kingdom, AUTH_EDIT);
        $creator  = $this->kingdomCreate();
        $stranger = $this->player('stranger');
        $admin    = $this->orkAdmin();

        $a = $this->circle->access($knight, $this->kingdom);
        $this->assertTrue($a['CanView']);
        $this->assertFalse($a['CanManage']);
        $this->assertFalse($a['CanAppoint']);
        $this->assertTrue($a['IsKnight']);

        $a = $this->circle->access($officer, $this->kingdom);
        $this->assertTrue($a['CanView']);
        $this->assertFalse($a['CanManage']);
        $this->assertFalse($a['CanAppoint']);
        $this->assertTrue($a['IsOfficer']);

        $a = $this->circle->access($creator, $this->kingdom);
        $this->assertTrue($a['CanView']);
        $this->assertTrue($a['CanAppoint']);
        $this->assertFalse($a['CanManage'], 'kingdom CREATE appoints but does not run the pipeline');

        $a = $this->circle->access($admin, $this->kingdom);
        $this->assertTrue($a['CanView'] && $a['CanManage'] && $a['CanAppoint'] && $a['IsOrkAdmin']);

        $a = $this->circle->access($stranger, $this->kingdom);
        $this->assertFalse($a['CanView']);
        $this->assertFalse($this->circle->access(0, $this->kingdom)['CanView']);
        $this->assertFalse($this->circle->access($admin, 0)['CanView']);
    }

    // ---- roster -----------------------------------------------------------

    public function testRosterIsClosedToStrangers(): void
    {
        $stranger = $this->player('stranger');
        $this->assertSame(3, $this->circle->roster($stranger, $this->kingdom)['Status']);
        $this->assertSame(3, $this->circle->admins($stranger, $this->kingdom)['Status']);
        $this->assertSame(1, $this->circle->roster($this->orkAdmin(), 0)['Status']);
        $this->assertSame(1, $this->circle->roster($this->orkAdmin(), $this->kingdom, 999)['Status']);
    }

    public function testRosterListsLiveKnightsWithEligibilityFacts(): void
    {
        $admin = $this->orkAdmin();

        $active = $this->player('active');
        $this->knight($active, 17);
        $this->signIns($active, 6);
        $this->fixture->insertDues($active, $this->park, $this->kingdom);

        $lapsed = $this->player('lapsed');
        $this->knight($lapsed, 19);
        $this->signIns($lapsed, 2);

        $revoked = $this->player('revoked');
        $this->knight($revoked, 17, 1);
        $this->signIns($revoked, 6);

        $notKnight = $this->player('notknight');
        $this->signIns($notKnight, 6);

        $suspended = $this->player('suspended');
        $this->knight($suspended, 20);
        $this->signIns($suspended, 6);
        $this->fixture->insertDues($suspended, $this->park, $this->kingdom);
        $this->fixture->setPlayerState($suspended, 1, 1);

        $by = $this->rosterById($admin);

        $this->assertArrayHasKey($active, $by);
        $this->assertTrue($by[$active]['Resident']);
        $this->assertSame(6, $by[$active]['SignIns']);
        $this->assertTrue($by[$active]['DuesPaid']);
        $this->assertNotNull($by[$active]['DuesUntil']);
        $this->assertTrue($by[$active]['Eligible'], implode(' ', $by[$active]['Reasons']));
        $this->assertSame([17], array_column($by[$active]['Orders'], 'AwardId'));
        $this->assertSame('Knight of the Flame', $by[$active]['Orders'][0]['Name']);
        $this->assertGreaterThan(0, $by[$active]['KnightMonths']);

        $this->assertArrayHasKey($lapsed, $by);
        $this->assertFalse($by[$lapsed]['Eligible']);
        $this->assertGreaterThanOrEqual(2, count($by[$lapsed]['Reasons']), 'too few sign-ins and no dues');

        $this->assertArrayNotHasKey($revoked, $by, 'a revoked knighthood is not a knighthood');
        $this->assertArrayNotHasKey($notKnight, $by);

        $this->assertArrayHasKey($suspended, $by);
        $this->assertTrue($by[$suspended]['Suspended']);
        $this->assertFalse($by[$suspended]['Eligible']);
    }

    public function testRosterOrderFilterAndSummary(): void
    {
        $admin = $this->orkAdmin();
        $flame = $this->player('flame');
        $this->knight($flame, 17);
        $serpent = $this->player('serpent');
        $this->knight($serpent, 19);

        $all = $this->circle->roster($admin, $this->kingdom);
        $flameOnly = $this->circle->roster($admin, $this->kingdom, 17);
        $this->assertSame(0, $flameOnly['Status']);
        $ids = array_column($flameOnly['Knights'], 'MundaneId');
        $this->assertContains($flame, $ids);
        $this->assertNotContains($serpent, $ids);
        $this->assertSame(count($flameOnly['Knights']), $flameOnly['Summary']['Total']);
        $this->assertLessThanOrEqual($all['Summary']['Total'], $flameOnly['Summary']['Total']);
        $this->assertSame(array_keys(KnightCircle::ORDERS), array_keys($all['Summary']['ByOrder']));
        $this->assertGreaterThanOrEqual(1, $all['Summary']['ByOrder'][19]['Count']);
        $this->assertArrayHasKey('WindowStart', $all);
        $this->assertSame($this->circle->config($this->kingdom), $all['Config']);
    }

    public function testNonResidentKnightAppearsOnlyWithInScopeSignIns(): void
    {
        $otherPark = (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE active = 'Active' AND kingdom_id <> " . $this->kingdom . ' AND kingdom_id > 0 ORDER BY park_id LIMIT 1'
        )->fetchColumn();
        if ($otherPark <= 0) {
            $this->markTestSkipped('The sandbox needs a park in a second kingdom.');
        }
        $otherKingdom = $this->fixture->kingdomIdForPark($otherPark);
        $admin = $this->orkAdmin();

        $visitor = $this->player('visitor', $otherPark);
        $ka = $this->fixture->insertKingdomAward($otherKingdom, 17, 'KC test visitor knight');
        $this->fixture->insertAwardOn($visitor, $otherPark, $otherKingdom, $ka, 17, 0, '2020-01-01');

        $this->assertArrayNotHasKey($visitor, $this->rosterById($admin), 'no sign-ins here, not listed');

        $this->circle->bustRoster($this->kingdom);
        $this->fixture->insertAttendance($visitor, $this->park, $this->kingdom, date('Y-m-d', strtotime('-1 day')));
        $by = $this->rosterById($admin);
        $this->assertArrayHasKey($visitor, $by);
        $this->assertFalse($by[$visitor]['Resident']);
        $this->assertFalse($by[$visitor]['Eligible'], 'residence is required by default');
    }

    public function testSavingConfigBustsTheCachedRoster(): void
    {
        $admin = $this->orkAdmin();
        $mid = $this->player('cached');
        $this->knight($mid);
        $this->signIns($mid, 3);
        $this->fixture->insertDues($mid, $this->park, $this->kingdom);

        $this->assertSame(0, $this->circle->saveConfig($admin, $this->kingdom, ['min_signins' => 6])['Status']);
        $this->assertFalse($this->rosterById($admin)[$mid]['Eligible']);

        $this->assertSame(0, $this->circle->saveConfig($admin, $this->kingdom, ['min_signins' => 3])['Status']);
        $this->assertTrue($this->rosterById($admin)[$mid]['Eligible']);
    }

    public function testSignInModeCountsDistinctDaysNotRows(): void
    {
        $admin = $this->orkAdmin();
        $mid = $this->player('days');
        $this->knight($mid);
        $day = date('Y-m-d', strtotime('-2 days'));
        $this->fixture->insertAttendance($mid, $this->park, $this->kingdom, $day);
        $this->fixture->insertAttendance($mid, $this->park, $this->kingdom, $day, 900000001); // same day, second row (an event sign-in)
        $this->assertSame(0, $this->circle->saveConfig($admin, $this->kingdom, ['signin_mode' => 'days'])['Status']);
        $this->assertSame(1, $this->rosterById($admin)[$mid]['SignIns']);
    }
}
