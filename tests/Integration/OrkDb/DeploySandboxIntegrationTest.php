<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Apply;
use OrkDb\Bootstrap;
use OrkDb\DeploySandbox;
use OrkDb\DeploymentTier;
use OrkDb\Extract;
use OrkDb\Init;
use OrkDb\Render;
use OrkDb\UseProfile;
use OrkDb\Validate;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class DeploySandboxIntegrationTest extends TestCase
{
    public function testDeploySandboxCompletesOnLocalSandbox(): void
    {
        if (!ork3_sandbox_db_available()) {
            $this->markTestSkipped('Sandbox database is not available.');
        }

        if (!ork3_mirror_db_available()) {
            $this->markTestSkipped('Mirror database is not available.');
        }

        putenv('ENVIRONMENT=DEV');

        $toolRoot = ORK3_ROOT . '/tools/ork-db';
        $wiring = new Wiring($toolRoot);
        $tier = new DeploymentTier($wiring, ORK3_ROOT);
        $validate = new Validate($wiring, $toolRoot);
        $init = new Init($wiring, $validate, ORK3_ROOT);
        $extract = new Extract($wiring, $toolRoot);
        $render = new Render($toolRoot, ORK3_ROOT);
        $apply = new Apply($wiring, $validate, $render, ORK3_ROOT);
        $bootstrap = new Bootstrap($validate, $init, $extract, $apply, $toolRoot);
        $useProfile = new UseProfile($tier, ORK3_ROOT, static fn (): int => 0);

        $deploy = new DeploySandbox(
            $tier,
            $wiring,
            $validate,
            $init,
            $bootstrap,
            $extract,
            $render,
            $apply,
            $useProfile,
            $toolRoot,
        );

        $result = $deploy->run(['yes' => true, 'skip_use_dev' => true]);
        putenv('ENVIRONMENT');

        $output = implode("\n", $result['lines']);
        $this->assertSame(0, $result['exit_code'], $output);
        $this->assertStringContainsString('Deploy:       complete', $output);
        $this->assertStringContainsString('POST-APPLY VALIDATION PASSED', $output);
    }
}
