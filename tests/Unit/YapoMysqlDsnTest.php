<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the 2026-08-18 prod outage: legacy configs smuggle
 * the DB port inside DB_HOSTNAME PDO-DSN style ('host;port=24306'). The
 * explicit-port DSN produced a duplicate port key and PDO dialed the
 * fallback port instead. splitHostPort() must honor an embedded port over
 * the fallback and hand back a clean host.
 */
final class YapoMysqlDsnTest extends TestCase
{
    public function testPlainHostUsesFallbackPort(): void
    {
        $this->assertSame(
            ['db.example.com', 3306],
            YapoMysql::splitHostPort('db.example.com', 3306)
        );
    }

    public function testPlainHostHonorsExplicitFallback(): void
    {
        $this->assertSame(
            ['127.0.0.1', 19307],
            YapoMysql::splitHostPort('127.0.0.1', 19307)
        );
    }

    public function testEmbeddedPortWinsOverFallback(): void
    {
        // The exact production shape that caused the outage.
        $this->assertSame(
            ['db.apps.amtgard.com', 24306],
            YapoMysql::splitHostPort('db.apps.amtgard.com;port=24306', 3306)
        );
    }

    public function testEmbeddedPortWinsEvenWhenFallbackDiffers(): void
    {
        $this->assertSame(
            ['db.apps.amtgard.com', 24306],
            YapoMysql::splitHostPort('db.apps.amtgard.com;port=24306', 19307)
        );
    }

    public function testEmbeddedPortWithSpacesAndCase(): void
    {
        $this->assertSame(
            ['db.example.com', 24306],
            YapoMysql::splitHostPort('db.example.com; PORT = 24306', 3306)
        );
    }

    public function testTrailingSemicolonIsStripped(): void
    {
        $this->assertSame(
            ['db.example.com', 24306],
            YapoMysql::splitHostPort('db.example.com;port=24306;', 3306)
        );
    }
}
