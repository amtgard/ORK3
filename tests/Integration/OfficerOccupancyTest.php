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
            "DELETE FROM ork_danger_audit WHERE method_call IN ('OfficerPosition::SetOccupant',"
            . " 'OfficerPosition::AddHistoryTerm', 'OfficerPosition::EditHistoryTerm', 'OfficerPosition::DeleteHistoryTerm')"
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

    /**
     * A backfilled past term may have a blank end ("end unknown"), so a null
     * end_date no longer means "current". Seating must close only the row
     * marked is_current = 1, never a past unknown-end row.
     */
    public function testSeatingClosesOnlyTheCurrentRow(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $holderA = $this->seededMundanes[0];
        $holderB = $this->seedMundane('holder_b');
        $this->seededMundanes[] = $holderB;
        $pastHolder = $this->seedMundane('past');
        $this->seededMundanes[] = $pastHolder;
        $pastId = $this->seedPastUnknownEndRow($positionId, 'crown_a', $pastHolder);

        $token = $this->fixture->createAuthorizedOfficer();
        $base = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0, 'PositionId' => $positionId];
        $a = $this->positions->SetOccupant($base + ['MundaneId' => $holderA]);
        self::assertSame(0, (int) $a['Status'], 'seating A should succeed: ' . ($a['Error'] ?? ''));
        $b = $this->positions->SetOccupant($base + ['MundaneId' => $holderB]);
        self::assertSame(0, (int) $b['Status'], 'seating B should succeed: ' . ($b['Error'] ?? ''));

        $past = $this->historyRow('officer_history_id = ' . $pastId);
        self::assertNull($past['end_date'], 'the past unknown-end row must not be closed');
        self::assertNull($past['end_season'], 'the past unknown-end row must not gain an end season');
        self::assertSame(0, (int) $past['is_current']);

        $rowA = $this->historyRow('position_id = ' . $positionId . ' AND mundane_id = ' . $holderA);
        self::assertSame(date('Y-m-d'), $rowA['end_date'], "A's term closes today");
        self::assertSame(0, (int) $rowA['is_current'], "A's closed term is no longer current");

        $rowB = $this->historyRow('position_id = ' . $positionId . ' AND mundane_id = ' . $holderB);
        self::assertNull($rowB['end_date']);
        self::assertSame(1, (int) $rowB['is_current'], "B's new term is current");
    }

    public function testOpenTermGuardIgnoresUnknownEnds(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $pastHolder = $this->seedMundane('past');
        $this->seededMundanes[] = $pastHolder;
        $this->seedPastUnknownEndRow($positionId, 'crown_a', $pastHolder);

        $token = $this->fixture->createAuthorizedOfficer();
        $r = $this->positions->SetOccupant([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $this->seededMundanes[0],
        ]);
        self::assertSame(0, (int) $r['Status'], 'seating beside a past unknown-end row should succeed: ' . ($r['Error'] ?? ''));

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = :pid AND is_current = 1'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'exactly one row for the office is current');
    }

    /**
     * The migration's backfill marks a null-end row current only when its
     * holder actually sits in the seat today.
     */
    public function testMigrationMarksOnlySeatedRowsCurrent(): void
    {
        $positionId = $this->seededPositions['crown_b'];
        $role = self::MARKER . '_crown_b';
        $seated = $this->seededMundanes[0];
        $other = $this->seedMundane('other');
        $this->seededMundanes[] = $other;

        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system, authorization_id, position_id, modified)
             VALUES (:kid, 0, :mid, :role, 0, 0, :pos, NOW())'
        )->execute([':kid' => self::KINGDOM_ID, ':mid' => $seated, ':role' => $role, ':pos' => $positionId]);
        $seatedRow = $this->insertHistoryRow($positionId, $role, $seated, '2020-01-01', 0);
        $otherRow = $this->insertHistoryRow($positionId, $role, $other, '2010-01-01', 0);

        $this->pdo->exec($this->migrationBackfillSql());

        self::assertSame(1, (int) $this->historyRow('officer_history_id = ' . $seatedRow)['is_current']);
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $otherRow)['is_current']);
    }

    // ---------------------------------------------------------------
    // One set of write rules for officer history (Task 3)
    // ---------------------------------------------------------------

    private const CURRENT_MANAGED = 'The current term is managed from Manage Officers.';

    /** @return array<string,mixed> */
    private function historyBase(string $positionKey = 'crown_a'): array
    {
        return [
            'Token' => $this->fixture->createAuthorizedOfficer(),
            'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $this->seededPositions[$positionKey],
        ];
    }

    /** Seat the seeded holder and return the office's current history row. */
    private function seatAndGetCurrentRow(string $positionKey = 'crown_a'): array
    {
        $r = $this->positions->SetOccupant($this->historyBase($positionKey) + ['MundaneId' => $this->seededMundanes[0]]);
        self::assertSame(0, (int) $r['Status'], 'seating should succeed: ' . ($r['Error'] ?? ''));
        return $this->historyRow('position_id = ' . $this->seededPositions[$positionKey] . ' AND is_current = 1');
    }

    public function testAddAcceptsAPlainTextHolderAndSeasonPeriod(): void
    {
        $r = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Sir Bram the Elder', 'Period' => 'Spring–Fall 95',
        ]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));

        $row = $this->historyRow('officer_history_id = ' . (int) $r['Detail']);
        self::assertSame(0, (int) $row['mundane_id']);
        self::assertSame('Sir Bram the Elder', $row['holder_name']);
        self::assertSame('spring', $row['start_season']);
        self::assertSame(1995, (int) $row['start_year']);
        self::assertSame('fall', $row['end_season']);
        self::assertSame(1995, (int) $row['end_year']);
        self::assertSame(0, (int) $row['is_current']);
        self::assertNull($row['start_date'], 'a season-only period stores no exact date');
        self::assertNull($row['end_date']);
    }

    public function testAddWithBlankEndIsUnknownNotCurrent(): void
    {
        $current = $this->seatAndGetCurrentRow();
        $add = $this->historyBase() + ['HolderName' => 'Dame Unknown End', 'Period' => 'Spring 95 –'];

        $a = $this->positions->AddHistoryTerm($add);
        self::assertSame(0, (int) $a['Status'], (string) ($a['Error'] ?? ''));
        $rowA = $this->historyRow('officer_history_id = ' . (int) $a['Detail']);
        self::assertSame(0, (int) $rowA['is_current'], 'a blank end means end unknown, not current');
        self::assertSame('spring', $rowA['start_season']);
        self::assertNull($rowA['end_season']);
        self::assertNull($rowA['end_year']);

        $b = $this->positions->AddHistoryTerm($add);
        self::assertSame(0, (int) $b['Status'], 'a second blank-end Add must succeed: ' . ($b['Error'] ?? ''));

        $after = $this->historyRow('position_id = ' . $this->seededPositions['crown_a'] . ' AND is_current = 1');
        self::assertSame($current['officer_history_id'], $after['officer_history_id'], 'the current row is unchanged');
        self::assertSame($current['mundane_id'], $after['mundane_id']);
        $count = $this->pdo->query(
            'SELECT COUNT(*) FROM ork_officer_history WHERE is_current = 1 AND position_id = ' . $this->seededPositions['crown_a']
        )->fetchColumn();
        self::assertSame(1, (int) $count, 'exactly one row is current');
    }

    public function testAddRejectsAFutureSeasonalEnd(): void
    {
        $r = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Lord Tomorrow', 'Period' => 'Spring 26 – Fall 30',
        ]);
        // Two-digit years pivot at next year, so "30" reads as 1930 and the
        // parser refuses this as end-before-start; either way it is refused.
        self::assertNotSame(0, (int) $r['Status']);

        $fourDigit = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Lord Tomorrow', 'Period' => 'Spring 2026 – Fall 2030',
        ]);
        self::assertSame('A term cannot end in the future.', $fourDigit['Error']);

        $nextSeason = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Lord Tomorrow', 'Period' => 'Spring 2026 – Fall 2027',
        ]);
        self::assertSame('A term cannot end in the future.', $nextSeason['Error'], 'a 2-digit-safe future year');
    }

    public function testAddRejectsAnEndBeforeTheStart(): void
    {
        $r = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Lord Backwards', 'Period' => 'Spring 95', 'EndDate' => '1990-06-01',
        ]);
        self::assertNotSame(0, (int) $r['Status']);
    }

    public function testAddRequiresAHolder(): void
    {
        $r = $this->positions->AddHistoryTerm($this->historyBase() + ['Period' => 'Spring–Fall 95']);
        self::assertNotSame(0, (int) $r['Status']);

        $blank = $this->positions->AddHistoryTerm($this->historyBase() + [
            'MundaneId' => 0, 'HolderName' => '   ', 'Period' => 'Spring–Fall 95',
        ]);
        self::assertNotSame(0, (int) $blank['Status'], 'a whitespace-only name is not a holder');

        $long = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => str_repeat('a', 121), 'Period' => 'Spring–Fall 95',
        ]);
        self::assertNotSame(0, (int) $long['Status'], 'a holder name is at most 120 characters');
    }

    public function testAddRequiresAStart(): void
    {
        $r = $this->positions->AddHistoryTerm($this->historyBase() + ['HolderName' => 'Sir No Start']);
        self::assertNotSame(0, (int) $r['Status']);
    }

    public function testAddWithAMemberStoresNoHolderName(): void
    {
        $r = $this->positions->AddHistoryTerm($this->historyBase() + [
            'MundaneId' => $this->seededMundanes[0], 'HolderName' => 'ignored', 'Period' => 'Fall 99',
            'StartDate' => '1999-09-15',
        ]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . (int) $r['Detail']);
        self::assertSame($this->seededMundanes[0], (int) $row['mundane_id']);
        self::assertSame('', $row['holder_name']);
        self::assertSame('fall', $row['start_season']);
        self::assertSame('1999-09-15', $row['start_date'], 'an exact date given alongside a period is stored too');
    }

    public function testEditCannotChangeTheCurrentHolderOrEnd(): void
    {
        $current = $this->seatAndGetCurrentRow();
        $id = (int) $current['officer_history_id'];
        $token = $this->fixture->createAuthorizedOfficer();
        $other = $this->seedMundane('edit_other');
        $this->seededMundanes[] = $other;

        foreach ([
            'EndDate' => ['EndDate' => '2026-01-01'],
            'MundaneId' => ['MundaneId' => $other],
            'HolderName' => ['MundaneId' => 0, 'HolderName' => 'Someone Else'],
            'Period end' => ['Period' => 'Spring 20 – Fall 21'],
        ] as $label => $fields) {
            $r = $this->positions->EditHistoryTerm(['Token' => $token, 'OfficerHistoryId' => $id] + $fields);
            self::assertNotSame(0, (int) $r['Status'], $label . ' must be refused on the current row');
            self::assertSame(self::CURRENT_MANAGED, $r['Error'], $label);
        }

        $ok = $this->positions->EditHistoryTerm([
            'Token' => $token, 'OfficerHistoryId' => $id, 'Period' => 'Spring 24', 'Note' => 'started earlier',
        ]);
        self::assertSame(0, (int) $ok['Status'], 'start and note may change: ' . ($ok['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame('spring', $row['start_season']);
        self::assertSame(2024, (int) $row['start_year']);
        self::assertNull($row['end_season']);
        self::assertNull($row['end_date']);
        self::assertSame(1, (int) $row['is_current'], 'the row stays current');
        self::assertSame($current['mundane_id'], $row['mundane_id']);
        self::assertSame('started earlier', $row['notes']);

        $date = $this->positions->EditHistoryTerm([
            'Token' => $token, 'OfficerHistoryId' => $id, 'StartDate' => '2024-04-02', 'EndDate' => '',
        ]);
        self::assertSame(0, (int) $date['Status'], 'an unchanged blank end is not an end change: ' . ($date['Error'] ?? ''));
        self::assertSame('2024-04-02', $this->historyRow('officer_history_id = ' . $id)['start_date']);
    }

    public function testDeleteRefusesTheCurrentRow(): void
    {
        $current = $this->seatAndGetCurrentRow();
        $r = $this->positions->DeleteHistoryTerm([
            'Token' => $this->fixture->createAuthorizedOfficer(),
            'OfficerHistoryId' => (int) $current['officer_history_id'],
        ]);
        self::assertNotSame(0, (int) $r['Status']);
        self::assertSame(self::CURRENT_MANAGED, $r['Error']);
        $this->historyRow('officer_history_id = ' . (int) $current['officer_history_id']);
    }

    public function testEditLinksAPlainTextHolderAndAudits(): void
    {
        $add = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Sir Bram the Elder', 'Period' => 'Spring–Fall 95',
        ]);
        self::assertSame(0, (int) $add['Status'], (string) ($add['Error'] ?? ''));
        $id = (int) $add['Detail'];
        $member = $this->seededMundanes[0];

        $r = $this->positions->EditHistoryTerm([
            'Token' => $this->fixture->createAuthorizedOfficer(),
            'OfficerHistoryId' => $id, 'MundaneId' => $member,
        ]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));

        $row = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame($member, (int) $row['mundane_id']);
        self::assertSame('', $row['holder_name'], 'a linked holder stores no plain-text name');
        self::assertSame('spring', $row['start_season'], 'an absent Period leaves the span alone');
        self::assertSame('fall', $row['end_season']);

        $audit = $this->pdo->query(
            "SELECT prior_state, post_state FROM ork_danger_audit
              WHERE method_call = 'OfficerPosition::EditHistoryTerm' AND entity_id = " . self::KINGDOM_ID . '
              ORDER BY danger_audit_id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($audit, 'the edit must be audited');
        $prior = json_decode((string) $audit['prior_state'], true);
        $post = json_decode((string) $audit['post_state'], true);
        self::assertSame(0, (int) $prior['MundaneId']);
        self::assertSame('Sir Bram the Elder', $prior['HolderName']);
        self::assertSame($member, (int) $post['MundaneId']);
        self::assertSame('', $post['HolderName']);
    }

    public function testEditOfAPastRowMayChangeItsPeriodAndKeepsItPast(): void
    {
        $add = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Dame Edit', 'Period' => 'Spring 95', 'StartDate' => '1995-04-01',
        ]);
        self::assertSame(0, (int) $add['Status'], (string) ($add['Error'] ?? ''));
        $id = (int) $add['Detail'];

        $r = $this->positions->EditHistoryTerm([
            'Token' => $this->fixture->createAuthorizedOfficer(),
            'OfficerHistoryId' => $id, 'Period' => 'Summer 96 –',
        ]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame('summer', $row['start_season']);
        self::assertSame(1996, (int) $row['start_year']);
        self::assertNull($row['end_season']);
        self::assertNull($row['start_date'], 'a new period replaces an exact date that was not resent');
        self::assertSame(0, (int) $row['is_current']);

        $future = $this->positions->EditHistoryTerm([
            'Token' => $this->fixture->createAuthorizedOfficer(),
            'OfficerHistoryId' => $id, 'Period' => 'Spring 2026 – Fall 2030',
        ]);
        self::assertSame('A term cannot end in the future.', $future['Error']);
    }

    public function testKingdomAndParkHistoryEntryPointsUseTheSameRules(): void
    {
        $k = Ork3::$Lib->kingdom->AddOfficerHistory($this->historyBase() + [
            'HolderName' => 'Kingdom Path', 'Period' => 'Spring–Fall 95',
        ]);
        self::assertSame(0, (int) $k['Status'], (string) ($k['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . (int) $k['Detail']);
        self::assertSame('Kingdom Path', $row['holder_name']);
        self::assertSame(0, (int) $row['park_id']);

        $future = Ork3::$Lib->park->AddOfficerHistory([
            'Token' => $this->fixture->createAuthorizedOfficer(), 'ParkId' => self::PARK_A,
            'PositionId' => $this->seededPositions['support_a'],
            'HolderName' => 'Park Path', 'Period' => 'Spring 2026 – Fall 2030',
        ]);
        self::assertSame('A term cannot end in the future.', $future['Error']);

        $p = Ork3::$Lib->park->AddOfficerHistory([
            'Token' => $this->fixture->createAuthorizedOfficer(), 'ParkId' => self::PARK_A,
            'PositionId' => $this->seededPositions['support_a'],
            'HolderName' => 'Park Path', 'Period' => 'Spring–Fall 95',
        ]);
        self::assertSame(0, (int) $p['Status'], (string) ($p['Error'] ?? ''));
        $prow = $this->historyRow('officer_history_id = ' . (int) $p['Detail']);
        self::assertSame(self::PARK_A, (int) $prow['park_id']);
        self::assertSame(self::KINGDOM_ID, (int) $prow['kingdom_id'], 'kingdom derived from the park');

        $e = Ork3::$Lib->park->EditOfficerHistory([
            'Token' => $this->fixture->createAuthorizedOfficer(), 'ParkId' => self::PARK_A,
            'OfficerHistoryId' => (int) $p['Detail'], 'Note' => 'edited',
        ]);
        self::assertSame(0, (int) $e['Status'], (string) ($e['Error'] ?? ''));
        $d = Ork3::$Lib->kingdom->DeleteOfficerHistory([
            'Token' => $this->fixture->createAuthorizedOfficer(), 'KingdomId' => self::KINGDOM_ID,
            'OfficerHistoryId' => (int) $k['Detail'],
        ]);
        self::assertSame(0, (int) $d['Status'], (string) ($d['Error'] ?? ''));
    }

    private function seedPastUnknownEndRow(int $positionId, string $suffix, int $mundaneId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_history
                (kingdom_id, park_id, mundane_id, role, position_id, display_label, start_date, end_date,
                 start_season, start_year, end_season, end_year, is_current, changed_by, created_at)
             VALUES (:kid, 0, :mid, :role, :pos, :label, NULL, NULL, "spring", 1995, NULL, NULL, 0, 0, NOW())'
        );
        $stmt->execute([
            ':kid' => self::KINGDOM_ID,
            ':mid' => $mundaneId,
            ':role' => self::MARKER . '_' . $suffix,
            ':pos' => $positionId,
            ':label' => 'Test ' . $suffix,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertHistoryRow(int $positionId, string $role, int $mundaneId, string $start, int $isCurrent): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_history
                (kingdom_id, park_id, mundane_id, role, position_id, display_label, start_date, end_date,
                 is_current, changed_by, created_at)
             VALUES (:kid, 0, :mid, :role, :pos, :role, :start, NULL, :cur, 0, NOW())'
        );
        $stmt->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $mundaneId, ':role' => $role,
            ':pos' => $positionId, ':start' => $start, ':cur' => $isCurrent,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function historyRow(string $where): array
    {
        $row = $this->pdo->query(
            "SELECT * FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%' AND " . $where
            . ' ORDER BY officer_history_id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'expected a history row for ' . $where);
        return $row;
    }

    /** The statements after the migration's `-- is_current backfill` marker. */
    private function migrationBackfillSql(): string
    {
        $path = dirname(__DIR__, 2) . '/db-migrations/2026-11-04-officer-history-rolls.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);
        $pos = strpos($sql, '-- is_current backfill');
        self::assertNotFalse($pos, 'the migration must mark its is_current backfill section');
        return substr($sql, $pos);
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
