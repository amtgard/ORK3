<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Init;
use OrkDb\Validate;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class ValidateIntegrationTest extends TestCase
{
    public function testPreApplyValidateAgainstSandbox(): void
    {
        if (!ork3_sandbox_db_available()) {
            $this->markTestSkipped('Sandbox database is not available.');
        }

        $toolRoot = ORK3_ROOT . '/tools/ork-db';
        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot);
        $this->ensureSandboxInitialized($validate, $wiring);

        $result = $validate->run(Validate::MODE_PRE_APPLY);

        $output = implode("\n", $result['lines']);
        $this->assertStringContainsString('Target:       127.0.0.1:19307/ork_test', $output);
        $this->assertStringContainsString('Port lock:    PASS', $output);
        $this->assertStringContainsString('Prod canary:  PASS', $output);
        $this->assertTrue($result['passed'], $output);
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

    public function testInitModeAllowsMissingKingdomFingerprints(): void
    {
        if (!ork3_sandbox_db_available()) {
            $this->markTestSkipped('Sandbox database is not available.');
        }

        $validate = new Validate(new Wiring(ORK3_ROOT . '/tools/ork-db'), ORK3_ROOT . '/tools/ork-db');
        $result = $validate->run(Validate::MODE_INIT);

        $output = implode("\n", $result['lines']);
        $this->assertStringContainsString('Kingdoms:     SKIP (init mode)', $output);
        $this->assertTrue($result['passed'], $output);
    }
}

function ork3_sandbox_db_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $pdo = new \PDO(
            'mysql:host=127.0.0.1;port=19307;dbname=ork_test;charset=utf8mb4',
            'root',
            'root',
            [\PDO::ATTR_TIMEOUT => 2]
        );
        $available = (bool) $pdo->query('SELECT 1')->fetchColumn();
    } catch (\Throwable) {
        $available = false;
    }

    return $available;
}
