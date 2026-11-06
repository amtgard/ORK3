<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/RbacRoleFixture.php';

/**
 * How an ork_user_role row's SCOPE is read, and who that scope reaches.
 *
 * The defect this locks down: CheckPermissionDirect() matched a non-global scope on a
 * single column (`ur.kingdom_id = N`), while sync_officer_role() writes a PARK officer's
 * assignment with BOTH kingdom_id and park_id populated -- 2,369 such rows exist, and
 * zero park-only rows do. Every park officer therefore satisfied every kingdom.* check
 * in their kingdom, which is precisely the park-to-kingdom escalation the role model
 * exists to prevent (the legacy HasAuthority(AUTH_KINGDOM) never resolved upward from a
 * park grant). The rule is now: a row is read by its NARROWEST non-zero scope column, so
 * park_id > 0 is a park grant whatever kingdom_id says.
 *
 * Pinning every non-target column to zero -- the obvious fix -- would have broken the
 * same rows' PARK permissions, since a park officer's row is not park-only either. That
 * is the second half of what these tests hold in place.
 *
 * Also covered: GranterPermissionKeysAtScope(), the escalation guard's aggregate fast
 * path, must resolve identically to HasPermission() or it becomes a way to grant what
 * you do not hold; the ban check the fast path skipped; the parent_kingdom_id traversal
 * Authorization::HasAuthority() has always done and role delegation did not; and the
 * all-zero (global) row that RevokeRole() could never revoke.
 */
final class RbacScopeIsolationTest extends TestCase
{
    private const MARKER = 'zzrbacscope';

    private const PARENT_KINGDOM_ID = 100081;
    private const CHILD_KINGDOM_ID  = 100082;   // a principality of PARENT
    private const PARK_ID           = 100083;   // in PARENT
    private const OTHER_PARK_ID     = 100084;   // in PARENT
    private const CHILD_PARK_ID     = 100085;   // in CHILD

    private const KINGDOM_KEY = 'kingdom.details.edit';
    private const PARK_KEY    = 'park.attendance.manage';
    private const PLAYER_KEY  = 'player.edit';

    private PDO $pdo;
    private RBACService $rbac;

    /**
     * The shared RBAC seeder. This suite hand-rolled the same 14-column ork_mundane
     * INSERT, role-with-exactly-these-permissions block and ork_user_role write that
     * RbacRoleFixture exists to own -- including a copy of the permission-count guard
     * that keeps a typo'd key from making every assertion below vacuously true.
     * Only the kingdom/park org units, which the fixture does not seed, stay local.
     */
    private RbacRoleFixture $fixture;

    private int $roleId = 0;
    private int $parkOfficerId = 0;
    private int $kingdomOfficerId = 0;
    private int $childOfficerId = 0;

    /** Seeded ids, kept locally only so tearDown can bust their permission caches. */
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
        $this->rbac = Ork3::$Lib->rbacservice;
        $this->fixture = new RbacRoleFixture($this->pdo, self::MARKER, self::PARENT_KINGDOM_ID);

        $this->seedKingdom(self::PARENT_KINGDOM_ID, 0);
        $this->seedKingdom(self::CHILD_KINGDOM_ID, self::PARENT_KINGDOM_ID);
        $this->seedPark(self::PARK_ID, self::PARENT_KINGDOM_ID);
        $this->seedPark(self::OTHER_PARK_ID, self::PARENT_KINGDOM_ID);
        $this->seedPark(self::CHILD_PARK_ID, self::CHILD_KINGDOM_ID);

        $this->roleId = $this->fixture->seedRoleWith(
            [self::KINGDOM_KEY, self::PARK_KEY, self::PLAYER_KEY],
            'kingdom',
            self::PARENT_KINGDOM_ID
        );

