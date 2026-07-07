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

    public function testUseDevRefusesWhenProductionTierDetected(): void
    {
        $tempRoot = sys_get_temp_dir() . '/ork-db-use-tier-' . uniqid('', true);
        mkdir($tempRoot);
        file_put_contents(
            $tempRoot . '/config.dev.php',
            "<?php\ndefine('DB_HOSTNAME', 'mysql.amtgard.com');\n"
        );

        $tier = new DeploymentTier(
            new Wiring(ORK3_ROOT . '/tools/ork-db'),
            $tempRoot,
            static fn (): bool => true
        );

        $this->expectException(TierRefusalException::class);
        $tier->refuseDataCommands('use dev');

        unlink($tempRoot . '/config.dev.php');
        rmdir($tempRoot);
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
