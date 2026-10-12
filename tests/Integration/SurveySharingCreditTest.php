<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Sharing and credits (spec 2026-09-10-survey-sharing-and-credits-design.md). */
final class SurveySharingCreditTest extends TestCase
{
    use SurveyOrgFixture;

    private int $k = 0;          // home kingdom
    private int $kOther = 0;     // another root kingdom
    private int $parkA = 0;
    private int $parkB = 0;
    private int $parkOther = 0;
    private int $kOfficer = 0;
    private int $pOfficerA = 0;
    private int $pOfficerB = 0;
    private int $kOtherOfficer = 0;

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->k = $this->kingdom('home');
        $this->kOther = $this->kingdom('away');
        $this->parkA = $this->park($this->k, 'a');
        $this->parkB = $this->park($this->k, 'b');
        $this->parkOther = $this->park($this->kOther, 'x');
        $this->kOfficer = $this->player('kofficer', $this->parkA, $this->k);
        $this->officer($this->kOfficer, AUTH_KINGDOM, $this->k);
        $this->pOfficerA = $this->player('pofficera', $this->parkA, $this->k);
        $this->officer($this->pOfficerA, AUTH_PARK, $this->parkA);
        $this->pOfficerB = $this->player('pofficerb', $this->parkB, $this->k);
        $this->officer($this->pOfficerB, AUTH_PARK, $this->parkB);
        $this->kOtherOfficer = $this->player('kother', $this->parkOther, $this->kOther);
        $this->officer($this->kOtherOfficer, AUTH_KINGDOM, $this->kOther);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testResultsShareIsRefusedOnAParkSurveyAndValidatedElsewhere(): void
    {
        $sid = $this->openSurvey($this->pOfficerA, 'park', $this->parkA);
        $this->assertSame(1, (new Survey())->update($sid, ['ResultsShare' => 'scoped'])['Status']);
        $this->assertSame(0, (new Survey())->update($sid, ['ResultsShare' => 'none'])['Status']);

        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->assertSame(1, (new Survey())->update($ks, ['ResultsShare' => 'bogus'])['Status']);
        $this->assertSame(0, (new Survey())->update($ks, ['ResultsShare' => 'all'])['Status']);
        $this->assertSame('all', $this->row($ks)['results_share']);
    }

