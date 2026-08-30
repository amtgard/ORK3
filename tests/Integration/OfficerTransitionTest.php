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
        $this->pdo->exec("DELETE FROM ork_danger_audit WHERE method_call = 'OfficerPosition::TransitionOfficer'");
        $this->pdo->exec("DELETE FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "%'");
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

    public function testRejectsAnEndDateBeforeTheTermStart(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $token      = $this->fixture->createAuthorizedOfficer();
        $this->seatWithOpenTerm($positionId, $this->seededMundanes[0], '2026-03-02');

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $this->seededMundanes[0],
            'OutgoingEndDate' => '2026-01-01',
        ]);
        self::assertSame(4, (int) $r['Status']);
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

    private function seatWithOpenTerm(int $positionId, int $mundaneId, string $start): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, 0, :mid, :role, 0, 0, :pid, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_crown_a', ':pid' => $positionId,
        ]);
        $this->pdo->prepare(
            'INSERT INTO ork_officer_history (kingdom_id, park_id, mundane_id, role,
                                              position_id, display_label, start_date,
                                              end_date, changed_by, created_at)
             VALUES (:kid, 0, :mid, :role, :pid, :label, :start, NULL, NULL, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_crown_a', ':pid' => $positionId,
            ':label' => 'Test crown_a', ':start' => $start,
        ]);
    }
}
