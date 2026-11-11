<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\DeploymentTier;
use OrkDb\TierRefusalException;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class DeploymentTierTest extends TestCase
{
    /** @var list<string> */
    private array $envKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->envKeys as $key) {
            putenv($key);
        }
        $this->envKeys = [];
    }

    public function testClassifiesLocalWhenPortsReachableAndEnvironmentDev(): void
    {
        $this->setEnv('ENVIRONMENT', 'DEV');
        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::LOCAL, $info['tier']);
        $this->assertTrue($info['mirror_reachable']);
        $this->assertTrue($info['sandbox_reachable']);
    }

    public function testClassifiesProductionWhenSandboxUnreachable(): void
    {
        $this->setEnv('ENVIRONMENT', 'DEV');
        $tier = $this->makeTier(static fn (string $host, int $port): bool => $port === 19306);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::PRODUCTION, $info['tier']);
        $this->assertFalse($info['sandbox_reachable']);
    }

    public function testClassifiesProductionWhenConfigHostnameIsProduction(): void
    {
        $this->setEnv('DB_HOSTNAME', 'mysql.amtgard.com');

        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::PRODUCTION, $info['tier']);
    }

    public function testClassifiesProductionWhenMirrorUnreachable(): void
    {
        $this->setEnv('ENVIRONMENT', 'DEV');
        $tier = $this->makeTier(static fn (string $host, int $port): bool => $port === 19307);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::PRODUCTION, $info['tier']);
        $this->assertFalse($info['mirror_reachable']);
    }

    public function testClassifiesProductionWhenLocalSignalMissing(): void
    {
        $this->setEnv('ENVIRONMENT', 'PRODUCTION');
        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::PRODUCTION, $info['tier']);
    }

    public function testClassifiesLocalWhenConfigUsesDockerHostname(): void
    {
        $this->setEnv('ENVIRONMENT', 'PRODUCTION');
        $this->setEnv('ORK3_DB_HOST', 'ork3-php8-db');

        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::LOCAL, $info['tier']);
    }

    public function testClassifiesLocalWhenOrk3TestDbHostIsLocal(): void
    {
        $this->setEnv('ENVIRONMENT', 'PRODUCTION');
        $this->setEnv('ORK3_TEST_DB_HOST', '127.0.0.1');

        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::LOCAL, $info['tier']);
    }

    public function testRefuseDataCommandsThrowsOnProductionTier(): void
    {
        $tier = $this->makeTier(static fn (string $host, int $port): bool => $port === 19306);

        $this->expectException(TierRefusalException::class);
        $tier->refuseDataCommands('extract');
    }

    public function testRefuseDataCommandsAllowsLocalTier(): void
    {
        $this->setEnv('ENVIRONMENT', 'DEV');
        $tier = $this->makeTier(static fn (): bool => true);
        $tier->refuseDataCommands('extract');

        $this->addToAssertionCount(1);
    }

    public function testClassifiesLocalWhenDbHostnameEnvIsLocal(): void
    {
        $this->setEnv('ENVIRONMENT', 'PRODUCTION');
        $this->setEnv('DB_HOSTNAME', '127.0.0.1');

        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::LOCAL, $info['tier']);
    }

    public function testClassifiesLocalWhenAppStageDevInEnv(): void
    {
        $this->setEnv('ENVIRONMENT', 'PRODUCTION');
        $this->setEnv('APP_STAGE', 'DEV');

        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::LOCAL, $info['tier']);
    }

    public function testProbePortReturnsFalseForClosedPort(): void
    {
        $this->assertFalse(DeploymentTier::probePort('127.0.0.1', 1, 0.2));
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $this->envKeys[] = $key;
    }

    /** @param callable(string, int): bool $portReachable */
    private function makeTier(callable $portReachable): DeploymentTier
    {
        return new DeploymentTier(new Wiring(ORK3_ROOT . '/tools/ork-db'), ORK3_ROOT, $portReachable);
    }
}
