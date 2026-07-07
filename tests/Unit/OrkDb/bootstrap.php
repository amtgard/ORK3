<?php

declare(strict_types=1);

define('ORK3_ROOT', dirname(__DIR__, 3));

require_once ORK3_ROOT . '/vendor/autoload.php';

require_once ORK3_ROOT . '/tools/ork-db/lib/IdNamespace.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/Json5.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/ValidationException.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/TierRefusalException.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/Wiring.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/DeploymentTier.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/MigrationClassifier.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/SchemaIntrospection.php';
require_once ORK3_ROOT . '/tools/ork-db/lib/LastRender.php';
require_once ORK3_ROOT . '/tools/ork-db/Validate.php';
require_once ORK3_ROOT . '/tools/ork-db/Extract.php';
require_once ORK3_ROOT . '/tools/ork-db/Render.php';
require_once ORK3_ROOT . '/tools/ork-db/Init.php';
require_once ORK3_ROOT . '/tools/ork-db/Apply.php';
require_once ORK3_ROOT . '/tools/ork-db/Use.php';
require_once ORK3_ROOT . '/tools/ork-db/Bootstrap.php';
require_once ORK3_ROOT . '/tools/ork-db/DriftCheck.php';
require_once ORK3_ROOT . '/tools/ork-db/SchemaDiff.php';
require_once ORK3_ROOT . '/tools/ork-db/DeploySandbox.php';
require_once ORK3_ROOT . '/tools/ork-db/GenerateAssets.php';
require_once ORK3_ROOT . '/tools/ork-db/DeployAssets.php';

/**
 * Whether the sandbox database used by ork-db tools accepts connections.
 */
function ork3_sandbox_db_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $pdo = new PDO(
            'mysql:host=127.0.0.1;port=19307;dbname=ork_test;charset=utf8mb4',
            'root',
            'root',
            [PDO::ATTR_TIMEOUT => 2]
        );
        $available = (bool) $pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable) {
        $available = false;
    }

    return $available;
}

function ork3_mirror_db_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $pdo = new PDO(
            'mysql:host=127.0.0.1;port=19306;dbname=ork;charset=utf8mb4',
            'root',
            'root',
            [PDO::ATTR_TIMEOUT => 2]
        );
        $available = (bool) $pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable) {
        $available = false;
    }

    return $available;
}

function ork3_ensure_mirror_prod_canary(): void
{
    $migration = ORK3_ROOT . '/db-migrations/2026-07-07-add-prod-canary.sql';
    if (!is_readable($migration)) {
        return;
    }

    $command = 'docker exec -i ork3-php8-db mariadb -uroot -proot ork < '
        . escapeshellarg($migration) . ' 2>/dev/null';
    exec($command);
}

function ork3_app_base_url(): string
{
    $base = getenv('ORK3_E2E_BASE_URL') ?: 'http://127.0.0.1:19080/orkui/';

    return str_replace('://localhost:', '://127.0.0.1:', $base);
}

function ork3_app_reachable(): bool
{
    static $reachable = null;
    if ($reachable !== null) {
        return $reachable;
    }

    $url = ork3_app_base_url();
    if (!str_ends_with($url, '/')) {
        $url .= '/';
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]);

    $headers = @get_headers($url, true, $context);
    if (!is_array($headers)) {
        $reachable = false;

        return $reachable;
    }

    $statusLine = $headers[0] ?? '';
    $reachable = is_string($statusLine)
        && preg_match('/\s(200|302|405)\s/', $statusLine) === 1;

    return $reachable;
}
