<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The row shape Kingdom::GetOfficerHistory() and Park::GetOfficerHistory() emit.
 *
 * ork_officer_history carries a `position_id` (the registry office the term was
 * served in) and a `display_label` (a SNAPSHOT of what that office was called at
 * the time -- the whole point of the column, since a kingdom can rename an office
 * afterwards and a historical term must keep the name it was actually held under).
 * Neither method emitted either one, so the only office identifier reaching the UI
 * was `Role`, the raw canonical key -- which is why a past term rendered as
 * "royal_scribe" where an office name belongs.
 *
 * Classification comes through a LEFT JOIN and must stay NULL, never defaulted,
 * when the row's position no longer exists or the row predates the registry
 * (position_id = 0). The caller sorts crown offices first; defaulting an unknown
 * to 'supporting' would silently assert a classification the data does not carry.
 *
 * Kingdom:: and Park:: are SEPARATE implementations of this read (two queries, two
 * row loops), so both scopes are covered here rather than assumed to share a
 * builder the way GetOfficers() does.
 *
 * Rows are seeded with raw INSERTs under the MARKER prefix and removed in
 * tearDown() -- not inline after an assertion, since inline cleanup never runs on
 * the failure path, which is exactly when leftovers poison the next run.
 */
final class OfficerHistoryRowShapeTest extends TestCase
{
    private const MARKER = 'zzohhist';

    /** Real ids from the seeded test database: PARK_ID belongs to KINGDOM_ID. */
    private const KINGDOM_ID = 100001;
    private const PARK_ID = 1000001;

    /**
     * A position_id that resolves to no ork_officer_position row. Distinct from 0:
     * 0 is the column's own "predates the registry" value, this is a dangling
     * reference to a position that was deleted. Both must classify as NULL.
     */
    private const GHOST_POSITION_ID = 999000;

    private PDO $pdo;

    /** @var array<string,int> slug => position_id */
    private array $seeded = [];

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
        $this->purgeMarkerRows();

        $this->seeded['crown'] = $this->seedPosition('crown', 'crown');
        $this->seeded['supp'] = $this->seedPosition('supp', 'supporting');

        // Kingdom scope (park_id = 0). Every display_label is deliberately UNLIKE the
        // role, so a method that emits the canonical key under DisplayLabel fails.
        $this->seedHistory(0, 'crown', $this->seeded['crown'], 'Sovereign of Old');
        $this->seedHistory(0, 'supp', $this->seeded['supp'], 'Royal Scribe of Old');
        $this->seedHistory(0, 'zero', 0, 'Pre-Registry Office');
        $this->seedHistory(0, 'ghost', self::GHOST_POSITION_ID, 'Deleted Office');

