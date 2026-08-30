<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficerTransitionTest extends TestCase
{
    private const MARKER = 'zztransition';
    private const KINGDOM_ID = 100021;

    private PDO $pdo;
    private OfficerPosition $positions;
    private AuthorizedOfficerFixture $fixture;
    /** @var array<string,int> */
    private array $seededPositions = [];
    /** @var list<int> */
    private array $seededMundanes = [];
    /** @var list<int> ork_park rows seeded for park-scoped transitions */
    private array $seededParkIds = [];

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
        // Scoped to entity ids this test owns (self::KINGDOM_ID + any park seeded
        // below), not a blanket delete of every TransitionOfficer audit row in
        // the database -- ork_danger_audit carries no marker column, and
        // entity_id is the closest scoped key TransitionOfficer's audit() call
        // writes.
        $entityIds = array_merge([self::KINGDOM_ID], $this->seededParkIds);
        $this->pdo->exec(
            "DELETE FROM ork_danger_audit WHERE method_call = 'OfficerPosition::TransitionOfficer'"
            . ' AND entity_id IN (' . implode(',', array_map('intval', $entityIds)) . ')'
        );
        $this->pdo->exec("DELETE FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "%'");
        if ($this->seededParkIds) {
            $this->pdo->exec('DELETE FROM ork_park WHERE park_id IN (' . implode(',', $this->seededParkIds) . ')');
        }
        if ($this->seededMundanes) {
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id IN (' . implode(',', $this->seededMundanes) . ')');
        }
    }

    public function testSkipHistorySuppressesTheLegacyHistoryWrite(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];

        // Seed the vacant slot set_officer's find() requires -- without this row,
        // find() fails and NOTHING is written, which would make this test pass
        // trivially regardless of whether $skip_history is honored.
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, 0, 0, :role, 0, 0, :pid, NOW())'
        )->execute([':kid' => self::KINGDOM_ID, ':role' => self::MARKER . '_crown_a', ':pid' => $positionId]);

        $common = new Common();
        $common->set_officer(
            self::KINGDOM_ID,
            0,
            $mundaneId,
            self::MARKER . '_crown_a',
            0,
            0,
            $positionId,
            'Test crown_a',
            true   // $skip_history
        );

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = :pid'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(
            0,
            (int) $stmt->fetchColumn(),
            'skip_history must suppress the term write entirely'
        );
    }

    public function testDefaultStillWritesHistorySoExistingCallersAreUnchanged(): void
    {
        $positionId = $this->seededPositions['crown_b'];
        $mundaneId  = $this->seededMundanes[0];

        // Seed the vacant slot set_officer's find() requires.
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, 0, 0, :role, 0, 0, :pid, NOW())'
        )->execute([':kid' => self::KINGDOM_ID, ':role' => self::MARKER . '_crown_b', ':pid' => $positionId]);

        $common = new Common();
        $common->set_officer(
            self::KINGDOM_ID,
            0,
            $mundaneId,
            self::MARKER . '_crown_b',
            0,
            0,
            $positionId,
            'Test crown_b'        // flag omitted
        );

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = :pid AND end_date IS NULL'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(
            1,
            (int) $stmt->fetchColumn(),
            'omitting the flag must behave exactly as before'
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

    /** seedPosition(), but in an arbitrary kingdom -- for the park-scoped test. */
    private function seedPositionInKingdom(string $suffix, string $classification, int $kingdomId): int
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
            ':kid' => $kingdomId,
            ':key' => $key,
            ':title' => 'Test ' . $suffix,
            ':cls' => $classification,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedPark(int $kingdomId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO ork_park
                (kingdom_id, name, abbreviation, url, address, city, province,
                 postal_code, google_geocode, latitude, longitude, location,
                 map_url, description, directions)
             VALUES (:kid, :name, 'ZZT', '', '', '', '', '', '', 0, 0, '', '', '', '')"
        );
        $stmt->execute([
            ':kid' => $kingdomId,
            ':name' => self::MARKER . '_park_' . bin2hex(random_bytes(3)),
        ]);
        $parkId = (int) $this->pdo->lastInsertId();
        $this->seededParkIds[] = $parkId;

        return $parkId;
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

    public function testRejectsAnAbsentToken(): void
    {
        $r = $this->positions->TransitionOfficer([
            'Token' => '', 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $this->seededPositions['crown_a'],
            'MundaneId' => $this->seededMundanes[0],
        ]);
        self::assertSame(5, (int) $r['Status'], 'an absent token must be NoAuthorization');
    }

    public function testBackdatedEndDateIsHonouredForACrownOffice(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $outgoing   = $this->seededMundanes[0];
        $incoming   = $this->seedMundane('incoming');
        $this->seededMundanes[] = $incoming;
        $token      = $this->fixture->createAuthorizedOfficer();

        // Seat the outgoing officer with an open term starting well in the past.
        $this->seatWithOpenTerm($positionId, $outgoing, '2026-03-02');

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $incoming,
            'OutgoingEndDate' => '2026-08-15',
            'TermStart' => '2026-08-15',
            'Note' => 'Reign 42',
        ]);
        self::assertSame(0, (int) $r['Status'], $r['Error'] ?? '');

        $stmt = $this->pdo->prepare(
            'SELECT mundane_id, start_date, end_date, notes FROM ork_officer_history
             WHERE position_id = :pid ORDER BY officer_history_id'
        );
        $stmt->execute([':pid' => $positionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(2, $rows, 'exactly one term closed and one opened');
        self::assertSame($outgoing, (int) $rows[0]['mundane_id']);
        self::assertSame('2026-08-15', $rows[0]['end_date'], 'the backdated end date must be stored, not today');
        self::assertSame($incoming, (int) $rows[1]['mundane_id']);
        self::assertSame('2026-08-15', $rows[1]['start_date']);
        self::assertNull($rows[1]['end_date'], 'the incoming term must be open');
        self::assertSame('Reign 42', $rows[1]['notes'], 'the note must persist');

        // The history rows alone don't prove the seat itself moved -- under C2
        // (set_officer's silent no-op on a missing authorization row) this could
        // pass with the outgoing officer still seated in ork_officer.
        $seatStmt = $this->pdo->prepare('SELECT mundane_id FROM ork_officer WHERE position_id = :pid');
        $seatStmt->execute([':pid' => $positionId]);
        self::assertSame($incoming, (int) $seatStmt->fetchColumn(), 'the seat itself must have moved');
    }

    public function testRejectsAFutureEndDate(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $token      = $this->fixture->createAuthorizedOfficer();
        $this->seatWithOpenTerm($positionId, $this->seededMundanes[0], '2026-03-02');

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $this->seededMundanes[0],
            'OutgoingEndDate' => date('Y-m-d', strtotime('+30 days')),
        ]);
        self::assertSame(
            4,
            (int) $r['Status'],
            'a future end date is what made a sitting officer read as departed'
        );
    }

    public function testRejectsAnEndDateBeforeTheOpenTermBegan(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $token      = $this->fixture->createAuthorizedOfficer();
        $this->seatWithOpenTerm($positionId, $this->seededMundanes[0], '2026-03-02');

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $this->seededMundanes[0],
            'OutgoingEndDate' => '2026-01-01',
        ]);
        self::assertSame(
            4,
            (int) $r['Status'],
            'the outgoing term opened 2026-03-02; an end date before that is rejected by the $end < $open_start check'
        );
    }

    /**
     * Distinct from testRejectsAnEndDateBeforeTheOpenTermBegan(): that test's end
     * date fails the $end < $open_start check without TermStart ever being
     * exercised (TermStart defaults to OutgoingEndDate, so $start < $end can
     * never be true when TermStart is omitted). This test passes an explicit
     * TermStart to reach the `$start < $end` branch specifically.
     */
    public function testRejectsATermStartBeforeTheOutgoingEndDate(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $token      = $this->fixture->createAuthorizedOfficer();
        $this->seatWithOpenTerm($positionId, $this->seededMundanes[0], '2026-03-02');

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $this->seededMundanes[0],
            'OutgoingEndDate' => '2026-08-15',
            'TermStart' => '2026-08-01',
        ]);
        self::assertSame(
            4,
            (int) $r['Status'],
            'an incoming TermStart before the outgoing OutgoingEndDate must be rejected'
        );
    }

    /**
     * The $start < $end check used to run unconditionally, before $outgoing was
     * even read -- so filling a VACANT office with a term that began in the
     * past failed unless the caller also sent a semantically meaningless
     * OutgoingEndDate ($end defaults to today, and a January TermStart is
     * always < today). The wizard skips step 1 when there is no outgoing
     * officer, so it never sends OutgoingEndDate. crown_b has no seated holder
     * -- setUp() never occupies it.
     */
    public function testAppointingToAVacantOfficeAllowsABackdatedTermStart(): void
    {
        $positionId = $this->seededPositions['crown_b'];
        $incoming   = $this->seedMundane('vacant_incoming');
        $this->seededMundanes[] = $incoming;
        $token      = $this->fixture->createAuthorizedOfficer();

        // Seed the vacant slot set_officer's find() requires.
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, 0, 0, :role, 0, 0, :pid, NOW())'
        )->execute([':kid' => self::KINGDOM_ID, ':role' => self::MARKER . '_crown_b', ':pid' => $positionId]);

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $incoming,
            'TermStart' => '2026-01-15',
        ]);
        self::assertSame(0, (int) $r['Status'], $r['Error'] ?? '');

        $stmt = $this->pdo->prepare(
            'SELECT start_date, end_date FROM ork_officer_history
             WHERE position_id = :pid AND mundane_id = :mid'
        );
        $stmt->execute([':pid' => $positionId, ':mid' => $incoming]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('2026-01-15', $row['start_date'], 'the backdated start date must be stored');
        self::assertNull($row['end_date'], 'the new term must be open');

        $seatStmt = $this->pdo->prepare('SELECT mundane_id FROM ork_officer WHERE position_id = :pid');
        $seatStmt->execute([':pid' => $positionId]);
        self::assertSame($incoming, (int) $seatStmt->fetchColumn(), 'the seat itself must have moved');
    }

    public function testAuditRowIsAttributedToTheTokenOwnerNotZero(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $incoming   = $this->seedMundane('audited');
        $this->seededMundanes[] = $incoming;
        $token      = $this->fixture->createAuthorizedOfficer();
        $actorId    = $this->fixture->officerMundaneId();
        $this->seatWithOpenTerm($positionId, $this->seededMundanes[0], '2026-03-02');

        $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $incoming,
        ]);

        $stmt = $this->pdo->prepare(
            "SELECT by_whom_id FROM ork_danger_audit
             WHERE method_call = 'OfficerPosition::TransitionOfficer'
             ORDER BY danger_audit_id DESC LIMIT 1"
        );
        $stmt->execute();
        self::assertSame(
            $actorId,
            (int) $stmt->fetchColumn(),
            'DangerAudit reads $_SESSION[is_authorized_mundane_id]; IsAuthorized must run first'
        );
    }

    public function testTheIncomingOfficerMustBelongToTheKingdom(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $token      = $this->fixture->createAuthorizedOfficer();
        $outsider   = $this->seedMundaneInKingdom('outsider', 999999);
        $this->seededMundanes[] = $outsider;

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $outsider,
        ]);
        self::assertSame(
            4,
            (int) $r['Status'],
            'matches the rule the legacy path has always applied (Kingdom::SetOfficer:1348)'
        );
    }

    public function testOutgoingStartDateBackfillsANullStart(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $outgoing   = $this->seededMundanes[0];
        $incoming   = $this->seedMundane('backfill_incoming');
        $this->seededMundanes[] = $incoming;
        $token      = $this->fixture->createAuthorizedOfficer();

        // Appointed before officer history tracked a start date.
        $this->seatWithOpenTerm($positionId, $outgoing, null);

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $incoming,
            'OutgoingEndDate' => '2026-08-15',
            'OutgoingStartDate' => '2020-01-01',
        ]);
        self::assertSame(0, (int) $r['Status'], $r['Error'] ?? '');

        $stmt = $this->pdo->prepare(
            'SELECT start_date FROM ork_officer_history WHERE position_id = :pid AND mundane_id = :mid'
        );
        $stmt->execute([':pid' => $positionId, ':mid' => $outgoing]);
        self::assertSame(
            '2020-01-01',
            $stmt->fetchColumn(),
            'a NULL start_date on the closed term must be backfilled from OutgoingStartDate'
        );
    }

    public function testOutgoingStartDateNeverOverwritesAnExistingStart(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $outgoing   = $this->seededMundanes[0];
        $incoming   = $this->seedMundane('nooverwrite_incoming');
        $this->seededMundanes[] = $incoming;
        $token      = $this->fixture->createAuthorizedOfficer();

        $this->seatWithOpenTerm($positionId, $outgoing, '2026-03-02');

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $incoming,
            'OutgoingEndDate' => '2026-08-15',
            'OutgoingStartDate' => '2020-01-01',
        ]);
        self::assertSame(0, (int) $r['Status'], $r['Error'] ?? '');

        $stmt = $this->pdo->prepare(
            'SELECT start_date FROM ork_officer_history WHERE position_id = :pid AND mundane_id = :mid'
        );
        $stmt->execute([':pid' => $positionId, ':mid' => $outgoing]);
        self::assertSame(
            '2026-03-02',
            $stmt->fetchColumn(),
            'a start_date already on the row must never be overwritten by OutgoingStartDate'
        );
    }

    /**
     * The office could exist only in a foreign kingdom the actor was never
     * authorized against, so has_auth_role=1 (the Core Five's shape) plus
     * EnsureCrownSlot's authorization_id=0 placeholder is the concrete case
     * where set_officer() silently no-ops (common.php ~888-902). Before C2 this
     * returned Success() with the outgoing term closed and the seat unmoved.
     */
    public function testAMissingAuthorizationRowAbortsAsProcessingErrorNotSuccess(): void
    {
        $positionId = $this->seedPosition('authgated', 'crown', 1);
        $incoming   = $this->seedMundane('authgated_incoming');
        $this->seededMundanes[] = $incoming;
        $token      = $this->fixture->createAuthorizedOfficer();

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $incoming,
        ]);
        self::assertSame(
            3,
            (int) $r['Status'],
            'a has_auth_role position with no matching ork_authorization row must abort as ProcessingError'
        );

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = :pid AND end_date IS NULL'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(
            0,
            (int) $stmt->fetchColumn(),
            'no new open term may be recorded when the seat was never actually reassigned'
        );
    }

    /**
     * Every other test passes ParkId => 0, so PermissionKeyFor('set', $park_id)
     * never returns 'park.officer.set' and the C3 kingdom-derivation path never
     * runs. KingdomId is deliberately omitted (0) here to prove it really is
     * derived from the park rather than trusted from the request.
     */
    public function testParkScopedTransitionDerivesKingdomFromThePark(): void
    {
        $parkKingdomId = 999888;
        $parkId        = $this->seedPark($parkKingdomId);
        $positionId    = $this->seedPositionInKingdom('park_crown', 'crown', $parkKingdomId);
        $outgoing      = $this->seedMundaneInKingdom('park_outgoing', $parkKingdomId);
        $incoming      = $this->seedMundaneInKingdom('park_incoming', $parkKingdomId);
        $this->seededMundanes[] = $outgoing;
        $this->seededMundanes[] = $incoming;

        $token = $this->fixture->createAuthorizedOfficer();
        $this->fixture->grantParkAuthority($parkId);

        $this->seatWithOpenTerm($positionId, $outgoing, '2026-03-02', $parkKingdomId, $parkId);

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => 0, 'ParkId' => $parkId,
            'PositionId' => $positionId, 'MundaneId' => $incoming,
        ]);
        self::assertSame(0, (int) $r['Status'], $r['Error'] ?? '');

        $seatStmt = $this->pdo->prepare(
            'SELECT mundane_id, kingdom_id FROM ork_officer WHERE position_id = :pid AND park_id = :parkid'
        );
        $seatStmt->execute([':pid' => $positionId, ':parkid' => $parkId]);
        $row = $seatStmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame($incoming, (int) $row['mundane_id'], 'the seat must move under the park-scoped permission');
        self::assertSame(
            $parkKingdomId,
            (int) $row['kingdom_id'],
            'kingdom_id must be derived from the park, not the (omitted) request value'
        );
    }

    /** seedMundane(), but in an arbitrary kingdom. */
    private function seedMundaneInKingdom(string $suffix, int $kingdomId): int
    {
        $username = self::MARKER . '_' . $suffix . '_' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES (:g, "Test", "", :u, :p, :e, 0, :kid, "", "", "0000-00-00", "", "", "0000-00-00")'
        );
        $stmt->execute([
            ':g' => self::MARKER, ':u' => $username, ':p' => self::MARKER . ' ' . $suffix,
            ':e' => $username . '@example.test', ':kid' => $kingdomId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** $start = null seats an officer with a NULL start_date, for OutgoingStartDate backfill tests. */
    private function seatWithOpenTerm(int $positionId, int $mundaneId, ?string $start, int $kingdomId = self::KINGDOM_ID, int $parkId = 0): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, :parkid, :mid, :role, 0, 0, :pid, NOW())'
        )->execute([
            ':kid' => $kingdomId, ':parkid' => $parkId, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_crown_a', ':pid' => $positionId,
        ]);
        $this->pdo->prepare(
            'INSERT INTO ork_officer_history (kingdom_id, park_id, mundane_id, role,
                                              position_id, display_label, start_date,
                                              end_date, changed_by, created_at)
             VALUES (:kid, :parkid, :mid, :role, :pid, :label, :start, NULL, NULL, NOW())'
        )->execute([
            ':kid' => $kingdomId, ':parkid' => $parkId, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_crown_a', ':pid' => $positionId,
            ':label' => 'Test crown_a', ':start' => $start,
        ]);
    }
}
