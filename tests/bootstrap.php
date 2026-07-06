<?php

declare(strict_types=1);

/**
 * Shared bootstrap for ORK3 backend unit and integration tests.
 *
 * Loads the full ORK3 runtime via startup.php with ENVIRONMENT=TEST
 * (config.test.php). See docs/megiddo/refactor/06-test-framework.md.
 */

define('ORK3_ROOT', dirname(__DIR__));
$_SERVER['HTTP_HOST'] ??= 'localhost';

putenv('ENVIRONMENT=TEST');

// Legacy ORK3 emits many dynamic-property deprecations on PHP 8.2+ during startup.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
set_error_handler(static function (int $errno, string $errstr): bool {
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        return true;
    }
    return false;
}, E_ALL);

// Host-side PHPUnit runs without the memcached extension or daemon. Production
// code constructs Ghettocache unconditionally during startup.
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
    }
}

require_once ORK3_ROOT . '/startup.php';

require_once __DIR__ . '/Support/EventRsvpFixture.php';
require_once __DIR__ . '/Support/AuthorizationAddFixture.php';
require_once __DIR__ . '/Support/BannerFixture.php';
require_once __DIR__ . '/Support/EventPlanningFixture.php';
require_once __DIR__ . '/Support/KingdomProfileFixture.php';
require_once DIR_UI . 'model/model.Event.php';
require_once DIR_UI . 'model/model.Attendance.php';
require_once DIR_SERVICE . 'Common.definitions.php';

/**
 * Whether the configured test database accepts connections.
 */
function ork3_test_db_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $host = getenv('ORK3_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('ORK3_TEST_DB_PORT') ?: '19306';
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname=ork",
            'ork',
            'secret',
            [PDO::ATTR_TIMEOUT => 2]
        );
        $available = (bool) $pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable) {
        $available = false;
    }

    return $available;
}
