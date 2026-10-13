<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficerOccupancyTest extends TestCase
{
    private const MARKER = 'zzoccupancy';
    private const KINGDOM_ID = 100011;
    private const PARK_A = 100012;
    private const PARK_B = 100013;

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
        $this->seedPark(self::PARK_A);
        $this->seedPark(self::PARK_B);
        $this->fixture->grantParkAuthority(self::PARK_A);
        $this->fixture->grantParkAuthority(self::PARK_B);
        $this->seededPositions['crown_a'] = $this->seedPosition('crown_a', 'crown');
        $this->seededPositions['crown_b'] = $this->seedPosition('crown_b', 'crown');
        $this->seededPositions['support_a'] = $this->seedPosition('support_a', 'supporting');
        $this->seededMundanes[] = $this->seedMundane('holder');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->fixture->cleanup();
        // Scoped to entity ids this test owns, not a blanket delete of every
        // SetOccupant audit row in the database (ork_danger_audit carries no
        // marker column; entity_id is the closest scoped key SetOccupant's
        // audit() call writes).
        $this->pdo->exec(
            "DELETE FROM ork_danger_audit WHERE method_call = 'OfficerPosition::SetOccupant'"
            . ' AND entity_id IN (' . self::KINGDOM_ID . ', ' . self::PARK_A . ', ' . self::PARK_B . ')'
        );
        $this->pdo->exec("DELETE FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "%'");
        $this->pdo->exec('DELETE FROM ork_park WHERE park_id IN (' . self::PARK_A . ', ' . self::PARK_B . ')');
        if ($this->seededMundanes) {
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id IN (' . implode(',', $this->seededMundanes) . ')');
        }
    }

    /**
     * SetOccupant derives kingdom_id from the park (never trusts the request),
     * so PARK_A/PARK_B need a real ork_park row to resolve against -- unlike
     * the pre-token-gate SetOfficerByPosition, which never looked the park up
     * at all. Explicit park_id (not auto-increment) to match the PARK_A/PARK_B
     * constants every test in this file already references.
     */
    private function seedPark(int $parkId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_park
                (park_id, kingdom_id, name, abbreviation, url, address, city, province,
                 postal_code, google_geocode, latitude, longitude, location,
                 map_url, description, directions)
             VALUES (:pid, :kid, :name, "ZZT", "", "", "", "", "", "", 0, 0, "", "", "", "")'
        );
        $stmt->execute([
            ':pid' => $parkId,
            ':kid' => self::KINGDOM_ID,
            ':name' => self::MARKER . '_park_' . $parkId,
        ]);
    }

    /**
     * The rule that was removed. A person holding a crown office in one park must be
     * appointable to a crown office elsewhere -- 242 people in production do exactly
     * this, 176 of them twice in the same park.
     */
    public function testAPersonMayHoldTwoCrownOfficesInDifferentScopes(): void
    {
        $mundaneId = $this->seededMundanes[0];
        $token = $this->fixture->createAuthorizedOfficer();
        $base  = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'PositionId' => $this->seededPositions['crown_a']];

        $first = $this->positions->SetOccupant($base + ['ParkId' => self::PARK_A, 'MundaneId' => $mundaneId]);
        self::assertSame(0, (int) $first['Status'], 'first appointment should succeed: ' . ($first['Error'] ?? ''));

        $second = $this->positions->SetOccupant($base + ['ParkId' => self::PARK_B, 'MundaneId' => $mundaneId]);
        self::assertSame(
            0,
            (int) $second['Status'],
            'a second crown office in another park must be allowed: ' . ($second['Error'] ?? '')
        );
    }

    public function testAPersonMayHoldTwoCrownOfficesInTheSameScope(): void
    {
        $mundaneId = $this->seededMundanes[0];
        $token = $this->fixture->createAuthorizedOfficer();
        $base  = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => self::PARK_A, 'MundaneId' => $mundaneId];

        $this->positions->SetOccupant($base + ['PositionId' => $this->seededPositions['crown_a']]);
        $second = $this->positions->SetOccupant($base + ['PositionId' => $this->seededPositions['crown_b']]);
        self::assertSame(
            0,
            (int) $second['Status'],
            'two offices in one park must be allowed: ' . ($second['Error'] ?? '')
        );
    }

    /**
     * A supporting position used to append a second ork_officer row per new
     * occupant (crown replaces in place via set_officer; supporting had no
     * equivalent guard). An office holds exactly one person -- a kingdom that
     * wants two deputies creates two offices.
     */
    public function testASecondPersonCannotTakeAnOccupiedSeat(): void
    {
        $positionId = $this->seededPositions['support_a'];
        $first  = $this->seededMundanes[0];
        $second = $this->seedMundane('second');
        $this->seededMundanes[] = $second;

        $token = $this->fixture->createAuthorizedOfficer();
        $base = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
                 'PositionId' => $positionId];
        $firstResult = $this->positions->SetOccupant($base + ['MundaneId' => $first]);
        self::assertSame(0, (int) $firstResult['Status'], 'first appointment should succeed: ' . ($firstResult['Error'] ?? ''));

        $secondResult = $this->positions->SetOccupant($base + ['MundaneId' => $second]);
        self::assertSame(
            4,
            (int) $secondResult['Status'],
            'a second, different person must be refused the occupied seat'
        );

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer WHERE position_id = :pid AND mundane_id > 0'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'an office holds exactly one person');
    }

    /**
     * Re-seating the SAME person into a seat they already hold is idempotent,
     * not a refusal -- the refusal is only for a different person.
     */
    public function testReseatingTheSameHolderIsIdempotent(): void
    {
        $positionId = $this->seededPositions['support_a'];
        $mundaneId  = $this->seededMundanes[0];
        $token = $this->fixture->createAuthorizedOfficer();
        $base = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
                 'PositionId' => $positionId, 'MundaneId' => $mundaneId];

        $this->positions->SetOccupant($base);
        $again = $this->positions->SetOccupant($base);
        self::assertSame(0, (int) $again['Status'], 'reseating the same holder is idempotent: ' . ($again['Error'] ?? ''));

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer WHERE position_id = :pid AND mundane_id > 0'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'idempotent reseating must not duplicate the row');
    }

    /**
     * Retire is CROSS-SCOPE (clears the position everywhere); one-seat is
     * PER-SCOPE. Collapsing them would break retirement, which must still
     * clear a crown position seated in two parks plus the kingdom at once.
     */
    public function testRetireStillClearsEveryScope(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];
        $token = $this->fixture->createAuthorizedOfficer();
        foreach ([self::PARK_A, self::PARK_B, 0] as $parkId) {
            $this->positions->SetOccupant([
                'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => $parkId,
                'PositionId' => $positionId, 'MundaneId' => $mundaneId,
            ]);
        }
        $this->positions->RetirePosition([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId,
        ]);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer WHERE position_id = :pid AND mundane_id > 0'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(
            0,
            (int) $stmt->fetchColumn(),
            'retire is cross-scope; one-seat is per-scope. Collapsing them breaks retirement.'
        );
    }

    /**
     * InsertOfficerRow (the write setOfficerByPosition's non-crown branch
     * delegates to for a supporting position) had no future-end-date
     * validation of its own -- exactly how the one known-bad production row
     * the 2026-08-29 backfill migration repairs was created. TransitionOfficer/
     * AddHistoryTerm/EditHistoryTerm already reject a future end date; this
     * proves SetOccupant's InsertOfficerRow path now does too.
     */
    public function testRejectsAFutureTermEnd(): void
    {
        $positionId = $this->seededPositions['support_a'];
        $mundaneId  = $this->seededMundanes[0];
        $token = $this->fixture->createAuthorizedOfficer();

        $r = $this->positions->SetOccupant([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $mundaneId,
            'TermEnd' => date('Y-m-d', strtotime('+30 days')),
        ]);
        self::assertSame(
            4,
            (int) $r['Status'],
            'a future TermEnd must be rejected before InsertOfficerRow writes anything'
        );

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer WHERE position_id = :pid AND mundane_id > 0'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'the rejected request must write nothing');
    }

    /**
     * has_auth_role=1 plus EnsureCrownSlot's authorization_id=0 placeholder is
     * exactly the shape where Common::set_officer() silently no-ops
     * (common.php ~888-902). TransitionOfficer already guards this with a
     * post-write currentHolder() re-read (see
     * OfficerTransitionTest::testAMissingAuthorizationRowAbortsAsProcessingErrorNotSuccess);
     * SetOccupant is the ONLY assignment path the live console uses and never
     * got the same guard.
     */
    public function testAMissingAuthorizationRowAbortsAsProcessingErrorNotSuccess(): void
    {
        $positionId = $this->seedPosition('authgated', 'crown', 1);
        $incoming   = $this->seedMundane('authgated_incoming');
        $this->seededMundanes[] = $incoming;
        $token = $this->fixture->createAuthorizedOfficer();

        $r = $this->positions->SetOccupant([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $incoming,
        ]);
        self::assertSame(
            3,
            (int) $r['Status'],
            'a has_auth_role position with no matching ork_authorization row must abort as ProcessingError'
        );

        $stmt = $this->pdo->prepare(
            'SELECT mundane_id FROM ork_officer WHERE position_id = :pid'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(
            '0',
            (string) $stmt->fetchColumn(),
            'the seat must remain vacant, not silently unmoved-but-reported-Success'
        );
    }

    private function seedPosition(string $suffix, string $classification, int $hasAuthRole = 0): int
    {
        $key = self::MARKER . '_' . $suffix;
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_position
                (kingdom_id, canonical_key, title, title_alias, classification,
                 is_pinned, is_system, rbac_role_id, has_auth_role, sort_order,
                 parent_position_id, hide_when_vacant, retired_at, created_by, created_at)
             VALUES (:kid, :key, :title, "", :cls, 0, 0, 0, :har, 100, NULL, 0, NULL, 0, NOW())'
        );
        $stmt->execute([
            ':kid' => self::KINGDOM_ID,
            ':key' => $key,
            ':title' => 'Test ' . $suffix,
            ':cls' => $classification,
            ':har' => $hasAuthRole,
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
