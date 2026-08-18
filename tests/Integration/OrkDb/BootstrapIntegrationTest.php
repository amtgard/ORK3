<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Apply;
use OrkDb\Bootstrap;
use OrkDb\Extract;
use OrkDb\Init;
use OrkDb\Render;
use OrkDb\Validate;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class BootstrapIntegrationTest extends TestCase
{
    public function testBootstrapCompletesOnSandboxWhenMirrorExtractExists(): void
    {
        if (!ork3_sandbox_db_available()) {
            $this->markTestSkipped('Sandbox database is not available.');
        }

        if (!ork3_mirror_db_available()) {
            $this->markTestSkipped('Mirror database is not available.');
        }

        $toolRoot = ORK3_ROOT . '/tools/ork-db';
        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot);
        $init = new Init($wiring, $validate, ORK3_ROOT);
        $extract = new Extract($wiring, $toolRoot);
        $render = new Render($toolRoot, ORK3_ROOT);
        $apply = new Apply($wiring, $validate, $render, ORK3_ROOT);
        $bootstrap = new Bootstrap($validate, $init, $extract, $apply, $toolRoot);

        $result = $bootstrap->run(['yes' => true]);
        $output = implode("\n", $result['lines']);

        $this->assertSame(0, $result['exit_code'], $output);
        $this->assertStringContainsString('Bootstrap:    complete', $output);
        $this->assertStringContainsString('POST-APPLY VALIDATION PASSED', $output);
    }
}
