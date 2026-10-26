<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Court Planner scroll/regalia tracking: explicit status writes (not a cycle),
 * the Scrollery Print value, and the guarded bulk "complete printed scrolls".
 */
final class CourtTrackingStatusTest extends TestCase
{
    private CourtFixture $fixture;
    private Court $court;
    private int $kid;
    private int $courtId;
    private int $playerId;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fixture  = CourtFixture::create();
        $this->court    = new Court();
        $this->kid      = $this->fixture->firstKingdomId();
        $this->courtId  = $this->fixture->createCourt(['kingdom_id' => $this->kid, 'status' => 'draft']);
        $this->playerId = $this->fixture->createPlayer('trk', $this->kid)['mundane_id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    private function award(array $o = [], ?int $courtId = null): int
    {
        return $this->fixture->createAward($courtId ?? $this->courtId, $this->playerId, $o);
    }

    private function row(int $id): array
    {
        return $this->fixture->fetchAward($id);
    }

    private function setScroll(int $id, int $status): array
    {
        return $this->court->setAwardTrackingStatus($id, 'scroll', $status, (int)$this->row($id)['row_version']);
    }

    public function testSetsEachAllowedScrollStatus(): void
    {
        $id = $this->award();
        $rv = (int)$this->row($id)['row_version'];
        foreach ([0, 1, 2, 3, 4, 5] as $status) {
            $r = $this->court->setAwardTrackingStatus($id, 'scroll', $status, $rv);
            $this->assertSame(0, $r['status']);
            $this->assertSame($status, $r['newStatus']);
            $rv = $r['RowVersion'];
            $this->assertSame($status, (int)$this->row($id)['scroll_status']);
        }
    }

    public function testDefinitionsListStatusesInDisplayOrder(): void
    {
        $d = Court::trackingStatusDefinitions();
        $this->assertSame([0, 3, 1, 4, 2, 5], array_column($d['scroll'], 'value'));
        $this->assertSame([0, 1, 4, 2, 5], array_column($d['regalia'], 'value'));
        $this->assertSame(
            ['Pending', 'Scrollery Print', 'Assigned', 'In Progress', 'Complete', 'Not Needed'],
            array_column($d['scroll'], 'label')
        );
        $this->assertSame(
            ['pending', 'print', 'assigned', 'inprogress', 'done', 'notneeded'],
            array_column($d['scroll'], 'group')
        );
        $this->assertSame(['Pending', 'Assigned', 'In Progress', 'Complete', 'Not Needed'], array_column($d['regalia'], 'label'));
        $this->assertSame(['pending', 'assigned', 'inprogress', 'done', 'notneeded'], array_column($d['regalia'], 'group'));
        $this->assertSame(
            ['Needed — nobody has it yet', 'Goes in the bulk print', 'An artisan has agreed to make it', 'Being made', '', "This line doesn't get one"],
            array_map(fn ($r) => (string)($r['hint'] ?? ''), $d['scroll'])
        );
        $this->assertSame([0, 3, 1, 4, 2, 5], $this->court->allowedTrackingStatuses('scroll'));
        $this->assertSame([0, 1, 4, 2, 5], $this->court->allowedTrackingStatuses('regalia'));
        $this->assertSame([], $this->court->allowedTrackingStatuses('bogus'));
    }

    public function testSetsEachAllowedRegaliaStatus(): void
    {
        $id = $this->award();
        $rv = (int)$this->row($id)['row_version'];
        foreach ([0, 1, 2, 4, 5] as $status) {
            $r = $this->court->setAwardTrackingStatus($id, 'regalia', $status, $rv);
            $this->assertSame(0, $r['status']);
            $rv = $r['RowVersion'];
            $this->assertSame($status, (int)$this->row($id)['regalia_status']);
        }
    }

    public function testRejectsUnknownStatus(): void
    {
        $id = $this->award();
        $before = $this->row($id);
        foreach (['scroll', 'regalia'] as $type) {
            $r = $this->court->setAwardTrackingStatus($id, $type, 6, (int)$before['row_version']);
            $this->assertSame(1, $r['status']);
        }
        $this->assertSame($before, $this->row($id));
    }

    public function testBackfillMovesUnmakeredAssignedToPending(): void
    {
        $a = $this->award(['scroll_status' => 1]);
        $b = $this->award(['scroll_status' => 1, 'scroll_maker_id' => $this->playerId]);
        $c = $this->award();
        $d = $this->award(['regalia_maker_id' => $this->playerId]);
        // The fixture has no regalia_status override.
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'court_award SET regalia_status = 1 WHERE court_award_id IN (' . $c . ',' . $d . ')');
        $e = $this->award(['scroll_status' => 2]);
        $before = [];
        foreach ([$b, $d, $e] as $i) {
            $before[$i] = $this->row($i);
        }
        $rvA = (int)$this->row($a)['row_version'];

        $sql = file_get_contents(__DIR__ . '/../../db-migrations/2026-10-26-court-tracking-pending-backfill.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $this->fixture->pdo()->exec($stmt);
        }

        $this->assertSame(0, (int)$this->row($a)['scroll_status']);
        $this->assertSame($rvA + 1, (int)$this->row($a)['row_version']);
        $this->assertSame(0, (int)$this->row($c)['regalia_status']);
        foreach ([$b, $d, $e] as $i) {
            $this->assertSame($before[$i], $this->row($i));
        }
    }

