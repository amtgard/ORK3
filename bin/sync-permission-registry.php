#!/usr/bin/env php
<?php

/**
 * Syncs PermissionRegistry (the in-code source of truth) to the ork_permission table,
 * and reports anything that has drifted apart from it.
 *
 * PermissionRegistry has always documented itself as the source of truth and offered
 * SyncToDatabase() to enforce that, but nothing ever called it -- no deploy step, no
 * CLI, no admin action. Parity survived on whoever remembered to hand-write a migration,
 * and the failure mode is quiet and confusing: PermissionRegistry::Exists() passes for a
 * newly-declared key while HasPermission() returns false for everyone except global
 * admins, because the join finds no ork_permission row.
 *
 * Run on deploy, after any change to the registry:
 *
 *     php bin/sync-permission-registry.php
 *
 * Preview without writing:
 *
 *     php bin/sync-permission-registry.php --dry-run
 *
 * Both forms need a readable config.php, which means inside the container. Outside it --
 * on a developer host or in CI -- prefix ENVIRONMENT=TEST to pick up the test config, or
 * the script fatals (exit 255) before it opens a connection:
 *
 *     ENVIRONMENT=TEST php bin/sync-permission-registry.php --dry-run
 *
 * What it does NOT do is delete. A key in the table but not in the registry is REPORTED,
 * never removed: it may belong to a branch that is not deployed yet, and dropping it
 * would silently revoke whatever roles hold it. Removals stay deliberate, in a migration
 * that also clears ork_role_permission (see 2026-08-31-rbac-coverage-expansion.sql).
 *
 * What it also does NOT do is GRANT. It writes ork_permission and nothing else, so a key
 * added here exists but is held by nobody -- including the crown roles, which are defined
 * as holding every scoped permission. That grant lives in the migration's CROSS JOIN, and
 * this script is not a substitute for it: it DETECTS the gap and exits 2 so the migration
 * gets written, rather than leaving a key dead on arrival for every Monarch.
 *
 * Exit codes: 0 = in sync (or synced); 1 = writes failed; 2 = drift needing a human
 * (missing keys under --dry-run, column drift, orphan rows, or a crown-role grant gap),
 * so CI can fail on it.
 */

// startup.php constructs Ghettocache unconditionally, so without the memcached extension
// this script fatals before it reads a row -- and CI (bin/run-unit-tests.sh) runs on the
// host, where that extension is not installed. Same shim, and the same reason for it, as
// tests/bootstrap.php. Only used when the real extension is absent.
if (!class_exists('Memcached', false)) {
    class Memcached
    {
        public function addServer($host, $port): bool
        {
            return true;
        }

        public function get(string $key): mixed
        {
            return false;
        }

        public function set(string $key, mixed $value, int $expiration = 0): bool
        {
            return true;
        }

        public function delete(string $key): bool
        {
            return true;
        }

        /** @return array<string, array<string, int>> */
        public function getStats(): array
        {
            return ['localhost:11211' => ['time' => time()]];
        }
    }
}

$_SERVER['HTTP_HOST'] ??= 'localhost';

require_once dirname(__DIR__) . '/startup.php';

/** Roles defined as holding every permission a kingdom can hold. Mirrors RbacRegistryParityTest. */
const CROWN_ROLES = ['monarch', 'regent', 'prime_minister'];

$dry_run = in_array('--dry-run', array_slice($argv, 1), true);

/**
 * @return array role name => count of non-global permissions held
 *
 * Reads through RBACService rather than joining ork_role/ork_role_permission/ork_permission
 * here: DB work belongs in system/lib/ork3, and the catalog already learned that lesson
 * (PermissionRegistry::GetDatabaseDefinitions() exists because four readers had spelled the
 * same SELECT four ways).
 */
function crown_role_holdings()
{
    $rbac = Ork3::$Lib->rbacservice;

    $held = array_fill_keys(CROWN_ROLES, 0);

    // kingdom_id 0 asks for the shared templates; the returned KingdomId is re-checked
    // because a kingdom's own copy of a crown-role name is a different role entirely.
    foreach ($rbac->GetAvailableRoles(0) as $role) {
        $name = strtolower((string) $role['Name']);
        if (!array_key_exists($name, $held) || (int) $role['KingdomId'] !== 0) {
            continue;
        }

        foreach ($rbac->GetRolePermissions((int) $role['RoleId']) as $perm) {
            if ((string) $perm['ScopeType'] !== 'global') {
                $held[$name]++;
            }
        }
    }

    return $held;
}

