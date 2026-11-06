<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * The two invariants that keep RBAC honest, and that nothing enforced before.
 *
 *  1. PermissionRegistry and ork_permission agree.
 *     PermissionRegistry calls itself the source of truth and ships SyncToDatabase() to
 *     make that so, but nothing ever called it -- parity survived on whoever remembered
 *     to hand-write a migration. The failure is quiet: PermissionRegistry::Exists()
 *     passes for a newly-declared key while HasPermission() returns false for everyone
 *     but global admins, because the join finds no permission row. A permission that
 *     grants nothing looks identical to a permission the user simply lacks.
 *
 *  2. The crown roles hold every scoped permission.
 *     rbac-seed.sql granted "all permissions" to monarch / regent / prime_minister with
 *     a CROSS JOIN executed once, so it covered exactly the permissions that existed
 *     that day. The qualification-test migration noticed and re-ran the join by hand;
 *     nothing enforced the habit. This test replaces that habit -- the next migration
 *     that forgets is caught here rather than by a Monarch who cannot do something the
 *     role claims to cover.
 *
 * Read-only: seeds nothing, cleans up nothing.
 */
final class RbacRegistryParityTest extends TestCase
{
    /** Roles that are defined as holding every permission a kingdom can hold. */
    private const CROWN_ROLES = ['monarch', 'regent', 'prime_minister'];

    private ?PDO $pdo = null;

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

    /**
     * The catalog as the database holds it, read through the class that owns it.
     *
     * PermissionRegistry::GetDatabaseKeys() is the single reader; this test asserting on
     * its own hand-written copy of the query is how "what does the database think the
     * catalog is" ended up with four implementations and no owner.
     *
     * @return list<string>
     */
    private function databasePermissionKeys(): array
    {
        return array_map('strval', Ork3::$Lib->permissionregistry->GetDatabaseKeys());
    }

    public function testEveryRegistryPermissionExistsInTheDatabase(): void
    {
        $registry = array_keys(PermissionRegistry::GetAll());
        $missing = array_values(array_diff($registry, $this->databasePermissionKeys()));

        self::assertSame(
            [],
            $missing,
            "These permissions are declared in PermissionRegistry but have no ork_permission row, "
            . "so HasPermission() returns false for everyone except global admins. Add them in a "
            . "migration (or run bin/sync-permission-registry.php): " . implode(', ', $missing)
        );
    }

    public function testDatabaseHasNoPermissionsMissingFromTheRegistry(): void
    {
        $registry = array_keys(PermissionRegistry::GetAll());
        $orphans = array_values(array_diff($this->databasePermissionKeys(), $registry));

        self::assertSame(
            [],
            $orphans,
            "These ork_permission rows have no PermissionRegistry entry. HasPermission() refuses "
            . "unknown keys outright, so any role holding one grants nothing, and the permissions "
            . "grid would advertise a capability that cannot be exercised: " . implode(', ', $orphans)
        );
    }

    public function testCrownRolesHoldEveryScopedPermission(): void
    {
        $pdo = $this->pdoConnection();

        // Global permissions are deliberately excluded: they are installation-operator
        // capabilities (purge logs, edit the shared award catalog) with no per-kingdom
        // meaning, and a kingdom's Monarch is not an installation operator. They belong
        // to the ork_admin role, covered separately below.
        $expected = (int) $pdo->query(
            "SELECT COUNT(*) FROM ork_permission WHERE scope_type <> 'global'"
        )->fetchColumn();
        self::assertGreaterThan(0, $expected, 'Fixture check: the permission catalog is empty.');

        foreach (self::CROWN_ROLES as $roleName) {
            $held = (int) $pdo->query(
                "SELECT COUNT(*)
                   FROM ork_role r
                   JOIN ork_role_permission rp ON rp.role_id = r.role_id
                   JOIN ork_permission p       ON p.permission_id = rp.permission_id
                  WHERE r.name = " . $pdo->quote($roleName) . "
                    AND r.kingdom_id = 0
                    AND p.scope_type <> 'global'"
            )->fetchColumn();

            self::assertSame(
                $expected,
                $held,
                "The '{$roleName}' role is defined as holding every scoped permission but holds "
                . "{$held} of {$expected}. A migration that adds a permission must also re-run the "
                . "CROSS JOIN that grants it to the crown roles -- rbac-seed.sql's original join "
                . "only ever covered the permissions that existed the day it ran."
            );
        }
    }

