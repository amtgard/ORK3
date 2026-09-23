<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\ValidationException;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class WiringTest extends TestCase
{
    private Wiring $wiring;

    protected function setUp(): void
    {
        $this->wiring = new Wiring(ORK3_ROOT . '/tools/ork-db');
    }

    public function testMirrorAndSandboxDsns(): void
    {
        $this->assertStringContainsString('dbname=ork', $this->wiring->mirrorDsn());
        $this->assertStringContainsString('dbname=ork_test', $this->wiring->sandboxDsn());
    }

    public function testMirrorAndSandboxLabels(): void
    {
        $this->assertSame('127.0.0.1:19306/ork', $this->wiring->mirrorTargetLabel());
        $this->assertSame('127.0.0.1:19307/ork_test', $this->wiring->sandboxTargetLabel());
    }

    public function testAssertSandboxEndpointPassesForManifestValues(): void
    {
        $sandbox = $this->wiring->sandbox();
        $this->wiring->assertSandboxEndpoint(
            (string) $sandbox['host'],
            (int) $sandbox['port'],
            (string) $sandbox['database']
        );

        $this->addToAssertionCount(1);
    }

    public function testAssertSandboxEndpointRejectsMirrorPort(): void
    {
        $this->expectException(ValidationException::class);
        $this->wiring->assertSandboxEndpoint('127.0.0.1', 19306, 'ork_test');
    }

    public function testAssertMirrorEndpointPassesForManifestValues(): void
    {
        $mirror = $this->wiring->mirror();
        $this->wiring->assertMirrorEndpoint(
            (string) $mirror['host'],
            (int) $mirror['port'],
            (string) $mirror['database']
        );

        $this->addToAssertionCount(1);
    }

    public function testAssertMirrorEndpointRejectsSandboxPort(): void
    {
        $this->expectException(ValidationException::class);
        $this->wiring->assertMirrorEndpoint('127.0.0.1', 19307, 'ork');
    }

    public function testAssertSandboxEndpointRejectsWrongDatabaseName(): void
    {
        $this->expectException(ValidationException::class);
        $this->wiring->assertSandboxEndpoint('127.0.0.1', 19307, 'ork');
    }

    public function testAssertMirrorEndpointRejectsDefaultMysqlPort(): void
    {
        $this->expectException(ValidationException::class);
        $this->wiring->assertMirrorEndpoint('127.0.0.1', 3306, 'ork');
    }

    public function testAssertMirrorEndpointRejectsDisallowedHost(): void
    {
        $this->expectException(ValidationException::class);
        $this->wiring->assertMirrorEndpoint('mysql.example.com', 19306, 'ork');
    }

    public function testAssertMirrorEndpointRejectsWrongDatabaseName(): void
    {
        $this->expectException(ValidationException::class);
        $this->wiring->assertMirrorEndpoint('127.0.0.1', 19306, 'ork_test');
    }

    public function testAssertSandboxEndpointRejectsDisallowedHost(): void
    {
        $this->expectException(ValidationException::class);
        $this->wiring->assertSandboxEndpoint('mysql.example.com', 19307, 'ork_test');
    }

    public function testCredentialsUseManifestDefaults(): void
    {
        $credentials = $this->wiring->credentials();
        $this->assertSame('root', $credentials['user']);
        $this->assertSame('root', $credentials['password']);
    }
}
