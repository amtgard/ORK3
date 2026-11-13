<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/RbacRoleFixture.php';

/**
 * The 'global' permission scope, and the two rules that keep it from becoming a
 * back door into every kingdom.
 *
 * Before this scope existed, every installation-level capability -- purging logs,
 * reading server health, editing the award catalog shared by all kingdoms, creating a
 * kingdom, merging a player across kingdoms, banning an account -- was reachable only
 * by holding an all-zero-scope `admin` row in ork_authorization. That row is
 * all-or-nothing: the ORK team could not hand someone "read server health" without
 * also handing them "purge the logs". ork_permission.scope_type has always declared a
 * 'global' member; nothing used it.
 *
 * The rules under test:
 *   - a global assignment is the ork_user_role row with EVERY scope column zero, so it
 *     cannot be confused with a park grant (which leaves kingdom_id zero);
 *   - global does NOT sit at the top of the scope cascade. A global role is an
 *     installation-operator role, not a super-kingdom, and letting it satisfy scoped
 *     checks would recreate the all-or-nothing row it exists to replace.
 */
final class RbacGlobalScopeTest extends TestCase
{
    private const MARKER = 'zzglobalscope';
    private const KINGDOM_ID = 100047;

    private ?PDO $pdo = null;
    private ?RbacRoleFixture $rbac = null;
    private ?int $mundaneId = null;

    protected function tearDown(): void
    {
        // The orphan-permission fixture removes its row in a finally block, but finally does
        // not survive a PHP fatal or a killed run. A surviving row is expensive to diagnose:
        // bin/run-unit-tests.sh dies at the sync-permission-registry.php --dry-run gate
        // (exit 2 under set -e) BEFORE PHPUnit starts, and RbacRegistryParityTest's
        // registry/DB parity assertions go red too -- two confusing failures nobody traces
        // back to a test fixture. The key is a fixed marker-derived string, so belt-and-
        // braces removal here is cheap and unconditional.
        if ($this->pdo !== null) {
            $this->pdo->prepare('DELETE FROM ork_permission WHERE `key` = :k')
                ->execute([':k' => 'global.' . self::MARKER . '.orphan']);
        }

        $this->rbac?->cleanup();
        $this->rbac = null;
        $this->mundaneId = null;
    }

