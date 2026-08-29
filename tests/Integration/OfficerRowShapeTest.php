<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The row shape Kingdom::buildOfficerRows() emits.
 *
 * buildOfficerRows() is the single builder behind BOTH the Kingdom profile
 * (Kingdom::GetOfficers) and the Park profile (Park::GetOfficers), so its row
 * shape is a contract two surfaces depend on. It emitted ParentPositionId but
 * not the position's own id, so nothing downstream could match a child row to
 * its parent -- a tree was impossible. It also dropped op.classification (which
 * the sidebars need to tell a crown office from a supporting one) and never
 * selected sort_order at all.
 *
 * The subtle one is SortOrder. The Core Five are SHARED rows (kingdom_id = 0)
 * and a kingdom's re-ordering of them lives in ork_officer_position_alias, so
 * the ORDER BY resolves through OfficerPosition::SortOrderSql(). The value
 * EMITTED has to be that same effective value: emitting raw op.sort_order would
 * let a consumer that sorts client-side by SortOrder disagree with the order the
 * query actually returned.
 *
 * Rows are seeded with raw INSERTs (no CreatePosition(), which would drag in the
 * RBAC role-creation path this test is not exercising). Every seeded row carries
 * the MARKER prefix and is removed in tearDown() -- not inline after an
 * assertion, since inline cleanup never runs on the failure path, which is
 * exactly when leftover rows poison the next run.
 */
final class OfficerRowShapeTest extends TestCase
{
    private const MARKER = 'zzrowshape';

    /** Real ids from the seeded test database: PARK_ID belongs to KINGDOM_ID. */
    private const KINGDOM_ID = 100001;
    private const OTHER_KINGDOM_ID = 100002;
    private const PARK_ID = 1000001;

    private PDO $pdo;

    /** @var array<string,int> label => position_id */
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

        // A kingdom-owned crown office with one supporting office reporting to it:
        // the minimum shape a tree needs, and the pair that proves a child can be
        // matched back to its parent.
        $this->seeded['crown'] = $this->seedPosition(self::KINGDOM_ID, 'crown', 'crown', null, 100);
        $this->seeded['child'] = $this->seedPosition(self::KINGDOM_ID, 'child', 'supporting', $this->seeded['crown'], 200);

        // Two SHARED rows (kingdom_id = 0), stand-ins for the Core Five: every
        // kingdom reads them, so a per-kingdom re-order of them has to live in the
        // alias table rather than on the row. The real Core Five are left alone.
        $this->seeded['sharedA'] = $this->seedPosition(0, 'shared_a', 'supporting', null, 100);
        $this->seeded['sharedB'] = $this->seedPosition(0, 'shared_b', 'supporting', null, 200);

        // Kingdom scope: park_id = 0.
        $this->seedOfficer(self::KINGDOM_ID, 0, $this->seeded['crown'], 'crown');
        $this->seedOfficer(self::KINGDOM_ID, 0, $this->seeded['child'], 'child');
        $this->seedOfficer(self::KINGDOM_ID, 0, $this->seeded['sharedA'], 'shared_a');
        $this->seedOfficer(self::KINGDOM_ID, 0, $this->seeded['sharedB'], 'shared_b');

        // Park scope: the same positions held at park level inside the same kingdom,
        // which is what makes the park list inherit its kingdom's ordering.
        $this->seedOfficer(self::KINGDOM_ID, self::PARK_ID, $this->seeded['crown'], 'p_crown');
        $this->seedOfficer(self::KINGDOM_ID, self::PARK_ID, $this->seeded['child'], 'p_child');
        $this->seedOfficer(self::KINGDOM_ID, self::PARK_ID, $this->seeded['sharedA'], 'p_shared_a');
        $this->seedOfficer(self::KINGDOM_ID, self::PARK_ID, $this->seeded['sharedB'], 'p_shared_b');

        // Another kingdom holding the same two shared positions, to prove one
        // kingdom's override does not leak.
        $this->seedOfficer(self::OTHER_KINGDOM_ID, 0, $this->seeded['sharedA'], 'o_shared_a');
        $this->seedOfficer(self::OTHER_KINGDOM_ID, 0, $this->seeded['sharedB'], 'o_shared_b');
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

