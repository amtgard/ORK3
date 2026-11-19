<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the shared award catalog + table maintenance endpoints.
 *
 * Both defects these cases pin survived review because nothing in the suite ever
 * called the methods: Award::EditAward() found on an unassigned property (a
 * guaranteed "Call to a member function find() on null" fatal in the live SOAP
 * endpoint), and Administration::OptimizeTable() fataled on count() before it
 * could report an honest count. Every assertion below goes through the real
 * method, never a replica of its body.
 */
final class AwardCatalogTest extends TestCase
{
    private AdminDashboardFixture $fixture;
    private PDO $pdo;
    private Award $awardDomain;
    private Administration $adminDomain;

    /** @var list<int> */
    private array $awardIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = AdminDashboardFixture::create();
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->awardDomain = new Award();
        $this->adminDomain = new Administration();
    }

    protected function tearDown(): void
    {
        foreach ($this->awardIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'award WHERE award_id = ' . (int) $id);
        }
        $this->awardIds = [];

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testEditAwardUpdatesTheCatalogRowInPlace(): void
    {
        $parkId = $this->fixture->firstParkId();
        $admin = $this->fixture->createPlayer($parkId, 'award-catalog-admin');
        $this->fixture->insertGlobalAdmin($admin['mundane_id']);

        $name = 'AwardCatalogTest Probe ' . bin2hex(random_bytes(4));
        $awardId = $this->seedAward($name);

        unset($_SESSION['is_authorized_mundane_id']);
        $this->awardDomain->EditAward([
            'Token' => $admin['token'],
            'AwardId' => $awardId,
            'Name' => $name . ' EDITED',
            'IsTitle' => 1,
            'TitleClass' => 3,
            'Peerage' => 'Knight',
            'OfficerRole' => 'kingdom',
            'IsLadder' => 1,
        ]);

        $row = $this->readAward($awardId);
        $this->assertSame($name . ' EDITED', $row['name']);
        $this->assertSame(1, (int) $row['is_title']);
        $this->assertSame(3, (int) $row['title_class']);
        $this->assertSame('Knight', $row['peerage']);
        $this->assertSame('kingdom', $row['officer_role']);
        $this->assertSame(1, (int) $row['is_ladder']);

        // save() must have updated, not inserted a twin.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'award WHERE name LIKE ?');
        $stmt->execute([$name . '%']);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testEditAwardRejectsUnknownAwardAndStrangerToken(): void
    {
        $parkId = $this->fixture->firstParkId();
        $admin = $this->fixture->createPlayer($parkId, 'award-catalog-admin2');
        $this->fixture->insertGlobalAdmin($admin['mundane_id']);
        $stranger = $this->fixture->createPlayer($parkId, 'award-catalog-stranger');

        $name = 'AwardCatalogTest Guard ' . bin2hex(random_bytes(4));
        $awardId = $this->seedAward($name);

        unset($_SESSION['is_authorized_mundane_id']);
        $missing = $this->awardDomain->EditAward([
            'Token' => $admin['token'],
            'AwardId' => 99999999,
            'Name' => 'AwardCatalogTest Should Not Exist',
            'IsTitle' => 0,
            'TitleClass' => 0,
            'Peerage' => 'None',
            'OfficerRole' => 'none',
        ]);
        $this->assertSame(ServiceErrorIds::InvalidParameter, $missing['Status'] ?? null);

        unset($_SESSION['is_authorized_mundane_id']);
        $denied = $this->awardDomain->EditAward([
            'Token' => $stranger['token'],
            'AwardId' => $awardId,
            'Name' => $name . ' HIJACKED',
            'IsTitle' => 0,
            'TitleClass' => 0,
            'Peerage' => 'None',
            'OfficerRole' => 'none',
        ]);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $denied['Status'] ?? null);
        $this->assertSame($name, $this->readAward($awardId)['name']);
    }

    public function testOptimizeTableCountsOnlyStatementsThatRan(): void
    {
        $parkId = $this->fixture->firstParkId();
        $admin = $this->fixture->createPlayer($parkId, 'optimize-admin');
        $this->fixture->insertGlobalAdmin($admin['mundane_id']);

        unset($_SESSION['is_authorized_mundane_id']);
        $ok = $this->adminDomain->OptimizeTable($admin['token'], [DB_PREFIX . 'log', DB_PREFIX . 'award']);
        $this->assertSame(ServiceErrorIds::Success, $ok['Status'] ?? null);
        $this->assertSame(2, $ok['Detail']);

        unset($_SESSION['is_authorized_mundane_id']);
        $none = $this->adminDomain->OptimizeTable($admin['token'], ['not_a_table']);
        $this->assertSame(ServiceErrorIds::Success, $none['Status'] ?? null);
        $this->assertSame(0, $none['Detail']);

        $stranger = $this->fixture->createPlayer($parkId, 'optimize-stranger');
        unset($_SESSION['is_authorized_mundane_id']);
        $denied = $this->adminDomain->OptimizeTable($stranger['token'], [DB_PREFIX . 'award']);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $denied['Status'] ?? null);
    }

    private function seedAward(string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'award
             (name, proposed_name, deprecate, is_ladder, is_title, title_class, peerage, crown_points, crown_limit, officer_role)
             VALUES (?, ?, 0, 0, 0, 0, ?, 0, 0, ?)'
        );
        $stmt->execute([$name, $name, 'None', 'none']);
        $id = (int) $this->pdo->lastInsertId();
        $this->awardIds[] = $id;

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function readAward(int $awardId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . DB_PREFIX . 'award WHERE award_id = ?');
        $stmt->execute([$awardId]);

        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