    public function testCrownRolesHoldNoGlobalPermissions(): void
    {
        $pdo = $this->pdoConnection();

        foreach (self::CROWN_ROLES as $roleName) {
            $held = (int) $pdo->query(
                "SELECT COUNT(*)
                   FROM ork_role r
                   JOIN ork_role_permission rp ON rp.role_id = r.role_id
                   JOIN ork_permission p       ON p.permission_id = rp.permission_id
                  WHERE r.name = " . $pdo->quote($roleName) . "
                    AND r.kingdom_id = 0
                    AND p.scope_type = 'global'"
            )->fetchColumn();

            self::assertSame(
                0,
                $held,
                "The '{$roleName}' role holds {$held} installation-wide permission(s). Global "
                . "permissions cover maintenance, diagnostics, and the shared catalogs; granting "
                . "them to a kingdom role hands every crowned head in the game the ability to "
                . "purge the logs."
            );
        }
    }

    public function testOrkAdminRoleHoldsEveryGlobalPermission(): void
    {
        $pdo = $this->pdoConnection();

        $expected = (int) $pdo->query(
            "SELECT COUNT(*) FROM ork_permission WHERE scope_type = 'global'"
        )->fetchColumn();
        self::assertGreaterThan(0, $expected, 'Fixture check: no global permissions are defined.');

        $held = (int) $pdo->query(
            "SELECT COUNT(*)
               FROM ork_role r
               JOIN ork_role_permission rp ON rp.role_id = r.role_id
               JOIN ork_permission p       ON p.permission_id = rp.permission_id
              WHERE r.name = 'ork_admin'
                AND r.kingdom_id = 0
                AND p.scope_type = 'global'"
        )->fetchColumn();

        self::assertSame(
            $expected,
            $held,
            "The 'ork_admin' role is the only way to delegate an installation-wide capability "
            . "without handing over an all-zero-scope admin row. It holds {$held} of {$expected}."
        );
    }

    public function testNoRolePermissionRowsPointAtADeletedPermission(): void
    {
        $orphaned = (int) $this->pdoConnection()->query(
            'SELECT COUNT(*)
               FROM ork_role_permission rp
               LEFT JOIN ork_permission p ON p.permission_id = rp.permission_id
              WHERE p.permission_id IS NULL'
        )->fetchColumn();

        self::assertSame(
            0,
            $orphaned,
            "{$orphaned} ork_role_permission row(s) reference a permission that no longer exists. "
            . 'There is no foreign key between these tables, so a migration that deletes a '
            . 'permission must clear its mappings FIRST -- otherwise the leftovers are invisible '
            . 'to every query that joins through ork_permission.'
        );
    }

    public function testEveryPermissionUsesAKnownScopeAndCategory(): void
    {
        // ork_permission.scope_type is an enum, so the database enforces that column.
        // Category is a free varchar, and a typo there silently drops a permission out of
        // whichever UI groups by category -- it renders under a heading nobody expects, or
        // not at all. The registry is the authority for both.
        $knownScopes = ['global', 'kingdom', 'park', 'event', 'unit'];
        $knownCategories = ['config', 'award', 'officer', 'heraldry', 'auth', 'event', 'player', 'financial', 'system'];

        foreach (PermissionRegistry::GetAll() as $key => $definition) {
            self::assertContains(
                $definition[2],
                $knownScopes,
                "Permission {$key} declares scope_type '{$definition[2]}', which ork_permission's "
                . 'enum will reject on insert.'
            );
            self::assertContains(
                $definition[3],
                $knownCategories,
                "Permission {$key} declares category '{$definition[3]}'. Add it to this test and to "
                . 'the category labels in Controller_OfficerAdminAjax, or the permission renders '
                . 'under a heading built from the raw slug.'
            );
            self::assertNotSame('', trim((string) $definition[0]), "Permission {$key} has no display name.");
            self::assertNotSame('', trim((string) $definition[1]), "Permission {$key} has no description.");
        }
    }

    /**
     * Permissions that are declared and synced on purpose but enforced nowhere.
     *
     * Keep this list SHORT and justified. Anything here is a key the permissions grid
     * offers an officer and that grants them nothing.
     */
    private const UNENFORCED_BY_DESIGN = [
        // Park creation and claiming are gated by their own legacy authority rules; the
        // keys exist so the catalogue can describe the capability.
        'kingdom.park.create',
        'kingdom.park.claim',
    ];

    /**
     * Every registry key is read by some PHP call site.
     *
     * The two parity tests above compare the registry with ork_permission -- they prove
     * the two agree, never that either is backed by code. That is invisible to them by
     * construction: a key can exist in the registry, exist in the table, be granted to a
     * role, be displayed in the grid, and still be checked by nothing. The audit that
     * prompted this test found six such keys by hand.
     *
     * Templates are excluded deliberately: the permission grid and the officer console
     * enumerate the whole catalogue, so a key's presence there says only that it was
     * rendered, not that anything enforces it.
     */
    public function testEveryRegistryPermissionIsReadBySomeCallSite(): void
    {
        $usage = $this->permissionKeyUsage();

        $unused = [];
        foreach (array_keys(PermissionRegistry::GetAll()) as $key) {
            if (in_array($key, self::UNENFORCED_BY_DESIGN, true)) {
                continue;
            }
            if (($usage[$key] ?? []) === []) {
                $unused[] = $key;
            }
        }

        self::assertSame(
            [],
            $unused,
            'These permissions are read by no PHP call site, so granting them changes nothing '
            . 'while the grid advertises them as a capability. Wire them to the gate their '
            . 'description names, delete them, or -- if the exemption is deliberate -- add them '
            . 'to UNENFORCED_BY_DESIGN with a reason: ' . implode(', ', $unused)
        );
    }