    public function testKingdomRowsCarryPositionIdClassificationAndSortOrder(): void
    {
        $rows = $this->kingdomRows(self::KINGDOM_ID);

        foreach (['crown', 'child', 'shared_a', 'shared_b'] as $key) {
            $this->assertArrayHasKey($key, $rows, 'seeded officer missing from the kingdom list');
            $this->assertArrayHasKey('PositionId', $rows[$key]);
            $this->assertArrayHasKey('Classification', $rows[$key]);
            $this->assertArrayHasKey('SortOrder', $rows[$key]);
        }

        $this->assertSame($this->seeded['crown'], $rows['crown']['PositionId']);
        $this->assertSame($this->seeded['child'], $rows['child']['PositionId']);
        $this->assertSame('crown', $rows['crown']['Classification']);
        $this->assertSame('supporting', $rows['child']['Classification']);
        $this->assertSame(100, $rows['crown']['SortOrder']);
        $this->assertSame(200, $rows['child']['SortOrder']);
    }

    public function testAChildRowCanBeMatchedToItsParentRow(): void
    {
        // The whole reason PositionId exists. Before it, ParentPositionId pointed at
        // nothing any consumer could resolve.
        $rows = $this->kingdomRows(self::KINGDOM_ID);

        $this->assertSame(
            $rows['crown']['PositionId'],
            $rows['child']['ParentPositionId'],
            'a child row must resolve to the parent row present in the same result set'
        );
        $this->assertNull($rows['crown']['ParentPositionId'], 'a root office reports to nothing');
    }

    public function testAnUnmappedLegacyOfficerRowEmitsZeroAndNull(): void
    {
        // ork_officer.position_id is NOT NULL DEFAULT 0, so 0 -- not NULL -- is the
        // database's own "no registry position" value; the LEFT JOIN then misses and
        // the position columns come back NULL. Pin that shape so a tree builder can
        // rely on 0 never colliding with a real AUTO_INCREMENT id.
        //
        // Matched by OfficerId, not CanonicalKey: an unmapped row falls back to
        // $r->role, which resolves to ork_authorization.role (selected by `a.*`), not
        // ork_officer.role (selected as officer_role) -- a pre-existing quirk this
        // change neither causes nor fixes.
        $officerId = $this->seedOfficer(self::KINGDOM_ID, 0, 0, 'legacy', 1);

        $legacy = $this->rowByOfficerId(
            Ork3::$Lib->kingdom->GetOfficers(['KingdomId' => self::KINGDOM_ID, 'Token' => '']),
            $officerId
        );

        $this->assertNotNull($legacy, 'legacy row must still be listed');
        $this->assertArrayHasKey('PositionId', $legacy);
        $this->assertArrayHasKey('Classification', $legacy);
        $this->assertArrayHasKey('SortOrder', $legacy);
        $this->assertSame(0, $legacy['PositionId']);
        $this->assertNull($legacy['Classification']);
        $this->assertSame(0, $legacy['SortOrder']);
    }

    public function testExistingKeysAreUnchanged(): void
    {
        // Purely additive: nothing renamed, nothing removed, no value re-shaped.
        $rows = $this->kingdomRows(self::KINGDOM_ID);
        $row = $rows['child'];

        foreach ([
            'AuthorizationId', 'MundaneId', 'ParkId', 'KingdomId', 'EventId', 'UnitId',
            'Role', 'CanonicalKey', 'ParentPositionId', 'HideWhenVacant', 'DisplayTitle',
            'ParkName', 'KingdomName', 'EventName', 'UnitName', 'Restricted', 'UserName',
            'GivenName', 'Surname', 'Persona', 'OfficerId', 'OfficerRoleKey', 'OfficerRole',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, $key . ' must survive the additive change');
        }

        $this->assertSame(self::MARKER . '_child', $row['CanonicalKey']);
        $this->assertSame(self::MARKER . '_child', $row['Role']);
        $this->assertSame('Row Shape child', $row['DisplayTitle']);
        $this->assertSame(0, $row['HideWhenVacant']);
        $this->assertSame($this->seeded['crown'], $row['ParentPositionId']);
    }

    // ============================================================
    // PARK SCOPE (the same builder, reached through Park::GetOfficers)
    // ============================================================

