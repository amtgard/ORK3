<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficerOccupancyTest extends TestCase
{
    private const MARKER = 'zzoccupancy';
    private const KINGDOM_ID = 100011;
    private const PARK_A = 100012;
    private const PARK_B = 100013;
    private const RETIRED_KINGDOM = 100014;
    private const OTHER_KINGDOM = 100015;

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
            . " 'OfficerPosition::AddHistoryTerm', 'OfficerPosition::EditHistoryTerm', 'OfficerPosition::DeleteHistoryTerm',"
            . " 'Park::TransferPark', 'Park::RetirePark', 'Park::RestorePark', 'Park::MergeParks')"
            . ' AND entity_id IN (' . self::KINGDOM_ID . ', ' . self::PARK_A . ', ' . self::PARK_B . ')'
        );
        $this->pdo->exec("DELETE FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "%'");
        $this->pdo->exec('DELETE FROM ork_park WHERE park_id IN (' . self::PARK_A . ', ' . self::PARK_B . ')');
        $this->pdo->exec('DELETE FROM ork_kingdom WHERE kingdom_id = ' . self::RETIRED_KINGDOM);
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

        $this->runMigrationBackfill();

        self::assertSame(1, (int) $this->historyRow('officer_history_id = ' . $seatedRow)['is_current']);
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $otherRow)['is_current']);
    }

    /**
     * A supporting office may have several holders at once, and current is per
     * seat (office + holder): both stay current, including one whose mark an
     * earlier office-only dedupe had cleared, and vacating one closes only
     * that holder's row.
     */
    public function testMigrationKeepsEveryHolderOfASupportingOfficeCurrent(): void
    {
        $positionId = $this->seededPositions['support_a'];
        $role = self::MARKER . '_support_a';
        $first = $this->seededMundanes[0];
        $second = $this->seedMundane('second');
        $this->seededMundanes[] = $second;
        $this->seatRaw($positionId, $role, $first);
        $this->seatRaw($positionId, $role, $second);
        $firstRow = $this->insertHistoryRow($positionId, $role, $first, '2021-01-01', 1);
        // The old dedupe left only the highest id marked: repair it.
        $secondRow = $this->insertHistoryRow($positionId, $role, $second, '2022-01-01', 0);
        $this->pdo->exec('UPDATE ork_officer_history SET is_current = 0 WHERE officer_history_id = ' . $firstRow);
        $this->pdo->exec('UPDATE ork_officer_history SET is_current = 1 WHERE officer_history_id = ' . $secondRow);

        $this->runMigrationBackfill();

        self::assertSame(1, (int) $this->historyRow('officer_history_id = ' . $firstRow)['is_current']);
        self::assertSame(1, (int) $this->historyRow('officer_history_id = ' . $secondRow)['is_current']);
        $current = array_filter(
            $this->positions->rolls_for_org(self::KINGDOM_ID, 0)['Offices'],
            fn ($o) => $o['PositionId'] === $positionId
        );
        $terms = array_values($current)[0]['Terms'];
        self::assertEqualsCanonicalizing([$firstRow, $secondRow], array_column(array_filter($terms, fn ($t) => $t['IsCurrent']), 'OfficerHistoryId'));

        $v = $this->positions->VacateOfficer([
            'Token' => $this->fixture->createAuthorizedOfficer(), 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $first,
        ]);
        self::assertSame(0, (int) $v['Status'], (string) ($v['Error'] ?? ''));
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $firstRow)['is_current']);
        self::assertSame(1, (int) $this->historyRow('officer_history_id = ' . $secondRow)['is_current'], 'the other holder stays current');
    }

    /** Two marked rows for one seat: the latest start keeps the mark, not the highest id. */
    public function testMigrationKeepsTheLatestStartForASeat(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $role = self::MARKER . '_crown_a';
        $holder = $this->seededMundanes[0];
        $this->seatRaw($positionId, $role, $holder);
        $later = $this->insertHistoryRow($positionId, $role, $holder, '2022-03-01', 1);
        $earlier = $this->insertHistoryRow($positionId, $role, $holder, '2015-03-01', 1);

        $this->runMigrationBackfill();

        self::assertSame(1, (int) $this->historyRow('officer_history_id = ' . $later)['is_current']);
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $earlier)['is_current']);
    }

    /**
     * Re-running touches legacy rows only, and never marks a second row for a
     * seat that already has a current one.
     */
    public function testMigrationRerunLeavesLedgerRowsAlone(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $role = self::MARKER . '_crown_a';
        $holder = $this->seededMundanes[0];
        $this->seatRaw($positionId, $role, $holder);
        $current = $this->insertHistoryRow($positionId, $role, $holder, '2010-01-01', 1);
        $legacyOpen = $this->insertHistoryRow($positionId, $role, $holder, '2024-01-01', 0);
        $ledger = $this->insertHistoryRow($positionId, $role, $holder, '2001-01-01', 0);
        $this->pdo->exec("UPDATE ork_officer_history SET start_season = 'spring', start_year = 2001 WHERE officer_history_id = " . $ledger);

        $this->runMigrationBackfill();

        self::assertSame(1, (int) $this->historyRow('officer_history_id = ' . $current)['is_current']);
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $legacyOpen)['is_current'], 'the seat already had a current row');
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $ledger)['is_current'], 'a ledger row is never re-marked');

        $this->pdo->exec('UPDATE ork_officer_history SET is_current = 0 WHERE officer_history_id = ' . $current);
        $this->pdo->exec('DELETE FROM ork_officer_history WHERE officer_history_id = ' . $legacyOpen);
        $this->runMigrationBackfill();
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $ledger)['is_current'], 'still not a legacy row');
    }

    /**
     * A dated ledger term that was typed as plain text and then linked keeps its
     * recorded name, so it never reads as a legacy open row: the migration does
     * not make a 1995 term the holder's current one, and opens a fresh current
     * row for the seat instead.
     */
    public function testMigrationLeavesALinkedLedgerRowWithARecordedNameAlone(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $role = self::MARKER . '_crown_a';
        $holder = $this->seededMundanes[0];
        $this->seatRaw($positionId, $role, $holder);
        $add = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Squire Dated', 'StartDate' => '1995-04-01',
        ]);
        self::assertSame(0, (int) $add['Status'], (string) ($add['Error'] ?? ''));
        $id = (int) $add['Detail'];
        $link = $this->positions->EditHistoryTerm([
            'Token' => $this->fixture->createAuthorizedOfficer(), 'OfficerHistoryId' => $id, 'MundaneId' => $holder,
        ]);
        self::assertSame(0, (int) $link['Status'], (string) ($link['Error'] ?? ''));
        $this->pdo->exec('UPDATE ork_officer_history SET is_current = 0 WHERE officer_history_id <> ' . $id
            . ' AND position_id = ' . $positionId);

        $this->runMigrationBackfill();

        $row = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame('Squire Dated', $row['holder_name']);
        self::assertSame(0, (int) $row['is_current'], 'a linked ledger term is not a legacy open row');
        $current = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = ' . $positionId . ' AND mundane_id = ' . $holder
            . ' AND is_current = 1 AND officer_history_id <> ' . $id
        )->fetchColumn();
        self::assertSame(1, $current, 'the seat gets its own current row');
    }

    /**
     * A seated officer with no current history row gets one, written as
     * record_officer_history writes it, and only once.
     */
    public function testMigrationOpensACurrentRowForASeatWithNone(): void
    {
        $positionId = $this->seededPositions['support_a'];
        $role = self::MARKER . '_support_a';
        $holder = $this->seededMundanes[0];
        $this->seatRaw($positionId, $role, $holder);

        $this->runMigrationBackfill();
        $this->runMigrationBackfill();

        $rows = $this->pdo->query(
            'SELECT * FROM ork_officer_history WHERE position_id = ' . $positionId . ' AND mundane_id = ' . $holder
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'idempotent: one row however often it runs');
        $row = $rows[0];
        self::assertSame(1, (int) $row['is_current']);
        self::assertSame(self::KINGDOM_ID, (int) $row['kingdom_id']);
        self::assertSame(0, (int) $row['park_id']);
        self::assertSame($role, $row['role']);
        self::assertSame('Test support_a', $row['display_label']);
        self::assertSame('', $row['holder_name']);
        self::assertNull($row['start_date']);
        self::assertNull($row['end_date']);
        self::assertNull($row['start_season']);
        self::assertNull($row['end_season']);
    }

    // ---------------------------------------------------------------
    // Current marks: retired orgs, stale marks, park transfers
    // ---------------------------------------------------------------

    /**
     * A retired park or kingdom has no current officers on its rolls: the
     * migration neither marks nor opens a current row there, and clears one
     * already marked, leaving its end unknown. An active park still gets one.
     */
    public function testMigrationGivesRetiredOrgsNoCurrentRows(): void
    {
        $crownA = $this->seededPositions['crown_a'];
        $crownB = $this->seededPositions['crown_b'];
        $support = $this->seededPositions['support_a'];
        $holder = $this->seededMundanes[0];
        $this->pdo->exec("UPDATE ork_park SET active = 'Retired' WHERE park_id = " . self::PARK_A);
        $this->seedKingdom(self::RETIRED_KINGDOM, 'Retired');

        // Retired park: a seat with no row, a seat already marked, a legacy open row.
        $this->seatRawAt(self::KINGDOM_ID, self::PARK_A, $crownA, self::MARKER . '_crown_a', $holder);
        $this->seatRawAt(self::KINGDOM_ID, self::PARK_A, $crownB, self::MARKER . '_crown_b', $holder);
        $marked = $this->insertHistoryRowAt(self::KINGDOM_ID, self::PARK_A, $crownB, self::MARKER . '_crown_b', $holder, '2019-01-01', 1);
        $this->seatRawAt(self::KINGDOM_ID, self::PARK_A, $support, self::MARKER . '_support_a', $holder);
        $legacy = $this->insertHistoryRowAt(self::KINGDOM_ID, self::PARK_A, $support, self::MARKER . '_support_a', $holder, '2018-01-01', 0);
        // Retired kingdom: one seat marked, one with no row.
        $this->seatRawAt(self::RETIRED_KINGDOM, 0, $crownA, self::MARKER . '_crown_a', $holder);
        $kMarked = $this->insertHistoryRowAt(self::RETIRED_KINGDOM, 0, $crownA, self::MARKER . '_crown_a', $holder, '2017-01-01', 1);
        $this->seatRawAt(self::RETIRED_KINGDOM, 0, $crownB, self::MARKER . '_crown_b', $holder);
        // Active park control.
        $this->seatRawAt(self::KINGDOM_ID, self::PARK_B, $crownA, self::MARKER . '_crown_a', $holder);

        $this->runMigrationBackfill();
        $this->runMigrationBackfill();

        $current = fn (int $kid, int $pid) => (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'"
            . ' AND is_current = 1 AND kingdom_id = ' . $kid . ' AND park_id = ' . $pid
        )->fetchColumn();
        self::assertSame(0, $current(self::KINGDOM_ID, self::PARK_A), 'a retired park has no current rows');
        self::assertSame(0, $current(self::RETIRED_KINGDOM, 0), 'a retired kingdom has no current rows');
        self::assertSame(1, $current(self::KINGDOM_ID, self::PARK_B), 'an active park seat still gets one');

        foreach ([$marked, $kMarked] as $id) {
            $row = $this->historyRow('officer_history_id = ' . $id);
            self::assertNull($row['end_date'], 'the cleared mark leaves the end unknown');
            self::assertNull($row['end_season']);
        }
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $legacy)['is_current']);
        $rows = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'"
            . ' AND (park_id = ' . self::PARK_A . ' OR kingdom_id = ' . self::RETIRED_KINGDOM . ')'
        )->fetchColumn();
        self::assertSame(3, $rows, 'no row is opened for a retired org');

        // The rolls show no synthetic "present" holder for the retired park either.
        foreach ($this->positions->rolls_for_org(0, self::PARK_A)['Offices'] as $office) {
            foreach ($office['Terms'] as $t) {
                self::assertFalse($t['IsCurrent'], 'no current term on a retired park\'s rolls');
            }
        }
    }

    /** Retiring a park clears its current marks, end unknown, as the migration does. */
    public function testRetiringAParkClearsItsCurrentMarks(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $token = $this->fixture->createAuthorizedOfficer();
        $r = $this->positions->SetOccupant([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => self::PARK_A,
            'PositionId' => $positionId, 'MundaneId' => $this->seededMundanes[0],
        ]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        $row = $this->historyRow('park_id = ' . self::PARK_A . ' AND is_current = 1');

        $retire = Ork3::$Lib->park->RetirePark(['Token' => $token, 'ParkId' => self::PARK_A]);
        self::assertSame(0, (int) $retire['Status'], (string) ($retire['Error'] ?? ''));

        $after = $this->historyRow('officer_history_id = ' . $row['officer_history_id']);
        self::assertSame(0, (int) $after['is_current']);
        self::assertNull($after['end_date'], 'retiring does not invent an end date');
    }

    /** Retiring a kingdom clears its current marks, its parks' included, ends unknown. */
    public function testRetiringAKingdomClearsItsCurrentMarks(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $role = self::MARKER . '_crown_a';
        $holder = $this->seededMundanes[0];
        $this->seedKingdom(self::RETIRED_KINGDOM, 'Active');
        $kRow = $this->insertHistoryRowAt(self::RETIRED_KINGDOM, 0, $positionId, $role, $holder, '2020-01-01', 1);
        $pRow = $this->insertHistoryRowAt(self::RETIRED_KINGDOM, self::PARK_B, $positionId, $role, $holder, '2020-01-01', 1);
        $other = $this->insertHistoryRowAt(self::KINGDOM_ID, 0, $positionId, $role, $holder, '2020-01-01', 1);
        $token = $this->fixture->createAuthorizedOfficer();
        $this->grantGlobalAdmin($token);

        $r = Ork3::$Lib->kingdom->RetireKingdom(['Token' => $token, 'KingdomId' => self::RETIRED_KINGDOM]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));

        foreach ([$kRow, $pRow] as $id) {
            $row = $this->historyRow('officer_history_id = ' . $id);
            self::assertSame(0, (int) $row['is_current']);
            self::assertNull($row['end_date']);
        }
        self::assertSame(1, (int) $this->historyRow('officer_history_id = ' . $other)['is_current'], 'another kingdom is untouched');
    }

    /**
     * Re-running clears marks that can no longer be current: a row with an end,
     * and a row whose holder no longer sits in that seat. Their ends are kept
     * as they are; a seated holder's mark is untouched.
     */
    public function testMigrationClearsStaleCurrentMarks(): void
    {
        $crownA = $this->seededPositions['crown_a'];
        $crownB = $this->seededPositions['crown_b'];
        $support = $this->seededPositions['support_a'];
        $holder = $this->seededMundanes[0];
        $gone = $this->seedMundane('gone');
        $this->seededMundanes[] = $gone;

        $this->seatRaw($crownA, self::MARKER . '_crown_a', $holder);
        $ended = $this->insertHistoryRow($crownA, self::MARKER . '_crown_a', $holder, '2015-01-01', 1);
        $this->pdo->exec("UPDATE ork_officer_history SET end_date = '2016-05-01', end_ordinal = 8065 WHERE officer_history_id = " . $ended);
        $unseated = $this->insertHistoryRow($crownB, self::MARKER . '_crown_b', $gone, '2014-01-01', 1);
        $this->seatRaw($support, self::MARKER . '_support_a', $holder);
        $seated = $this->insertHistoryRow($support, self::MARKER . '_support_a', $holder, '2020-01-01', 1);

        $this->runMigrationBackfill();

        $e = $this->historyRow('officer_history_id = ' . $ended);
        self::assertSame(0, (int) $e['is_current'], 'a row with an end is not current');
        self::assertSame('2016-05-01', $e['end_date'], 'its real end is kept');
        $u = $this->historyRow('officer_history_id = ' . $unseated);
        self::assertSame(0, (int) $u['is_current'], 'a holder no longer in the seat is not current');
        self::assertNull($u['end_date'], 'its end stays unknown');
        self::assertSame(1, (int) $this->historyRow('officer_history_id = ' . $seated)['is_current']);
    }

    /**
     * A crown seat change closes the outgoing holder's row (today) and unmarks
     * any other current row on that office -- a stale mark, e.g. left by a
     * player merge -- leaving its end unknown. A vacant seat (no outgoing
     * holder) unmarks stale rows too.
     */
    public function testACrownSeatChangeUnmarksAStaleHolderWithoutStampingIt(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $role = self::MARKER . '_crown_a';
        $holderA = $this->seededMundanes[0];
        $holderB = $this->seedMundane('holder_b');
        $stale = $this->seedMundane('stale');
        $this->seededMundanes[] = $holderB;
        $this->seededMundanes[] = $stale;

        $base = $this->historyBase();
        $a = $this->positions->SetOccupant($base + ['MundaneId' => $holderA]);
        self::assertSame(0, (int) $a['Status'], (string) ($a['Error'] ?? ''));
        $co = $this->insertHistoryRow($positionId, $role, $stale, '2012-01-01', 1);

        $b = $this->positions->SetOccupant($base + ['MundaneId' => $holderB]);
        self::assertSame(0, (int) $b['Status'], (string) ($b['Error'] ?? ''));

        $rowA = $this->historyRow('position_id = ' . $positionId . ' AND mundane_id = ' . $holderA);
        self::assertSame(date('Y-m-d'), $rowA['end_date']);
        self::assertSame(0, (int) $rowA['is_current']);
        $rowCo = $this->historyRow('officer_history_id = ' . $co);
        self::assertSame(0, (int) $rowCo['is_current'], 'the stale mark is cleared');
        self::assertNull($rowCo['end_date'], 'the stale row is not stamped with today');
        $current = $this->pdo->query(
            'SELECT mundane_id FROM ork_officer_history WHERE is_current = 1 AND position_id = ' . $positionId
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([$holderB], array_map('intval', $current), "only the incoming holder's row is current");

        // A vacant seat: no outgoing holder, a stale mark still goes.
        $crownB = $this->seededPositions['crown_b'];
        $this->seatRaw($crownB, self::MARKER . '_crown_b', 0);
        $staleB = $this->insertHistoryRow($crownB, self::MARKER . '_crown_b', $stale, '2013-01-01', 1);
        $c = $this->positions->SetOccupant($this->historyBase('crown_b') + ['MundaneId' => $holderB]);
        self::assertSame(0, (int) $c['Status'], (string) ($c['Error'] ?? ''));
        $rowStaleB = $this->historyRow('officer_history_id = ' . $staleB);
        self::assertSame(0, (int) $rowStaleB['is_current']);
        self::assertNull($rowStaleB['end_date']);
        $currentB = $this->pdo->query(
            'SELECT mundane_id FROM ork_officer_history WHERE is_current = 1 AND position_id = ' . $crownB
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([$holderB], array_map('intval', $currentB));
    }

    /** Merging a park away closes its officers' current terms today, as their seats go. */
    public function testMergingAParkClosesItsCurrentRows(): void
    {
        $holder = $this->seededMundanes[0];
        $token = $this->fixture->createAuthorizedOfficer();
        $this->grantGlobalAdmin($token);
        foreach (['crown_a', 'crown_b'] as $key) {
            $r = $this->positions->SetOccupant([
                'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => self::PARK_A,
                'PositionId' => $this->seededPositions[$key], 'MundaneId' => $holder,
            ]);
            self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        }

        $m = Ork3::$Lib->park->MergeParks(['Token' => $token, 'FromParkId' => self::PARK_A, 'ToParkId' => self::PARK_B]);
        self::assertSame(0, (int) $m['Status'], (string) ($m['Error'] ?? ''));

        $rows = $this->pdo->query(
            'SELECT is_current, end_date FROM ork_officer_history WHERE park_id = ' . self::PARK_A
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame(0, (int) $row['is_current'], 'the merged park has no current rows');
            self::assertSame(date('Y-m-d'), $row['end_date'], 'each closes today');
        }
    }

    /**
     * Restoring a retired park or kingdom re-marks the seated holders' pre-retire
     * rows: one current row per seated holder, and no synthetic twin on the rolls.
     */
    public function testRestoringAnOrgReMarksItsSeatedHolders(): void
    {
        $holder = $this->seededMundanes[0];
        $token = $this->fixture->createAuthorizedOfficer();
        $this->grantGlobalAdmin($token);
        $positionId = $this->seededPositions['crown_a'];
        $r = $this->positions->SetOccupant([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => self::PARK_A,
            'PositionId' => $positionId, 'MundaneId' => $holder,
        ]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        $before = $this->historyRow('park_id = ' . self::PARK_A . ' AND is_current = 1');
        // An older open row for the same seat and holder: the newest wins.
        $older = $this->insertHistoryRowAt(self::KINGDOM_ID, self::PARK_A, $positionId, self::MARKER . '_crown_a', $holder, '2001-01-01', 0);

        self::assertSame(0, (int) Ork3::$Lib->park->RetirePark(['Token' => $token, 'ParkId' => self::PARK_A])['Status']);
        self::assertSame(0, (int) Ork3::$Lib->park->RestorePark(['Token' => $token, 'ParkId' => self::PARK_A])['Status']);

        $current = $this->pdo->query(
            'SELECT officer_history_id FROM ork_officer_history WHERE is_current = 1 AND park_id = ' . self::PARK_A
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([(int) $before['officer_history_id']], array_map('intval', $current), 'the pre-retire row is current again');
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $older)['is_current']);
        $ids = [];
        foreach ($this->positions->rolls_for_org(0, self::PARK_A)['Offices'] as $office) {
            foreach ($office['Terms'] as $t) {
                if ($t['IsCurrent']) {
                    $ids[] = $t['OfficerHistoryId'];
                }
            }
        }
        self::assertSame([(int) $before['officer_history_id']], $ids, 'no synthetic row for the holder');

        // A kingdom the same way.
        $this->seedKingdom(self::RETIRED_KINGDOM, 'Active');
        $this->seatRawAt(self::RETIRED_KINGDOM, 0, $positionId, self::MARKER . '_crown_a', $holder);
        $kRow = $this->insertHistoryRowAt(self::RETIRED_KINGDOM, 0, $positionId, self::MARKER . '_crown_a', $holder, '2020-01-01', 1);
        self::assertSame(0, (int) Ork3::$Lib->kingdom->RetireKingdom(['Token' => $token, 'KingdomId' => self::RETIRED_KINGDOM])['Status']);
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $kRow)['is_current']);
        self::assertSame(0, (int) Ork3::$Lib->kingdom->RestoreKingdom(['Token' => $token, 'KingdomId' => self::RETIRED_KINGDOM])['Status']);
        self::assertSame(1, (int) $this->historyRow('officer_history_id = ' . $kRow)['is_current']);
    }

    /**
     * A transferred park takes its history with it: the rolls still list the
     * pre-transfer terms, and the next seat change closes the current row
     * rather than leaving it open beside a new one.
     */
    public function testATransferredParksHistoryFollowsIt(): void
    {
        $positionId = $this->seedPosition('global_crown', 'crown', 0, 0);
        $this->seededPositions['global_crown'] = $positionId;
        $holderA = $this->seededMundanes[0];
        $holderB = $this->seedMundane('holder_b');
        $this->seededMundanes[] = $holderB;
        $token = $this->fixture->createAuthorizedOfficer();
        $this->grantGlobalAdmin($token);
        $base = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => self::PARK_A, 'PositionId' => $positionId];

        $a = $this->positions->SetOccupant($base + ['MundaneId' => $holderA]);
        self::assertSame(0, (int) $a['Status'], (string) ($a['Error'] ?? ''));
        $rowA = $this->historyRow('position_id = ' . $positionId . ' AND mundane_id = ' . $holderA);

        $t = Ork3::$Lib->park->TransferPark(['Token' => $token, 'ParkId' => self::PARK_A, 'KingdomId' => self::OTHER_KINGDOM]);
        self::assertSame(0, (int) $t['Status'], (string) ($t['Error'] ?? ''));
        self::assertSame(self::OTHER_KINGDOM, (int) $this->historyRow('officer_history_id = ' . $rowA['officer_history_id'])['kingdom_id']);

        $ids = [];
        foreach ($this->positions->rolls_for_org(0, self::PARK_A)['Offices'] as $office) {
            foreach ($office['Terms'] as $term) {
                $ids[] = $term['OfficerHistoryId'];
            }
        }
        self::assertSame([(int) $rowA['officer_history_id']], $ids, 'the pre-transfer term is on the rolls, with no synthetic twin');

        $b = $this->positions->SetOccupant(['KingdomId' => self::OTHER_KINGDOM] + $base + ['MundaneId' => $holderB]);
        self::assertSame(0, (int) $b['Status'], (string) ($b['Error'] ?? ''));
        $closed = $this->historyRow('officer_history_id = ' . $rowA['officer_history_id']);
        self::assertSame(0, (int) $closed['is_current'], 'the transferred current row closes');
        self::assertSame(date('Y-m-d'), $closed['end_date']);
        $open = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ork_officer_history WHERE is_current = 1 AND position_id = ' . $positionId
        )->fetchColumn();
        self::assertSame(1, $open, 'one current row for the office');
    }

    /**
     * Park rows filed under a kingdom the park no longer belongs to (a transfer
     * before TransferPark moved history) are realigned; kingdom rows are not.
     */
    public function testMigrationAlignsParkRowsWithTheirParksKingdom(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $role = self::MARKER . '_crown_a';
        $holder = $this->seededMundanes[0];
        $parkRow = $this->insertHistoryRowAt(self::OTHER_KINGDOM, self::PARK_A, $positionId, $role, $holder, '2011-01-01', 0);
        $kingdomRow = $this->insertHistoryRowAt(self::OTHER_KINGDOM, 0, $positionId, $role, $holder, '2011-01-01', 0);

        $this->runMigrationBackfill();

        self::assertSame(self::KINGDOM_ID, (int) $this->historyRow('officer_history_id = ' . $parkRow)['kingdom_id']);
        self::assertSame(self::OTHER_KINGDOM, (int) $this->historyRow('officer_history_id = ' . $kingdomRow)['kingdom_id']);
    }

    /**
     * The close paths find a park's current row by park alone (park_id is
     * unique), so a row still filed under an old kingdom closes all the same.
     */
    public function testASeatChangeClosesAParkRowFiledUnderAnOldKingdom(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $holderA = $this->seededMundanes[0];
        $holderB = $this->seedMundane('holder_b');
        $this->seededMundanes[] = $holderB;
        $base = ['Token' => $this->fixture->createAuthorizedOfficer(), 'KingdomId' => self::KINGDOM_ID,
            'ParkId' => self::PARK_A, 'PositionId' => $positionId];
        $a = $this->positions->SetOccupant($base + ['MundaneId' => $holderA]);
        self::assertSame(0, (int) $a['Status'], (string) ($a['Error'] ?? ''));
        $rowA = $this->historyRow('park_id = ' . self::PARK_A . ' AND mundane_id = ' . $holderA);
        $this->pdo->exec('UPDATE ork_officer_history SET kingdom_id = ' . self::OTHER_KINGDOM
            . ' WHERE officer_history_id = ' . $rowA['officer_history_id']);

        $b = $this->positions->SetOccupant($base + ['MundaneId' => $holderB]);
        self::assertSame(0, (int) $b['Status'], (string) ($b['Error'] ?? ''));
        self::assertSame(0, (int) $this->historyRow('officer_history_id = ' . $rowA['officer_history_id'])['is_current']);
        $vacate = $this->positions->VacateOfficer($base + ['MundaneId' => $holderB]);
        self::assertSame(0, (int) $vacate['Status'], (string) ($vacate['Error'] ?? ''));
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ork_officer_history WHERE is_current = 1 AND park_id = ' . self::PARK_A
        )->fetchColumn());
    }

    public function testAddRejectsAFutureStart(): void
    {
        $season = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Lord Soon', 'Period' => 'Spring ' . ((int) date('Y') + 1),
        ]);
        self::assertSame('A term cannot start in the future.', $season['Error'] ?? null);

        $exact = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Lord Soon', 'StartDate' => date('Y-m-d', strtotime('+1 day')),
        ]);
        self::assertSame('A term cannot start in the future.', $exact['Error'] ?? null);
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

    /**
     * A linked holder carries the name the term was recorded under: the ledger
     * sends the picked persona, and any name sent is kept beside the link.
     */
    public function testAddWithAMemberKeepsAnyRecordedName(): void
    {
        $persona = (string) $this->pdo->query('SELECT persona FROM ork_mundane WHERE mundane_id = ' . (int) $this->seededMundanes[0])->fetchColumn();
        self::assertNotSame('', $persona);
        $r = $this->positions->AddHistoryTerm($this->historyBase() + [
            'MundaneId' => $this->seededMundanes[0], 'HolderName' => $persona, 'Period' => 'Fall 98',
        ]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        self::assertSame($persona, $this->historyRow('officer_history_id = ' . (int) $r['Detail'])['holder_name'], 'the persona at the pick is the name of record');

        $r = $this->positions->AddHistoryTerm($this->historyBase() + [
            'MundaneId' => $this->seededMundanes[0], 'HolderName' => 'Squire Recorded', 'Period' => 'Fall 99',
            'StartDate' => '1999-09-15',
        ]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . (int) $r['Detail']);
        self::assertSame($this->seededMundanes[0], (int) $row['mundane_id']);
        self::assertSame('Squire Recorded', $row['holder_name'], 'the name of record is kept beside the link');
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

    /**
     * Item 1: a stored start is fixed. A current "Winter 24" (Jan-Feb 2024) is
     * stamped start_ordinal 2024*4; closing it -- by seating the next holder
     * (record_officer_history / closeTermAt) or by vacating
     * (CloseOfficerHistoryTerm) -- writes the end's ordinal and leaves the
     * start where it was, where re-resolving the columns read Dec 2024.
     */
    public function testClosingACurrentWinterStartKeepsItsStartOrdinal(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        $today = OfficerTermPeriod::currentOrdinal();
        foreach (['seat next' => 'crown_a', 'vacate' => 'crown_b'] as $how => $key) {
            $current = $this->seatAndGetCurrentRow($key);
            $id = (int) $current['officer_history_id'];
            self::assertSame($today, (int) $current['start_ordinal'], $how . ': an opened term stamps its start');
            self::assertNull($current['end_ordinal']);

            // The two-year name of a winter is a single season, so a current row takes it.
            $e = $this->positions->EditHistoryTerm(['Token' => $token, 'OfficerHistoryId' => $id, 'Period' => 'Winter 2023–24']);
            self::assertSame(0, (int) $e['Status'], $how . ': ' . ($e['Error'] ?? ''));
            $e = $this->positions->EditHistoryTerm(['Token' => $token, 'OfficerHistoryId' => $id, 'Period' => 'Winter 24']);
            self::assertSame(0, (int) $e['Status'], $how . ': ' . ($e['Error'] ?? ''));
            $row = $this->historyRow('officer_history_id = ' . $id);
            self::assertSame(['winter', 2024, 2024 * 4], [$row['start_season'], (int) $row['start_year'], (int) $row['start_ordinal']], $how);

            if ($how === 'seat next') {
                $next = $this->seedMundane('winter_next');
                $this->seededMundanes[] = $next;
                $r = $this->positions->SetOccupant($this->historyBase($key) + ['MundaneId' => $next]);
            } else {
                $r = $this->positions->VacateOfficer($this->historyBase($key) + ['MundaneId' => $this->seededMundanes[0]]);
            }
            self::assertSame(0, (int) $r['Status'], $how . ': ' . ($r['Error'] ?? ''));

            $closed = $this->historyRow('officer_history_id = ' . $id);
            self::assertSame(0, (int) $closed['is_current'], $how);
            self::assertSame(date('Y-m-d'), $closed['end_date'], $how);
            self::assertSame(2024 * 4, (int) $closed['start_ordinal'], $how . ': closing leaves the start where it was');
            self::assertSame($today, (int) $closed['end_ordinal'], $how . ': the close stamps its end');

            $rows = array_values(array_filter(
                (new Kingdom())->GetOfficerHistory(['KingdomId' => self::KINGDOM_ID])['History'],
                fn ($h) => $h['OfficerHistoryId'] === $id
            ));
            self::assertSame(2024 * 4, $rows[0]['StartOrdinal'], $how);
            self::assertStringStartsWith('Winter 2023–24 – ', $rows[0]['PeriodLabel'], $how);
        }
    }

    /**
     * Manage Officers' Set-it dialog edits the seat's open row by an id read when
     * the list loaded. ExpectCurrent makes a reseat in between refuse the save,
     * rather than rewriting the now-closed term's span (a Period on a past row
     * replaces both ends).
     */
    public function testExpectCurrentRefusesARowClosedSinceTheListLoaded(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        $current = $this->seatAndGetCurrentRow();
        $id = (int) $current['officer_history_id'];

        $next = $this->seedMundane('expect_next');
        $this->seededMundanes[] = $next;
        $r = $this->positions->SetOccupant($this->historyBase() + ['MundaneId' => $next]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        $closed = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame(0, (int) $closed['is_current']);

        $e = $this->positions->EditHistoryTerm([
            'Token' => $token, 'OfficerHistoryId' => $id, 'Period' => 'Spring 24', 'ExpectCurrent' => 1,
        ]);
        self::assertNotSame(0, (int) $e['Status'], 'a closed row is refused when the caller expected it current');
        self::assertStringContainsString('ended since the list loaded', (string) ($e['Error'] ?? ''));
        $after = $this->historyRow('officer_history_id = ' . $id);
        foreach (['start_season', 'start_year', 'start_date', 'start_ordinal', 'end_season', 'end_year', 'end_date', 'end_ordinal', 'is_current'] as $c) {
            self::assertSame($closed[$c], $after[$c], $c . ' is unchanged');
        }

        // On the open row ExpectCurrent changes nothing.
        $open = $this->historyRow('mundane_id = ' . $next . ' AND is_current = 1');
        $ok = $this->positions->EditHistoryTerm([
            'Token' => $token, 'OfficerHistoryId' => (int) $open['officer_history_id'], 'Period' => 'Spring 24', 'ExpectCurrent' => 1,
        ]);
        self::assertSame(0, (int) $ok['Status'], (string) ($ok['Error'] ?? ''));
    }

    /** Add and Edit stamp the ordinals they set; a side an edit leaves alone keeps its own. */
    public function testAddAndEditStampTheOrdinalsTheySet(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        $add = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Lady January', 'Period' => 'Jan 23 – Fall 24',
        ]);
        self::assertSame(0, (int) $add['Status'], (string) ($add['Error'] ?? ''));
        $id = (int) $add['Detail'];
        $row = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame([2023 * 4, 2024 * 4 + 3], [(int) $row['start_ordinal'], (int) $row['end_ordinal']], 'a month start is that month (item 2)');
        // The columns are the entered text, chosen so they read back the same way.
        self::assertSame(['winter', 2022], [$row['start_season'], (int) $row['start_year']]);

        $note = $this->positions->EditHistoryTerm(['Token' => $token, 'OfficerHistoryId' => $id, 'Note' => 'from the wiki']);
        self::assertSame(0, (int) $note['Status'], (string) ($note['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame([2023 * 4, 2024 * 4 + 3], [(int) $row['start_ordinal'], (int) $row['end_ordinal']], 'a note edit keeps both');

        $end = $this->positions->EditHistoryTerm(['Token' => $token, 'OfficerHistoryId' => $id, 'EndDate' => '2025-01-10']);
        self::assertSame(0, (int) $end['Status'], (string) ($end['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame([2023 * 4, 2025 * 4], [(int) $row['start_ordinal'], (int) $row['end_ordinal']], 'an end date stamps the end only');

        $cleared = $this->positions->EditHistoryTerm(['Token' => $token, 'OfficerHistoryId' => $id, 'EndDate' => '']);
        self::assertSame(0, (int) $cleared['Status'], (string) ($cleared['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame([2023 * 4, 2024 * 4 + 3], [(int) $row['start_ordinal'], (int) $row['end_ordinal']], 'clearing the date falls back to the entered end season');

        // The edit text shown for it reads back to the stored ordinals.
        $term = array_values(array_filter(
            (new Kingdom())->GetOfficerHistory(['KingdomId' => self::KINGDOM_ID])['History'],
            fn ($h) => $h['OfficerHistoryId'] === $id
        ))[0];
        self::assertSame('Winter 2022–23 – Fall 2024', $term['PeriodLabel']);
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
        self::assertSame('Sir Bram the Elder', $row['holder_name'], 'linking keeps the name the term was recorded under');
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
        self::assertSame('Sir Bram the Elder', $post['HolderName']);

        // Relinking to another player keeps the recorded name; unlinking replaces it.
        $other = $this->seedMundane('relink');
        $this->seededMundanes[] = $other;
        $token = $this->fixture->createAuthorizedOfficer();
        $r = $this->positions->EditHistoryTerm(['Token' => $token, 'OfficerHistoryId' => $id, 'MundaneId' => $other]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        self::assertSame('Sir Bram the Elder', $this->historyRow('officer_history_id = ' . $id)['holder_name']);
        $r = $this->positions->EditHistoryTerm(['Token' => $token, 'OfficerHistoryId' => $id, 'MundaneId' => 0, 'HolderName' => 'Bram']);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame(0, (int) $row['mundane_id']);
        self::assertSame('Bram', $row['holder_name']);
    }

    /** An edit may not take away a row's only start; a season start survives a cleared date. */
    public function testEditRefusesToClearTheOnlyStart(): void
    {
        $add = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Dame Dated', 'StartDate' => '2019-04-02', 'EndDate' => '2019-10-20',
        ]);
        self::assertSame(0, (int) $add['Status'], (string) ($add['Error'] ?? ''));
        $id = (int) $add['Detail'];

        $r = $this->positions->EditHistoryTerm([
            'Token' => $this->fixture->createAuthorizedOfficer(),
            'OfficerHistoryId' => $id, 'StartDate' => '',
        ]);
        self::assertNotSame(0, (int) $r['Status'], 'clearing the only start is refused');
        self::assertSame('A term needs a start.', $r['Error'] ?? null);
        self::assertSame('2019-04-02', $this->historyRow('officer_history_id = ' . $id)['start_date']);

        // With a season start stored, the cleared date falls back to the season.
        $add = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Dame Both', 'Period' => 'Spring 19 – Fall 19', 'StartDate' => '2019-04-02',
        ]);
        self::assertSame(0, (int) $add['Status'], (string) ($add['Error'] ?? ''));
        $both = (int) $add['Detail'];
        $r = $this->positions->EditHistoryTerm([
            'Token' => $this->fixture->createAuthorizedOfficer(),
            'OfficerHistoryId' => $both, 'StartDate' => '',
        ]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . $both);
        self::assertNull($row['start_date']);
        self::assertSame('spring', $row['start_season']);
    }

    /** A row recorded with no start (end only) may still be edited, e.g. its note. */
    public function testEditOfAnEndOnlyRowIsAllowed(): void
    {
        $add = $this->positions->AddHistoryTerm($this->historyBase() + [
            'HolderName' => 'Ancient', 'Period' => 'Spring 05 – Fall 05',
        ]);
        self::assertSame(0, (int) $add['Status'], (string) ($add['Error'] ?? ''));
        $id = (int) $add['Detail'];
        // Legacy shape: only the end is known.
        $this->pdo->exec('UPDATE ork_officer_history SET start_season = NULL, start_year = NULL, start_date = NULL WHERE officer_history_id = ' . $id);

        $r = $this->positions->EditHistoryTerm([
            'Token' => $this->fixture->createAuthorizedOfficer(),
            'OfficerHistoryId' => $id, 'Note' => 'end only', 'StartDate' => '',
        ]);
        self::assertSame(0, (int) $r['Status'], (string) ($r['Error'] ?? ''));
        $row = $this->historyRow('officer_history_id = ' . $id);
        self::assertSame('end only', $row['notes']);
        self::assertNull($row['start_season']);
        self::assertSame('fall', $row['end_season']);
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

    /**
     * Run the statements after the migration's `-- is_current backfill` marker
     * one at a time, so a failing later statement fails the test (a single
     * multi-statement exec reports only the first).
     */
    private function runMigrationBackfill(): void
    {
        $path = dirname(__DIR__, 2) . '/db-migrations/2026-11-04-officer-history-rolls.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);
        $pos = strpos($sql, '-- is_current backfill');
        self::assertNotFalse($pos, 'the migration must mark its is_current backfill section');
        $statements = [];
        foreach (preg_split('/;\s*$/m', substr($sql, $pos)) as $chunk) {
            $body = trim(preg_replace('/^\s*--.*$/m', '', $chunk));
            if ($body !== '') {
                $statements[] = $body;
            }
        }
        self::assertCount(6, $statements, 'backfill: align park rows, clear stale marks, clear retired orgs, mark seats, dedupe per seat, open missing seats');
        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }

    private function seatRawAt(int $kingdomId, int $parkId, int $positionId, string $role, int $mundaneId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system, authorization_id, position_id, modified)
             VALUES (:kid, :pid, :mid, :role, 0, 0, :pos, NOW())'
        )->execute([':kid' => $kingdomId, ':pid' => $parkId, ':mid' => $mundaneId, ':role' => $role, ':pos' => $positionId]);
    }

    private function insertHistoryRowAt(int $kingdomId, int $parkId, int $positionId, string $role, int $mundaneId, string $start, int $isCurrent): int
    {
        $id = $this->insertHistoryRow($positionId, $role, $mundaneId, $start, $isCurrent);
        $this->pdo->exec('UPDATE ork_officer_history SET kingdom_id = ' . $kingdomId . ', park_id = ' . $parkId
            . ' WHERE officer_history_id = ' . $id);
        return $id;
    }

    private function seedKingdom(int $kingdomId, string $active): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_kingdom (kingdom_id, name, abbreviation, parent_kingdom_id, active)
             VALUES (:kid, :name, "ZZK", 0, :active)'
        )->execute([':kid' => $kingdomId, ':name' => self::MARKER . '_kingdom_' . $kingdomId, ':active' => $active]);
    }

    /** A global (all-zero scope) admin grant for the fixture officer, for TransferPark. */
    private function grantGlobalAdmin(string $token): void
    {
        $this->pdo->prepare(
            "INSERT INTO ork_authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             SELECT mundane_id, 0, 0, 0, 0, 'admin' FROM ork_session WHERE token = :t LIMIT 1"
        )->execute([':t' => $token]);
    }

    private function seatRaw(int $positionId, string $role, int $mundaneId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system, authorization_id, position_id, modified)
             VALUES (:kid, 0, :mid, :role, 0, 0, :pos, NOW())'
        )->execute([':kid' => self::KINGDOM_ID, ':mid' => $mundaneId, ':role' => $role, ':pos' => $positionId]);
    }

    private function seedPosition(string $suffix, string $classification, int $hasAuthRole = 0, int $kingdomId = self::KINGDOM_ID): int
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
            ':kid' => $kingdomId,
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
