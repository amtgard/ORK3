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
            . " ('OfficerPosition::SetOccupant', 'OfficerPosition::VacateOffice', 'OfficerPosition::VacateOfficer',"
            . " 'OfficerPosition::AddHistoryTerm', 'OfficerPosition::EditHistoryTerm', 'OfficerPosition::DeleteHistoryTerm')"
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

    public function testHistoryMethodsRejectAnInvalidToken(): void
    {
        foreach (['AddHistoryTerm', 'EditHistoryTerm', 'DeleteHistoryTerm'] as $method) {
            $r = $this->positions->{$method}([
                'Token' => 'not-a-real-token', 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
                'PositionId' => $this->seededPositions['crown_a'],
                'OfficerHistoryId' => 1, 'MundaneId' => $this->seededMundanes[0],
            ]);
            self::assertSame(5, (int) $r['Status'], $method . ' must reject an invalid token');
        }
    }

    public function testAddHistoryTermCreatesATermUnderTheHistoryPermission(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId = $this->seededMundanes[0];

        $r = $this->positions->AddHistoryTerm([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $mundaneId,
            'StartDate' => '2020-01-01', 'EndDate' => '2020-06-01', 'Note' => 'seeded term',
        ]);
        self::assertSame(0, (int) $r['Status'], $r['Error'] ?? '');

        $stmt = $this->pdo->prepare(
            'SELECT kingdom_id, park_id, mundane_id, position_id, start_date, end_date, notes
             FROM ork_officer_history WHERE officer_history_id = :id'
        );
        $stmt->execute([':id' => $r['Detail']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'the term must actually be written');
        self::assertSame(self::KINGDOM_ID, (int) $row['kingdom_id']);
        self::assertSame(0, (int) $row['park_id']);
        self::assertSame($mundaneId, (int) $row['mundane_id']);
        self::assertSame($positionId, (int) $row['position_id']);
        self::assertSame('2020-01-01', $row['start_date']);
        self::assertSame('2020-06-01', $row['end_date']);

        $auditStmt = $this->pdo->prepare(
            "SELECT entity FROM ork_danger_audit
             WHERE method_call = 'OfficerPosition::AddHistoryTerm' AND entity_id = :kid
             ORDER BY danger_audit_id DESC LIMIT 1"
        );
        $auditStmt->execute([':kid' => self::KINGDOM_ID]);
        self::assertSame('Kingdom', $auditStmt->fetchColumn(), 'entity must be the capitalized DangerAudit vocabulary');
    }

    public function testEditHistoryTermRejectsAFutureEndDate(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        $historyId = $this->seedHistoryRow(self::KINGDOM_ID, 0, $this->seededMundanes[0], $this->seededPositions['crown_a']);

        $r = $this->positions->EditHistoryTerm([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'OfficerHistoryId' => $historyId,
            'EndDate' => date('Y-m-d', strtotime('+30 days')),
        ]);
        self::assertSame(
            4,
            (int) $r['Status'],
            'the rolls must not be able to record a term ending in the future'
        );
    }

    public function testEditHistoryTermRefusesANonexistentRow(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        // officer_history_id 0 never exists.
        $r = $this->positions->EditHistoryTerm([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'OfficerHistoryId' => 0, 'EndDate' => '2026-01-01',
        ]);
        self::assertSame(4, (int) $r['Status']);
    }

    /**
     * THE MOST IMPORTANT TEST IN THIS TASK. A row belonging to a DIFFERENT
     * kingdom must be refused even though the caller supplies their own
     * (legitimate) KingdomId -- the gate must run against the ROW's own
     * scope, never the caller's claim. If this ever regresses to gating on
     * the request's KingdomId, this test starts failing because the actor's
     * authority is scoped only to self::KINGDOM_ID, not the foreign kingdom.
     */
    public function testEditHistoryTermRefusesARowBelongingToADifferentKingdom(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        $foreignKingdomId = self::KINGDOM_ID + 1;
        $foreignHistoryId = $this->seedHistoryRow($foreignKingdomId, 0, $this->seededMundanes[0], 0);

        $r = $this->positions->EditHistoryTerm([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'OfficerHistoryId' => $foreignHistoryId, 'EndDate' => '2020-01-01',
        ]);
        self::assertSame(
            5,
            (int) $r['Status'],
            'naming your own kingdom must not reach a different kingdom\'s row'
        );

        $this->pdo->exec('DELETE FROM ork_officer_history WHERE officer_history_id = ' . $foreignHistoryId);
    }

    public function testDeleteHistoryTermRefusesARowBelongingToADifferentKingdom(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        $foreignKingdomId = self::KINGDOM_ID + 1;
        $foreignHistoryId = $this->seedHistoryRow($foreignKingdomId, 0, $this->seededMundanes[0], 0);

        $r = $this->positions->DeleteHistoryTerm([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'OfficerHistoryId' => $foreignHistoryId,
        ]);
        self::assertSame(5, (int) $r['Status']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ork_officer_history WHERE officer_history_id = :id');
        $stmt->execute([':id' => $foreignHistoryId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'the refused row must not be deleted');

        $this->pdo->exec('DELETE FROM ork_officer_history WHERE officer_history_id = ' . $foreignHistoryId);
    }

    public function testDeleteHistoryTermAuditsTheFullDeletedRowAsPriorState(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        $mundaneId = $this->seededMundanes[0];
        $historyId = $this->seedHistoryRow(self::KINGDOM_ID, 0, $mundaneId, $this->seededPositions['crown_a']);

        $r = $this->positions->DeleteHistoryTerm([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'OfficerHistoryId' => $historyId,
        ]);
        self::assertSame(0, (int) $r['Status'], $r['Error'] ?? '');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ork_officer_history WHERE officer_history_id = :id');
        $stmt->execute([':id' => $historyId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'the row must actually be gone');

        $auditStmt = $this->pdo->prepare(
            "SELECT prior_state FROM ork_danger_audit
             WHERE method_call = 'OfficerPosition::DeleteHistoryTerm' AND entity_id = :kid
             ORDER BY danger_audit_id DESC LIMIT 1"
        );
        $auditStmt->execute([':kid' => self::KINGDOM_ID]);
        $priorState = $auditStmt->fetchColumn();
        self::assertNotFalse($priorState);
        self::assertStringContainsString((string) $historyId, (string) $priorState);
        self::assertStringContainsString((string) $mundaneId, (string) $priorState);
    }

    public function testAddHistoryTermRefusesASecondOpenTerm(): void
    {
        $token      = $this->fixture->createAuthorizedOfficer();
        $positionId = $this->seededPositions['crown_a'];
        $first      = $this->seededMundanes[0];
        $second     = $this->seedMundane('second_open');
        $this->seededMundanes[] = $second;

        $base = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
                 'PositionId' => $positionId];

        $a = $this->positions->AddHistoryTerm($base + ['MundaneId' => $first, 'StartDate' => '2026-01-01']);
        self::assertSame(0, (int)$a['Status'], $a['Error'] ?? '');

        $b = $this->positions->AddHistoryTerm($base + ['MundaneId' => $second, 'StartDate' => '2026-02-01']);
        self::assertSame(
            4,
            (int)$b['Status'],
            'two open terms on one office means it reads as held by two people'
        );
    }

    public function testEditHistoryTermRefusesReopeningIntoASecondOpenTerm(): void
    {
        $token      = $this->fixture->createAuthorizedOfficer();
        $positionId = $this->seededPositions['crown_b'];
        $former     = $this->seedMundane('former_reopen');
        $current    = $this->seedMundane('current_reopen');
        $this->seededMundanes[] = $former;
        $this->seededMundanes[] = $current;
        $base = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
                 'PositionId' => $positionId];

        // A closed term, then an open one on the same office.
        $closed = $this->positions->AddHistoryTerm(
            $base + ['MundaneId' => $former, 'StartDate' => '2025-01-01', 'EndDate' => '2025-12-31']
        );
        self::assertSame(0, (int)$closed['Status'], $closed['Error'] ?? '');
        $this->positions->AddHistoryTerm($base + ['MundaneId' => $current, 'StartDate' => '2026-01-01']);

        $closedId = (int)$this->pdo->query(
            "SELECT officer_history_id FROM ork_officer_history
              WHERE position_id = {$positionId} AND end_date IS NOT NULL
              ORDER BY officer_history_id DESC LIMIT 1"
        )->fetchColumn();

        $r = $this->positions->EditHistoryTerm([
            'Token' => $token, 'OfficerHistoryId' => $closedId, 'EndDate' => '',
        ]);
        self::assertSame(
            4,
            (int)$r['Status'],
            'clearing EndDate must not create a second open term'
        );
    }

    public function testEditHistoryTermStillAllowsClearingWhenNoOtherOpenTermExists(): void
    {
        $token      = $this->fixture->createAuthorizedOfficer();
        $positionId = $this->seededPositions['crown_a'];
        $who        = $this->seedMundane('lone_reopen');
        $this->seededMundanes[] = $who;

        $this->positions->AddHistoryTerm([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $who,
            'StartDate' => '2025-01-01', 'EndDate' => '2025-12-31',
        ]);
        $id = (int)$this->pdo->query(
            "SELECT officer_history_id FROM ork_officer_history
              WHERE position_id = {$positionId} AND mundane_id = {$who} LIMIT 1"
        )->fetchColumn();

        $r = $this->positions->EditHistoryTerm([
            'Token' => $token, 'OfficerHistoryId' => $id, 'EndDate' => '',
        ]);
        self::assertSame(
            0,
            (int)$r['Status'],
            'reopening the ONLY term must still work -- the guard is about a SECOND one'
        );
    }

    /** @return list<array{0:string,1:array<string,mixed>}> */
    public static function positionMethodProvider(): array
    {
        return [
            ['CreatePosition',    ['Title' => 'Test Office', 'Classification' => 'supporting']],
            ['EditPosition',      ['PositionId' => 1, 'Title' => 'Renamed']],
            ['ReorderPositions',  ['ParentPositionId' => 0, 'OrderedPositionIds' => [1, 2]]],
            ['RetirePosition',    ['PositionId' => 1]],
            ['ReinstatePosition', ['PositionId' => 1]],
        ];
    }

    /** @dataProvider positionMethodProvider */
    public function testEveryPositionMethodRejectsAnInvalidToken(string $method, array $payload): void
    {
        $payload['Token'] = 'not-a-real-token';
        $payload['KingdomId'] = self::KINGDOM_ID;
        $payload['ParkId'] = 0;
        $r = $this->positions->{$method}($payload);
        self::assertSame(5, (int) $r['Status'], $method . ' must reject an invalid token');
    }

    public function testPositionManagementUsesThePositionPermission(): void
    {
        self::assertSame('kingdom.officer.position.manage', OfficerPosition::PermissionKeyFor('position', 0));
        // Defined in PermissionRegistry since this branch began, referenced nowhere.
        self::assertSame('park.officer.position.manage', OfficerPosition::PermissionKeyFor('position', 9));
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

    /**
     * Seed one ork_officer_history row directly (bypassing the domain methods
     * under test), so EditHistoryTerm/DeleteHistoryTerm tests have a real row
     * to authorize against. role carries the MARKER prefix so tearDown's
     * blanket cleanup catches it even for a $kingdomId this test never
     * registers elsewhere (the cross-kingdom tests use self::KINGDOM_ID + 1).
     */
    private function seedHistoryRow(int $kingdomId, int $parkId, int $mundaneId, int $positionId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_history
                (kingdom_id, park_id, mundane_id, role, position_id, display_label,
                 start_date, end_date, changed_by, notes, created_at)
             VALUES (:kid, :pid, :mid, :role, :posid, :label, "2019-01-01", NULL, 0, "", NOW())'
        );
        $stmt->execute([
            ':kid' => $kingdomId,
            ':pid' => $parkId,
            ':mid' => $mundaneId,
            ':role' => self::MARKER . '_manual',
            ':posid' => $positionId,
            ':label' => self::MARKER . ' manual term',
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
