<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * survey-credit.js (sharing spec §3.6) run under node against a DOM stub
 * (tests/Unit/js/survey-credit-harness.js). The host's onChange(on) repaints
 * the list row's Credits button, so it may run only when THIS org's switch
 * changes: the panel's automatic reconcile can post another org's owed credits.
 */
final class SurveyCreditPanelScriptTest extends TestCase
{
    private function harness(string $scenario): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }
        $out = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/js/survey-credit-harness.js') . ' ' . escapeshellarg($scenario) . ' 2>&1');
        $res = json_decode(trim((string) $out), true);
        $this->assertIsArray($res, 'harness output: ' . $out);
        return $res;
    }

    public function testTheAutomaticReconcileNeverMarksTheRowOn(): void
    {
        $res = $this->harness('reconcile');
        $this->assertContains('credit_reconcile', $res['calls'], 'pending credits trigger the automatic reconcile');
        $this->assertSame(0, $res['onChange'], 'posting owed credits (possibly another org\'s) changes no one\'s on/off state');
        $this->assertFalse($res['enableHidden'], 'off: the Turn on button shows');
        $this->assertTrue($res['disableHidden']);
    }

    public function testSwitchingOnMarksTheRowOn(): void
    {
        $res = $this->harness('enable');
        $this->assertContains('credit_enable', $res['calls']);
        $this->assertSame([true], $res['on']);
        $this->assertTrue($res['enableHidden'], 'on: the Turn on button is gone');
        $this->assertFalse($res['disableHidden'], 'and Turn off is offered');
    }

    public function testSwitchingOffMarksTheRowOff(): void
    {
        $res = $this->harness('disable');
        $this->assertContains('credit_disable', $res['calls']);
        $this->assertNotContains('credit_enable', $res['calls']);
        $this->assertSame([false], $res['on']);
        $this->assertFalse($res['enableHidden'], 'off again: Turn on comes back');
    }
}
