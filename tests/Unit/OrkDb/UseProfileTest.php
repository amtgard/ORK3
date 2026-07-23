<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\DeploymentTier;
use OrkDb\TierRefusalException;
use OrkDb\UseProfile;
use OrkDb\ValidationException;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class UseProfileTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/ork-db-use-' . uniqid('', true);
        mkdir($this->tempRoot);
    }

    protected function tearDown(): void
    {
        $profileFile = $this->tempRoot . '/.ork3-db.local';
        if (is_file($profileFile)) {
            unlink($profileFile);
        }
        if (is_dir($this->tempRoot)) {
            rmdir($this->tempRoot);
        }
    }

    public function testRunWritesProfileFileAndRestartsAppOnLocalTier(): void
    {
        putenv('ENVIRONMENT=DEV');
        $restarted = false;
        $use = new UseProfile(
            $this->makeLocalTier(),
            $this->tempRoot,
            static function (array $command) use (&$restarted): int {
                $restarted = $command[0] === 'docker' && end($command) === 'ork3app';

                return 0;
            }
        );

        $result = $use->run(UseProfile::PROFILE_DEV);

        $this->assertTrue($restarted);
        $this->assertTrue($result['changed']);
        $this->assertSame(UseProfile::PROFILE_DEV, $use->readCurrentProfile());
        $this->assertSame(
            'ORK3_DB_PROFILE=dev' . PHP_EOL,
            file_get_contents($this->tempRoot . '/.ork3-db.local')
        );
        putenv('ENVIRONMENT');
    }

    public function testRunProdIsNoOpOnProductionTier(): void
    {
        $restarted = false;
        $use = new UseProfile(
            $this->makeProductionTier(),
            $this->tempRoot,
            static function (array $command) use (&$restarted): int {
                $restarted = true;

                return 0;
            }
        );

        $result = $use->run(UseProfile::PROFILE_PROD);

        $this->assertFalse($restarted);
        $this->assertFalse($result['changed']);
        $this->assertFalse(is_file($this->tempRoot . '/.ork3-db.local'));
        $this->assertStringContainsString('production host', implode("\n", $result['lines']));
    }

    public function testRunDevRefusesOnProductionTier(): void
    {
        $use = new UseProfile($this->makeProductionTier(), $this->tempRoot);

        $this->expectException(TierRefusalException::class);
        $use->run(UseProfile::PROFILE_DEV);
    }

    public function testRunRejectsUnknownProfile(): void
    {
        putenv('ENVIRONMENT=DEV');
        $use = new UseProfile($this->makeLocalTier(), $this->tempRoot);

        $this->expectException(ValidationException::class);
        $use->run('staging');
        putenv('ENVIRONMENT');
    }

    public function testReadCurrentProfileDefaultsToProd(): void
    {
        $use = new UseProfile($this->makeLocalTier(), $this->tempRoot);

        $this->assertSame(UseProfile::PROFILE_PROD, $use->readCurrentProfile());
    }

    public function testRunSkipsWriteWhenProfileUnchanged(): void
    {
        putenv('ENVIRONMENT=DEV');
        file_put_contents(
            $this->tempRoot . '/.ork3-db.local',
            'ORK3_DB_PROFILE=prod' . PHP_EOL
        );

        $restartCount = 0;
        $use = new UseProfile(
            $this->makeLocalTier(),
            $this->tempRoot,
            static function (array $command) use (&$restartCount): int {
                $restartCount++;

                return 0;
            }
        );

        $result = $use->run(UseProfile::PROFILE_PROD);

        $this->assertFalse($result['changed']);
        $this->assertSame(1, $restartCount);
        putenv('ENVIRONMENT');
    }

    private function makeLocalTier(): DeploymentTier
    {
        return new DeploymentTier(
            new Wiring(ORK3_ROOT . '/tools/ork-db'),
            $this->tempRoot,
            static fn (): bool => true
        );
    }

    private function makeProductionTier(): DeploymentTier
    {
        return new DeploymentTier(
            new Wiring(ORK3_ROOT . '/tools/ork-db'),
            $this->tempRoot,
            static fn (string $host, int $port): bool => $port === 19306
        );
    }
}