    public function testParkRowsCarryTheSameThreeKeys(): void
    {
        $rows = $this->parkRows(self::PARK_ID);

        foreach (['crown', 'child', 'shared_a', 'shared_b'] as $key) {
            $this->assertArrayHasKey($key, $rows, 'seeded park officer missing from the park list');
            $this->assertArrayHasKey('PositionId', $rows[$key]);
            $this->assertArrayHasKey('Classification', $rows[$key]);
            $this->assertArrayHasKey('SortOrder', $rows[$key]);
        }

        $this->assertSame($this->seeded['crown'], $rows['crown']['PositionId']);
        $this->assertSame('crown', $rows['crown']['Classification']);
        $this->assertSame('supporting', $rows['child']['Classification']);
        $this->assertSame(200, $rows['child']['SortOrder']);
        $this->assertSame(
            $rows['crown']['PositionId'],
            $rows['child']['ParentPositionId'],
            'the park list must be tree-buildable too'
        );
    }

    // ============================================================
    // SortOrder must be the EFFECTIVE (per-kingdom) value
    // ============================================================

    public function testSortOrderReflectsThisKingdomsAliasOverride(): void
    {
        // shared_a is a kingdom_id = 0 row with sort_order 100. KINGDOM_ID moves it
        // behind shared_b via its own alias row; the shared row itself is untouched.
        $this->seedAliasSortOrder(self::KINGDOM_ID, 'shared_a', 500);

        $rows = $this->kingdomRows(self::KINGDOM_ID);

        $this->assertSame(
            500,
            $rows['shared_a']['SortOrder'],
            'SortOrder must emit the kingdom override, not the shared raw value'
        );
        $this->assertSame(200, $rows['shared_b']['SortOrder'], 'a row with no override keeps the shared value');
        $this->assertSame(
            100,
            $this->rawSortOrderOf($this->seeded['sharedA']),
            'the shared row itself must not have been rewritten'
        );
    }

    public function testAnotherKingdomStillSeesTheSharedSortOrder(): void
    {
        $this->seedAliasSortOrder(self::KINGDOM_ID, 'shared_a', 500);

        $rows = $this->kingdomRows(self::OTHER_KINGDOM_ID);

        $this->assertSame(100, $rows['shared_a']['SortOrder'], "another kingdom's list must be untouched");
        $this->assertSame(200, $rows['shared_b']['SortOrder']);
    }

    public function testEmittedSortOrderAgreesWithTheOrderTheQueryReturned(): void
    {
        // The actual failure mode of emitting raw op.sort_order: the row order the
        // query returns and the order a consumer computes from SortOrder diverge.
        $this->seedAliasSortOrder(self::KINGDOM_ID, 'shared_a', 500);

        $rows = $this->kingdomRows(self::KINGDOM_ID);
        $shared = array_intersect_key($rows, ['shared_a' => 1, 'shared_b' => 1]);
        foreach ($shared as $label => $row) {
            $this->assertArrayHasKey('SortOrder', $row, $label . ' must carry SortOrder');
            $this->assertIsInt($row['SortOrder'], $label . ' SortOrder must be an int to sort by');
        }

        $returnedSequence = array_keys($shared);
        $sortedSequence = $returnedSequence;
        usort($sortedSequence, static fn (string $a, string $b): int => $shared[$a]['SortOrder'] <=> $shared[$b]['SortOrder']);

        $this->assertSame(['shared_b', 'shared_a'], $returnedSequence, 'the override must drive the ORDER BY');
        $this->assertSame(
            $returnedSequence,
            $sortedSequence,
            'sorting client-side by the emitted SortOrder must reproduce the query order'
        );
    }