    /**
     * Companion to the check above, for the subtler shape: the key IS referenced, but
     * only from inside a private method nothing calls. Report::_authorizeKingdomParkReportScope()
     * and _authorizeReportPlayerScope() were exactly this -- four gates swapped onto
     * park.report.view under a comment implying the gap was closed, in methods that run
     * zero times.
     */
    public function testNoPermissionIsReferencedOnlyFromAnUncalledPrivateMethod(): void
    {
        $all = $this->permissionKeyUsage(false);
        $live = $this->permissionKeyUsage(true);

        $deadOnly = [];
        foreach (array_keys(PermissionRegistry::GetAll()) as $key) {
            if (in_array($key, self::UNENFORCED_BY_DESIGN, true)) {
                continue;
            }
            if (($all[$key] ?? []) !== [] && ($live[$key] ?? []) === []) {
                $deadOnly[] = $key . ' (only in ' . implode(', ', array_unique($all[$key])) . ')';
            }
        }

        self::assertSame(
            [],
            $deadOnly,
            'These permissions are referenced only from private methods that nothing calls, so '
            . 'the gate never runs and the coverage story is broader than the enforcement: '
            . implode('; ', $deadOnly)
        );
    }

    /**
     * key => list of "file::method" references across orkui/ and system/lib/ork3/.
     *
     * PermissionRegistry itself is excluded (it declares every key by definition). With
     * $skipUncalledPrivateMethods, a reference inside a private/protected method whose
     * name appears in no `$this->` / `self::` / `static::` call in the same file is
     * dropped: the method is unreachable, so the gate inside it never runs.
     *
     * @return array<string, list<string>>
     */
    private function permissionKeyUsage(bool $skipUncalledPrivateMethods = true): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        $ui = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/orkui'));
        foreach ($ui as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        foreach (glob($root . '/system/lib/ork3/*.php') as $file) {
            if (basename($file) !== 'class.PermissionRegistry.php') {
                $files[] = $file;
            }
        }

        $keys = array_keys(PermissionRegistry::GetAll());
        $usage = [];

        foreach ($files as $path) {
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }

            // Blank every comment before scanning, preserving byte offsets so the method
            // boundaries below still line up. Scanning the raw text counted a DOCBLOCK that
            // merely NAMES a key as a call site -- which defeats the whole point of this
            // test. Proven by mutation: replacing event.reconcile's sole real gate in
            // EventPlanning while leaving the comment three lines above it naming the key
            // left both usage tests green, so the key was dead and the net did not notice.
            // event.reconcile and unit.auth.manage each have as many comment references as
            // code references today, so this is the live case, not a hypothetical.
            $src = '';
            foreach (token_get_all($raw) as $token) {
                if (is_array($token)) {
                    $text = $token[1];
                    $src .= (($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT))
                        // Same byte length, no key text, and newlines kept so any
                        // line-oriented reading of these offsets stays honest.
                        ? preg_replace('/[^\n]/', ' ', $text)
                        : $text;
                } else {
                    $src .= $token;
                }
            }

            // Method boundaries, in file order, with visibility and reachability.
            preg_match_all(
                '/(private|protected|public)?\s*(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
                $src,
                $matches,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER
            );
            $methods = [];
            foreach ($matches as $match) {
                $visibility = trim($match[1][0]);
                $name = $match[2][0];
                $hidden = ($visibility === 'private' || $visibility === 'protected');
                $methods[] = [
                    'offset' => $match[0][1],
                    'name' => $name,
                    'unreachable' => $hidden && !preg_match(
                        '/(?:\$this->|self::|static::)' . preg_quote($name, '/') . '\s*\(/',
                        $src
                    ),
                ];
            }

            foreach ($keys as $key) {
                $offset = 0;
                while (($offset = strpos($src, $key, $offset)) !== false) {
                    $enclosing = null;
                    foreach ($methods as $method) {
                        if ($method['offset'] > $offset) {
                            break;
                        }
                        $enclosing = $method;
                    }
                    $offset += strlen($key);

                    if ($skipUncalledPrivateMethods && $enclosing !== null && $enclosing['unreachable']) {
                        continue;
                    }
                    $usage[$key][] = basename($path)
                        . ($enclosing !== null ? '::' . $enclosing['name'] : '');
                }
            }
        }

        return $usage;
    }
}
