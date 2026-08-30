<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficerAuthorizationTest extends TestCase
{
    private const MARKER = 'zzofficerauth';
    private const KINGDOM_ID = 100031;

    private PDO $pdo;
    private OfficerPosition $positions;
    private AuthorizedOfficerFixture $fixture;
    /** @var array<string,int> */
    private array $seededPositions = [];
    /** @var list<int> */
    private array $seededMundanes = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->positions = new OfficerPosition();
        $this->fixture = new AuthorizedOfficerFixture($this->pdo, self::MARKER, self::KINGDOM_ID);
        $this->seededPositions['crown_a'] = $this->seedPosition('crown_a', 'crown');
        $this->seededPositions['crown_b'] = $this->seedPosition('crown_b', 'crown');
        $this->seededMundanes[] = $this->seedMundane('holder');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->fixture->cleanup();
        // Scoped to entity_id = self::KINGDOM_ID (every test in this file posts
        // ParkId => 0), not a blanket delete of every audit row in the database --
        // ork_danger_audit carries no marker column.
        $this->pdo->exec(
            "DELETE FROM ork_danger_audit WHERE method_call IN"
            . " ('OfficerPosition::SetOccupant', 'OfficerPosition::VacateOffice', 'OfficerPosition::VacateOfficer')"
            . ' AND entity_id = ' . self::KINGDOM_ID
        );
        $this->pdo->exec("DELETE FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "%'");
        if ($this->seededMundanes) {
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id IN (' . implode(',', $this->seededMundanes) . ')');
        }
    }

    public function testSetOccupantRejectsAnInvalidToken(): void
    {
        $r = $this->positions->SetOccupant([
            'Token' => 'not-a-real-token', 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $this->seededPositions['crown_a'],
            'MundaneId' => $this->seededMundanes[0],
        ]);
        self::assertSame(5, (int) $r['Status']);
    }

    public function testVacateRejectsAnInvalidToken(): void
    {
        $r = $this->positions->VacateOfficer([
            'Token' => '', 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $this->seededPositions['crown_a'],
            'MundaneId' => $this->seededMundanes[0],
        ]);
        self::assertSame(5, (int) $r['Status']);
    }

    public function testVacateChecksTheVacatePermissionNotTheSetPermission(): void
    {
        // Documents the gate the console never applied: OfficerAdminAjax mapped
        // every vacate action to kingdom.officer.set, so kingdom.officer.vacate
        // existed and was checked by nothing.
        self::assertSame('kingdom.officer.vacate', OfficerPosition::PermissionKeyFor('vacate', 0));
        self::assertSame('park.officer.vacate', OfficerPosition::PermissionKeyFor('vacate', 7));
    }

    public function testTheOldEntryPointsAreNoLongerPublic(): void
    {
        // Visibility is the lever, NOT casing. method_exists() is case-insensitive and
        // returns true for private methods, so reflect on visibility explicitly.
        $r = new ReflectionClass('OfficerPosition');
        self::assertTrue(
            $r->getMethod('setOfficerByPosition')->isPrivate(),
            'a public lowercase-initial method is still reachable as ?call=.../SetOfficerByPosition'
        );
        self::assertTrue($r->getMethod('vacateOfficerByPosition')->isPrivate());
        self::assertTrue($r->getMethod('SetOccupant')->isPublic());
        self::assertTrue($r->getMethod('VacateOfficer')->isPublic());
        self::assertTrue($r->getMethod('VacateOffice')->isPublic());
    }

    public function testVacateOfficeRejectsAnInvalidToken(): void
    {
        $r = $this->positions->VacateOffice([
            'Token' => 'not-a-real-token', 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $this->seededPositions['crown_a'],
        ]);
        self::assertSame(5, (int) $r['Status']);
    }

    /**
     * VacateOffice is the console's ONLY vacate control for crown offices
     * (_manage_officers.tpl's per-holder button is gated to non-crown
     * positions) and takes no MundaneId at all -- restoring it after it was
     * mistakenly deleted (Task 5) is what this test guards against
     * regressing again.
     */
    public function testVacateOfficeClearsTheSeatAndClosesTheOpenTerm(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];
        $token      = $this->fixture->createAuthorizedOfficer();

        $seat = $this->positions->SetOccupant([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $mundaneId,
        ]);
        self::assertSame(0, (int) $seat['Status'], 'setup: seating the officer must succeed: ' . ($seat['Error'] ?? ''));

        $r = $this->positions->VacateOffice([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId,
        ]);
        self::assertSame(0, (int) $r['Status'], $r['Error'] ?? '');

        $seatStmt = $this->pdo->prepare(
            'SELECT mundane_id FROM ork_officer WHERE kingdom_id = :kid AND park_id = 0 AND position_id = :pid'
        );
        $seatStmt->execute([':kid' => self::KINGDOM_ID, ':pid' => $positionId]);
        $remaining = $seatStmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains(
            $mundaneId,
            array_map('intval', $remaining),
            'the office must no longer show this person as the seated occupant'
        );

        $historyStmt = $this->pdo->prepare(
            'SELECT end_date FROM ork_officer_history
             WHERE position_id = :pid AND mundane_id = :mid ORDER BY officer_history_id DESC LIMIT 1'
        );
        $historyStmt->execute([':pid' => $positionId, ':mid' => $mundaneId]);
        self::assertNotNull(
            $historyStmt->fetchColumn(),
            'vacating must close the open history term, not leave it open'
        );
    }

    /**
     * Proves VacateOfficer and VacateOffice stayed two distinct verbs: a
     * missing MundaneId on VacateOfficer is rejected rather than silently
     * falling back to the all-holders behaviour VacateOffice provides.
     */
    public function testVacateOfficerStillRejectsAMissingMundaneId(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();

        $r = $this->positions->VacateOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $this->seededPositions['crown_a'],
        ]);
        self::assertSame(4, (int) $r['Status']);
    }

    private function seedPosition(string $suffix, string $classification): int
    {
        $key = self::MARKER . '_' . $suffix;
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_position
                (kingdom_id, canonical_key, title, title_alias, classification,
                 is_pinned, is_system, rbac_role_id, has_auth_role, sort_order,
                 parent_position_id, hide_when_vacant, retired_at, created_by, created_at)
             VALUES (:kid, :key, :title, "", :cls, 0, 0, 0, 0, 100, NULL, 0, NULL, 0, NOW())'
        );
        $stmt->execute([
            ':kid' => self::KINGDOM_ID,
            ':key' => $key,
            ':title' => 'Test ' . $suffix,
            ':cls' => $classification,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedMundane(string $suffix): int
    {
        $username = self::MARKER . '_' . $suffix . '_' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES (:g, "Test", "", :u, :p, :e, 0, :kid, "", "", "0000-00-00", "", "", "0000-00-00")'
        );
        $stmt->execute([
            ':g' => self::MARKER,
            ':u' => $username,
            ':p' => self::MARKER . ' ' . $suffix,
            ':e' => $username . '@example.test',
            ':kid' => self::KINGDOM_ID,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