    public function testParkSortOrderInheritsItsKingdomsOverride(): void
    {
        // Park::GetOfficers matches the alias table on o.kingdom_id, so a park list
        // shows its kingdom's ordering -- which is what a park officer expects.
        $this->seedAliasSortOrder(self::KINGDOM_ID, 'shared_a', 500);

        $rows = $this->parkRows(self::PARK_ID);

        $this->assertSame(500, $rows['shared_a']['SortOrder']);
        $this->assertSame(200, $rows['shared_b']['SortOrder']);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Kingdom::GetOfficers rows, keyed by the seeded slug (marker prefix stripped).
     *
     * @return array<string,array<string,mixed>>
     */
    private function kingdomRows(int $kingdomId): array
    {
        $r = Ork3::$Lib->kingdom->GetOfficers(['KingdomId' => $kingdomId, 'Token' => '']);
        $this->assertSame(0, (int) $r['Status']['Status'], 'Kingdom::GetOfficers must succeed');

        return $this->markerRows($r);
    }

    /**
     * Park::GetOfficers rows, keyed by the seeded slug (marker prefix stripped).
     *
     * @return array<string,array<string,mixed>>
     */
    private function parkRows(int $parkId): array
    {
        $r = Ork3::$Lib->park->GetOfficers(['ParkId' => $parkId, 'Token' => '']);
        $this->assertSame(0, (int) $r['Status']['Status'], 'Park::GetOfficers must succeed');

        return $this->markerRows($r);
    }

    /**
     * One emitted row by its ork_officer.officer_id, or null.
     *
     * @param  array<string,mixed> $result
     * @return array<string,mixed>|null
     */
    private function rowByOfficerId(array $result, int $officerId): ?array
    {
        foreach ($result['Officers'] ?? [] as $row) {
            if ((int) ($row['OfficerId'] ?? 0) === $officerId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed> $result
     * @return array<string,array<string,mixed>>
     */
    private function markerRows(array $result): array
    {
        $out = [];
        foreach ($result['Officers'] ?? [] as $row) {
            $key = (string) ($row['CanonicalKey'] ?? '');
            if (!str_starts_with($key, self::MARKER . '_')) {
                continue;
            }
            // An unmapped officer row has no canonical_key and falls back to its role,
            // which is the full marker string; keep it under that name.
            $out[substr($key, strlen(self::MARKER) + 1)] = $row;
        }

        return $out;
    }

    private function seedPosition(int $kingdomId, string $slug, string $classification, ?int $parent, int $sortOrder): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_position
                (kingdom_id, canonical_key, title, title_alias, classification, is_pinned, is_system,
                 rbac_role_id, has_auth_role, sort_order, parent_position_id, hide_when_vacant,
                 retired_at, created_by, created_at)
             VALUES
                (:kingdom_id, :canonical_key, :title, \'\', :classification, 0, 0,
                 0, 0, :sort_order, :parent_position_id, 0,
                 NULL, 0, NOW())'
        );
        $stmt->bindValue(':kingdom_id', $kingdomId, PDO::PARAM_INT);
        $stmt->bindValue(':canonical_key', self::MARKER . '_' . $slug);
        $stmt->bindValue(':title', 'Row Shape ' . $slug);
        $stmt->bindValue(':classification', $classification);
        $stmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
        if ($parent === null) {
            $stmt->bindValue(':parent_position_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':parent_position_id', $parent, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function seedOfficer(int $kingdomId, int $parkId, int $positionId, string $slug, int $mundaneId = 0): int
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, position_id, system, authorization_id)
             VALUES (:kid, :pkid, :mid, :role, :pid, 0, 0)'
        )->execute([
            ':kid' => $kingdomId,
            ':pkid' => $parkId,
            ':mid' => $mundaneId,
            ':role' => self::MARKER . '_' . $slug,
            ':pid' => $positionId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedAliasSortOrder(int $kingdomId, string $slug, int $sortOrder): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer_position_alias (kingdom_id, canonical_key, sort_order)
             VALUES (:kid, :key, :so)
             ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)'
        )->execute([
            ':kid' => $kingdomId,
            ':key' => self::MARKER . '_' . $slug,
            ':so' => $sortOrder,
        ]);
    }

    private function rawSortOrderOf(int $positionId): int
    {
        $stmt = $this->pdo->prepare('SELECT sort_order FROM ork_officer_position WHERE position_id = :id');
        $stmt->execute([':id' => $positionId]);

        return (int) $stmt->fetchColumn();
    }

    private function purgeMarkerRows(): void
    {
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "\\_%'");
        $this->pdo->exec("DELETE FROM ork_officer_position_alias WHERE canonical_key LIKE '" . self::MARKER . "\\_%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "\\_%'");
    }
}
