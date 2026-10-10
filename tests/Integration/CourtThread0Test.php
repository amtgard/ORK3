<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Thread 0 correctness fixes — see
 * docs/superpowers/specs/2026-09-01-court-recs-workflow-design.md §3.
 */
final class CourtThread0Test extends TestCase
{
    private CourtFixture $fixture;
    private Court $court;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fixture = CourtFixture::create();
        $this->court = new Court();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testUnrecordedCourtsIncludesPastDatedAndUndated(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('unrec', $kid);

        $past = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01']);
        $this->fixture->createAward($past, $player['mundane_id']);

        $undated = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => null]);
        $this->fixture->createAward($undated, $player['mundane_id']);

        $ids = array_column($this->court->getUnrecordedCourts($kid), 'CourtId');

        $this->assertContains($past, $ids, 'A past dated court with nothing recorded must be surfaced.');
        $this->assertContains($undated, $ids, 'An undated court with nothing recorded must be surfaced.');
    }

    public function testUnrecordedCourtsExcludesPartlyRecordedAndFuture(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('rec', $kid);

        $partly = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01']);
        $this->fixture->createAward($partly, $player['mundane_id'], ['status' => 'staged']);

        $future = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2099-01-01']);
        $this->fixture->createAward($future, $player['mundane_id']);

        $draft = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01', 'status' => 'draft']);
        $this->fixture->createAward($draft, $player['mundane_id']);

        $ids = array_column($this->court->getUnrecordedCourts($kid), 'CourtId');

        $this->assertNotContains($partly, $ids, 'A court with a staged row is being recorded — do not nag.');
        $this->assertNotContains($future, $ids, 'A court that has not happened yet is not overdue.');
        $this->assertNotContains($draft, $ids, 'A draft court was never published.');
    }

    public function testUpdateCourtWritesOnlyProvidedFields(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $courtId = $this->fixture->createCourt([
            'kingdom_id' => $kid,
            'name'       => 'T0CRT-original',
            'court_date' => null,
        ]);

        $ok = $this->court->updateCourt($courtId, ['CourtDate' => '2026-09-12']);
        $this->assertTrue($ok);

        $row = $this->fixture->fetchCourt($courtId);
        $this->assertSame('2026-09-12', $row['court_date']);
        $this->assertSame('T0CRT-original', $row['name'], 'A date-only update must not rewrite the name.');
    }

    public function testUpdateCourtIsRefusedOnCompleteCourts(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $courtId = $this->fixture->createCourt([
            'kingdom_id' => $kid,
            'status'     => 'complete',
            'court_date' => '2026-01-01',
        ]);

        $this->assertFalse($this->court->updateCourt($courtId, ['CourtDate' => '2026-09-12']));
        $this->assertSame('2026-01-01', $this->fixture->fetchCourt($courtId)['court_date']);
    }

    public function testUpdateCourtRelinksToADifferentEvent(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid]);

        // ork_court.event_calendardetail_id carries no FOREIGN KEY constraint (verified
        // via SHOW CREATE TABLE ork_court on both the dev and test databases), so this
        // pins re-link behavior with two arbitrary non-zero ids rather than depending on
        // seeded ork_event_calendardetail rows existing in every environment this suite
        // runs against.
        $this->assertTrue($this->court->updateCourt($courtId, ['EventCalendarDetailId' => 101]));
        $this->assertSame(101, (int) $this->fixture->fetchCourt($courtId)['event_calendardetail_id']);

        $this->assertTrue($this->court->updateCourt($courtId, ['EventCalendarDetailId' => 202]));
        $this->assertSame(
            202,
            (int) $this->fixture->fetchCourt($courtId)['event_calendardetail_id'],
            'A re-link must overwrite the previous event, not just accept an unlink to 0.'
        );
    }

    public function testUpdateAwardLeavesOmittedFieldsIntact(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('partial', $kid);
        $maker  = $this->fixture->createPlayer('maker', $kid);
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid]);

        $awardId = $this->fixture->createAward($courtId, $player['mundane_id'], [
            'notes'           => 'hold until the drama settles',
            'pass_to_local'   => 1,
            'scroll_maker_id' => $maker['mundane_id'],
        ]);

        // A citation-only save, as the Record Court view will make.
        $this->assertTrue($this->court->updateAward($awardId, ['PublicComment' => 'For steadfast service.']));

        $row = $this->fixture->fetchAward($awardId);
        $this->assertSame('For steadfast service.', $row['public_comment']);
        $this->assertSame('hold until the drama settles', $row['notes'], 'Internal notes must survive a citation-only save.');
        $this->assertSame(1, (int) $row['pass_to_local'], 'Pass-to-local must survive a citation-only save.');
        $this->assertSame(
            $maker['mundane_id'],
            (int) $row['scroll_maker_id'],
            'The scroll maker credit must survive a citation-only save.'
        );
    }

    public function testStageAwardRejectsStaleRowVersion(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('stale', $kid);
        $giver  = $this->fixture->createPlayer('giver', $kid);
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid]);
        $awardId = $this->fixture->createAward($courtId, $player['mundane_id']);

        $before = (int) $this->fixture->fetchAward($awardId)['row_version'];

        // Signature is stageAward($court_award_id, $given_by_mundane_id, $public_comment, $rank)
        // with $expectedRowVersion appended by this task as the 5th parameter.
        // First writer wins and bumps row_version.
        $this->assertTrue($this->court->stageAward($awardId, $giver['mundane_id'], '', 0, $before));

        // Return the row to an eligible status so the ONLY thing that can reject
        // the next call is the stale row_version. Without this, stageAward's own
        // `status NOT IN ('given','cancelled','staged')` guard rejects the second
        // call by itself, and the test would pass even if the row_version
        // predicate were deleted entirely. unstageAward() also bumps row_version
        // again, which is what makes $before ($before === 0) definitely stale.
        $this->court->unstageAward($awardId);

        // Second writer holds the now-stale token and must be refused.
        $this->assertFalse($this->court->stageAward($awardId, $giver['mundane_id'], '', 0, $before));

        $this->assertSame('planned', $this->fixture->fetchAward($awardId)['status']);
    }

    /**
     * Fix round 1 / Finding 2 (Task 7 review): unstageAward() had zero RowVersion
     * references while stageAward/skipAward had four each — the third mark had
     * no optimistic lock at all, and reported success even when its WHERE
     * (status='staged') matched nothing, because it used Execute() (void return)
     * instead of DataSet()+Size(). Reproduces the reviewer's exact scenario:
     * Recorder A stages a row, Recorder B skips it out from under A, and A's
     * unstage attempt (holding the now-stale pre-skip token) must be refused —
     * not silently accepted while the DB stays 'cancelled'.
     */
    public function testUnstageAwardRejectsStaleRowVersion(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('unstale', $kid);
        $giver  = $this->fixture->createPlayer('unstalegiver', $kid);
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid]);
        $awardId = $this->fixture->createAward($courtId, $player['mundane_id']);

        // Recorder A stages the row and remembers the row_version stageAward left.
        $this->assertTrue($this->court->stageAward($awardId, $giver['mundane_id'], '', 0, 0));
        $staleToken = (int) $this->fixture->fetchAward($awardId)['row_version'];

        // Recorder B skips the same row out from under A, bumping row_version again.
        $this->assertTrue($this->court->skipAward($awardId));
        $this->assertSame('cancelled', $this->fixture->fetchAward($awardId)['status']);

        // Recorder A's unstage, holding the pre-skip token, must be refused —
        // not fabricate 'planned' over a row the database holds as 'cancelled'.
        $this->assertFalse($this->court->unstageAward($awardId, $staleToken));
        $this->assertSame('cancelled', $this->fixture->fetchAward($awardId)['status']);
    }

    /**
     * Companion to the stale-token case above: even with NO token supplied,
     * unstageAward's WHERE (status='staged') matching zero rows must report
     * failure, not synthesize success — this is what let the controller return
     * {status:0, award_status:'planned'} while the database still held
     * 'cancelled', independent of any concurrency race.
     */
    public function testUnstageAwardReportsFailureWhenNothingStaged(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('unstagenoop', $kid);
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid]);
        $awardId = $this->fixture->createAward($courtId, $player['mundane_id']);

        // Never staged — still 'planned'. unstageAward has nothing to undo.
        $this->assertFalse($this->court->unstageAward($awardId));
        $this->assertSame('planned', $this->fixture->fetchAward($awardId)['status']);
    }

    public function testDefaultRecorderPrefersParkPrimeMinister(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $pid = $this->fixture->firstParkId($kid);
        if ($pid <= 0) {
            $this->markTestSkipped('No park available in this kingdom.');
        }

        $kingdomPm = $this->fixture->createPlayer('kpm', $kid);
        $this->fixture->insertOfficer($kingdomPm['mundane_id'], $kid, 0, 'Prime Minister');

        // With only a kingdom PM, a park court falls through to it.
        $this->assertSame($kingdomPm['mundane_id'], $this->court->getDefaultRecorder($kid, $pid));

        // A park PM outranks the kingdom PM for that park's court.
        $parkPm = $this->fixture->createPlayer('ppm', $kid, $pid);
        $this->fixture->insertOfficer($parkPm['mundane_id'], $kid, $pid, 'Prime Minister');
        $this->assertSame($parkPm['mundane_id'], $this->court->getDefaultRecorder($kid, $pid));
    }

    /**
     * The sibling of testUpdateAwardLeavesOmittedFieldsIntact, guarding the
     * other half of the array_key_exists contract.
     *
     * That test proves an OMITTED key survives. This one proves a key that IS
     * present but empty actually CLEARS — the case that silently regresses the
     * moment someone "tidies" array_key_exists into isset(), !empty() or a bare
     * truthiness check, none of which can tell "clear this field" apart from
     * "don't touch this field". Without it, an officer deleting a stale internal
     * note, or unticking Pass-to-Local, would appear to save and change nothing.
     */
    public function testUpdateAwardClearsFieldsExplicitlySetEmpty(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('clearing', $kid);
        $maker  = $this->fixture->createPlayer('clearmaker', $kid);
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid]);

        $awardId = $this->fixture->createAward($courtId, $player['mundane_id'], [
            'notes'           => 'stale note to delete',
            'public_comment'  => 'For steadfast service.',
            'pass_to_local'   => 1,
            'scroll_maker_id' => $maker['mundane_id'],
        ]);

        // Every value here is falsy — '' , 0, 0 — so any truthiness-based guard
        // would drop all three and report success anyway.
        $this->assertTrue($this->court->updateAward($awardId, [
            'Notes'         => '',
            'PassToLocal'   => 0,
            'ScrollMakerId' => 0,
        ]));

        $row = $this->fixture->fetchAward($awardId);
        $this->assertSame('', $row['notes'], 'An explicitly emptied note must actually clear.');
        $this->assertSame(0, (int) $row['pass_to_local'], 'Unticking Pass-to-Local must actually clear it.');
        $this->assertNull($row['scroll_maker_id'], 'A cleared scroll maker must become NULL.');

        // …and the omitted sibling is still untouched, so "clear" did not
        // degenerate into "overwrite everything".
        $this->assertSame(
            'For steadfast service.',
            $row['public_comment'],
            'An omitted field must survive a save that clears its siblings.'
        );
    }

    /**
     * A typo'd field name must fail loudly rather than being dropped while its
     * valid siblings save and the caller is told everything worked.
     */
    public function testUpdateAwardRejectsUnrecognizedFields(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('badkey', $kid);
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid]);
        $awardId = $this->fixture->createAward($courtId, $player['mundane_id'], [
            'notes' => 'original',
        ]);

        $this->assertFalse(
            $this->court->updateAward($awardId, ['Notes' => 'changed', 'Nnotes' => 'typo']),
            'A mixed valid/invalid field set must be rejected outright.'
        );
        $this->assertSame(
            'original',
            $this->fixture->fetchAward($awardId)['notes'],
            'A rejected write must not have partially applied.'
        );
    }

    /**
     * A save whose values all match what is already stored changes 0 rows.
     * Reporting that as failure told officers a *draft* court could not be
     * edited because it was complete (the Edit Details modal posts all four
     * fields, so a no-op save is routine).
     */
    public function testUpdateCourtSucceedsWhenNothingChanged(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid, 'name' => 'Midwinter Court']);

        $this->assertTrue($this->court->updateCourt($courtId, ['Name' => 'Midwinter Court']));
        // Twice: the second call is guaranteed to change nothing at all.
        $this->assertTrue(
            $this->court->updateCourt($courtId, ['Name' => 'Midwinter Court']),
            'A save with no net change must not be reported as a failure.'
        );
    }

    /**
     * getUnrecordedCourts must scope exactly as getCourtList does. Filtering
     * kingdom context on kingdom_id alone leaked every park court onto the
     * kingdom list — and fired a notification at every park recorder in the
     * kingdom whenever one kingdom officer opened the planner.
     */
    public function testUnrecordedCourtsExcludeParkCourtsFromKingdomScope(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $pid = $this->fixture->firstParkId($kid);
        if ($pid <= 0) {
            $this->markTestSkipped('No park available in this kingdom.');
        }

        $kingdomCourt = $this->fixture->createCourt([
            'kingdom_id' => $kid, 'park_id' => 0, 'court_date' => '2026-01-01',
        ]);
        $parkCourt = $this->fixture->createCourt([
            'kingdom_id' => $kid, 'park_id' => $pid, 'court_date' => '2026-01-01',
        ]);

        $kingdomIds = array_column($this->court->getUnrecordedCourts($kid), 'CourtId');
        $this->assertContains($kingdomCourt, $kingdomIds);
        $this->assertNotContains($parkCourt, $kingdomIds, 'A park court must not appear in kingdom scope.');

        $parkIds = array_column($this->court->getUnrecordedCourts($kid, $pid), 'CourtId');
        $this->assertContains($parkCourt, $parkIds);
        $this->assertNotContains($kingdomCourt, $parkIds, 'A kingdom court must not appear in park scope.');
    }
}
