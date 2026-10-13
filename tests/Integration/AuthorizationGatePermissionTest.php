<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/RbacRoleFixture.php';

/**
 * The *.auth.manage permissions, and the grant-scope check on RBACService::GrantRole().
 *
 * Both cover the same class of defect: a permission that was checked somewhere the write
 * did not happen.
 *
 * The consoles checked park/kingdom/event.auth.manage and then called straight into
 * Authorization::add_authorization(), which authorizes on HasAuthority() alone -- and
 * lives in class.Authorization.php, which the pre-commit hook keeps out of every commit,
 * so it cannot be taught about permissions. The four keys were therefore pre-filters, not
 * grants: an officer holding park.auth.manage through a role and nothing else passed the
 * console check and was then refused by the domain. unit.auth.manage had no console check
 * at all, so it granted nothing anywhere. AuthorizationGate::GrantScopedAuthorization()
 * is the committable path that makes them real, falling through to the legacy rule
 * (KPM unit-roster bypass included) for anyone who does not hold one.
 *
 * GrantRole authorized against request['KingdomId'] and then wrote a row at a ScopeType /
 * ScopeId the caller named, with nothing tying the two together. Escalation prevention
 * refused that for any role carrying permissions -- but a role carrying NONE short-circuits
 * CheckEscalation, so an empty role could be assigned at any scope in any kingdom.
 */
final class AuthorizationGatePermissionTest extends TestCase
{
    private const MARKER = 'zzauthgateperm';
    private const KINGDOM_ID = 100051;
    private const OTHER_KINGDOM_ID = 100052;
    private const PARK_ID = 100053;
    /** A sibling park in the SAME kingdom -- the near-miss a Type-only check would let through. */
    private const SIBLING_PARK_ID = 100054;
    /** A park and an event owned by OTHER_KINGDOM_ID, for the ScopeBelongsToKingdom cases. */
    private const FOREIGN_PARK_ID = 100055;
    private const FOREIGN_EVENT_ID = 100056;
    /** A principality whose parent_kingdom_id is KINGDOM_ID. */
    private const CHILD_KINGDOM_ID = 100058;

    private ?PDO $pdo = null;
    private ?RbacRoleFixture $rbac = null;
    private ?int $actorId = null;
    private ?string $actorToken = null;
    private ?int $targetId = null;

    /** @var list<string> org-unit rows seeded by seedOrgUnits(), as "table:column:id" */
    private array $orgRows = [];

