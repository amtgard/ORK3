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

    /** A draft that has never opened may move to another org the actor holds; after open, never. */
    public function testScopeMovesOnlyWhileDraftAndOnlyToAnOrgTheActorHolds(): void
    {
        $s = new Survey();
        $s->setActor($this->pOfficerA);
        $r = $s->create($this->pOfficerA, 'park', $this->parkA, 'T11SHARE scope');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $sid = $this->fx['survey'][] = (int) $r['SurveyId'];

        // No authority on park B or the kingdom: denied, and the row is untouched.
        $this->assertSame(3, $s->update($sid, ['ScopeType' => 'park', 'ScopeId' => $this->parkB])['Status']);
        $this->assertSame(3, $s->update($sid, ['ScopeType' => 'kingdom', 'ScopeId' => $this->k])['Status']);
        $this->assertSame('park', $this->row($sid)['scope_type']);
        $this->assertSame($this->parkA, (int) $this->row($sid)['scope_id']);

        // A type that is not a scope, and an org that does not exist.
        $this->assertSame(1, $s->update($sid, ['ScopeType' => 'unit', 'ScopeId' => 1])['Status']);
        $this->officer($this->pOfficerA, AUTH_PARK, $this->parkB);
        $this->assertSame(1, $s->update($sid, ['ScopeType' => 'park', 'ScopeId' => 999999999])['Status']);

        // The same scope again is a no-op success; holding park B too, the move lands.
        $this->assertSame(0, $s->update($sid, ['ScopeType' => 'park', 'ScopeId' => $this->parkA])['Status']);
        $this->assertSame(0, $s->update($sid, ['ScopeType' => 'park', 'ScopeId' => $this->parkB])['Status']);
        $this->assertSame($this->parkB, (int) $this->row($sid)['scope_id']);

        // Kingdom -> park resets what only made sense above it: the kingdom list and sharing.
        $ks = new Survey();
        $ks->setActor($this->kOfficer);
        $r = $ks->create($this->kOfficer, 'kingdom', $this->k, 'T11SHARE scope k');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $kid = $this->fx['survey'][] = (int) $r['SurveyId'];
        $this->assertSame(0, $ks->update($kid, ['ResultsShare' => 'all'])['Status']);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'survey SET audience_kingdom_ids = \'[' . $this->k . ']\' WHERE survey_id = ' . $kid);
        $this->officer($this->kOfficer, AUTH_PARK, $this->parkA);
        $this->assertSame(0, $ks->update($kid, ['ScopeType' => 'park', 'ScopeId' => $this->parkA])['Status']);
        $row = $this->row($kid);
        $this->assertSame('park', $row['scope_type']);
        $this->assertSame($this->parkA, (int) $row['scope_id']);
        $this->assertSame('none', $row['results_share']);
        $this->assertNull($row['audience_kingdom_ids']);
        $this->assertNull($row['audience_event_calendardetail_id']);

        // Once opened the scope is fixed, even for someone who holds both orgs.
        $page = (int) $this->pdo->query('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $kid . ' LIMIT 1')->fetchColumn();
        $q = $ks->questionAdd($kid, $page, 'single', null);
        $ks->questionUpdate((int) $q['Question']['question_id'], ['Prompt' => 'T11SHARE pick one']);
        $this->assertSame(0, $ks->setStatus($kid, 'open')['Status']);
        $r = $ks->update($kid, ['ScopeType' => 'kingdom', 'ScopeId' => $this->k]);
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('fixed once', (string) $r['Error']);
        $this->assertSame('park', $this->row($kid)['scope_type']);
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

        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $kings, ['type' => 'kingdom', 'id' => $this->k])['Status']);
        $row = $rowFor();
        $this->assertFalse($row['CreditOn'], 'the park has no config of its own');
        $this->assertSame((new Survey())->scopeName('kingdom', $this->k), $row['CreditCoveredBy']);
        $this->assertTrue($row['CreditShownOn'], 'covered: the row shows the credit as on (the domain decides, not the template)');

        $this->assertSame(0, $this->credit()->enable($this->pOfficerA, $kings, ['type' => 'park', 'id' => $this->parkA])['Status']);
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

    private function credit(): SurveyCredit
    {
        return new SurveyCredit();
    }

    /** The survey's ledger rows joined to the bonus credits they point at, by player. */
    private function grants(int $surveyId): array
    {
        return $this->pdo->query('SELECT g.mundane_id, g.credit_id AS switch_id, b.* FROM ' . DB_PREFIX . 'survey_credit_grant g
                                  JOIN ' . DB_PREFIX . 'bonus_credit b ON b.bonus_credit_id = g.bonus_credit_id
                                  WHERE g.survey_id = ' . $surveyId . ' ORDER BY g.mundane_id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The survey's bonus-credit category row, or null. */
    private function category(int $surveyId): ?array
    {
        $id = (int) $this->scalar('SELECT COALESCE(bonus_category_id, 0) FROM ' . DB_PREFIX . 'survey WHERE survey_id = ' . $surveyId);
        if ($id <= 0) {
            return null;
        }
        $row = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'bonus_credit_category WHERE category_id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function K(): array
    {
        return ['type' => 'kingdom', 'id' => $this->k];
    }

    private function PA(): array
    {
        return ['type' => 'park', 'id' => $this->parkA];
    }

    /**
     * The credit writers are not on the public JSON service: orkservice/Json/
     * index.php whitelists whole classes, and neither BonusCredit nor
     * SurveyCredit is listed. Attendance and EventPlanning, which are, carry no
     * token-free writer any more (the attendance-credit design is gone).
     */
    public function testCreditWritersAreNotOnTheJsonService(): void
    {
        $index = (string) file_get_contents(ORK3_ROOT . '/orkservice/Json/index.php');
        $this->assertStringNotContainsString("'BonusCredit'", $index);
        $this->assertStringNotContainsString("'SurveyCredit'", $index);
        foreach ([Attendance::class, EventPlanning::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                $this->assertStringNotContainsString('NO TOKEN', (string) $m->getDocComment(), $class . '::' . $m->getName());
            }
        }
        $this->assertFalse(method_exists(Attendance::class, 'add_system_credit'));
        $this->assertFalse(method_exists(EventPlanning::class, 'create_system_event'));
    }

    public function testEnableCreatesTheSurveysCategoryAndBackfillsBonusCreditsDatedTheDayTaken(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $full = $this->player('full', $this->parkA, $this->k);
        $this->answer($ks, $full, 'full');
        $this->answer($ks, $this->player('part', $this->parkA, $this->k), 'partial');
        $this->answer($ks, $this->player('anon', $this->parkA, $this->k), 'anonymous');
        $submitted = (string) $this->scalar('SELECT DATE(submitted_at) FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $ks . ' AND mundane_id = ' . $full);
        $this->assertNull($this->category($ks), 'no category until someone switches credit on');

        $r = $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(1, $r['Granted']);
        $this->assertFalse($r['Already']);

        $cat = $this->category($ks);
        $this->assertNotNull($cat);
        $this->assertSame('Survey Bonus - T11SHARE survey', $cat['name']);
        $this->assertSame(
            ['kingdom', (string) $this->k, 'survey', (string) $ks, '1.00', '0'],
            [$cat['scope_type'], (string) $cat['scope_id'], $cat['source_type'], (string) $cat['source_id'], $cat['default_credits'], (string) $cat['repeatable']]
        );
        $this->assertSame((string) $this->kOfficer, (string) $cat['created_by']);

        $g = $this->grants($ks);
        $this->assertCount(1, $g);
        $this->assertSame((string) $full, (string) $g[0]['mundane_id']);
        $this->assertSame($submitted, $g[0]['credit_date'], 'dated the day they answered');
        $this->assertSame('6', (string) $g[0]['class_id'], 'no prior attendance: Color');
        $this->assertSame('1.00', $g[0]['credits']);
        $this->assertSame((string) $cat['category_id'], (string) $g[0]['category_id']);
        $this->assertSame('Survey #' . $ks, $g[0]['note']);
        $this->assertSame((string) $this->kOfficer, (string) $g[0]['granted_by'], 'the officer who switched it on');
        $this->assertNull($g[0]['revoked_at']);
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = ' . $full), 'no attendance row');
        $this->assertSame([6 => 1.0], (new BonusCredit())->totalsByClass($full), 'it counts toward class levels');
    }

    /**
     * D1: a credit is dated and visible, so only a respondent whose data gate
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

        $g   = $this->PA();
        $pre = $this->credit()->status($this->pOfficerA, $ks, $g)['Credit']['mine']['preview'];
        $this->assertSame(['eligible_now' => 1, 'no_notice' => 1], $pre);

        $r = $this->credit()->enable($this->pOfficerA, $ks, $g);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(1, $r['Granted'], 'the backfill credits only the respondent who was told');
        $this->assertSame([(string) $told], array_map(static fn (array $x): string => (string) $x['mundane_id'], $this->grants($ks)));
        $this->assertSame(['Granted' => 0, 'Skipped' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
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
        $this->assertStringContainsString('bonus credit', $take, 'the line says what kind of credit it is');
        $this->assertStringNotContainsString('attendance credit', $take);

        $ajax   = (string) file_get_contents(DIR_UI . 'controller/controller.SurveyAjax.php');
        $submit = substr($ajax, (int) strpos($ajax, 'public function submit('), 900);
        $this->assertMatchesRegularExpression('/\$notice\s*=\s*\$this->truthy\(\$_POST\[\'CreditNotice\'\] \?\? 0\);/', $submit);
        $this->assertStringContainsString('->submit($surveyId, $uid, $answers, $consent, $duration, $isTest, $notice)', $submit);
        $model = (string) file_get_contents(DIR_UI . 'model/model.Survey.php');
        $this->assertStringContainsString('->submit($surveyId, $uid, $answers, $consent, $durationSeconds, $isTest, $creditNotice)', $model);
    }

    public function testEnableIsIdempotentAndRefusedWithoutAuthorityOrTheGate(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $g = $this->K();
        $this->assertSame(3, $this->credit()->enable($this->pOfficerA, $ks, $g)['Status'], 'not a kingdom officer');
        $first = $this->credit()->enable($this->kOfficer, $ks, $g);
        $this->assertSame(0, $first['Status']);
        $again = $this->credit()->enable($this->kOfficer, $ks, $g);
        $this->assertSame([0, true], [$again['Status'], $again['Already']], 'switching on twice is a success, not an error');
        $this->assertSame((int) $first['CreditId'], (int) $again['CreditId']);
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . "bonus_credit_category WHERE source_type = 'survey' AND source_id = " . $ks), 'one category');

        $gateOff = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['data_gate_enabled' => 0]);
        $r = $this->credit()->enable($this->kOfficer, $gateOff, $g);
        $this->assertSame(1, $r['Status']);
        $this->assertNull($this->category($gateOff), 'a refused enable creates no category');
    }

    /** Spec §5: an invalid grantor is status 1 even for someone who holds CREATE there; status 3 is only a missing canCreate. */
    public function testEnableRefusesAnInvalidGrantorAsABadRequestAndAMissingCreateAsUnauthorized(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $outside = $this->credit()->enable($this->kOtherOfficer, $ks, ['type' => 'kingdom', 'id' => $this->kOther]);
        $this->assertSame(1, $outside['Status'], 'a kingdom outside the survey is not a grantor, though its officer holds CREATE there');
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, ['type' => 'park', 'id' => $this->parkOther])['Status'], 'a park outside the survey');
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, null)['Status'], 'no grantor');
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, ['type' => 'unit', 'id' => $this->k])['Status'], 'not a grantor type');
        $this->assertSame(3, $this->credit()->enable($this->pOfficerA, $ks, ['type' => 'park', 'id' => $this->parkB])['Status'], 'a valid grantor without CREATE');
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertNull($this->category($ks));
    }

    public function testLiveGrantAfterEnableAndReconcileIsIdempotent(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $uid = $this->player('live', $this->parkB, $this->k);
        $this->answer($ks, $uid, 'full');
        $this->assertSame('granted', $this->credit()->grantFor($ks, $uid));
        $this->assertSame('granted', $this->credit()->grantFor($ks, $uid), 'second call is a no-op');
        $this->assertCount(1, $this->grants($ks));
        $this->assertSame(['Granted' => 0, 'Skipped' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'bonus_credit WHERE note = \'Survey #' . $ks . '\''));
    }

    public function testOneCreditPerPlayerWhenKingdomAndParkBothSwitchOn(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $uid = $this->player('both', $this->parkA, $this->k);
        $this->answer($ks, $uid, 'full');
        $park = $this->credit()->enable($this->pOfficerA, $ks, $this->PA());
        $this->assertSame(0, $park['Status']);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $this->K())['Status']);
        $g = $this->grants($ks);
        $this->assertCount(1, $g);
        $this->assertSame((int) $park['CreditId'], (int) $g[0]['switch_id'], 'the park switch came first');
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'bonus_credit WHERE mundane_id = ' . $uid));
    }

    /**
     * Switching off stops credit for responses submitted after that moment.
     * Anyone who answered while it was on is still owed theirs, even if it
     * posts later. Switching on again clears the off stamps, so the earlier
     * switch-on date keeps its place in the precedence order.
     */
    public function testSwitchingOffStopsLaterResponsesButStillOwesEarlierOnes(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $g  = $this->K();
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $g)['Status']);
        $early = $this->player('early', $this->parkA, $this->k);
        $this->assertSame('granted', $this->answer($ks, $early, 'full')['Credit']);
        // Answered while on, but the live grant never happened (as if it failed).
        $owed = $this->player('owed', $this->parkA, $this->k);
        $this->answer($ks, $owed, 'full');
        $this->pdo->exec('DELETE b, g FROM ' . DB_PREFIX . 'survey_credit_grant g JOIN ' . DB_PREFIX . 'bonus_credit b ON b.bonus_credit_id = g.bonus_credit_id
                          WHERE g.survey_id = ' . $ks . ' AND g.mundane_id = ' . $owed);
        sleep(1);   // the off stamp must be later than that response's submitted_at (second resolution)

        $this->assertSame(3, $this->credit()->disable($this->pOfficerA, $ks, $g)['Status'], 'the same authority as switching on');
        $off = $this->credit()->disable($this->kOfficer, $ks, $g);
        $this->assertSame(0, $off['Status'], (string) ($off['Error'] ?? ''));
        $row = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks)->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($row['disabled_at']);
        $this->assertSame((string) $this->kOfficer, (string) $row['disabled_by']);
        sleep(1);

        $late = $this->player('late', $this->parkA, $this->k);
        $this->assertSame('none', $this->answer($ks, $late, 'full')['Credit'], 'answered after the switch went off');
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $this->player('next', $this->parkA, $this->k)), 'the runner promises nothing');
        $this->assertFalse($this->credit()->hasConfigs($ks), 'the data gate may be turned off again');

        $st = $this->credit()->status($this->kOfficer, $ks, $g)['Credit'];
        $this->assertSame([false, true, false], [$st['mine']['enabled'], $st['mine']['can_enable'], $st['mine']['can_disable']]);
        $this->assertSame(1, $st['pending'], 'the one who answered while it was on is still owed');
        $this->assertFalse($st['configs'][0]['enabled']);
        $this->assertNotNull($st['configs'][0]['disabled_at']);

        $this->assertSame(['Granted' => 1, 'Skipped' => 0, 'Pending' => 0], $this->credit()->reconcile($ks), 'and gets it');
        $this->assertSame([$early, $owed], array_map(static fn (array $x): int => (int) $x['mundane_id'], $this->grants($ks)));
        $this->assertSame('none', $this->credit()->grantFor($ks, $late));

        // Credits already given stay (nothing was revoked by switching off).
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'bonus_credit WHERE note = \'Survey #' . $ks . '\' AND revoked_at IS NOT NULL'));

        // Back on: the switch keeps its original enabled_at, and the response
        // made during the gap is covered again and posts.
        $on = $this->credit()->enable($this->kOfficer, $ks, $g);
        $this->assertSame([0, false, 1], [$on['Status'], $on['Already'], $on['Granted']]);
        $this->assertSame($row['enabled_at'], (string) $this->scalar('SELECT enabled_at FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertNull($this->scalar('SELECT disabled_at FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertCount(3, $this->grants($ks));

        $acts = array_map(
            static fn (array $a): string => (string) (json_decode((string) $a['detail'], true)['action'] ?? ''),
            $this->pdo->query('SELECT detail FROM ' . DB_PREFIX . "survey_activity WHERE survey_id = {$ks} AND action = 'credit' ORDER BY activity_id")->fetchAll(PDO::FETCH_ASSOC)
        );
        $this->assertSame(['enable', 'disable', 'enable'], $acts);
    }

    /** A revoked credit is never granted again: the ledger row outlives the credit. */
    public function testARevokedCreditIsNeverGrantedAgain(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $uid = $this->player('revoked', $this->parkA, $this->k);
        $this->assertSame('granted', $this->answer($ks, $uid, 'full')['Credit']);
        $id = (int) $this->grants($ks)[0]['bonus_credit_id'];

        $r = (new BonusCredit())->revoke($this->kOfficer, $id, 'T11SHARE undo');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertNotNull($this->grants($ks)[0]['revoked_at']);
        $this->assertSame([], (new BonusCredit())->totalsByClass($uid), 'no longer counts');

        $this->assertSame(['Granted' => 0, 'Skipped' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
        $this->assertSame('granted', $this->credit()->grantFor($ks, $uid), 'the ledger says: already dealt with');
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $uid));
        $this->assertSame(0, $this->credit()->status($this->kOfficer, $ks, $this->K())['Credit']['pending']);
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'bonus_credit WHERE mundane_id = ' . $uid));
    }

    /**
     * The owner's switch covers everyone who answered, visitors included, but
     * a visitor's own Kingdom decides whether another Kingdom's credit may
     * reach them (the accept-outside setting). Until it says yes the visitor
     * is skipped, stays owed, and posts once it does.
     */
    public function testTheOwnersSwitchCoversVisitorsOnlyWhereTheirKingdomAcceptsOutsideCredits(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $local   = $this->player('local', $this->parkA, $this->k);
        $visitor = $this->player('visitor', $this->parkOther, $this->kOther);
        $this->answer($ks, $local, 'full');
        // A visitor can only answer an event-audience survey; the coverage rule is what's under test here.
        $this->pdo->exec('INSERT INTO ' . DB_PREFIX . "survey_response (survey_id, consent, mundane_id, kingdom_id, park_id, credit_notice, is_test, submitted_at)
                          VALUES ({$ks}, 'full', {$visitor}, {$this->kOther}, {$this->parkOther}, 1, 0, '" . date('Y-m-d H:i:s') . "')");

        // Accepting outside credits is the default; the visitor's Kingdom declines first.
        $this->assertSame(0, (new BonusCredit())->setAcceptsOutside($this->kOtherOfficer, $this->kOther, false)['Status']);
        $r = $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame([1, 1, 0], [$r['Granted'], $r['Skipped'], $r['Pending']], 'the local player is credited, the visitor skipped');
        $this->assertSame([$local], array_map(static fn (array $x): int => (int) $x['mundane_id'], $this->grants($ks)));
        $this->assertSame(1, $this->credit()->status($this->kOfficer, $ks, $this->K())['Credit']['pending'], 'the visitor stays owed');
        $this->assertSame('none', $this->credit()->grantFor($ks, $visitor), 'and gets nothing live either');

        $this->assertSame(0, (new BonusCredit())->setAcceptsOutside($this->kOtherOfficer, $this->kOther, true)['Status']);
        $this->assertSame(['Granted' => 1, 'Skipped' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
        $g = $this->grants($ks);
        $this->assertCount(2, $g);
        $this->assertSame((string) $this->category($ks)['category_id'], (string) $g[1]['category_id'], 'the owner\'s own category, crossing into the other Kingdom');
    }

    /** A park switch covers its members only; a kingdom switch covers the kingdom and its principalities. */
    public function testParkSwitchCoversItsMembersAndKingdomSwitchCoversThePrincipality(): void
    {
        $prin  = $this->kingdom('prin', $this->k);
        $pPark = $this->park($prin, 'pp');
        $ks    = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $inA   = $this->player('ina', $this->parkA, $this->k);
        $inB   = $this->player('inb', $this->parkB, $this->k);
        $inP   = $this->player('inp', $pPark, $prin);
        foreach ([$inA, $inB, $inP] as $uid) {
            $this->answer($ks, $uid, 'full');
        }
        $this->assertSame(1, $this->credit()->enable($this->pOfficerA, $ks, $this->PA())['Granted'], 'park A: its own member only');
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $inB));
        $this->assertSame(2, $this->credit()->enable($this->kOfficer, $ks, $this->K())['Granted'], 'the kingdom: park B and the principality');
        $this->assertSame([$inA, $inB, $inP], array_map(static fn (array $x): int => (int) $x['mundane_id'], $this->grants($ks)));
    }

    /**
     * An Amtgard-wide survey gets an ork-scope category; a kingdom switching
     * credit on adopts it, which is what lets the credit reach its players. A
     * kingdom that has not switched on (not adopted) gets nothing, and its
     * parks cannot switch on before it does.
     */
    public function testAnAmtgardWideSurveyUsesAnOrkCategoryAdoptedBySwitchingOn(): void
    {
        $os   = $this->openSurvey($this->kOfficer, 'ork', $this->k);
        $home = $this->player('orkhome', $this->parkA, $this->k);
        $away = $this->player('orkaway', $this->parkOther, $this->kOther);
        $this->answer($os, $home, 'full');
        $this->answer($os, $away, 'full');

        $park = $this->credit()->status($this->pOfficerA, $os, $this->PA())['Credit']['mine'];
        $this->assertFalse($park['can_enable']);
        $this->assertStringContainsString('Kingdom', $park['blocked_reason']);
        $this->assertSame(1, $this->credit()->enable($this->pOfficerA, $os, $this->PA())['Status'], 'a park cannot go first on an Amtgard-wide survey');

        $r = $this->credit()->enable($this->kOfficer, $os, $this->K());
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $cat = $this->category($os);
        $this->assertSame(['ork', '0'], [$cat['scope_type'], (string) $cat['scope_id']]);
        $bonus = new BonusCredit();
        $this->assertTrue($bonus->isAdoptedIn((int) $cat['category_id'], [$this->k]), 'switching on adopted it');
        $this->assertFalse($bonus->isAdoptedIn((int) $cat['category_id'], [$this->kOther]));
        $this->assertSame([$home], array_map(static fn (array $x): int => (int) $x['mundane_id'], $this->grants($os)));
        $this->assertSame(0, $r['Skipped'], 'the other kingdom\'s respondent is not covered by this switch at all');

        $this->assertTrue($this->credit()->status($this->pOfficerA, $os, $this->PA())['Credit']['mine']['can_enable'], 'now the park may');
        $this->assertSame(0, $this->credit()->enable($this->kOtherOfficer, $os, ['type' => 'kingdom', 'id' => $this->kOther])['Status']);
        $this->assertTrue($bonus->isAdoptedIn((int) $cat['category_id'], [$this->kOther]));
        $this->assertSame([$home, $away], array_map(static fn (array $x): int => (int) $x['mundane_id'], $this->grants($os)), 'by player id');
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . "bonus_credit_category WHERE source_type = 'survey' AND source_id = " . $os), 'one category for the whole survey');
    }

    /** A bonus credit needs no park: a kingdom switch credits a member of a retired park like anyone else in the kingdom. */
    public function testARetiredHomeParkIsNoObstacleToAKingdomCredit(): void
    {
        $retired = $this->park($this->k, 'retired');
        $uid = $this->player('retired', $retired, $this->k);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "park SET active = 'Retired' WHERE park_id = " . $retired);
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->answer($ks, $uid, 'full');
        $r = $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $this->assertSame(1, $r['Granted']);
        $this->assertTrue($this->credit()->creditAvailableFor($this->row($ks), $this->player('retired2', $retired, $this->k)));
    }

    /** A deleted draft takes its switches with it, and a category that never granted. */
    public function testDeletingADraftRemovesItsSwitchesAndItsUnusedCategory(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . "survey SET status = 'draft' WHERE survey_id = " . $ks);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $this->K())['Status'], 'the owner may switch on a draft');
        $cat = $this->category($ks);
        $this->assertNotNull($cat);

        $s = new Survey();
        $s->setActor($this->kOfficer);
        $this->assertSame(0, $s->delete($ks)['Status']);
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'bonus_credit_category WHERE category_id = ' . (int) $cat['category_id']), 'the never-granted category is gone');
        $this->assertNotContains($ks, $this->credit()->surveysWithConfigs(), 'out of the sweep\'s work list');
    }

    /** The category is named after the survey and follows a rename; archiving the survey archives it. */
    public function testTheCategoryFollowsTheTitleAndIsArchivedWithTheSurvey(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $this->answer($ks, $this->player('kept', $this->parkA, $this->k), 'full');
        $s = new Survey();
        $s->setActor($this->kOfficer);
        $this->assertSame(0, $s->update($ks, ['Title' => 'T11SHARE renamed'])['Status']);
        $this->assertSame('Survey Bonus - T11SHARE renamed', $this->category($ks)['name']);

        $this->assertSame(0, $s->setStatus($ks, 'archived')['Status']);
        $cat = $this->category($ks);
        $this->assertNotNull($cat['archived_at']);
        $this->assertSame((string) $this->kOfficer, (string) $cat['archived_by']);
        $this->assertCount(1, $this->grants($ks), 'credits already given stay');
        $this->assertNull($this->grants($ks)[0]['revoked_at']);
    }

    /** A kingdom that already has a credit of that name (by hand, or an older survey) gets the survey id as a suffix. */
    public function testACategoryNameClashGetsTheSurveyIdSuffix(): void
    {
        $taken = (new BonusCredit())->createCategory($this->kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->k, 'Name' => 'Survey Bonus - T11SHARE survey']);
        $this->assertSame(0, $taken['Status'], (string) ($taken['Error'] ?? ''));
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $this->K())['Status']);
        $this->assertSame('Survey Bonus - T11SHARE survey (#' . $ks . ')', $this->category($ks)['name']);
        $this->assertNotSame((int) $taken['CategoryId'], (int) $this->category($ks)['category_id'], 'the manual one is left alone');
    }

    /**
     * An officer may also hand the survey's credit out under Bonus Credits.
     * A player who already holds it that way is ledgered, not credited twice,
     * when they answer.
     */
    public function testAnOfficersHandGrantOfTheSurveyCreditIsLedgeredNotDoubled(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $uid = $this->player('byhand', $this->parkA, $this->k);
        $cat = (int) $this->category($ks)['category_id'];
        $g = (new BonusCredit())->grant($this->kOfficer, ['MundaneId' => $uid, 'ClassId' => 6, 'CategoryId' => $cat, 'HostType' => 'kingdom', 'HostId' => $this->k, 'Note' => 'by hand']);
        $this->assertSame(0, $g['Status'], (string) ($g['Error'] ?? ''));

        $this->assertSame('granted', $this->answer($ks, $uid, 'full')['Credit']);
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'bonus_credit WHERE mundane_id = ' . $uid), 'still one credit');
        $this->assertSame((int) $g['BonusCreditId'], (int) $this->grants($ks)[0]['bonus_credit_id'], 'the ledger points at the hand-made one');
        $this->assertSame(['Granted' => 0, 'Skipped' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
    }

    public function testNonOwnerCannotEnableOnADraftAndStatusHidesUnrelatedConfigs(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['status' => 'draft']);
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $os, $this->K())['Status']);

        $open = $this->openSurvey($this->kOfficer, 'ork', $this->k);
        $this->credit()->enable($this->kOtherOfficer, $open, ['type' => 'kingdom', 'id' => $this->kOther]);
        $st = $this->credit()->status($this->kOfficer, $open, $this->K());
        $this->assertSame(0, $st['Status']);
        $this->assertSame([], $st['Credit']['configs'], "another kingdom's config is not shown");
        $this->assertTrue($st['Credit']['mine']['can_enable']);
        $this->assertSame(['eligible_now', 'no_notice'], array_keys($st['Credit']['mine']['preview']));
        $this->assertNotNull($st['Credit']['category'], 'the category exists once anyone switched on');
    }

    /** A hidden config's owed credits must not leak through `pending` either. */
    public function testStatusPendingCountsOnlyTheConfigsTheViewerCanSee(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $os, $this->K())['Status']);
        $this->assertSame(0, $this->credit()->enable($this->kOtherOfficer, $os, ['type' => 'kingdom', 'id' => $this->kOther])['Status']);
        // Owed but not yet granted (a failed live grant): one player in each kingdom.
        $home = $this->player('owedhome', $this->parkA, $this->k);
        $away = $this->player('owedaway', $this->parkOther, $this->kOther);
        $now  = date('Y-m-d H:i:s');
        $this->pdo->exec('INSERT INTO ' . DB_PREFIX . "survey_response (survey_id, consent, mundane_id, kingdom_id, park_id, credit_notice, is_test, submitted_at)
                          VALUES ({$os}, 'full', {$home}, {$this->k}, {$this->parkA}, 1, 0, '{$now}'),
                                 ({$os}, 'full', {$away}, {$this->kOther}, {$this->parkOther}, 1, 0, '{$now}')");

        $mine = $this->credit()->status($this->kOfficer, $os, $this->K());
        $this->assertSame(0, $mine['Status']);
        $this->assertCount(1, $mine['Credit']['configs'], "the other kingdom's config stays hidden");
        $this->assertSame(1, $mine['Credit']['pending'], "only this kingdom's owed credit is counted");

        $theirs = $this->credit()->status($this->kOtherOfficer, $os, ['type' => 'kingdom', 'id' => $this->kOther]);
        $this->assertSame(1, $theirs['Credit']['pending']);

        $park = $this->credit()->status($this->pOfficerA, $os, $this->PA());
        $this->assertSame(1, $park['Credit']['pending'], 'a park sees its own kingdom\'s config and its owed credit');

        // Reconcile still posts everything owed, whoever asked, but reports
        // only the credits under the configs the caller's panel shows.
        $this->assertSame(1, $this->credit()->reconcileAs($this->kOfficer, $os, $this->K())['Granted'], "the other kingdom's credit is posted but not counted");
        $this->assertCount(2, $this->grants($os), 'both owed credits were posted');
        $this->assertSame(0, $this->credit()->status($this->kOfficer, $os, $this->K())['Credit']['pending']);
        $this->assertSame(0, $this->credit()->status($this->kOtherOfficer, $os, ['type' => 'kingdom', 'id' => $this->kOther])['Credit']['pending']);
    }

    /**
     * credit_reconcile and credit_enable answer with counts, and a hidden
     * config's must not leak through them any more than through status().
     * The activity log records each switch with its own backfill.
     */
    public function testReconcileAndEnableCountOnlyTheConfigsTheCallerCanSee(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k);
        $this->assertSame(0, $this->credit()->enable($this->kOtherOfficer, $os, ['type' => 'kingdom', 'id' => $this->kOther])['Status']);
        // The other kingdom: two owed credits, one of them with no home park (a bonus credit needs none).
        $away   = $this->player('awayowed', $this->parkOther, $this->kOther);
        $noPark = $this->player('awaynopark', $this->parkOther, $this->kOther);
        $now    = date('Y-m-d H:i:s');
        $this->pdo->exec('INSERT INTO ' . DB_PREFIX . "survey_response (survey_id, consent, mundane_id, kingdom_id, park_id, credit_notice, is_test, submitted_at)
                          VALUES ({$os}, 'full', {$away}, {$this->kOther}, {$this->parkOther}, 1, 0, '{$now}'),
                                 ({$os}, 'full', {$noPark}, {$this->kOther}, NULL, 1, 0, '{$now}')");
        $this->answer($os, $this->player('homeenable', $this->parkA, $this->k), 'full');

        $r = $this->credit()->enable($this->kOfficer, $os, $this->K());
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(1, $r['Granted'], "only this kingdom's backfill is reported");
        $this->assertCount(3, $this->grants($os), 'the owed credits elsewhere were still posted');

        $again = $this->credit()->reconcileAs($this->kOfficer, $os, $this->K());
        $this->assertSame(['Granted' => 0, 'Skipped' => 0, 'Pending' => 0], array_intersect_key($again, array_flip(['Granted', 'Skipped', 'Pending'])));

        $act = $this->pdo->query('SELECT action, detail FROM ' . DB_PREFIX . "survey_activity
                                  WHERE survey_id = {$os} AND action = 'credit' ORDER BY activity_id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $act, 'one credit entry per switch-on');
        $detail = json_decode((string) $act[1]['detail'], true);
        $this->assertSame(['action', 'credit_id', 'grantor_type', 'grantor_id', 'category_id', 'backfilled'], array_keys($detail));
        $this->assertSame(['enable', 'kingdom', $this->k, 1], [$detail['action'], $detail['grantor_type'], $detail['grantor_id'], $detail['backfilled']]);
        $this->assertSame((int) $r['CreditId'], $detail['credit_id']);
        $this->assertSame((int) $this->category($os)['category_id'], $detail['category_id']);
    }

    /**
     * D5: other orgs never see a draft and archived is hidden. The panel
     * endpoints (status, reconcile) used to answer any officer below the
     * owner with the draft's title, status and preview counts.
     */
    public function testCreditPanelHidesDraftsAndArchivedSurveysFromOtherOrgs(): void
    {
        $ks     = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $orkRow = $this->openSurvey($this->kOfficer, 'ork', $this->k);
        $park   = $this->PA();
        $kg     = $this->K();
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
        $r = $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('archived', $r['Error']);
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertCount(0, $this->grants($ks));
        $st = $this->credit()->status($this->kOfficer, $ks, $this->K());
        $this->assertFalse($st['Credit']['mine']['can_enable']);
        $this->assertStringContainsString('archived', $st['Credit']['mine']['blocked_reason']);
    }

    public function testCreditAvailableForFollowsCoverage(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $uid = $this->player('avail', $this->parkB, $this->k);
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $uid));
        $this->credit()->enable($this->pOfficerA, $ks, $this->PA());
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $uid), 'park A does not cover park B');
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $this->assertTrue($this->credit()->creditAvailableFor($this->row($ks), $uid));
    }

    /** A player already holding the survey's credit is not promised another (e.g. a retake after cleared results). */
    public function testCreditAvailableSkipsAPlayerAlreadyGranted(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
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
        $this->credit()->enable($this->kOfficer, $covered, $this->K());
        $this->credit()->enable($this->pOfficerA, $parkA, $this->PA());
        $this->credit()->enable($this->kOfficer, $gateOff, $this->K());
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
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $this->assertSame('granted', $this->answer($ks, $this->player('s1', $this->parkA, $this->k), 'full')['Credit']);
        $this->assertSame('none', $this->answer($ks, $this->player('s2', $this->parkA, $this->k), 'partial')['Credit']);
        $this->assertSame('none', $this->answer($ks, $this->player('s3', $this->parkA, $this->k), 'anonymous')['Credit']);
        $this->assertCount(1, $this->grants($ks));
    }

    /** One ordinary attendance row (every NOT NULL column named): the player's "last class" comes from here. */
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
     * AC 7 / D6: a credit failure never costs the response. The bonus grant is
     * made to fail (the category is archived for a moment); submit still
     * commits and answers Status 0 with Credit 'pending', and reconcile posts
     * the credit once the obstacle is gone.
     */
    public function testACreditFailureNeverLosesTheResponseAndReconcileRepairsIt(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $this->K())['Status']);
        $uid = $this->player('pending', $this->parkA, $this->k);
        $cat = (int) $this->category($ks)['category_id'];
        $this->assertSame(0, (new BonusCredit())->archiveCategory($this->kOfficer, $cat)['Status']);

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
        $this->assertSame(1, $this->credit()->status($this->kOfficer, $ks, $this->K())['Credit']['pending']);

        $this->assertSame(0, (new BonusCredit())->restoreCategory($this->kOfficer, $cat)['Status']);
        $this->assertSame(['Granted' => 1, 'Skipped' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
        $this->assertCount(1, $this->grants($ks));
        $this->assertSame(['Granted' => 0, 'Skipped' => 0, 'Pending' => 0], $this->credit()->reconcile($ks), 'and re-running changes nothing');
    }

    /** §8: a live grant uses the player's last class, not Color, when they have one. */
    public function testLiveGrantUsesThePlayersLastClass(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
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
     * Remaining (the sweep finishes them); a retry of the enable is a success
     * that carries on the backfill. The backfill's batched last-class lookup
     * matches the live one.
     */
    public function testEnableBackfillIsBoundedAndARetrySucceeds(): void
    {
        $ks  = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $g   = $this->K();
        $ids = [];
        foreach (['b1', 'b2', 'b3'] as $s) {
            $this->answer($ks, $ids[] = $this->player($s, $this->parkA, $this->k), 'full');
        }
        $this->manualAttendance($ids[0], date('Y-m-d', strtotime('-9 days')), $this->parkA, $this->k, 2, 'T11SHARE older');
        $this->manualAttendance($ids[0], date('Y-m-d', strtotime('-3 days')), $this->parkA, $this->k, 3, 'T11SHARE newer');

        $r = $this->cappedCredit(2)->enable($this->kOfficer, $ks, $g);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame([2, 1, false], [$r['Granted'], $r['Remaining'], $r['Already']]);
        $this->assertCount(2, $this->grants($ks));
        $this->assertSame('3', (string) $this->grants($ks)[0]['class_id'], 'batched lookup: the class of their latest attendance');
        $this->assertSame('6', (string) $this->grants($ks)[1]['class_id'], 'no attendance: Color');
        $this->assertSame(1, $this->credit()->status($this->kOfficer, $ks, $g)['Credit']['pending'], 'the rest stays owed');

        $again = $this->cappedCredit(2)->enable($this->kOfficer, $ks, $g);
        $this->assertSame(0, $again['Status'], 'retrying the enable is not an error');
        $this->assertSame([true, 1, 0], [$again['Already'], $again['Granted'], $again['Remaining']]);
        $this->assertSame((int) $r['CreditId'], (int) $again['CreditId']);
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertCount(3, $this->grants($ks));
    }

    public function testPanelReconcileIsBoundedAndTheSweepReconcileIsNot(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $g  = $this->K();
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $g)['Status']);
        // Owed but not yet posted (as if their live grants had failed).
        foreach (['r1', 'r2', 'r3'] as $s) {
            $uid = $this->player($s, $this->parkA, $this->k);
            $this->answer($ks, $uid, 'full');
            $this->pdo->exec('DELETE b, g FROM ' . DB_PREFIX . 'survey_credit_grant g JOIN ' . DB_PREFIX . 'bonus_credit b ON b.bonus_credit_id = g.bonus_credit_id
                              WHERE g.survey_id = ' . $ks . ' AND g.mundane_id = ' . $uid);
        }
        $r = $this->cappedCredit(1)->reconcileAs($this->kOfficer, $ks, $g);
        $this->assertSame(0, $r['Status']);
        $this->assertSame([1, 2], [$r['Granted'], $r['Remaining']]);
        $this->assertSame(['Granted' => 2, 'Skipped' => 0, 'Pending' => 0], $this->cappedCredit(1)->reconcile($ks), 'the sweep finishes the backlog');
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
        $g       = $this->K();
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

        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $g)['Status']);
        $got = array_map(static fn (array $x): int => (int) $x['mundane_id'], $this->grants($ks));
        sort($got);
        $want = [$clean, $expired];
        sort($want);
        $this->assertSame($want, $got);

        $st = $this->credit()->status($this->kOfficer, $ks, $g)['Credit'];
        $this->assertSame([0, 3], [$st['pending'], $st['held']]);
        $this->assertSame('none', $this->credit()->grantFor($ks, $susp), 'no live credit while suspended');
        $this->assertSame(['Granted' => 0, 'Skipped' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));

        $this->pdo->exec("UPDATE {$m} SET penalty_box = 0 WHERE mundane_id = {$banned}");
        $this->assertSame(['Granted' => 1, 'Skipped' => 0, 'Pending' => 0], $this->credit()->reconcile($ks), 'posts once the ban lifts');
        $this->assertSame(2, $this->credit()->status($this->kOfficer, $ks, $g)['Credit']['held']);
    }

    /** Spec §8 / AC 3: rows and export stay manager-only; shared access never opens them. */
    public function testSharedViewersCannotReachRowsOrExport(): void
    {
        $ks  = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'all', 'results_share_timing' => 'ongoing']);
        $acc = (new Survey())->resultsAccess($this->pOfficerA, $this->row($ks), $this->PA());
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

    /** §3.5: Clone copies no credit switches and no category; a new draft inherits no promise. */
    public function testCloneDoesNotCopyCreditSwitchesOrTheCategory(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $c = (new Survey())->cloneSurvey($ks, $this->kOfficer);
        $this->assertSame(0, $c['Status'], (string) ($c['Error'] ?? ''));
        $copy = $this->fx['survey'][] = (int) $c['SurveyId'];
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks));
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $copy));
        $this->assertNull($this->category($copy));
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

    public function testGateCannotBeTurnedOffWhileASwitchIsOn(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $r = (new Survey())->update($ks, ['DataGateEnabled' => 0]);
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('bonus credits', $r['Error']);
        $this->assertSame('1', (string) $this->row($ks)['data_gate_enabled']);
        $this->credit()->disable($this->kOfficer, $ks, $this->K());
        $this->assertSame(0, (new Survey())->update($ks, ['DataGateEnabled' => 0])['Status'], 'off: the gate is free again');
    }

    /**
     * opened_at and closed_at are compared with response times written from
     * PHP's clock (SurveyResponse::nowStamp()), so they must be written on it
     * too, never SQL NOW(): the DB server runs UTC. A zone 14 hours from UTC
     * makes any NOW() write show up regardless of the DB zone.
     */
    public function testOpenAndCloseStampsUseThePhpClock(): void
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

            $this->assertSame(0, $s->setStatus($sid, 'closed')['Status']);
            $this->assertLessThan(120, abs(strtotime((string) $this->row($sid)['closed_at']) - time()), 'closed_at is on the PHP clock');
        } finally {
            date_default_timezone_set($tz);
        }
    }

    /** Run bin/survey-credit-sweep.php in a clean CLI process against the sandbox. */
    private function runSweep(array $env = [], array $args = []): array
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

    /** The sweep needs no site host any more (it builds no URLs) and posts what a web request left owed. */
    public function testSweepRunsWithoutAHostAndPostsOwedCredits(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $this->K())['Status']);
        $uid = $this->player('swept', $this->parkA, $this->k);
        $this->answer($ks, $uid, 'full');
        $this->pdo->exec('DELETE b, g FROM ' . DB_PREFIX . 'survey_credit_grant g JOIN ' . DB_PREFIX . 'bonus_credit b ON b.bonus_credit_id = g.bonus_credit_id
                          WHERE g.survey_id = ' . $ks . ' AND g.mundane_id = ' . $uid);
        $this->assertCount(0, $this->grants($ks));

        $run = $this->runSweep();
        $this->assertSame(0, $run['exit'], $run['out'] . $run['err']);
        $this->assertStringNotContainsString('Undefined array key', $run['out'] . $run['err']);
        $this->assertStringNotContainsString('Warning', $run['out'] . $run['err']);
        $this->assertStringContainsString('survey=' . $ks . ' granted=1', $run['out']);
        $this->assertCount(1, $this->grants($ks));
    }

    /**
     * bin/ is under the web docroot. The sweep must refuse every non-CLI SAPI
     * before startup.php, or an anonymous GET would run the whole credit engine.
     */
    public function testSweepRefusesToRunOverHttp(): void
    {
        $src = (string) file_get_contents(ORK3_ROOT . '/bin/survey-credit-sweep.php');
        $guard = strpos($src, "'cli' !== PHP_SAPI");
        $this->assertNotFalse($guard, 'a PHP_SAPI guard');
        $this->assertLessThan(strpos($src, 'require_once'), $guard, 'before startup.php');

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

    public function testOutOfScopePlayerIsToldWhoTheSurveyIsForNotNotFound(): void
    {
        $ps = $this->openSurvey($this->pOfficerA, 'park', $this->parkA);
        $outsider = $this->player('outsider', $this->parkB, $this->k);
        $def = (new SurveyResponse())->definitionForRespondent($ps, $outsider, false);
        $this->assertSame(0, $def['Status'], 'a shared link to another park\'s survey is not a 404');
        $this->assertFalse($def['Eligible']);
        $this->assertSame('scope', $def['Reason']);
        $this->assertSame('park', $def['Survey']['scope_type']);
        $this->assertNotSame('', $def['Survey']['scope_label']);
        $this->assertSame([], $def['Pages'], 'nothing beyond the caption leaks');
        $this->assertArrayNotHasKey('description', $def['Survey']);

        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $away = $this->player('away', $this->parkOther, $this->kOther);
        $def = (new SurveyResponse())->definitionForRespondent($ks, $away, false);
        $this->assertSame(0, $def['Status']);
        $this->assertSame('scope', $def['Reason']);
        $this->assertSame('kingdom', $def['Survey']['scope_type']);
    }

    public function testRunnerSeesCreditAvailableOnlyWhenCovered(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $uid = $this->player('runner', $this->parkA, $this->k);
        $def = (new SurveyResponse())->definitionForRespondent($ks, $uid, false);
        $this->assertFalse($def['Survey']['credit_available']);
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $def = (new SurveyResponse())->definitionForRespondent($ks, $uid, false);
        $this->assertTrue($def['Survey']['credit_available']);
        $rows = array_values(array_filter((new SurveyResponse())->availableFor($uid), static fn ($r) => $r['survey_id'] === $ks));
        $this->assertTrue($rows[0]['credit_available']);
    }

    /** A bonus credit is not attendance: it never qualifies a player for another survey's recent-attendance audience. */
    public function testSurveyCreditsDoNotCountAsRecentAttendance(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, $this->K());
        $uid = $this->player('recent', $this->parkA, $this->k);
        $this->assertSame('granted', $this->answer($ks, $uid, 'full')['Credit']);
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = ' . $uid), 'nothing in ork_attendance');

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