    public function testRegaliaRejectsScrolleryValue(): void
    {
        $id  = $this->award();
        $before = $this->row($id);
        $r = $this->court->setAwardTrackingStatus($id, 'regalia', 3, (int)$before['row_version']);
        $this->assertSame(1, $r['status']);
        $this->assertSame($before, $this->row($id));
    }

    public function testScrolleryPrintClearsScrollMaker(): void
    {
        $id = $this->award(['scroll_maker_id' => $this->playerId]);
        $this->assertSame(0, $this->setScroll($id, 3)['status']);
        $this->assertNull($this->row($id)['scroll_maker_id']);
        $this->assertSame(0, $this->setScroll($id, 1)['status']);
        $this->assertNull($this->row($id)['scroll_maker_id']);
    }

    public function testStaleRowVersionIsRefused(): void
    {
        $id = $this->award();
        $before = $this->row($id);
        $r = $this->court->setAwardTrackingStatus($id, 'scroll', 2, (int)$before['row_version'] + 1);
        $this->assertSame(['status' => 9, 'stale' => true], $r);
        $this->assertSame($before, $this->row($id));
    }

    public function testEveryWriteBumpsRowVersion(): void
    {
        $id = $this->award();
        $rv = (int)$this->row($id)['row_version'];
        foreach ([1, 2, 0] as $status) {
            $r = $this->court->setAwardTrackingStatus($id, 'scroll', $status, $rv);
            $this->assertSame($rv + 1, $r['RowVersion']);
            $this->assertSame($rv + 1, (int)$this->row($id)['row_version']);
            $rv++;
        }
    }

    private function lines(array $ids): array
    {
        return array_map(fn ($i) => ['CourtAwardId' => $i, 'RowVersion' => (int)$this->row($i)['row_version']], $ids);
    }

    public function testCompleteMarksOnlyListedScrolleryLines(): void
    {
        $a = [$this->award(), $this->award(), $this->award()];
        foreach ($a as $id) {
            $this->setScroll($id, 3);
        }
        $r = $this->court->completePrintedScrolls($this->courtId, $this->lines([$a[0], $a[1]]));
        $this->assertSame(0, $r['status']);
        $this->assertEqualsCanonicalizing([$a[0], $a[1]], $r['Completed']);
        $this->assertSame([], $r['Stale']);
        $this->assertSame(2, (int)$this->row($a[0])['scroll_status']);
        $this->assertSame(2, (int)$this->row($a[1])['scroll_status']);
        $this->assertSame(3, (int)$this->row($a[2])['scroll_status']);
    }

    public function testCompleteSkipsLinesWhoseRowVersionMoved(): void
    {
        $a = [$this->award(), $this->award()];
        foreach ($a as $id) {
            $this->setScroll($id, 3);
        }
        $lines = $this->lines($a);
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'court_award SET row_version = row_version + 1 WHERE court_award_id = ' . $a[1]);
        $r = $this->court->completePrintedScrolls($this->courtId, $lines);
        $this->assertSame([$a[0]], $r['Completed']);
        $this->assertSame([$a[1]], $r['Stale']);
        $this->assertSame(3, (int)$this->row($a[1])['scroll_status']);
    }

    public function testCompleteIgnoresLinesFromAnotherCourt(): void
    {
        $other = $this->fixture->createCourt(['kingdom_id' => $this->kid, 'status' => 'draft']);
        $id = $this->award([], $other);
        $this->setScroll($id, 3);
        $r = $this->court->completePrintedScrolls($this->courtId, $this->lines([$id]));
        $this->assertSame([], $r['Completed']);
        $this->assertSame([$id], $r['Stale']);
        $this->assertSame(3, (int)$this->row($id)['scroll_status']);
    }

    public function testCompleteIgnoresLinesNoLongerScrolleryPrint(): void
    {
        $id = $this->award();
        $this->setScroll($id, 3);
        $this->setScroll($id, 1);
        $r = $this->court->completePrintedScrolls($this->courtId, $this->lines([$id]));
        $this->assertSame([], $r['Completed']);
        $this->assertSame([$id], $r['Stale']);
        $this->assertSame(1, (int)$this->row($id)['scroll_status']);
    }

    public function testCompleteDeduplicatesIds(): void
    {
        $id = $this->award();
        $this->setScroll($id, 3);
        $rv    = (int)$this->row($id)['row_version'];
        $lines = $this->lines([$id, $id]);
        $r = $this->court->completePrintedScrolls($this->courtId, $lines);
        $this->assertSame([$id], $r['Completed']);
        $this->assertSame([], $r['Stale']);
        $this->assertSame(2, (int)$this->row($id)['scroll_status']);
        $this->assertSame($rv + 1, (int)$this->row($id)['row_version']);
    }

    public function testCompleteIgnoresNonArrayElements(): void
    {
        $id = $this->award();
        $this->setScroll($id, 3);
        $lines = array_merge(['junk', 7, null], $this->lines([$id]), [false]);
        // phpunit.xml sets failOnWarning="false", so trap any warning ourselves. Before
        // the fix each junk element was read as line 0 and reported in Stale.
        set_error_handler(static function (int $no, string $msg): bool {
            throw new ErrorException($msg, 0, $no);
        });
        try {
            $r = $this->court->completePrintedScrolls($this->courtId, $lines);
        } finally {
            restore_error_handler();
        }
        $this->assertSame([$id], $r['Completed']);
        $this->assertSame([], $r['Stale']);
        $this->assertSame(2, (int)$this->row($id)['scroll_status']);
    }
}