    protected function tearDown(): void
    {
        $this->rbac?->cleanup();
        $this->rbac = null;
        $this->actorId = null;
        $this->actorToken = null;
        $this->targetId = null;

        if ($this->pdo !== null) {
            foreach ($this->orgRows as $row) {
                [$table, $column, $id] = explode(':', $row);
                $this->pdo->exec('DELETE FROM ' . $table . ' WHERE ' . $column . ' = ' . (int) $id);
            }
        }
        $this->orgRows = [];
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

    /** @return array{0: int, 1: string} */
    private function seedPlayer(string $suffix, bool $withSession): array
    {
        return $this->rbac()->seedPlayer($suffix, $withSession, self::PARK_ID, self::KINGDOM_ID);
    }

    /**
     * Real ork_park / ork_event / ork_kingdom rows.
     *
     * ScopeBelongsToKingdom resolves a park's and an event's owning kingdom from these
     * tables, so a test that names an id with no row behind it proves only that a
     * missing row is refused -- not that a REAL entity in another kingdom is.
     *
     * No unit row: ScopeBelongsToKingdom resolves a unit through `ork_unit.park_id`,
     * and ork_test's ork_unit has no such column, so the unit branch cannot be
     * exercised here without proving a refusal that came from a broken query instead
     * of from the check.
     */
    private function seedOrgUnits(): void
    {
        if ($this->orgRows !== []) {
            return;
        }
        $pdo = $this->pdoConnection();

        // The child kingdom first: parks reference it by id, and a principality is
        // simply a kingdom carrying parent_kingdom_id.
        $pdo->prepare(
            'INSERT INTO ork_kingdom (kingdom_id, name, abbreviation, parent_kingdom_id)
             VALUES (:id, :n, \'ZZC\', :parent)'
        )->execute([
            ':id' => self::CHILD_KINGDOM_ID,
            ':n' => self::MARKER . ' Principality',
            ':parent' => self::KINGDOM_ID,
        ]);
        $this->orgRows[] = 'ork_kingdom:kingdom_id:' . self::CHILD_KINGDOM_ID;

        foreach ([
            [self::PARK_ID, self::KINGDOM_ID],
            [self::SIBLING_PARK_ID, self::KINGDOM_ID],
            [self::FOREIGN_PARK_ID, self::OTHER_KINGDOM_ID],
        ] as [$parkId, $kingdomId]) {
            $pdo->prepare(
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
            $this->orgRows[] = 'ork_park:park_id:' . $parkId;
        }

        $pdo->prepare(
            'INSERT INTO ork_event (event_id, kingdom_id, park_id, mundane_id, unit_id, name)
             VALUES (:id, :kid, 0, 0, 0, :name)'
        )->execute([
            ':id' => self::FOREIGN_EVENT_ID,
            ':kid' => self::OTHER_KINGDOM_ID,
            ':name' => self::MARKER . ' Foreign Event',
        ]);
        $this->orgRows[] = 'ork_event:event_id:' . self::FOREIGN_EVENT_ID;

    }

    /**
     * An actor holding $permissionKey (or keys) through a role at $scope, and NO
     * ork_authorization row -- so every result comes from the permission branch.
     *
     * @param string|list<string> $permissionKeys
     * @param array{kingdom_id?: int, park_id?: int} $scope
     */
    private function seedPermissionOnlyActor($permissionKeys, array $scope): string
    {
        if ($this->actorToken !== null) {
            return $this->actorToken;
        }
        $rbac = $this->rbac();
        [$this->actorId, $this->actorToken] = $this->seedPlayer('actor', true);

        $roleId = $rbac->seedRoleWith($permissionKeys, 'park', self::KINGDOM_ID);
        $rbac->assignRole($this->actorId, $roleId, $scope);

        self::assertSame(
            0,
            (int) $this->pdoConnection()
                ->query('SELECT COUNT(*) FROM ork_authorization WHERE mundane_id = ' . $this->actorId)
                ->fetchColumn(),
            'Fixture check: the actor must hold NO legacy authorization row, or this test proves nothing.'
        );

        return $this->actorToken;
    }

    /** A legacy kingdom officer: the gate itself passes, so only the scope check can refuse. */
    private function seedLegacyKingdomOfficer(int $kingdomId): string
    {
        [$this->actorId, $this->actorToken] = $this->seedPlayer('granter', true);
        $this->rbac()->grantLegacyAuthorization($this->actorId, ['kingdom_id' => $kingdomId]);

        return $this->actorToken;
    }

    /** An empty role: CheckEscalation short-circuits for one, so only the scope check refuses. */
    private function seedEmptyRole(int $ownerKingdomId = self::KINGDOM_ID): int
    {
        return $this->rbac()->seedRoleWith([], 'kingdom', $ownerKingdomId);
    }

    public function testParkAuthManageAloneCanGrantAParkAuthorization(): void
    {
        $token = $this->seedPermissionOnlyActor('park.auth.manage', ['park_id' => self::PARK_ID]);
        [$this->targetId] = $this->seedPlayer('target', false);

        $gate = new AuthorizationGate();
        $r = $gate->GrantScopedAuthorization([
            'Token' => $token,
            'MundaneId' => $this->targetId,
            'Type' => AUTH_PARK,
            'Id' => self::PARK_ID,
            'Role' => AUTH_CREATE,
        ]);

        self::assertSame(
            0,
            (int) $r['Status'],
            'An officer holding park.auth.manage through a role, and nothing else, must be able to '
            . 'grant a park authorization. Routed through Authorization::AddAuthorization instead, '
            . 'this was refused -- which made the permission decorative.'
        );

        self::assertSame(
            1,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_authorization WHERE mundane_id = ' . $this->targetId
                . ' AND park_id = ' . self::PARK_ID
            )->fetchColumn()
        );
    }

    public function testTheGrantIsRefusedAtAScopeTheActorDoesNotHold(): void
    {
        $token = $this->seedPermissionOnlyActor('park.auth.manage', ['park_id' => self::PARK_ID]);
        [$this->targetId] = $this->seedPlayer('target', false);

        $gate = new AuthorizationGate();
        $r = $gate->GrantScopedAuthorization([
            'Token' => $token,
            'MundaneId' => $this->targetId,
            'Type' => AUTH_KINGDOM,
            'Id' => self::OTHER_KINGDOM_ID,
            'Role' => AUTH_CREATE,
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'park.auth.manage at one park must not confer kingdom.auth.manage over a foreign kingdom.'
        );
    }

    public function testAnUnrecognizedRoleIsRefusedOnThePermissionPath(): void
    {
        $token = $this->seedPermissionOnlyActor('park.auth.manage', ['park_id' => self::PARK_ID]);
        [$this->targetId] = $this->seedPlayer('target', false);

        $gate = new AuthorizationGate();
        $r = $gate->GrantScopedAuthorization([
            'Token' => $token,
            'MundaneId' => $this->targetId,
            'Type' => AUTH_PARK,
            'Id' => self::PARK_ID,
            'Role' => 'sovereign',
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'The permission path must apply the same role validation add_authorization() does, or '
            . 'it becomes a way to write roles the legacy path would have rejected.'
        );
    }

    public function testRevokeAuthorizesAgainstTheRowsOwnScope(): void
    {
        $token = $this->seedPermissionOnlyActor('park.auth.manage', ['park_id' => self::PARK_ID]);
        [$this->targetId] = $this->seedPlayer('target', false);

        // A KINGDOM-scoped grant on another kingdom. The actor holds park.auth.manage for
        // one park and must not be able to revoke it by naming its id.
        $pdo = $this->pdoConnection();
        $authId = $this->rbac()->grantLegacyAuthorization(
            $this->targetId,
            ['kingdom_id' => self::OTHER_KINGDOM_ID]
        );

        $gate = new AuthorizationGate();
        $r = $gate->RevokeScopedAuthorization([
            'Token' => $token,
            'AuthorizationId' => $authId,
        ]);

        self::assertNotSame(0, (int) $r['Status'], 'The row decides which permission applies, not the caller.');
        self::assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM ork_authorization WHERE authorization_id = ' . $authId)->fetchColumn(),
            'A refused revoke must leave the row in place.'
        );
    }

    public function testGrantRoleRefusesAScopeOutsideTheGatingKingdom(): void
    {
        // The actor is a full kingdom officer via the legacy bridge, so the gate itself
        // passes; what must stop this is the scope-ownership check.
        $this->seedLegacyKingdomOfficer(self::KINGDOM_ID);
        $pdo = $this->pdoConnection();

        [$this->targetId] = $this->seedPlayer('grantee', false);

        // An EMPTY role: CheckEscalation returns early for one, so escalation prevention
        // is not what refuses this. Only the scope check can.
        $roleId = $this->seedEmptyRole();

        $r = Ork3::$Lib->rbacservice->GrantRole([
            'Token' => $this->actorToken,
            'KingdomId' => self::KINGDOM_ID,
            'MundaneId' => $this->targetId,
            'RoleId' => $roleId,
            'ScopeType' => 'kingdom',
            'ScopeId' => self::OTHER_KINGDOM_ID,
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'Authorizing against one kingdom and writing the row at another is the whole defect. '
            . 'An empty role slips past escalation prevention, so this is the only guard.'
        );

        self::assertSame(
            0,
            (int) $pdo->query(
                'SELECT COUNT(*) FROM ork_user_role WHERE mundane_id = ' . $this->targetId
            )->fetchColumn(),
            'No cross-kingdom assignment row may be written.'
        );
    }

    public function testGrantRoleStillWorksWithinTheGatingKingdom(): void
    {
        $this->seedLegacyKingdomOfficer(self::KINGDOM_ID);

        [$this->targetId] = $this->seedPlayer('grantee', false);

        $roleId = $this->seedEmptyRole();

        $r = Ork3::$Lib->rbacservice->GrantRole([
            'Token' => $this->actorToken,
            'KingdomId' => self::KINGDOM_ID,
            'MundaneId' => $this->targetId,
            'RoleId' => $roleId,
            'ScopeType' => 'kingdom',
            'ScopeId' => self::KINGDOM_ID,
        ]);

        self::assertSame(
            0,
            (int) $r['Status'],
            'The new scope check must not break the ordinary case it was added around.'
        );
    }

    // ------------------------------------------------------------------
    // AUTH_ADMIN: the highest-privilege write in the system.
    //
    // add_auth_h() writes the all-zero row for Type=admin, and that row is what
    // IsAdmin() and HasAuthority() short-circuit on. Reaching it through a lesser
    // *.auth.manage key -- or through global.admin.grant, which the RBAC roles hand out
    // as "may manage grants" -- would make an operator role a one-request promotion to
    // full legacy admin.
    // ------------------------------------------------------------------

    public function testKingdomAuthManageCannotGrantAnAdminAuthorization(): void
    {
        $token = $this->seedPermissionOnlyActor('kingdom.auth.manage', ['kingdom_id' => self::KINGDOM_ID]);
        [$this->targetId] = $this->seedPlayer('target', false);

        $gate = new AuthorizationGate();
        $r = $gate->GrantScopedAuthorization([
            'Token' => $token,
            'MundaneId' => $this->targetId,
            'Type' => AUTH_ADMIN,
            'Id' => 0,
            'Role' => AUTH_ADMIN,
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'kingdom.auth.manage is authority to hand out grants inside ONE kingdom. It must not '
            . 'write the all-zero admin row, which the legacy path only ever let an existing '
            . 'all-zero admin write.'
        );
        self::assertSame(
            0,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_authorization
                  WHERE mundane_id = ' . $this->targetId . " AND role = 'admin'"
            )->fetchColumn(),
            'No admin row may have been written.'
        );
    }

    public function testGlobalAdminGrantAloneCannotMintAnAdminAuthorization(): void
    {
        // The all-zero ROLE assignment (global scope), and no legacy admin row: the exact
        // shape of the ork_admin operator role.
        $token = $this->seedPermissionOnlyActor('global.admin.grant', []);
        [$this->targetId] = $this->seedPlayer('target', false);

        self::assertTrue(
            Ork3::$Lib->rbacservice->HasPermission($this->actorId, 'global.admin.grant', 'global', 0),
            'Fixture check: the actor must really hold global.admin.grant, or this proves nothing.'
        );

        $gate = new AuthorizationGate();
        $r = $gate->GrantScopedAuthorization([
            'Token' => $token,
            'MundaneId' => $this->targetId,
            'Type' => AUTH_ADMIN,
            'Id' => 0,
            'Role' => AUTH_ADMIN,
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'Handing out the all-zero admin row stays a TRUE-admin act. Otherwise the role sold '
            . 'as "one capability without all of them" is unrestricted admin one request later.'
        );
        self::assertSame(
            0,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_authorization
                  WHERE mundane_id = ' . $this->targetId . " AND role = 'admin'"
            )->fetchColumn(),
            'No admin row may have been written.'
        );
    }

    public function testTypeAdminWithANonAdminRoleIsRefused(): void
    {
        // add_auth_h() writes an all-zero row for Type=admin whatever the Role is, so this
        // combination produced a row that conferred nothing and that rowScope() could
        // never classify -- one no console could ever revoke.
        $token = $this->seedPermissionOnlyActor('global.admin.grant', []);
        [$this->targetId] = $this->seedPlayer('target', false);

        $gate = new AuthorizationGate();
        $r = $gate->GrantScopedAuthorization([
            'Token' => $token,
            'MundaneId' => $this->targetId,
            'Type' => AUTH_ADMIN,
            'Id' => 0,
            'Role' => AUTH_CREATE,
        ]);

        self::assertNotSame(0, (int) $r['Status'], 'Type and Role have to agree about admin.');
        self::assertSame(
            0,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_authorization WHERE mundane_id = ' . $this->targetId
            )->fetchColumn(),
            'A permanently unrevokable row must never be written.'
        );
    }

    public function testGlobalAdminGrantAloneCannotRevokeAnAdminAuthorization(): void
    {
        // Mirror of testGlobalAdminGrantAloneCannotMintAnAdminAuthorization on the revoke
        // side: remove_auth_h() has no authority check of its own, so without the guard
        // the operator role could delete every real all-zero admin row.
        $token = $this->seedPermissionOnlyActor('global.admin.grant', []);
        [$this->targetId] = $this->seedPlayer('target', false);

        $pdo = $this->pdoConnection();
        $authId = $this->rbac()->grantLegacyAuthorization($this->targetId, [], AUTH_ADMIN);

        $gate = new AuthorizationGate();
        $r = $gate->RevokeScopedAuthorization([
            'Token' => $token,
            'AuthorizationId' => $authId,
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'Taking away the all-zero admin row stays a TRUE-admin act; the legacy arm refused this too.'
        );
        self::assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM ork_authorization WHERE authorization_id = ' . $authId)->fetchColumn(),
            'A refused revoke must leave the admin row in place.'
        );
    }

    public function testParkAuthManageDoesNotReachASiblingParkInTheSameKingdom(): void
    {
        // The narrower and likelier mistake than crossing scope TYPES: comparing the key
        // to the Type alone rather than to (Type, Id). Both parks are in the same kingdom,
        // so nothing but the id distinguishes them.
        $this->seedOrgUnits();
        $token = $this->seedPermissionOnlyActor('park.auth.manage', ['park_id' => self::PARK_ID]);
        [$this->targetId] = $this->seedPlayer('target', false);

        $gate = new AuthorizationGate();
        $r = $gate->GrantScopedAuthorization([
            'Token' => $token,
            'MundaneId' => $this->targetId,
            'Type' => AUTH_PARK,
            'Id' => self::SIBLING_PARK_ID,
            'Role' => AUTH_CREATE,
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'park.auth.manage names ONE park. Holding it must not confer authority over every '
            . 'other park in the kingdom.'
        );
        self::assertSame(
            0,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_authorization WHERE mundane_id = ' . $this->targetId
            )->fetchColumn(),
            'No row may be written at the sibling park.'
        );
    }

    public function testRevokeIsGatedOnARowsMostSpecificScopeWhenSeveralAreSet(): void
    {
        // ork_authorization's schema does not stop a row from carrying several scope
        // columns, and the application writes exactly that shape elsewhere. Whichever
        // column revoke resolves, holding park.auth.manage for the park named on a row
        // that ALSO names a kingdom must not be enough: the kingdom half of the grant is
        // authority this actor was never given.
        $this->seedOrgUnits();
        $token = $this->seedPermissionOnlyActor('park.auth.manage', ['park_id' => self::PARK_ID]);
        [$this->targetId] = $this->seedPlayer('target', false);

        $authId = $this->rbac()->grantLegacyAuthorization($this->targetId, [
            'park_id' => self::PARK_ID,
            'kingdom_id' => self::KINGDOM_ID,
        ]);

        $gate = new AuthorizationGate();
        $r = $gate->RevokeScopedAuthorization([
            'Token' => $token,
            'AuthorizationId' => $authId,
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'A row naming both a park and a kingdom confers kingdom authority; revoking it must '
            . 'require the kingdom key, not the park one.'
        );
        self::assertSame(
            1,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_authorization WHERE authorization_id = ' . $authId
            )->fetchColumn(),
            'A refused revoke must leave the row in place.'
        );
    }

    // ------------------------------------------------------------------
    // ScopeBelongsToKingdom, at every ScopeType it must resolve -- not just 'kingdom'.
    // ------------------------------------------------------------------

    /** @return list<array{0: string, 1: string}> ScopeType, and the constant naming the entity */
    public static function foreignScopeProvider(): array
    {
        return [
            'a park in another kingdom' => ['park', 'FOREIGN_PARK_ID'],
            'an event in another kingdom' => ['event', 'FOREIGN_EVENT_ID'],
        ];
    }

    /** @dataProvider foreignScopeProvider */
    public function testGrantRoleRefusesAForeignScopeOfEveryType(string $scopeType, string $idConstant): void
    {
        $this->seedOrgUnits();
        $this->seedLegacyKingdomOfficer(self::KINGDOM_ID);
        [$this->targetId] = $this->seedPlayer('grantee', false);
        $roleId = $this->seedEmptyRole();

        $r = Ork3::$Lib->rbacservice->GrantRole([
            'Token' => $this->actorToken,
            'KingdomId' => self::KINGDOM_ID,
            'MundaneId' => $this->targetId,
            'RoleId' => $roleId,
            'ScopeType' => $scopeType,
            'ScopeId' => constant('self::' . $idConstant),
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            "A {$scopeType} owned by another kingdom is not a scope this kingdom's console may "
            . 'assign at. The kingdom-scope case was the only one covered; the cross-kingdom hole '
            . 'is just as open through a park, an event or a unit.'
        );
        self::assertSame(
            0,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_user_role WHERE mundane_id = ' . $this->targetId
            )->fetchColumn(),
            'No cross-kingdom assignment row may be written.'
        );
    }

    public function testGrantRoleFailsClosedOnAnUnrecognizedScopeType(): void
    {
        $this->seedLegacyKingdomOfficer(self::KINGDOM_ID);
        [$this->targetId] = $this->seedPlayer('grantee', false);
        $roleId = $this->seedEmptyRole();

        $r = Ork3::$Lib->rbacservice->GrantRole([
            'Token' => $this->actorToken,
            'KingdomId' => self::KINGDOM_ID,
            'MundaneId' => $this->targetId,
            'RoleId' => $roleId,
            'ScopeType' => 'realm',
            'ScopeId' => self::KINGDOM_ID,
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'A ScopeType nothing recognises must fail CLOSED. A switch that falls through to '
            . '"belongs" would make an unknown string the way past the whole check.'
        );
        self::assertSame(
            0,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_user_role WHERE mundane_id = ' . $this->targetId
            )->fetchColumn(),
            'And no row may be written under a scope type the reader cannot interpret.'
        );
    }

    public function testAParentKingdomMayGrantIntoItsPrincipality(): void
    {
        // Authorization::HasAuthority walks parent_kingdom_id because parent-kingdom
        // officers hold authority over a principality and its parks. If
        // ScopeBelongsToKingdom demands an exact match instead, a parent kingdom can
        // delegate NOTHING into its own principality through a role -- and the console
        // posts the parent's KingdomId, so the "act as the child" workaround is never
        // taken.
        $this->seedOrgUnits();
        $this->seedLegacyKingdomOfficer(self::KINGDOM_ID);
        [$this->targetId] = $this->seedPlayer('grantee', false);
        $roleId = $this->seedEmptyRole();

        $r = Ork3::$Lib->rbacservice->GrantRole([
            'Token' => $this->actorToken,
            'KingdomId' => self::KINGDOM_ID,
            'MundaneId' => $this->targetId,
            'RoleId' => $roleId,
            'ScopeType' => 'kingdom',
            'ScopeId' => self::CHILD_KINGDOM_ID,
        ]);

        self::assertSame(
            0,
            (int) $r['Status'],
            'A principality is a sub-group of its parent kingdom, not a foreign one. Error: '
            . (string) ($r['Error'] ?? '')
        );
        self::assertSame(
            1,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_user_role
                  WHERE mundane_id = ' . $this->targetId . ' AND kingdom_id = ' . self::CHILD_KINGDOM_ID
            )->fetchColumn(),
            'And the row must land at the principality, not at the gating kingdom.'
        );
    }

    public function testAPrincipalityMayNotGrantIntoItsParentKingdom(): void
    {
        // The other direction of the same chain, and the one that must stay shut: a
        // principality's officers hold no authority over the kingdom above them.
        $this->seedOrgUnits();
        $this->seedLegacyKingdomOfficer(self::CHILD_KINGDOM_ID);
        [$this->targetId] = $this->seedPlayer('grantee', false);
        $roleId = $this->seedEmptyRole(self::CHILD_KINGDOM_ID);

        $r = Ork3::$Lib->rbacservice->GrantRole([
            'Token' => $this->actorToken,
            'KingdomId' => self::CHILD_KINGDOM_ID,
            'MundaneId' => $this->targetId,
            'RoleId' => $roleId,
            'ScopeType' => 'kingdom',
            'ScopeId' => self::KINGDOM_ID,
        ]);

        self::assertNotSame(
            0,
            (int) $r['Status'],
            'The parent chain is walked upward for authority, so it must not be walked downward '
            . 'for scope: a principality officer granting at the parent kingdom is escalation.'
        );
        self::assertSame(
            0,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_user_role WHERE mundane_id = ' . $this->targetId
            )->fetchColumn(),
            'No assignment row may be written at the parent kingdom.'
        );
    }
}
