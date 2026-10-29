<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The pairwise option editor of survey-build.js under node
 * (tests/Unit/js/survey-build-pairwise-harness.js): a held list never lets a
 * half-typed line out (pairwise spec §5 "nothing saves until it is fixed"), a
 * redraw of the open card keeps the held list's reason, and a paste keeps the
 * clipboard's own line breaks.
 */
final class SurveyBuildPairwiseScriptTest extends TestCase
{
    private static ?array $out = null;

    private function harness(): array
    {
        if (self::$out !== null) {
            return self::$out;
        }
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }
        $raw = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/js/survey-build-pairwise-harness.js') . ' 2>&1');
        $res = json_decode(trim((string) $raw), true);
        $this->assertIsArray($res, 'harness output: ' . $raw);
        return self::$out = $res;
    }

    public function testDuplicateLineCancelsTheSaveTheLastKeystrokeQueued(): void
    {
        $h = $this->harness()['hold'];
        $this->assertSame(['opts:666:choice'], $h['queuedFirst'], 'the half-typed line queued a save');
        $this->assertSame(10, $h['optimisticCount']);
        $this->assertSame(0, $h['posts'], 'the queued "Fall feas" must never be sent');
        $this->assertSame([], $h['pendingAfter']);
        $this->assertSame('“Fall feast” is listed twice.', $h['held']);
        $this->assertSame('“Fall feast” is listed twice.', $h['lastMark']);
        $this->assertCount(9, $h['labels'], 'S is back on the saved list');
        $this->assertNotContains('Fall feas', $h['labels']);
    }

    public function testFixingTheDuplicateSavesTheList(): void
    {
        $f = $this->harness()['fixed'];
        $this->assertSame(1, $f['posts']);
        $this->assertFalse($f['held']);
        $this->assertSame('Winter court', end($f['sentLabels']));
        $this->assertCount(10, $f['sentLabels']);
        $this->assertSame(9, $f['keptIds'], 'saved lines keep their option ids');
    }

    public function testIdleWaiterRunsOnlyAfterTheQueuedOptionSetResendReturns(): void
    {
        $r = $this->harness()['resend'];
        $this->assertTrue($r['busyWaiting'], 'the edit made during the save waits in optBusy');
        $this->assertSame([], $r['pendingWhileBusy'], 'no second option_set is queued while one is on the wire');
        $this->assertFalse($r['idleBeforeReply'], 'a busy option_set is not idle');
        $this->assertSame(['post', 'post'], $r['afterFirstReply'], 'the resend posts before the idle waiter runs');
        $this->assertSame([], $r['pendingAfterFirstReply'], 'with a waiter, the resend is flushed, not debounced');
        $this->assertSame('Harvest games', end($r['resendLabels']));
        $this->assertSame(5000, $r['resendWinterId'], 'the resend carries the id the first reply returned');
        $this->assertSame(['post', 'post', 'idle'], $r['afterSecondReply']);
        $this->assertSame([], $r['busyAfter']);
    }

    public function testRedrawingTheOpenCardPutsTheHeldReasonBack(): void
    {
        $r = $this->harness()['redraw'];
        $this->assertSame(['“Youth day” is listed twice.'], $r['openCard']);
        $this->assertSame([], $r['otherCard'], 'a closed card has no fields to mark');
        $this->assertSame(0, $r['drops']);
    }

    public function testPasteKeepsTheClipboardsLineBreaksAtItsEnds(): void
    {
        $p = $this->harness()['paste'];
        $this->assertSame("Spring war\nYouth day\nHarvest games\nWinter court\nSpring feast", $p['leadingAtEnd']);
        $this->assertSame("Spring war\nHarvest games\nWinter court\nYouth day", $p['trailingAtLine']);
        $this->assertSame("Spring war\nHarvest games\nWinter court", $p['leadingAfterBreak'], 'no blank line doubled');
        $this->assertSame("Harvest games\nWinter court", $p['leadingInEmpty']);
        $this->assertSame("SpringHarvest games\nWinter court war\nYouth day", $p['midLine'], 'mid-line pastes join, as natively');
    }
}