    public function testResultsAccessMatrix(): void
    {
        $s = new Survey();
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $parkCtx = ['type' => 'park', 'id' => $this->parkA];

        $this->assertSame('manage', $s->resultsAccess($this->kOfficer, $this->row($ks), null)['level']);
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($ks), $parkCtx), 'none shares nothing');

        $s->update($ks, ['ResultsShare' => 'scoped', 'ResultsShareTiming' => 'ongoing']);
        $acc = $s->resultsAccess($this->pOfficerA, $this->row($ks), $parkCtx);
        $this->assertSame('shared', $acc['level']);
        $this->assertSame(['shared' => true, 'park_id' => $this->parkA], $acc['lens']);
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($ks), ['type' => 'park', 'id' => $this->parkB]), 'not an officer of park B');
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($ks), ['type' => 'kingdom', 'id' => $this->k]), 'wrong level');
        $this->assertNull($s->resultsAccess($this->kOtherOfficer, $this->row($ks), ['type' => 'park', 'id' => $this->parkOther]), 'not reached');

        $s->update($ks, ['ResultsShare' => 'all']);
        $this->assertSame(['shared' => true], $s->resultsAccess($this->pOfficerA, $this->row($ks), $parkCtx)['lens']);

        // setStatus() no longer lets an opened survey back to draft; stage one directly.
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET status = 'draft', opened_at = NULL WHERE survey_id = " . $ks);
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($ks), $parkCtx), 'drafts never roll down');
    }

    public function testOrkSurveyRollsDownToKingdomsOnlyAndRespectsTheAudienceList(): void
    {
        $s = new Survey();
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'scoped', 'results_share_timing' => 'ongoing', 'audience_kingdom_ids' => json_encode([$this->k])]);
        $acc = $s->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame('kingdom', $acc['label']);
        $this->assertSame([$this->k], $acc['lens']['kingdom_ids']);
        $this->assertNull($s->resultsAccess($this->kOtherOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->kOther]), 'outside the audience list');
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($os), ['type' => 'park', 'id' => $this->parkA]), 'ORK never rolls to parks');
    }

    // ------------------------------------------------ sharing timing

    public function testResultsShareTimingIsValidatedAndDefaultsToAfterClose(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->assertSame('after_close', (string) $this->row($ks)['results_share_timing']);
        $this->assertSame(1, (new Survey())->update($ks, ['ResultsShareTiming' => 'someday'])['Status']);
        $this->assertSame(0, (new Survey())->update($ks, ['ResultsShareTiming' => 'ongoing'])['Status']);
        $this->assertSame('ongoing', (string) $this->row($ks)['results_share_timing']);
    }

    public function testAfterCloseHoldsSharedResultsUntilADayAfterTheSurveyEnds(): void
    {
        $s = new Survey();
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped']);
        $ctx = ['type' => 'park', 'id' => $this->parkA];

        // Still taking responses: refused, and pending with no date yet.
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($ks), $ctx));
        $this->assertSame(['opens_at' => null], $s->resultsPending($this->pOfficerA, $this->row($ks), $ctx));

        // Closed an hour ago: still refused, and pending opens 23 hours from now.
        $closed = date('Y-m-d H:i:s', time() - 3600);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET status = 'closed', closed_at = '{$closed}' WHERE survey_id = {$ks}");
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($ks), $ctx));
        $this->assertSame(['opens_at' => date('Y-m-d H:i:s', strtotime($closed) + 86400)], $s->resultsPending($this->pOfficerA, $this->row($ks), $ctx));

        // Closed two days ago: shared, and no longer pending.
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET closed_at = '" . date('Y-m-d H:i:s', time() - 2 * 86400) . "' WHERE survey_id = {$ks}");
        $this->assertSame('shared', $s->resultsAccess($this->pOfficerA, $this->row($ks), $ctx)['level']);
        $this->assertNull($s->resultsPending($this->pOfficerA, $this->row($ks), $ctx));

        // The owner never waits.
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET status = 'open', closed_at = NULL WHERE survey_id = {$ks}");
        $this->assertSame('manage', $s->resultsAccess($this->kOfficer, $this->row($ks), null)['level']);
    }

    public function testOngoingSharesWhileTheSurveyIsOpenAndPendingNeedsAQualifyingViewer(): void
    {
        $s = new Survey();
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped', 'results_share_timing' => 'ongoing']);
        $this->assertSame('shared', $s->resultsAccess($this->pOfficerA, $this->row($ks), ['type' => 'park', 'id' => $this->parkA])['level']);

        $held = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped']);
        $this->assertNull($s->resultsPending($this->kOtherOfficer, $this->row($held), ['type' => 'park', 'id' => $this->parkOther]), 'not reached: not pending, just refused');
        $this->assertNull($s->resultsPending($this->pOfficerA, $this->row($this->openSurvey($this->kOfficer, 'kingdom', $this->k)), ['type' => 'park', 'id' => $this->parkA]), "results_share 'none' is never pending");
    }

    public function testListRowSaysWhenHeldResultsOpen(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped']);
        $closed = date('Y-m-d H:i:s', time() - 3600);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET status = 'closed', closed_at = '{$closed}' WHERE survey_id = {$ks}");

        $rows = (new Survey())->listForScope($this->pOfficerA, 'park', $this->parkA)['Rows']['kingdom'];
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['CanResults']);
        $this->assertTrue($rows[0]['ResultsPending']);
        $this->assertSame(date('Y-m-d H:i:s', strtotime($closed) + 86400), $rows[0]['ResultsOpensAt']);
        $this->assertSame(Survey::sharingPendingText($rows[0]['ResultsOpensAt']), $rows[0]['ResultsPendingText']);
    }

    public function testCloneKeepsResultsShareTiming(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped', 'results_share_timing' => 'ongoing']);
        $c = (new Survey())->cloneSurvey($ks, $this->kOfficer);
        $copy = $this->fx['survey'][] = (int) $c['SurveyId'];
        $this->assertSame('ongoing', (string) $this->row($copy)['results_share_timing']);
    }

    public function testKingdomFamilyIncludesPrincipalities(): void
    {
        $pr = $this->kingdom('principality', $this->k);
        $fam = (new Survey())->kingdomFamily($this->k);
        sort($fam);
        $want = [$this->k, $pr];
        sort($want);
        $this->assertSame($want, $fam);
    }

    private function ids(array $rows): array
    {
        return array_map(static fn ($r) => (int) $r['survey_id'], $rows);
    }

    public function testKingdomPageShowsReachedOrkOpenClosedOwnKingdomAndItsParks(): void
    {
        $ork      = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['audience_kingdom_ids' => json_encode([$this->k])]);
        $orkElse  = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['audience_kingdom_ids' => json_encode([$this->kOther])]);
        $orkDraft = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['status' => 'draft']);
        $mine     = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $parkA    = $this->openSurvey($this->pOfficerA, 'park', $this->parkA);
        $other    = $this->openSurvey($this->kOtherOfficer, 'park', $this->parkOther);

        $list = (new Survey())->listForScope($this->kOfficer, 'kingdom', $this->k);
        $this->assertContains($ork, $this->ids($list['Rows']['ork']));
        $this->assertNotContains($orkElse, $this->ids($list['Rows']['ork']));
        $this->assertNotContains($orkDraft, $this->ids($list['Rows']['ork']));
        $this->assertSame([$mine], $this->ids($list['Rows']['kingdom']));
        $this->assertSame([$parkA], $this->ids($list['Rows']['park']));
        $this->assertNotContains($other, array_merge(...array_map([$this, 'ids'], array_values($list['Rows']))));

        $orkRow = $list['Rows']['ork'][0];
        $this->assertSame('shared', $orkRow['Access']);
        $this->assertSame('Kingdom/' . $this->k, $orkRow['CreditGrantor']);
        $this->assertFalse($orkRow['CanResults'], 'results_share defaults to none');
        $this->assertSame('manage', $list['Rows']['park'][0]['Access']);
        $this->assertSame('Park/' . $this->parkA, $list['Rows']['park'][0]['CreditGrantor'], 'a kingdom acts for its park');
        $this->assertSame('Amtgard', $list['Labels']['ork']);
    }

    public function testParkPageSeesOrkOwnKingdomAndOwnParkOnly(): void
    {
        $ork   = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'scoped', 'results_share_timing' => 'ongoing']);
        $kings = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped', 'results_share_timing' => 'ongoing']);
        $away  = $this->openSurvey($this->kOtherOfficer, 'kingdom', $this->kOther);
        $mineP = $this->openSurvey($this->pOfficerA, 'park', $this->parkA);
        $sibP  = $this->openSurvey($this->pOfficerB, 'park', $this->parkB);

        $list = (new Survey())->listForScope($this->pOfficerA, 'park', $this->parkA);
        $this->assertContains($ork, $this->ids($list['Rows']['ork']));
        $this->assertSame([$kings], $this->ids($list['Rows']['kingdom']));
        $this->assertSame([$mineP], $this->ids($list['Rows']['park']));
        $all = array_merge(...array_map([$this, 'ids'], array_values($list['Rows'])));
        $this->assertNotContains($away, $all);
        $this->assertNotContains($sibP, $all);

        $kRow = $list['Rows']['kingdom'][0];
        $this->assertTrue($kRow['CanResults']);
        $this->assertSame('Park/' . $this->parkA, $kRow['ResultsContext']);
        $this->assertSame('park', $kRow['ResultsLabel']);
        $this->assertFalse($list['Rows']['ork'][0]['CanResults'], 'ORK results never reach parks');
    }

    /** §1 CreditChip: a park page shows the credit as on when its kingdom's config already covers it. */
    public function testParkRowShowsTheKingdomsCreditAsCovering(): void
    {
        $kings = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $rowFor = function () use ($kings): array {
            foreach ((new Survey())->listForScope($this->pOfficerA, 'park', $this->parkA)['Rows']['kingdom'] as $r) {
                if ((int) $r['survey_id'] === $kings) {
                    return $r;
                }
            }
            $this->fail('survey ' . $kings . ' not listed');
        };
        $this->assertFalse($rowFor()['CreditOn']);
        $this->assertNull($rowFor()['CreditCoveredBy']);
        $this->assertFalse($rowFor()['CreditShownOn']);

        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $kings, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true)['Status']);
        $row = $rowFor();
        $this->assertFalse($row['CreditOn'], 'the park has no config of its own');
        $this->assertSame((new Survey())->scopeName('kingdom', $this->k), $row['CreditCoveredBy']);
        $this->assertTrue($row['CreditShownOn'], 'covered: the row shows the credit as on (the domain decides, not the template)');

        $this->assertSame(0, $this->credit()->enable($this->pOfficerA, $kings, ['type' => 'park', 'id' => $this->parkA], 'home_park', true)['Status']);
        $row = $rowFor();
        $this->assertTrue($row['CreditOn']);
        $this->assertNull($row['CreditCoveredBy']);
        $this->assertTrue($row['CreditShownOn']);
    }

    /** §1: a shared row's response count reaches the page only under results_share = 'all'. */
    public function testSharedRowsCarryTheResponseCountOnlyWhenResultsAreSharedWithEveryone(): void
    {
        $kings = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped', 'results_share_timing' => 'ongoing']);
        $mineP = $this->openSurvey($this->pOfficerA, 'park', $this->parkA);
        $this->answer($kings, $this->player('cnt1', $this->parkB, $this->k), 'full');
        $this->answer($mineP, $this->player('cnt2', $this->parkA, $this->k), 'anonymous');
        $rowFor = function (int $sid): array {
            $list = (new Survey())->listForScope($this->pOfficerA, 'park', $this->parkA);
            foreach (array_merge(...array_values($list['Rows'])) as $r) {
                if ((int) $r['survey_id'] === $sid) {
                    return $r;
                }
            }
            $this->fail('survey ' . $sid . ' not listed');
        };

        $shared = $rowFor($kings);
        $this->assertSame('shared', $shared['Access']);
        $this->assertNull($shared['ResponseCount'], 'scoped: no count');
        $this->assertNull($shared['response_count'], 'and not the raw column either');
        $this->assertSame(1, $rowFor($mineP)['ResponseCount'], 'a managed row keeps its count');

        (new Survey())->update($kings, ['ResultsShare' => 'all']);
        $this->assertSame(1, $rowFor($kings)['ResponseCount'], "'all': the count is shown");

        (new Survey())->update($kings, ['ResultsShare' => 'none']);
        $this->assertNull($rowFor($kings)['ResponseCount'], "'none': no count");
    }

    public function testListForScopeRefusesAnOrgTheViewerCannotActFor(): void
    {
        $list = (new Survey())->listForScope($this->pOfficerA, 'park', $this->parkB);
        $this->assertSame([], array_merge(...array_values($list['Rows'])));
    }

    public function testKingdomLensKeepsTheKingdomsRowsUnderTheExistingSmallGroupRules(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'scoped', 'results_share_timing' => 'ongoing']);
        // 5 full + 2 partial + 1 anonymous at home; 2 full away.
        foreach (['full', 'full', 'full', 'full', 'full', 'partial', 'partial', 'anonymous'] as $i => $c) {
            $this->answer($os, $this->player('home' . $i, $this->parkA, $this->k), $c);
        }
        foreach (['full', 'full'] as $i => $c) {
            $this->answer($os, $this->player('away' . $i, $this->parkOther, $this->kOther), $c);
        }
        // The kingdom officer cannot manage an ORK survey, so they read it shared.
        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame('shared', $acc['level']);
        $out = (new SurveyReport())->sharedResults($os, [], $acc['lens']);
        // Full rows from the kingdom count. The 2 partial rows are left out by
        // base §2 rule 5 (fewer than 5 partial rows in the kingdom under a
        // kingdom filter). Anonymous rows (no kingdom) and the away kingdom are
        // outside the lens.
        $this->assertSame(5, (int) $out['summary']['responses']);
        $this->assertFalse((bool) $out['summary']['suppressed']);
        $this->assertNull($out['summary']['starts']);
        $this->assertNull($out['summary']['excluded_anonymous']);
        $this->assertSame(['label' => 'kingdom'], $out['summary']['lens'], 'summary.lens = {label} (spec §5)');
        $this->assertSame($acc['label'], $out['summary']['lens']['label'], 'the JSON label agrees with resultsAccess');

        (new Survey())->update($os, ['ResultsShare' => 'all']);
        $all = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame(['label' => 'all'], (new SurveyReport())->sharedResults($os, [], $all['lens'])['summary']['lens']);
        $this->assertArrayNotHasKey('lens', (new SurveyReport())->summary($os, SurveyReport::normalizeFilters([])), 'a manager view carries no lens');
    }

    public function testParkLensCountsOnlyAnyOrkDataFromThatParkAndSuppressesUnderFive(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped', 'results_share_timing' => 'ongoing']);
        foreach (['full', 'full', 'partial', 'anonymous'] as $i => $c) {
            $this->answer($ks, $this->player('pa' . $i, $this->parkA, $this->k), $c);
        }
        $this->answer($ks, $this->player('pb', $this->parkB, $this->k), 'full');
        $acc = (new Survey())->resultsAccess($this->pOfficerA, $this->row($ks), ['type' => 'park', 'id' => $this->parkA]);
        $this->afterCloseTiming($ks); // the live count; an ongoing share's snapshot has its own test
        $out = (new SurveyReport())->sharedResults($ks, [], $acc['lens']);
        $this->assertSame(2, (int) $out['summary']['responses']);
        $this->assertTrue((bool) $out['summary']['suppressed'], 'a lens view under 5 is suppressed');
        $this->assertSame(['label' => 'park'], $out['summary']['lens']);
    }

    /**
     * A shared viewer gets no per-day counts and no date bounds: a public
     * home-park credit is dated the day taken, so two date windows would
     * isolate the named respondent of a one-response day (D2). A client's
     * park_id cannot narrow a kingdom lens to one park either.
     */
    public function testSharedViewersGetNoDateWindowsPerDayCountsOrParkSlices(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'scoped', 'results_share_timing' => 'ongoing']);
        for ($i = 0; $i < 6; $i++) {
            $this->answer($ks, $this->player('dw' . $i, $i < 5 ? $this->parkA : $this->parkB, $this->k), 'full');
        }
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey_response SET submitted_at = '{$yesterday} 12:00:00'
                          WHERE survey_id = {$ks} ORDER BY response_id LIMIT 5");

        $manager = (new SurveyReport())->summary($ks, SurveyReport::normalizeFilters(['date_to' => $yesterday]));
        $this->assertSame(5, (int) $manager['responses'], 'control: the date bound works for a manager');
        $this->assertNotNull($manager['by_day']);

        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($ks), ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame('shared', $acc['level']);
        $this->afterCloseTiming($ks); // the live count; an ongoing share's snapshot has its own test
        $all = (new SurveyReport())->sharedResults($ks, [], $acc['lens']);
        $cut = (new SurveyReport())->sharedResults($ks, ['date_to' => $yesterday], $acc['lens']);
        $this->assertSame(6, (int) $all['summary']['responses']);
        $this->assertSame(6, (int) $cut['summary']['responses'], 'the date bound is ignored');
        $this->assertNull($all['summary']['by_day'], 'no per-day counts');

        $park = (new SurveyReport())->sharedResults($ks, ['park_id' => $this->parkB], $acc['lens']);
        $this->assertSame(6, (int) $park['summary']['responses'], "a client's park_id is ignored");
    }

    /** Read shared results live (after close), not through an ongoing share's snapshot. */
    private function afterCloseTiming(int $sid): void
    {
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET results_share_timing = 'after_close' WHERE survey_id = " . (int) $sid);
    }

    /** An unfiltered 'all' share is a shared view too: one response is suppressed. */
    public function testAnAllShareOfOneResponseIsSuppressed(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'all', 'results_share_timing' => 'ongoing']);
        $this->answer($os, $this->player('one', $this->parkA, $this->k), 'full');
        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame(['shared' => true], $acc['lens']);
        $this->afterCloseTiming($os);
        $out = (new SurveyReport())->sharedResults($os, [], $acc['lens']);
        $this->assertTrue((bool) $out['summary']['suppressed']);
        $this->assertNotEmpty($out['questions']);
        foreach ($out['questions'] as $q) {
            $this->assertSame(['suppressed' => true], $q['agg']);
        }
        $manager = (new SurveyReport())->aggregate($os, SurveyReport::normalizeFilters([]));
        $this->assertArrayNotHasKey('suppressed', $manager['questions'][0]['agg'], 'control: the owner still sees it');
    }

    /**
     * An ongoing share with 1..MIN_CELL-1 matches says so with a flag (#8),
     * never with the count: the snapshot stays empty until MIN_CELL match.
     */
    public function testAnOngoingShareFlagsHeldResultsWithoutTheCount(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'all', 'results_share_timing' => 'ongoing']);
        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $rep = new SurveyReport();
        $this->assertFalse($rep->sharedResults($os, [], $acc['lens'])['summary']['held'], 'nobody has answered: not held');

        for ($i = 0; $i < 3; $i++) {
            $this->answer($os, $this->player('held' . $i, $this->parkA, $this->k), 'full');
        }
        $out = $rep->sharedResults($os, [], $acc['lens']);
        $this->assertTrue($out['summary']['held']);
        $this->assertSame(0, (int) $out['summary']['responses'], 'the snapshot is still empty');
        array_walk_recursive($out, function ($v, $k) {
            $this->assertFalse(is_int($v) && $v === 3, "no count of the held responses leaks ({$k})");
        });
        $this->assertFalse($rep->sharedResults($os, ['kingdom_ids' => [$this->kOther]], $acc['lens'])['summary']['held'], 'none match the view: not held');

        for ($i = 3; $i < 5; $i++) {
            $this->answer($os, $this->player('held' . $i, $this->parkA, $this->k), 'full');
        }
        $out = $rep->sharedResults($os, [], $acc['lens']);
        $this->assertFalse($out['summary']['held'], 'MIN_CELL match: the snapshot opens');
        $this->assertSame(5, (int) $out['summary']['responses']);
    }

    /** The shared copy promises individual comments stay with the owners. */
    public function testSharedPayloadsCarryNoVerbatimText(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'all', 'results_share_timing' => 'ongoing']);
        $page = (int) $this->scalar('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $os . ' LIMIT 1');
        // An open survey's structure is locked, so the paragraph goes in directly.
        $this->pdo->exec('INSERT INTO ' . DB_PREFIX . "survey_question (survey_id, page_id, sort_order, type, prompt, settings, created_at, updated_at)
                          VALUES ({$os}, {$page}, 9, 'paragraph', 'T11SHARE comments', '{}', NOW(), NOW())");
        $qid = (int) $this->pdo->lastInsertId();
        for ($i = 0; $i < 6; $i++) {
            $this->answer($os, $this->player('txt' . $i, $this->parkA, $this->k), 'full');
            $rid = (int) $this->scalar('SELECT MAX(response_id) FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $os);
            $this->pdo->exec('INSERT INTO ' . DB_PREFIX . "survey_answer (response_id, question_id, value_text) VALUES ({$rid}, {$qid}, 'T11SHARE secret {$i}')");
        }
        $manager = (string) json_encode((new SurveyReport())->aggregate($os, SurveyReport::normalizeFilters([])));
        $this->assertStringContainsString('T11SHARE secret', $manager, 'control: the owner reads the comments');
        $this->assertStringContainsString('"other_texts"', $manager);

        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        foreach ([[], ['crosstab_question_id' => $qid]] as $filters) {
            $out = (new SurveyReport())->sharedResults($os, $filters, $acc['lens']);
            $json = (string) json_encode($out);
            $this->assertStringNotContainsString('"texts"', $json);
            $this->assertStringNotContainsString('"other_texts"', $json);
            $this->assertStringNotContainsString('T11SHARE secret', $json);
        }
        $byId = array_column($out['questions'], null, 'question_id');
        $this->assertSame(5, (int) $byId[$qid]['n'], 'the count stays (ongoing snapshot: 5 of 6)');
    }

    /**
     * [A,B] minus [A] isolates B: a shared viewer's kingdom pick is the whole
     * lens or one kingdom, and that kingdom and the rest of the lens beside
     * it must each hold 0 or at least MIN_CELL responses.
     */
    public function testSharedKingdomPicksCannotDifferenceOutASmallKingdom(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'all', 'results_share_timing' => 'ongoing']);
        for ($i = 0; $i < 6; $i++) {
            $this->answer($os, $this->player('dh' . $i, $this->parkA, $this->k), 'full');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->answer($os, $this->player('da' . $i, $this->parkOther, $this->kOther), 'full');
        }
        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $this->afterCloseTiming($os);
        $rep = new SurveyReport();

        $this->assertSame(8, (int) $rep->sharedResults($os, [], $acc['lens'])['summary']['responses'], 'the whole lens');
        $away = $rep->sharedResults($os, ['kingdom_ids' => [$this->kOther]], $acc['lens']);
        $this->assertSame(0, (int) $away['summary']['responses'], 'a kingdom under 5 is refused');
        $this->assertTrue((bool) $away['summary']['suppressed']);
        $home = $rep->sharedResults($os, ['kingdom_ids' => [$this->k]], $acc['lens']);
        $this->assertSame(0, (int) $home['summary']['responses'], 'whole minus home would isolate the 2 away');
        $both = $rep->sharedResults($os, ['kingdom_ids' => [$this->k, $this->kOther]], $acc['lens']);
        $this->assertSame(0, (int) $both['summary']['responses'], 'a multi-kingdom subset is refused');

        for ($i = 2; $i < 5; $i++) {
            $this->answer($os, $this->player('da' . $i, $this->parkOther, $this->kOther), 'full');
        }
        $this->assertSame(6, (int) $rep->sharedResults($os, ['kingdom_ids' => [$this->k]], $acc['lens'])['summary']['responses'], 'both sides at 5+');
        $this->assertSame(5, (int) $rep->sharedResults($os, ['kingdom_ids' => [$this->kOther]], $acc['lens'])['summary']['responses']);
    }

    /**
     * Multi-way differencing: with A=10, B=10, C=1 each big pick passes a
     * two-way check, but whole - A - B is C's one response. Complementary
     * suppression allows only one of A and B.
     */
    public function testSharedKingdomPicksCannotBeSummedAgainstTheWhole(): void
    {
        $kThird = $this->kingdom('third');
        $parkThird = $this->park($kThird, 't');
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'all', 'results_share_timing' => 'ongoing']);
        for ($i = 0; $i < 10; $i++) {
            $this->answer($os, $this->player('mh' . $i, $this->parkA, $this->k), 'full');
            $this->answer($os, $this->player('ma' . $i, $this->parkOther, $this->kOther), 'full');
        }
        $this->answer($os, $this->player('mt', $parkThird, $kThird), 'full');
        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $this->afterCloseTiming($os);
        $rep = new SurveyReport();

        $whole = (int) $rep->sharedResults($os, [], $acc['lens'])['summary']['responses'];
        $this->assertSame(21, $whole);
        $sum = 0;
        foreach ([$this->k, $this->kOther, $kThird] as $kid) {
            $sum += (int) $rep->sharedResults($os, ['kingdom_ids' => [$kid]], $acc['lens'])['summary']['responses'];
        }
        $this->assertSame(10, $sum, 'only one of the two big kingdoms is served');
        $this->assertGreaterThanOrEqual(SurveyReport::MIN_CELL, $whole - $sum, 'whole minus every pick is a blend of 5+');
        $choices = $rep->sharedKingdomChoices($os, $acc['lens']);
        $this->assertCount(1, $choices, 'the filter list offers the same allowed set');
        $this->assertSame([10], array_values($choices));
    }

    /** A lone anonymous row (no kingdom) is the remainder of whole minus the kingdoms. */
    public function testSharedKingdomPicksCannotIsolateALoneAnonymousRow(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'all', 'results_share_timing' => 'ongoing']);
        for ($i = 0; $i < 6; $i++) {
            $this->answer($os, $this->player('ah' . $i, $this->parkA, $this->k), 'full');
        }
        for ($i = 0; $i < 5; $i++) {
            $this->answer($os, $this->player('aa' . $i, $this->parkOther, $this->kOther), 'full');
        }
        $this->answer($os, $this->player('anon', $this->parkA, $this->k), 'anonymous');
        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $this->afterCloseTiming($os);
        $rep = new SurveyReport();

        $this->assertSame(12, (int) $rep->sharedResults($os, [], $acc['lens'])['summary']['responses']);
        $home = (int) $rep->sharedResults($os, ['kingdom_ids' => [$this->k]], $acc['lens'])['summary']['responses'];
        $away = (int) $rep->sharedResults($os, ['kingdom_ids' => [$this->kOther]], $acc['lens'])['summary']['responses'];
        $this->assertSame(6, $home);
        $this->assertSame(0, $away, 'the smaller kingdom is withheld so whole - home - away is not the anonymous row');
        $this->assertSame([$this->k => 6], $rep->sharedKingdomChoices($os, $acc['lens']));
    }

    /** 'any' minus 'full' would isolate a lone partial row: shared viewers get no consent pick. */
    public function testSharedViewersCannotDifferenceByConsent(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'all', 'results_share_timing' => 'ongoing']);
        for ($i = 0; $i < 6; $i++) {
            $this->answer($os, $this->player('cf' . $i, $this->parkA, $this->k), 'full');
        }
        $this->answer($os, $this->player('cp', $this->parkA, $this->k), 'partial');
        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $this->afterCloseTiming($os);
        $rep = new SurveyReport();

        $this->assertSame(6, (int) $rep->summary($os, SurveyReport::normalizeFilters(['consent' => 'full']))['responses'], 'control: a manager can pick consent');
        foreach (['any', 'full', 'partial', 'anonymous'] as $c) {
            $this->assertSame(7, (int) $rep->sharedResults($os, ['consent' => $c], $acc['lens'])['summary']['responses'], "consent '{$c}' is ignored");
        }
    }

    /**
     * An ongoing share reads a snapshot that moves only once MIN_CELL new
     * responses arrive, so reloading after one publicly credited response
     * cannot diff out that player's answers. The owner stays live.
     */
    public function testOngoingShareServesASnapshotThatAdvancesInBatches(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'all', 'results_share_timing' => 'ongoing']);
        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $rep = new SurveyReport();
        $shared = function () use ($rep, $os, $acc): int {
            return (int) $rep->sharedResults($os, [], $acc['lens'])['summary']['responses'];
        };
        $expect = [1 => 0, 4 => 0, 5 => 5, 6 => 5, 9 => 5, 10 => 10, 11 => 10];
        for ($i = 1; $i <= 11; $i++) {
            $this->answer($os, $this->player('sn' . $i, $this->parkA, $this->k), 'full');
            if (isset($expect[$i])) {
                $this->assertSame($expect[$i], $shared(), "after {$i} responses");
            }
        }
        $this->assertSame(11, (int) $rep->summary($os, SurveyReport::normalizeFilters([]))['responses'], 'the owner is live');
    }

    public function testAddSystemCreditWritesEveryColumnAndBustsNothingElse(): void
    {
        $uid = $this->player('credit', $this->parkA, $this->k);
        $r = Ork3::$Lib->attendance->add_system_credit([
            'MundaneId' => $uid, 'ClassId' => 6, 'Date' => '2026-09-05', 'ParkId' => $this->parkA, 'KingdomId' => $this->k,
            'EventId' => 0, 'EventCalendarDetailId' => 0, 'Credits' => 1, 'Note' => 'Survey #1', 'ByWhomId' => $this->kOfficer,
            'EntryMethod' => 'survey',
        ]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $row = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ' . (int) $r['AttendanceId'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2026-09-05', $row['date']);
        $this->assertSame('survey', $row['entry_method']);
        $this->assertSame('Survey #1', $row['note']);
        $this->assertSame((string) $this->kOfficer, (string) $row['by_whom_id']);
        $this->assertSame('2026', (string) $row['date_year']);
        $this->assertSame('9', (string) $row['date_month']);
        $this->assertNotSame('0', (string) $row['date_week3']);
        $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ' . (int) $r['AttendanceId']);
    }

    public function testAddSystemCreditRefusesAnyOtherEntryMethodOrABadRequest(): void
    {
        $uid = $this->player('credit2', $this->parkA, $this->k);
        $base = ['MundaneId' => $uid, 'ClassId' => 6, 'Date' => '2026-09-05', 'ParkId' => $this->parkA, 'KingdomId' => $this->k,
                 'EventId' => 0, 'EventCalendarDetailId' => 0, 'Credits' => 1, 'Note' => 'x', 'ByWhomId' => 1];
        $this->assertSame(1, Ork3::$Lib->attendance->add_system_credit($base + ['EntryMethod' => 'manual'])['Status']);
        $this->assertSame(1, Ork3::$Lib->attendance->add_system_credit(['Date' => 'nope', 'EntryMethod' => 'survey'] + $base)['Status']);
    }

    /**
     * The token-free system writers must never be callable through the public
     * JSON service (orkservice/Json/index.php whitelists whole classes, and
     * Attendance is one of them). JsonServer refuses any requested method name
     * containing '_'; PHP method names are case-insensitive, so a lower-case
     * first letter alone would NOT keep a camelCase name off the endpoint.
     */
    public function testTokenFreeSystemWritersAreNotCallableThroughTheJsonService(): void
    {
        require_once ORK3_ROOT . '/system/lib/system/class.JsonServer.php';
        $server   = new JsonServer(['Attendance', 'EventPlanning']);
        $validate = new ReflectionMethod(JsonServer::class, 'validate_method');
        $validate->setAccessible(true);

        $found = 0;
        foreach ([Attendance::class, EventPlanning::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                if (strpos((string) $m->getDocComment(), 'NO TOKEN') === false) {
                    continue;
                }
                $found++;
                foreach ([$m->getName(), ucfirst($m->getName()), strtoupper($m->getName())] as $spelling) {
                    $this->assertFalse($validate->invoke($server, $class, $spelling), $class . '::' . $spelling . ' is reachable through JsonServer');
                }
            }
        }
        $this->assertSame(4, $found, 'every token-free system writer is covered (credit, event create, delete, re-date)');
        $this->assertFalse(method_exists(Attendance::class, 'AddSystemCredit'), 'no JSON-callable spelling may exist');
        $this->assertFalse(method_exists(EventPlanning::class, 'CreateSystemEvent'), 'no JSON-callable spelling may exist');
    }

    public function testCreateSystemEventMakesAOneDayPublishedParkEvent(): void
    {
        $r = Ork3::$Lib->eventplanning->create_system_event([
            'KingdomId' => $this->kOther,            // ignored for a park event
            'ParkId' => $this->parkA, 'Name' => 'Survey Credit - T11SHARE', 'Date' => '2026-09-05',
            'Description' => 'desc', 'Url' => 'javascript:alert(1)', 'UrlName' => 'Take the survey',
        ]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $e = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $r['EventId'])->fetch(PDO::FETCH_ASSOC);
        $d = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . (int) $r['DetailId'])->fetch(PDO::FETCH_ASSOC);
        try {
            $this->assertSame((string) $this->k, (string) $e['kingdom_id'], 'kingdom comes from the park');
            $this->assertSame((string) $this->parkA, (string) $e['park_id']);
            $this->assertSame('published', $e['status']);
            $this->assertSame('2026-09-05 00:00:00', $d['event_start']);
            $this->assertSame('2026-09-05 23:59:59', $d['event_end']);
            $this->assertSame((string) $this->parkA, (string) $d['at_park_id']);
            $this->assertSame('Other', $d['event_type']);
            $this->assertSame('', $d['url'], 'non-http(s) URLs are dropped');
        } finally {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . (int) $r['EventId']);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $r['EventId']);
        }
    }

    /** Enforced, not documented: inside a caller's transaction no event is made. */
    public function testCreateSystemEventAndEnsureEventRefuseInsideAnOpenTransaction(): void
    {
        global $DB;
        $s   = new Survey();
        $sid = $this->fx['survey'][] = (int) $s->create($this->kOfficer, 'kingdom', $this->k, 'T11SHARE intrans')['SurveyId'];
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $sid, ['type' => 'kingdom', 'id' => $this->k], 'event', true)['Status']);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET status = 'open', opened_at = NOW() WHERE survey_id = " . $sid);
        $stale = $this->credit()->configs($sid)[0];
        $row   = $this->row($sid);
        $name  = 'T11SHARE intrans direct';

        $DB->BeginTrans();
        try {
            $r = Ork3::$Lib->eventplanning->create_system_event([
                'KingdomId' => $this->k, 'ParkId' => 0, 'Name' => $name, 'Date' => '2026-09-05',
                'Description' => 'desc', 'Url' => '', 'UrlName' => '',
            ]);
            $this->assertSame(1, $r['Status']);

            $ensure = new ReflectionMethod(SurveyCredit::class, 'ensureEvent');
            $ensure->setAccessible(true);
            $this->assertSame($stale, $ensure->invoke(new SurveyCredit(), $stale, $row), 'config left unchanged');
        } finally {
            $DB->RollbackTrans();
        }
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'event WHERE name IN ('
            . $this->pdo->quote($name) . ', ' . $this->pdo->quote(SurveyCredit::eventName('T11SHARE intrans')) . ')'), 'no ork_event row');
        $this->assertSame(0, (int) $this->scalar('SELECT COALESCE(event_calendardetail_id, 0) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid));
    }

    private function credit(): SurveyCredit
    {
        return new SurveyCredit();
    }

    private function grants(int $surveyId): array
    {
        return $this->pdo->query('SELECT g.mundane_id, a.* FROM ' . DB_PREFIX . 'survey_credit_grant g
                                  JOIN ' . DB_PREFIX . 'attendance a ON a.attendance_id = g.attendance_id
                                  WHERE g.survey_id = ' . $surveyId . ' ORDER BY g.mundane_id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testEnableBackfillsAnyOrkDataOnlyAtTheHomeParkOnTheDayTaken(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $full = $this->player('full', $this->parkA, $this->k);
        $this->answer($ks, $full, 'full');
        $this->answer($ks, $this->player('part', $this->parkA, $this->k), 'partial');
        $this->answer($ks, $this->player('anon', $this->parkA, $this->k), 'anonymous');
        $submitted = (string) $this->scalar('SELECT DATE(submitted_at) FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $ks . ' AND mundane_id = ' . $full);

        $r = $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(1, $r['Granted']);

        $g = $this->grants($ks);
        $this->assertCount(1, $g);
        $this->assertSame((string) $full, (string) $g[0]['mundane_id']);
        $this->assertSame($submitted, $g[0]['date']);
        $this->assertSame((string) $this->parkA, (string) $g[0]['park_id']);
        $this->assertSame((string) $this->k, (string) $g[0]['kingdom_id']);
        $this->assertSame('6', (string) $g[0]['class_id'], 'no prior attendance: Color');
        $this->assertSame('Survey #' . $ks, $g[0]['note']);
        $this->assertSame('survey', $g[0]['entry_method']);
        $this->assertSame((string) $this->kOfficer, (string) $g[0]['by_whom_id']);
        $this->assertSame('1.00', number_format((float) $g[0]['credits'], 2));
    }

    /**
     * D1: a credit is public and dated, so only a respondent whose data gate
     * showed a credit line is ever credited, by the backfill or live. Someone
     * who chose Any ORK Data without being told (an older runner, another
     * client) gets nothing, and the panel counts them as no_notice. The flag
     * is never kept on a partial or anonymous row.
     */
    public function testOnlyRespondentsTheGateToldAboutCreditsAreCredited(): void
    {
        $ks     = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $told   = $this->player('told', $this->parkA, $this->k);
        $untold = $this->player('untold', $this->parkA, $this->k);
        $part   = $this->player('toldpart', $this->parkA, $this->k);
        $anon   = $this->player('toldanon', $this->parkA, $this->k);
        $this->answer($ks, $told, 'full');
        $this->answer($ks, $untold, 'full', false);
        $this->answer($ks, $part, 'partial');
        $this->answer($ks, $anon, 'anonymous');

        $notice = $this->pdo->query('SELECT consent, credit_notice FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $ks
            . ' ORDER BY response_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(
            [['full', '1'], ['full', '0'], ['partial', '0'], ['anonymous', '0']],
            array_map(static fn (array $r): array => [$r['consent'], (string) $r['credit_notice']], $notice)
        );

        $g   = ['type' => 'park', 'id' => $this->parkA];
        $pre = $this->credit()->status($this->pOfficerA, $ks, $g)['Credit']['mine']['preview']['home_park'];
        $this->assertSame(['eligible_now' => 1, 'no_home_park' => 0, 'no_notice' => 1], $pre);

        $r = $this->credit()->enable($this->pOfficerA, $ks, $g, 'home_park', true);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(1, $r['Granted'], 'the backfill credits only the respondent who was told');
        $this->assertSame([(string) $told], array_map(static fn (array $x): string => (string) $x['mundane_id'], $this->grants($ks)));
        $this->assertSame(['Granted' => 0, 'SkippedNoPark' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
        $this->assertSame(0, $this->credit()->status($this->pOfficerA, $ks, $g)['Credit']['pending'], 'nobody untold is owed');
        $this->assertSame('none', $this->credit()->grantFor($ks, $untold));

        $late = $this->player('lateuntold', $this->parkA, $this->k);
        $this->assertSame('none', $this->answer($ks, $late, 'full', false)['Credit'], 'no live credit without the line either');
        $this->assertSame('granted', $this->answer($ks, $this->player('latetold', $this->parkA, $this->k), 'full')['Credit']);
        $this->assertCount(2, $this->grants($ks));
    }

    /**
     * The notice reaches the domain: the runner always shows a credit line on
     * a gated survey and posts CreditNotice, and SurveyAjax/submit forwards it
     * through the model (without it every live credit would be refused).
     */
    public function testTheRunnerReportsTheCreditLineAndSubmitForwardsIt(): void
    {
        $take = (string) file_get_contents(DIR_UI . 'template/default/script/survey-take.js');
        $final = substr($take, (int) strpos($take, 'function renderFinal('), 3000);
        $this->assertMatchesRegularExpression('/esc\(s\.credit_available \? CREDIT_NOTE : CREDIT_MAYBE\).*creditNoticeShown = true;/s', $final, 'every gate shows one of the two lines');
        $this->assertStringContainsString('CreditNotice: (gate && creditNoticeShown) ? 1 : 0', $take);

        $ajax   = (string) file_get_contents(DIR_UI . 'controller/controller.SurveyAjax.php');
        $submit = substr($ajax, (int) strpos($ajax, 'public function submit('), 900);
        $this->assertMatchesRegularExpression('/\$notice\s*=\s*\$this->truthy\(\$_POST\[\'CreditNotice\'\] \?\? 0\);/', $submit);
        $this->assertStringContainsString('->submit($surveyId, $uid, $answers, $consent, $duration, $isTest, $notice)', $submit);
        $model = (string) file_get_contents(DIR_UI . 'model/model.Survey.php');
        $this->assertStringContainsString('->submit($surveyId, $uid, $answers, $consent, $durationSeconds, $isTest, $creditNotice)', $model);
    }

    public function testEnableIsPermanentAndRefusedTwiceOrUnconfirmedOrGateOff(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $g = ['type' => 'kingdom', 'id' => $this->k];
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, $g, 'home_park', false)['Status'], 'needs confirmation');
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, $g, 'teleport', true)['Status'], 'unknown mode');
        $this->assertSame(3, $this->credit()->enable($this->pOfficerA, $ks, $g, 'home_park', true)['Status'], 'not a kingdom officer');
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $g, 'home_park', true)['Status']);
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, $g, 'event', true)['Status'], 'configs are permanent');

        $gateOff = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['data_gate_enabled' => 0]);
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $gateOff, $g, 'home_park', true)['Status']);
    }

    /** Spec §5: an invalid grantor is status 1 even for someone who holds CREATE there; status 3 is only a missing canCreate. */
    public function testEnableRefusesAnInvalidGrantorAsABadRequestAndAMissingCreateAsUnauthorized(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $outside = $this->credit()->enable($this->kOtherOfficer, $ks, ['type' => 'kingdom', 'id' => $this->kOther], 'home_park', true);
        $this->assertSame(1, $outside['Status'], 'a kingdom outside the survey is not a grantor, though its officer holds CREATE there');
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, ['type' => 'park', 'id' => $this->parkOther], 'home_park', true)['Status'], 'a park outside the survey');
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, null, 'home_park', true)['Status'], 'no grantor');
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, ['type' => 'unit', 'id' => $this->k], 'home_park', true)['Status'], 'not a grantor type');
        $this->assertSame(3, $this->credit()->enable($this->pOfficerA, $ks, ['type' => 'park', 'id' => $this->parkB], 'home_park', true)['Status'], 'a valid grantor without CREATE');
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
    }

    public function testLiveGrantAfterEnableAndReconcileIsIdempotent(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $uid = $this->player('live', $this->parkB, $this->k);
        $this->answer($ks, $uid, 'full');
        $this->assertSame('granted', $this->credit()->grantFor($ks, $uid));
        $this->assertSame('granted', $this->credit()->grantFor($ks, $uid), 'second call is a no-op');
        $this->assertCount(1, $this->grants($ks));
        $this->assertSame(['Granted' => 0, 'SkippedNoPark' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'attendance WHERE note = \'Survey #' . $ks . '\''));
    }

    public function testOneCreditPerPlayerWhenKingdomAndParkBothGrant(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $uid = $this->player('both', $this->parkA, $this->k);
        $this->answer($ks, $uid, 'full');
        $this->assertSame(0, $this->credit()->enable($this->pOfficerA, $ks, ['type' => 'park', 'id' => $this->parkA], 'home_park', true)['Status']);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'event', true)['Status']);
        $g = $this->grants($ks);
        $this->assertCount(1, $g);
        $this->assertSame('0', (string) $g[0]['event_id'], 'the park config came first');
    }

    public function testEventModeCreatesOneEventDatedTheStartAndCreditsVisitors(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $visitor = $this->player('visitor', $this->parkOther, $this->kOther);
        $this->answer($ks, $this->player('local', $this->parkA, $this->k), 'full');
        // A visitor can only answer an event-audience survey; the coverage rule is what's under test here.
        $this->pdo->exec('INSERT INTO ' . DB_PREFIX . "survey_response (survey_id, consent, mundane_id, kingdom_id, park_id, credit_notice, is_test, submitted_at)
                          VALUES ({$ks}, 'full', {$visitor}, {$this->kOther}, {$this->parkOther}, 1, 0, NOW())");

        $r = $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'event', true);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(2, $r['Granted']);

        $cfg = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks)->fetch(PDO::FETCH_ASSOC);
        $this->assertGreaterThan(0, (int) $cfg['event_calendardetail_id']);
        $start = SurveyCredit::startDate($this->row($ks));
        foreach ($this->grants($ks) as $g) {
            $this->assertSame($start, $g['date']);
            $this->assertSame((string) $cfg['event_id'], (string) $g['event_id']);
        }
        $name = (string) $this->scalar('SELECT name FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $cfg['event_id']);
        $this->assertSame('Survey Credit - T11SHARE survey', $name);
        // Exactly one (§8), counted by name in the grantor kingdom, not by the
        // config's own id, so an orphan left by a re-created event would show.
        $kingdomEvents = fn (): int => (int) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'event WHERE kingdom_id = ' . $this->k
            . ' AND park_id = 0 AND name = ' . $this->pdo->quote($name));
        $this->assertSame(1, $kingdomEvents());
        $ev = $this->pdo->query('SELECT e.status, cd.event_start, cd.event_end FROM ' . DB_PREFIX . 'event e
                                 JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
                                 WHERE e.event_id = ' . (int) $cfg['event_id'])->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $ev, 'one occurrence');
        $this->assertSame('published', $ev[0]['status']);
        $this->assertSame($start . ' 00:00:00', $ev[0]['event_start'], 'one day: the start date');
        $this->assertSame($start . ' 23:59:59', $ev[0]['event_end']);

        // Reconcile is idempotent in event mode too: the linked event is reused.
        $this->assertSame(['Granted' => 0, 'SkippedNoPark' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
        $this->assertSame(['Granted' => 0, 'SkippedNoPark' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
        $this->assertSame(1, $kingdomEvents(), 'a second reconcile creates no second event');
        $this->assertSame((string) $cfg['event_calendardetail_id'], (string) $this->scalar('SELECT event_calendardetail_id FROM ' . DB_PREFIX
            . 'survey_credit WHERE credit_id = ' . (int) $cfg['credit_id']), 'the config keeps its event');

        // The one-day event covers its own start date (today here), yet the
        // attendance pages' "currently happening" nudge must never offer it (§3.4):
        // not the kingdom's, and not a park grantor's park-scoped one either.
        $pe = $this->credit()->enable($this->pOfficerA, $ks, ['type' => 'park', 'id' => $this->parkA], 'event', true);
        $this->assertSame(0, $pe['Status'], (string) ($pe['Error'] ?? ''));
        $parkDetail = (int) $this->scalar('SELECT event_calendardetail_id FROM ' . DB_PREFIX . "survey_credit
                                           WHERE survey_id = {$ks} AND grantor_type = 'park'");
        $this->assertGreaterThan(0, $parkDetail);
        // Control: an ordinary published one-day event on the same day still nudges.
        $plain = Ork3::$Lib->eventplanning->create_system_event([
            'KingdomId' => $this->k, 'ParkId' => 0, 'Name' => 'T11SHARE plain event', 'Date' => $start,
            'Description' => 'desc', 'Url' => '', 'UrlName' => '',
        ]);
        $this->assertSame(0, $plain['Status'], (string) ($plain['Error'] ?? ''));
        try {
            $ev = new Event();
            foreach ([['kingdom', $this->k, (int) $cfg['event_calendardetail_id']], ['park', $this->parkA, $parkDetail]] as [$scope, $id, $detail]) {
                $active = $ev->GetActiveEventsAtScope(['Scope' => $scope, 'ScopeId' => $id, 'Date' => $start]);
                $this->assertSame(0, (int) ($active['Status']['Status'] ?? 1));
                $this->assertNotContains($detail, array_column($active['Events'], 'EventCalendarDetailId'), "no {$scope} nudge for a survey credit event");
                if ($scope === 'kingdom') {
                    $this->assertContains((int) $plain['DetailId'], array_column($active['Events'], 'EventCalendarDetailId'), 'ordinary events still nudge');
                }
            }
        } finally {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . (int) $plain['EventId']);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $plain['EventId']);
        }
    }

    /**
     * Two requests that read an event config before either links an event
     * (enable's backfill beside a live submit, the sweep) each create one. The
     * link is conditional and the loser deletes its own, so one event remains
     * and no orphan "Survey Credit" occurrence is left to nudge attendance pages.
     */
    public function testConcurrentEventCreationLeavesOneLinkedEventAndNoOrphan(): void
    {
        $s   = new Survey();
        $sid = $this->fx['survey'][] = (int) $s->create($this->kOfficer, 'kingdom', $this->k, 'T11SHARE race')['SurveyId'];
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $sid, ['type' => 'kingdom', 'id' => $this->k], 'event', true)['Status']);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET status = 'open', opened_at = NOW() WHERE survey_id = " . $sid);
        $name = SurveyCredit::eventName('T11SHARE race');
        $countEvents = fn (): int => (int) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'event WHERE kingdom_id = ' . $this->k
            . ' AND name = ' . $this->pdo->quote($name));

        try {
            // Both read the unlinked config first, then each ensures its event.
            $stale  = $this->credit()->configs($sid)[0];
            $row    = $this->row($sid);
            $ensure = new ReflectionMethod(SurveyCredit::class, 'ensureEvent');
            $ensure->setAccessible(true);
            $a = $ensure->invoke(new SurveyCredit(), $stale, $row);
            $b = $ensure->invoke(new SurveyCredit(), $stale, $row);

            $linked = (int) $this->scalar('SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid);
            $this->assertGreaterThan(0, $linked);
            $this->assertSame($linked, (int) $a['event_calendardetail_id']);
            $this->assertSame($linked, (int) $b['event_calendardetail_id'], 'the loser uses the winner\'s event');
            $this->assertSame(1, $countEvents(), 'the losing event was deleted');

            $active = (new Event())->GetActiveEventsAtScope(['Scope' => 'kingdom', 'ScopeId' => $this->k, 'Date' => SurveyCredit::startDate($row)]);
            $this->assertNotContains($name, array_column($active['Events'], 'Name'), 'no survey credit occurrence nudges');
        } finally {
            foreach ($this->pdo->query('SELECT event_id FROM ' . DB_PREFIX . 'event WHERE kingdom_id = ' . $this->k . ' AND name = '
                . $this->pdo->quote($name))->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . (int) $eid);
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $eid);
            }
        }
    }

    /**
     * The credit event is dated the start date, and open_at stays editable. It
     * follows an open_at change while it holds no credits, so clearing a far-off
     * open_at no longer dates today's credits weeks in the future; once credits
     * exist it stays where they are.
     */
    public function testCreditEventFollowsOpenAtUntilItHoldsCredits(): void
    {
        $future = date('Y-m-d', strtotime('+20 days'));
        $today  = date('Y-m-d');
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['open_at' => $future . ' 09:00:00']);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'event', true)['Status']);
        $eventDate = fn (): string => (string) $this->scalar('SELECT DATE(cd.event_start) FROM ' . DB_PREFIX . 'event_calendardetail cd
            JOIN ' . DB_PREFIX . 'survey_credit c ON c.event_calendardetail_id = cd.event_calendardetail_id WHERE c.survey_id = ' . $ks);
        $this->assertSame($future, $eventDate(), 'dated the scheduled start');

        $this->assertSame(0, (new Survey())->update($ks, ['OpenAt' => ''])['Status']);
        $this->assertSame($today, $eventDate(), 'clearing open_at moves the empty event to the real start');

        $uid = $this->player('redate', $this->parkA, $this->k);
        $this->assertSame('granted', $this->answer($ks, $uid, 'full')['Credit']);
        $this->assertSame($today, (string) $this->grants($ks)[0]['date'], 'the credit is dated today, not in the future');

        $this->assertSame(0, (new Survey())->update($ks, ['OpenAt' => date('Y-m-d', strtotime('+5 days')) . ' 09:00:00'])['Status']);
        $this->assertSame($today, $eventDate(), 'an event holding credits keeps its date');
    }

    /** A retired home park is no home park: no automatic credit at a park that closed. */
    public function testRetiredHomeParkIsTreatedAsNoHomePark(): void
    {
        $retired = $this->park($this->k, 'retired');
        $uid = $this->player('retired', $retired, $this->k);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "park SET active = 'Retired' WHERE park_id = " . $retired);
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->answer($ks, $uid, 'full');
        $g = ['type' => 'kingdom', 'id' => $this->k];

        $pre = $this->credit()->status($this->kOfficer, $ks, $g)['Credit']['mine']['preview']['home_park'];
        $this->assertSame(['eligible_now' => 0, 'no_home_park' => 1, 'no_notice' => 0], $pre, 'the preview counts them as having no home park');

        $r = $this->credit()->enable($this->kOfficer, $ks, $g, 'home_park', true);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(0, $r['Granted']);
        $this->assertSame(1, $r['SkippedNoPark']);
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'attendance WHERE park_id = ' . $retired));
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $uid), 'and the runner does not promise one');
    }

    /**
     * An un-told respondent at a retired park is still one the credit would
     * have reached: the home-park warning counts them as no_notice, the same
     * as event mode does, instead of dropping them from both counts.
     */
    public function testHomeParkPreviewCountsUntoldRespondentsAtARetiredPark(): void
    {
        $retired = $this->park($this->k, 'retired2');
        $uid = $this->player('untold', $retired, $this->k);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "park SET active = 'Retired' WHERE park_id = " . $retired);
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->pdo->exec('INSERT INTO ' . DB_PREFIX . "survey_response (survey_id, consent, mundane_id, kingdom_id, park_id, credit_notice, is_test, submitted_at)
                          VALUES ({$ks}, 'full', {$uid}, {$this->k}, {$retired}, 0, 0, NOW())");

        $preview = $this->credit()->status($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k])['Credit']['mine']['preview'];
        $this->assertSame(['eligible_now' => 0, 'no_home_park' => 0, 'no_notice' => 1], $preview['home_park']);
        $this->assertSame(1, $preview['event']['no_notice'], 'both modes agree');
    }

    /** A deleted draft takes its credit configs and their generated event with it. */
    public function testDeletingADraftRemovesItsCreditConfigsAndEvent(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);   // opened: it has a start date
        // setStatus() refuses opened -> draft now; stage the draft-with-a-start-date row directly.
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET status = 'draft' WHERE survey_id = " . $ks);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'event', true)['Status']);
        $eventId = (int) $this->scalar('SELECT event_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks);
        $this->assertGreaterThan(0, $eventId, 'the draft owner\'s event exists');

        $this->assertSame(0, (new Survey())->delete($ks)['Status']);
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $eventId));
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $eventId));
        $this->assertNotContains($ks, $this->credit()->surveysWithConfigs(), 'out of the sweep\'s work list');
    }

    public function testDraftOwnerConfigGetsItsEventOnFirstOpen(): void
    {
        $s = new Survey();
        $r = $s->create($this->kOfficer, 'kingdom', $this->k, 'T11SHARE draft');
        $sid = $this->fx['survey'][] = (int) $r['SurveyId'];
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $sid, ['type' => 'kingdom', 'id' => $this->k], 'event', true)['Status']);
        $this->assertNull($this->scalar('SELECT event_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid) ?: null);

        $page = (int) $this->scalar('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $sid . ' LIMIT 1');
        $q = $s->questionAdd($sid, $page, 'single', null);
        $s->questionUpdate((int) $q['Question']['question_id'], ['Prompt' => 'T11SHARE q']);
        $this->credit()->onOpened($sid);   // Task 10 wires this into setStatus(); called directly here
        $this->assertNull($this->scalar('SELECT event_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid) ?: null, 'still a draft: no start date');
        $s->setStatus($sid, 'open');
        $this->credit()->onOpened($sid);
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT event_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid));
    }

    public function testNonOwnerCannotEnableOnADraftAndStatusHidesUnrelatedConfigs(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['status' => 'draft']);
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $os, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true)['Status']);

        $open = $this->openSurvey($this->kOfficer, 'ork', $this->k);
        $this->credit()->enable($this->kOtherOfficer, $open, ['type' => 'kingdom', 'id' => $this->kOther], 'home_park', true);
        $st = $this->credit()->status($this->kOfficer, $open, ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame(0, $st['Status']);
        $this->assertSame([], $st['Credit']['configs'], "another kingdom's config is not shown");
        $this->assertTrue($st['Credit']['mine']['can_enable']);
        $this->assertArrayHasKey('home_park', $st['Credit']['mine']['preview']);
    }

    /** A hidden config's owed credits must not leak through `pending` either. */
    public function testStatusPendingCountsOnlyTheConfigsTheViewerCanSee(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $os, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true)['Status']);
        $this->assertSame(0, $this->credit()->enable($this->kOtherOfficer, $os, ['type' => 'kingdom', 'id' => $this->kOther], 'home_park', true)['Status']);
        // Owed but not yet granted (a failed live grant): one player in each kingdom.
        $home = $this->player('owedhome', $this->parkA, $this->k);
        $away = $this->player('owedaway', $this->parkOther, $this->kOther);
        $this->pdo->exec('INSERT INTO ' . DB_PREFIX . "survey_response (survey_id, consent, mundane_id, kingdom_id, park_id, credit_notice, is_test, submitted_at)
                          VALUES ({$os}, 'full', {$home}, {$this->k}, {$this->parkA}, 1, 0, NOW()),
                                 ({$os}, 'full', {$away}, {$this->kOther}, {$this->parkOther}, 1, 0, NOW())");

        $mine = $this->credit()->status($this->kOfficer, $os, ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame(0, $mine['Status']);
        $this->assertCount(1, $mine['Credit']['configs'], "the other kingdom's config stays hidden");
        $this->assertSame(1, $mine['Credit']['pending'], "only this kingdom's owed credit is counted");

        $theirs = $this->credit()->status($this->kOtherOfficer, $os, ['type' => 'kingdom', 'id' => $this->kOther]);
        $this->assertSame(1, $theirs['Credit']['pending']);

        $park = $this->credit()->status($this->pOfficerA, $os, ['type' => 'park', 'id' => $this->parkA]);
        $this->assertSame(1, $park['Credit']['pending'], 'a park sees its own kingdom\'s config and its owed credit');

        // Reconcile still posts everything owed, whoever asked, but reports
        // only the credits under the configs the caller's panel shows.
        $this->assertSame(1, $this->credit()->reconcileAs($this->kOfficer, $os, ['type' => 'kingdom', 'id' => $this->k])['Granted'], "the other kingdom's credit is posted but not counted");
        $this->assertCount(2, $this->grants($os), 'both owed credits were posted');
        $this->assertSame(0, $this->credit()->status($this->kOfficer, $os, ['type' => 'kingdom', 'id' => $this->k])['Credit']['pending']);
        $this->assertSame(0, $this->credit()->status($this->kOtherOfficer, $os, ['type' => 'kingdom', 'id' => $this->kOther])['Credit']['pending']);
    }

    /**
     * credit_reconcile and credit_enable answer with counts, and a hidden
     * config's must not leak through them any more than through status():
     * Granted, Pending and SkippedNoPark cover the caller's visible configs.
     */
    public function testReconcileAndEnableCountOnlyTheConfigsTheCallerCanSee(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k);
        $this->assertSame(0, $this->credit()->enable($this->kOtherOfficer, $os, ['type' => 'kingdom', 'id' => $this->kOther], 'home_park', true)['Status']);
        // The other kingdom: one owed credit, and one full respondent with no home park (owed for ever).
        $away   = $this->player('awayowed', $this->parkOther, $this->kOther);
        $noPark = $this->player('awaynopark', $this->parkOther, $this->kOther);
        $this->pdo->exec('INSERT INTO ' . DB_PREFIX . "survey_response (survey_id, consent, mundane_id, kingdom_id, park_id, credit_notice, is_test, submitted_at)
                          VALUES ({$os}, 'full', {$away}, {$this->kOther}, {$this->parkOther}, 1, 0, NOW()),
                                 ({$os}, 'full', {$noPark}, {$this->kOther}, NULL, 1, 0, NOW())");
        $this->answer($os, $this->player('homeenable', $this->parkA, $this->k), 'full');

        $r = $this->credit()->enable($this->kOfficer, $os, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(1, $r['Granted'], "only this kingdom's backfill is reported");
        $this->assertSame(0, $r['SkippedNoPark'], "the other kingdom's unplaceable respondent is not");
        $this->assertCount(2, $this->grants($os), 'the owed credit elsewhere was still posted');

        $again = $this->credit()->reconcileAs($this->kOfficer, $os, ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame(['Granted' => 0, 'SkippedNoPark' => 0, 'Pending' => 0], array_intersect_key($again, array_flip(['Granted', 'SkippedNoPark', 'Pending'])));
        $theirs = $this->credit()->reconcileAs($this->kOtherOfficer, $os, ['type' => 'kingdom', 'id' => $this->kOther]);
        $this->assertSame(1, $theirs['SkippedNoPark'], 'the kingdom whose config it is still sees it');
        $this->assertSame(1, $this->credit()->reconcile($os)['SkippedNoPark'], 'the sweep counts everything');

        // The activity log records the new config's own backfill (§3.5).
        $act = $this->pdo->query('SELECT action, detail FROM ' . DB_PREFIX . "survey_activity
                                  WHERE survey_id = {$os} AND action = 'credit' ORDER BY activity_id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $act, 'one credit entry per enable');
        $detail = json_decode((string) $act[1]['detail'], true);
        $this->assertSame(['credit_id', 'grantor_type', 'grantor_id', 'mode', 'backfilled'], array_keys($detail));
        $this->assertSame(['kingdom', $this->k, 'home_park', 1], [$detail['grantor_type'], $detail['grantor_id'], $detail['mode'], $detail['backfilled']]);
        $this->assertSame((int) $r['CreditId'], $detail['credit_id']);
    }

    /**
     * D5: other orgs never see a draft and archived is hidden. The panel
     * endpoints (status, reconcile) used to answer any officer below the
     * owner with the draft's title, status, event name and preview counts.
     */
    public function testCreditPanelHidesDraftsAndArchivedSurveysFromOtherOrgs(): void
    {
        $ks     = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $orkRow = $this->openSurvey($this->kOfficer, 'ork', $this->k);
        $park   = ['type' => 'park', 'id' => $this->parkA];
        $kg     = ['type' => 'kingdom', 'id' => $this->k];
        $this->assertSame(0, $this->credit()->status($this->pOfficerA, $ks, $park)['Status'], 'open: a park below may look');

        foreach (['draft', 'archived'] as $st) {
            $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET status = '{$st}' WHERE survey_id IN ({$ks}, {$orkRow})");
            $p = $this->credit()->status($this->pOfficerA, $ks, $park);
            $this->assertSame(3, $p['Status'], "a park officer on the kingdom's {$st}");
            $this->assertArrayNotHasKey('Credit', $p, 'and learns nothing about it');
            $this->assertSame(3, $this->credit()->reconcileAs($this->pOfficerA, $ks, $park)['Status']);
            $this->assertSame(3, $this->credit()->status($this->kOfficer, $orkRow, $kg)['Status'], "a kingdom officer on an ORK {$st}");
            $this->assertSame(0, $this->credit()->status($this->kOfficer, $ks, $kg)['Status'], "the owner's own {$st} stays readable");
        }
    }

    /** §3.2: the owner may configure a draft, but nobody backfills an archived survey. */
    public function testOwnerCannotTurnCreditsOnForAnArchivedSurvey(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->answer($ks, $this->player('arch', $this->parkA, $this->k), 'full');
        $this->assertSame(0, (new Survey())->setStatus($ks, 'archived')['Status']);
        $r = $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('archived', $r['Error']);
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertCount(0, $this->grants($ks));
        $st = $this->credit()->status($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertFalse($st['Credit']['mine']['can_enable']);
        $this->assertStringContainsString('archived', $st['Credit']['mine']['blocked_reason']);
    }

    public function testCreditAvailableForFollowsCoverage(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $uid = $this->player('avail', $this->parkB, $this->k);
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $uid));
        $this->credit()->enable($this->pOfficerA, $ks, ['type' => 'park', 'id' => $this->parkA], 'home_park', true);
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $uid), 'park A does not cover park B');
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->assertTrue($this->credit()->creditAvailableFor($this->row($ks), $uid));
    }

    /** A player already holding the survey's credit is not promised another (e.g. a retake after cleared results). */
    public function testCreditAvailableSkipsAPlayerAlreadyGranted(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $granted = $this->player('held', $this->parkA, $this->k);
        $fresh   = $this->player('fresh', $this->parkA, $this->k);
        $this->assertTrue($this->credit()->creditAvailableFor($this->row($ks), $granted), 'covered, not yet granted');
        $this->assertSame('granted', $this->answer($ks, $granted, 'full')['Credit']);
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $granted), 'covered and already granted');
        $this->assertSame([$ks => false], $this->credit()->creditAvailableMap([$this->row($ks)], $granted));
        $this->assertSame([$ks => true], $this->credit()->creditAvailableMap([$this->row($ks)], $fresh), 'covered, not granted');
    }

    /** SELECTs run so far on the app's own connection (the one SurveyCredit uses). */
    private function appSelects(): int
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet("SHOW SESSION STATUS LIKE 'Com_select'");
        $this->assertTrue($rs && $rs->Next());
        return (int) $rs->CurrentFieldSet()['Value'];
    }

    /** My Amtgard's list resolves every credit flag in a fixed number of queries, not one pair per survey. */
    public function testCreditAvailableMapAgreesWithTheSingleCheckInAFixedNumberOfQueries(): void
    {
        $uid     = $this->player('mapper', $this->parkB, $this->k);
        $covered = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $parkA   = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $plain   = [$this->openSurvey($this->kOfficer, 'kingdom', $this->k), $this->openSurvey($this->kOfficer, 'kingdom', $this->k)];
        $gateOff = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $covered, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->credit()->enable($this->pOfficerA, $parkA, ['type' => 'park', 'id' => $this->parkA], 'home_park', true);
        $this->credit()->enable($this->kOfficer, $gateOff, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'survey SET data_gate_enabled = 0 WHERE survey_id = ' . $gateOff);

        $rows = array_map([$this, 'row'], array_merge([$covered, $parkA, $gateOff], $plain));
        $want = [$covered => true, $parkA => false, $gateOff => false, $plain[0] => false, $plain[1] => false];
        $c = $this->credit();

        $before = $this->appSelects();
        $map = $c->creditAvailableMap($rows, $uid);
        $used = $this->appSelects() - $before;
        $this->assertSame($want, $map);
        $this->assertLessThanOrEqual(3, $used, 'configs + player + parent map, whatever the list length');

        foreach ($rows as $r) {
            $this->assertSame($want[(int) $r['survey_id']], $this->credit()->creditAvailableFor($r, $uid), 'survey ' . $r['survey_id']);
        }

        $plainRows = array_map([$this, 'row'], $plain);
        $before = $this->appSelects();
        $this->assertSame([$plain[0] => false, $plain[1] => false], $this->credit()->creditAvailableMap($plainRows, $uid));
        $this->assertSame(1, $this->appSelects() - $before, 'no configs: the player is never looked up');

        $gateOffOnly = $this->row($gateOff);
        $before = $this->appSelects();
        $this->assertSame([$gateOff => false], $this->credit()->creditAvailableMap([$gateOffOnly], $uid));
        $this->assertSame(0, $this->appSelects() - $before, 'no gated survey: no query at all');
    }

    public function testSubmitGrantsAfterCommitAndReportsIt(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->assertSame('granted', $this->answer($ks, $this->player('s1', $this->parkA, $this->k), 'full')['Credit']);
        $this->assertSame('none', $this->answer($ks, $this->player('s2', $this->parkA, $this->k), 'partial')['Credit']);
        $this->assertSame('none', $this->answer($ks, $this->player('s3', $this->parkA, $this->k), 'anonymous')['Credit']);
        $this->assertCount(1, $this->grants($ks));
    }

    /** One ordinary attendance row (every NOT NULL column named). */
    private function manualAttendance(int $uid, string $date, int $parkId, int $kingdomId, int $classId, string $note): int
    {
        $st = $this->pdo->prepare('INSERT INTO ' . DB_PREFIX . 'attendance
            (mundane_id, class_id, date, date_year, date_month, date_week3, date_week6, park_id, kingdom_id,
             event_id, event_calendardetail_id, credits, persona, flavor, note, by_whom_id, entry_method, entered_at)
            VALUES (?, ?, ?, YEAR(?), MONTH(?), 1, 1, ?, ?, 0, 0, 1, \'\', \'\', ?, ?, \'manual\', NOW())');
        $st->execute([$uid, $classId, $date, $date, $date, $parkId, $kingdomId, $note, $this->kOfficer]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * AC 7 / D6: a credit failure never costs the response. The attendance
     * insert is made to fail (an identical row already holds its unique key);
     * submit still commits and answers Status 0 with Credit 'pending', and
     * reconcile posts the credit once the obstacle is gone.
     */
    public function testACreditFailureNeverLosesTheResponseAndReconcileRepairsIt(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true)['Status']);
        $uid   = $this->player('pending', $this->parkA, $this->k);
        $block = $this->manualAttendance($uid, date('Y-m-d'), $this->parkA, $this->k, 6, 'Survey #' . $ks);

        $log = ini_set('error_log', '/dev/null');   // the grant failure is logged on purpose
        try {
            $r = (new SurveyResponse())->submit($ks, $uid, [], 'full', 30, false, true);
        } finally {
            ini_set('error_log', (string) $log);
        }
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame('pending', $r['Credit']);
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . "survey_response
                                                       WHERE survey_id = {$ks} AND mundane_id = {$uid} AND consent = 'full'"), 'the response is kept');
        $this->assertCount(0, $this->grants($ks));
        $this->assertSame(1, $this->credit()->status($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k])['Credit']['pending']);

        $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ' . $block);
        $this->assertSame(['Granted' => 1, 'SkippedNoPark' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
        $this->assertCount(1, $this->grants($ks));
        $this->assertSame(['Granted' => 0, 'SkippedNoPark' => 0, 'Pending' => 0], $this->credit()->reconcile($ks), 'and re-running changes nothing');
    }

    /** §8: a live grant uses the player's last class, not Color, when they have one. */
    public function testLiveGrantUsesThePlayersLastClass(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $uid = $this->player('lastclass', $this->parkA, $this->k);
        $this->manualAttendance($uid, date('Y-m-d', strtotime('-7 days')), $this->parkA, $this->k, 3, 'T11SHARE prior');
        $this->assertSame('granted', $this->answer($ks, $uid, 'full')['Credit']);
        $this->assertSame('3', (string) $this->grants($ks)[0]['class_id'], 'the class of their last attendance');
    }

    /** SurveyCredit with a small synchronous grant cap, so a test survey can exceed it. */
    private function cappedCredit(int $cap): SurveyCredit
    {
        $c = new SurveyCredit();
        $p = new ReflectionProperty(SurveyCredit::class, 'syncCap');
        $p->setAccessible(true);
        $p->setValue($c, $cap);
        return $c;
    }

    /**
     * #25: a web request posts a bounded batch and reports the rest as
     * Remaining (the sweep finishes them); a retry of the same enable is a
     * success that carries on the backfill, while a different mode is still
     * refused. The backfill's batched last-class lookup matches the live one.
     */
    public function testEnableBackfillIsBoundedAndARetryWithTheSameModeSucceeds(): void
    {
        $ks  = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $g   = ['type' => 'kingdom', 'id' => $this->k];
        $ids = [];
        foreach (['b1', 'b2', 'b3'] as $s) {
            $this->answer($ks, $ids[] = $this->player($s, $this->parkA, $this->k), 'full');
        }
        $this->manualAttendance($ids[0], date('Y-m-d', strtotime('-9 days')), $this->parkA, $this->k, 2, 'T11SHARE older');
        $this->manualAttendance($ids[0], date('Y-m-d', strtotime('-3 days')), $this->parkA, $this->k, 3, 'T11SHARE newer');

        $r = $this->cappedCredit(2)->enable($this->kOfficer, $ks, $g, 'home_park', true);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame([2, 1, false], [$r['Granted'], $r['Remaining'], $r['Already']]);
        $this->assertCount(2, $this->grants($ks));
        $this->assertSame('3', (string) $this->grants($ks)[0]['class_id'], 'batched lookup: the class of their latest attendance');
        $this->assertSame('6', (string) $this->grants($ks)[1]['class_id'], 'no attendance: Color');
        $this->assertSame(1, $this->credit()->status($this->kOfficer, $ks, $g)['Credit']['pending'], 'the rest stays owed');

        $again = $this->cappedCredit(2)->enable($this->kOfficer, $ks, $g, 'home_park', true);
        $this->assertSame(0, $again['Status'], 'retrying the same enable is not an error');
        $this->assertSame([true, 1, 0], [$again['Already'], $again['Granted'], $again['Remaining']]);
        $this->assertSame((int) $r['CreditId'], (int) $again['CreditId']);
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, $g, 'event', true)['Status'], 'a different mode is still refused');
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertCount(3, $this->grants($ks));
    }

    public function testPanelReconcileIsBoundedAndTheSweepReconcileIsNot(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $g  = ['type' => 'kingdom', 'id' => $this->k];
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $g, 'home_park', true)['Status']);
        // Owed but not yet posted (as if their live grants had failed).
        foreach (['r1', 'r2', 'r3'] as $s) {
            $uid = $this->player($s, $this->parkA, $this->k);
            $this->answer($ks, $uid, 'full');
            $this->pdo->exec('DELETE a, g FROM ' . DB_PREFIX . 'survey_credit_grant g JOIN ' . DB_PREFIX . 'attendance a ON a.attendance_id = g.attendance_id
                              WHERE g.survey_id = ' . $ks . ' AND g.mundane_id = ' . $uid);
        }
        $r = $this->cappedCredit(1)->reconcileAs($this->kOfficer, $ks, $g);
        $this->assertSame(0, $r['Status']);
        $this->assertSame([1, 2], [$r['Granted'], $r['Remaining']]);
        $this->assertSame(['Granted' => 2, 'SkippedNoPark' => 0, 'Pending' => 0], $this->cappedCredit(1)->reconcile($ks), 'the sweep finishes the backlog');
        $this->assertCount(3, $this->grants($ks));
    }

    /**
     * #26: a banned or currently suspended player is never credited, by the
     * backfill, the live grant or the sweep. Their credit is held (counted
     * in the panel) and posts once the sanction lifts; an expired suspension
     * is no sanction.
     */
    public function testBannedAndSuspendedPlayersAreHeldUntilTheSanctionLifts(): void
    {
        $ks      = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $g       = ['type' => 'kingdom', 'id' => $this->k];
        $clean   = $this->player('clean', $this->parkA, $this->k);
        $banned  = $this->player('banned', $this->parkA, $this->k);
        $susp    = $this->player('susp', $this->parkA, $this->k);
        $forever = $this->player('forever', $this->parkA, $this->k);
        $expired = $this->player('expired', $this->parkA, $this->k);
        foreach ([$clean, $banned, $susp, $forever, $expired] as $uid) {
            $this->answer($ks, $uid, 'full');
        }
        $m = DB_PREFIX . 'mundane';
        $this->pdo->exec("UPDATE {$m} SET penalty_box = 1 WHERE mundane_id = {$banned}");
        $this->pdo->exec("UPDATE {$m} SET suspended = 1, suspended_at = CURDATE(), suspended_until = CURDATE() + INTERVAL 30 DAY WHERE mundane_id = {$susp}");
        $this->pdo->exec("UPDATE {$m} SET suspended = 1, suspended_at = CURDATE(), suspended_until = NULL WHERE mundane_id = {$forever}");
        $this->pdo->exec("UPDATE {$m} SET suspended = 1, suspended_at = CURDATE() - INTERVAL 30 DAY, suspended_until = CURDATE() - INTERVAL 1 DAY WHERE mundane_id = {$expired}");

        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $g, 'home_park', true)['Status']);
        $got = array_map(static fn (array $x): int => (int) $x['mundane_id'], $this->grants($ks));
        sort($got);
        $want = [$clean, $expired];
        sort($want);
        $this->assertSame($want, $got);

        $st = $this->credit()->status($this->kOfficer, $ks, $g)['Credit'];
        $this->assertSame([0, 3], [$st['pending'], $st['held']]);
        $this->assertSame('none', $this->credit()->grantFor($ks, $susp), 'no live credit while suspended');
        $this->assertSame(['Granted' => 0, 'SkippedNoPark' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));

        $this->pdo->exec("UPDATE {$m} SET penalty_box = 0 WHERE mundane_id = {$banned}");
        $this->assertSame(['Granted' => 1, 'SkippedNoPark' => 0, 'Pending' => 0], $this->credit()->reconcile($ks), 'posts once the ban lifts');
        $this->assertSame(2, $this->credit()->status($this->kOfficer, $ks, $g)['Credit']['held']);
    }

    /** Spec §8 / AC 3: rows and export stay manager-only; shared access never opens them. */
    public function testSharedViewersCannotReachRowsOrExport(): void
    {
        $ks  = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'all', 'results_share_timing' => 'ongoing']);
        $acc = (new Survey())->resultsAccess($this->pOfficerA, $this->row($ks), ['type' => 'park', 'id' => $this->parkA]);
        $this->assertSame('shared', $acc['level'], 'the park officer may read charts');
        $this->assertFalse((new Survey())->canManage($this->pOfficerA, $this->row($ks)), 'but fails the gate rows and export use');

        // The gates themselves: SurveyAjax/rows and Survey/export check can_manage,
        // never results_access (a shared viewer passes that one).
        $ajax  = (string) file_get_contents(DIR_UI . 'controller/controller.SurveyAjax.php');
        $rows  = substr($ajax, (int) strpos($ajax, 'public function rows('), 700);
        $this->assertMatchesRegularExpression('/requireManage\(\$uid, \$surveyId\);.*\$this->Survey->rows\(/s', $rows);
        $this->assertStringNotContainsString('results_access', $rows);
        $page   = (string) file_get_contents(DIR_UI . 'controller/controller.Survey.php');
        $export = substr($page, (int) strpos($page, 'public function export('), 900);
        $this->assertMatchesRegularExpression('/if \(!\$this->Survey->can_manage\(\$uid, \$row\)\).*http_response_code\(403\)/s', $export);
        $this->assertStringNotContainsString('results_access', $export);
    }

    /** §3.5: Clone copies no credit configs; a new draft inherits no promise. */
    public function testCloneDoesNotCopyCreditConfigs(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $c = (new Survey())->cloneSurvey($ks, $this->kOfficer);
        $this->assertSame(0, $c['Status'], (string) ($c['Error'] ?? ''));
        $copy = $this->fx['survey'][] = (int) $c['SurveyId'];
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $copy));
    }

    /** Clone copies the results-sharing setting like every other survey setting. */
    public function testCloneKeepsResultsSharing(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped', 'results_share_timing' => 'ongoing']);
        $c = (new Survey())->cloneSurvey($ks, $this->kOfficer);
        $this->assertSame(0, $c['Status'], (string) ($c['Error'] ?? ''));
        $copy = $this->fx['survey'][] = (int) $c['SurveyId'];
        $this->assertSame('scoped', (string) $this->row($copy)['results_share']);
    }

    public function testGateCannotBeTurnedOffOnceCreditsExist(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->assertSame(1, (new Survey())->update($ks, ['DataGateEnabled' => 0])['Status']);
        $this->assertSame('1', (string) $this->row($ks)['data_gate_enabled']);
    }

    public function testOpeningCreatesTheEventThroughSetStatus(): void
    {
        $s = new Survey();
        $sid = $this->fx['survey'][] = (int) $s->create($this->kOfficer, 'kingdom', $this->k, 'T11SHARE hook')['SurveyId'];
        $page = (int) $this->scalar('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $sid . ' LIMIT 1');
        $q = $s->questionAdd($sid, $page, 'single', null);
        $s->questionUpdate((int) $q['Question']['question_id'], ['Prompt' => 'T11SHARE q']);
        $this->credit()->enable($this->kOfficer, $sid, ['type' => 'kingdom', 'id' => $this->k], 'event', true);
        $s->setStatus($sid, 'open');
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT event_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid));
    }

    /**
     * opened_at feeds SurveyCredit::startDate(), which dates event-mode credits
     * on players' public records. It must be written on the module's one clock
     * (PHP's, SurveyResponse::nowStamp()), not SQL NOW(): the DB server runs
     * UTC, so a US-evening open used to date the event the next day. A zone
     * 14 hours from UTC makes any NOW() write show up regardless of the DB zone.
     */
    public function testOpenAndCloseStampsUseThePhpClockSoTheStartDateIsTheLocalDay(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        try {
            $s = new Survey();
            $sid = $this->fx['survey'][] = (int) $s->create($this->kOfficer, 'kingdom', $this->k, 'T11SHARE clock')['SurveyId'];
            $page = (int) $this->scalar('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $sid . ' LIMIT 1');
            $q = $s->questionAdd($sid, $page, 'single', null);
            $s->questionUpdate((int) $q['Question']['question_id'], ['Prompt' => 'T11SHARE q']);

            $this->assertSame(0, $s->setStatus($sid, 'open')['Status']);
            $row = $this->row($sid);
            $this->assertLessThan(120, abs(strtotime((string) $row['opened_at']) - time()), 'opened_at is on the PHP clock');
            $this->assertSame(date('Y-m-d'), SurveyCredit::startDate($row), 'the start date is the local day');

            $this->assertSame(0, $s->setStatus($sid, 'closed')['Status']);
            $this->assertLessThan(120, abs(strtotime((string) $this->row($sid)['closed_at']) - time()), 'closed_at is on the PHP clock');
        } finally {
            date_default_timezone_set($tz);
        }
    }

    /** Run bin/survey-credit-sweep.php in a clean CLI process against the sandbox. */
    private function runSweep(array $env, array $args = []): array
    {
        // Host-side PHP has no memcached extension; startup constructs Ghettocache
        // unconditionally, so the child gets the same stub tests/bootstrap.php uses.
        $prepend = null;
        $php = [PHP_BINARY, '-d', 'error_reporting=8191'];   // E_ALL minus deprecations; warnings still show
        if (!extension_loaded('memcached')) {
            $prepend = tempnam(sys_get_temp_dir(), 'sweepstub');
            file_put_contents($prepend, '<?php if (!class_exists("Memcached", false)) { class Memcached {
                public function addServer($h, $p) { return true; }
                public function get($k) { return false; }
                public function set($k, $v, $e = 0) { return true; }
                public function delete($k) { return true; }
                public function getStats() { return ["localhost:11211" => ["time" => time()]]; }
            } }');
            array_push($php, '-d', 'auto_prepend_file=' . $prepend);
        }
        $cmd = array_merge($php, [ORK3_ROOT . '/bin/survey-credit-sweep.php'], $args);
        $env = $env + ['ENVIRONMENT' => 'TEST', 'PATH' => (string) getenv('PATH')];
        foreach (['ORK3_TEST_DB_HOST', 'ORK3_TEST_DB_PORT'] as $k) {
            if (getenv($k) !== false) {
                $env[$k] = (string) getenv($k);
            }
        }
        // Files, not pipes: a full pipe the parent is not draining deadlocks the child.
        $outFile = tempnam(sys_get_temp_dir(), 'sweep');
        $errFile = tempnam(sys_get_temp_dir(), 'sweep');
        $proc = proc_open($cmd, [1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes, ORK3_ROOT, $env);
        $exit = proc_close($proc);
        $out = (string) file_get_contents($outFile);
        $err = (string) file_get_contents($errFile);
        unlink($outFile);
        unlink($errFile);
        if ($prepend !== null) {
            unlink($prepend);
        }
        return ['exit' => $exit, 'out' => $out, 'err' => $err];
    }

    /**
     * The config builds every URL from $_SERVER['HTTP_HOST'], which the CLI does
     * not have: the sweep used to print undefined-key warnings and create
     * credit events whose survey link was dropped ('http:///orkui/...' has no
     * scheme). It now requires the public host and refuses to run without it.
     */
    public function testSweepRequiresTheSiteHostAndLinksTheEventsItCreates(): void
    {
        $none = $this->runSweep([]);
        $this->assertSame(2, $none['exit'], $none['out'] . $none['err']);
        $this->assertStringContainsString('HTTP_HOST', $none['err']);
        $this->assertStringNotContainsString('Undefined array key', $none['out'] . $none['err']);

        $bad = $this->runSweep(['HTTP_HOST' => 'bad host/x']);
        $this->assertSame(2, $bad['exit'], 'a malformed host is refused');

        // An event-mode config whose survey opened without the setStatus hook,
        // so the sweep is what creates the event.
        $s = new Survey();
        $sid = $this->fx['survey'][] = (int) $s->create($this->kOfficer, 'kingdom', $this->k, 'T11SHARE sweep')['SurveyId'];
        $page = (int) $this->scalar('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $sid . ' LIMIT 1');
        $q = $s->questionAdd($sid, $page, 'single', null);
        $s->questionUpdate((int) $q['Question']['question_id'], ['Prompt' => 'T11SHARE q']);
        $en = $this->credit()->enable($this->kOfficer, $sid, ['type' => 'kingdom', 'id' => $this->k], 'event', true);
        $this->assertSame(0, $en['Status'], (string) ($en['Error'] ?? ''));
        $this->assertSame(0, (int) $this->scalar('SELECT COALESCE(event_id, 0) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid));
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET status = 'open', opened_at = '2026-09-05 10:00:00' WHERE survey_id = " . $sid);

        $run = $this->runSweep(['HTTP_HOST' => 'sweep.example.test']);
        $this->assertSame(0, $run['exit'], $run['out'] . $run['err']);
        $this->assertStringNotContainsString('Warning', $run['out'] . $run['err']);
        $detail = (int) $this->scalar('SELECT COALESCE(event_calendardetail_id, 0) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid);
        $this->assertGreaterThan(0, $detail, 'the sweep created the event');
        $slug = (string) $this->scalar('SELECT slug FROM ' . DB_PREFIX . 'survey WHERE survey_id = ' . $sid);
        $this->assertSame(
            'http://sweep.example.test/orkui/index.php?Route=Survey/s/' . $slug,
            (string) $this->scalar('SELECT url FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . $detail)
        );
    }

    /**
     * bin/ is under the web docroot. The sweep must refuse every non-CLI SAPI
     * before startup.php: under FPM getenv('HTTP_HOST') is the request's Host
     * header, so an anonymous GET used to run the whole credit engine (200).
     */
    public function testSweepRefusesToRunOverHttp(): void
    {
        $src = (string) file_get_contents(ORK3_ROOT . '/bin/survey-credit-sweep.php');
        $guard = strpos($src, "'cli' !== PHP_SAPI");
        $this->assertNotFalse($guard, 'a PHP_SAPI guard');
        $this->assertLessThan(strpos($src, "\$host = '';"), $guard, 'the guard runs before the host is read');
        $this->assertLessThan(strpos($src, 'require_once'), $guard, 'and before startup.php');

        $base = rtrim((string) (getenv('ORK3_E2E_BASE_URL') ?: 'http://127.0.0.1:19080/orkui/'), '/');
        $url  = preg_replace('#/orkui$#', '', $base) . '/bin/survey-credit-sweep.php';
        $ctx  = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $head = $http_response_header[0] ?? '';
        if ($body === false && $head === '') {
            $this->markTestSkipped('The local web server is not reachable.');
        }
        $this->assertMatchesRegularExpression('#\s404\s#', $head . ' ', 'served over HTTP the sweep answers 404 and runs nothing');
    }

    public function testRunnerSeesCreditAvailableOnlyWhenCovered(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $uid = $this->player('runner', $this->parkA, $this->k);
        $def = (new SurveyResponse())->definitionForRespondent($ks, $uid, false);
        $this->assertFalse($def['Survey']['credit_available']);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $def = (new SurveyResponse())->definitionForRespondent($ks, $uid, false);
        $this->assertTrue($def['Survey']['credit_available']);
        $rows = array_values(array_filter((new SurveyResponse())->availableFor($uid), static fn ($r) => $r['survey_id'] === $ks));
        $this->assertTrue($rows[0]['credit_available']);
    }

    public function testSurveyCreditsDoNotCountAsRecentAttendance(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $uid = $this->player('recent', $this->parkA, $this->k);
        $this->answer($ks, $uid, 'full');   // earns a survey credit dated today

        $recent = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['audience_recent_months' => 6]);
        $e = (new SurveyResponse())->eligibility($this->row($recent), $uid);
        $this->assertFalse($e['eligible']);
        $this->assertSame('recent_attendance', $e['reason']);

        // The set-based mirror (the builder's audience count and response rate) too.
        $this->assertSame(0, (new SurveyResponse())->audienceCount($this->row($recent)), 'a survey credit alone puts nobody in the audience');
        $this->manualAttendance($uid, date('Y-m-d', strtotime('-3 days')), $this->parkA, $this->k, 6, 'T11SHARE park day');
        $this->assertSame(1, (new SurveyResponse())->audienceCount($this->row($recent)), 'control: a real park day does');
    }
}