        // Park scope: the same four shapes, through the separate Park:: query.
        $this->seedHistory(self::PARK_ID, 'p_crown', $this->seeded['crown'], 'Park Sovereign of Old');
        $this->seedHistory(self::PARK_ID, 'p_supp', $this->seeded['supp'], 'Park Scribe of Old');
        $this->seedHistory(self::PARK_ID, 'p_zero', 0, 'Park Pre-Registry Office');
        $this->seedHistory(self::PARK_ID, 'p_ghost', self::GHOST_POSITION_ID, 'Park Deleted Office');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->purgeMarkerRows();
        }
        $this->seeded = [];
    }

    // ============================================================
    // KINGDOM SCOPE
    // ============================================================

    public function testKingdomHistoryRowsCarryAllThreeNewKeys(): void
    {
        $rows = $this->kingdomRows();

        foreach (['crown', 'supp', 'zero', 'ghost'] as $slug) {
            $this->assertArrayHasKey($slug, $rows, 'seeded history row missing from the kingdom list');
            $this->assertArrayHasKey('PositionId', $rows[$slug], $slug . ' must carry PositionId');
            $this->assertArrayHasKey('DisplayLabel', $rows[$slug], $slug . ' must carry DisplayLabel');
            $this->assertArrayHasKey('Classification', $rows[$slug], $slug . ' must carry Classification');
        }
    }

    public function testKingdomPositionIdIsAPlainIntNeverNull(): void
    {
        // ork_officer_history.position_id is INT NOT NULL DEFAULT 0, the same shape as
        // ork_officer.position_id, so 0 -- not NULL -- is the database's own "no
        // registry position" value and buildOfficerRows()' plain-int emission is the
        // convention to match. A consumer can then key history and current-officer
        // rows the same way.
        $rows = $this->kingdomRows();

        foreach ($rows as $slug => $row) {
            $this->assertIsInt($row['PositionId'], $slug . ' PositionId must be a plain int');
        }

        $this->assertSame($this->seeded['crown'], $rows['crown']['PositionId']);
        $this->assertSame($this->seeded['supp'], $rows['supp']['PositionId']);
        $this->assertSame(0, $rows['zero']['PositionId'], 'a pre-registry row is 0, never null');
        $this->assertSame(self::GHOST_POSITION_ID, $rows['ghost']['PositionId'], 'a dangling id is emitted as stored');
    }

    public function testKingdomDisplayLabelIsTheStoredSnapshotNotTheCanonicalKey(): void
    {
        // The failure this fixes: the UI rendered `royal_scribe` because the canonical
        // key was the only office identifier being emitted. DisplayLabel must be the
        // name the office was HELD under, which is a different string entirely.
        $rows = $this->kingdomRows();

        $this->assertSame('Sovereign of Old', $rows['crown']['DisplayLabel']);
        $this->assertSame('Royal Scribe of Old', $rows['supp']['DisplayLabel']);
        $this->assertSame('Pre-Registry Office', $rows['zero']['DisplayLabel']);

        $this->assertNotSame(
            $rows['supp']['Role'],
            $rows['supp']['DisplayLabel'],
            'DisplayLabel must be the stored snapshot, not the canonical key'
        );
    }

    public function testKingdomClassificationIsCrownSupportingOrNull(): void
    {
        $rows = $this->kingdomRows();

        $this->assertSame('crown', $rows['crown']['Classification']);
        $this->assertSame('supporting', $rows['supp']['Classification']);

        // NULL, never a default. The caller sorts crown offices first and has to be
        // able to tell "definitely not crown" from "unknown"; defaulting either of
        // these to 'supporting' would promote an unknown into a group it may not
        // belong to.
        $this->assertNull(
            $rows['zero']['Classification'],
            'a pre-registry row (position_id = 0) has no classification'
        );
        $this->assertNull(
            $rows['ghost']['Classification'],
            'a row whose position no longer exists has no classification'
        );
    }

    public function testKingdomRoleFilterStillWorks(): void
    {
        $all = $this->kingdomRows();
        $this->assertCount(4, $all, 'the unfiltered read must return every seeded row');

        $filtered = $this->kingdomRows(self::MARKER . '_supp');

        $this->assertSame(['supp'], array_keys($filtered), 'the role filter must narrow to one role');
        $this->assertSame('Royal Scribe of Old', $filtered['supp']['DisplayLabel']);
        $this->assertSame('supporting', $filtered['supp']['Classification']);
    }

    public function testKingdomExistingKeysAreUnchanged(): void
    {
        // Purely additive: nothing renamed, nothing removed, no value re-shaped.
        $row = $this->kingdomRows()['supp'];

        foreach ([
            'OfficerHistoryId', 'KingdomId', 'ParkId', 'MundaneId', 'Role', 'StartDate',
            'EndDate', 'ChangedBy', 'ChangedByPersona', 'Notes', 'Persona', 'UserName',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, $key . ' must survive the additive change');
        }

        $this->assertSame(self::MARKER . '_supp', $row['Role']);
        $this->assertSame(self::KINGDOM_ID, $row['KingdomId']);
        $this->assertSame(0, $row['ParkId']);
        $this->assertSame('2001-01-01', $row['StartDate']);
        $this->assertSame('2001-12-31', $row['EndDate']);
    }

    // ============================================================
    // PARK SCOPE (a SEPARATE implementation of the same read)
    // ============================================================

    public function testParkHistoryRowsCarryAllThreeNewKeys(): void
    {
        $rows = $this->parkRows();

        foreach (['p_crown', 'p_supp', 'p_zero', 'p_ghost'] as $slug) {
            $this->assertArrayHasKey($slug, $rows, 'seeded history row missing from the park list');
            $this->assertArrayHasKey('PositionId', $rows[$slug], $slug . ' must carry PositionId');
            $this->assertArrayHasKey('DisplayLabel', $rows[$slug], $slug . ' must carry DisplayLabel');
            $this->assertArrayHasKey('Classification', $rows[$slug], $slug . ' must carry Classification');
        }
    }

    public function testParkPositionIdIsAPlainIntNeverNull(): void
    {
        $rows = $this->parkRows();

        foreach ($rows as $slug => $row) {
            $this->assertIsInt($row['PositionId'], $slug . ' PositionId must be a plain int');
        }

        $this->assertSame($this->seeded['crown'], $rows['p_crown']['PositionId']);
        $this->assertSame(0, $rows['p_zero']['PositionId']);
        $this->assertSame(self::GHOST_POSITION_ID, $rows['p_ghost']['PositionId']);
    }

    public function testParkDisplayLabelIsTheStoredSnapshotNotTheCanonicalKey(): void
    {
        $rows = $this->parkRows();

        $this->assertSame('Park Sovereign of Old', $rows['p_crown']['DisplayLabel']);
        $this->assertSame('Park Scribe of Old', $rows['p_supp']['DisplayLabel']);
        $this->assertNotSame(
            $rows['p_supp']['Role'],
            $rows['p_supp']['DisplayLabel'],
            'DisplayLabel must be the stored snapshot, not the canonical key'
        );
    }

    public function testParkClassificationIsCrownSupportingOrNull(): void
    {
        $rows = $this->parkRows();

        $this->assertSame('crown', $rows['p_crown']['Classification']);
        $this->assertSame('supporting', $rows['p_supp']['Classification']);
        $this->assertNull($rows['p_zero']['Classification']);
        $this->assertNull($rows['p_ghost']['Classification']);
    }

    public function testParkRoleFilterStillWorks(): void
    {
        $all = $this->parkRows();
        $this->assertCount(4, $all, 'the unfiltered read must return every seeded row');

        $filtered = $this->parkRows(self::MARKER . '_p_crown');

        $this->assertSame(['p_crown'], array_keys($filtered), 'the role filter must narrow to one role');
        $this->assertSame('crown', $filtered['p_crown']['Classification']);
    }

    public function testParkExistingKeysAreUnchanged(): void
    {
        $row = $this->parkRows()['p_supp'];

        foreach ([
            'OfficerHistoryId', 'KingdomId', 'ParkId', 'MundaneId', 'Role', 'StartDate',
            'EndDate', 'ChangedBy', 'ChangedByPersona', 'Notes', 'Persona', 'UserName',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, $key . ' must survive the additive change');
        }

        $this->assertSame(self::MARKER . '_p_supp', $row['Role']);
        $this->assertSame(self::PARK_ID, $row['ParkId']);
        $this->assertSame(self::KINGDOM_ID, $row['KingdomId']);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Kingdom::GetOfficerHistory rows, keyed by seeded slug (marker prefix stripped).
     *
     * @return array<string,array<string,mixed>>
     */
    private function kingdomRows(?string $roleFilter = null): array
    {
        $request = ['KingdomId' => self::KINGDOM_ID];
        if ($roleFilter !== null) {
            $request['Role'] = $roleFilter;
        }
        $r = Ork3::$Lib->kingdom->GetOfficerHistory($request);
        $this->assertSame(0, (int) $r['Status']['Status'], 'Kingdom::GetOfficerHistory must succeed');

        return $this->markerRows($r);
    }

    /**
     * Park::GetOfficerHistory rows, keyed by seeded slug (marker prefix stripped).
     *
     * @return array<string,array<string,mixed>>
     */
    private function parkRows(?string $roleFilter = null): array
    {
        $request = ['ParkId' => self::PARK_ID];
        if ($roleFilter !== null) {
            $request['Role'] = $roleFilter;
        }
        $r = Ork3::$Lib->park->GetOfficerHistory($request);
        $this->assertSame(0, (int) $r['Status']['Status'], 'Park::GetOfficerHistory must succeed');

        return $this->markerRows($r);
    }

    /**
     * @param  array<string,mixed> $result
     * @return array<string,array<string,mixed>>
     */
    private function markerRows(array $result): array
    {
        $out = [];
        foreach ($result['History'] ?? [] as $row) {
            $role = (string) ($row['Role'] ?? '');
            if (!str_starts_with($role, self::MARKER . '_')) {
                continue;
            }
            $out[substr($role, strlen(self::MARKER) + 1)] = $row;
        }

        return $out;
    }

    private function seedPosition(string $slug, string $classification): int
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer_position
                (kingdom_id, canonical_key, title, title_alias, classification, is_pinned, is_system,
                 rbac_role_id, has_auth_role, sort_order, parent_position_id, hide_when_vacant,
                 retired_at, created_by, created_at)
             VALUES
                (:kingdom_id, :canonical_key, :title, \'\', :classification, 0, 0,
                 0, 0, 100, NULL, 0, NULL, 0, NOW())'
        )->execute([
            ':kingdom_id' => self::KINGDOM_ID,
            ':canonical_key' => self::MARKER . '_' . $slug,
            ':title' => 'History Shape ' . $slug,
            ':classification' => $classification,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedHistory(int $parkId, string $slug, int $positionId, string $displayLabel): int
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer_history
                (kingdom_id, park_id, mundane_id, role, position_id, display_label,
                 start_date, end_date, changed_by, notes, created_at)
             VALUES
                (:kid, :pkid, 0, :role, :pid, :label,
                 \'2001-01-01\', \'2001-12-31\', 0, NULL, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID,
            ':pkid' => $parkId,
            ':role' => self::MARKER . '_' . $slug,
            ':pid' => $positionId,
            ':label' => $displayLabel,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function purgeMarkerRows(): void
    {
        $this->pdo->exec("DELETE FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "\\_%'");
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "\\_%'");
    }
}
