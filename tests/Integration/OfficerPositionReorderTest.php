<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OfficerPosition::ReorderSiblings() + Controller_OfficerAdminAjax reorderpositions.
 *
 * Reordering a sibling group used to mean N sequential editposition calls, each
 * writing one sort_order; a failure halfway left the list scrambled with no way to
 * tell how far it got. ReorderSiblings() renumbers the whole group in one
 * validate-everything-then-write-once call.
 *
 * The validation half is the part worth testing hardest: the endpoint takes a
 * group key (ParentPositionId) and a list of ids, so a caller that puts an id in
 * the wrong group -- a different kingdom's position, or a position whose real
 * parent is something else -- must be rejected with NOTHING written, or the
 * endpoint becomes an unguarded reparent/cross-kingdom-write primitive.
 *
 * Rows are seeded with raw INSERTs rather than CreatePosition() so the test does
 * not drag in the RBAC role-creation path it is not exercising. Every seeded row
 * carries the MARKER canonical-key prefix and is removed in tearDown() (not
 * inline after an assertion -- inline cleanup never runs on the failure path,
 * which is exactly when leftover rows poison the next run).
 */
final class OfficerPositionReorderTest extends TestCase
{
    private const MARKER = 'zzreorder';
    private const KINGDOM_ID = 1;
    private const OTHER_KINGDOM_ID = 2;

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

        // One parent with three children, all sharing sort_order 100. Equal (or
        // duplicate) sort_order values are exactly the state a swap-two-values
        // implementation cannot recover from, which is why ReorderSiblings
        // renumbers the group outright.
        $this->seeded['parent'] = $this->seedPosition(self::KINGDOM_ID, 'parent', null, 100);
        $this->seeded['childA'] = $this->seedPosition(self::KINGDOM_ID, 'child_a', $this->seeded['parent'], 100);
        $this->seeded['childB'] = $this->seedPosition(self::KINGDOM_ID, 'child_b', $this->seeded['parent'], 100);
        $this->seeded['childC'] = $this->seedPosition(self::KINGDOM_ID, 'child_c', $this->seeded['parent'], 100);

        // Two more top-level rows in the same kingdom (siblings of 'parent').
        $this->seeded['topA'] = $this->seedPosition(self::KINGDOM_ID, 'top_a', null, 700);
        $this->seeded['topB'] = $this->seedPosition(self::KINGDOM_ID, 'top_b', null, 800);

        // A foreign kingdom's row, top-level in ITS kingdom.
        $this->seeded['foreign'] = $this->seedPosition(self::OTHER_KINGDOM_ID, 'foreign', null, 100);

