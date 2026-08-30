<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class RbacAuthorizationTest extends TestCase
{
    private const MARKER = 'zzrbacauth';
    private const KINGDOM_ID = 100041;

    private ?PDO $pdo = null;
    private ?int $unprivilegedMundaneId = null;

    protected function tearDown(): void
    {
        if ($this->pdo === null || $this->unprivilegedMundaneId === null) {
            return;
        }
        $this->pdo->exec('DELETE FROM ork_session WHERE mundane_id = ' . $this->unprivilegedMundaneId);
        $this->pdo->exec('DELETE FROM ork_authorization WHERE mundane_id = ' . $this->unprivilegedMundaneId);
        $this->pdo->exec('DELETE FROM ork_user_role WHERE mundane_id = ' . $this->unprivilegedMundaneId);
        $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id = ' . $this->unprivilegedMundaneId);
        $this->unprivilegedMundaneId = null;
    }

    public function testEveryUserFacingMutatorRejectsAnInvalidToken(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $rbac = new RBACService();
        foreach (['GrantRole', 'RevokeRole', 'CreateRole', 'EditRole', 'DeleteRole'] as $method) {
            $r = $rbac->{$method}(['Token' => 'not-a-real-token', 'KingdomId' => 100041]);
            self::assertSame(5, (int) $r['Status'], $method . ' must reject an invalid token');
        }
    }

    public function testTheActorCannotBeNamedByTheCaller(): void
    {
        $rbac = new RBACService();
        // Every one of these took the actor as a parameter. Passing one must not
        // grant standing -- the token is the only thing that establishes identity.
        $r = $rbac->GrantRole([
            'Token' => '', 'GranterId' => 1, 'KingdomId' => 100041,
            'MundaneId' => 2, 'RoleId' => 3, 'ScopeType' => 'kingdom', 'ScopeId' => 100041,
        ]);
        self::assertSame(5, (int) $r['Status']);
    }

    public function testInternalSyncMethodsAreUnreachableByTheDispatcher(): void
    {
        foreach (['SyncOfficerRole', 'SyncOfficerRoleByPositionId', 'SyncNewOfficerSlot'] as $old) {
            self::assertFalse(method_exists('RBACService', $old), $old . ' must be renamed with underscores');
        }
        foreach (['sync_officer_role', 'sync_officer_role_by_position_id', 'sync_new_officer_slot'] as $new) {
            self::assertTrue(method_exists('RBACService', $new));
        }
    }

    /**
     * The three tests above prove the TOKEN check runs -- an invalid/empty token gets
     * rejected before any of these methods look at KingdomId at all. None of them prove
     * the PERMISSION gate (checkPermissionOrAuthority against kingdom.auth.manage) is
     * wired to the right scope, the right key, or the right id. A bug that handed the
     * gate the wrong scope type, the wrong permission key, or $request['ScopeId'] instead
     * of $request['KingdomId'] would leave every test above green.
     *
     * This seeds a genuinely-authenticated user -- a real ork_mundane row plus a real,
     * unexpired ork_session row for a fresh token, so IsAuthorized() resolves a real,
     * nonzero actor id from it -- who holds ZERO ork_authorization rows and ZERO
     * ork_user_role rows anywhere. That combination is verified below (not assumed)
     * to (a) authenticate and (b) fail checkPermissionOrAuthority('kingdom.auth.manage')
     * before the mutator calls run, so a failure of the mutators is provably a
     * permission-gate failure and not a disguised authentication failure.
     */
    public function testMutatorsRejectAnAuthenticatedUserWithoutTheManagePermission(): void
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

        $token = md5(self::MARKER . bin2hex(random_bytes(8)));
        $username = strtolower(self::MARKER . '_' . substr($token, 0, 12));

        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES
                (:given_name, :surname, :other_name, :username, :persona, :email, 0, :kingdom_id,
                 :token, :waiver_ext, :password_expires, :password_salt, :xtoken, :reeve_qualified_until)'
        );
        $stmt->execute([
            ':given_name' => self::MARKER,
            ':surname' => 'Unprivileged',
            ':other_name' => '',
            ':username' => $username,
            ':persona' => self::MARKER . ' Unprivileged',
            ':email' => $username . '@example.test',
            ':kingdom_id' => self::KINGDOM_ID,
            ':token' => $token,
            ':waiver_ext' => '',
            ':password_expires' => '2099-01-01 00:00:00',
            ':password_salt' => '',
            ':xtoken' => '',
            ':reeve_qualified_until' => '2000-01-01',
        ]);
        $this->unprivilegedMundaneId = (int) $this->pdo->lastInsertId();

        // NOW()/DATE_ADD() computed in SQL, not PHP date() -- see AuthorizedOfficerFixture.
        $this->pdo->prepare(
            'INSERT INTO ork_session (mundane_id, token, created, last_seen, expires)
             VALUES (:mundane_id, :token, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([
            ':mundane_id' => $this->unprivilegedMundaneId,
            ':token' => $token,
        ]);

        // Deliberately no ork_authorization row and no ork_user_role row for this
        // mundane_id anywhere -- that absence is the entire point of this fixture.

        // Prove the premise before trusting anything below: this token must actually
        // authenticate (IsAuthorized() must resolve a real, nonzero actor id), and the
        // resulting actor must genuinely fail the RBAC gate this task wires in. If either
        // assertion here fails, the fixture itself is broken and the mutator assertions
        // below would be meaningless.
        $actorId = Ork3::$Lib->authorization->IsAuthorized($token);
        self::assertSame(
            $this->unprivilegedMundaneId,
            $actorId,
            'Fixture bug: the seeded session must authenticate as the seeded mundane for this test to prove anything.'
        );
        self::assertFalse(
            Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
                $actorId,
                'kingdom.auth.manage',
                'kingdom',
                self::KINGDOM_ID,
                AUTH_CREATE
            ),
            'Fixture bug: the seeded user must genuinely lack kingdom.auth.manage for this test to isolate the gate.'
        );

        $rbac = new RBACService();
        foreach (['GrantRole', 'RevokeRole', 'CreateRole', 'EditRole', 'DeleteRole'] as $method) {
            $r = $rbac->{$method}(['Token' => $token, 'KingdomId' => self::KINGDOM_ID]);
            self::assertSame(
                5,
                (int) $r['Status'],
                $method . ' must reject a valid, authenticated token that lacks kingdom.auth.manage'
            );
        }
    }
}
