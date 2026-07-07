<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Apply;
use OrkDb\Init;
use OrkDb\Render;
use OrkDb\Validate;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class RenderApplyIntegrationTest extends TestCase
{
    public function testRenderAndApplyRoundTripOnSandbox(): void
    {
        if (!ork3_sandbox_db_available()) {
            $this->markTestSkipped('Sandbox database is not available.');
        }

        $toolRoot = ORK3_ROOT . '/tools/ork-db';
        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot);
        $this->ensureSandboxInitialized($validate, $wiring);

        $render = new Render($toolRoot, ORK3_ROOT);
        $apply = new Apply($wiring, $validate, $render, ORK3_ROOT);

        $rendered = $render->run([
            'anchor_date' => '2026-07-07',
            'seed' => 42,
            'deterministic' => true,
        ]);

        $this->assertSame(20, $rendered['park_count']);

        $result = $apply->run(['yes' => true, 'sql' => $rendered['output']]);
        $output = implode("\n", $result['lines']);

        $this->assertSame(0, $result['exit_code'], $output);
        $this->assertStringContainsString('Grand Duchy of Litavia', $output);
        $this->assertStringContainsString('POST-APPLY VALIDATION PASSED', $output);
    }

    private function ensureSandboxInitialized(Validate $validate, Wiring $wiring): void
    {
        $pre = $validate->run(Validate::MODE_PRE_APPLY);
        if ($pre['passed']) {
            return;
        }

        $init = new Init($wiring, $validate, ORK3_ROOT);
        $init->run();
    }
}
