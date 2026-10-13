<?php

declare(strict_types=1);

/**
 * Seeds the RBAC primitives every permission test needs: a player with no privileges,
 * a throwaway role carrying an exact set of permission keys, an assignment of that role
 * at a scope (with cache invalidation), and legacy ork_authorization rows.
 *
 * Written because RbacGlobalScopeTest, AuthorizationGatePermissionTest and
 * TournamentAuthorizationTest had each hand-rolled the same 14-column ork_mundane
 * INSERT, the same pdoConnection(), and the same "create a role carrying exactly this
 * permission and assign it here" block -- the single most reusable primitive the RBAC
 * work produced, written three times and available to none of the officer suites.
 *
 * Unlike the two copies it replaces, roles are tracked as a LIST: a test that seeds a
 * second role no longer leaks the first one's ork_role / ork_role_permission rows into
 * the shared test database.
 *
 * Every seeded row is removed by cleanup(); call it from tearDown().
 */
final class RbacRoleFixture
{
    /** @var list<int> */
    private array $mundaneIds = [];
    /** @var list<int> */
    private array $roleIds = [];
    /** @var list<int> */
    private array $userRoleIds = [];
    /** @var list<int> */
    private array $authorizationIds = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $marker,
        private readonly int $kingdomId = 0,
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * One ork_mundane row, optionally with a live ork_session so the id is reachable
     * through a Token. Returns [mundane_id, token].
     *
     * NOW()/DATE_ADD() are computed in SQL, not PHP: startup.php pins the default
     * timezone to America/Chicago, so a PHP-side expiry compared against the DB's UTC
     * NOW() reads as already-expired.
     *
     * @return array{0: int, 1: string}
     */
    public function seedPlayer(
        string $suffix,
        bool $withSession = false,
        int $parkId = 0,
        ?int $kingdomId = null
    ): array {
        $token = md5($this->marker . $suffix . bin2hex(random_bytes(6)));
        $username = strtolower($this->marker . '_' . $suffix . '_' . substr($token, 0, 8));

        $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES
                (:g, :s, \'\', :u, :p, :e, :pk, :k, :t, \'\', \'2099-01-01 00:00:00\', \'\', \'\', \'2000-01-01\')'
        )->execute([
            ':g' => $this->marker,
            ':s' => ucfirst($suffix),
            ':u' => $username,
            ':p' => $this->marker . ' ' . $suffix,
            ':e' => $username . '@example.test',
            ':pk' => $parkId,
            ':k' => $kingdomId ?? $this->kingdomId,
            ':t' => $token,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->mundaneIds[] = $id;

        if ($withSession) {
            $this->pdo->prepare(
                'INSERT INTO ork_session (mundane_id, token, created, last_seen, expires)
                 VALUES (:m, :t, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))'
            )->execute([':m' => $id, ':t' => $token]);
        }

        return [$id, $token];
    }

    /** A player with no authorization rows at all -- every result must come from RBAC. */
    public function seedUnprivilegedPlayer(string $suffix = 'player', int $parkId = 0): int
    {
        [$id] = $this->seedPlayer($suffix, false, $parkId);

        return $id;
    }

    /**
     * A throwaway role carrying exactly $permissionKeys and nothing else.
     *
     * Asserts the keys exist in ork_permission: a role silently carrying zero
     * permissions makes every downstream assertion vacuous.
     *
     * @param string|list<string> $permissionKeys
     */
    public function seedRoleWith($permissionKeys, string $scopeType = 'park', ?int $ownerKingdomId = null): int
    {
        $keys = is_array($permissionKeys) ? array_values($permissionKeys) : [$permissionKeys];

        $name = $this->marker . '_' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO ork_role (`name`, display_name, description, scope_type, is_system, kingdom_id)
             VALUES (:n, :d, \'Fixture role\', :st, 0, :k)'
        )->execute([
            ':n' => $name,
            ':d' => 'Test ' . $name,
            ':st' => $scopeType,
            ':k' => $ownerKingdomId ?? $this->kingdomId,
        ]);
        $roleId = (int) $this->pdo->lastInsertId();
        $this->roleIds[] = $roleId;

        foreach ($keys as $key) {
            $this->pdo->prepare(
                'INSERT INTO ork_role_permission (role_id, permission_id)
                 SELECT :r, permission_id FROM ork_permission WHERE `key` = :k'
            )->execute([':r' => $roleId, ':k' => $key]);
        }

        $granted = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM ork_role_permission WHERE role_id = ' . $roleId)
            ->fetchColumn();
        if ($granted !== count($keys)) {
            throw new RuntimeException(
                'Fixture check: expected ' . count($keys) . ' permission row(s) for ['
                . implode(', ', $keys) . '] but got ' . $granted
                . ' -- one of those keys has no ork_permission row.'
            );
        }

        return $roleId;
    }

    /**
     * Assign $roleId to $mundaneId at $scope, and bust the permission cache.
     *
     * An empty $scope is the all-zero (global) assignment. Every column is written
     * explicitly so a park grant can never accidentally carry a kingdom id -- the
     * loose-row shape that lets a park officer satisfy a kingdom-scope check.
     *
     * @param array{kingdom_id?: int, park_id?: int, event_id?: int, unit_id?: int} $scope
     */
    public function assignRole(int $mundaneId, int $roleId, array $scope = []): int
    {
        $this->pdo->prepare(
            'INSERT INTO ork_user_role (mundane_id, role_id, kingdom_id, park_id, event_id, unit_id)
             VALUES (:m, :r, :k, :p, :e, :u)'
        )->execute([
            ':m' => $mundaneId,
            ':r' => $roleId,
            ':k' => $scope['kingdom_id'] ?? 0,
            ':p' => $scope['park_id'] ?? 0,
            ':e' => $scope['event_id'] ?? 0,
            ':u' => $scope['unit_id'] ?? 0,
        ]);
        $userRoleId = (int) $this->pdo->lastInsertId();
        $this->userRoleIds[] = $userRoleId;

        Ork3::$Lib->rbacservice->InvalidateUserCache($mundaneId);

        return $userRoleId;
    }

    /**
     * A legacy ork_authorization row -- the bridge arm of checkPermissionOrAuthority().
     * Pass no scope for the all-zero row (which is what role='admin' means).
     *
     * @param array{kingdom_id?: int, park_id?: int, event_id?: int, unit_id?: int} $scope
     */
    public function grantLegacyAuthorization(int $mundaneId, array $scope = [], string $role = 'create'): int
    {
        $this->pdo->prepare(
            'INSERT INTO ork_authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (:m, :p, :k, :e, :u, :r)'
        )->execute([
            ':m' => $mundaneId,
            ':p' => $scope['park_id'] ?? 0,
            ':k' => $scope['kingdom_id'] ?? 0,
            ':e' => $scope['event_id'] ?? 0,
            ':u' => $scope['unit_id'] ?? 0,
            ':r' => $role,
        ]);
        $authId = (int) $this->pdo->lastInsertId();
        $this->authorizationIds[] = $authId;

        return $authId;
    }

    /** ork_authorization rows a test wrote by other means, so cleanup() still removes them. */
    public function trackAuthorizationId(int $authorizationId): void
    {
        $this->authorizationIds[] = $authorizationId;
    }

    public function cleanup(): void
    {
        foreach ($this->userRoleIds as $id) {
            $this->pdo->exec('DELETE FROM ork_user_role WHERE user_role_id = ' . $id);
        }
        foreach ($this->authorizationIds as $id) {
            $this->pdo->exec('DELETE FROM ork_authorization WHERE authorization_id = ' . $id);
        }
        foreach ($this->roleIds as $id) {
            $this->pdo->exec('DELETE FROM ork_user_role WHERE role_id = ' . $id);
            $this->pdo->exec('DELETE FROM ork_role_permission WHERE role_id = ' . $id);
            $this->pdo->exec('DELETE FROM ork_role WHERE role_id = ' . $id);
        }
        foreach ($this->mundaneIds as $id) {
            // Audit trails the domain writes as a side effect of a grant/revoke/officer
            // call. Nothing collects their ids, and they outlive the player they name, so
            // without this every suite that exercises a real domain method left rows behind
            // in the shared test database on every run.
            try {
                $this->pdo->exec(
                    'DELETE FROM ork_rbac_audit WHERE actor_mundane_id = ' . $id
                    . ' OR target_mundane_id = ' . $id
                );
            } catch (PDOException $e) {
                // Snapshot without the audit table -- nothing to clean.
            }
            try {
                $this->pdo->exec('DELETE FROM ork_danger_audit WHERE by_whom_id = ' . $id);
            } catch (PDOException $e) {
                // Snapshot without the audit table -- nothing to clean.
            }

            // Rows a test granted TO a seeded player, whose ids it never collected.
            $this->pdo->exec('DELETE FROM ork_user_role WHERE mundane_id = ' . $id);
            $this->pdo->exec('DELETE FROM ork_authorization WHERE mundane_id = ' . $id);
            $this->pdo->exec('DELETE FROM ork_session WHERE mundane_id = ' . $id);
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id = ' . $id);
        }

        $this->userRoleIds = [];
        $this->authorizationIds = [];
        $this->roleIds = [];
        $this->mundaneIds = [];
    }
}
