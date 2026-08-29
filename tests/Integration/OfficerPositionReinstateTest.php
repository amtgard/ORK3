<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OfficerPosition::ReinstatePosition() placement.
 *
 * Retire only sets retired_at; it never touches sort_order. Meanwhile the UI
 * never lists retired siblings, so ReorderSiblings() renumbers the group WITHOUT
 * them -- a group that was 10/15/20/30 becomes 10/20/30 with the retired row
 * still sitting at 15. Reinstate then cleared retired_at and nothing else, so the
 * position reappeared wedged between the first and second live siblings, at a
 * slot no one had ever chosen for it. Where exactly it landed depended entirely
 * on what the group's numbers had drifted to since.
 *
 * Reinstate now assigns a fresh sort_order at the END of the row's CURRENT
 * sibling group, reusing the same MAX(effective)+10 measurement CreatePosition
 * makes -- a reinstated position is, positionally, a new arrival in the group.
 *
 * THE SHARED CASE is the one worth reading twice. A kingdom_id = 0 row is read by
 * every kingdom in the game; its per-kingdom order lives in
 * ork_officer_position_alias.sort_order, resolved by SortOrderSql(). Writing
 * officer_position.sort_order for one kingdom's reinstate would silently re-order
 * the officer list for all of them, so the acting kingdom's placement goes in its
 * own alias row instead -- the identical ownership split ReorderSiblings() makes.
 *
 * Rows are seeded with raw INSERTs rather than CreatePosition() so the test does
 * not drag in the RBAC role-creation path it is not exercising. Every seeded row
 * carries the MARKER canonical-key prefix and is removed in tearDown() (not
 * inline after an assertion -- inline cleanup never runs on the failure path,
 * which is exactly when leftover rows poison the next run).
 */
final class OfficerPositionReinstateTest extends TestCase
{
    private const MARKER = 'zzreinstate';

    /** Seeded test-database kingdoms; neither owns any real registry position. */
    private const KINGDOM_ID = 100001;
    private const OTHER_KINGDOM_ID = 100002;

    private PDO $pdo;
    private OfficerPosition $positions;

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
        $this->positions = new OfficerPosition();

        // A parent whose children form ONE closed sibling group. Nesting the group
        // under a parent keeps the real Core Five (shared, top-level) out of the
        // measurement, so every number below comes from rows this test seeded.
        $this->seeded['group'] = $this->seedPosition(self::KINGDOM_ID, 'group', null, 100);

        // The live group, already renumbered by a drag the retired rows missed.
        $this->seeded['sibA'] = $this->seedPosition(self::KINGDOM_ID, 'sib_a', $this->seeded['group'], 10);
        $this->seeded['sibB'] = $this->seedPosition(self::KINGDOM_ID, 'sib_b', $this->seeded['group'], 20);
        $this->seeded['sibC'] = $this->seedPosition(self::KINGDOM_ID, 'sib_c', $this->seeded['group'], 30);

        // A kingdom-owned retired row at 15: it predates the renumbering, so on
        // reinstate it lands BETWEEN sibA and sibB unless placement is deliberate.
        $this->seeded['retiredOwned'] = $this->seedPosition(self::KINGDOM_ID, 'retired_owned', $this->seeded['group'], 15);
        $this->retire($this->seeded['retiredOwned']);

        // A SHARED retired row at 5 -- first in the group for every kingdom that
        // has not overridden it. Its placement cannot be written to the row.
        $this->seeded['retiredShared'] = $this->seedPosition(0, 'retired_shared', $this->seeded['group'], 5);
        $this->retire($this->seeded['retiredShared']);