$registry = Ork3::$Lib->permissionregistry;
$registry_keys = array_keys(PermissionRegistry::GetAll());
sort($registry_keys);

$diff = $registry->DiffAgainstDatabase();

// One read of the catalog, reused by the printed count and by the scoped total further
// down. GetDatabaseKeys() is array_keys(GetDatabaseDefinitions()), so asking for both was
// two full-catalog SELECTs for the same rows.
$db_definitions = $registry->GetDatabaseDefinitions();

echo 'Registry: ' . count($registry_keys) . " permissions\n";
echo 'Database: ' . count($db_definitions) . " permissions\n";

if (count($diff['missing']) > 0) {
    echo "\nIn the registry but NOT in the database (" . count($diff['missing']) . "):\n";
    foreach ($diff['missing'] as $key) {
        echo '  + ' . $key . "\n";
    }
} else {
    echo "\nEvery registry permission is present in the database.\n";
}

if (count($diff['drifted']) > 0) {
    echo "\nPresent on both sides but with different definitions (" . count($diff['drifted']) . "):\n";
    foreach ($diff['drifted'] as $key => $columns) {
        echo '  ~ ' . $key . ' (' . implode(', ', $columns) . ")\n";
    }
    echo "A stale scope_type is the dangerous one: the key resolves, but HasPermission()'s\n"
        . "cascade and global-scope logic then run against the wrong scope.\n";
}

$exit = 0;

if ($dry_run) {
    echo "\n--dry-run: no writes made.\n";
    if (count($diff['missing']) > 0 || count($diff['drifted']) > 0) {
        // The exact failure this script exists for. Reporting it and exiting 0 is why
        // CI could run --dry-run against a database missing ten permissions and pass.
        echo "Run without --dry-run to write these.\n";
        $exit = 2;
    }
} else {
    $result = $registry->SyncToDatabase();
    echo "\nSynced " . (int) $result['synced'] . " permission definitions.\n";
    if (count($result['errors']) > 0) {
        echo "ERRORS:\n";
        foreach ($result['errors'] as $error) {
            echo '  ! ' . $error . "\n";
        }
        exit(1);
    }

    // Re-read, so what is printed below is observed rather than assumed.
    $diff = $registry->DiffAgainstDatabase();
    $db_definitions = $registry->GetDatabaseDefinitions();
    if (count($diff['missing']) > 0 || count($diff['drifted']) > 0) {
        echo "STILL out of sync after the write -- the sync reported success but the table\n"
            . "does not agree with the registry. This needs a human.\n";
        $exit = 2;
    }
}

if (count($diff['orphans']) > 0) {
    echo "\nIn the database but NOT in the registry (" . count($diff['orphans']) . "):\n";
    foreach ($diff['orphans'] as $key) {
        echo '  ? ' . $key . "\n";
    }
    echo "\nNot removed automatically -- these may belong to an undeployed branch, and\n"
        . "dropping one would revoke it from every role that holds it. If they are genuinely\n"
        . "dead, remove them in a migration that clears ork_role_permission first.\n";
    $exit = 2;
}

// Permissions exist; nothing here grants them. A key with no crown-role mapping is dead
// on arrival for every Monarch, Regent and Prime Minister, and only a migration can fix it.
// The catalog is counted from the single read taken above -- refreshed after a write --
// not a second COUNT(*).
$scoped = 0;
foreach ($db_definitions as $definition) {
    if ($definition['scope_type'] !== 'global') {
        $scoped++;
    }
}

$gaps = [];
foreach (crown_role_holdings() as $role_name => $held) {
    if ($held !== $scoped) {
        $gaps[] = '  ! ' . $role_name . ' holds ' . $held . ' of ' . $scoped . ' scoped permissions';
    }
}

if (count($gaps) > 0) {
    echo "\nCrown roles are missing grants:\n" . implode("\n", $gaps) . "\n";
    echo "\nThis script writes ork_permission only. Re-run the crown CROSS JOIN in a migration\n"
        . "(excluding scope_type = 'global' -- a kingdom's Monarch is not an installation\n"
        . "operator); see db-migrations/2026-08-31-rbac-coverage-expansion.sql section 2.\n";
    $exit = 2;
}

if ($exit === 0) {
    echo "\nRegistry and database agree.\n";
}

exit($exit);
