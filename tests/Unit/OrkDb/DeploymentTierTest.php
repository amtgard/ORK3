<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\DeploymentTier;
use OrkDb\TierRefusalException;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class DeploymentTierTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/ork-db-tier-' . uniqid('', true);
        mkdir($this->tempRoot);
    }

    protected function tearDown(): void
    {
        foreach (['config.dev.php', 'config.php'] as $file) {
            $path = $this->tempRoot . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->tempRoot)) {
            rmdir($this->tempRoot);
        }

        putenv('ENVIRONMENT');
    }

    public function testClassifiesLocalWhenPortsReachableAndEnvironmentDev(): void
    {
        putenv('ENVIRONMENT=DEV');
        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::LOCAL, $info['tier']);
        $this->assertTrue($info['mirror_reachable']);
        $this->assertTrue($info['sandbox_reachable']);
    }

    public function testClassifiesProductionWhenSandboxUnreachable(): void
    {
        putenv('ENVIRONMENT=DEV');
        $tier = $this->makeTier(static fn (string $host, int $port): bool => $port === 19306);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::PRODUCTION, $info['tier']);
        $this->assertFalse($info['sandbox_reachable']);
    }

    public function testClassifiesProductionWhenConfigHostnameIsProduction(): void
    {
        file_put_contents(
            $this->tempRoot . '/config.dev.php',
            "<?php\ndefine('DB_HOSTNAME', 'mysql.amtgard.com');\n"
        );

        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::PRODUCTION, $info['tier']);
    }

    public function testClassifiesProductionWhenMirrorUnreachable(): void
    {
        putenv('ENVIRONMENT=DEV');
        $tier = $this->makeTier(static fn (string $host, int $port): bool => $port === 19307);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::PRODUCTION, $info['tier']);
        $this->assertFalse($info['mirror_reachable']);
    }

    public function testClassifiesProductionWhenLocalSignalMissing(): void
    {
        putenv('ENVIRONMENT=PRODUCTION');
        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::PRODUCTION, $info['tier']);
    }

    public function testClassifiesLocalWhenConfigUsesDockerHostname(): void
    {
        putenv('ENVIRONMENT=PRODUCTION');
        file_put_contents(
            $this->tempRoot . '/config.dev.php',
            "<?php\ndefine('DB_HOSTNAME', 'ork3-php8-db');\n"
        );

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
        putenv('ENVIRONMENT=DEV');
        $tier = $this->makeTier(static fn (): bool => true);
        $tier->refuseDataCommands('extract');

        $this->addToAssertionCount(1);
    }

    public function testClassifiesLocalUsingRepoConfigPhp(): void
    {
        putenv('ENVIRONMENT=PRODUCTION');
        file_put_contents(
            $this->tempRoot . '/config.php',
            "<?php\ndefine('DB_HOSTNAME', '127.0.0.1');\n"
        );

        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::LOCAL, $info['tier']);
    }

    public function testClassifiesLocalWhenAppStageDevInConfig(): void
    {
        putenv('ENVIRONMENT=PRODUCTION');
        file_put_contents(
            $this->tempRoot . '/config.dev.php',
            "<?php\ndefine('APP_STAGE', 'DEV');\n"
        );

        $tier = $this->makeTier(static fn (): bool => true);
        $info = $tier->classify();

        $this->assertSame(DeploymentTier::LOCAL, $info['tier']);
    }

    public function testProbePortReturnsFalseForClosedPort(): void
    {
        $this->assertFalse(DeploymentTier::probePort('127.0.0.1', 1, 0.2));
    }

    /** @param callable(string, int): bool $portReachable */
    private function makeTier(callable $portReachable): DeploymentTier
    {
        return new DeploymentTier(new Wiring(ORK3_ROOT . '/tools/ork-db'), $this->tempRoot, $portReachable);
    }
}
