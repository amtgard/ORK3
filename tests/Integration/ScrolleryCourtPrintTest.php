<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Court Planner -> Scrollery: Model_Scrollery::scrolls_for_court_awards() turns a
 * court's Scrollery Print lines into one print run.
 *
 * What is pinned: only this court's lines, only those still at Scrollery Print and
 * not cancelled, in the court's running order; an award Scrollery cannot print (an
 * officer position, a title, a nameless Custom Award) is reported with a reason
 * rather than silently dropped or printed blank; and the giver falls back to the
 * court's seated monarch the way the planner's own Grant modal does.
 */
final class ScrolleryCourtPrintTest extends TestCase
{
    private CourtFixture $fixture;
    private Model_Scrollery $model;
    private int $kid;
    private int $courtId;
    private int $playerId;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fixture  = CourtFixture::create();
        $this->model    = new Model_Scrollery();
        $this->kid      = $this->fixture->firstKingdomId();
        $this->courtId  = $this->fixture->createCourt(['kingdom_id' => $this->kid, 'court_date' => '2026-11-07']);
        $this->playerId = $this->fixture->createPlayer('scp', $this->kid)['mundane_id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    /** A kingdomaward this kingdom's Scrollery catalog prints with real artwork. */
    private function printableKa(): int
    {
        foreach ($this->model->printable_award_choices($this->kid) as $choice) {
            if ($choice['Order'] !== 'custom' && $choice['KingdomAwardId'] > 0) {
                return $choice['KingdomAwardId'];
            }
        }
        $this->markTestSkipped('No printable kingdomaward in kingdom ' . $this->kid . '.');
    }

    /** @param array<string, mixed> $o */
    private function scrolleryLine(int $kaId, array $o = []): int
    {
        return $this->fixture->createAward(
            $this->courtId,
            $this->playerId,
            array_merge(['kingdomaward_id' => $kaId, 'rank' => 2, 'scroll_status' => Court::SCROLL_SCROLLERY], $o)
        );
    }

    public function testPrintableKingdomAwardIdsIsAMapOfTheCatalog(): void
    {
        $map = $this->model->printable_kingdomaward_ids($this->kid);

        $this->assertNotSame([], $map);
        foreach ($this->model->printable_award_choices($this->kid) as $choice) {
            // Every catalog choice that prints a name is in the map; one that would
            // print a blank title is not (it could only ever be skipped).
            $this->assertSame(
                Model_Scrollery::courtChoicePrintsANameForTest($choice),
                $map[$choice['KingdomAwardId']] ?? false,
                $choice['Name']
            );
        }
        $this->assertSame([], $this->model->printable_kingdomaward_ids(0));
    }

    public function testScrolleryLinesPrintInRunningOrder(): void
    {
        $ka     = $this->printableKa();
        $second = $this->scrolleryLine($ka, ['sort_order' => 20]);
        $first  = $this->scrolleryLine($ka, ['sort_order' => 10, 'rank' => 3]);

        $run = $this->model->scrolls_for_court_awards($this->courtId, [], $this->playerId);

        $this->assertSame([$first, $second], array_keys($run['Scrolls']));
        $this->assertSame([], $run['Skipped']);
        $this->assertSame(0, $run['Dropped']);
        // The court's date, not the line's: a court line has no date of its own.
        $this->assertSame(Scroll::FormatDate('2026-11-07'), $run['Scrolls'][$first]['date']);
        $this->assertNotSame('', $run['Scrolls'][$first]['date']);
        $this->assertSame($this->playerId, $run['Scrolls'][$first]['mundaneId']);
        $this->assertSame('kingdom', $run['Scrolls'][$first]['scopeType']);
        $this->assertSame($this->kid, $run['Scrolls'][$first]['scopeId']);
    }

    public function testRequestedIdsNarrowTheRun(): void
    {
        $ka = $this->printableKa();
        $a  = $this->scrolleryLine($ka, ['sort_order' => 10]);
        $b  = $this->scrolleryLine($ka, ['sort_order' => 20]);

        $run = $this->model->scrolls_for_court_awards($this->courtId, [$b], $this->playerId);

        $this->assertSame([$b], array_keys($run['Scrolls']));
        $this->assertArrayNotHasKey($a, $run['Scrolls']);
    }

    /** Review focus 4: foreign ids and lines no longer at Scrollery Print never print. */
    public function testForeignAndNonScrolleryLinesAreNotPrinted(): void
    {
        $ka        = $this->printableKa();
        $printed   = $this->scrolleryLine($ka, ['sort_order' => 10]);
        $custom    = $this->scrolleryLine($ka, ['sort_order' => 20, 'scroll_status' => Court::SCROLL_CUSTOM]);
        $cancelled = $this->scrolleryLine($ka, ['sort_order' => 30, 'status' => 'cancelled']);

        $otherCourt = $this->fixture->createCourt(['kingdom_id' => $this->kid]);
        $foreign    = $this->fixture->createAward($otherCourt, $this->playerId, [
            'kingdomaward_id' => $ka,
            'scroll_status'   => Court::SCROLL_SCROLLERY,
        ]);

        $run = $this->model->scrolls_for_court_awards(
            $this->courtId,
            [$printed, $custom, $cancelled, $foreign],
            $this->playerId
        );

        $this->assertSame([$printed], array_keys($run['Scrolls']));
        foreach ([$custom, $cancelled, $foreign] as $id) {
            $this->assertArrayNotHasKey($id, $run['Scrolls']);
            $this->assertArrayNotHasKey($id, $run['Skipped']);
        }
    }

    /** Review focus 3: an award Scrollery can't print is reported, never printed. */
    public function testUnprintableAwardIsSkippedWithReason(): void
    {
        $printable = $this->model->printable_kingdomaward_ids($this->kid);
        $st        = $this->fixture->pdo()->prepare(
            'SELECT kingdomaward_id FROM ' . DB_PREFIX . 'kingdomaward WHERE kingdom_id = ? ORDER BY kingdomaward_id'
        );
        $st->execute([$this->kid]);
        $unprintable = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $kaId) {
            if (!isset($printable[(int) $kaId])) {
                $unprintable = (int) $kaId;
                break;
            }
        }
        if ($unprintable === 0) {
            $this->markTestSkipped('Every kingdomaward in kingdom ' . $this->kid . ' is printable.');
        }

        $line = $this->scrolleryLine($unprintable);
        $run  = $this->model->scrolls_for_court_awards($this->courtId, [], $this->playerId);

        $this->assertArrayNotHasKey($line, $run['Scrolls']);
        $this->assertSame("Scrollery can't print this award.", $run['Skipped'][$line] ?? null);
    }

