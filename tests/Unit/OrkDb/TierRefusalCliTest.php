<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\DeploymentTier;
use OrkDb\Extract;
use OrkDb\TierRefusalException;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class TierRefusalCliTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('DB_HOSTNAME');
        putenv('ORK3_DB_HOST');
        putenv('ORK3_TEST_DB_HOST');
        putenv('APP_STAGE');
        putenv('ENVIRONMENT');
    }

    public function testExtractRefusesWhenProductionTierDetected(): void
    {
        $tier = $this->productionTier();
        $extract = new Extract(new Wiring(ORK3_ROOT . '/tools/ork-db'), ORK3_ROOT . '/tools/ork-db');

        $this->expectException(TierRefusalException::class);
        $tier->refuseDataCommands('extract');
        $extract->run();
    }

    public function testApplyRefusesWhenProductionTierDetected(): void
    {
        $tier = $this->productionTier();
        $this->expectException(TierRefusalException::class);
        $tier->refuseDataCommands('apply');
    }

    public function testDeploySandboxRefusesWhenProductionTierDetected(): void
    {
        $tier = $this->productionTier();
        $this->expectException(TierRefusalException::class);
        $tier->refuseDataCommands('deploy-sandbox');
    }

    public function testGenerateAssetsRefusesWhenProductionTierDetected(): void
    {
        $tier = $this->productionTier();
        $this->expectException(TierRefusalException::class);
        $tier->refuseDataCommands('generate-assets');
    }

    public function testDeployAssetsRefusesWhenProductionTierDetected(): void
    {
        $tier = $this->productionTier();
        $this->expectException(TierRefusalException::class);
        $tier->refuseDataCommands('deploy-assets');
    }

    public function testUseDevRefusesWhenProductionTierDetected(): void
    {
        putenv('DB_HOSTNAME=mysql.amtgard.com');

        $tier = new DeploymentTier(
            new Wiring(ORK3_ROOT . '/tools/ork-db'),
            ORK3_ROOT,
            static fn (): bool => true
        );

        $this->expectException(TierRefusalException::class);
        $tier->refuseDataCommands('use dev');
    }

    private function productionTier(): DeploymentTier
    {
        return new DeploymentTier(
            new Wiring(ORK3_ROOT . '/tools/ork-db'),
            ORK3_ROOT,
            static fn (string $host, int $port): bool => $port === 19306
        );
    }
}
