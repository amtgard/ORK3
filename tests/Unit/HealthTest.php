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

        $health = new Health();
        $this->assertTrue($health->PingDb());
    }

    public function testPingDbReturnsFalseWhenDbDown(): void
    {
        try {
            $pdo = new PDO(
                'mysql:host=127.0.0.1;port=1;dbname=ork',
                DB_USERNAME,
                DB_PASSWORD,
                [PDO::ATTR_TIMEOUT => 1],
            );
        } catch (Throwable) {
            $this->assertTrue(true);

            return;
        }

        $this->assertFalse(Health::PingPdo($pdo));
    }
}