        // Three SHARED rows (kingdom_id = 0), top-level, visible to every kingdom --
        // stand-ins for the Core Five. The real Core Five are deliberately left
        // alone: the whole point of the per-kingdom override is that reordering
        // shared rows must not mutate a row every kingdom reads.
        $this->seeded['sharedA'] = $this->seedPosition(0, 'shared_a', null, 100);
        $this->seeded['sharedB'] = $this->seedPosition(0, 'shared_b', null, 200);
        $this->seeded['sharedC'] = $this->seedPosition(0, 'shared_c', null, 300);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        if (isset($this->pdo)) {
            $this->purgeMarkerRows();
        }
        $this->seeded = [];
    }

    // ============================================================
    // DOMAIN: OfficerPosition::ReorderSiblings()
    // ============================================================

    public function testHappyPathRenumbersGroupInGivenOrder(): void
    {
        $order = [$this->seeded['childC'], $this->seeded['childA'], $this->seeded['childB']];

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, $this->seeded['parent'], $order);

        $this->assertIsArray($r);
        $this->assertSame(0, (int) $r['Status'], 'reorder should succeed');
        $this->assertSame(10, $this->sortOrderOf($this->seeded['childC']));
        $this->assertSame(20, $this->sortOrderOf($this->seeded['childA']));
        $this->assertSame(30, $this->sortOrderOf($this->seeded['childB']));
    }

    public function testHappyPathLeavesParentageUntouched(): void
    {
        $order = [$this->seeded['childB'], $this->seeded['childC'], $this->seeded['childA']];

        $this->positions->ReorderSiblings(self::KINGDOM_ID, $this->seeded['parent'], $order);

        foreach (['childA', 'childB', 'childC'] as $label) {
            $this->assertSame(
                $this->seeded['parent'],
                $this->parentOf($this->seeded[$label]),
                $label . ' must keep its parent'
            );
        }
    }

    public function testTopLevelReorderUsesParentZero(): void
    {
        $order = [$this->seeded['topB'], $this->seeded['topA'], $this->seeded['parent']];

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, 0, $order);

        $this->assertSame(0, (int) $r['Status'], 'top-level reorder should succeed');
        $this->assertSame(10, $this->sortOrderOf($this->seeded['topB']));
        $this->assertSame(20, $this->sortOrderOf($this->seeded['topA']));
        $this->assertSame(30, $this->sortOrderOf($this->seeded['parent']));
        $this->assertNull($this->parentOf($this->seeded['topB']));
        $this->assertNull($this->parentOf($this->seeded['parent']));
    }

    public function testForeignKingdomIdIsRejectedAndWritesNothing(): void
    {
        $before = $this->snapshot();
        $order = [$this->seeded['topA'], $this->seeded['foreign'], $this->seeded['topB']];

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, 0, $order);

        $this->assertNotSame(0, (int) $r['Status'], "a foreign kingdom's position must be rejected");
        $this->assertSame($before, $this->snapshot(), 'a rejected reorder must write nothing');
    }

    public function testWrongParentIsRejectedAndWritesNothing(): void
    {
        // topA is top-level; claiming it is a child of 'parent' would reparent it.
        $before = $this->snapshot();
        $order = [$this->seeded['childA'], $this->seeded['topA'], $this->seeded['childB']];

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, $this->seeded['parent'], $order);

        $this->assertNotSame(0, (int) $r['Status'], 'an id from another group must be rejected');
        $this->assertSame($before, $this->snapshot(), 'a rejected reorder must write nothing');
        $this->assertNull($this->parentOf($this->seeded['topA']), 'topA must not have been reparented');
    }

    public function testChildClaimedAsTopLevelIsRejectedAndWritesNothing(): void
    {
        // The mirror of the case above: a real child passed into the parent-0 group.
        $before = $this->snapshot();
        $order = [$this->seeded['topA'], $this->seeded['childA']];

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, 0, $order);

        $this->assertNotSame(0, (int) $r['Status'], 'a nested position must not reorder as top-level');
        $this->assertSame($before, $this->snapshot(), 'a rejected reorder must write nothing');
        $this->assertSame($this->seeded['parent'], $this->parentOf($this->seeded['childA']));
    }

    public function testNonexistentIdIsRejectedAndWritesNothing(): void
    {
        $before = $this->snapshot();
        $ghost = (int) $this->pdo->query('SELECT MAX(position_id) FROM ork_officer_position')->fetchColumn() + 5000;
        $order = [$this->seeded['childA'], $ghost, $this->seeded['childB']];

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, $this->seeded['parent'], $order);

        $this->assertNotSame(0, (int) $r['Status'], 'an id that does not exist must be rejected');
        $this->assertSame($before, $this->snapshot(), 'a rejected reorder must write nothing');
    }

    public function testDuplicateIdIsRejectedAndWritesNothing(): void
    {
        $before = $this->snapshot();
        $order = [$this->seeded['childA'], $this->seeded['childB'], $this->seeded['childA']];

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, $this->seeded['parent'], $order);

        $this->assertNotSame(0, (int) $r['Status'], 'a repeated id must be rejected');
        $this->assertSame($before, $this->snapshot(), 'a rejected reorder must write nothing');
    }

    public function testEmptyListIsRejected(): void
    {
        $before = $this->snapshot();

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, $this->seeded['parent'], []);

        $this->assertNotSame(0, (int) $r['Status'], 'an empty order must be rejected');
        $this->assertSame($before, $this->snapshot(), 'a rejected reorder must write nothing');
    }

    public function testMissingKingdomIsRejected(): void
    {
        $before = $this->snapshot();

        $r = $this->positions->ReorderSiblings(0, $this->seeded['parent'], [$this->seeded['childA']]);

        $this->assertNotSame(0, (int) $r['Status'], 'kingdom_id 0 must be rejected');
        $this->assertSame($before, $this->snapshot(), 'a rejected reorder must write nothing');
    }

    // ============================================================
    // PER-KINGDOM ORDER OF SHARED (kingdom_id = 0) ROWS
    // ============================================================

    public function testReorderingSharedRowsWritesAPerKingdomOverrideNotTheSharedRow(): void
    {
        $order = [$this->seeded['sharedC'], $this->seeded['sharedA'], $this->seeded['sharedB']];

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, 0, $order);

        $this->assertSame(0, (int) $r['Status']);

        // The SHARED rows themselves must be byte-for-byte unchanged: every other
        // kingdom reads them.
        $this->assertSame(100, $this->sortOrderOf($this->seeded['sharedA']));
        $this->assertSame(200, $this->sortOrderOf($this->seeded['sharedB']));
        $this->assertSame(300, $this->sortOrderOf($this->seeded['sharedC']));

        // The new order lives in the acting kingdom's alias rows.
        $this->assertSame(20, (int) $this->aliasFor(self::KINGDOM_ID, 'sharedA')['sort_order']);
        $this->assertSame(30, (int) $this->aliasFor(self::KINGDOM_ID, 'sharedB')['sort_order']);
        $this->assertSame(10, (int) $this->aliasFor(self::KINGDOM_ID, 'sharedC')['sort_order']);
    }

    public function testSharedReorderByOneKingdomDoesNotChangeAnotherKingdomsView(): void
    {
        // THE point of the whole change. Kingdom A drags its crown officers into a
        // new order; kingdom B must see exactly what it saw before.
        $beforeB = $this->effectiveSequenceFor(self::OTHER_KINGDOM_ID, ['sharedA', 'sharedB', 'sharedC']);
        $this->assertSame(['sharedA', 'sharedB', 'sharedC'], $beforeB, 'precondition: B sees the shared order');

        $this->positions->ReorderSiblings(
            self::KINGDOM_ID,
            0,
            [$this->seeded['sharedC'], $this->seeded['sharedB'], $this->seeded['sharedA']]
        );

        $this->assertSame(
            ['sharedC', 'sharedB', 'sharedA'],
            $this->effectiveSequenceFor(self::KINGDOM_ID, ['sharedA', 'sharedB', 'sharedC']),
            'the acting kingdom must see its new order'
        );
        $this->assertSame(
            ['sharedA', 'sharedB', 'sharedC'],
            $this->effectiveSequenceFor(self::OTHER_KINGDOM_ID, ['sharedA', 'sharedB', 'sharedC']),
            "another kingdom's order must be untouched"
        );
    }

    public function testOverrideWinsForItsOwnKingdomOnly(): void
    {
        $this->positions->ReorderSiblings(
            self::KINGDOM_ID,
            0,
            [$this->seeded['sharedC'], $this->seeded['sharedA'], $this->seeded['sharedB']]
        );

        $this->assertSame(
            ['sharedA' => 20, 'sharedB' => 30, 'sharedC' => 10],
            $this->effectiveOrderFor(self::KINGDOM_ID, ['sharedA', 'sharedB', 'sharedC']),
            'the emitted SortOrder must be the EFFECTIVE value, not the shared row value'
        );
        $this->assertSame(
            ['sharedA' => 100, 'sharedB' => 200, 'sharedC' => 300],
            $this->effectiveOrderFor(self::OTHER_KINGDOM_ID, ['sharedA', 'sharedB', 'sharedC']),
            'a kingdom with no override still reads the shared values'
        );
    }

    public function testKingdomWithNoOverrideSeesTheSharedOrder(): void
    {
        // No reorder has happened at all: both kingdoms read the shared row.
        $expected = ['sharedA' => 100, 'sharedB' => 200, 'sharedC' => 300];
        $this->assertSame($expected, $this->effectiveOrderFor(self::KINGDOM_ID, ['sharedA', 'sharedB', 'sharedC']));
        $this->assertSame($expected, $this->effectiveOrderFor(self::OTHER_KINGDOM_ID, ['sharedA', 'sharedB', 'sharedC']));
        $this->assertSame([], $this->aliasRows(), 'no override row should exist yet');
    }

    public function testExistingTitleAliasSurvivesAReorder(): void
    {
        // uq_kingdom_canonical means the title alias and the sort override share ONE
        // row. A drag must not blank a kingdom's custom title as a side effect.
        $this->pdo->prepare(
            'INSERT INTO ork_officer_position_alias (kingdom_id, canonical_key, title_alias)
             VALUES (:kid, :key, :alias)'
        )->execute([
            ':kid' => self::KINGDOM_ID,
            ':key' => self::MARKER . '_shared_a',
            ':alias' => 'Sovereign of Sorting',
        ]);

        $this->positions->ReorderSiblings(
            self::KINGDOM_ID,
            0,
            [$this->seeded['sharedB'], $this->seeded['sharedA']]
        );

        $alias = $this->aliasFor(self::KINGDOM_ID, 'sharedA');
        $this->assertSame('Sovereign of Sorting', $alias['title_alias'], 'the custom title must survive the drag');
        $this->assertSame(20, (int) $alias['sort_order']);

        $titles = [];
        foreach ($this->positions->GetPositions(self::KINGDOM_ID, true) as $row) {
            $titles[$row['CanonicalKey']] = $row['DisplayTitle'];
        }
        $this->assertSame('Sovereign of Sorting', $titles[self::MARKER . '_shared_a']);
    }

    public function testClearingATitleAliasKeepsTheSortOverride(): void
    {
        // EditPosition used to DELETE the alias row when a kingdom cleared its custom
        // title. With the sort override living on that same row, that delete would
        // silently reset the kingdom's order.
        $this->positions->ReorderSiblings(
            self::KINGDOM_ID,
            0,
            [$this->seeded['sharedB'], $this->seeded['sharedA']]
        );
        $this->positions->EditPosition(
            $this->seeded['sharedA'],
            ['title_alias' => 'Temporary Name', 'changed_by' => 0],
            self::KINGDOM_ID
        );
        $this->positions->EditPosition(
            $this->seeded['sharedA'],
            ['title_alias' => '', 'changed_by' => 0],
            self::KINGDOM_ID
        );

        $alias = $this->aliasFor(self::KINGDOM_ID, 'sharedA');
        $this->assertNotNull($alias, 'clearing the title must not drop the sort override row');
        $this->assertSame('', $alias['title_alias']);
        $this->assertSame(20, (int) $alias['sort_order']);
    }

    public function testClearingATitleAliasWithNoOverrideStillRemovesTheRow(): void
    {
        // The flip side: a row that carries nothing at all should not linger.
        $this->positions->EditPosition(
            $this->seeded['sharedA'],
            ['title_alias' => 'Temporary Name', 'changed_by' => 0],
            self::KINGDOM_ID
        );
        $this->assertNotNull($this->aliasFor(self::KINGDOM_ID, 'sharedA'));

        $this->positions->EditPosition(
            $this->seeded['sharedA'],
            ['title_alias' => '', 'changed_by' => 0],
            self::KINGDOM_ID
        );

        $this->assertNull($this->aliasFor(self::KINGDOM_ID, 'sharedA'));
    }

    public function testSingleRowSortOrderEditOnASharedRowIsAlsoPerKingdom(): void
    {
        // EditPosition's own sort_order write is the same hazard as the batch path:
        // it must not write a globally shared row either.
        $r = $this->positions->EditPosition(
            $this->seeded['sharedA'],
            ['sort_order' => 77, 'changed_by' => 0],
            self::KINGDOM_ID
        );

        $this->assertSame(0, (int) $r['Status']);
        $this->assertSame(100, $this->sortOrderOf($this->seeded['sharedA']), 'the shared row must be untouched');
        $this->assertSame(77, (int) $this->aliasFor(self::KINGDOM_ID, 'sharedA')['sort_order']);
        $this->assertSame(
            ['sharedA' => 100],
            $this->effectiveOrderFor(self::OTHER_KINGDOM_ID, ['sharedA'])
        );
    }

    public function testSingleRowSortOrderEditOnAnOwnedRowStillWritesTheRow(): void
    {
        $r = $this->positions->EditPosition(
            $this->seeded['topA'],
            ['sort_order' => 55, 'changed_by' => 0],
            self::KINGDOM_ID
        );

        $this->assertSame(0, (int) $r['Status']);
        $this->assertSame(55, $this->sortOrderOf($this->seeded['topA']));
        $this->assertSame([], $this->aliasRows(), 'an owned row must not grow an alias override');
    }

    public function testMixedGroupOfSharedAndOwnedSiblingsRenumbersCoherently(): void
    {
        // A top-level group holding both a shared row and a kingdom-owned row has to
        // come back interleaved exactly as dragged, even though the two halves are
        // stored in different tables.
        $order = [
            $this->seeded['sharedB'],
            $this->seeded['topA'],
            $this->seeded['sharedA'],
            $this->seeded['topB'],
            $this->seeded['sharedC'],
        ];

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, 0, $order);

        $this->assertSame(0, (int) $r['Status']);
        $this->assertSame(
            ['sharedB', 'topA', 'sharedA', 'topB', 'sharedC'],
            $this->effectiveSequenceFor(self::KINGDOM_ID, ['sharedA', 'sharedB', 'sharedC', 'topA', 'topB'])
        );
        // Owned rows moved on the row; shared rows moved only for this kingdom.
        $this->assertSame(20, $this->sortOrderOf($this->seeded['topA']));
        $this->assertSame(40, $this->sortOrderOf($this->seeded['topB']));
        $this->assertSame(100, $this->sortOrderOf($this->seeded['sharedA']));
        $this->assertSame(
            ['sharedA', 'sharedB', 'sharedC'],
            $this->effectiveSequenceFor(self::OTHER_KINGDOM_ID, ['sharedA', 'sharedB', 'sharedC'])
        );
    }

    public function testARejectedMixedReorderWritesNeitherTable(): void
    {
        $before = $this->snapshot();
        $order = [$this->seeded['sharedA'], $this->seeded['topA'], $this->seeded['foreign']];

        $r = $this->positions->ReorderSiblings(self::KINGDOM_ID, 0, $order);

        $this->assertNotSame(0, (int) $r['Status']);
        $this->assertSame($before, $this->snapshot(), 'neither officer_position nor the alias table may change');
        $this->assertSame([], $this->aliasRows());
    }

    public function testKingdomOfficerListHonoursThePerKingdomOverride(): void
    {
        // Kingdom::buildOfficerRows() is the read the public officer list and the
        // profile sidebars go through -- a different query from GetPositions(), with
        // its own ORDER BY, so it needs its own proof.
        global $DB;

        foreach (['sharedA', 'sharedB', 'sharedC'] as $label) {
            $this->seedOfficer(self::KINGDOM_ID, $this->seeded[$label], $this->slugOf($label));
            $this->seedOfficer(self::OTHER_KINGDOM_ID, $this->seeded[$label], $this->slugOf($label));
        }

        $this->positions->ReorderSiblings(
            self::KINGDOM_ID,
            0,
            [$this->seeded['sharedC'], $this->seeded['sharedB'], $this->seeded['sharedA']]
        );

        $this->assertSame(
            ['shared_c', 'shared_b', 'shared_a'],
            $this->officerListKeys($DB, self::KINGDOM_ID),
            'the acting kingdom\'s officer list must follow its override'
        );
        $this->assertSame(
            ['shared_a', 'shared_b', 'shared_c'],
            $this->officerListKeys($DB, self::OTHER_KINGDOM_ID),
            "another kingdom's officer list must be untouched"
        );
    }

    // ============================================================
    // CONTROLLER: OfficerAdminAjax reorderpositions
    // ============================================================

    public function testControllerAcceptsCommaSeparatedOrder(): void
    {
        $_POST = [
            'ParentPositionId' => (string) $this->seeded['parent'],
            'Order' => implode(',', [$this->seeded['childC'], $this->seeded['childB'], $this->seeded['childA']]),
        ];

        $payload = $this->callControllerAction();

        $this->assertSame(0, $payload['status'], json_encode($payload));
        $this->assertSame(
            [$this->seeded['childC'], $this->seeded['childB'], $this->seeded['childA']],
            $payload['data']['Order']
        );
        $this->assertSame(10, $this->sortOrderOf($this->seeded['childC']));
        $this->assertSame(20, $this->sortOrderOf($this->seeded['childB']));
        $this->assertSame(30, $this->sortOrderOf($this->seeded['childA']));
    }

    public function testControllerAcceptsArrayOrder(): void
    {
        $_POST = [
            'ParentPositionId' => '0',
            'Order' => [(string) $this->seeded['topB'], (string) $this->seeded['topA']],
        ];

        $payload = $this->callControllerAction();

        $this->assertSame(0, $payload['status'], json_encode($payload));
        $this->assertSame(10, $this->sortOrderOf($this->seeded['topB']));
        $this->assertSame(20, $this->sortOrderOf($this->seeded['topA']));
    }

    public function testControllerRejectsEmptyOrder(): void
    {
        $before = $this->snapshot();
        $_POST = ['ParentPositionId' => (string) $this->seeded['parent'], 'Order' => ' , ,'];

        $payload = $this->callControllerAction();

        $this->assertNotSame(0, $payload['status']);
        $this->assertArrayHasKey('error', $payload);
        $this->assertSame($before, $this->snapshot());
    }

    public function testControllerSurfacesDomainRejection(): void
    {
        $before = $this->snapshot();
        $_POST = [
            'ParentPositionId' => (string) $this->seeded['parent'],
            'Order' => implode(',', [$this->seeded['childA'], $this->seeded['foreign']]),
        ];

        $payload = $this->callControllerAction();

        $this->assertNotSame(0, $payload['status']);
        $this->assertSame($before, $this->snapshot());
    }

    public function testReorderPositionsIsGatedLikeTheOtherPositionActions(): void
    {
        // The gate lives in a per-action map in officer(), not inside each action
        // method, so the guarantee to test is that the new action is IN that map
        // with the same permission key the other position-management actions use.
        $src = file_get_contents(DIR_UI . 'controller/controller.OfficerAdminAjax.php');
        $this->assertMatchesRegularExpression(
            "/'reorderpositions'\s*=>\s*'kingdom\.officer\.position\.manage'/",
            (string) $src,
            'reorderpositions must sit in the same permission gate map as editposition/reclassify'
        );
        $this->assertMatchesRegularExpression(
            "/case 'reorderpositions':/",
            (string) $src,
            'reorderpositions must be dispatched from the action switch'
        );
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /** Invoke the private controller action without running Controller::__construct(). */
    private function callControllerAction(): array
    {
        require_once DIR_UI . 'model/model.OfficerPosition.php';
        require_once DIR_UI . 'controller/controller.OfficerAdminAjax.php';

        $rc = new ReflectionClass('Controller_OfficerAdminAjax');
        $controller = $rc->newInstanceWithoutConstructor();
        $controller->OfficerPosition = new Model_OfficerPosition();

        $method = $rc->getMethod('actionReorderPositions');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($controller, self::KINGDOM_ID, 0);
        $out = (string) ob_get_clean();

        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'action must echo a JSON object, got: ' . $out);

        return $decoded;
    }

    /** One vacant ork_officer row so buildOfficerRows() has something to return. */
    private function seedOfficer(int $kingdomId, int $positionId, string $slug): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, position_id, system, authorization_id)
             VALUES (:kid, 0, 0, :role, :pid, 0, 0)'
        )->execute([
            ':kid' => $kingdomId,
            ':role' => self::MARKER . '_' . $slug,
            ':pid' => $positionId,
        ]);
    }

    /** Marker canonical keys in the order Kingdom::buildOfficerRows() returns them. */
    private function officerListKeys($db, int $kingdomId): array
    {
        $db->Clear();
        $result = Kingdom::buildOfficerRows(
            $db,
            (string) $kingdomId,
            'o.kingdom_id = ' . $kingdomId . ' and o.park_id = 0',
            0,
            false
        );
        $out = [];
        foreach ($result['Officers'] ?? [] as $row) {
            $key = (string) ($row['CanonicalKey'] ?? '');
            if (str_starts_with($key, self::MARKER . '_')) {
                $out[] = substr($key, strlen(self::MARKER) + 1);
            }
        }

        return $out;
    }

    private function seedPosition(int $kingdomId, string $slug, ?int $parent, int $sortOrder): int
    {
        $key = self::MARKER . '_' . $slug;
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
        $stmt->bindValue(':canonical_key', $key);
        $stmt->bindValue(':title', 'Reorder ' . $slug);
        $stmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
        if ($parent === null) {
            $stmt->bindValue(':parent_position_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':parent_position_id', $parent, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function sortOrderOf(int $positionId): int
    {
        $stmt = $this->pdo->prepare('SELECT sort_order FROM ork_officer_position WHERE position_id = :id');
        $stmt->execute([':id' => $positionId]);

        return (int) $stmt->fetchColumn();
    }

    private function parentOf(int $positionId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT parent_position_id FROM ork_officer_position WHERE position_id = :id');
        $stmt->execute([':id' => $positionId]);
        $value = $stmt->fetchColumn();

        return ($value === null || $value === false || $value === '') ? null : (int) $value;
    }

    /**
     * Everything a reorder is allowed to touch: the seeded rows' own sort_order
     * and parent, PLUS every alias row for the marker keys. A rejected reorder
     * must leave both tables untouched, so "writes nothing" has to mean nothing
     * in the alias table either.
     */
    private function snapshot(): array
    {
        $out = [];
        foreach ($this->seeded as $label => $pid) {
            $out[$label] = [$this->sortOrderOf($pid), $this->parentOf($pid)];
        }
        $out['__alias'] = $this->aliasRows();

        return $out;
    }

    /** All ork_officer_position_alias rows for the marker keys, ordered. */
    private function aliasRows(): array
    {
        $sql = "SELECT kingdom_id, canonical_key, title_alias, sort_order
                FROM ork_officer_position_alias
                WHERE canonical_key LIKE '" . self::MARKER . "\\_%'
                ORDER BY kingdom_id, canonical_key";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The alias row for one kingdom + seeded position, or null. */
    private function aliasFor(int $kingdomId, string $label): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT title_alias, sort_order FROM ork_officer_position_alias
             WHERE kingdom_id = :kid AND canonical_key = :key'
        );
        $stmt->execute([':kid' => $kingdomId, ':key' => self::MARKER . '_' . $this->slugOf($label)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function slugOf(string $label): string
    {
        $map = [
            'sharedA' => 'shared_a', 'sharedB' => 'shared_b', 'sharedC' => 'shared_c',
            'topA' => 'top_a', 'topB' => 'top_b', 'childA' => 'child_a',
            'childB' => 'child_b', 'childC' => 'child_c', 'parent' => 'parent',
            'foreign' => 'foreign',
        ];

        return $map[$label];
    }

    /** Effective SortOrder for a seeded position as $kingdomId sees it, via GetPositions(). */
    private function effectiveOrderFor(int $kingdomId, array $labels): array
    {
        $wanted = [];
        foreach ($labels as $label) {
            $wanted[self::MARKER . '_' . $this->slugOf($label)] = $label;
        }
        $out = [];
        foreach ($this->positions->GetPositions($kingdomId, true) as $row) {
            $key = $row['CanonicalKey'] ?? '';
            if (isset($wanted[$key])) {
                $out[$wanted[$key]] = (int) $row['SortOrder'];
            }
        }
        ksort($out); // values, not sequence -- effectiveSequenceFor() asserts order

        return $out;
    }

    /** The seeded labels in the order GetPositions() returns them for $kingdomId. */
    private function effectiveSequenceFor(int $kingdomId, array $labels): array
    {
        $wanted = [];
        foreach ($labels as $label) {
            $wanted[self::MARKER . '_' . $this->slugOf($label)] = $label;
        }
        $out = [];
        foreach ($this->positions->GetPositions($kingdomId, true) as $row) {
            $key = $row['CanonicalKey'] ?? '';
            if (isset($wanted[$key])) {
                $out[] = $wanted[$key];
            }
        }

        return $out;
    }

    private function purgeMarkerRows(): void
    {
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "\\_%'");
        $this->pdo->exec("DELETE FROM ork_officer_position_alias WHERE canonical_key LIKE '" . self::MARKER . "\\_%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "\\_%'");
    }
}