        // Another kingdom's row, to prove the ownership guard still writes nothing.
        $this->seeded['foreign'] = $this->seedPosition(self::OTHER_KINGDOM_ID, 'foreign', null, 100);
        $this->retire($this->seeded['foreign']);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->purgeMarkerRows();
        }
        $this->seeded = [];
    }

    // ============================================================
    // KINGDOM-OWNED ROW: the order lives on the row
    // ============================================================

    public function testReinstateClearsRetiredAt(): void
    {
        $r = $this->positions->ReinstatePosition($this->seeded['retiredOwned'], self::KINGDOM_ID);

        $this->assertIsArray($r);
        $this->assertSame(0, (int) $r['Status'], 'reinstate should succeed');
        $this->assertNull($this->retiredAtOf($this->seeded['retiredOwned']), 'retired_at must be cleared');
    }

    public function testOwnedRowLandsAtTheEndOfItsGroup(): void
    {
        // Measured independently of the production query: the largest effective
        // order among the group's OTHER members, which the reinstated row must clear.
        $maxBefore = $this->maxEffectiveInGroupExcluding(
            self::KINGDOM_ID,
            $this->seeded['group'],
            $this->seeded['retiredOwned']
        );

        $this->positions->ReinstatePosition($this->seeded['retiredOwned'], self::KINGDOM_ID);

        $this->assertSame(
            $maxBefore + 10,
            $this->rawSortOrderOf($this->seeded['retiredOwned']),
            'an owned row takes MAX(effective in group) + 10, on the row itself'
        );
    }

    public function testOwnedRowIsLastInTheGroupTheKingdomActuallySees(): void
    {
        // The behavioural statement: not a number, but a position in the list.
        $this->positions->ReinstatePosition($this->seeded['retiredOwned'], self::KINGDOM_ID);

        $this->assertSame(
            ['retired_shared', 'sib_a', 'sib_b', 'sib_c', 'retired_owned'],
            $this->groupSequenceFor(self::KINGDOM_ID),
            'the reinstated row must come last, not back at its stale slot'
        );
    }

    public function testAReinstatedRowIsNotMeasuredAgainstItself(): void
    {
        // The retired row happens to hold the group's largest value -- the other way
        // a stale sort_order goes wrong. Counting it in its own MAX would hand it
        // 110: still last, but drifting further out on every retire/reinstate cycle
        // and opening a gap no drag ever created.
        $this->pdo
            ->prepare('UPDATE ork_officer_position SET sort_order = 100 WHERE position_id = :id')
            ->execute([':id' => $this->seeded['retiredOwned']]);

        $this->positions->ReinstatePosition($this->seeded['retiredOwned'], self::KINGDOM_ID);

        $this->assertSame(
            40,
            $this->rawSortOrderOf($this->seeded['retiredOwned']),
            'placement follows the LIVE group max (30), not the row\'s own stale 100'
        );
    }

    public function testOwnedReinstateWritesNoAliasRow(): void
    {
        // The kingdom owns the row outright, so nothing belongs in the alias table.
        $this->positions->ReinstatePosition($this->seeded['retiredOwned'], self::KINGDOM_ID);

        $this->assertNull($this->aliasFor(self::KINGDOM_ID, 'retired_owned'));
    }

    public function testOwnedReinstateLeavesTheRestOfTheGroupAlone(): void
    {
        $this->positions->ReinstatePosition($this->seeded['retiredOwned'], self::KINGDOM_ID);

        $this->assertSame(10, $this->rawSortOrderOf($this->seeded['sibA']));
        $this->assertSame(20, $this->rawSortOrderOf($this->seeded['sibB']));
        $this->assertSame(30, $this->rawSortOrderOf($this->seeded['sibC']));
        $this->assertSame(
            $this->seeded['group'],
            $this->parentOf($this->seeded['retiredOwned']),
            'reinstate must not reparent'
        );
    }

    // ============================================================
    // SHARED ROW: the order lives in the acting kingdom's alias
    // ============================================================

    public function testSharedRowPlacementGoesInTheActingKingdomsAlias(): void
    {
        $maxBefore = $this->maxEffectiveInGroupExcluding(
            self::KINGDOM_ID,
            $this->seeded['group'],
            $this->seeded['retiredShared']
        );

        $r = $this->positions->ReinstatePosition($this->seeded['retiredShared'], self::KINGDOM_ID);

        $this->assertSame(0, (int) $r['Status'], 'reinstate should succeed');
        $alias = $this->aliasFor(self::KINGDOM_ID, 'retired_shared');
        $this->assertNotNull($alias, 'a shared row needs a per-kingdom alias row to hold its placement');
        $this->assertSame($maxBefore + 10, (int) $alias['sort_order']);
    }

    public function testSharedRowsOwnSortOrderIsNeverRewritten(): void
    {
        // The globally destructive write: every kingdom reads this column.
        $this->positions->ReinstatePosition($this->seeded['retiredShared'], self::KINGDOM_ID);

        $this->assertSame(
            5,
            $this->rawSortOrderOf($this->seeded['retiredShared']),
            "one kingdom's reinstate must not re-order the shared row for the whole game"
        );
    }

    public function testAnotherKingdomsOrderIsUntouchedByASharedReinstate(): void
    {
        $this->positions->ReinstatePosition($this->seeded['retiredShared'], self::KINGDOM_ID);

        $this->assertSame(
            5,
            $this->effectiveOrderFor(self::OTHER_KINGDOM_ID, 'retired_shared'),
            'a kingdom that expressed no opinion still sees the shared value'
        );
    }

    public function testSharedRowIsLastInTheActingKingdomsGroup(): void
    {
        $this->positions->ReinstatePosition($this->seeded['retiredShared'], self::KINGDOM_ID);

        // retired_owned is still retired at its stale 15, so it stays mid-list; the
        // reinstated shared row is the one that has to be at the end.
        $this->assertSame(
            ['sib_a', 'retired_owned', 'sib_b', 'sib_c', 'retired_shared'],
            $this->groupSequenceFor(self::KINGDOM_ID),
            'the shared row must come last for the acting kingdom'
        );
    }

    public function testSharedReinstateKeepsAnExistingTitleAlias(): void
    {
        // The alias row is shared with the kingdom's custom TITLE for the position;
        // an upsert that named title_alias would blank it.
        $this->seedAlias(self::KINGDOM_ID, 'retired_shared', 'Court Herald', null);

        $this->positions->ReinstatePosition($this->seeded['retiredShared'], self::KINGDOM_ID);

        $alias = $this->aliasFor(self::KINGDOM_ID, 'retired_shared');
        $this->assertNotNull($alias);
        $this->assertSame('Court Herald', $alias['title_alias'], 'the custom title must survive a reinstate');
        $this->assertNotNull($alias['sort_order'], 'the placement must still have been written');
    }

    public function testSharedReinstateWithNoActingKingdomLeavesOrderAlone(): void
    {
        // With no acting kingdom there is no list to place the row into, and the only
        // column reachable is the shared one -- which must not be written. Clearing
        // retired_at alone is the defensible outcome, not a guess at a placement.
        $r = $this->positions->ReinstatePosition($this->seeded['retiredShared'], 0);

        $this->assertSame(0, (int) $r['Status']);
        $this->assertNull($this->retiredAtOf($this->seeded['retiredShared']), 'retired_at must still be cleared');
        $this->assertSame(5, $this->rawSortOrderOf($this->seeded['retiredShared']));
        $this->assertSame([], $this->aliasRows(), 'no alias row may be invented for an unknown kingdom');
    }

    // ============================================================
    // GUARDS: unchanged, and still write nothing
    // ============================================================

    public function testAnotherKingdomsRowIsRefusedAndNothingIsWritten(): void
    {
        $before = $this->snapshot();

        $r = $this->positions->ReinstatePosition($this->seeded['foreign'], self::KINGDOM_ID);

        $this->assertNotSame(0, (int) $r['Status'], "another kingdom's position must be refused");
        $this->assertNotNull($this->retiredAtOf($this->seeded['foreign']), 'the row must stay retired');
        $this->assertSame($before, $this->snapshot(), 'a refused reinstate must write nothing');
    }

    public function testAMissingPositionIsRefused(): void
    {
        $before = $this->snapshot();

        $r = $this->positions->ReinstatePosition(0, self::KINGDOM_ID);

        $this->assertNotSame(0, (int) $r['Status']);
        $this->assertSame($before, $this->snapshot());
    }

    // ============================================================
    // CreatePosition's own placement is unchanged
    // ============================================================

    public function testAnEmptyGroupStillStartsAtTen(): void
    {
        // The shared measurement returns MAX + 10 over an empty set, i.e. 10 -- the
        // number a brand-new first child gets. Reinstate must not have moved that.
        $this->assertSame(
            10,
            $this->nextSortOrderVia($this->positions, self::KINGDOM_ID, $this->seeded['sibA'])
        );
    }

    public function testANonEmptyGroupContinuesFromItsMax(): void
    {
        $this->assertSame(
            40,
            $this->nextSortOrderVia($this->positions, self::KINGDOM_ID, $this->seeded['group']),
            'the group maxes at 30 (sibC); the next arrival takes 40'
        );
    }

    // ============================================================
    // Helpers
    // ============================================================

    /** The private group-placement helper, reached without going through RBAC. */
    private function nextSortOrderVia(OfficerPosition $positions, int $kingdomId, ?int $parentId): int
    {
        $m = new ReflectionMethod(OfficerPosition::class, 'NextSortOrderInGroup');
        $m->setAccessible(true);

        return (int) $m->invoke($positions, $kingdomId, $parentId, 0);
    }

    /**
     * MAX effective sort_order in a group as $kingdomId sees it, ignoring one row.
     * Written out here rather than borrowed from the class under test so the
     * expectation is an independent statement of the contract.
     */
    private function maxEffectiveInGroupExcluding(int $kingdomId, int $parentId, int $excludeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(IF(p.kingdom_id = 0, IFNULL(a.sort_order, p.sort_order), p.sort_order)) AS mx
             FROM ork_officer_position p
             LEFT JOIN ork_officer_position_alias a
               ON a.kingdom_id = :kid AND a.canonical_key = p.canonical_key
             WHERE (p.kingdom_id = 0 OR p.kingdom_id = :kid2)
               AND p.parent_position_id = :parent
               AND p.position_id != :exclude'
        );
        $stmt->execute([':kid' => $kingdomId, ':kid2' => $kingdomId, ':parent' => $parentId, ':exclude' => $excludeId]);

        return (int) $stmt->fetchColumn();
    }

    /** The seeded group's slugs in the order $kingdomId's registry read returns them. */
    private function groupSequenceFor(int $kingdomId): array
    {
        $out = [];
        foreach ($this->positions->GetPositions($kingdomId, true) as $row) {
            $key = (string) ($row['CanonicalKey'] ?? '');
            if (!str_starts_with($key, self::MARKER . '_')) {
                continue;
            }
            $slug = substr($key, strlen(self::MARKER) + 1);
            if ($slug === 'group' || $slug === 'foreign') {
                continue; // the parent itself, and another kingdom's row
            }
            $out[] = $slug;
        }

        return $out;
    }

    /** Effective SortOrder for one seeded slug as $kingdomId sees it. */
    private function effectiveOrderFor(int $kingdomId, string $slug): int
    {
        foreach ($this->positions->GetPositions($kingdomId, true) as $row) {
            if ((string) ($row['CanonicalKey'] ?? '') === self::MARKER . '_' . $slug) {
                return (int) $row['SortOrder'];
            }
        }

        return -1;
    }

    private function seedPosition(int $kingdomId, string $slug, ?int $parent, int $sortOrder): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_position
                (kingdom_id, canonical_key, title, title_alias, classification, is_pinned, is_system,
                 rbac_role_id, has_auth_role, sort_order, parent_position_id, hide_when_vacant,
                 retired_at, created_by, created_at)
             VALUES
                (:kingdom_id, :canonical_key, :title, \'\', \'supporting\', 0, 0,
                 0, 0, :sort_order, :parent_position_id, 0,
                 NULL, 0, NOW())'
        );
        $stmt->bindValue(':kingdom_id', $kingdomId, PDO::PARAM_INT);
        $stmt->bindValue(':canonical_key', self::MARKER . '_' . $slug);
        $stmt->bindValue(':title', 'Reinstate ' . $slug);
        $stmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
        if ($parent === null) {
            $stmt->bindValue(':parent_position_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':parent_position_id', $parent, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function retire(int $positionId): void
    {
        $this->pdo
            ->prepare('UPDATE ork_officer_position SET retired_at = NOW() WHERE position_id = :id')
            ->execute([':id' => $positionId]);
    }

    private function seedAlias(int $kingdomId, string $slug, string $titleAlias, ?int $sortOrder): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_position_alias (kingdom_id, canonical_key, title_alias, sort_order)
             VALUES (:kid, :key, :ta, :so)
             ON DUPLICATE KEY UPDATE title_alias = VALUES(title_alias), sort_order = VALUES(sort_order)'
        );
        $stmt->bindValue(':kid', $kingdomId, PDO::PARAM_INT);
        $stmt->bindValue(':key', self::MARKER . '_' . $slug);
        $stmt->bindValue(':ta', $titleAlias);
        if ($sortOrder === null) {
            $stmt->bindValue(':so', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':so', $sortOrder, PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    private function rawSortOrderOf(int $positionId): int
    {
        $stmt = $this->pdo->prepare('SELECT sort_order FROM ork_officer_position WHERE position_id = :id');
        $stmt->execute([':id' => $positionId]);

        return (int) $stmt->fetchColumn();
    }

    private function retiredAtOf(int $positionId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT retired_at FROM ork_officer_position WHERE position_id = :id');
        $stmt->execute([':id' => $positionId]);
        $value = $stmt->fetchColumn();

        return ($value === null || $value === false) ? null : (string) $value;
    }

    private function parentOf(int $positionId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT parent_position_id FROM ork_officer_position WHERE position_id = :id');
        $stmt->execute([':id' => $positionId]);
        $value = $stmt->fetchColumn();

        return ($value === null || $value === false || $value === '') ? null : (int) $value;
    }

    /** All alias rows for the marker keys, ordered. */
    private function aliasRows(): array
    {
        $sql = "SELECT kingdom_id, canonical_key, title_alias, sort_order
                FROM ork_officer_position_alias
                WHERE canonical_key LIKE '" . self::MARKER . "\\_%'
                ORDER BY kingdom_id, canonical_key";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function aliasFor(int $kingdomId, string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT title_alias, sort_order FROM ork_officer_position_alias
             WHERE kingdom_id = :kid AND canonical_key = :key'
        );
        $stmt->execute([':kid' => $kingdomId, ':key' => self::MARKER . '_' . $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Everything a reinstate is allowed to touch, across both tables. */
    private function snapshot(): array
    {
        $out = [];
        foreach ($this->seeded as $label => $pid) {
            $out[$label] = [$this->rawSortOrderOf($pid), $this->parentOf($pid), $this->retiredAtOf($pid)];
        }
        $out['__alias'] = $this->aliasRows();

        return $out;
    }

    private function purgeMarkerRows(): void
    {
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "\\_%'");
        $this->pdo->exec("DELETE FROM ork_officer_position_alias WHERE canonical_key LIKE '" . self::MARKER . "\\_%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "\\_%'");
    }
}