        // The shape sync_officer_role() writes for a PARK officer: kingdom_id records
        // which kingdom the park sits in, park_id is the actual reach of the grant.
        $this->parkOfficerId = $this->seedPlayer('parkofficer');
        $this->assign($this->parkOfficerId, self::PARENT_KINGDOM_ID, self::PARK_ID);

        // The shape it writes for a KINGDOM officer: park_id = 0.
        $this->kingdomOfficerId = $this->seedPlayer('kingdomofficer');
        $this->assign($this->kingdomOfficerId, self::PARENT_KINGDOM_ID, 0);

        // A kingdom officer of the principality, for the direction the parent chain
        // must NOT flow.
        $this->childOfficerId = $this->seedPlayer('childofficer');
        $this->assign($this->childOfficerId, self::CHILD_KINGDOM_ID, 0);
    }

    protected function tearDown(): void
    {
        if (!isset($this->fixture)) {
            return;
        }

        // Bust the caches BEFORE the rows go: the fixture deletes rows but leaves the
        // per-user permission cache alone, and a stale entry outlives the player it named.
        foreach ($this->seededMundanes as $mid) {
            $this->rbac->InvalidateUserCache($mid);
        }
        $this->seededMundanes = [];
        $this->roleId = 0;

        $this->fixture->cleanup();

        $this->pdo->exec(
            'DELETE FROM ork_park WHERE park_id IN ('
            . self::PARK_ID . ', ' . self::OTHER_PARK_ID . ', ' . self::CHILD_PARK_ID . ')'
        );
        $this->pdo->exec(
            'DELETE FROM ork_kingdom WHERE kingdom_id IN ('
            . self::PARENT_KINGDOM_ID . ', ' . self::CHILD_KINGDOM_ID . ')'
        );
    }

    // ================================================================
    // The escalation itself
    // ================================================================

    public function testAParkOfficersRowDoesNotSatisfyAKingdomScopeCheck(): void
    {
        self::assertFalse(
            $this->rbac->HasPermission(
                $this->parkOfficerId,
                self::KINGDOM_KEY,
                'kingdom',
                self::PARENT_KINGDOM_ID
            ),
            'A row with park_id set is a PARK grant, whatever its kingdom_id column says. '
            . 'Reading it as a kingdom grant handed every park officer kingdom.details.edit, '
            . 'kingdom.auth.manage and kingdom.role.grant over their whole kingdom.'
        );
    }

    public function testAParkOfficerStillHoldsParkAndPlayerPermissionsAtTheirOwnPark(): void
    {
        foreach ([self::PARK_KEY, self::PLAYER_KEY] as $key) {
            self::assertTrue(
                $this->rbac->HasPermission($this->parkOfficerId, $key, 'park', self::PARK_ID),
                $key . ' must still resolve at the park the officer holds. Pinning every '
                . 'non-target scope column to zero would have broken this too: an officer-synced '
                . 'row is not park-only, it carries kingdom_id as well.'
            );
        }
    }

    public function testAParkOfficerDoesNotReachAnotherParkInTheSameKingdom(): void
    {
        self::assertFalse(
            $this->rbac->HasPermission($this->parkOfficerId, self::PARK_KEY, 'park', self::OTHER_PARK_ID),
            'A park grant reaches its own park only.'
        );
    }

    public function testAKingdomOfficersRowStillSatisfiesKingdomScope(): void
    {
        self::assertTrue(
            $this->rbac->HasPermission(
                $this->kingdomOfficerId,
                self::KINGDOM_KEY,
                'kingdom',
                self::PARENT_KINGDOM_ID
            ),
            'A genuine kingdom grant (park_id = 0) must keep working.'
        );
    }

    public function testAKingdomGrantStillCascadesDownToTheKingdomsParks(): void
    {
        self::assertTrue(
            $this->rbac->HasPermission($this->kingdomOfficerId, self::PARK_KEY, 'park', self::PARK_ID),
            'The park -> kingdom cascade is what widens a kingdom grant; it must survive the '
            . 'narrowest-column read rule.'
        );
    }

    // ================================================================
    // The escalation guard must agree with HasPermission
    // ================================================================

    public function testTheEscalationGuardResolvesIdenticallyToHasPermission(): void
    {
        $method = new ReflectionMethod(RBACService::class, 'GranterPermissionKeysAtScope');
        $method->setAccessible(true);

        $scopes = [
            ['kingdom', self::PARENT_KINGDOM_ID],
            ['kingdom', self::CHILD_KINGDOM_ID],
            ['park', self::PARK_ID],
            ['park', self::OTHER_PARK_ID],
            ['park', self::CHILD_PARK_ID],
        ];
        $actors = [
            'park officer'    => $this->parkOfficerId,
            'kingdom officer' => $this->kingdomOfficerId,
            'child officer'   => $this->childOfficerId,
        ];

        foreach ($actors as $label => $actorId) {
            foreach ($scopes as [$scopeType, $scopeId]) {
                $keys = $method->invoke($this->rbac, $actorId, $scopeType, $scopeId);
                foreach ([self::KINGDOM_KEY, self::PARK_KEY, self::PLAYER_KEY] as $key) {
                    self::assertSame(
                        $this->rbac->HasPermission($actorId, $key, $scopeType, $scopeId),
                        isset($keys[$key]),
                        sprintf(
                            'GranterPermissionKeysAtScope must agree with HasPermission for %s / %s '
                            . 'at %s:%d. It is the escalation guard\'s fast path, so a looser answer '
                            . 'there lets a granter hand out a permission they do not hold.',
                            $label,
                            $key,
                            $scopeType,
                            $scopeId
                        )
                    );
                }
            }
        }
    }

    public function testTheEscalationGuardRefusesAParkOfficerActingAtKingdomScope(): void
    {
        $missing = $this->rbac->MissingRolePermissions(
            $this->parkOfficerId,
            $this->roleId,
            'kingdom',
            self::PARENT_KINGDOM_ID
        );

        self::assertContains(
            self::KINGDOM_KEY,
            $missing,
            'A park officer cannot grant a kingdom-scoped role carrying ' . self::KINGDOM_KEY . '.'
        );
    }

    public function testABannedGranterHoldsNothingForTheEscalationGuard(): void
    {
        $this->pdo->exec('UPDATE ork_mundane SET penalty_box = 1 WHERE mundane_id = ' . $this->kingdomOfficerId);
        $this->rbac->InvalidateUserCache($this->kingdomOfficerId);

        $missing = $this->rbac->MissingRolePermissions(
            $this->kingdomOfficerId,
            $this->roleId,
            'kingdom',
            self::PARENT_KINGDOM_ID
        );

        sort($missing);
        $expected = [self::KINGDOM_KEY, self::PARK_KEY, self::PLAYER_KEY];
        sort($expected);
        self::assertSame(
            $expected,
            $missing,
            'HasPermission() denies a banned user every key before it looks at any assignment. '
            . 'The aggregate fast path filters only on mundane_id, scope and expires_at, so '
            . 'without an explicit ban check it accepted a banned granter\'s keys outright.'
        );
    }

    // ================================================================
    // Principality traversal (parity with Authorization::HasAuthority)
    // ================================================================

    public function testAParentKingdomGrantReachesThePrincipalityAndItsParks(): void
    {
        self::assertTrue(
            $this->rbac->HasPermission(
                $this->kingdomOfficerId,
                self::KINGDOM_KEY,
                'kingdom',
                self::CHILD_KINGDOM_ID
            ),
            'HasAuthority() has always walked parent_kingdom_id, because parent-kingdom '
            . 'officers hold authority over a principality. Role delegation stopped at the '
            . 'kingdom line, so a parent kingdom could delegate nothing into its principality.'
        );

        self::assertTrue(
            $this->rbac->HasPermission($this->kingdomOfficerId, self::PARK_KEY, 'park', self::CHILD_PARK_ID),
            'The same traversal must apply to a park inside the principality.'
        );
    }

    public function testAPrincipalityGrantDoesNotReachTheParentKingdom(): void
    {
        self::assertFalse(
            $this->rbac->HasPermission(
                $this->childOfficerId,
                self::KINGDOM_KEY,
                'kingdom',
                self::PARENT_KINGDOM_ID
            ),
            'The parent chain runs one way only.'
        );

        self::assertFalse(
            $this->rbac->HasPermission($this->childOfficerId, self::PARK_KEY, 'park', self::PARK_ID),
            'A principality officer does not reach the parent kingdom\'s parks.'
        );
    }

    public function testACyclicParentChainTerminates(): void
    {
        // A corrupt parent_kingdom_id (A -> B -> A) must not spin forever, exactly as
        // HasAuthority()'s visited-set + depth cap guarantees.
        $this->pdo->exec(
            'UPDATE ork_kingdom SET parent_kingdom_id = ' . self::CHILD_KINGDOM_ID
            . ' WHERE kingdom_id = ' . self::PARENT_KINGDOM_ID
        );
        $this->rbac->InvalidateUserCache($this->parkOfficerId);

        self::assertFalse(
            $this->rbac->HasPermission(
                $this->parkOfficerId,
                self::KINGDOM_KEY,
                'kingdom',
                self::PARENT_KINGDOM_ID
            )
        );
    }

    // ================================================================
    // The global (all-zero) assignment row
    // ================================================================

    public function testAGlobalAssignmentIsRevokableByAnAdminAndNobodyElse(): void
    {
        $target = $this->seedPlayer('globaltarget');
        $globalRoleRowId = $this->assign($target, 0, 0);

        [$plainId, $plainToken] = $this->seedPlayerWithSession('plain');
        $r = $this->rbac->RevokeRole(['Token' => $plainToken, 'UserRoleId' => $globalRoleRowId]);
        self::assertNotSame(0, (int) $r['Status'], 'A non-admin must not revoke an installation-wide role.');
        self::assertSame(1, $this->countUserRole($globalRoleRowId));

        [$adminId, $adminToken] = $this->seedPlayerWithSession('globaladmin');
        $this->fixture->grantLegacyAuthorization($adminId, [], 'admin');

        $r = $this->rbac->RevokeRole(['Token' => $adminToken, 'UserRoleId' => $globalRoleRowId]);
        self::assertSame(
            0,
            (int) $r['Status'],
            'RevokeRole resolved an all-zero row to owning kingdom 0 and refused unconditionally, '
            . 'so a global assignment could be granted but never revoked -- by anyone.'
        );
        self::assertSame(0, $this->countUserRole($globalRoleRowId));

        unset($plainId);
    }

    // ================================================================
    // Every reader of ork_user_role uses the same rule
    // ================================================================

    public function testGetUserRolesAppliesTheSameNarrowestColumnRule(): void
    {
        $atKingdom = $this->rbac->GetUserRoles($this->parkOfficerId, 'kingdom', self::PARENT_KINGDOM_ID);
        self::assertSame(
            [],
            $atKingdom,
            'GetUserRoles filtered on the single scope column, so it reported a park officer\'s '
            . 'row as a KINGDOM assignment -- the same escalation the permission checks now '
            . 'refuse. A read surface that disagrees with the checker is a loaded footgun.'
        );

        $atPark = $this->rbac->GetUserRoles($this->parkOfficerId, 'park', self::PARK_ID);
        self::assertCount(
            1,
            $atPark,
            'The narrowing must not cost the park officer their own park row.'
        );

        $kingdomOfficer = $this->rbac->GetUserRoles($this->kingdomOfficerId, 'kingdom', self::PARENT_KINGDOM_ID);
        self::assertCount(1, $kingdomOfficer, 'A genuine kingdom row (park_id = 0) must still be listed.');
    }

    public function testTheScopeBelongsToKingdomRuleIsReadableFromOutsideTheClass(): void
    {
        $private = new ReflectionMethod(RBACService::class, 'ScopeBelongsToKingdom');
        $private->setAccessible(true);

        $cases = [
            ['kingdom', self::CHILD_KINGDOM_ID, self::PARENT_KINGDOM_ID],
            ['park', self::CHILD_PARK_ID, self::PARENT_KINGDOM_ID],
            ['kingdom', self::PARENT_KINGDOM_ID, self::CHILD_KINGDOM_ID],
            ['global', 0, self::PARENT_KINGDOM_ID],
        ];

        foreach ($cases as [$scopeType, $scopeId, $kingdomId]) {
            self::assertSame(
                $private->invoke($this->rbac, $scopeType, $scopeId, $kingdomId),
                $this->rbac->ScopeIsInKingdom($scopeType, $scopeId, $kingdomId),
                'The public reader must be the gate\'s own rule, parent chain included, so a '
                . 'console validating a picked scope cannot disagree with grant/revoke: '
                . $scopeType . ' ' . $scopeId . ' in kingdom ' . $kingdomId
            );
        }

        self::assertTrue(
            $this->rbac->ScopeIsInKingdom('park', self::CHILD_PARK_ID, self::PARENT_KINGDOM_ID),
            'A parent-kingdom officer may grant into the principality, so a scope validator '
            . 'that only does an exact kingdom match plus a park lookup rejects a scope the '
            . 'gate accepts.'
        );
    }

    // ================================================================
    // Fixtures
    // ================================================================

    private function countUserRole(int $userRoleId): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ork_user_role WHERE user_role_id = ' . $userRoleId
        )->fetchColumn();
    }

    private function seedKingdom(int $kingdomId, int $parentKingdomId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_kingdom (kingdom_id, name, abbreviation, parent_kingdom_id, description, url)
             VALUES (:kid, :name, "ZZK", :parent, "", "")'
        )->execute([
            ':kid' => $kingdomId,
            ':name' => self::MARKER . '_kingdom_' . $kingdomId,
            ':parent' => $parentKingdomId,
        ]);
    }

    private function seedPark(int $parkId, int $kingdomId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_park
                (park_id, kingdom_id, name, abbreviation, url, address, city, province,
                 postal_code, google_geocode, latitude, longitude, location,
                 map_url, description, directions)
             VALUES (:pid, :kid, :name, "ZZP", "", "", "", "", "", "", 0, 0, "", "", "", "")'
        )->execute([
            ':pid' => $parkId,
            ':kid' => $kingdomId,
            ':name' => self::MARKER . '_park_' . $parkId,
        ]);
    }

    private function seedPlayer(string $suffix): int
    {
        [$id] = $this->seedPlayerRow($suffix, false);
        return $id;
    }

    /** @return array{0:int,1:string} */
    private function seedPlayerWithSession(string $suffix): array
    {
        return $this->seedPlayerRow($suffix, true);
    }

    /** @return array{0:int,1:string} */
    private function seedPlayerRow(string $suffix, bool $withSession): array
    {
        [$id, $token] = $this->fixture->seedPlayer(
            $suffix,
            $withSession,
            self::PARK_ID,
            self::PARENT_KINGDOM_ID
        );
        $this->seededMundanes[] = $id;

        return [$id, $token];
    }

    /**
     * Write an ork_user_role row verbatim -- the writers are not under test here.
     *
     * Every scope column is written explicitly by the fixture, so a kingdom_id + park_id
     * row means exactly what sync_officer_role() writes: the shape this whole suite exists
     * to hold to a park.
     */
    private function assign(int $mundaneId, int $kingdomId, int $parkId): int
    {
        return $this->fixture->assignRole(
            $mundaneId,
            $this->roleId,
            ['kingdom_id' => $kingdomId, 'park_id' => $parkId]
        );
    }
}