    /**
     * Review focus 3: a Custom Award the kingdom never named resolves to a blank
     * title. The reprint surface prints that blank on purpose; a court run would be
     * printing a certificate nobody can read, so it is skipped with its reason.
     * "Custom Award" is the ORK's own placeholder and resolves exactly as an empty
     * name does (Scroll::ResolveTitle()), so either counts as unnamed.
     */
    public function testUnnamedCustomAwardIsSkippedAsBlankTitle(): void
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT kingdomaward_id FROM ' . DB_PREFIX . "kingdomaward
              WHERE kingdom_id = ? AND award_id = 94
                AND (TRIM(name) = '' OR LOWER(TRIM(name)) = 'custom award')
              ORDER BY kingdomaward_id LIMIT 1"
        );
        $st->execute([$this->kid]);
        $kaId = (int) $st->fetchColumn();
        $inCatalog = in_array($kaId, array_column($this->model->printable_award_choices($this->kid), 'KingdomAwardId'), true);
        if ($kaId <= 0 || !$inCatalog) {
            $this->markTestSkipped('No unnamed Custom Award in kingdom ' . $this->kid . "'s Scrollery catalog.");
        }

        // The planner's dropdown never offers Scrollery Print for it...
        $this->assertArrayNotHasKey($kaId, $this->model->printable_kingdomaward_ids($this->kid));

        // ...and a line that reached Scrollery Print anyway is skipped, not printed blank.
        $line = $this->scrolleryLine($kaId, ['rank' => 0]);
        $run  = $this->model->scrolls_for_court_awards($this->courtId, [], $this->playerId);

        $this->assertArrayNotHasKey($line, $run['Scrolls']);
        $this->assertSame('This award has no name to print.', $run['Skipped'][$line] ?? null);
    }

    public function testMissingRecipientIsSkippedWithReason(): void
    {
        $line = $this->fixture->createAward($this->courtId, 999999999, [
            'kingdomaward_id' => $this->printableKa(),
            'scroll_status'   => Court::SCROLL_SCROLLERY,
        ]);

        $run = $this->model->scrolls_for_court_awards($this->courtId, [], $this->playerId);

        $this->assertSame('The recipient could not be found.', $run['Skipped'][$line] ?? null);
    }

    public function testGiverFallsBackToCourtDefault(): void
    {
        $monarch = $this->fixture->createPlayer('mon', $this->kid);
        $this->fixture->insertOfficer($monarch['mundane_id'], $this->kid, 0, 'Monarch');
        $line = $this->scrolleryLine($this->printableKa());

        $run = $this->model->scrolls_for_court_awards($this->courtId, [], $this->playerId);

        $this->assertSame($monarch['persona'], $run['Scrolls'][$line]['givenBy'] ?? null);
    }

    public function testLinesOwnGiverWins(): void
    {
        $monarch = $this->fixture->createPlayer('mon', $this->kid);
        $this->fixture->insertOfficer($monarch['mundane_id'], $this->kid, 0, 'Monarch');
        $giver = $this->fixture->createPlayer('gvr', $this->kid);
        $line  = $this->scrolleryLine($this->printableKa(), ['given_by_mundane_id' => $giver['mundane_id']]);

        $run = $this->model->scrolls_for_court_awards($this->courtId, [], $this->playerId);

        $this->assertSame($giver['persona'], $run['Scrolls'][$line]['givenBy'] ?? null);
    }

    /**
     * Review focus 2, end to end: a citation saved while the rec was public, and the
     * rec hidden since, never prints — even for a court manager who cannot see the
     * rec. That viewer's rows carry no RecReason, so the run cannot compare the
     * citation against it; the court reader already withheld the citation.
     */
    public function testHiddenRecReasonNotPrintedForOfficerViewer(): void
    {
        $ka    = $this->printableKa();
        $p     = $this->fixture->firstParkId($this->kid);
        $recip = $this->fixture->createPlayer('scp-mo', $this->kid, $p)['mundane_id'];
        $st    = $this->fixture->pdo()->prepare('SELECT award_id FROM ' . DB_PREFIX . 'kingdomaward WHERE kingdomaward_id = ?');
        $st->execute([$ka]);
        // Kingdom-level Monarchy Only: a park officer of the recipient's park cannot see it.
        $rec = $this->fixture->createRecommendation($recip, $ka, (int) $st->fetchColumn(), [
            'monarchy_only' => 2, 'reason' => 'secret reason',
        ]);

        $parkCourt = $this->fixture->createCourt(['kingdom_id' => $this->kid, 'park_id' => $p, 'court_date' => '2026-11-07']);
        $line      = $this->fixture->createAward($parkCourt, $recip, [
            'kingdomaward_id' => $ka,
            'rank'            => 2,
            'scroll_status'   => Court::SCROLL_SCROLLERY,
            'public_comment'  => 'secret reason',
        ]);
        $this->fixture->pdo()->exec(
            'UPDATE ' . DB_PREFIX . 'court_award SET recommendations_id = ' . $rec . ' WHERE court_award_id = ' . $line
        );

        $viewer = $this->fixture->createPlayer('scp-mopark', $this->kid, $p)['mundane_id'];
        $this->fixture->insertOfficer($viewer, $this->kid, $p, 'Monarch');
        $this->fixture->insertScopedAuth($viewer, $p, 0, 'edit');
        $this->assertTrue((new Court())->canManage($viewer, $this->kid, $p), 'the viewer manages this park court');
        $this->assertFalse(Ork3::$Lib->recommendationvisibility->canSeeRecId($viewer, $rec), 'the viewer cannot see the rec');
        $this->assertNotSame($recip, $viewer);

        $run = $this->model->scrolls_for_court_awards($parkCourt, [$line], $viewer);

        $this->assertArrayHasKey($line, $run['Scrolls']);
        $this->assertSame('', $run['Scrolls'][$line]['reason']);
    }
}