    private function pdoConnection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
                DB_USERNAME,
                DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }

        return $this->pdo;
    }

    private function rbac(): RbacRoleFixture
    {
        if ($this->rbac === null) {
            $this->rbac = new RbacRoleFixture($this->pdoConnection(), self::MARKER, self::KINGDOM_ID);
        }

        return $this->rbac;
    }

    /** A player with no authorization rows at all -- every result must come from RBAC. */
    private function seedUnprivilegedPlayer(): int
    {
        if ($this->mundaneId === null) {
            $this->mundaneId = $this->rbac()->seedUnprivilegedPlayer('operator');
        }

        return $this->mundaneId;
    }

    /** A throwaway role carrying exactly $permissionKey. */
    private function seedRoleWith(string $permissionKey, string $scopeType): int
    {
        return $this->rbac()->seedRoleWith($permissionKey, $scopeType, self::KINGDOM_ID);
    }

    /** @param array{kingdom_id?: int, park_id?: int} $scope */
    private function assignRole(int $mundaneId, int $roleId, array $scope = []): void
    {
        $this->rbac()->assignRole($mundaneId, $roleId, $scope);
    }

    public function testGlobalPermissionIsGrantedByAnAllZeroScopeAssignment(): void
    {
        $uid = $this->seedUnprivilegedPlayer();
        $roleId = $this->seedRoleWith('global.health.view', 'global');

        self::assertFalse(
            Ork3::$Lib->rbacservice->HasPermission($uid, 'global.health.view', 'global', 0),
            'Before assignment the player must hold nothing.'
        );

        $this->assignRole($uid, $roleId);

        self::assertTrue(
            Ork3::$Lib->rbacservice->HasPermission($uid, 'global.health.view', 'global', 0),
            'An ork_user_role row with every scope column zero is the global assignment.'
        );
    }

    public function testAKingdomScopedAssignmentDoesNotConferAGlobalPermission(): void
    {
        $uid = $this->seedUnprivilegedPlayer();
        $roleId = $this->seedRoleWith('global.maintenance.run', 'global');

        // Same role, but pinned to a kingdom. The permission is installation-wide;
        // holding it "for one kingdom" is meaningless and must not resolve.
        $this->assignRole($uid, $roleId, ['kingdom_id' => self::KINGDOM_ID]);

        self::assertFalse(
            Ork3::$Lib->rbacservice->HasPermission($uid, 'global.maintenance.run', 'global', 0),
            'A scoped assignment must not satisfy a global check -- the all-zero rule is what '
            . 'separates a global grant from a park grant, which also leaves kingdom_id at 0.'
        );
    }

    public function testAGlobalAssignmentDoesNotCascadeIntoAKingdom(): void
    {
        $uid = $this->seedUnprivilegedPlayer();
        // A global role that also carries a kingdom-scoped permission. Even so, holding it
        // globally must not confer that permission inside any particular kingdom: global is
        // an operator role, not a super-kingdom.
        $roleId = $this->seedRoleWith('kingdom.details.edit', 'global');
        $this->assignRole($uid, $roleId);

        self::assertFalse(
            Ork3::$Lib->rbacservice->HasPermission($uid, 'kingdom.details.edit', 'kingdom', self::KINGDOM_ID),
            'CheckPermissionCascade deliberately stops at kingdom. If global were its top, one '
            . 'global role would silently become authority over every kingdom in the game.'
        );
    }

    public function testGlobalChecksIgnoreTheSuppliedScopeId(): void
    {
        $uid = $this->seedUnprivilegedPlayer();
        $roleId = $this->seedRoleWith('global.admin.grant', 'global');
        $this->assignRole($uid, $roleId);

        // Callers pass 0, but a stray id must not turn a held permission into a denial:
        // HasPermission's early "scope_id must be positive" guard would otherwise reject
        // global outright, and a nonzero id must not send it looking for a scoped row.
        self::assertTrue(
            Ork3::$Lib->rbacservice->HasPermission($uid, 'global.admin.grant', 'global', 0)
        );
        self::assertTrue(
            Ork3::$Lib->rbacservice->HasPermission($uid, 'global.admin.grant', 'global', self::KINGDOM_ID),
            'A global permission has no entity, so the scope id is not part of the question.'
        );
    }

    public function testUnknownPermissionKeysAreStillRefused(): void
    {
        $uid = $this->seedUnprivilegedPlayer();

        self::assertFalse(
            Ork3::$Lib->rbacservice->HasPermission($uid, 'global.not.a.real.key', 'global', 0),
            'The registry check must run before the global branch, not after it.'
        );

        // A key absent from ork_permission can never resolve -- the direct query joins that
        // table -- so the assertion above holds with the registry check DELETED, and proves
        // nothing about it. The registry is only load-bearing for a key the TABLE knows and
        // the code does not: a stale row left by a reverted deploy, or one inserted by hand.
        // Grant exactly that and the registry gate is the only thing that can refuse it.
        $pdo = $this->pdoConnection();
        $orphanKey = 'global.' . self::MARKER . '.orphan';
        $pdo->prepare(
            'INSERT INTO ork_permission (`key`, display_name, description, scope_type, category, is_system)
             VALUES (:k, :d, \'\', \'global\', \'test\', 0)'
        )->execute([':k' => $orphanKey, ':d' => 'Orphan fixture permission']);

        try {
            self::assertFalse(
                PermissionRegistry::Exists($orphanKey),
                'Fixture check: the key must be unknown to the registry, or this proves nothing.'
            );

            $roleId = $this->seedRoleWith($orphanKey, 'global');
            $this->assignRole($uid, $roleId);

            self::assertFalse(
                Ork3::$Lib->rbacservice->HasPermission($uid, $orphanKey, 'global', 0),
                'A permission row the registry does not declare must not be honoured, however it '
                . 'got into the table. PermissionRegistry is the source of truth, not ork_permission.'
            );
        } finally {
            $pdo->prepare('DELETE FROM ork_permission WHERE `key` = :k')->execute([':k' => $orphanKey]);
        }
    }

    // ------------------------------------------------------------------
    // The WRITE side of the global scope.
    //
    // Everything above establishes that an all-zero ork_user_role row confers
    // installation-wide permissions. That makes GrantRole's ScopeType='global' branch
    // the single point of failure for the whole scope: whoever can post it can mint
    // that row. The schema is no help -- it happily stores a scope_type='global' role
    // owned by a real kingdom_id -- so only the IsAdmin() guard stands between a
    // kingdom's role console and the installation.
    // ------------------------------------------------------------------

    public function testAKingdomOfficerCannotGrantARoleAtGlobalScope(): void
    {
        $rbac = $this->rbac();
        [$actorId, $actorToken] = $rbac->seedPlayer('granter', true);
        $targetId = $rbac->seedUnprivilegedPlayer('grantee');

        // Permission-only: the actor holds kingdom.role.grant for their own kingdom
        // through a role, and no legacy ork_authorization row at all.
        $granterRole = $rbac->seedRoleWith('kingdom.role.grant', 'kingdom', self::KINGDOM_ID);
        $rbac->assignRole($actorId, $granterRole, ['kingdom_id' => self::KINGDOM_ID]);

        // An EMPTY role: CheckEscalation short-circuits for one, so escalation
        // prevention cannot be what refuses this. Only the IsAdmin() guard can.
        $emptyRole = $rbac->seedRoleWith([], 'global', self::KINGDOM_ID);

        $r = Ork3::$Lib->rbacservice->GrantRole([
            'Token' => $actorToken,
            'KingdomId' => self::KINGDOM_ID,
            'MundaneId' => $targetId,
            'RoleId' => $emptyRole,
            'ScopeType' => 'global',
            'ScopeId' => 0,
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'kingdom.role.grant is authority over ONE kingdom. Naming ScopeType=global on the '
            . 'same request must not turn it into authority over the installation.'
        );

        self::assertSame(
            0,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_user_role
                  WHERE mundane_id = ' . $targetId . '
                    AND kingdom_id = 0 AND park_id = 0 AND event_id = 0 AND unit_id = 0'
            )->fetchColumn(),
            'No all-zero assignment row may exist for ANY role: that row is the global grant.'
        );
    }

    public function testATrueAdminCanGrantARoleAtGlobalScope(): void
    {
        $rbac = $this->rbac();
        [$adminId, $adminToken] = $rbac->seedPlayer('admin', true);
        // The all-zero ork_authorization admin row -- the only thing IsAdmin() honours.
        $rbac->grantLegacyAuthorization($adminId, [], AUTH_ADMIN);

        $targetId = $rbac->seedUnprivilegedPlayer('operator_grantee');
        $roleId = $rbac->seedRoleWith('global.health.view', 'global', 0);

        $r = Ork3::$Lib->rbacservice->GrantRole([
            'Token' => $adminToken,
            'KingdomId' => self::KINGDOM_ID,
            'MundaneId' => $targetId,
            'RoleId' => $roleId,
            'ScopeType' => 'global',
            'ScopeId' => 0,
        ]);

        self::assertSame(
            0,
            (int) $r['Status'],
            'Refusing everyone would make the global scope undeliverable: an installation '
            . 'administrator is exactly who may assign an operator role. Detail: '
            . (string) ($r['Error'] ?? '')
        );

        self::assertSame(
            1,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_user_role
                  WHERE mundane_id = ' . $targetId . ' AND role_id = ' . $roleId . '
                    AND kingdom_id = 0 AND park_id = 0 AND event_id = 0 AND unit_id = 0'
            )->fetchColumn(),
            'The grant must land as the all-zero row, not carry the gating KingdomId.'
        );

        self::assertTrue(
            Ork3::$Lib->rbacservice->HasPermission($targetId, 'global.health.view', 'global', 0),
            'And the row it wrote must be the one the read side recognises.'
        );
    }

    public function testANonAdminCannotBuildARoleCarryingAGlobalKey(): void
    {
        // The global filter on the role builder is UI-side only, so the domain has to
        // refuse this itself: a kingdom officer may not mint a role carrying an
        // installation-wide key and then hand it to themselves.
        $rbac = $this->rbac();
        [$actorId, $actorToken] = $rbac->seedPlayer('builder', true);
        $builderRole = $rbac->seedRoleWith('kingdom.role.manage', 'kingdom', self::KINGDOM_ID);
        $rbac->assignRole($actorId, $builderRole, ['kingdom_id' => self::KINGDOM_ID]);

        $name = self::MARKER . '_escalation_' . bin2hex(random_bytes(4));
        $r = Ork3::$Lib->rbacservice->CreateRole([
            'Token' => $actorToken,
            'KingdomId' => self::KINGDOM_ID,
            'Name' => $name,
            'DisplayName' => 'Test ' . $name,
            'Description' => 'Fixture role',
            'ScopeType' => 'kingdom',
            'Permissions' => ['global.admin.grant'],
        ]);

        $pdo = $this->pdoConnection();
        $roleId = (int) $pdo->query(
            'SELECT COALESCE(MAX(role_id), 0) FROM ork_role WHERE `name` = ' . $pdo->quote($name)
        )->fetchColumn();
        if ($roleId > 0) {
            $pdo->exec('DELETE FROM ork_role_permission WHERE role_id = ' . $roleId);
            $pdo->exec('DELETE FROM ork_role WHERE role_id = ' . $roleId);
        }

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'A creator who does not hold global.admin.grant must not be able to put it into a '
            . 'role -- otherwise the kingdom role console mints installation authority.'
        );
        self::assertSame(0, $roleId, 'A refused create must not have written the role.');
    }
}
