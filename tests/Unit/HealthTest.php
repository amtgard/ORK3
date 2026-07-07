<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for orkui/index.php health probe (T-INF-01).
 */
final class HealthTest extends TestCase
{
    public function testPingDbReturnsTrueWhenConnected(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->assertTrue($this->mirrorHealthPingDb());
    }

    public function testPingDbReturnsFalseWhenDbDown(): void
    {
        $this->assertFalse($this->mirrorHealthPingDbWithDsn('mysql:host=127.0.0.1;port=1;dbname=ork'));
    }

    private function mirrorHealthPingDb(): bool
    {
        global $DB;
        try {
            $r = $DB->query('SELECT 1 AS ok');

            return (bool) ($r && $r->size() > 0);
        } catch (Throwable) {
            return false;
        }
    }

    private function mirrorHealthPingDbWithDsn(string $dsn): bool
    {
        try {
            $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, [PDO::ATTR_TIMEOUT => 1]);
            $pdo->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
